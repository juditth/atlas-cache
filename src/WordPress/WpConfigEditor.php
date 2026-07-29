<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use RuntimeException;

final class WpConfigEditor
{
    private const START_MARKER = '/* BEGIN Atlas Cache WP_CACHE original=';
    private const END_MARKER = '/* END Atlas Cache WP_CACHE */';

    public function isCacheEnabled(): bool
    {
        return defined('WP_CACHE') && (bool) WP_CACHE;
    }

    public function enableCache(): void
    {
        $path = $this->configPath();
        if (!is_writable($path)) {
            throw new RuntimeException('wp-config.php is not writable. Enable WP_CACHE manually or adjust file permissions.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read wp-config.php.');
        }

        if (strpos($contents, self::START_MARKER) !== false) {
            return;
        }

        $pattern = '/^[ \t]*define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(true|false|0|1)\s*\)\s*;\s*$/mi';
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $line = (string) $matches[0][0];
            $value = strtolower((string) $matches[2][0]);
            if ($value === 'true' || $value === '1') {
                return;
            }

            $contents = substr_replace($contents, $this->markerBlock($line), (int) $matches[0][1], strlen($line));
            $this->write($path, $contents);
            return;
        }

        if (stripos($contents, 'WP_CACHE') !== false) {
            throw new RuntimeException('wp-config.php contains a custom WP_CACHE definition. Enable it manually so Atlas Cache does not edit an unknown format.');
        }

        $contents = $this->insertBeforeStopEditing($contents, $this->markerBlock(''));
        $this->write($path, $contents);
    }

    public function configPath(): string
    {
        $candidates = [
            ABSPATH . 'wp-config.php',
            dirname(ABSPATH) . '/wp-config.php',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Cannot locate wp-config.php.');
    }

    private function insertBeforeStopEditing(string $contents, string $block): string
    {
        $needles = [
            "/* That's all, stop editing! Happy publishing. */",
            "/* That's all, stop editing! Happy blogging. */",
            "require_once ABSPATH . 'wp-settings.php';",
            'require_once ABSPATH . "wp-settings.php";',
        ];

        foreach ($needles as $needle) {
            $position = strpos($contents, $needle);
            if ($position !== false) {
                return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
            }
        }

        return rtrim($contents) . "\n\n" . $block . "\n";
    }

    private function markerBlock(string $originalLine): string
    {
        $original = $originalLine !== '' ? base64_encode($originalLine) : 'none';

        return self::START_MARKER . $original . " */\n"
            . "define('WP_CACHE', true);\n"
            . self::END_MARKER;
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write wp-config.php.');
        }
    }
}
