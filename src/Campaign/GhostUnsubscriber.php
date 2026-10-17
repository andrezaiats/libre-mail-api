<?php

namespace LibreMailApi\Campaign;

use GuzzleHttp\Client;

/**
 * Unsubscribes a member from ALL Ghost newsletters through Ghost's own RFC 8058
 * endpoint (POST /unsubscribe/?uuid=..&key=.. without `newsletter`), so Ghost
 * records the change in members_subscribe_events like any other unsubscribe.
 */
class GhostUnsubscriber
{
    private Db $db;
    private string $ghostInternalUrl;
    private string $publicHost;
    private Client $http;
    private ?string $secret = null;

    public function __construct(Db $db, string $ghostInternalUrl, string $publicUrl, ?Client $http = null)
    {
        $this->db = $db;
        $this->ghostInternalUrl = rtrim($ghostInternalUrl, '/');
        $this->publicHost = (string) parse_url($publicUrl, PHP_URL_HOST);
        $this->http = $http ?? new Client(['timeout' => 10, 'http_errors' => false, 'allow_redirects' => false]);
    }

    /** hex(HMAC_SHA256(members_email_auth_secret, uuid)) — same as Ghost's settings-helpers createUnsubscribeUrl. */
    public function key(string $uuid): string
    {
        return hash_hmac('sha256', $uuid, $this->secret());
    }

    public function unsubscribeAll(string $uuid): bool
    {
        $res = $this->http->request('POST', $this->ghostInternalUrl . '/unsubscribe/', [
            'query' => ['uuid' => $uuid, 'key' => $this->key($uuid)],
            'headers' => [
                'Host' => $this->publicHost,
                'X-Forwarded-Proto' => 'https',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => 'List-Unsubscribe=One-Click',
        ]);
        return $res->getStatusCode() === 201;
    }

    private function secret(): string
    {
        if ($this->secret === null) {
            $st = $this->db->pdo()->prepare(
                'SELECT value FROM ' . $this->db->ghost() . ".settings WHERE `key` = 'members_email_auth_secret'"
            );
            $st->execute();
            $v = $st->fetchColumn();
            if (!is_string($v) || $v === '') {
                throw new \RuntimeException('members_email_auth_secret not found in Ghost settings');
            }
            $this->secret = $v;
        }
        return $this->secret;
    }
}
