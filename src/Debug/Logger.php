<?php

declare(strict_types=1);

namespace AtlasCache\Debug;

use AtlasCache\Storage\CachePaths;

final class Logger
{
    private CachePaths $paths;

    public function __construct(CachePaths $paths)
    {
        $this->paths = $paths;
    }

    public function log(string $event, string $message): void
    {
        $directory = $this->paths->logsRoot();
        if (!is_dir($directory)) {
            wp_mkdir_p($directory);
        }

        $line = sprintf("[%s] [%s] %s\n", gmdate('c'), strtoupper($event), $message);
        @file_put_contents($directory . DIRECTORY_SEPARATOR . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function cleanup(int $retentionDays): void
    {
        $directory = $this->paths->logsRoot();
        if (!is_dir($directory)) {
            return;
        }

        $threshold = time() - (max(1, $retentionDays) * DAY_IN_SECONDS);
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [] as $file) {
            if (is_file($file) && filemtime($file) !== false && filemtime($file) < $threshold) {
                @unlink($file);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function files(): array
    {
        $directory = $this->paths->logsRoot();
        if (!is_dir($directory)) {
            return [];
        }

        $files = array_filter(glob($directory . DIRECTORY_SEPARATOR . '*.log') ?: [], 'is_file');
        rsort($files, SORT_STRING);

        return array_values($files);
    }

    /**
     * @return list<string>
     */
    public function readLines(string $file, int $limit = 300): array
    {
        $directory = $this->normalizePath($this->paths->logsRoot());
        $file = $this->normalizePath($file);

        if ($file !== $directory && strpos($file, $directory . '/') !== 0) {
            return [];
        }

        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_slice($lines, -max(1, min(1000, $limit)));
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
