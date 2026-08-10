<?php

declare(strict_types=1);

namespace AtlasCache\DropIn;

use RuntimeException;

final class DropInInstaller
{
    private string $source;
    private string $target;

    public function __construct(string $source, string $target)
    {
        $this->source = $source;
        $this->target = $target;
    }

    public function install(): void
    {
        if (!is_file($this->source)) {
            throw new RuntimeException('Zdrojový advanced-cache.php neexistuje.');
        }

        if (is_file($this->target) && !$this->isOwnedByAtlas()) {
            throw new RuntimeException('advanced-cache.php už existuje a nepatří Atlas Cache.');
        }

        $contents = file_get_contents($this->source);
        if (!is_string($contents)) {
            throw new RuntimeException('Nelze načíst zdrojový advanced-cache.php.');
        }

        if (file_put_contents($this->target, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Nelze zapsat advanced-cache.php do wp-content.');
        }
    }

    public function uninstall(): void
    {
        if (is_file($this->target) && $this->isOwnedByAtlas()) {
            if (!unlink($this->target)) {
                throw new RuntimeException('Nelze odstranit Atlas Cache advanced-cache.php z wp-content.');
            }
        }
    }

    public function isCurrent(): bool
    {
        if (!$this->isOwnedByAtlas() || !is_file($this->source)) {
            return false;
        }

        $sourceHash = hash_file('sha256', $this->source);
        $targetHash = hash_file('sha256', $this->target);

        return is_string($sourceHash)
            && is_string($targetHash)
            && hash_equals($sourceHash, $targetHash);
    }

    public function exists(): bool
    {
        return is_file($this->target);
    }

    public function isOwnedByAtlas(): bool
    {
        if (!is_file($this->target)) {
            return false;
        }

        $contents = file_get_contents($this->target);

        return is_string($contents) && strpos($contents, 'Atlas Cache drop-in') !== false;
    }
}
