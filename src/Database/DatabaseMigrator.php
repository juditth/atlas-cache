<?php

declare(strict_types=1);

namespace AtlasCache\Database;

use RuntimeException;
use wpdb;

final class DatabaseMigrator
{
    private const MIGRATIONS_OPTION = 'atlas_cache_db_migrations';

    private wpdb $wpdb;

    public function __construct(wpdb $wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function migrate(): void
    {
        $applied = $this->appliedMigrations();

        foreach ($this->migrations() as $id => $migration) {
            if (in_array($id, $applied, true)) {
                continue;
            }

            $migration();
            $this->assertNoDatabaseError($id);

            $applied[] = $id;
            update_option(self::MIGRATIONS_OPTION, array_values(array_unique($applied)), false);
        }
    }

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        $applied = get_option(self::MIGRATIONS_OPTION, []);
        if (!is_array($applied)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $applied)));
    }

    /**
     * @return array<string, callable(): void>
     */
    private function migrations(): array
    {
        return [
            '202607280001_create_queue_table' => function (): void {
                $table = $this->queueTable();
                $charset = $this->wpdb->get_charset_collate();

                $this->wpdb->query(
                    "CREATE TABLE IF NOT EXISTS {$table} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        url text NOT NULL,
                        status varchar(20) NOT NULL DEFAULT 'pending',
                        PRIMARY KEY  (id)
                    ) {$charset};"
                );
            },
            '202607280002_add_queue_cache_key' => function (): void {
                $this->addColumnIfMissing('cache_key', "ALTER TABLE {$this->queueTable()} ADD COLUMN cache_key varchar(255) NOT NULL DEFAULT '' AFTER url");
            },
            '202607280003_add_queue_mode' => function (): void {
                $this->addColumnIfMissing('mode', "ALTER TABLE {$this->queueTable()} ADD COLUMN mode varchar(20) NOT NULL DEFAULT 'revalidate' AFTER cache_key");
            },
            '202607280004_add_queue_priority' => function (): void {
                $this->addColumnIfMissing('priority', "ALTER TABLE {$this->queueTable()} ADD COLUMN priority int(11) NOT NULL DEFAULT 10 AFTER mode");
            },
            '202607280005_add_queue_attempts' => function (): void {
                $this->addColumnIfMissing('attempts', "ALTER TABLE {$this->queueTable()} ADD COLUMN attempts int(11) NOT NULL DEFAULT 0 AFTER status");
            },
            '202607280006_add_queue_last_error' => function (): void {
                $this->addColumnIfMissing('last_error', "ALTER TABLE {$this->queueTable()} ADD COLUMN last_error text NULL AFTER attempts");
            },
            '202607280007_add_queue_available_at' => function (): void {
                $this->addColumnIfMissing('available_at', "ALTER TABLE {$this->queueTable()} ADD COLUMN available_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER last_error");
            },
            '202607280008_add_queue_locked_until' => function (): void {
                $this->addColumnIfMissing('locked_until', "ALTER TABLE {$this->queueTable()} ADD COLUMN locked_until datetime NULL AFTER available_at");
            },
            '202607280009_add_queue_created_at' => function (): void {
                $this->addColumnIfMissing('created_at', "ALTER TABLE {$this->queueTable()} ADD COLUMN created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER locked_until");
            },
            '202607280010_add_queue_updated_at' => function (): void {
                $this->addColumnIfMissing('updated_at', "ALTER TABLE {$this->queueTable()} ADD COLUMN updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER created_at");
            },
            '202607280011_add_queue_status_priority_index' => function (): void {
                $this->addIndexIfMissing('status_priority', "ALTER TABLE {$this->queueTable()} ADD KEY status_priority (status, priority)");
            },
            '202607280012_add_queue_cache_key_index' => function (): void {
                $this->addIndexIfMissing('cache_key', "ALTER TABLE {$this->queueTable()} ADD KEY cache_key (cache_key)");
            },
        ];
    }

    private function addColumnIfMissing(string $column, string $sql): void
    {
        if ($this->hasColumn($column)) {
            return;
        }

        $this->wpdb->query($sql);
    }

    private function addIndexIfMissing(string $index, string $sql): void
    {
        if ($this->hasIndex($index)) {
            return;
        }

        $this->wpdb->query($sql);
    }

    private function hasColumn(string $column): bool
    {
        $row = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW COLUMNS FROM {$this->queueTable()} LIKE %s",
                $column
            )
        );

        return is_string($row) && $row !== '';
    }

    private function hasIndex(string $index): bool
    {
        $row = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SHOW INDEX FROM {$this->queueTable()} WHERE Key_name = %s",
                $index
            )
        );

        return is_string($row) && $row !== '';
    }

    private function assertNoDatabaseError(string $migrationId): void
    {
        if ($this->wpdb->last_error !== '') {
            throw new RuntimeException('Database migration failed: ' . $migrationId . ' - ' . $this->wpdb->last_error);
        }
    }

    private function queueTable(): string
    {
        return $this->wpdb->prefix . 'atlas_cache_queue';
    }
}
