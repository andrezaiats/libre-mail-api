<?php

require_once __DIR__ . '/support.php';

use LibreMailApi\Campaign\CampaignService;
use LibreMailApi\Campaign\Db;
use LibreMailApi\Campaign\EngagementPolicy;
use LibreMailApi\Campaign\PostRanker;
use Psr\Log\NullLogger;

$db = new Db(testMysqlConfig());
try {
    resetTestSchema($db);
} catch (\Throwable $e) {
    test('MySQL test schema is reachable (aipster_mail_test)', function () use ($e) {
        throw $e;
    });
    return;
}

$policy = new EngagementPolicy([], 'America/Sao_Paulo');
$service = new CampaignService($db, $policy, ['newsletter_slug' => 'default-newsletter'], __DIR__ . '/../../templates', function () {
}, new NullLogger());

addMember($db, 'Active1@Example.com', 2);
addMember($db, 'active2@example.com', 10);
addMember($db, 'warm1@example.com', 60, 15);
addMember($db, 'cold1@example.com', 120, 15);
addMember($db, 'dormant1@example.com', 400, 15);
$sup = addMember($db, 'suppressed1@example.com', 60, 15);
$db->pdo()->prepare("INSERT INTO member_state (member_id, tier, last_engaged_at, sends_since_engaged, sunset_stage, stage_at, updated_at) VALUES (?, 'warm', NOW(), 0, 'suppressed', NOW(), NOW())")
    ->execute([$sup['id']]);

$rank = fn (\DateTimeImmutable $now) => $service->rankAudience($now);
$ranker = new PostRanker($rank, $policy, new NullLogger());
$msg = fn (string $to, array $tags = ['bulk-email', 'ghost-email']) => [
    'message_id' => 'm1',
    'to' => $to,
    'recipients' => [],
    'tags_json' => json_encode($tags),
];

test('post batch is ordered active > warm > cold', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('cold1@example.com, warm1@example.com, active2@example.com, active1@example.com'));
    assertSame(['active1@example.com', 'active2@example.com', 'warm1@example.com', 'cold1@example.com'], $out['recipients']);
    assertSame(['active' => 2, 'warm' => 1, 'cold' => 1], $out['ranking_tiers']);
    assertSame('cold', $out['recipient_tiers']['cold1@example.com']);
});

test('dormant and suppressed are dropped', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('dormant1@example.com, suppressed1@example.com, warm1@example.com'));
    assertSame(['warm1@example.com'], $out['recipients']);
    assertSame(2, $out['ranking_skipped']);
});

test('mixed-case addresses keep their tier', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('Warm1@Example.com, ACTIVE1@example.com'));
    assertSame(['active1@example.com', 'warm1@example.com'], $out['recipients']);
    assertSame('active', $out['recipient_tiers']['active1@example.com']);
});

test('unknown address is placed between active and warm with tier all', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('cold1@example.com, brandnew@example.com, warm1@example.com, active1@example.com'));
    assertSame(['active1@example.com', 'brandnew@example.com', 'warm1@example.com', 'cold1@example.com'], $out['recipients']);
    assertSame('all', $out['recipient_tiers']['brandnew@example.com']);
});

test('unknown address alone is kept', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('brandnew@example.com'));
    assertSame(['brandnew@example.com'], $out['recipients']);
});

test('Name <email> tokens are preserved, including commas in quoted names', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('"Cold, One" <cold1@example.com>, Active One <active1@example.com>'));
    assertSame('Active One <active1@example.com>, "Cold, One" <cold1@example.com>', $out['to']);
    assertSame(['active1@example.com', 'cold1@example.com'], $out['recipients']);
});

test('all recipients dropped yields an empty list and a skip count', function () use ($ranker, $msg) {
    $out = $ranker->apply($msg('dormant1@example.com'));
    assertSame([], $out['recipients']);
    assertSame('', $out['to']);
    assertSame(1, $out['ranking_skipped']);
});

test('DB failure is retried once, then fails open with the message unchanged', function () use ($policy, $msg) {
    $calls = 0;
    $ranker = new PostRanker(function () use (&$calls) {
        $calls++;
        throw new \RuntimeException('db down');
    }, $policy, new NullLogger());
    $in = $msg('dormant1@example.com, warm1@example.com');
    assertSame($in, $ranker->apply($in));
    assertSame(2, $calls);
});

test('a transient failure on the first attempt still ranks', function () use ($policy, $msg, $rank) {
    $calls = 0;
    $ranker = new PostRanker(function ($now) use (&$calls, $rank) {
        if (++$calls === 1) {
            throw new \RuntimeException('blip');
        }
        return $rank($now);
    }, $policy, new NullLogger());
    $out = $ranker->apply($msg('cold1@example.com, active1@example.com'));
    assertSame(['active1@example.com', 'cold1@example.com'], $out['recipients']);
});

test('flag off and non-ghost messages are returned untouched', function () use ($policy, $msg, $rank) {
    $off = new PostRanker($rank, $policy, new NullLogger(), false);
    $in = $msg('cold1@example.com, active1@example.com');
    assertSame($in, $off->apply($in));
    $on = new PostRanker($rank, $policy, new NullLogger());
    $plain = $msg('cold1@example.com, active1@example.com', ['transactional']);
    assertSame($plain, $on->apply($plain));
});

test('sends_since counts delivered_at only, not processed-only rows', function () use ($db, $service) {
    $m = addMember($db, 'skipped@example.com', 400, 0);
    $at = gmdate('Y-m-d H:i:s', time() - 5 * 86400);
    for ($i = 0; $i < 12; $i++) {
        $db->pdo()->prepare('INSERT INTO email_recipients (id, member_id, delivered_at, processed_at) VALUES (?, ?, NULL, ?)')
            ->execute([fakeId(), $m['id'], $at]);
    }
    $row = $service->rankAudience(new DateTimeImmutable('now', new DateTimeZone('UTC')))['skipped@example.com'];
    assertSame('warm', $row['tier'], 'processed-only rows are not sends: <10 real sends keeps a 400-day-old member at warm (counting them would make it dormant)');
});
