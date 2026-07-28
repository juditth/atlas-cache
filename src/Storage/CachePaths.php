<?php

declare(strict_types=1);

namespace AtlasCache\Storage;

use AtlasCache\Cache\CacheKey;

final class CachePaths
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function pagesRoot(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'pages';
    }

    public function stateRoot(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'state';
    }

    public function logsRoot(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'logs';
    }

    public function configFile(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'config.php';
    }

    public function htmlFile(CacheKey $key): string
    {
        return $this->pagesRoot() . DIRECTORY_SEPARATOR . $key->relativeDirectory() . DIRECTORY_SEPARATOR . 'index.html';
    }

    public function metaFile(CacheKey $key): string
    {
        return $this->pagesRoot() . DIRECTORY_SEPARATOR . $key->relativeDirectory() . DIRECTORY_SEPARATOR . 'index.meta.json';
    }

    public function globalStaleFile(): string
    {
        return $this->stateRoot() . DIRECTORY_SEPARATOR . 'global-stale.json';
    }

    /**
     * @return list<string>
     */
    public function requiredDirectories(): array
    {
        return [
            $this->root,
            $this->pagesRoot(),
            $this->stateRoot(),
            $this->logsRoot(),
        ];
    }
}
