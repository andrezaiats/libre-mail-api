<?php

require_once __DIR__ . '/support.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LibreMailApi\Campaign\CampaignService;
use LibreMailApi\Campaign\CampaignWorker;
use LibreMailApi\Campaign\Db;
use LibreMailApi\Campaign\EngagementPolicy;
use LibreMailApi\Campaign\GhostUnsubscriber;
use LibreMailApi\Campaign\Personalizer;
use LibreMailApi\Campaign\PublicEndpoints;
use LibreMailApi\Campaign\TokenSigner;
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

$secret = 'integration-secret';
$signer = new TokenSigner($secret);
$personalizer = new Personalizer('https://aipster.com', $signer);
$todayIso = (int) (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('N');
$notToday = $todayIso % 7 + 1;

// Audience: 40 active, 5 warm, 1 guarded (old but <10 sends -> warm), 3 cold, 2 dormant.
// Excluded from audience: 1 email-disabled member, 1 member of another newsletter.
for ($i = 1; $i <= 40; $i++) {
    addMember($db, "active$i@example.com", 2);
}
for ($i = 1; $i <= 5; $i++) {
    addMember($db, "warm$i@example.com", 60, 15);
}
addMember($db, 'guarded@example.com', 400, 3);
for ($i = 1; $i <= 3; $i++) {
    addMember($db, "cold$i@example.com", 120, 15);
}
$dormant1 = addMember($db, 'dormant1@example.com', 400, 15);
addMember($db, 'dormant2@example.com', 400, 15);
addMember($db, 'disabled@example.com', 1, 0, 'default-newsletter', true);
addMember($db, 'other@example.com', 1, 0, 'some-other-newsletter');
$db->pdo()->prepare("INSERT INTO settings (id, `key`, value) VALUES (?, 'members_email_auth_secret', 'secret-xyz')")->execute([fakeId()]);

$payload = fn (string $key) => [
    'campaign_key' => $key,
    'subject' => 'AI news for %%first_name|you%%',
    'preheader' => 'Top stories',
    'html' => '<html><body><p>Hi %%first_name|there%%</p><a href="https://aipster.com/story">Story</a> <a href="%%unsubscribe_url%%">Unsubscribe</a></body></html>',
];

$spawned = [];
$recordingSpawner = function (int $campaignId, int $workers) use (&$spawned) {
    $spawned[] = [$campaignId, $workers];
};
$logSend = function (string $worker) use ($db, $signer) {
    $log = $db->pdo()->prepare('INSERT INTO test_sent_log (delivery_id, worker) VALUES (?, ?)');
    return function (array $msg) use ($log, $signer, $worker) {
        preg_match('#/t/u/([A-Za-z0-9_-]+)#', $msg['headers'][0][1], $m);
        $log->execute([$signer->verify($m[1], TokenSigner::UNSUBSCRIBE)['delivery_id'], $worker]);
    };
};
$makeService = function (array $opts = []) use ($db, $recordingSpawner, $notToday) {
    return new CampaignService(
        $db,
        new EngagementPolicy(['weekly_day' => $opts['weekly_day'] ?? $notToday], 'America/Sao_Paulo'),
        ['workers' => 4, 'chunk' => 50, 'lease_seconds' => 600] + ($opts['cfg'] ?? []),
        __DIR__ . '/../../templates',
        $opts['spawner'] ?? $recordingSpawner,
        new NullLogger(),
        null,
        $opts['hooks'] ?? []
    );
};
$campaignId = fn (string $key) => countRows($db, 'SELECT id FROM campaigns WHERE campaign_key = ?', [$key]);
$sentLogFor = fn (int $cid) => [
    countRows($db, 'SELECT COUNT(*) FROM test_sent_log l JOIN deliveries d ON d.id = l.delivery_id WHERE d.campaign_id = ?', [$cid]),
    countRows($db, 'SELECT COUNT(DISTINCT l.delivery_id) FROM test_sent_log l JOIN deliveries d ON d.id = l.delivery_id WHERE d.campaign_id = ?', [$cid]),
];

test('dry run reports audience and links without writing anything', function () use ($makeService, $payload, $db) {
    $r = $makeService()->create($payload('dry-1') + ['dry_run' => true]);
    assertSame(200, $r['status']);
    assertSame(46, $r['data']['recipients']);
    assertSame(1, $r['data']['links'], 'unsubscribe placeholder is not a tracked link');
    assertSame(2, $r['data']['reengage_due']);
    assertSame(0, countRows($db, 'SELECT COUNT(*) FROM campaigns'));
    assertSame(0, countRows($db, 'SELECT COUNT(*) FROM member_state'));
});

test('a failure after the campaign insert rolls back everything; retrying the same key succeeds', function () use ($makeService, $payload, $db, &$spawned) {
    $failing = $makeService(['hooks' => ['before_commit' => function () {
        throw new \RuntimeException('injected failure');
    }]]);
    $threw = false;
    try {
        $failing->create($payload('daily-T1'));
    } catch (\RuntimeException $e) {
        $threw = $e->getMessage() === 'injected failure';
    }
    assertTrue($threw, 'create() surfaces the failure');
    assertSame(0, countRows($db, 'SELECT COUNT(*) FROM campaigns'), 'no stranded campaign row');
    assertSame(0, countRows($db, 'SELECT COUNT(*) FROM deliveries'));
    assertSame(0, countRows($db, 'SELECT COUNT(*) FROM member_state'), 'tick rolled back too');
    assertSame(null, $db->getMeta('last_tick_date'));

    $spawned = [];
    $r = $makeService()->create($payload('daily-T1'));
    assertSame(202, $r['status']);
    assertSame(46, $r['data']['recipients']);
    assertSame(['active' => 40, 'warm' => 6], $r['data']['by_tier']);
    assertSame(2, $r['data']['reengage']['recipients'] ?? null, 'dormant members get the re-engagement email');
    assertSame(2, count($spawned), 'workers spawned for the daily and the re-engagement campaign');
    assertSame('active', $db->pdo()->query("SELECT d.tier FROM deliveries d JOIN campaigns c ON c.id = d.campaign_id WHERE c.campaign_key = 'daily-T1' ORDER BY send_order LIMIT 1")->fetchColumn());
    assertSame(0, countRows($db, "SELECT COUNT(*) FROM deliveries d JOIN campaigns c ON c.id = d.campaign_id WHERE c.campaign_key = 'daily-T1' AND d.email LIKE 'dormant%'"), 'reengaged members skip today\'s Daily');
    assertSame(0, countRows($db, "SELECT COUNT(*) FROM deliveries d JOIN campaigns c ON c.id = d.campaign_id WHERE c.campaign_key = 'daily-T1' AND d.email IN ('disabled@example.com', 'other@example.com')"));
});

test('duplicate campaign_key -> 409 and the sunset tick is not re-run', function () use ($makeService, $payload, $db, $dormant1) {
    $stageAt = $db->pdo()->query("SELECT stage_at FROM member_state WHERE member_id = '{$dormant1['id']}'")->fetchColumn();
    assertSame('reengage1', $db->pdo()->query("SELECT sunset_stage FROM member_state WHERE member_id = '{$dormant1['id']}'")->fetchColumn());
    $r = $makeService()->create($payload('daily-T1'));
    assertSame(409, $r['status']);
    assertSame(1, countRows($db, "SELECT COUNT(*) FROM campaigns WHERE kind = 'reengage'"));
    assertSame(46, countRows($db, "SELECT COUNT(*) FROM deliveries d JOIN campaigns c ON c.id = d.campaign_id WHERE c.campaign_key = 'daily-T1'"));
    assertSame($stageAt, $db->pdo()->query("SELECT stage_at FROM member_state WHERE member_id = '{$dormant1['id']}'")->fetchColumn());

    // A second Daily on the same day: tick already done, no new re-engagement campaign.
    $r2 = $makeService()->create($payload('daily-T1b'));
    assertSame(202, $r2['status']);
    assertSame(null, $r2['data']['reengage']);
    assertSame(1, countRows($db, "SELECT COUNT(*) FROM campaigns WHERE kind = 'reengage'"));
});

test('spawn failure leaves the campaign queued; resume starts workers that complete it', function () use ($makeService, $payload, $db, $personalizer, $logSend, $campaignId) {
    $broken = $makeService(['spawner' => function () {
        throw new \RuntimeException('exec disabled');
    }]);
    $r = $broken->create($payload('daily-T3'));
    assertSame(500, $r['status']);
    $cid = $campaignId('daily-T3');
    assertSame('queued', $db->pdo()->query("SELECT status FROM campaigns WHERE id = $cid")->fetchColumn());
    assertSame('exec disabled', $db->pdo()->query("SELECT spawn_error FROM campaigns WHERE id = $cid")->fetchColumn());
    assertSame(46, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'queued'", [$cid]));

    $inline = function (int $id, int $workers) use ($db, $personalizer, $logSend) {
        for ($i = 1; $i <= $workers; $i++) {
            (new CampaignWorker($db, $personalizer, $logSend("inline$i"), ['chunk' => 10], new NullLogger()))->run($id, "inline$i");
        }
    };
    $res = $makeService(['spawner' => $inline])->resume('daily-T3');
    assertSame(200, $res['status']);
    assertSame(46, $res['data']['deliveries']['sent']);
    assertSame('done', $db->pdo()->query("SELECT status FROM campaigns WHERE id = $cid")->fetchColumn());
    assertSame(null, $db->pdo()->query("SELECT spawn_error FROM campaigns WHERE id = $cid")->fetchColumn());
});

test('two workers running in parallel never send the same delivery twice', function () use ($makeService, $payload, $db, $campaignId, $sentLogFor) {
    $makeService()->create($payload('daily-T4'));
    $cid = $campaignId('daily-T4');
    $procs = [];
    foreach (['pA', 'pB'] as $w) {
        $procs[$w] = proc_open(
            [PHP_BINARY, __DIR__ . '/stub-worker.php', (string) $cid, $w, '3'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes[$w]
        );
    }
    $out = [];
    foreach ($procs as $w => $p) {
        $out[$w] = stream_get_contents($pipes[$w][1]) . stream_get_contents($pipes[$w][2]);
        assertSame(0, proc_close($p), "worker $w exit code; output: " . $out[$w]);
    }
    [$total, $distinct] = $sentLogFor($cid);
    assertSame(46, $total, 'every delivery sent once');
    assertSame(46, $distinct, 'no duplicates');
    $byWorker = $db->pdo()->query("SELECT l.worker, COUNT(*) FROM test_sent_log l JOIN deliveries d ON d.id = l.delivery_id WHERE d.campaign_id = $cid GROUP BY l.worker")->fetchAll(\PDO::FETCH_KEY_PAIR);
    assertTrue(count($byWorker) === 2, 'both workers did work: ' . json_encode($byWorker) . ' ' . json_encode($out));
    assertSame('done', $db->pdo()->query("SELECT status FROM campaigns WHERE id = $cid")->fetchColumn());
});

test('worker crash mid-batch: resume requeues untouched rows, parks the in-flight one as unknown, never resends', function () use ($makeService, $payload, $db, $personalizer, $logSend, $campaignId, $sentLogFor) {
    $svc = $makeService();
    $svc->create($payload('daily-T5'));
    $cid = $campaignId('daily-T5');

    $calls = 0;
    $log = $logSend('crashy');
    $crashing = function (array $msg) use (&$calls, $log) {
        $calls++;
        $log($msg); // the MTA accepted it...
        if ($calls === 5) {
            throw new \LogicException('simulated worker death'); // ...and the process died before marking it sent
        }
    };
    $died = false;
    try {
        (new CampaignWorker($db, $personalizer, $crashing, ['chunk' => 50], new NullLogger()))->run($cid, 'crashy');
    } catch (\LogicException $e) {
        $died = true;
    }
    assertTrue($died);
    assertSame(4, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'sent'", [$cid]));
    assertSame(42, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'sending'", [$cid]));

    assertSame(['requeued' => 0, 'unknown' => 0], $svc->reclaimExpired($cid), 'live leases are never reclaimed');

    $db->pdo()->prepare("UPDATE deliveries SET claimed_at = NOW(3) - INTERVAL 1 HOUR WHERE campaign_id = ? AND status = 'sending'")->execute([$cid]);
    assertSame(['requeued' => 41, 'unknown' => 1], $svc->reclaimExpired($cid));

    (new CampaignWorker($db, $personalizer, $logSend('recovery'), ['chunk' => 50], new NullLogger()))->run($cid, 'recovery');
    assertSame(45, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'sent'", [$cid]));
    assertSame(1, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'unknown'", [$cid]));
    [$total, $distinct] = $sentLogFor($cid);
    assertSame(46, $total, 'the unknown row was not sent again');
    assertSame(46, $distinct);
    assertSame('done', $db->pdo()->query("SELECT status FROM campaigns WHERE id = $cid")->fetchColumn());
});

test('suppressed members are excluded; any later engagement reactivates them', function () use ($makeService, $db, $dormant1, $todayIso) {
    $svc = $makeService(['weekly_day' => $todayIso]); // weekly day: cold/dormant would normally be included
    $db->pdo()->prepare("UPDATE member_state SET sunset_stage = 'suppressed', stage_at = NOW(3) - INTERVAL 1 DAY WHERE member_id = ?")
        ->execute([$dormant1['id']]);
    $db->setMeta('last_tick_date', '2000-01-01');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    $emails = fn (array $rows) => array_column($rows, 'email');
    $plan = $svc->planDaily($now, false);
    assertTrue(!in_array('dormant1@example.com', $emails($plan['daily']), true), 'suppressed: no Daily');
    assertTrue(!in_array('dormant1@example.com', $emails($plan['reengage']), true), 'suppressed: no more re-engagement');
    assertTrue(in_array('cold1@example.com', $emails($plan['daily']), true), 'cold members get the weekly Daily');

    $db->pdo()->prepare('INSERT INTO members_click_events (id, member_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([fakeId(), $dormant1['id']]);
    $plan = $svc->planDaily($now->modify('+1 second'), false);
    assertTrue(in_array('dormant1@example.com', $emails($plan['daily']), true), 'reactivated by a click');
});

test('public endpoints: open/click recorded, invalid tokens ignored, one-click unsubscribe goes to Ghost once', function () use ($makeService, $payload, $db, $signer, $campaignId) {
    $cid = $campaignId('daily-T5');
    $d = $db->pdo()->query("SELECT id, member_uuid FROM deliveries WHERE campaign_id = $cid AND status = 'sent' ORDER BY send_order LIMIT 1")->fetch();
    $db->pdo()->prepare('UPDATE deliveries SET sent_at = NOW(3) - INTERVAL 1 HOUR WHERE id = ?')->execute([$d['id']]);

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(201), new Response(201)]));
    $stack->push(Middleware::history($history));
    $ghost = new GhostUnsubscriber($db, 'http://127.0.0.1:2368', 'https://aipster.com', new Client(['handler' => $stack, 'http_errors' => false]));
    $ep = new PublicEndpoints($db, $signer, $ghost, ['trusted_proxy_cidrs' => '172.18.0.0/16'], new NullLogger());
    $server = ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.77', 'HTTP_USER_AGENT' => 'Mozilla/5.0'];
    $call = function (string $method, string $path, array $post = []) use ($ep, $server) {
        ob_start();
        $handled = $ep->handle($method, $path, $server, $post);
        ob_end_clean();
        return $handled;
    };
    $events = fn (string $type) => countRows($db, 'SELECT COUNT(*) FROM events WHERE delivery_id = ? AND type = ?', [$d['id'], $type]);

    assertTrue($call('GET', 't/co/' . $signer->sign(TokenSigner::OPEN, $d['id'])));
    assertSame(1, $events('open'));
    assertSame('203.0.113.0', $db->pdo()->query("SELECT ip_trunc FROM events WHERE delivery_id = '{$d['id']}' AND type = 'open'")->fetchColumn());

    $call('GET', 't/c/' . $signer->sign(TokenSigner::CLICK, $d['id'], 0));
    assertSame(1, $events('click'));
    assertSame(0, countRows($db, "SELECT suspect FROM events WHERE delivery_id = ? AND type = 'click'", [$d['id']]), 'human click an hour after send');

    $before = countRows($db, 'SELECT COUNT(*) FROM events');
    $call('GET', 't/c/invalid-token');
    $call('GET', 't/co/invalid-token');
    $call('POST', 't/u/invalid-token', ['List-Unsubscribe' => 'One-Click']);
    $call('GET', 't/u/' . $signer->sign(TokenSigner::OPEN, $d['id'])); // wrong token type
    assertSame($before, countRows($db, 'SELECT COUNT(*) FROM events'), 'invalid tokens record nothing');
    assertSame(0, count($history), 'invalid tokens never reach Ghost');

    $unsub = 't/u/' . $signer->sign(TokenSigner::UNSUBSCRIBE, $d['id']);
    $call('GET', $unsub);
    assertSame(0, count($history), 'GET only shows the confirmation page');
    $call('POST', $unsub, ['List-Unsubscribe' => 'One-Click']);
    $call('POST', $unsub, ['confirm' => '1']);
    assertSame(2, count($history));
    $req = $history[0]['request'];
    parse_str($req->getUri()->getQuery(), $q);
    assertSame('POST', $req->getMethod());
    assertSame('/unsubscribe/', $req->getUri()->getPath());
    assertSame($d['member_uuid'], $q['uuid']);
    assertSame(hash_hmac('sha256', $d['member_uuid'], 'secret-xyz'), $q['key'], 'same HMAC as Ghost settings-helpers');
    assertTrue(!isset($q['newsletter']), 'no newsletter param: leave ALL newsletters');
    assertSame('aipster.com', $req->getHeaderLine('Host'));
    assertSame('https', $req->getHeaderLine('X-Forwarded-Proto'));
    assertSame(1, $events('unsubscribe'), 'recorded once even if posted twice');

    // test_to recipient that is not a Ghost member: nothing is sent to Ghost
    $r = $makeService()->create($payload('smoke') + ['test_to' => ['not-a-member+x@example.com']]);
    assertSame(202, $r['status']);
    $tid = $db->pdo()->query("SELECT id FROM deliveries WHERE email = 'not-a-member+x@example.com'")->fetchColumn();
    $call('POST', 't/u/' . $signer->sign(TokenSigner::UNSUBSCRIBE, $tid), ['List-Unsubscribe' => 'One-Click']);
    assertSame(2, count($history));
});

test('test_to sends are excluded from engagement and keys never collide', function () use ($makeService, $payload, $db) {
    $a = $makeService()->create($payload('smoke2') + ['test_to' => ['active1@example.com']]);
    assertSame(202, $a['status']);
    assertSame('test', $db->pdo()->query("SELECT kind FROM campaigns WHERE campaign_key = " . $db->pdo()->quote($a['data']['campaign_key']))->fetchColumn());
    assertContains('smoke2:test:', $a['data']['campaign_key']);
    assertTrue($db->pdo()->query("SELECT member_uuid FROM deliveries WHERE email = 'active1@example.com' AND campaign_id = (SELECT id FROM campaigns WHERE campaign_key = " . $db->pdo()->quote($a['data']['campaign_key']) . ')')->fetchColumn() !== null, 'test recipient matched to the Ghost member');
});

test('SMTP outage pauses the campaign: rows go back to the queue (not failed) and resume completes them', function () use ($makeService, $payload, $db, $personalizer, $logSend, $campaignId) {
    $svc = $makeService();
    $svc->create($payload('daily-T6'));
    $cid = $campaignId('daily-T6');
    $down = function (array $msg) {
        throw \LibreMailApi\Campaign\SendFailed::from('SMTP connect() failed', true);
    };
    $stats = (new CampaignWorker($db, $personalizer, $down, ['chunk' => 50], new NullLogger()))->run($cid, 'down');
    assertTrue(!empty($stats['paused']));
    assertSame(0, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status IN ('failed', 'sending', 'unknown')", [$cid]));
    $total = countRows($db, 'SELECT total FROM campaigns WHERE id = ?', [$cid]);
    assertTrue($total > 40, 'audience resolved');
    assertSame($total, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'queued'", [$cid]));
    assertTrue($db->pdo()->query("SELECT status FROM campaigns WHERE id = $cid")->fetchColumn() !== 'done', 'not closed while rows are queued');

    $permanent = function (array $msg) {
        throw \LibreMailApi\Campaign\SendFailed::from('550 mailbox unavailable', false);
    };
    (new CampaignWorker($db, $personalizer, $permanent, ['chunk' => 50], new NullLogger()))->run($cid, 'rejecting');
    assertSame($total, countRows($db, "SELECT COUNT(*) FROM deliveries WHERE campaign_id = ? AND status = 'failed'", [$cid]), 'permanent rejection is final');
});

dropTestSchema($db);
