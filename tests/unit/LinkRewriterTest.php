<?php

use LibreMailApi\Campaign\LinkRewriter;

test('http(s) links become %%click:N%% and identical URLs share an index', function () {
    $html = '<a href="https://aipster.com/a">A</a> <a class="x" href="http://example.com/b">B</a> <a href="https://aipster.com/a">A again</a>';
    $r = LinkRewriter::rewrite($html);
    assertSame(['https://aipster.com/a', 'http://example.com/b'], $r['links']);
    assertSame('<a href="%%click:0%%">A</a> <a class="x" href="%%click:1%%">B</a> <a href="%%click:0%%">A again</a>', $r['html']);
});

test('mailto, tel, anchors, placeholders and data-no-track are left alone', function () {
    $html = '<a href="mailto:x@y.z">m</a><a href="tel:+55">t</a><a href="#top">#</a>'
        . '<a href="%%unsubscribe_url%%">u</a><a data-no-track href="https://aipster.com/keep">k</a><a href="/relative">r</a>';
    $r = LinkRewriter::rewrite($html);
    assertSame([], $r['links']);
    assertSame($html, $r['html']);
});

test('&amp; in hrefs is decoded in the stored URL', function () {
    $r = LinkRewriter::rewrite('<a href="https://x.test/?a=1&amp;b=2">x</a>');
    assertSame(['https://x.test/?a=1&b=2'], $r['links']);
});

test('single quotes, uppercase tags and attributes after href', function () {
    $r = LinkRewriter::rewrite("<A HREF='https://x.test/p' target='_blank'>x</A>");
    assertSame(['https://x.test/p'], $r['links']);
    assertSame("<A HREF='%%click:0%%' target='_blank'>x</A>", $r['html']);
});
