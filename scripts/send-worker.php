<?php
/**
 * Background SMTP send worker.
 *
 * Reads a queued message JSON file (passed as argv[1]), sends each recipient
 * via SMTP through SmtpHandler, logs results, and deletes the queue file.
 *
 * Spawned by MessageHandler when a batch arrives so the HTTP response
 * returns to Ghost immediately (avoiding the 60 s Mailgun timeout).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use LibreMailApi\SmtpHandler;
use LibreMailApi\EventStorage;
use LibreMailApi\TrackingHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// ── guard ───────────────────────────────────────────────────────────────
if (!isset($argv[1]) || !file_exists($argv[1])) {
    fwrite(STDERR, "send-worker: missing or invalid queue file\n");
    exit(1);
}

$queueFile = $argv[1];
$payload   = json_decode(file_get_contents($queueFile), true);

if (!$payload || empty($payload['message'])) {
    fwrite(STDERR, "send-worker: malformed payload in $queueFile\n");
    @unlink($queueFile);
    exit(1);
}

$message = $payload['message'];
$config  = require __DIR__ . '/../config/config.php';

// ── logger ──────────────────────────────────────────────────────────────
$logger = new Logger('send-worker');
if ($config['logging']['enabled']) {
    $logDir = dirname($config['logging']['file']);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logger->pushHandler(new StreamHandler($config['logging']['file'], Logger::INFO));
}

$messageId = $message['message_id'] ?? 'unknown';
$logger->info("send-worker started", [
    'message_id' => $messageId,
    'queue_file' => basename($queueFile),
    'to'         => $message['to'] ?? 'unknown',
    'subject'    => $message['subject'] ?? 'no subject',
]);

// ── send ────────────────────────────────────────────────────────────────
try {
    $eventStorage    = new EventStorage($config['storage']['path']);
    $trackingHandler = new TrackingHandler(
        $eventStorage,
        $config['tracking']['pixel_base_url'] ?? 'https://aipster.com/t/o',
        $config['tracking']['hmac_secret']    ?? 'default-secret'
    );

    $smtp   = new SmtpHandler($config, $logger, $eventStorage, $trackingHandler);
    $result = $smtp->sendMessage($message);

    if ($result['success']) {
        $logger->info("send-worker finished OK", [
            'message_id'       => $messageId,
            'total_recipients'  => $result['total_recipients'] ?? 0,
            'successful_sends'  => $result['successful_sends'] ?? 0,
        ]);
    } else {
        $logger->warning("send-worker finished with failures", [
            'message_id'       => $messageId,
            'total_recipients'  => $result['total_recipients'] ?? 0,
            'successful_sends'  => $result['successful_sends'] ?? 0,
            'failed_sends'      => $result['failed_sends'] ?? 0,
            'errors'            => $result['errors'] ?? [],
        ]);
    }
} catch (\Throwable $e) {
    $logger->error("send-worker crashed", [
        'message_id' => $messageId,
        'error'      => $e->getMessage(),
    ]);
}

// ── cleanup ─────────────────────────────────────────────────────────────
@unlink($queueFile);
