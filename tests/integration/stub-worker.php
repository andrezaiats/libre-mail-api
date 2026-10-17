<?php
/**
 * Test-only worker: same CampaignWorker, but "sending" just records the delivery
 * id in test_sent_log. Used to run several workers truly in parallel.
 *   php stub-worker.php <campaignId> <workerId> [chunk]
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/support.php';

use LibreMailApi\Campaign\CampaignWorker;
use LibreMailApi\Campaign\Db;
use LibreMailApi\Campaign\Personalizer;
use LibreMailApi\Campaign\TokenSigner;
use Psr\Log\NullLogger;

$campaignId = (int) $argv[1];
$workerId = $argv[2];
$chunk = (int) ($argv[3] ?? 3);

$db = new Db(testMysqlConfig());
$log = $db->pdo()->prepare('INSERT INTO test_sent_log (delivery_id, worker) VALUES (?, ?)');
$send = function (array $msg) use ($log, $workerId) {
    preg_match('#/t/u/([A-Za-z0-9_-]+)#', $msg['headers'][0][1], $m);
    $t = (new TokenSigner('integration-secret'))->verify($m[1], TokenSigner::UNSUBSCRIBE);
    usleep(2000);
    $log->execute([$t['delivery_id'], $workerId]);
};
$worker = new CampaignWorker($db, new Personalizer('https://aipster.com', new TokenSigner('integration-secret')), $send, ['chunk' => $chunk], new NullLogger());
$stats = $worker->run($campaignId, $workerId);
echo json_encode($stats), "\n";
