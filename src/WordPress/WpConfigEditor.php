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
        $originalContents = file_get_contents($path);
        if (!is_string($originalContents)) {
            throw new RuntimeException('Cannot read wp-config.php.');
        }

        $pattern = '/^[ \t]*define[ \t]*\([ \t]*([\'"])WP_CACHE\1[ \t]*,[ \t]*(true|false|0|1)[ \t]*\)[ \t]*;[ \t]*(?:(?:\/\/|#)[^\r\n]*)?$/mi';
        $originalBootstrapPosition = $this->bootstrapPosition($originalContents);
        if (preg_match($pattern, $this->scannablePhp($originalContents), $originalMatches, PREG_OFFSET_CAPTURE) === 1) {
            $originalValue = strtolower((string) $originalMatches[2][0]);
            $originalLinePosition = (int) $originalMatches[0][1];
            if (($originalValue === 'true' || $originalValue === '1') && ($originalBootstrapPosition === null || $originalLinePosition < $originalBootstrapPosition)) {
                return;
            }
        }

        if (!is_writable($path)) {
            throw new RuntimeException('wp-config.php is not writable. Enable WP_CACHE manually or adjust file permissions.');
        }

        [$contents, $originalFromMarker] = $this->removeAtlasMarker($originalContents);
        $bootstrapPosition = $this->bootstrapPosition($contents);

        if (preg_match($pattern, $this->scannablePhp($contents), $matches, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr($contents, (int) $matches[0][1], strlen((string) $matches[0][0]));
            $value = strtolower((string) $matches[2][0]);
            $linePosition = (int) $matches[0][1];
            if (($value === 'true' || $value === '1') && ($bootstrapPosition === null || $linePosition < $bootstrapPosition)) {
                return;
            }

            $contents = substr_replace($contents, '', $linePosition, strlen($line));
            $contents = $this->insertBeforeBootstrap($contents, $this->markerBlock($line));
            $this->backup($originalContents);
            $this->write($path, $contents);
            $this->assertEffectiveMarker($contents);
            return;
        }

        if ($this->containsCustomWpCacheDefinition($contents)) {
            throw new RuntimeException('wp-config.php contains a custom WP_CACHE definition. Enable it manually so Atlas Cache does not edit an unknown format.');
        }

        $contents = $this->insertBeforeBootstrap($contents, $this->markerBlock($originalFromMarker));
        $this->backup($originalContents);
        $this->write($path, $contents);
        $this->assertEffectiveMarker($contents);
    }

    public function disableCache(): void
    {
        $path = $this->configPath();
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Cannot read wp-config.php.');
        }

        if (strpos($contents, self::START_MARKER) === false) {
            return;
        }

        if (!is_writable($path)) {
            throw new RuntimeException('wp-config.php is not writable. Atlas Cache cannot restore its WP_CACHE change.');
        }

        [$contents, $originalLine] = $this->removeAtlasMarker($contents);
        if ($originalLine !== '') {
            $contents = $this->insertBeforeBootstrap($contents, $originalLine);
        }
        $this->write($path, $contents);
    }

    /** Explicitly turns off a simple WP_CACHE definition, including one created by another plugin. */
    public function disableCacheExplicitly(): void
    {
        $path = $this->configPath();
        $originalContents = file_get_contents($path);
        if (!is_string($originalContents)) {
            throw new RuntimeException('Cannot read wp-config.php.');
        }

        $contents = $originalContents;
        $hadAtlasMarker = strpos($contents, self::START_MARKER) !== false;
        if ($hadAtlasMarker) {
            [$contents, $originalLine] = $this->removeAtlasMarker($contents);
            if (strpos($contents, self::START_MARKER) !== false) {
                throw new RuntimeException('Atlas Cache WP_CACHE block has an unknown format. Edit wp-config.php manually.');
            }
            if ($originalLine !== '') {
                $contents = $this->insertBeforeBootstrap($contents, $originalLine);
            }
        }

        $definitions = preg_match_all('/\bdefine\s*\(\s*([\'\"])WP_CACHE\1/i', $contents);
        if ($definitions === false || $definitions > 1) {
            throw new RuntimeException('wp-config.php has multiple WP_CACHE definitions. Edit it manually.');
        }
        if ($definitions === 0 && !$hadAtlasMarker) {
            throw new RuntimeException('Cannot locate a simple WP_CACHE definition in wp-config.php. Edit it manually.');
        }
        if ($definitions === 1) {
            $pattern = '/^([ \t]*define\s*\(\s*([\'\"])WP_CACHE\2\s*,\s*)(true|false|1|0)(\s*\)\s*;[ \t]*(?:\/\/[^\r\n]*)?)$/mi';
            if (preg_match($pattern, $contents) !== 1) {
                throw new RuntimeException('wp-config.php has a custom WP_CACHE definition. Edit it manually.');
            }
            $contents = (string) preg_replace($pattern, '${1}false${4}', $contents, 1);
        }

        if ($contents === $originalContents) {
            return;
        }
        if (!is_writable($path)) {
            throw new RuntimeException('wp-config.php is not writable.');
        }
        $this->backup($originalContents);
        $this->write($path, $contents);
    }

    /**
     * Saves the pre-edit file content so a broken automated edit can be recovered. This is
     * stored in the options table (admin-only, DB-only access) rather than as a sibling file
     * next to wp-config.php, because a predictably-named wp-config.php.bak on disk would be
     * served as plain text by most webservers and leak DB credentials and secret keys.
     */
    private function backup(string $originalContents): void
    {
        if ($originalContents === '') {
            return;
        }

        update_option('atlas_cache_wp_config_backup', [
            'content' => base64_encode($originalContents),
            'created_at' => time(),
        ], false);
    }

    private function containsCustomWpCacheDefinition(string $contents): bool
    {
        $tokens = token_get_all($contents);
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONST) {
                $nameIndex = $this->nextCodeTokenIndex($tokens, $index + 1);
                if ($nameIndex !== null && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_STRING && $tokens[$nameIndex][1] === 'WP_CACHE') {
                    return true;
                }
            }

            if ($token[0] !== T_STRING || strcasecmp($token[1], 'define') !== 0) {
                continue;
            }

            $openIndex = $this->nextCodeTokenIndex($tokens, $index + 1);
            if ($openIndex === null || $tokens[$openIndex] !== '(') {
                continue;
            }

            $nameIndex = $this->nextCodeTokenIndex($tokens, $openIndex + 1);
            if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = $tokens[$nameIndex][1];
            if (strlen($literal) >= 2 && ($literal[0] === "'" || $literal[0] === '"') && $literal[0] === substr($literal, -1) && substr($literal, 1, -1) === 'WP_CACHE') {
                return true;
            }
        }

        return false;
    }

    private function scannablePhp(string $contents): string
    {
        $scannable = '';
        foreach (token_get_all($contents) as $token) {
            $value = is_array($token) ? $token[1] : $token;
            if (is_array($token) && (
                in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE], true)
                || ($token[0] === T_CONSTANT_ENCAPSED_STRING && $value !== "'WP_CACHE'" && $value !== '"WP_CACHE"')
            )) {
                $scannable .= (string) preg_replace('/[^\r\n]/', ' ', $value);
                continue;
            }

            $scannable .= $value;
        }

        return $scannable;
    }

    /** @param array<int, string|array<int, string|int>> $tokens */
    private function nextCodeTokenIndex(array $tokens, int $start): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }

        return null;
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
