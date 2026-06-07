<?php

namespace LibreMailApi;

use Ramsey\Uuid\Uuid;

/**
 * SQLite-backed event and delivery storage for Mailgun-compatible analytics.
 * Uses WAL mode for concurrent read/write access.
 */
class EventStorage
{
    private \PDO $db;
    private string $dbPath;

    public function __construct(string $storagePath)
    {
        $this->dbPath = rtrim($storagePath, '/') . '/events.db';
        $this->initDatabase();
    }

    private function initDatabase(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new \PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA busy_timeout=5000');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS events (
                id TEXT PRIMARY KEY,
                event TEXT NOT NULL,
                timestamp REAL NOT NULL,
                recipient TEXT NOT NULL,
                message_id TEXT NOT NULL,
                email_id TEXT,
                tags TEXT,
                created_at REAL NOT NULL DEFAULT (strftime(\'%s\',\'now\'))
            )
        ');

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_events_type_ts ON events(event, timestamp)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_events_ts ON events(timestamp)');

        $this->db->exec('
            CREATE TABLE IF NOT EXISTS deliveries (
                delivery_id TEXT PRIMARY KEY,
                message_id TEXT NOT NULL,
                recipient TEXT NOT NULL,
                email_id TEXT,
                tags TEXT,
                created_at REAL NOT NULL DEFAULT (strftime(\'%s\',\'now\'))
            )
        ');
    }

    /**
     * Store a delivery record (maps delivery_id to message metadata).
     */
    public function storeDelivery(string $deliveryId, string $messageId, string $recipient, ?string $emailId, ?string $tags): void
    {
        $stmt = $this->db->prepare('
            INSERT OR IGNORE INTO deliveries (delivery_id, message_id, recipient, email_id, tags)
            VALUES (:delivery_id, :message_id, :recipient, :email_id, :tags)
        ');
        $stmt->execute([
            ':delivery_id' => $deliveryId,
            ':message_id' => $messageId,
            ':recipient' => $recipient,
            ':email_id' => $emailId,
            ':tags' => $tags,
        ]);
    }

    /**
     * Look up a delivery record by its ID.
     */
    public function getDelivery(string $deliveryId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM deliveries WHERE delivery_id = :id');
        $stmt->execute([':id' => $deliveryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Store an event in Mailgun-compatible format.
     */
    public function storeEvent(string $eventType, string $recipient, string $messageId, ?string $emailId, ?string $tags, ?float $timestamp = null): void
    {
        $id = Uuid::uuid4()->toString();
        $ts = $timestamp ?? microtime(true);

        $stmt = $this->db->prepare('
            INSERT INTO events (id, event, timestamp, recipient, message_id, email_id, tags)
            VALUES (:id, :event, :timestamp, :recipient, :message_id, :email_id, :tags)
        ');
        $stmt->execute([
            ':id' => $id,
            ':event' => $eventType,
            ':timestamp' => $ts,
            ':recipient' => $recipient,
            ':message_id' => $messageId,
            ':email_id' => $emailId,
            ':tags' => $tags,
        ]);
    }

    /**
     * Fetch events in Mailgun Events API format.
     *
     * @param array $options Keys: event (string "delivered OR opened"), tags (string),
     *                       begin (float unix ts), end (float unix ts), limit (int),
     *                       ascending (string "yes"/"no"), page (string cursor)
     * @return array { items: [...], paging: { next: url, previous: url } }
     */
    public function fetch(array $options, string $baseUrl, string $domain): array
    {
        $where = [];
        $params = [];

        // Filter by event types (e.g. "delivered OR opened")
        if (!empty($options['event'])) {
            $types = array_map('trim', preg_split('/\s+OR\s+/i', $options['event']));
            $placeholders = [];
            foreach ($types as $i => $type) {
                $key = ":event_{$i}";
                $placeholders[] = $key;
                $params[$key] = $type;
            }
            $where[] = 'event IN (' . implode(',', $placeholders) . ')';
        }

        // Filter by tags (e.g. "bulk-email AND ghost-email")
        if (!empty($options['tags'])) {
            $tagList = array_map('trim', preg_split('/\s+AND\s+/i', $options['tags']));
            foreach ($tagList as $i => $tag) {
                $key = ":tag_{$i}";
                // JSON array contains check: tags column stores e.g. '["bulk-email","ghost-email"]'
                $where[] = "tags LIKE {$key}";
                $params[$key] = '%"' . str_replace(['%', '_'], ['\%', '\_'], $tag) . '"%';
            }
        }

        // Time range
        if (!empty($options['begin'])) {
            $where[] = 'timestamp >= :begin';
            $params[':begin'] = (float) $options['begin'];
        }
        if (!empty($options['end'])) {
            $where[] = 'timestamp <= :end';
            $params[':end'] = (float) $options['end'];
        }

        // Cursor-based pagination: page is a timestamp cursor
        if (!empty($options['page'])) {
            $decoded = base64_decode($options['page']);
            if ($decoded !== false) {
                $cursor = json_decode($decoded, true);
                if ($cursor && isset($cursor['ts'])) {
                    $ascending = ($options['ascending'] ?? 'yes') === 'yes';
                    if ($ascending) {
                        $where[] = 'timestamp > :cursor_ts';
                    } else {
                        $where[] = 'timestamp < :cursor_ts';
                    }
                    $params[':cursor_ts'] = (float) $cursor['ts'];
                }
            }
        }

        $limit = min((int) ($options['limit'] ?? 300), 300);
        $ascending = ($options['ascending'] ?? 'yes') === 'yes';
        $order = $ascending ? 'ASC' : 'DESC';

        $sql = 'SELECT * FROM events';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY timestamp {$order} LIMIT :limit";
        $params[':limit'] = $limit;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            if ($key === ':limit') {
                $stmt->bindValue($key, $val, \PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $val);
            }
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build Mailgun-format items
        $items = array_map(function ($row) {
            $tags = $row['tags'] ? json_decode($row['tags'], true) : [];
            return [
                'id' => $row['id'],
                'event' => $row['event'],
                'timestamp' => (float) $row['timestamp'],
                'recipient' => $row['recipient'],
                'message' => [
                    'headers' => [
                        'message-id' => $row['message_id'],
                    ],
                ],
                'user-variables' => $row['email_id'] ? ['email-id' => $row['email_id']] : [],
                'tags' => $tags ?: [],
            ];
        }, $rows);

        // Build paging URLs
        $paging = $this->buildPaging($items, $options, $baseUrl, $domain, $ascending);

        return [
            'items' => $items,
            'paging' => $paging,
        ];
    }

    private function buildPaging(array $items, array $options, string $baseUrl, string $domain, bool $ascending): array
    {
        $basePath = "{$baseUrl}/v3/{$domain}/events";

        // Preserve original query params
        $baseParams = [];
        if (!empty($options['event'])) {
            $baseParams['event'] = $options['event'];
        }
        if (!empty($options['tags'])) {
            $baseParams['tags'] = $options['tags'];
        }
        if (!empty($options['limit'])) {
            $baseParams['limit'] = $options['limit'];
        }
        if (!empty($options['ascending'])) {
            $baseParams['ascending'] = $options['ascending'];
        }
        if (!empty($options['begin'])) {
            $baseParams['begin'] = $options['begin'];
        }
        if (!empty($options['end'])) {
            $baseParams['end'] = $options['end'];
        }

        $nextUrl = $basePath;
        $prevUrl = $basePath;

        if (!empty($items)) {
            $lastItem = end($items);
            $firstItem = reset($items);

            $nextCursor = base64_encode(json_encode(['ts' => $lastItem['timestamp']]));
            $prevCursor = base64_encode(json_encode(['ts' => $firstItem['timestamp']]));

            $nextParams = array_merge($baseParams, ['page' => $nextCursor]);
            $prevParams = array_merge($baseParams, ['page' => $prevCursor]);

            $nextUrl = $basePath . '?' . http_build_query($nextParams);
            $prevUrl = $basePath . '?' . http_build_query($prevParams);
        }

        return [
            'next' => $nextUrl,
            'previous' => $prevUrl,
        ];
    }

    /**
     * Delete events older than the given number of days.
     */
    public function cleanup(int $retentionDays): int
    {
        $cutoff = microtime(true) - ($retentionDays * 86400);

        $stmt = $this->db->prepare('DELETE FROM events WHERE timestamp < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        $eventsDeleted = $stmt->rowCount();

        $stmt = $this->db->prepare('DELETE FROM deliveries WHERE created_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $eventsDeleted;
    }
}
