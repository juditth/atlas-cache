<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

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

    public function install(): void
    {
        $contents = $this->readWritableContents();
        $contents = $this->removeBlock($contents);
        $contents = rtrim($contents) . "\n\n" . $this->block() . "\n";
        $this->write($contents);
    }

    public function uninstall(): void
    {
        if (!is_file($this->path) || !is_writable($this->path)) {
            return;
        }

        $contents = file_get_contents($this->path);
        if (!is_string($contents) || strpos($contents, self::START_MARKER) === false) {
            return;
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

    private function block(): string
    {
        return self::START_MARKER . "\n"
            . "<IfModule mod_expires.c>\n"
            . "    ExpiresActive On\n"
            . "    ExpiresByType image/avif \"access plus 1 year\"\n"
            . "    ExpiresByType image/bmp \"access plus 1 year\"\n"
            . "    ExpiresByType image/gif \"access plus 1 year\"\n"
            . "    ExpiresByType image/jpeg \"access plus 1 year\"\n"
            . "    ExpiresByType image/png \"access plus 1 year\"\n"
            . "    ExpiresByType image/svg+xml \"access plus 1 year\"\n"
            . "    ExpiresByType image/webp \"access plus 1 year\"\n"
            . "    ExpiresByType image/x-icon \"access plus 1 year\"\n"
            . "    ExpiresByType text/css \"access plus 1 year\"\n"
            . "    ExpiresByType text/javascript \"access plus 1 year\"\n"
            . "    ExpiresByType application/javascript \"access plus 1 year\"\n"
            . "    ExpiresByType application/x-javascript \"access plus 1 year\"\n"
            . "    ExpiresByType application/font-woff \"access plus 1 year\"\n"
            . "    ExpiresByType application/font-woff2 \"access plus 1 year\"\n"
            . "    ExpiresByType application/vnd.ms-fontobject \"access plus 1 year\"\n"
            . "    ExpiresByType font/otf \"access plus 1 year\"\n"
            . "    ExpiresByType font/ttf \"access plus 1 year\"\n"
            . "    ExpiresByType font/woff \"access plus 1 year\"\n"
            . "    ExpiresByType font/woff2 \"access plus 1 year\"\n"
            . "</IfModule>\n\n"
            . "<IfModule mod_headers.c>\n"
            . "    <FilesMatch \"\\.(avif|bmp|css|eot|gif|ico|jpe?g|js|map|otf|png|svg|ttf|webp|woff2?)$\">\n"
            . "        Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
            . "    </FilesMatch>\n"
            . "</IfModule>\n"
            . self::END_MARKER;
    }
}
