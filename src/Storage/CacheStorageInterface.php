<?php

declare(strict_types=1);

namespace AtlasCache\Storage;

use AtlasCache\Cache\CacheKey;

interface CacheStorageInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function write(CacheKey $key, string $html, array $metadata): void;

    /** Remove only this URL's files; throw on deletion failure. */
    public function purge(CacheKey $key): void;

    /** Remove all cached pages; preserve configuration and logs; throw on failure. */
    public function purgeAll(): void;

    /**
     * @return array{files:int,size:int}
     */
    public function stats(): array;
}
