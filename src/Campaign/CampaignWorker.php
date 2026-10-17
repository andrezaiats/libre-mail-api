<?php

namespace LibreMailApi\Campaign;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Claims batches of queued deliveries and sends them.
 *
 * Crash-safety protocol per row: attempt_at is written (only if this worker
 * still owns the row) right before the SMTP send, and sent/failed right after.
 * A row left `sending` with attempt_at set may have been accepted by the MTA,
 * so CampaignService::reclaimExpired() parks it as `unknown` instead of resending.
 */
class CampaignWorker
{
    private Db $db;
    private Personalizer $personalizer;
    /** @var callable(array $msg): void, throws SendFailed on rejection */
    private $send;
    private array $cfg;
    private LoggerInterface $logger;

    public function __construct(Db $db, Personalizer $personalizer, callable $send, array $campaignsConfig, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->personalizer = $personalizer;
        $this->send = $send;
        $this->cfg = $campaignsConfig + ['chunk' => 50, 'feedback_sender' => 'aipster', 'heartbeat_every' => 25];
        $this->logger = $logger;
    }

    /** @return array{sent: int, failed: int, skipped: int, paused?: bool} */
    public function run(int $campaignId, string $workerId): array
    {
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
        $st->execute([$campaignId]);
        $campaign = $st->fetch();
        if (!$campaign) {
            throw new \RuntimeException("campaign $campaignId not found");
        }
        $pdo->prepare("UPDATE campaigns SET status = 'sending', started_at = COALESCE(started_at, NOW(3)) WHERE id = ? AND status = 'queued'")
            ->execute([$campaignId]);

        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $this->logger->info('campaign worker started', ['campaign_id' => $campaignId, 'worker' => $workerId]);

        while (($batch = $this->claim($campaignId)) !== null) {
            [$batchId, $rows] = $batch;
            foreach ($rows as $i => $row) {
                if ($i > 0 && $i % $this->cfg['heartbeat_every'] === 0) {
                    $pdo->prepare("UPDATE deliveries SET claimed_at = NOW(3) WHERE batch_id = ? AND status = 'sending'")
                        ->execute([$batchId]);
                }
                $own = $pdo->prepare("UPDATE deliveries SET attempt_at = NOW(3) WHERE id = ? AND batch_id = ? AND status = 'sending'");
                $own->execute([$row['id'], $batchId]);
                if ($own->rowCount() === 0) {
                    $stats['skipped']++; // lease was reclaimed by resume(); someone else owns it now
                    continue;
                }
                try {
                    ($this->send)($this->buildMessage($campaign, $row));
                    $pdo->prepare("UPDATE deliveries SET status = 'sent', sent_at = NOW(3), error = NULL WHERE id = ? AND status IN ('sending', 'unknown')")
                        ->execute([$row['id']]);
                    $stats['sent']++;
                } catch (SendFailed $e) {
                    if ($e->transient) {
                        // Not accepted by the MTA: give this row and the rest of the batch back to the queue
                        // and stop; POST /v3/campaigns/{key}/resume continues once the server is reachable.
                        $pdo->prepare("UPDATE deliveries SET status = 'queued', batch_id = NULL, claimed_at = NULL, attempt_at = NULL, error = ? WHERE batch_id = ? AND status = 'sending' AND sent_at IS NULL")
                            ->execute([substr('retry: ' . $e->getMessage(), 0, 1000), $batchId]);
                        $this->logger->error('campaign paused: SMTP temporarily unavailable', ['campaign_id' => $campaignId, 'worker' => $workerId, 'error' => $e->getMessage()]);
                        $stats['paused'] = true;
                        return $stats;
                    }
                    $pdo->prepare("UPDATE deliveries SET status = 'failed', error = ? WHERE id = ?")
                        ->execute([substr($e->getMessage(), 0, 1000), $row['id']]);
                    $stats['failed']++;
                    $this->logger->warning('campaign send failed', ['campaign_id' => $campaignId, 'to' => $row['email'], 'error' => $e->getMessage()]);
                }
            }
        }

        CampaignService::markDoneIfFinished($pdo, $campaignId);
        $this->logger->info('campaign worker finished', ['campaign_id' => $campaignId, 'worker' => $workerId] + $stats);
        return $stats;
    }

    /** @return array{0: string, 1: array[]}|null */
    private function claim(int $campaignId): ?array
    {
        $pdo = $this->db->pdo();
        $batchId = Uuid::uuid4()->toString();
        for ($try = 0; ; $try++) {
            try {
                $st = $pdo->prepare(
                    "UPDATE deliveries SET status = 'sending', batch_id = ?, claimed_at = NOW(3), attempt_at = NULL
                     WHERE campaign_id = ? AND status = 'queued' ORDER BY send_order, id LIMIT " . (int) $this->cfg['chunk']
                );
                $st->execute([$batchId, $campaignId]);
                break;
            } catch (\PDOException $e) {
                // Concurrent claimers can deadlock on the same index range; retry.
                if (in_array($e->errorInfo[1] ?? null, [1213, 1205], true) && $try < 5) {
                    usleep(random_int(20000, 120000));
                    continue;
                }
                throw $e;
            }
        }
        if ($st->rowCount() === 0) {
            return null;
        }
        $rows = $pdo->prepare('SELECT * FROM deliveries WHERE batch_id = ? ORDER BY send_order, id');
        $rows->execute([$batchId]);
        return [$batchId, $rows->fetchAll()];
    }

    public function buildMessage(array $campaign, array $delivery): array
    {
        $r = $this->personalizer->render($campaign, $delivery);
        $headers = [
            ['List-Unsubscribe', $r['list_unsubscribe']],
            ['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'],
            ['Feedback-ID', \LibreMailApi\HeaderBuilder::feedbackId($campaign['kind'], $delivery['tier'] ?? 'na', $this->cfg['feedback_sender'])],
            ['Auto-Submitted', 'auto-generated'],
            ['X-Auto-Response-Suppress', 'OOF, AutoReply'],
            ['X-AIpster-Campaign', $campaign['campaign_key']],
        ];
        return [
            'to_email' => $delivery['email'],
            'to_name' => $delivery['name'],
            'subject' => $r['subject'],
            'html' => $r['html'],
            'text' => $r['text'],
            'headers' => $headers,
        ];
    }
}
