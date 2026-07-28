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

    public function purge(CacheKey $key): void;

    public function purgeAll(): void;

    /**
     * @return array{files:int,size:int}
     */
    public function stats(): array;
}
