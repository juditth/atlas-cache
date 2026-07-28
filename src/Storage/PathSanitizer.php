<?php

declare(strict_types=1);

namespace AtlasCache\Storage;

final class PathSanitizer
{
    public function host(string $host): string
    {
        $host = strtolower($host);
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/[^a-z0-9.-]/', '-', $host) ?? '';
        $host = trim($host, '.-');

        return $host !== '' ? $host : 'unknown-host';
    }

    public function segment(string $segment): string
    {
        $segment = strtolower(rawurldecode($segment));
        $segment = preg_replace('/[^a-z0-9_-]+/i', '-', $segment) ?? '';
        $segment = trim($segment, '-_');

        return $segment !== '' ? $segment : 'index';
    }

    /**
     * @return list<string>
     */
    public function pathSegments(string $path): array
    {
        $path = parse_url($path, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';
        $parts = explode('/', trim($path, '/'));
        $segments = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                continue;
            }

            $segments[] = $this->segment($part);
        }

        return $segments;
    }
}
