<?php

namespace LibreMailApi\Campaign;

use LibreMailApi\TrackingHandler;
use Psr\Log\LoggerInterface;

/**
 * Unauthenticated campaign routes, reached via Cloudflare -> NPM (/t/) -> :8025.
 *
 *   GET  /t/co/<token>  open pixel (always a GIF)
 *   GET  /t/c/<token>   click redirect (only to URLs stored for the campaign)
 *   GET  /t/u/<token>   unsubscribe confirmation page
 *   POST /t/u/<token>   unsubscribe (RFC 8058 one-click or the page's button)
 *
 * Invalid tokens never record anything. Every response is no-store.
 */
class PublicEndpoints
{
    private Db $db;
    private TokenSigner $signer;
    private GhostUnsubscriber $unsubscriber;
    private array $cfg;
    private LoggerInterface $logger;

    public function __construct(Db $db, TokenSigner $signer, GhostUnsubscriber $unsubscriber, array $campaignsConfig, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->signer = $signer;
        $this->unsubscriber = $unsubscriber;
        $this->cfg = $campaignsConfig + [
            'suspect_click_seconds' => 10,
            'trusted_proxy_cidrs' => '172.18.0.0/16',
            'home_url' => 'https://aipster.com/',
        ];
        $this->logger = $logger;
    }

    /** Returns true when the path was handled. */
    public function handle(string $method, string $path, array $server, array $post): bool
    {
        if (!preg_match('#^t/(co|c|u)/([A-Za-z0-9_-]{1,512})/?$#', $path, $m)) {
            return false;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        [$route, $token] = [$m[1], $m[2]];
        $ip = self::truncateIp(self::clientIp($server, self::parseCidrs($this->cfg['trusted_proxy_cidrs'])));
        $ua = substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 512);

        try {
            switch ($route) {
                case 'co':
                    $this->open($token, $ip, $ua);
                    return true;
                case 'c':
                    $this->click($token, $ip, $ua);
                    return true;
                default:
                    $this->unsubscribe($method, $token, $ip, $ua, $post);
                    return true;
            }
        } catch (\Throwable $e) {
            $this->logger->error('public campaign endpoint failed', ['route' => $route, 'error' => $e->getMessage()]);
            if ($route === 'co') {
                TrackingHandler::outputPixel();
            } elseif ($route === 'c') {
                $this->redirect($this->cfg['home_url']);
            } else {
                $this->page(500, 'Something went wrong', '<p>We could not process your request. Please try again in a minute.</p>');
            }
            return true;
        }
    }

    private function open(string $token, ?string $ip, string $ua): void
    {
        $t = $this->signer->verify($token, TokenSigner::OPEN);
        if ($t && ($d = $this->delivery($t['delivery_id']))) {
            $this->event($d, 'open', null, $ip, $ua, false);
        }
        TrackingHandler::outputPixel();
    }

    private function click(string $token, ?string $ip, string $ua): void
    {
        $t = $this->signer->verify($token, TokenSigner::CLICK);
        $d = $t && $t['link_idx'] !== null ? $this->delivery($t['delivery_id']) : null;
        if (!$d) {
            $this->redirect($this->cfg['home_url']);
            return;
        }
        $st = $this->db->pdo()->prepare('SELECT url FROM campaign_links WHERE campaign_id = ? AND idx = ?');
        $st->execute([$d['campaign_id'], $t['link_idx']]);
        $url = $st->fetchColumn();
        if (!is_string($url) || !preg_match('#^https?://#i', $url)) {
            $this->redirect($this->cfg['home_url']);
            return;
        }
        $this->event($d, 'click', $t['link_idx'], $ip, $ua, self::isSuspectClick($d['sent_at'], $ua, (int) $this->cfg['suspect_click_seconds']));
        $this->redirect($url);
    }

    private function unsubscribe(string $method, string $token, ?string $ip, string $ua, array $post): void
    {
        $t = $this->signer->verify($token, TokenSigner::UNSUBSCRIBE);
        $d = $t ? $this->delivery($t['delivery_id']) : null;
        if (!$d) {
            $this->page(400, 'Invalid link', '<p>This link is invalid or has expired.</p>');
            return;
        }

        $oneClick = $method === 'POST' && (($post['List-Unsubscribe'] ?? '') === 'One-Click');

        if ($method !== 'POST') {
            $email = htmlspecialchars(self::maskEmail($d['email']), ENT_QUOTES, 'UTF-8');
            $this->page(200, 'Unsubscribe', '<p>Unsubscribe <strong>' . $email . '</strong> from all AIpster emails?</p>'
                . '<p class="muted">This stops both the AIpster Daily and the AIpster newsletter.</p>'
                . '<form method="post"><input type="hidden" name="confirm" value="1">'
                . '<button type="submit">Unsubscribe</button></form>');
            return;
        }

        if (empty($d['member_uuid'])) {
            // test_to recipient that is not a Ghost member: nothing to unsubscribe
            if ($oneClick) {
                http_response_code(200);
                return;
            }
            $this->page(200, 'Test link', '<p>This was a test email to an address that is not an AIpster member, so there is nothing to unsubscribe.</p>');
            return;
        }

        $ok = false;
        try {
            $ok = $this->unsubscriber->unsubscribeAll($d['member_uuid']);
        } catch (\Throwable $e) {
            $this->logger->error('ghost unsubscribe call failed', ['delivery_id' => $d['id'], 'error' => $e->getMessage()]);
        }

        if (!$ok) {
            $this->event($d, 'unsubscribe_failed', null, $ip, $ua, false);
            if ($oneClick) {
                http_response_code(500);
                return;
            }
            $this->page(500, 'Something went wrong', '<p>We could not unsubscribe you right now. Please try again in a minute.</p>');
            return;
        }

        $exists = $this->db->pdo()->prepare("SELECT 1 FROM events WHERE delivery_id = ? AND type = 'unsubscribe' LIMIT 1");
        $exists->execute([$d['id']]);
        if (!$exists->fetchColumn()) {
            $this->event($d, 'unsubscribe', null, $ip, $ua, false);
        }
        $this->logger->info('member unsubscribed', ['delivery_id' => $d['id'], 'one_click' => $oneClick]);

        if ($oneClick) {
            http_response_code(200);
            return;
        }
        $this->page(200, 'You are unsubscribed', '<p>You will no longer receive AIpster emails.</p>'
            . '<p class="muted">Changed your mind? Sign in at <a href="https://aipster.com/#/portal/account">aipster.com</a> to subscribe again.</p>');
    }

    // ------------------------------------------------------------------ helpers

    private function delivery(string $id): ?array
    {
        $st = $this->db->pdo()->prepare('SELECT id, campaign_id, member_id, member_uuid, email, sent_at FROM deliveries WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    private function event(array $d, string $type, ?int $linkIdx, ?string $ip, string $ua, bool $suspect): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO events (delivery_id, campaign_id, member_id, type, link_idx, ip_trunc, user_agent, suspect) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$d['id'], $d['campaign_id'], $d['member_id'], $type, $linkIdx, $ip, $ua === '' ? null : $ua, $suspect ? 1 : 0]);
    }

    private function redirect(string $url): void
    {
        http_response_code(302);
        header('Location: ' . $url);
    }

    private function page(int $status, string $title, string $bodyHtml): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
            . "<title>$t · AIpster</title><style>"
            . 'body{margin:0;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#15171a;color:rgba(255,255,255,.92);display:flex;min-height:100vh;align-items:center;justify-content:center}'
            . 'main{max-width:440px;padding:40px 32px;border:1px solid hsla(0,0%,100%,.15);border-radius:12px;text-align:center}'
            . 'h1{font-size:22px;margin:0 0 16px}.muted{color:hsla(0,0%,100%,.64);font-size:14px}a{color:#22D3EE}'
            . 'button{background:#22D3EE;color:#0b0c0e;border:0;border-radius:8px;padding:12px 28px;font-size:16px;font-weight:600;cursor:pointer}'
            . "</style></head><body><main><h1>$t</h1>$bodyHtml</main></body></html>";
    }

    public static function isSuspectClick(?string $sentAt, string $ua, int $windowSeconds): bool
    {
        if (trim($ua) === '' || $sentAt === null) {
            return true;
        }
        $sent = strtotime($sentAt . ' UTC');
        return $sent === false || (time() - $sent) < $windowSeconds;
    }

    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at < 1) {
            return '***';
        }
        return substr($email, 0, 1) . str_repeat('*', max(2, min(6, $at - 1))) . substr($email, $at);
    }

    /** @return array<int, array{0: string, 1: int}> */
    public static function parseCidrs(string $list): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $list))) as $c) {
            [$net, $bits] = array_pad(explode('/', $c, 2), 2, null);
            if (filter_var($net, FILTER_VALIDATE_IP)) {
                $max = strpos($net, ':') !== false ? 128 : 32;
                $out[] = [$net, $bits === null ? $max : (int) $bits];
            }
        }
        return $out;
    }

    public static function ipInCidrs(string $ip, array $cidrs): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($cidrs as [$net, $bits]) {
            $nbin = @inet_pton($net);
            if ($nbin === false || strlen($nbin) !== strlen($bin)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($nbin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($nbin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** CF-Connecting-IP / X-Forwarded-For are only believed when the peer is a trusted proxy (NPM). */
    public static function clientIp(array $server, array $trustedCidrs): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && self::ipInCidrs($remote, $trustedCidrs)) {
            $cf = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
            if (filter_var($cf, FILTER_VALIDATE_IP)) {
                return $cf;
            }
            $xff = trim(explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
            if (filter_var($xff, FILTER_VALIDATE_IP)) {
                return $xff;
            }
        }
        return $remote;
    }

    /** IPv4 -> /24, IPv6 -> /48 (data minimisation). */
    public static function truncateIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = explode('.', $ip);
            return "{$p[0]}.{$p[1]}.{$p[2]}.0";
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $bin = inet_pton($ip);
            return inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48';
        }
        return null;
    }
}
