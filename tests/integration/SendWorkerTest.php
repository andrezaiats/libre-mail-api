<?php

require_once __DIR__ . '/support.php';

use LibreMailApi\Campaign\Db;
use LibreMailApi\SmtpHandler;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\NullLogger;

$db = new Db(testMysqlConfig());
try {
    resetTestSchema($db);
} catch (\Throwable $e) {
    test('MySQL test schema is reachable (aipster_mail_test)', function () use ($e) {
        throw $e;
    });
    return;
}
addMember($db, 'only-dormant@example.com', 400, 15);
addMember($db, 'Mixed.Case@Example.com', 2);
addMember($db, 'cold1@example.com', 120, 15);

$root = dirname(__DIR__, 2);
$config = require $root . '/config/config.php';
$testDb = getenv('MAIL_TEST_DB_NAME') ?: 'aipster_mail_test';

test('empty recipient list makes zero SMTP calls', function () use ($root, $config, $testDb) {
    $id = 'worker-test-' . bin2hex(random_bytes(6));
    $queue = sys_get_temp_dir() . "/$id.json";
    file_put_contents($queue, json_encode(['message' => [
        'message_id' => $id,
        'from' => 'AIpster <noreply@aipster.com>',
        'to' => 'only-dormant@example.com',
        'recipients' => ['only-dormant@example.com'],
        'subject' => 'x',
        'html' => '<p>x</p>',
        'tags_json' => json_encode(['bulk-email', 'ghost-email']),
    ]]));
    $env = 'MAIL_DB_NAME=' . escapeshellarg($testDb) . ' GHOST_DB_NAME=' . escapeshellarg($testDb);
    exec("$env php " . escapeshellarg($root . '/scripts/send-worker.php') . ' ' . escapeshellarg($queue) . ' 2>&1', $out, $code);
    assertSame(0, $code, implode("\n", $out));
    assertTrue(!file_exists($queue), 'queue file removed');
    $log = (string) @file_get_contents($config['logging']['file']);
    $mine = array_filter(explode("\n", $log), fn ($l) => strpos($l, $id) !== false);
    $mine = implode("\n", $mine);
    assertContains('all recipients skipped', $mine);
    assertNotContains('Email sent successfully', $mine);
    assertNotContains('Failed to send email', $mine);
});

test('SmtpHandler submits in the given order with a tier Feedback-ID per recipient (mixed case included)', function () use ($config) {
    $capture = new class (true) extends PHPMailer {
        public array $sent = [];
        public function send(): bool
        {
            $headers = [];
            foreach ($this->getCustomHeaders() as [$n, $v]) {
                $headers[strtolower($n)] = $v;
            }
            $this->sent[] = [array_key_first($this->getAllRecipientAddresses()), $headers];
            return true;
        }
        public function smtpClose(): void
        {
        }
    };
    $handler = new SmtpHandler($config, new NullLogger());
    $prop = new ReflectionProperty(SmtpHandler::class, 'mailer');
    $prop->setAccessible(true);
    $prop->setValue($handler, $capture);

    $result = $handler->sendMessage([
        'message_id' => 'fb-test',
        'from' => 'AIpster <noreply@aipster.com>',
        'to' => 'Mixed.Case@Example.com, cold1@example.com, brandnew@example.com',
        'subject' => 's',
        'html' => '<p>x</p>',
        'text' => 'x',
        'tags_json' => json_encode(['bulk-email', 'ghost-email']),
        'recipient_tiers' => ['mixed.case@example.com' => 'active', 'cold1@example.com' => 'cold', 'brandnew@example.com' => 'all'],
    ]);
    assertTrue($result['success'], 'send ok');
    assertSame(['mixed.case@example.com', 'cold1@example.com', 'brandnew@example.com'], array_map('strtolower', array_column($capture->sent, 0)));
    $fb = array_map(fn ($s) => $s[1]['feedback-id'] ?? null, $capture->sent);
    assertSame(['newsletter:active:bulk:aipster', 'newsletter:cold:bulk:aipster', 'newsletter:all:bulk:aipster'], $fb);
});
