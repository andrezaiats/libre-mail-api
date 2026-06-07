<?php

namespace LibreMailApi;

/**
 * Handles open-tracking pixel injection, token generation/validation,
 * and pixel serving for Mailgun-compatible email analytics.
 */
class TrackingHandler
{
    private EventStorage $eventStorage;
    private string $pixelBaseUrl;
    private string $hmacSecret;

    /** 1x1 transparent GIF (43 bytes) */
    private const TRANSPARENT_GIF = "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00\x80\x00\x00\xff\xff\xff\x00\x00\x00\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    public function __construct(EventStorage $eventStorage, string $pixelBaseUrl, string $hmacSecret)
    {
        $this->eventStorage = $eventStorage;
        $this->pixelBaseUrl = rtrim($pixelBaseUrl, '/');
        $this->hmacSecret = $hmacSecret;
    }

    /**
     * Create a delivery record and return the delivery_id.
     */
    public function createDelivery(string $messageId, string $recipient, ?string $emailId, ?string $tags): string
    {
        $deliveryId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $this->eventStorage->storeDelivery($deliveryId, $messageId, $recipient, $emailId, $tags);
        return $deliveryId;
    }

    /**
     * Generate a signed token for the given delivery_id.
     * Format: base64url(delivery_id + "." + hmac_hex)
     */
    public function generateToken(string $deliveryId): string
    {
        $hmac = hash_hmac('sha256', $deliveryId, $this->hmacSecret);
        $payload = $deliveryId . '.' . $hmac;
        return $this->base64UrlEncode($payload);
    }

    /**
     * Validate a token and return the delivery_id, or null if invalid.
     */
    public function validateToken(string $token): ?string
    {
        $decoded = $this->base64UrlDecode($token);
        if ($decoded === false) {
            return null;
        }

        $dotPos = strrpos($decoded, '.');
        if ($dotPos === false) {
            return null;
        }

        $deliveryId = substr($decoded, 0, $dotPos);
        $receivedHmac = substr($decoded, $dotPos + 1);
        $expectedHmac = hash_hmac('sha256', $deliveryId, $this->hmacSecret);

        if (!hash_equals($expectedHmac, $receivedHmac)) {
            return null;
        }

        return $deliveryId;
    }

    /**
     * Inject a tracking pixel <img> tag into the HTML body before </body>.
     * Returns the modified HTML.
     */
    public function injectTrackingPixel(string $html, string $deliveryId): string
    {
        $token = $this->generateToken($deliveryId);
        $pixelUrl = $this->pixelBaseUrl . '/' . $token;
        $pixelTag = '<img src="' . htmlspecialchars($pixelUrl, ENT_QUOTES, 'UTF-8')
            . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0">';

        // Insert before </body> if present, otherwise append
        $pos = stripos($html, '</body>');
        if ($pos !== false) {
            return substr($html, 0, $pos) . $pixelTag . substr($html, $pos);
        }

        return $html . $pixelTag;
    }

    /**
     * Handle a pixel request: validate token, record open event, serve GIF.
     * Outputs directly and exits.
     */
    public function handlePixelRequest(string $token): void
    {
        $deliveryId = $this->validateToken($token);

        // Always serve the pixel (even for invalid tokens) to avoid leaking validity
        // but only record the event for valid tokens
        if ($deliveryId !== null) {
            $delivery = $this->eventStorage->getDelivery($deliveryId);
            if ($delivery) {
                $this->eventStorage->storeEvent(
                    'opened',
                    $delivery['recipient'],
                    $delivery['message_id'],
                    $delivery['email_id'],
                    $delivery['tags']
                );
            }
        }

        $this->servePixel();
    }

    /**
     * Output the 1x1 transparent GIF with appropriate headers.
     */
    private function servePixel(): void
    {
        http_response_code(200);
        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen(self::TRANSPARENT_GIF));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        echo self::TRANSPARENT_GIF;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), strlen($data) + (4 - strlen($data) % 4) % 4, '=');
        return base64_decode($padded);
    }
}
