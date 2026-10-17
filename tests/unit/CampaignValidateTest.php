<?php

use LibreMailApi\Campaign\CampaignService;
use LibreMailApi\Campaign\Db;
use LibreMailApi\Campaign\EngagementPolicy;
use Psr\Log\NullLogger;

// validate() never touches the database (Db connects lazily).
$svc = new CampaignService(new Db([]), new EngagementPolicy([]), [], __DIR__, fn () => null, new NullLogger());
$ok = ['campaign_key' => 'daily-2026-10-17', 'subject' => 'AI news', 'html' => '<p>x <a href="%%unsubscribe_url%%">u</a></p>'];

test('a valid payload passes', function () use ($svc, $ok) {
    assertSame(null, $svc->validate($ok));
    assertSame(null, $svc->validate($ok + ['test_to' => ['a+1@example.com'], 'preheader' => 'p', 'text' => 't']));
});

test('html without a visible %%unsubscribe_url%% is rejected', function () use ($svc, $ok) {
    assertContains('unsubscribe_url', (string) $svc->validate(['html' => '<p>no link</p>'] + $ok));
});

test('bad campaign_key / subject / test_to are rejected', function () use ($svc, $ok) {
    assertTrue($svc->validate(['campaign_key' => 'has space'] + $ok) !== null);
    assertTrue($svc->validate(['campaign_key' => ''] + $ok) !== null);
    assertTrue($svc->validate(['subject' => '  '] + $ok) !== null);
    assertTrue($svc->validate($ok + ['test_to' => ['not-an-email']]) !== null);
    assertTrue($svc->validate($ok + ['test_to' => array_fill(0, 21, 'a@b.co')]) !== null);
});

test('oversized html is rejected', function () use ($ok) {
    $small = new CampaignService(new Db([]), new EngagementPolicy([]), ['max_html_bytes' => 100], __DIR__, fn () => null, new NullLogger());
    assertContains('exceeds', (string) $small->validate(['html' => str_repeat('x', 101) . '%%unsubscribe_url%%'] + $ok));
});
