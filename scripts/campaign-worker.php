<?php
/**
 * Background campaign worker: php campaign-worker.php <campaignId> <workerId>
 *
 * Spawned (N in parallel) by CampaignService. Each worker claims batches of
 * queued deliveries and sends them over one kept-alive SMTP connection.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use LibreMailApi\Campaign\Factory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

if (!isset($argv[1]) || !ctype_digit($argv[1])) {
    fwrite(STDERR, "usage: campaign-worker.php <campaignId> [workerId]\n");
    exit(1);
}
$campaignId = (int) $argv[1];
$workerId = $argv[2] ?? ('w-' . getmypid());

$config = require __DIR__ . '/../config/config.php';
$logger = new Logger('campaign-worker');
if ($config['logging']['enabled']) {
    $logDir = dirname($config['logging']['file']);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    $logger->pushHandler(new StreamHandler($config['logging']['file'], Logger::INFO));
}

$mailer = null;
try {
    [$worker, $mailer] = (new Factory($config, $logger))->worker();
    $worker->run($campaignId, $workerId);
} catch (\Throwable $e) {
    // Rows this worker was holding stay `sending`; POST /v3/campaigns/{key}/resume
    // requeues the untouched ones and parks a possibly-sent one as `unknown`.
    $logger->error('campaign worker crashed', ['campaign_id' => $campaignId, 'worker' => $workerId, 'error' => $e->getMessage()]);
    exit(1);
} finally {
    if ($mailer) {
        $mailer->close();
    }
}
