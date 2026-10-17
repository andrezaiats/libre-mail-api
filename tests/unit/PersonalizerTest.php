<?php

use LibreMailApi\Campaign\Personalizer;
use LibreMailApi\Campaign\TokenSigner;

$p = new Personalizer('https://aipster.com/', new TokenSigner('unit-test-secret'));
$delivery = ['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'email' => 'ana@example.com', 'name' => 'Ana <b>Lima</b>'];
$campaign = [
    'subject' => 'Hi %%first_name|there%% — AI news',
    'preheader' => 'Top stories today',
    'html' => '<html><body><p>Hi %%first_name|there%%, %%name%%</p><a href="%%click:0%%">Story</a> <a href="%%unsubscribe_url%%">Unsubscribe</a> %%unknown|x%%</body></html>',
    'text' => "Hi %%first_name|there%%\nUnsubscribe: %%unsubscribe_url%%\n",
];

test('first_name fallback when the member has no name', function () use ($p, $campaign) {
    $r = $p->render($campaign, ['id' => '0f8fad5b-d9cb-469f-a165-70867728950e', 'email' => 'x@y.z', 'name' => null]);
    assertSame('Hi there — AI news', $r['subject']);
    assertContains('<p>Hi there, </p>', $r['html']);
});

test('names are HTML-escaped in html only', function () use ($p, $campaign, $delivery) {
    $r = $p->render($campaign, $delivery);
    assertContains('Hi Ana, Ana &lt;b&gt;Lima&lt;/b&gt;', $r['html']);
    assertSame('Hi Ana — AI news', $r['subject']);
    assertContains('Hi Ana', $r['text']);
});

test('click and unsubscribe placeholders become signed aipster.com URLs', function () use ($p, $campaign, $delivery) {
    $r = $p->render($campaign, $delivery);
    assertContains('href="https://aipster.com/t/c/', $r['html']);
    assertContains('href="https://aipster.com/t/u/', $r['html']);
    assertContains('Unsubscribe: https://aipster.com/t/u/', $r['text']);
    assertSame(1, preg_match('#^<https://aipster\.com/t/u/[A-Za-z0-9_-]+>$#', $r['list_unsubscribe']));
    assertNotContains('%%', $r['html']);
    assertContains('</a> x<img ', $r['html'], 'unknown placeholder renders its fallback');
});

test('open pixel is injected right before </body>', function () use ($p, $campaign, $delivery) {
    $r = $p->render($campaign, $delivery);
    assertSame(1, preg_match('#<img src="https://aipster\.com/t/co/[A-Za-z0-9_-]+"[^>]*></body>#', $r['html']));
});

test('preheader is inserted right after <body>', function () use ($p, $campaign, $delivery) {
    $r = $p->render($campaign, $delivery);
    assertSame(1, preg_match('#<body><div style="display:none;[^"]*">Top stories today#', $r['html']));
});

test('text alternative is generated from HTML', function () {
    $text = Personalizer::htmlToText('<html><head><style>p{}</style></head><body><h1>Title</h1><p>One &amp; two<br>three</p>'
        . '<ul><li>a</li><li>b</li></ul><a href="https://aipster.com/x">Read</a> <a href="%%unsubscribe_url%%">Unsubscribe</a></body></html>');
    assertContains("Title\n\nOne & two\nthree", $text);
    assertContains('- a', $text);
    assertContains('Read (https://aipster.com/x)', $text);
    assertContains('Unsubscribe (%%unsubscribe_url%%)', $text);
    assertNotContains('p{}', $text);
});
