<?php

namespace LibreMailApi\Campaign;

/**
 * MySQL access for campaigns (schema `aipster_mail`) and read access to the
 * Ghost schema. All DATETIME values are UTC (session time_zone is forced).
 *
 * The databases are created once by an administrator (the Ghost DB user has
 * no global CREATE privilege); this class only creates tables.
 */
class Db
{
    private array $cfg;
    private ?\PDO $pdo = null;

    public function __construct(array $mysqlConfig)
    {
        $this->cfg = $mysqlConfig + [
            'host' => '127.0.0.1',
            'port' => 3307,
            'user' => 'ghost',
            'password' => '',
            'ghost_db' => 'ghost',
            'mail_db' => 'aipster_mail',
        ];
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->cfg['host'],
                (int) $this->cfg['port'],
                $this->cfg['mail_db']
            );
            $this->pdo = new \PDO($dsn, $this->cfg['user'], $this->cfg['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
        return $this->pdo;
    }

    /** Backtick-quoted Ghost schema name for cross-schema queries. */
    public function ghost(): string
    {
        return '`' . str_replace('`', '', $this->cfg['ghost_db']) . '`';
    }

    public function ensureSchema(): void
    {
        foreach (self::schemaStatements() as $sql) {
            $this->pdo()->exec($sql);
        }
    }

    public function getMeta(string $key): ?string
    {
        $st = $this->pdo()->prepare('SELECT v FROM meta WHERE k = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->pdo()->prepare('INSERT INTO meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([$key, $value]);
    }

    /** @return string[] */
    public static function schemaStatements(): array
    {
        $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
        return [
            "CREATE TABLE IF NOT EXISTS campaigns (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                campaign_key VARCHAR(191) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                subject VARCHAR(998) NOT NULL,
                preheader VARCHAR(512) NULL,
                html MEDIUMTEXT NOT NULL,
                text MEDIUMTEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                total INT NOT NULL DEFAULT 0,
                spawn_error TEXT NULL,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                started_at DATETIME(3) NULL,
                finished_at DATETIME(3) NULL,
                UNIQUE KEY uq_campaign_key (campaign_key),
                KEY idx_kind_created (kind, created_at)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS campaign_links (
                campaign_id INT UNSIGNED NOT NULL,
                idx INT NOT NULL,
                url TEXT NOT NULL,
                PRIMARY KEY (campaign_id, idx)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS deliveries (
                id CHAR(36) NOT NULL PRIMARY KEY,
                campaign_id INT UNSIGNED NOT NULL,
                member_id VARCHAR(24) NULL,
                member_uuid VARCHAR(36) NULL,
                email VARCHAR(191) NOT NULL,
                name VARCHAR(191) NULL,
                tier VARCHAR(16) NOT NULL DEFAULT 'active',
                send_order INT NOT NULL DEFAULT 0,
                status VARCHAR(16) NOT NULL DEFAULT 'queued',
                batch_id CHAR(36) NULL,
                claimed_at DATETIME(3) NULL,
                attempt_at DATETIME(3) NULL,
                sent_at DATETIME(3) NULL,
                error VARCHAR(1000) NULL,
                UNIQUE KEY uq_campaign_email (campaign_id, email),
                KEY idx_claim (campaign_id, status, send_order),
                KEY idx_batch (batch_id),
                KEY idx_member_sent (member_id, sent_at)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                delivery_id CHAR(36) NOT NULL,
                campaign_id INT UNSIGNED NOT NULL,
                member_id VARCHAR(24) NULL,
                type VARCHAR(24) NOT NULL,
                link_idx INT NULL,
                ip_trunc VARCHAR(64) NULL,
                user_agent VARCHAR(512) NULL,
                suspect TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_campaign_type (campaign_id, type),
                KEY idx_member_created (member_id, created_at),
                KEY idx_delivery (delivery_id)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS member_state (
                member_id VARCHAR(24) NOT NULL PRIMARY KEY,
                tier VARCHAR(16) NOT NULL,
                last_engaged_at DATETIME(3) NULL,
                sends_since_engaged INT NOT NULL DEFAULT 0,
                sunset_stage VARCHAR(16) NOT NULL DEFAULT 'none',
                stage_at DATETIME(3) NULL,
                updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
            ) $opts",
            "CREATE TABLE IF NOT EXISTS meta (
                k VARCHAR(64) NOT NULL PRIMARY KEY,
                v VARCHAR(255) NOT NULL
            ) $opts",
        ];
    }
}
