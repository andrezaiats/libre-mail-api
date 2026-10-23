<?php

namespace LibreMailApi\Campaign;

/**
 * Pure engagement / soft-sunset rules (no I/O).
 *
 *   days since last engagement   tier      Daily
 *   <= active_days (30)          active    every day, sent first
 *   <= warm_days   (90)          warm      every day
 *   <= cold_days   (180)         cold      weekly day only
 *   >  cold_days                 dormant   weekly day only + re-engagement flow
 *
 * Nobody drops below `warm` before `min_sends_before_cooling` sends since
 * their last engagement. Re-engagement: dormant -> reengage1 (email) ->
 * +14d reengage2 (email) -> +14d suppressed (Daily only). Any engagement
 * after the stage started resets the member to `none`.
 */
class EngagementPolicy
{
    public const TIERS = ['active', 'warm', 'cold', 'dormant'];

    private array $cfg;
    private \DateTimeZone $tz;

    public function __construct(array $sunsetConfig, string $timezone = 'America/Sao_Paulo')
    {
        $this->cfg = $sunsetConfig + [
            'active_days' => 30,
            'warm_days' => 90,
            'cold_days' => 180,
            'min_sends_before_cooling' => 10,
            'reengage_interval_days' => 14,
            'weekly_day' => 1, // ISO-8601: 1 = Monday
        ];
        $this->tz = new \DateTimeZone($timezone);
    }

    public function tier(\DateTimeImmutable $now, \DateTimeImmutable $engagedAt, int $sendsSinceEngaged): string
    {
        $days = intdiv(max(0, $now->getTimestamp() - $engagedAt->getTimestamp()), 86400);
        if ($days <= $this->cfg['active_days']) {
            $tier = 'active';
        } elseif ($days <= $this->cfg['warm_days']) {
            $tier = 'warm';
        } elseif ($days <= $this->cfg['cold_days']) {
            $tier = 'cold';
        } else {
            $tier = 'dormant';
        }
        if (($tier === 'cold' || $tier === 'dormant') && $sendsSinceEngaged < $this->cfg['min_sends_before_cooling']) {
            return 'warm';
        }
        return $tier;
    }

    /**
     * Advance the sunset stage machine by one tick.
     *
     * @return array{stage: string, stage_at: ?\DateTimeImmutable, send_reengage: bool, reactivated: bool}
     */
    public function advance(
        string $stage,
        ?\DateTimeImmutable $stageAt,
        string $tier,
        \DateTimeImmutable $engagedAt,
        \DateTimeImmutable $now
    ): array {
        $reactivated = false;
        if ($stage !== 'none' && ($stageAt === null || $engagedAt > $stageAt)) {
            $stage = 'none';
            $stageAt = null;
            $reactivated = true;
        }

        $interval = $this->cfg['reengage_interval_days'] * 86400;
        $elapsed = $stageAt ? $now->getTimestamp() - $stageAt->getTimestamp() : 0;

        switch ($stage) {
            case 'none':
                if ($tier === 'dormant') {
                    return ['stage' => 'reengage1', 'stage_at' => $now, 'send_reengage' => true, 'reactivated' => $reactivated];
                }
                break;
            case 'reengage1':
                if ($elapsed >= $interval) {
                    return ['stage' => 'reengage2', 'stage_at' => $now, 'send_reengage' => true, 'reactivated' => false];
                }
                break;
            case 'reengage2':
                if ($elapsed >= $interval) {
                    return ['stage' => 'suppressed', 'stage_at' => $now, 'send_reengage' => false, 'reactivated' => false];
                }
                break;
        }
        return ['stage' => $stage, 'stage_at' => $stageAt, 'send_reengage' => false, 'reactivated' => $reactivated];
    }

    public function isWeeklyDay(\DateTimeImmutable $now): bool
    {
        return (int) $now->setTimezone($this->tz)->format('N') === (int) $this->cfg['weekly_day'];
    }

    public function localDate(\DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->tz)->format('Y-m-d');
    }

    public function includeInDaily(string $tier, string $stage, bool $weeklyDay): bool
    {
        if ($stage === 'suppressed') {
            return false;
        }
        if ($tier === 'active' || $tier === 'warm') {
            return true;
        }
        return $weeklyDay;
    }

    /**
     * Ghost post newsletters: everyone but dormant members (in the re-engagement
     * track) and suppressed ones. Cold still receives every post, last in line.
     */
    public function includeInPost(string $tier, string $stage): bool
    {
        return $stage !== 'suppressed' && $tier !== 'dormant';
    }

    /**
     * Shared send order: tier, then most recent engagement first, then email.
     * Rows need 'tier', 'engaged_at' (DateTimeImmutable) and 'email'.
     */
    public static function compareForSend(array $a, array $b): int
    {
        return [self::tierRank($a['tier']), -$a['engaged_at']->getTimestamp(), $a['email']]
            <=> [self::tierRank($b['tier']), -$b['engaged_at']->getTimestamp(), $b['email']];
    }

    public static function tierRank(string $tier): int
    {
        $i = array_search($tier, self::TIERS, true);
        return $i === false ? count(self::TIERS) : $i;
    }
}
