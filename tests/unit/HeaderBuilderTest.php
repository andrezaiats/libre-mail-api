<?php

use LibreMailApi\HeaderBuilder;

// Shape captured from a real Ghost newsletter batch (storage/messages/*.json)
$ghostHeaders = [
    ['Reply-To', '"AIpster" <noreply@aipster.com>'],
    ['Sender', '"AIpster" <noreply@aipster.com>'],
    ['Auto-Submitted', 'auto-generated'],
    ['List-Unsubscribe', '<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>'],
    ['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'],
];
$ghostVars = [
    'uuid' => '53c87cdb-180d-477b-932e-3ef65fa59328',
    'key' => 'f2592a205682b7c025562b1bdd48cf661da57bef492a73245e3bf8498caba421',
    'unsubscribe_url' => 'https://aipster.com/unsubscribe/?uuid=53c87cdb-180d-477b-932e-3ef65fa59328&key=f2592a205682b7c025562b1bdd48cf661da57bef492a73245e3bf8498caba421&newsletter=db0ef38b-cf1e-4017-b5a9-cbcb6d5f21b8',
    'list_unsubscribe' => 'https://aipster.com/unsubscribe/?uuid=53c87cdb-180d-477b-932e-3ef65fa59328&key=f2592a205682b7c025562b1bdd48cf661da57bef492a73245e3bf8498caba421&newsletter=db0ef38b-cf1e-4017-b5a9-cbcb6d5f21b8',
];

function headerMap(array $pairs): array
{
    $out = [];
    foreach ($pairs as [$n, $v]) {
        $out[strtolower($n)] = $v;
    }
    return $out;
}

test('Ghost %recipient.list_unsubscribe% is resolved and Mailgun-only %tag_unsubscribe_email% dropped', function () use ($ghostHeaders, $ghostVars) {
    $h = headerMap(HeaderBuilder::personalizeHeaders($ghostHeaders, $ghostVars));
    assertSame('<' . $ghostVars['list_unsubscribe'] . '>', $h['list-unsubscribe']);
    assertSame('List-Unsubscribe=One-Click', $h['list-unsubscribe-post']);
    assertNotContains('%', $h['list-unsubscribe']);
    assertSame('"AIpster" <noreply@aipster.com>', $h['reply-to'], 'other headers untouched');
});

test('List-Unsubscribe and -Post are dropped when nothing resolves', function () use ($ghostHeaders) {
    $h = headerMap(HeaderBuilder::personalizeHeaders($ghostHeaders, []));
    assertTrue(!isset($h['list-unsubscribe']), 'no List-Unsubscribe with literal placeholders');
    assertTrue(!isset($h['list-unsubscribe-post']), 'no -Post without a URI');
    assertSame('auto-generated', $h['auto-submitted']);
});

test('-Post is dropped when only a mailto URI is left', function () {
    $h = headerMap(HeaderBuilder::personalizeHeaders([
        ['List-Unsubscribe', '<mailto:unsub@aipster.com>'],
        ['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'],
    ], []));
    assertSame('<mailto:unsub@aipster.com>', $h['list-unsubscribe']);
    assertTrue(!isset($h['list-unsubscribe-post']));
});

test('percent-encoded URLs are not mistaken for placeholders', function () {
    assertSame('<https://x.test/u?a=%2Fb%3D&c=%AB%CD>', HeaderBuilder::sanitizeListUnsubscribe('<https://x.test/u?a=%2Fb%3D&c=%AB%CD>'));
});

test('Feedback-ID format and sanitising', function () {
    assertSame('newsletter:all:bulk:aipster', HeaderBuilder::feedbackId('newsletter', 'all', 'aipster'));
    assertSame('daily:warm:bulk:aipster', HeaderBuilder::feedbackId('daily', 'warm', 'aipster'));
    assertSame('re-engage:na:bulk:aipster', HeaderBuilder::feedbackId('re engage:', '', 'aipster'));
});
