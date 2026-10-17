<?php

namespace LibreMailApi;

/**
 * Per-recipient header helpers.
 *
 * Ghost sends `h:List-Unsubscribe: <%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>`
 * through its Mailgun client. Headers must get the same recipient-variable
 * substitution as the body, and Mailgun-only variables that can never resolve
 * here must be dropped instead of leaking literally into the message.
 */
class HeaderBuilder
{
    /** Replace %recipient.key% (Mailgun syntax) using the recipient's variables. */
    public static function resolveRecipientVars(string $value, array $vars): string
    {
        foreach ($vars as $key => $v) {
            if (is_scalar($v) || $v === null) {
                $value = str_replace('%recipient.' . $key . '%', (string) $v, $value);
            }
        }
        return $value;
    }

    /**
     * Keep only the `<...>` entries that are fully resolved http(s)/mailto URIs.
     * Returns the cleaned header value, or null when nothing usable is left.
     */
    public static function sanitizeListUnsubscribe(string $value): ?string
    {
        if (!preg_match_all('/<([^<>]*)>/', $value, $m)) {
            return null;
        }
        $kept = [];
        foreach ($m[1] as $uri) {
            $uri = trim($uri);
            // Unresolved variables look like %recipient.x% / %tag_unsubscribe_email%;
            // 3+ chars between the percent signs so %AB%-style URL escapes are not matched.
            if ($uri === '' || preg_match('/%[a-z][a-z_.]{2,}%/i', $uri)) {
                continue;
            }
            if (!preg_match('#^(https?://|mailto:)#i', $uri)) {
                continue;
            }
            $kept[] = '<' . $uri . '>';
        }
        return $kept ? implode(', ', $kept) : null;
    }

    /** True when the List-Unsubscribe value contains an https URI (required for RFC 8058 one-click). */
    public static function hasHttpsUri(string $listUnsubscribe): bool
    {
        return (bool) preg_match('#<https://#i', $listUnsubscribe);
    }

    /**
     * Gmail Feedback-ID: up to four colon-separated fields, last one is the sender id.
     * Fields are reduced to [a-z0-9-] so they never break the header syntax.
     */
    public static function feedbackId(string $type, string $segment, string $sender, string $stream = 'bulk'): string
    {
        $clean = static function (string $s): string {
            $s = strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $s));
            $s = trim($s, '-');
            return $s === '' ? 'na' : $s;
        };
        return implode(':', [$clean($type), $clean($segment), $clean($stream), $clean($sender)]);
    }

    /**
     * Apply recipient variables to a list of [name, value] header pairs and fix
     * up List-Unsubscribe / List-Unsubscribe-Post. Returns [[name, value], ...].
     */
    public static function personalizeHeaders(array $headers, array $vars): array
    {
        $out = [];
        $listUnsub = null;
        $hasPost = false;
        foreach ($headers as [$name, $value]) {
            $lname = strtolower($name);
            $value = self::resolveRecipientVars((string) $value, $vars);
            if ($lname === 'list-unsubscribe') {
                $listUnsub = self::sanitizeListUnsubscribe($value);
                if ($listUnsub !== null) {
                    $out[] = [$name, $listUnsub];
                }
                continue;
            }
            if ($lname === 'list-unsubscribe-post') {
                $hasPost = true;
                continue; // re-added below only when valid
            }
            $out[] = [$name, $value];
        }
        if ($hasPost && $listUnsub !== null && self::hasHttpsUri($listUnsub)) {
            $out[] = ['List-Unsubscribe-Post', 'List-Unsubscribe=One-Click'];
        }
        return $out;
    }
}
