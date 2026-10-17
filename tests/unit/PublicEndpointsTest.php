<?php

use LibreMailApi\Campaign\PublicEndpoints;

$trusted = PublicEndpoints::parseCidrs('172.18.0.0/16');

test('CF-Connecting-IP is used only when the peer is the trusted proxy (NPM)', function () use ($trusted) {
    $viaNpm = ['REMOTE_ADDR' => '172.18.0.5', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'];
    assertSame('203.0.113.9', PublicEndpoints::clientIp($viaNpm, $trusted));
});

test('a forged CF-Connecting-IP from an untrusted peer is ignored', function () use ($trusted) {
    $forged = ['REMOTE_ADDR' => '192.168.1.50', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'];
    assertSame('192.168.1.50', PublicEndpoints::clientIp($forged, $trusted));
});

test('X-Forwarded-For is the fallback behind the proxy; junk headers are ignored', function () use ($trusted) {
    assertSame('198.51.100.1', PublicEndpoints::clientIp(['REMOTE_ADDR' => '172.18.3.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1, 172.18.0.5'], $trusted));
    assertSame('172.18.3.4', PublicEndpoints::clientIp(['REMOTE_ADDR' => '172.18.3.4', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'], $trusted));
});

test('CIDR matching (v4 and v6)', function () {
    assertTrue(PublicEndpoints::ipInCidrs('172.18.255.1', PublicEndpoints::parseCidrs('172.18.0.0/16')));
    assertTrue(!PublicEndpoints::ipInCidrs('172.19.0.1', PublicEndpoints::parseCidrs('172.18.0.0/16')));
    assertTrue(PublicEndpoints::ipInCidrs('172.18.0.9', PublicEndpoints::parseCidrs('10.0.0.0/8, 172.18.0.0/20')));
    assertTrue(PublicEndpoints::ipInCidrs('2001:db8::1', PublicEndpoints::parseCidrs('2001:db8::/32')));
    assertTrue(!PublicEndpoints::ipInCidrs('2001:db9::1', PublicEndpoints::parseCidrs('2001:db8::/32')));
});

test('stored IPs are truncated (/24, /48)', function () {
    assertSame('203.0.113.0', PublicEndpoints::truncateIp('203.0.113.77'));
    assertSame('2001:db8:abcd::/48', PublicEndpoints::truncateIp('2001:db8:abcd:12::99'));
    assertSame(null, PublicEndpoints::truncateIp('nope'));
});

test('suspect clicks: empty UA, unknown send time, or within the scanner window', function () {
    $old = gmdate('Y-m-d H:i:s', time() - 3600);
    $fresh = gmdate('Y-m-d H:i:s', time() - 2);
    assertTrue(!PublicEndpoints::isSuspectClick($old, 'Mozilla/5.0', 10));
    assertTrue(PublicEndpoints::isSuspectClick($fresh, 'Mozilla/5.0', 10));
    assertTrue(PublicEndpoints::isSuspectClick($old, '', 10));
    assertTrue(PublicEndpoints::isSuspectClick(null, 'Mozilla/5.0', 10));
});

test('email masking on the unsubscribe page', function () {
    assertSame('a******@example.com', PublicEndpoints::maskEmail('andre.silva@example.com'));
    assertSame('j**@x.io', PublicEndpoints::maskEmail('jo@x.io'));
});
