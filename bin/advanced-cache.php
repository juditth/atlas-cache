<?php
/**
 * Atlas Cache drop-in.
 *
 * This file is copied to wp-content/advanced-cache.php.
 * It must stay independent from WordPress functions and plugin autoloading.
 */

declare(strict_types=1);

if (defined('ATLAS_CACHE_DROPIN_RUNNING')) {
    return;
}

define('ATLAS_CACHE_DROPIN_RUNNING', true);

$atlasCacheConfigFile = __DIR__ . '/cache/atlas-cache/config.php';
if (!is_file($atlasCacheConfigFile)) {
    return;
}

$atlasCacheConfig = include $atlasCacheConfigFile;
if (!is_array($atlasCacheConfig) || empty($atlasCacheConfig['enabled'])) {
    return;
}

if (atlas_cache_dropin_is_refresh_request($atlasCacheConfig, $_SERVER)) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'BYPASS', 'Revalidate', '');
    return;
}

$atlasCacheReason = atlas_cache_dropin_bypass_reason($atlasCacheConfig, $_SERVER, $_COOKIE);
if ($atlasCacheReason !== null) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'BYPASS', $atlasCacheReason, '');
    return;
}

$atlasCacheKey = atlas_cache_dropin_cache_key($atlasCacheConfig, $_SERVER);
if ($atlasCacheKey === null) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'BYPASS', 'InvalidKey', '');
    return;
}

$atlasCacheRoot = atlas_cache_dropin_normalize_path((string) $atlasCacheConfig['cache_root']);
$atlasCacheHtml = $atlasCacheRoot . '/pages/' . $atlasCacheKey . '/index.html';
$atlasCacheMeta = $atlasCacheRoot . '/pages/' . $atlasCacheKey . '/index.meta.json';

if (!atlas_cache_dropin_inside_root($atlasCacheRoot, $atlasCacheHtml) || !atlas_cache_dropin_inside_root($atlasCacheRoot, $atlasCacheMeta)) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'BYPASS', 'StorageError', $atlasCacheKey);
    return;
}

if (!is_file($atlasCacheHtml) || !is_readable($atlasCacheHtml)) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'MISS', 'Missing', $atlasCacheKey);
    return;
}

$atlasCacheMetaData = atlas_cache_dropin_read_meta($atlasCacheMeta);
$atlasCacheGenerated = isset($atlasCacheMetaData['generated_at']) && is_string($atlasCacheMetaData['generated_at'])
    ? strtotime($atlasCacheMetaData['generated_at'])
    : filemtime($atlasCacheHtml);

if (!is_int($atlasCacheGenerated) && $atlasCacheGenerated === false) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'MISS', 'MissingMetadata', $atlasCacheKey);
    return;
}

$atlasCacheTtl = max(60, (int) ($atlasCacheConfig['ttl'] ?? 86400));
$atlasCacheAge = time() - (int) $atlasCacheGenerated;
$atlasCacheIsStale = $atlasCacheAge > $atlasCacheTtl;

if ($atlasCacheIsStale && empty($atlasCacheConfig['stale_while_revalidate'])) {
    atlas_cache_dropin_debug_headers($atlasCacheConfig, 'MISS', 'Stale', $atlasCacheKey);
    return;
}

$atlasCacheStatus = $atlasCacheIsStale ? 'STALE' : 'HIT';
$atlasCacheReason = $atlasCacheIsStale ? 'StaleServed' : 'Fresh';

atlas_cache_dropin_debug_headers($atlasCacheConfig, $atlasCacheStatus, $atlasCacheReason, $atlasCacheKey);
header('Content-Type: text/html; charset=UTF-8');
if (!empty($atlasCacheConfig['debug_headers'])) {
    header('X-Atlas-Cache-Age: ' . max(0, $atlasCacheAge));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

readfile($atlasCacheHtml);

if (atlas_cache_dropin_frontend_debug_enabled($atlasCacheConfig)) {
    echo "\n<!-- Atlas Cache: " . htmlspecialchars($atlasCacheStatus, ENT_QUOTES, 'UTF-8')
        . '; generated=' . htmlspecialchars((string) ($atlasCacheMetaData['generated_at'] ?? ''), ENT_QUOTES, 'UTF-8')
        . '; age=' . (int) $atlasCacheAge
        . '; key=' . htmlspecialchars($atlasCacheKey, ENT_QUOTES, 'UTF-8')
        . " -->";
}

exit;

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $server
 * @param array<string, mixed> $cookies
 */
function atlas_cache_dropin_bypass_reason(array $config, array $server, array $cookies): ?string
{
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        return 'PostRequest';
    }

    $uri = (string) ($server['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';

    if (strpos($path, '/wp-admin') === 0 || $path === '/wp-login.php') {
        return 'Admin';
    }

    if (strpos($path, '/wp-json') === 0 || strpos($path, '/xmlrpc.php') === 0) {
        return 'RestOrXmlRpc';
    }

    if (isset($server['HTTP_X_REQUESTED_WITH']) && strtolower((string) $server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return 'Ajax';
    }

    if (!atlas_cache_dropin_query_allowed((string) ($server['QUERY_STRING'] ?? ''), $config['query_string_whitelist'] ?? [])) {
        return 'QueryString';
    }

    foreach ((array) ($config['excluded_url_patterns'] ?? []) as $pattern) {
        $pattern = (string) $pattern;
        if ($pattern !== '' && stripos($uri, $pattern) !== false) {
            return 'ExcludedUrl';
        }
    }

    foreach ($cookies as $name => $value) {
        $name = (string) $name;
        foreach ((array) ($config['sensitive_cookies'] ?? []) as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && strpos($name, $prefix) === 0) {
                return strpos($name, 'wordpress_logged_in_') === 0 ? 'LoggedIn' : 'SensitiveCookie';
            }
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $server
 */
function atlas_cache_dropin_is_refresh_request(array $config, array $server): bool
{
    $token = (string) ($config['refresh_token'] ?? '');
    if ($token === '') {
        return false;
    }

    $queryString = (string) ($server['QUERY_STRING'] ?? '');
    if ($queryString === '') {
        return false;
    }

    parse_str($queryString, $query);
    $requestToken = isset($query['atlas_cache_refresh']) ? (string) $query['atlas_cache_refresh'] : '';

    return $requestToken !== '' && hash_equals($token, $requestToken);
}

/**
 * @param mixed $whitelist
 */
function atlas_cache_dropin_query_allowed(string $queryString, $whitelist): bool
{
    if ($queryString === '') {
        return true;
    }

    if (!is_array($whitelist) || $whitelist === []) {
        return false;
    }

    parse_str($queryString, $query);
    foreach (array_keys($query) as $key) {
        if (!in_array((string) $key, $whitelist, true)) {
            return false;
        }
    }

    return true;
}

/**
 * @param array<string, mixed> $config
 * @param array<string, mixed> $server
 */
function atlas_cache_dropin_cache_key(array $config, array $server): ?string
{
    $host = atlas_cache_dropin_sanitize_host((string) ($server['HTTP_HOST'] ?? 'localhost'));
    $uri = (string) ($server['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/';
    $segments = atlas_cache_dropin_path_segments($path);

    $parts = array_merge([$host, 'default', 'public'], $segments);
    $key = implode('/', $parts);

    return $key !== '' && strpos($key, '..') === false ? $key : null;
}

function atlas_cache_dropin_sanitize_host(string $host): string
{
    $host = strtolower($host);
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $host = preg_replace('/[^a-z0-9.-]/', '-', $host) ?? '';
    $host = trim($host, '.-');

    return $host !== '' ? $host : 'unknown-host';
}

/**
 * @return list<string>
 */
function atlas_cache_dropin_path_segments(string $path): array
{
    $parts = explode('/', trim($path, '/'));
    $segments = [];

    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            continue;
        }

        $segment = strtolower(rawurldecode($part));
        $segment = preg_replace('/[^a-z0-9_-]+/i', '-', $segment) ?? '';
        $segment = trim($segment, '-_');
        if ($segment !== '') {
            $segments[] = $segment;
        }
    }

    return $segments;
}

/**
 * @return array<string, mixed>
 */
function atlas_cache_dropin_read_meta(string $file): array
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }

    $json = file_get_contents($file);
    if (!is_string($json)) {
        return [];
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

function atlas_cache_dropin_normalize_path(string $path): string
{
    return str_replace('\\', '/', rtrim($path, '/\\'));
}

function atlas_cache_dropin_inside_root(string $root, string $path): bool
{
    $root = atlas_cache_dropin_normalize_path($root);
    $path = atlas_cache_dropin_normalize_path($path);

    return $path === $root || strpos($path, $root . '/') === 0;
}

/**
 * @param array<string, mixed> $config
 */
function atlas_cache_dropin_debug_headers(array $config, string $status, string $reason, string $key): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Atlas-Cache: ' . $status);

    if (!empty($config['debug_headers'])) {
        header('X-Atlas-Cache-Reason: ' . $reason);
        if ($key !== '') {
            header('X-Atlas-Cache-Key: ' . $key);
        }
    }
}

/**
 * @param array<string, mixed> $config
 */
function atlas_cache_dropin_frontend_debug_enabled(array $config): bool
{
    if (empty($config['frontend_debug_enabled'])) {
        return false;
    }

    $expiresAt = (int) ($config['frontend_debug_expires_at'] ?? 0);

    return $expiresAt > time();
}
