<?php

use LibreMailApi\Campaign\EngagementPolicy;

$policy = new EngagementPolicy([], 'America/Sao_Paulo');
$utc = new DateTimeZone('UTC');
// Wednesday 2026-10-14 12:00 UTC
$now = new DateTimeImmutable('2026-10-14 12:00:00', $utc);
$daysAgo = fn (int $d) => $now->modify("-$d days");

test('tier boundaries at 30 / 90 / 180 days', function () use ($policy, $now, $daysAgo) {
    assertSame('active', $policy->tier($now, $daysAgo(0), 50));
    assertSame('active', $policy->tier($now, $daysAgo(30), 50));
    assertSame('warm', $policy->tier($now, $daysAgo(31), 50));
    assertSame('warm', $policy->tier($now, $daysAgo(90), 50));
    assertSame('cold', $policy->tier($now, $daysAgo(91), 50));
    assertSame('cold', $policy->tier($now, $daysAgo(180), 50));
    assertSame('dormant', $policy->tier($now, $daysAgo(181), 50));
});

test('nobody cools below warm before 10 sends since last engagement', function () use ($policy, $now, $daysAgo) {
    assertSame('warm', $policy->tier($now, $daysAgo(400), 9));
    assertSame('warm', $policy->tier($now, $daysAgo(120), 0));
    assertSame('dormant', $policy->tier($now, $daysAgo(400), 10));
    assertSame('active', $policy->tier($now, $daysAgo(5), 0), 'guard never promotes');
});

test('dormant -> reengage1 -> (14d) reengage2 -> (14d) suppressed', function () use ($policy, $now, $daysAgo) {
    $engaged = $daysAgo(400);
    $a = $policy->advance('none', null, 'dormant', $engaged, $now);
    assertSame(['reengage1', true], [$a['stage'], $a['send_reengage']]);

    $b = $policy->advance('reengage1', $now, 'dormant', $engaged, $now->modify('+13 days'));
    assertSame(['reengage1', false], [$b['stage'], $b['send_reengage']], 'waits 14 days');

    $c = $policy->advance('reengage1', $now, 'dormant', $engaged, $now->modify('+14 days'));
    assertSame(['reengage2', true], [$c['stage'], $c['send_reengage']]);

    $d = $policy->advance('reengage2', $now->modify('+14 days'), 'dormant', $engaged, $now->modify('+27 days'));
    assertSame('reengage2', $d['stage']);

    $e = $policy->advance('reengage2', $now->modify('+14 days'), 'dormant', $engaged, $now->modify('+28 days'));
    assertSame(['suppressed', false], [$e['stage'], $e['send_reengage']]);

    $f = $policy->advance('suppressed', $now->modify('+28 days'), 'dormant', $engaged, $now->modify('+90 days'));
    assertSame('suppressed', $f['stage'], 'stays suppressed without engagement');
});

test('any engagement after the stage started reactivates (even from suppressed)', function () use ($policy, $now) {
    $stageAt = $now->modify('-20 days');
    $r = $policy->advance('suppressed', $stageAt, 'active', $now->modify('-1 day'), $now);
    assertSame(['none', null, true, false], [$r['stage'], $r['stage_at'], $r['reactivated'], $r['send_reengage']]);
});

test('non-dormant members never enter the flow', function () use ($policy, $now, $daysAgo) {
    foreach (['active', 'warm', 'cold'] as $tier) {
        $r = $policy->advance('none', null, $tier, $daysAgo(100), $now);
        assertSame(['none', false], [$r['stage'], $r['send_reengage']], $tier);
    }
});

test('cold/dormant get the Daily only on Monday (America/Sao_Paulo)', function () use ($policy, $utc) {
    $mondayLocal = new DateTimeImmutable('2026-10-19 06:00:00', new DateTimeZone('America/Sao_Paulo'));
    $sundayNightUtcMondayLocal = new DateTimeImmutable('2026-10-19 02:30:00', $utc); // still Sunday 23:30 in SP
    assertTrue($policy->isWeeklyDay($mondayLocal));
    assertTrue(!$policy->isWeeklyDay($sundayNightUtcMondayLocal), 'uses local, not UTC, weekday');
    assertTrue($policy->includeInDaily('cold', 'none', true));
    assertTrue(!$policy->includeInDaily('cold', 'none', false));
    assertTrue(!$policy->includeInDaily('dormant', 'reengage1', false));
    assertTrue($policy->includeInDaily('warm', 'none', false));
    assertTrue(!$policy->includeInDaily('active', 'suppressed', true), 'suppressed never gets the Daily');
});
