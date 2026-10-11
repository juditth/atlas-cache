<?php

declare(strict_types=1);

namespace AtlasCache\Storage;

use AtlasCache\Cache\CacheKey;
use RuntimeException;

final class FileCacheStorage implements CacheStorageInterface
{
    private CachePaths $paths;

    public function __construct(CachePaths $paths)
    {
        $this->paths = $paths;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function write(CacheKey $key, string $html, array $metadata): void
    {
        $htmlFile = $this->paths->htmlFile($key);
        $metaFile = $this->paths->metaFile($key);

        $this->ensureInsideRoot($htmlFile);
        $this->ensureInsideRoot($metaFile);
        $this->ensureDirectory(dirname($htmlFile));

        $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($metadataJson)) {
            throw new RuntimeException('Metadata nelze serializovat.');
        }

        $this->atomicWrite($htmlFile, $html);
        $this->atomicWrite($metaFile, $metadataJson . "\n");
    }

    public function purgeAll(): void
    {
        $pagesRoot = $this->paths->pagesRoot();
        if (!is_dir($pagesRoot)) {
            return;
        }

        $this->removeChildren($pagesRoot);
    }

    public function purge(CacheKey $key): void
    {
        $directory = $this->paths->pagesRoot() . DIRECTORY_SEPARATOR . $key->relativeDirectory();
        $this->ensureInsideRoot($directory);

        if (!is_dir($directory)) {
            return;
        }

        $this->ensurePurgeDirectory($directory);
        // Child directories contain separate URLs, including below the homepage.
        $this->removeFile($this->paths->htmlFile($key));
        $this->removeFile($this->paths->metaFile($key));
    }

    /**
     * @return array{files:int,size:int}
     */
    public function stats(): array
    {
        $files = 0;
        $size = 0;
        $root = $this->paths->pagesRoot();

        if (!is_dir($root)) {
            return ['files' => 0, 'size' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files++;
            $size += $file->getSize();
        }

        return ['files' => $files, 'size' => $size];
    }

    public function ensureBaseDirectories(): void
    {
        foreach ($this->paths->requiredDirectories() as $directory) {
            $this->ensureDirectory($directory);
        }
    }

    private function atomicWrite(string $target, string $contents): void
    {
        $this->ensureInsideRoot($target);
        $temporary = $target . '.tmp.' . bin2hex(random_bytes(6));

        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Nelze zapsat dočasný cache soubor.');
        }

        if (!@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Nelze atomicky nahradit cache soubor.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        $this->ensureInsideRoot($directory);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Nelze vytvořit cache adresář.');
        }
    }

    private function ensureInsideRoot(string $path): void
    {
        $root = $this->normalizePath($this->paths->root());
        $candidate = $this->normalizePath($path);

        if ($candidate !== $root && strpos($candidate, $root . '/') !== 0) {
            throw new RuntimeException('Cache cesta je mimo povolený adresář.');
        }
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }

    private function removeChildren(string $directory): void
    {
        $this->ensurePurgeDirectory($directory);

        $items = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            $path = $item->getPathname();
            $this->ensureInsideRoot($path);

            if ($item->isDir() && !$item->isLink()) {
                $this->removeChildren($path);
                if (!@rmdir($path) && is_dir($path)) {
                    throw new RuntimeException('Nelze odstranit cache adresář.');
                }
                continue;
            }

            $this->removeFile($path);
        }
    }

    private function ensurePurgeDirectory(string $directory): void
    {
        $this->ensureInsideRoot($directory);
        $root = realpath($this->paths->root());
        $resolved = realpath($directory);
        if ($root === false || $resolved === false || is_link($directory)
            || strpos($this->normalizePath($resolved), $this->normalizePath($root) . '/') !== 0) {
            throw new RuntimeException('Cache adresář je mimo povolené úložiště nebo je odkaz.');
        }
    }

    private function removeFile(string $path): void
    {
        $this->ensureInsideRoot($path);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }

        if (!@unlink($path)) {
            throw new RuntimeException('Nelze odstranit cache soubor.');
        }
    }
}
