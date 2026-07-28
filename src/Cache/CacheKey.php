<?php

declare(strict_types=1);

namespace AtlasCache\Cache;

final class CacheKey
{
    private string $host;
    private string $language;
    private string $variant;
    /** @var list<string> */
    private array $pathSegments;

    /**
     * @param list<string> $pathSegments
     */
    public function __construct(string $host, string $language, string $variant, array $pathSegments)
    {
        $this->host = $host;
        $this->language = $language;
        $this->variant = $variant;
        $this->pathSegments = $pathSegments;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function variant(): string
    {
        return $this->variant;
    }

    /**
     * @return list<string>
     */
    public function pathSegments(): array
    {
        return $this->pathSegments;
    }

    public function relativeDirectory(): string
    {
        $parts = array_merge([$this->host, $this->language, $this->variant], $this->pathSegments);

        return implode(DIRECTORY_SEPARATOR, $parts);
    }

    public function debugKey(): string
    {
        $path = $this->pathSegments === [] ? 'index' : implode('/', $this->pathSegments);

        return $this->host . '/' . $this->language . '/' . $this->variant . '/' . $path;
    }
}
