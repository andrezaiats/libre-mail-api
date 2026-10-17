<?php

namespace LibreMailApi\Campaign;

/**
 * Signed, typed tokens for campaign tracking URLs.
 *
 * Token = base64url("<type>.<deliveryId>.<linkIdx>.<mac>") where mac is a
 * truncated HMAC-SHA256 over everything before it. The type is covered by the
 * MAC, so an open-pixel token can never be replayed as an unsubscribe token.
 */
class TokenSigner
{
    public const OPEN = 'o';
    public const CLICK = 'c';
    public const UNSUBSCRIBE = 'u';

    private const MAC_HEX_LEN = 32; // 128 bits

    private string $secret;

    public function __construct(string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Token secret must not be empty');
        }
        $this->secret = $secret;
    }

    public function sign(string $type, string $deliveryId, ?int $linkIdx = null): string
    {
        $payload = 'v1.' . $type . '.' . $deliveryId . '.' . ($linkIdx === null ? '' : (string) $linkIdx);
        return self::b64urlEncode($payload . '.' . $this->mac($payload));
    }

    /**
     * @return array{delivery_id: string, link_idx: ?int}|null
     */
    public function verify(string $token, string $expectedType): ?array
    {
        if ($token === '' || strlen($token) > 512 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }
        $raw = self::b64urlDecode($token);
        if ($raw === false) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 5 || $parts[0] !== 'v1') {
            return null;
        }
        [, $type, $deliveryId, $idx, $mac] = $parts;
        $payload = 'v1.' . $type . '.' . $deliveryId . '.' . $idx;
        if (!hash_equals($this->mac($payload), $mac)) {
            return null;
        }
        if ($type !== $expectedType || !preg_match('/^[0-9a-f-]{36}$/', $deliveryId)) {
            return null;
        }
        if ($idx !== '' && !ctype_digit($idx)) {
            return null;
        }
        return ['delivery_id' => $deliveryId, 'link_idx' => $idx === '' ? null : (int) $idx];
    }

    private function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', 'campaign:' . $payload, $this->secret), 0, self::MAC_HEX_LEN);
    }

    public static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return string|false */
    public static function b64urlDecode(string $data)
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode($padded, true);
    }
}
