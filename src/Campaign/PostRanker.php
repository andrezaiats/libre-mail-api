<?php

namespace LibreMailApi\Campaign;

use Psr\Log\LoggerInterface;

/**
 * Applies the engagement policy to a Ghost post newsletter batch (the
 * Mailgun-compatible path): active first, then warm, then cold; dormant and
 * suppressed members are dropped. Fails open: if the ranking cannot be
 * computed the message goes out unchanged, because a post that never ships is
 * worse than one that also reaches a dormant member once.
 */
class PostRanker
{
    /** @var callable(\DateTimeImmutable): array<string, array> */
    private $rank;
    private EngagementPolicy $policy;
    private LoggerInterface $logger;
    private bool $enabled;
    /** @var callable(): \DateTimeImmutable */
    private $clock;

    public function __construct(callable $rank, EngagementPolicy $policy, LoggerInterface $logger, bool $enabled = true, ?callable $clock = null)
    {
        $this->rank = $rank;
        $this->policy = $policy;
        $this->logger = $logger;
        $this->enabled = $enabled;
        $this->clock = $clock ?? fn () => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * Returns the message with `to`, `recipients` and `recipient_tiers` rewritten, plus
     * `ranking_skipped` (int) and `ranking_tiers` (tier => count) for logging.
     * Messages without the ghost-email tag are returned untouched.
     */
    public function apply(array $message): array
    {
        if (!$this->enabled) {
            return $message;
        }
        $tags = json_decode($message['tags_json'] ?? '[]', true);
        if (!is_array($tags) || !in_array('ghost-email', $tags, true)) {
            return $message;
        }

        $audience = null;
        for ($attempt = 1; $attempt <= 2 && $audience === null; $attempt++) {
            try {
                $audience = ($this->rank)(($this->clock)());
            } catch (\Throwable $e) {
                if ($attempt === 2) {
                    $this->logger->error('post ranking failed, sending in Ghost order (fail-open)', [
                        'message_id' => $message['message_id'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                    return $message;
                }
            }
        }

        $known = [];
        $unknown = [];
        $skipped = 0;
        foreach (self::splitRecipients((string) ($message['to'] ?? '')) as $i => $token) {
            $email = self::emailOf($token);
            $row = $audience[$email] ?? null;
            if ($row === null) {
                $unknown[] = ['token' => $token, 'email' => $email, 'i' => $i];
                continue;
            }
            if (!$this->policy->includeInPost($row['tier'], $row['stage'])) {
                $skipped++;
                continue;
            }
            $known[] = $row + ['token' => $token, 'i' => $i];
        }
        usort($known, fn (array $a, array $b) => EngagementPolicy::compareForSend($a, $b) ?: $a['i'] <=> $b['i']);

        // Unknown addresses (e.g. subscribed moments ago) go after active, before warm.
        $ordered = [];
        $placedUnknown = false;
        foreach ($known as $row) {
            if (!$placedUnknown && EngagementPolicy::tierRank($row['tier']) > EngagementPolicy::tierRank('active')) {
                foreach ($unknown as $u) {
                    $ordered[] = ['token' => $u['token'], 'email' => $u['email'], 'tier' => 'all'];
                }
                $placedUnknown = true;
            }
            $ordered[] = $row;
        }
        if (!$placedUnknown) {
            foreach ($unknown as $u) {
                $ordered[] = ['token' => $u['token'], 'email' => $u['email'], 'tier' => 'all'];
            }
        }

        $tiers = [];
        $recipientTiers = [];
        foreach ($ordered as $r) {
            $tiers[$r['tier']] = ($tiers[$r['tier']] ?? 0) + 1;
            $recipientTiers[$r['email']] = $r['tier'];
        }

        $message['to'] = implode(', ', array_column($ordered, 'token'));
        $message['recipients'] = array_column($ordered, 'email');
        $message['recipient_tiers'] = $recipientTiers;
        $message['ranking_skipped'] = $skipped;
        $message['ranking_tiers'] = $tiers;
        return $message;
    }

    /** @return string[] comma-separated tokens, ignoring commas inside quotes or <> */
    public static function splitRecipients(string $to): array
    {
        $out = [];
        $cur = '';
        $quoted = false;
        $angle = false;
        foreach (str_split($to) as $ch) {
            if ($ch === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $ch === '<') {
                $angle = true;
            } elseif (!$quoted && $ch === '>') {
                $angle = false;
            }
            if ($ch === ',' && !$quoted && !$angle) {
                $out[] = trim($cur);
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        $out[] = trim($cur);
        return array_values(array_filter($out, fn ($t) => $t !== ''));
    }

    public static function emailOf(string $token): string
    {
        if (preg_match('/<([^>]+)>/', $token, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($token));
    }
}
