<?php

namespace LibreMailApi\Campaign;

/**
 * Renders one recipient's copy of a campaign.
 *
 * Placeholders (n8n-safe, no {{ }}):
 *   %%name%%  %%first_name%%  %%email%%  — optional fallback: %%first_name|there%%
 *   %%unsubscribe_url%%  %%click:N%% (inserted by LinkRewriter)
 * Unknown %%word%% placeholders render as their fallback or empty string.
 */
class Personalizer
{
    private string $publicUrl;
    private TokenSigner $signer;

    public function __construct(string $publicUrl, TokenSigner $signer)
    {
        $this->publicUrl = rtrim($publicUrl, '/');
        $this->signer = $signer;
    }

    public function unsubscribeUrl(string $deliveryId): string
    {
        return $this->publicUrl . '/t/u/' . $this->signer->sign(TokenSigner::UNSUBSCRIBE, $deliveryId);
    }

    public function clickUrl(string $deliveryId, int $idx): string
    {
        return $this->publicUrl . '/t/c/' . $this->signer->sign(TokenSigner::CLICK, $deliveryId, $idx);
    }

    public function openUrl(string $deliveryId): string
    {
        return $this->publicUrl . '/t/co/' . $this->signer->sign(TokenSigner::OPEN, $deliveryId);
    }

    /**
     * @param array $campaign row: subject, preheader, html (already link-rewritten), text
     * @param array $delivery row: id, email, name
     * @return array{subject: string, html: string, text: string, list_unsubscribe: string}
     */
    public function render(array $campaign, array $delivery): array
    {
        $deliveryId = $delivery['id'];
        $vars = [
            'name' => trim((string) ($delivery['name'] ?? '')),
            'first_name' => self::firstName((string) ($delivery['name'] ?? '')),
            'email' => (string) $delivery['email'],
            'unsubscribe_url' => $this->unsubscribeUrl($deliveryId),
        ];

        $subject = str_replace(["\r", "\n"], ' ', $this->fill((string) $campaign['subject'], $vars, $deliveryId, false));
        $html = $this->fill((string) $campaign['html'], $vars, $deliveryId, true);
        $html = self::insertPreheader($html, (string) ($campaign['preheader'] ?? ''));
        $html = self::insertPixel($html, $this->openUrl($deliveryId));
        $text = $this->fill((string) ($campaign['text'] ?? ''), $vars, $deliveryId, false);

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'list_unsubscribe' => '<' . $vars['unsubscribe_url'] . '>',
        ];
    }

    private function fill(string $content, array $vars, string $deliveryId, bool $html): string
    {
        return preg_replace_callback(
            '/%%([a-z_]+)(?::(\d+))?(?:\|([^%]*))?%%/i',
            function (array $m) use ($vars, $deliveryId, $html) {
                $key = strtolower($m[1]);
                if ($key === 'click' && isset($m[2]) && $m[2] !== '') {
                    return $this->clickUrl($deliveryId, (int) $m[2]);
                }
                $fallback = isset($m[3]) ? html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
                $value = $vars[$key] ?? '';
                if ($value === '') {
                    $value = $fallback;
                }
                if ($html && $key !== 'unsubscribe_url') {
                    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                return $value;
            },
            $content
        ) ?? $content;
    }

    public static function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $name);
        return $parts[0] ?? '';
    }

    public static function insertPixel(string $html, string $pixelUrl): string
    {
        $img = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8')
            . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $img . substr($html, $pos);
        }
        return $html . $img;
    }

    public static function insertPreheader(string $html, string $preheader): string
    {
        $preheader = trim($preheader);
        if ($preheader === '') {
            return $html;
        }
        // Trailing zero-width spacers stop clients from pulling body text into the preview.
        $div = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
            . htmlspecialchars($preheader, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . str_repeat('&#8199;&#65279;&#847; ', 40)
            . '</div>';
        if (preg_match('/<body\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $at) . $div . substr($html, $at);
        }
        return $div . $html;
    }

    /** Plain-text alternative generated from the original (not link-rewritten) HTML. */
    public static function htmlToText(string $html): string
    {
        $s = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $s = preg_replace('#<!--.*?-->#s', '', $s) ?? $s;
        $s = preg_replace_callback(
            '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            function (array $m) {
                $href = html_entity_decode(trim($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($href === '' || $href[0] === '#' || stripos($href, 'mailto:') === 0) {
                    return $label;
                }
                return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
            },
            $s
        ) ?? $s;
        $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
        $s = preg_replace('#</(p|div|h[1-6]|li|tr|table|blockquote|section|article)>#i', "\n\n", $s) ?? $s;
        $s = preg_replace('#<li\b[^>]*>#i', '- ', $s) ?? $s;
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = str_replace("\u{00A0}", ' ', $s);
        $lines = array_map(fn ($l) => trim(preg_replace('/[ \t]+/', ' ', $l) ?? $l), explode("\n", $s));
        $s = implode("\n", $lines);
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return trim($s) . "\n";
    }
}
