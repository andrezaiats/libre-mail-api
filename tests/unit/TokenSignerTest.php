<?php

use LibreMailApi\Campaign\TokenSigner;

$signer = new TokenSigner('unit-test-secret');
$did = '0f8fad5b-d9cb-469f-a165-70867728950e';

test('round-trip for open/click/unsubscribe tokens', function () use ($signer, $did) {
    assertSame(['delivery_id' => $did, 'link_idx' => null], $signer->verify($signer->sign(TokenSigner::OPEN, $did), TokenSigner::OPEN));
    assertSame(['delivery_id' => $did, 'link_idx' => 7], $signer->verify($signer->sign(TokenSigner::CLICK, $did, 7), TokenSigner::CLICK));
    assertSame(['delivery_id' => $did, 'link_idx' => null], $signer->verify($signer->sign(TokenSigner::UNSUBSCRIBE, $did), TokenSigner::UNSUBSCRIBE));
});

test('tokens are URL-safe', function () use ($signer, $did) {
    assertTrue((bool) preg_match('/^[A-Za-z0-9_-]+$/', $signer->sign(TokenSigner::CLICK, $did, 12)));
});

test('tampered MAC is rejected', function () use ($signer, $did) {
    $raw = TokenSigner::b64urlDecode($signer->sign(TokenSigner::OPEN, $did));
    $tampered = TokenSigner::b64urlEncode(substr($raw, 0, -1) . (substr($raw, -1) === 'a' ? 'b' : 'a'));
    assertSame(null, $signer->verify($tampered, TokenSigner::OPEN));
});

test('tampered link index is rejected', function () use ($signer, $did) {
    $raw = TokenSigner::b64urlDecode($signer->sign(TokenSigner::CLICK, $did, 1));
    $tampered = TokenSigner::b64urlEncode(str_replace('.1.', '.2.', $raw));
    assertSame(null, $signer->verify($tampered, TokenSigner::CLICK));
});

test('an open token cannot be used as an unsubscribe token', function () use ($signer, $did) {
    assertSame(null, $signer->verify($signer->sign(TokenSigner::OPEN, $did), TokenSigner::UNSUBSCRIBE));
});

test('a token signed with another secret is rejected', function () use ($signer, $did) {
    $other = new TokenSigner('other-secret');
    assertSame(null, $signer->verify($other->sign(TokenSigner::UNSUBSCRIBE, $did), TokenSigner::UNSUBSCRIBE));
});

test('garbage tokens are rejected', function () use ($signer) {
    assertSame(null, $signer->verify('invalid-token', TokenSigner::UNSUBSCRIBE));
    assertSame(null, $signer->verify('', TokenSigner::OPEN));
    assertSame(null, $signer->verify('a/b', TokenSigner::OPEN));
});
