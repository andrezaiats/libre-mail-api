<?php
/**
 * Shared fixtures for integration tests: a throwaway schema that holds both the
 * campaign tables and minimal fake Ghost tables (members, newsletters, ...).
 */

use LibreMailApi\Campaign\Db;

function testMysqlConfig(): array
{
    $db = getenv('MAIL_TEST_DB_NAME') ?: 'aipster_mail_test';
    return [
        'host' => getenv('MAIL_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('MAIL_DB_PORT') ?: 3307),
        'user' => getenv('MAIL_DB_USER') ?: 'ghost',
        'password' => getenv('MAIL_DB_PASSWORD') ?: '',
        'mail_db' => $db,
        'ghost_db' => $db,
    ];
}

function resetTestSchema(Db $db): void
{
    $pdo = $db->pdo();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
    }
    $pdo->exec('CREATE TABLE members (id VARCHAR(24) PRIMARY KEY, uuid VARCHAR(36) NOT NULL, email VARCHAR(191) NOT NULL,
        name VARCHAR(191) NULL, email_disabled TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, last_seen_at DATETIME NULL)');
    $pdo->exec('CREATE TABLE newsletters (id VARCHAR(24) PRIMARY KEY, slug VARCHAR(191) NOT NULL)');
    $pdo->exec('CREATE TABLE members_newsletters (id VARCHAR(24) PRIMARY KEY, member_id VARCHAR(24) NOT NULL, newsletter_id VARCHAR(24) NOT NULL)');
    $pdo->exec('CREATE TABLE email_recipients (id VARCHAR(24) PRIMARY KEY, member_id VARCHAR(24) NOT NULL,
        opened_at DATETIME NULL, delivered_at DATETIME NULL, processed_at DATETIME NULL, KEY (member_id))');
    $pdo->exec('CREATE TABLE members_click_events (id VARCHAR(24) PRIMARY KEY, member_id VARCHAR(24) NOT NULL, created_at DATETIME NOT NULL, KEY (member_id))');
    $pdo->exec('CREATE TABLE settings (id VARCHAR(24) PRIMARY KEY, `key` VARCHAR(50) NOT NULL, value TEXT NULL)');
    $pdo->exec('CREATE TABLE test_sent_log (delivery_id CHAR(36) NOT NULL, worker VARCHAR(32) NOT NULL, KEY (delivery_id))');
    $db->ensureSchema();
}

function dropTestSchema(Db $db): void
{
    $pdo = $db->pdo();
    foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '', $t) . '`');
    }
}

function fakeId(): string
{
    return bin2hex(random_bytes(12));
}

/**
 * Insert a Ghost member.
 * $engagedDaysAgo: created/last-seen this many days ago; $ghostSends: Ghost newsletters delivered after that.
 */
function addMember(Db $db, string $email, int $engagedDaysAgo, int $ghostSends = 0, string $newsletterSlug = 'default-newsletter', bool $disabled = false): array
{
    $pdo = $db->pdo();
    $nid = $pdo->query("SELECT id FROM newsletters WHERE slug = " . $pdo->quote($newsletterSlug))->fetchColumn();
    if (!$nid) {
        $nid = fakeId();
        $pdo->prepare('INSERT INTO newsletters (id, slug) VALUES (?, ?)')->execute([$nid, $newsletterSlug]);
    }
    $id = fakeId();
    $uuid = \Ramsey\Uuid\Uuid::uuid4()->toString();
    $at = gmdate('Y-m-d H:i:s', time() - $engagedDaysAgo * 86400);
    $pdo->prepare('INSERT INTO members (id, uuid, email, name, email_disabled, created_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $uuid, $email, ucfirst(strstr($email, '@', true)) . ' Test', $disabled ? 1 : 0, $at, $at]);
    $pdo->prepare('INSERT INTO members_newsletters (id, member_id, newsletter_id) VALUES (?, ?, ?)')->execute([fakeId(), $id, $nid]);
    for ($i = 0; $i < $ghostSends; $i++) {
        $sentAt = gmdate('Y-m-d H:i:s', time() - $engagedDaysAgo * 86400 + ($i + 1) * 3600);
        $pdo->prepare('INSERT INTO email_recipients (id, member_id, delivered_at, processed_at) VALUES (?, ?, ?, ?)')
            ->execute([fakeId(), $id, $sentAt, $sentAt]);
    }
    return ['id' => $id, 'uuid' => $uuid, 'email' => $email];
}

function countRows(Db $db, string $sql, array $args = []): int
{
    $st = $db->pdo()->prepare($sql);
    $st->execute($args);
    return (int) $st->fetchColumn();
}
