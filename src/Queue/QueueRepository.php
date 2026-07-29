<?php

declare(strict_types=1);

namespace AtlasCache\Queue;

use wpdb;

final class QueueRepository
{
    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table();
        $charset = $this->wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                url text NOT NULL,
                cache_key varchar(255) NOT NULL DEFAULT '',
                mode varchar(20) NOT NULL DEFAULT 'revalidate',
                priority int(11) NOT NULL DEFAULT 10,
                status varchar(20) NOT NULL DEFAULT 'pending',
                attempts int(11) NOT NULL DEFAULT 0,
                last_error text NULL,
                available_at datetime NOT NULL,
                locked_until datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status_priority (status, priority),
                KEY cache_key (cache_key)
            ) {$charset};"
        );
    }

    public function countPending(): int
    {
        $table = $this->table();

        return (int) $this->wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claimNext(): ?array
    {
        $table = $this->table();
        $now = current_time('mysql', true);
        $lockedUntil = gmdate('Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS);

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE status = 'pending'
                AND available_at <= %s
                ORDER BY priority ASC, id ASC
                LIMIT 1",
                $now
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        $updated = $this->wpdb->update(
            $table,
            [
                'status' => 'running',
                'attempts' => ((int) $row['attempts']) + 1,
                'locked_until' => $lockedUntil,
                'updated_at' => $now,
            ],
            [
                'id' => (int) $row['id'],
                'status' => 'pending',
            ],
            ['%s', '%d', '%s', '%s'],
            ['%d', '%s']
        );

        if ($updated !== 1) {
            return null;
        }

        $row['status'] = 'running';
        $row['attempts'] = ((int) $row['attempts']) + 1;
        $row['locked_until'] = $lockedUntil;

        return $row;
    }

    /**
     * @param list<string> $urls
     */
    public function enqueueMany(array $urls, int $priority = 10, string $mode = 'revalidate', int $delaySeconds = 0): int
    {
        $result = $this->enqueueManyDetailed($urls, $priority, $mode, $delaySeconds);

        return $result['created'] + $result['requeued'];
    }

    /**
     * @param list<string> $urls
     * @return array{total:int, created:int, requeued:int, updated:int, skipped:int, failed:int}
     */
    public function enqueueManyDetailed(array $urls, int $priority = 10, string $mode = 'revalidate', int $delaySeconds = 0): array
    {
        $result = [
            'total' => count($urls),
            'created' => 0,
            'requeued' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        foreach ($urls as $url) {
            $status = $this->enqueueUrlDetailed($url, $priority, $mode, $delaySeconds);
            if (isset($result[$status])) {
                $result[$status]++;
            } else {
                $result['failed']++;
            }
        }

        return $result;
    }

    public function enqueueUrl(string $url, int $priority = 10, string $mode = 'revalidate', int $delaySeconds = 0): bool
    {
        return in_array($this->enqueueUrlDetailed($url, $priority, $mode, $delaySeconds), ['created', 'requeued'], true);
    }

    public function enqueueUrlDetailed(string $url, int $priority = 10, string $mode = 'revalidate', int $delaySeconds = 0): string
    {
        $url = esc_url_raw($url);
        if ($url === '') {
            return 'skipped';
        }

        $mode = $this->normalizeMode($mode);
        $cacheKey = $this->cacheKeyForUrl($url);
        $table = $this->table();
        $now = current_time('mysql', true);
        $availableAt = gmdate('Y-m-d H:i:s', time() + max(0, $delaySeconds));

        $existing = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, status FROM {$table} WHERE cache_key = %s AND mode = %s LIMIT 1",
                $cacheKey,
                $mode
            ),
            ARRAY_A
        );

        if (is_array($existing)) {
            $wasAlreadyPending = in_array((string) $existing['status'], ['pending', 'running'], true);
            $updated = $this->wpdb->update(
                $table,
                [
                    'url' => $url,
                    'mode' => $mode,
                    'priority' => $priority,
                    'status' => 'pending',
                    'attempts' => 0,
                    'last_error' => null,
                    'available_at' => $availableAt,
                    'locked_until' => null,
                    'updated_at' => $now,
                ],
                ['id' => (int) $existing['id']],
                ['%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return 'failed';
            }

            return $wasAlreadyPending ? 'updated' : 'requeued';
        }

        $inserted = $this->wpdb->insert(
            $table,
            [
                'url' => $url,
                'cache_key' => $cacheKey,
                'mode' => $mode,
                'priority' => $priority,
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'available_at' => $availableAt,
                'locked_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $inserted !== false ? 'created' : 'failed';
    }

    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        $table = $this->table();
        $rows = $this->wpdb->get_results("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A);
        $counts = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<array<string, string>>
     */
    public function latest(int $limit = 20): array
    {
        $table = $this->table();
        $limit = max(1, min(100, $limit));
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT url, mode, status, priority, attempts, last_error, available_at, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function lastDatabaseError(): string
    {
        return (string) $this->wpdb->last_error;
    }

    public function markDone(int $id): void
    {
        $this->wpdb->update(
            $this->table(),
            [
                'status' => 'done',
                'last_error' => null,
                'locked_until' => null,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function markFailed(int $id, string $error, bool $retry = true): void
    {
        $status = $retry ? 'pending' : 'failed';
        $availableAt = gmdate('Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS);

        $this->wpdb->update(
            $this->table(),
            [
                'status' => $status,
                'last_error' => mb_substr($error, 0, 1000),
                'available_at' => $availableAt,
                'locked_until' => null,
                'updated_at' => current_time('mysql', true),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );
    }

    public function releaseStaleLocks(): int
    {
        $table = $this->table();

        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$table}
                SET status = 'pending', locked_until = NULL, updated_at = %s
                WHERE status = 'running'
                AND locked_until IS NOT NULL
                AND locked_until < %s",
                current_time('mysql', true),
                current_time('mysql', true)
            )
        );
    }

    public function table(): string
    {
        return $this->wpdb->prefix . 'atlas_cache_queue';
    }

    private function cacheKeyForUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return 'url:' . sha1($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';

        return 'url:' . sha1($scheme . '://' . $host . $path . $query);
    }

    private function normalizeMode(string $mode): string
    {
        if ($mode === 'purge') {
            return 'purge';
        }

        return 'revalidate';
    }
}
