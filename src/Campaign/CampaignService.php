<?php

namespace LibreMailApi\Campaign;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Campaign API: idempotent creation, audience freezing, the daily sunset tick,
 * status and crash-safe resume.
 *
 * Invariants:
 *  - campaign row + sunset tick + deliveries are written in ONE transaction:
 *    a failure leaves nothing behind, so the caller can retry the same key;
 *  - a duplicate campaign_key is rejected (409) before anything else changes;
 *  - a delivery that may have been handed to SMTP is never resent automatically.
 */
class CampaignService
{
    private Db $db;
    private EngagementPolicy $policy;
    private array $cfg;
    private string $templatesDir;
    /** @var callable(int $campaignId, int $workers): void */
    private $spawner;
    private LoggerInterface $logger;
    /** @var callable(): \DateTimeImmutable */
    private $clock;
    /** @var array<string, callable> test hooks, e.g. before_commit */
    private array $hooks;

    public function __construct(
        Db $db,
        EngagementPolicy $policy,
        array $campaignsConfig,
        string $templatesDir,
        callable $spawner,
        LoggerInterface $logger,
        ?callable $clock = null,
        array $hooks = []
    ) {
        $this->db = $db;
        $this->policy = $policy;
        $this->cfg = $campaignsConfig + [
            'workers' => 4,
            'chunk' => 50,
            'newsletter_slug' => 'default-newsletter',
            'max_html_bytes' => 2 * 1024 * 1024,
            'lease_seconds' => 600,
            'max_test_recipients' => 20,
            'pii_retention_days' => 180,
        ];
        $this->templatesDir = rtrim($templatesDir, '/');
        $this->spawner = $spawner;
        $this->logger = $logger;
        $this->clock = $clock ?? fn () => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->hooks = $hooks;
    }

    // ------------------------------------------------------------------ create

    /** @return array{status: int, data: array} */
    public function create(array $p): array
    {
        $error = $this->validate($p);
        if ($error !== null) {
            return ['status' => 400, 'data' => ['message' => $error]];
        }

        $now = ($this->clock)();
        $isTest = !empty($p['test_to']);
        $kind = $isTest ? 'test' : 'daily';
        $key = $isTest ? $p['campaign_key'] . ':test:' . $now->getTimestamp() : $p['campaign_key'];

        $rewritten = LinkRewriter::rewrite($p['html']);
        $text = isset($p['text']) && trim((string) $p['text']) !== ''
            ? (string) $p['text']
            : Personalizer::htmlToText($p['html']);

        if (!empty($p['dry_run'])) {
            return $this->dryRun($p, $rewritten['links'], $now);
        }

        $pdo = $this->db->pdo();
        $reengage = null;
        $pdo->beginTransaction();
        try {
            $campaignId = $this->insertCampaign($key, $kind, $p['subject'], $p['preheader'] ?? null, $rewritten['html'], $text);
            $this->insertLinks($campaignId, $rewritten['links']);

            if ($isTest) {
                $recipients = $this->testRecipients($p['test_to']);
            } else {
                $plan = $this->planDaily($now, true);
                $this->persistStates($plan['states'], $plan['tick_applied']);
                $recipients = $plan['daily'];
                if ($plan['reengage']) {
                    $reengage = $this->createReengageCampaign($now, $plan['reengage']);
                }
                if ($plan['tick_applied']) {
                    $this->purgeOldPii();
                    $this->db->setMeta('last_tick_date', $this->policy->localDate($now));
                }
            }

            $this->insertDeliveries($campaignId, $recipients);
            $this->finalizeTotal($campaignId, count($recipients));

            if (isset($this->hooks['before_commit'])) {
                ($this->hooks['before_commit'])($campaignId);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            if ($this->isDuplicate($e)) {
                return ['status' => 409, 'data' => ['message' => 'Campaign already exists', 'campaign_key' => $key]];
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $byTier = self::countByTier($recipients);
        $data = [
            'campaign_key' => $key,
            'kind' => $kind,
            'recipients' => count($recipients),
            'by_tier' => $byTier,
            'links' => count($rewritten['links']),
            'workers' => 0,
            'reengage' => $reengage ? ['campaign_key' => $reengage['key'], 'recipients' => $reengage['count']] : null,
        ];

        $spawnErrors = [];
        if (count($recipients) > 0) {
            $data['workers'] = $this->workerCount(count($recipients));
            $err = $this->spawn($campaignId, $data['workers']);
            if ($err !== null) {
                $spawnErrors[] = $err;
            }
        }
        if ($reengage && $reengage['count'] > 0) {
            $err = $this->spawn($reengage['id'], $this->workerCount($reengage['count']));
            if ($err !== null) {
                $spawnErrors[] = 'reengage: ' . $err;
            }
        }
        if ($spawnErrors) {
            $data['message'] = 'Campaign stored but workers could not be started; call POST /v3/campaigns/{key}/resume';
            $data['errors'] = $spawnErrors;
            return ['status' => 500, 'data' => $data];
        }

        $this->logger->info('campaign created', ['campaign_key' => $key, 'recipients' => count($recipients), 'by_tier' => $byTier]);
        return ['status' => 202, 'data' => $data];
    }

    private function dryRun(array $p, array $links, \DateTimeImmutable $now): array
    {
        if (!empty($p['test_to'])) {
            $recipients = $this->testRecipients($p['test_to']);
            $reengageDue = 0;
        } else {
            $plan = $this->planDaily($now, false);
            $recipients = $plan['daily'];
            $reengageDue = count($plan['reengage']);
        }
        return ['status' => 200, 'data' => [
            'dry_run' => true,
            'recipients' => count($recipients),
            'by_tier' => self::countByTier($recipients),
            'reengage_due' => $reengageDue,
            'weekly_day' => $this->policy->isWeeklyDay($now),
            'links' => count($links),
            'link_urls' => $links,
        ]];
    }

    public function validate(array $p): ?string
    {
        if (!isset($p['campaign_key']) || !is_string($p['campaign_key']) || !preg_match('/^[A-Za-z0-9._-]{1,120}$/', $p['campaign_key'])) {
            return 'campaign_key is required (1-120 chars: letters, digits, dot, dash, underscore)';
        }
        if (!isset($p['subject']) || !is_string($p['subject']) || trim($p['subject']) === '' || strlen($p['subject']) > 998) {
            return 'subject is required (max 998 bytes)';
        }
        if (!isset($p['html']) || !is_string($p['html']) || trim($p['html']) === '') {
            return 'html is required';
        }
        if (strlen($p['html']) > $this->cfg['max_html_bytes']) {
            return 'html exceeds ' . $this->cfg['max_html_bytes'] . ' bytes';
        }
        if (strpos($p['html'], '%%unsubscribe_url%%') === false) {
            return 'html must contain a visible %%unsubscribe_url%% link';
        }
        if (isset($p['preheader']) && (!is_string($p['preheader']) || strlen($p['preheader']) > 512)) {
            return 'preheader must be a string up to 512 bytes';
        }
        if (isset($p['text']) && !is_string($p['text'])) {
            return 'text must be a string';
        }
        if (isset($p['test_to']) && $p['test_to'] !== null && $p['test_to'] !== []) {
            if (!is_array($p['test_to']) || count($p['test_to']) > $this->cfg['max_test_recipients']) {
                return 'test_to must be an array of up to ' . $this->cfg['max_test_recipients'] . ' emails';
            }
            foreach ($p['test_to'] as $email) {
                if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return 'test_to contains an invalid email';
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------ audience / tick

    /**
     * Compute tiers for the whole audience, and (when $applyTick and not yet
     * done today) advance the sunset stage machine.
     *
     * @return array{daily: array[], reengage: array[], states: array[], tick_applied: bool}
     */
    public function planDaily(\DateTimeImmutable $now, bool $applyTick): array
    {
        $members = $this->loadAudience();
        $states = $this->loadStates();
        $tickDue = $this->db->getMeta('last_tick_date') !== $this->policy->localDate($now);
        $weekly = $this->policy->isWeeklyDay($now);

        $daily = [];
        $reengage = [];
        $newStates = [];
        foreach ($members as $m) {
            $tier = $this->policy->tier($now, $m['engaged_at'], $m['sends_since']);
            $prev = $states[$m['id']] ?? ['sunset_stage' => 'none', 'stage_at' => null];
            $stage = $prev['sunset_stage'];
            $stageAt = $prev['stage_at'];
            $sendReengage = false;
            if ($tickDue) {
                $adv = $this->policy->advance($stage, $stageAt, $tier, $m['engaged_at'], $now);
                $stage = $adv['stage'];
                $stageAt = $adv['stage_at'];
                $sendReengage = $adv['send_reengage'];
            }
            $row = $m + ['tier' => $tier];
            $newStates[] = [
                'member_id' => $m['id'],
                'tier' => $tier,
                'last_engaged_at' => $m['engaged_at'],
                'sends_since' => $m['sends_since'],
                'stage' => $stage,
                'stage_at' => $stageAt,
            ];
            if ($sendReengage) {
                $reengage[] = $row; // gets the re-engagement email instead of today's Daily
            } elseif ($this->policy->includeInDaily($tier, $stage, $weekly)) {
                $daily[] = $row;
            }
        }

        $order = function (array $a, array $b): int {
            return [EngagementPolicy::tierRank($a['tier']), -$a['engaged_at']->getTimestamp(), $a['email']]
                <=> [EngagementPolicy::tierRank($b['tier']), -$b['engaged_at']->getTimestamp(), $b['email']];
        };
        usort($daily, $order);
        usort($reengage, $order);

        return ['daily' => $daily, 'reengage' => $reengage, 'states' => $newStates, 'tick_applied' => $applyTick && $tickDue];
    }

    /** @return array<int, array{id: string, uuid: string, email: string, name: ?string, engaged_at: \DateTimeImmutable, sends_since: int}> */
    private function loadAudience(): array
    {
        $g = $this->db->ghost();
        $sql = "
            WITH aud AS (
                SELECT m.id, m.uuid, m.email, m.name, m.created_at, m.last_seen_at
                FROM {$g}.members m
                JOIN {$g}.members_newsletters mn ON mn.member_id = m.id
                JOIN {$g}.newsletters n ON n.id = mn.newsletter_id
                WHERE n.slug = ? AND m.email_disabled = 0
                GROUP BY m.id, m.uuid, m.email, m.name, m.created_at, m.last_seen_at
            ),
            eng AS (
                SELECT a.id, GREATEST(
                    a.created_at,
                    COALESCE(a.last_seen_at, a.created_at),
                    COALESCE((SELECT MAX(er.opened_at) FROM {$g}.email_recipients er WHERE er.member_id = a.id), a.created_at),
                    COALESCE((SELECT MAX(ce.created_at) FROM {$g}.members_click_events ce WHERE ce.member_id = a.id), a.created_at),
                    COALESCE((SELECT MAX(e.created_at) FROM events e JOIN campaigns c ON c.id = e.campaign_id
                              WHERE e.member_id = a.id AND e.type IN ('open', 'click') AND e.suspect = 0 AND c.kind <> 'test'), a.created_at)
                ) AS engaged_at
                FROM aud a
            )
            SELECT a.id, a.uuid, a.email, a.name, eng.engaged_at,
                (SELECT COUNT(*) FROM {$g}.email_recipients er
                  WHERE er.member_id = a.id AND COALESCE(er.delivered_at, er.processed_at) > eng.engaged_at)
              + (SELECT COUNT(*) FROM deliveries d JOIN campaigns c ON c.id = d.campaign_id
                  WHERE d.member_id = a.id AND d.status = 'sent' AND c.kind <> 'test' AND d.sent_at > eng.engaged_at)
                AS sends_since
            FROM aud a JOIN eng ON eng.id = a.id
        ";
        $st = $this->db->pdo()->prepare($sql);
        $st->execute([$this->cfg['newsletter_slug']]);
        $utc = new \DateTimeZone('UTC');
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[] = [
                'id' => $r['id'],
                'uuid' => $r['uuid'],
                'email' => strtolower(trim($r['email'])),
                'name' => $r['name'],
                'engaged_at' => new \DateTimeImmutable($r['engaged_at'], $utc),
                'sends_since' => (int) $r['sends_since'],
            ];
        }
        return $out;
    }

    /** @return array<string, array{sunset_stage: string, stage_at: ?\DateTimeImmutable}> */
    private function loadStates(): array
    {
        $utc = new \DateTimeZone('UTC');
        $out = [];
        foreach ($this->db->pdo()->query('SELECT member_id, sunset_stage, stage_at FROM member_state') as $r) {
            $out[$r['member_id']] = [
                'sunset_stage' => $r['sunset_stage'],
                'stage_at' => $r['stage_at'] ? new \DateTimeImmutable($r['stage_at'], $utc) : null,
            ];
        }
        return $out;
    }

    private function persistStates(array $states, bool $pruneMissing): void
    {
        $pdo = $this->db->pdo();
        foreach (array_chunk($states, 500) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $s) {
                $values[] = '(?, ?, ?, ?, ?, ?, NOW(3))';
                array_push(
                    $args,
                    $s['member_id'],
                    $s['tier'],
                    self::fmt($s['last_engaged_at']),
                    $s['sends_since'],
                    $s['stage'],
                    $s['stage_at'] ? self::fmt($s['stage_at']) : null
                );
            }
            $pdo->prepare(
                'INSERT INTO member_state (member_id, tier, last_engaged_at, sends_since_engaged, sunset_stage, stage_at, updated_at) VALUES '
                . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE tier = VALUES(tier), last_engaged_at = VALUES(last_engaged_at),'
                . ' sends_since_engaged = VALUES(sends_since_engaged), sunset_stage = VALUES(sunset_stage),'
                . ' stage_at = VALUES(stage_at), updated_at = VALUES(updated_at)'
            )->execute($args);
        }

        if ($pruneMissing) {
            // Members who left the newsletter drop out of the engagement table.
            $current = array_flip(array_column($states, 'member_id'));
            $stale = [];
            foreach ($pdo->query('SELECT member_id FROM member_state')->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                if (!isset($current[$id])) {
                    $stale[] = $id;
                }
            }
            foreach (array_chunk($stale, 500) as $chunk) {
                $pdo->prepare('DELETE FROM member_state WHERE member_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                    ->execute($chunk);
            }
        }
    }

    /** @return array{id: int, key: string, count: int}|null */
    private function createReengageCampaign(\DateTimeImmutable $now, array $members): ?array
    {
        $key = 'reengage-' . $this->policy->localDate($now);
        $html = (string) file_get_contents($this->templatesDir . '/reengage.html');
        $text = (string) file_get_contents($this->templatesDir . '/reengage.txt');
        $rewritten = LinkRewriter::rewrite($html);
        $id = $this->insertCampaign($key, 'reengage', 'Still want the AIpster Daily?', 'One click keeps the Daily coming. No click, and we stop sending it.', $rewritten['html'], $text);
        $this->insertLinks($id, $rewritten['links']);
        $this->insertDeliveries($id, $members);
        $this->finalizeTotal($id, count($members));
        $this->logger->info('re-engagement campaign created', ['campaign_key' => $key, 'recipients' => count($members)]);
        return ['id' => $id, 'key' => $key, 'count' => count($members)];
    }

    private function purgeOldPii(): void
    {
        $this->db->pdo()->prepare(
            'UPDATE events SET ip_trunc = NULL, user_agent = NULL
             WHERE created_at < NOW(3) - INTERVAL ? DAY AND (ip_trunc IS NOT NULL OR user_agent IS NOT NULL)'
        )->execute([(int) $this->cfg['pii_retention_days']]);
    }

    private function testRecipients(array $emails): array
    {
        $emails = array_values(array_unique(array_map(fn ($e) => strtolower(trim($e)), $emails)));
        $g = $this->db->ghost();
        $st = $this->db->pdo()->prepare(
            "SELECT id, uuid, LOWER(email) AS email, name FROM {$g}.members WHERE LOWER(email) IN ("
            . implode(',', array_fill(0, count($emails), '?')) . ')'
        );
        $st->execute($emails);
        $known = [];
        foreach ($st->fetchAll() as $r) {
            $known[$r['email']] = $r;
        }
        $now = ($this->clock)();
        $out = [];
        foreach ($emails as $e) {
            $m = $known[$e] ?? null;
            $out[] = [
                'id' => $m['id'] ?? null,
                'uuid' => $m['uuid'] ?? null,
                'email' => $e,
                'name' => $m['name'] ?? null,
                'tier' => 'active',
                'engaged_at' => $now,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------------ writes

    private function insertCampaign(string $key, string $kind, string $subject, ?string $preheader, string $html, string $text): int
    {
        $this->db->pdo()->prepare(
            'INSERT INTO campaigns (campaign_key, kind, subject, preheader, html, text, status) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$key, $kind, $subject, $preheader, $html, $text, 'queued']);
        return (int) $this->db->pdo()->lastInsertId();
    }

    private function insertLinks(int $campaignId, array $links): void
    {
        foreach ($links as $idx => $url) {
            $this->db->pdo()->prepare('INSERT INTO campaign_links (campaign_id, idx, url) VALUES (?, ?, ?)')
                ->execute([$campaignId, $idx, $url]);
        }
    }

    private function insertDeliveries(int $campaignId, array $recipients): void
    {
        $order = 0;
        foreach (array_chunk($recipients, 500) as $chunk) {
            $values = [];
            $args = [];
            foreach ($chunk as $r) {
                $values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($args, Uuid::uuid4()->toString(), $campaignId, $r['id'], $r['uuid'], $r['email'], $r['name'], $r['tier'], $order++);
            }
            $this->db->pdo()->prepare(
                'INSERT INTO deliveries (id, campaign_id, member_id, member_uuid, email, name, tier, send_order) VALUES ' . implode(',', $values)
            )->execute($args);
        }
    }

    private function finalizeTotal(int $campaignId, int $total): void
    {
        $status = $total === 0 ? 'done' : 'queued';
        $this->db->pdo()->prepare(
            'UPDATE campaigns SET total = ?, status = ?, finished_at = IF(? = 0, NOW(3), NULL) WHERE id = ?'
        )->execute([$total, $status, $total, $campaignId]);
    }

    // ------------------------------------------------------------- status/resume

    public function find(string $key): ?array
    {
        $st = $this->db->pdo()->prepare('SELECT id, campaign_key, kind, subject, status, total, spawn_error, created_at, started_at, finished_at FROM campaigns WHERE campaign_key = ?');
        $st->execute([$key]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return array{status: int, data: array} */
    public function status(string $key): array
    {
        $c = $this->find($key);
        if (!$c) {
            return ['status' => 404, 'data' => ['message' => 'Campaign not found']];
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT status, COUNT(*) n FROM deliveries WHERE campaign_id = ? GROUP BY status');
        $st->execute([$c['id']]);
        $counts = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'unknown' => 0];
        foreach ($st->fetchAll() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $st = $pdo->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN type = 'open' THEN delivery_id END) AS unique_opens,
                COUNT(DISTINCT CASE WHEN type = 'click' AND suspect = 0 THEN delivery_id END) AS unique_clicks,
                COUNT(DISTINCT CASE WHEN type = 'click' AND suspect = 1 THEN delivery_id END) AS suspect_clicks,
                COUNT(DISTINCT CASE WHEN type = 'unsubscribe' THEN delivery_id END) AS unsubscribes
             FROM events WHERE campaign_id = ?"
        );
        $st->execute([$c['id']]);
        $ev = array_map('intval', $st->fetch() ?: []);
        unset($c['id']);
        $c['total'] = (int) $c['total'];
        return ['status' => 200, 'data' => $c + ['deliveries' => $counts, 'events' => $ev]];
    }

    /** @return array{status: int, data: array} */
    public function resume(string $key): array
    {
        $c = $this->find($key);
        if (!$c) {
            return ['status' => 404, 'data' => ['message' => 'Campaign not found']];
        }
        $id = (int) $c['id'];
        $requeued = $this->reclaimExpired($id);

        $st = $this->db->pdo()->prepare("SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'queued'");
        $st->execute([$id]);
        $queued = (int) $st->fetchColumn();

        $workers = 0;
        if ($queued > 0) {
            $workers = $this->workerCount($queued);
            $err = $this->spawn($id, $workers);
            if ($err !== null) {
                return ['status' => 500, 'data' => ['message' => 'Could not start workers', 'error' => $err]];
            }
        } else {
            self::markDoneIfFinished($this->db->pdo(), $id);
        }
        $status = $this->status($key)['data'];
        return ['status' => 200, 'data' => $status + ['resume' => $requeued + ['spawned_workers' => $workers]]];
    }

    /**
     * Expired leases: rows never handed to SMTP go back to the queue; the row a
     * dead worker was sending becomes `unknown` and is never resent.
     *
     * @return array{requeued: int, unknown: int}
     */
    public function reclaimExpired(int $campaignId): array
    {
        $pdo = $this->db->pdo();
        $lease = (int) $this->cfg['lease_seconds'];
        $q = $pdo->prepare(
            "UPDATE deliveries SET status = 'queued', batch_id = NULL, claimed_at = NULL
             WHERE campaign_id = ? AND status = 'sending' AND attempt_at IS NULL
               AND claimed_at < NOW(3) - INTERVAL ? SECOND"
        );
        $q->execute([$campaignId, $lease]);
        $requeued = $q->rowCount();
        $u = $pdo->prepare(
            "UPDATE deliveries SET status = 'unknown', error = 'worker stopped after handing the message to SMTP; not resent'
             WHERE campaign_id = ? AND status = 'sending' AND attempt_at IS NOT NULL AND sent_at IS NULL
               AND claimed_at < NOW(3) - INTERVAL ? SECOND"
        );
        $u->execute([$campaignId, $lease]);
        return ['requeued' => $requeued, 'unknown' => $u->rowCount()];
    }

    public static function markDoneIfFinished(\PDO $pdo, int $campaignId): bool
    {
        $st = $pdo->prepare(
            "UPDATE campaigns SET status = 'done', finished_at = NOW(3)
             WHERE id = ? AND status <> 'done'
               AND NOT EXISTS (SELECT 1 FROM deliveries WHERE campaign_id = ? AND status IN ('queued', 'sending'))"
        );
        $st->execute([$campaignId, $campaignId]);
        return $st->rowCount() > 0;
    }

    // ------------------------------------------------------------------ helpers

    private function spawn(int $campaignId, int $workers): ?string
    {
        try {
            ($this->spawner)($campaignId, $workers);
            $this->db->pdo()->prepare('UPDATE campaigns SET spawn_error = NULL WHERE id = ?')->execute([$campaignId]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('campaign worker spawn failed', ['campaign_id' => $campaignId, 'error' => $e->getMessage()]);
            $this->db->pdo()->prepare('UPDATE campaigns SET spawn_error = ? WHERE id = ?')->execute([substr($e->getMessage(), 0, 1000), $campaignId]);
            return $e->getMessage();
        }
    }

    private function workerCount(int $recipients): int
    {
        return max(1, min((int) $this->cfg['workers'], (int) ceil($recipients / max(1, (int) $this->cfg['chunk']))));
    }

    private function isDuplicate(\PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1062 && strpos($e->getMessage(), 'uq_campaign_key') !== false;
    }

    private static function countByTier(array $recipients): array
    {
        $out = [];
        foreach ($recipients as $r) {
            $out[$r['tier']] = ($out[$r['tier']] ?? 0) + 1;
        }
        return $out;
    }

    private static function fmt(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
