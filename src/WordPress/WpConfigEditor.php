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

        [$contents, $originalFromMarker] = $this->removeAtlasMarker($contents);
        $bootstrapPosition = $this->bootstrapPosition($contents);

        $pattern = '/^[ \t]*define\s*\(\s*([\'"])WP_CACHE\1\s*,\s*(true|false|0|1)\s*\)\s*;\s*$/mi';
        if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $line = (string) $matches[0][0];
            $value = strtolower((string) $matches[2][0]);
            $linePosition = (int) $matches[0][1];
            if (($value === 'true' || $value === '1') && ($bootstrapPosition === null || $linePosition < $bootstrapPosition)) {
                return;
            }

            $contents = substr_replace($contents, '', $linePosition, strlen($line));
            $contents = $this->insertBeforeBootstrap($contents, $this->markerBlock($line));
            $this->write($path, $contents);
            $this->assertEffectiveMarker($contents);
            return;
        }

        if (stripos($contents, 'WP_CACHE') !== false) {
            throw new RuntimeException('wp-config.php contains a custom WP_CACHE definition. Enable it manually so Atlas Cache does not edit an unknown format.');
        }

        $contents = $this->insertBeforeBootstrap($contents, $this->markerBlock($originalFromMarker));
        $this->write($path, $contents);
        $this->assertEffectiveMarker($contents);
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

    /**
     * @return array{0:string,1:string}
     */
    private function removeAtlasMarker(string $contents): array
    {
        $originalLine = '';
        $pattern = '~\R?/\* BEGIN Atlas Cache WP_CACHE original=([A-Za-z0-9+/=]+|none) \*/\Rdefine\(\'WP_CACHE\', true\);\R/\* END Atlas Cache WP_CACHE \*/\R?~';
        $contents = (string) preg_replace_callback($pattern, static function (array $matches) use (&$originalLine): string {
            $original = (string) ($matches[1] ?? 'none');
            if ($original !== 'none') {
                $decoded = base64_decode($original, true);
                if (is_string($decoded) && $decoded !== '') {
                    $originalLine = $decoded;
                }
            }

            return "\n";
        }, $contents);

        return [$contents, $originalLine];
    }

    private function insertBeforeBootstrap(string $contents, string $block): string
    {
        $position = $this->bootstrapPosition($contents);
        if ($position !== null) {
            return substr($contents, 0, $position) . $block . "\n" . substr($contents, $position);
        }

        return $this->insertBeforeStopEditing($contents, $block);
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

    private function bootstrapPosition(string $contents): ?int
    {
        $patterns = [
            '/^[ \t]*require_once\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*;\s*$/mi',
            '/^[ \t]*require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;\s*$/mi',
            '/^[ \t]*require\s+ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*;\s*$/mi',
            '/^[ \t]*require\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;\s*$/mi',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === 1) {
                return (int) $matches[0][1];
            }
        }

        return null;
    }

    private function assertEffectiveMarker(string $contents): void
    {
        $markerPosition = strpos($contents, self::START_MARKER);
        if ($markerPosition === false) {
            throw new RuntimeException('WP_CACHE marker was not written to wp-config.php.');
        }

        $bootstrapPosition = $this->bootstrapPosition($contents);
        if ($bootstrapPosition !== null && $markerPosition > $bootstrapPosition) {
            throw new RuntimeException('WP_CACHE was written after wp-settings.php and would not load the drop-in early enough.');
        }
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
