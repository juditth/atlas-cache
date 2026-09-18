<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Config\BrowserCacheGroups;
use RuntimeException;

final class HtaccessBrowserCacheRules
{
    private const START_MARKER = '# BEGIN Atlas Cache Browser Cache';
    private const END_MARKER = '# END Atlas Cache Browser Cache';

    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? ABSPATH . '.htaccess';
    }

    /** @param array<string, int>|null $days */
    public function install(?array $days = null, bool $gzipFallback = false): void
    {
        $contents = $this->readWritableContents();
        $contents = $this->removeBlock($contents);
        $contents = rtrim($contents) . "\n\n" . $this->block($days, $gzipFallback) . "\n";
        $this->write($contents);
    }

    public function uninstall(): void
    {
        if (!is_file($this->path)) {
            return;
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read .htaccess: ' . $this->path);
        }
        if (strpos($contents, self::START_MARKER) === false) {
            return;
        }
        if (!is_writable($this->path)) {
            throw new RuntimeException('.htaccess is not writable: ' . $this->path);
        }

        $this->write(rtrim($this->removeBlock($contents)) . "\n");
    }

    public function status(): string
    {
        if (!is_file($this->path)) {
            return 'Not available - .htaccess was not found. This is normal on nginx servers.';
        }

        $contents = file_get_contents($this->path);
        $hasBlock = is_string($contents) && strpos($contents, self::START_MARKER) !== false;
        if ($hasBlock) {
            return 'Atlas Cache browser cache rules are installed: ' . $this->path;
        }

        if (!is_writable($this->path)) {
            return 'Available but not writable: ' . $this->path;
        }

        return 'Available but not installed: ' . $this->path;
    }

    /** Whether the existing .htaccess contains Atlas Cache browser cache rules. */
    public function isInstalled(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        $contents = file_get_contents($this->path);

        return is_string($contents) && strpos($contents, self::START_MARKER) !== false;
    }

    public function hasGzipFallback(): bool
    {
        if (!is_file($this->path)) {
            return false;
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents)) {
            return false;
        }

        $start = strpos($contents, self::START_MARKER);
        $end = strpos($contents, self::END_MARKER);

        return $start !== false && $end !== false && $end > $start
            && strpos(substr($contents, $start, $end - $start), '# Atlas Cache gzip fallback') !== false;
    }

    private function readWritableContents(): string
    {
        if (!is_file($this->path)) {
            throw new RuntimeException('.htaccess was not found. This tool only edits an existing .htaccess file.');
        }

        if (!is_writable($this->path)) {
            throw new RuntimeException('.htaccess is not writable: ' . $this->path);
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read .htaccess: ' . $this->path);
        }

        return $contents;
    }

    private function removeBlock(string $contents): string
    {
        $pattern = '~\R?' . preg_quote(self::START_MARKER, '~') . '.*?' . preg_quote(self::END_MARKER, '~') . '\R?~s';

        return (string) preg_replace($pattern, "\n", $contents);
    }

    private function write(string $contents): void
    {
        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write .htaccess: ' . $this->path);
        }
    }

    /** @param array<string, int>|null $days */
    private function block(?array $days, bool $gzipFallback): string
    {
        $lines = [self::START_MARKER, '<IfModule mod_headers.c>'];
        foreach (BrowserCacheGroups::normalize($days) as $key => $duration) {
            if ($duration === 0) {
                continue;
            }

            $extensions = BrowserCacheGroups::all()[$key]['extensions'];
            $seconds = $duration * 86400;
            $lines[] = '    <FilesMatch "\\.(' . $extensions . ')$">';
            $lines[] = '        Header set Cache-Control "public, max-age=' . $seconds . '"';
            $lines[] = '    </FilesMatch>';
        }
        $lines[] = '</IfModule>';
        if ($gzipFallback) {
            $lines[] = '';
            $lines[] = '# Atlas Cache gzip fallback';
            $lines[] = '<IfModule mod_deflate.c>';
            $lines[] = '    <IfModule mod_filter.c>';
            $lines[] = '        AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript text/xml application/javascript application/json application/rss+xml application/vnd.ms-fontobject application/x-font application/x-font-opentype application/x-font-otf application/x-font-truetype application/x-font-ttf application/x-javascript application/xhtml+xml application/xml font/opentype font/otf font/ttf image/svg+xml image/x-icon';
            $lines[] = '    </IfModule>';
            $lines[] = '</IfModule>';
        }
        $lines[] = self::END_MARKER;

        return implode("\n", $lines);
    }
}
