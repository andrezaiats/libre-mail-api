<?php

namespace LibreMailApi\Campaign;

/**
 * Replaces http(s) hrefs with %%click:N%% placeholders, once per campaign.
 *
 * Regex instead of DOMDocument on purpose: DOMDocument rewrites email HTML
 * (doctype, entities, Outlook conditional comments) in ways that break layouts.
 */
class LinkRewriter
{
    /**
     * @return array{html: string, links: string[]} links indexed by N (0-based); identical URLs share an index
     */
    public static function rewrite(string $html): array
    {
        $links = [];
        $index = [];
        // 1: "<a ... href=" (kept verbatim), 2: quote, 3: URL, 4: rest of the tag incl. ">"
        $pattern = '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2([^>]*>)/is';

        $out = preg_replace_callback($pattern, function (array $m) use (&$links, &$index) {
            $attrs = $m[1] . ' ' . $m[4];
            $raw = trim($m[3]);
            if (stripos($attrs, 'data-no-track') !== false || strpos($raw, '%%') !== false) {
                return $m[0];
            }
            $url = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('#^https?://[^\s]+$#i', $url)) {
                return $m[0]; // mailto:, tel:, #anchor, relative, javascript:, ...
            }
            if (!isset($index[$url])) {
                $index[$url] = count($links);
                $links[] = $url;
            }
            return $m[1] . $m[2] . '%%click:' . $index[$url] . '%%' . $m[2] . $m[4];
        }, $html);

        return ['html' => $out ?? $html, 'links' => $links];
    }
}
