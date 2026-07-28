<?php

declare(strict_types=1);

namespace AtlasCache\Cache;

use AtlasCache\Storage\PathSanitizer;

final class CacheKeyGenerator
{
    private PathSanitizer $sanitizer;

    public function __construct(PathSanitizer $sanitizer)
    {
        $this->sanitizer = $sanitizer;
    }

    public function generate(string $host, string $path, string $language = 'default', string $variant = 'public'): CacheKey
    {
        return new CacheKey(
            $this->sanitizer->host($host),
            $this->sanitizer->segment($language),
            $this->sanitizer->segment($variant),
            $this->sanitizer->pathSegments($path)
        );
    }
}
