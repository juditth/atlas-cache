<?php

declare(strict_types=1);

const MINUTE_IN_SECONDS = 60;
const ARRAY_A = 'ARRAY_A';

$GLOBALS['atlas_private_test_response'] = [];

function get_option(string $name, $default = false)
{
    if ($name === 'atlas_cache_settings') {
        return ['enabled' => true, 'refresh_token' => str_repeat('a', 48)];
    }

    return $default;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

function wp_unslash($value)
{
    return $value;
}

function current_time(string $type, bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s');
}

function wp_mkdir_p(string $directory): bool
{
    return mkdir($directory, 0777, true);
}

function wp_remote_get(string $url, array $args): array
{
    if ($args['cookies'] !== [] || $args['headers']['X-Atlas-Cache-Refresh-Token'] === '') {
        throw new RuntimeException('The worker must make an anonymous authenticated refresh request.');
    }
    if (array_key_exists('Cache-Control', $args['headers'])) {
        throw new RuntimeException('The refresh token bypasses Atlas cache; the worker must not send a generic no-cache request header.');
    }
    if (array_keys($args['headers']) !== ['X-Atlas-Cache-Refresh-Token']) {
        throw new RuntimeException('The worker must send only the refresh token header required by Atlas.');
    }

    return $GLOBALS['atlas_private_test_response'];
}

function is_wp_error($response): bool
{
    return false;
}

function wp_parse_url(string $url)
{
    return parse_url($url);
}

function wp_remote_retrieve_response_code(array $response): int
{
    return $response['code'];
}

function wp_remote_retrieve_header(array $response, string $name): string
{
    return $response['headers'][$name] ?? '';
}

class wpdb
{
    public string $prefix = 'wp_';
    public array $updates = [];
    public string $mode = 'revalidate';
    private bool $claimed = false;

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function query(string $query): int
    {
        return 0;
    }

    public function get_row(string $query, string $format): ?array
    {
        return $this->claimed ? null : [
            'id' => 1,
            'url' => 'https://example.test/',
            'mode' => $this->mode,
            'attempts' => 0,
        ];
    }

    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int
    {
        $this->updates[] = $data;
        if (($data['status'] ?? '') === 'running') {
            $this->claimed = true;
        }

        return 1;
    }
}

require_once dirname(__DIR__) . '/src/Config/BrowserCacheGroups.php';
require_once dirname(__DIR__) . '/src/Config/SettingsRepository.php';
require_once dirname(__DIR__) . '/src/Storage/CachePaths.php';
require_once dirname(__DIR__) . '/src/Storage/CacheStorageInterface.php';
require_once dirname(__DIR__) . '/src/Debug/Logger.php';
require_once dirname(__DIR__) . '/src/Queue/QueueRepository.php';
require_once dirname(__DIR__) . '/src/Cache/CacheKeyGenerator.php';
require_once dirname(__DIR__) . '/src/Cache/CacheKey.php';
require_once dirname(__DIR__) . '/src/Storage/PathSanitizer.php';
require_once dirname(__DIR__) . '/src/Queue/QueueWorker.php';
require_once dirname(__DIR__) . '/src/Request/ResponsePolicy.php';
require_once dirname(__DIR__) . '/src/Request/RequestPolicy.php';
require_once dirname(__DIR__) . '/src/WordPress/PageCacheMiddleware.php';

$refreshToken = str_repeat('a', 48);
$requestPolicy = new AtlasCache\Request\RequestPolicy();
if ($requestPolicy->bypassReason(
    ['enabled' => true, 'refresh_token' => $refreshToken],
    ['HTTP_HOST' => 'example.test', 'HTTP_X_ATLAS_CACHE_REFRESH_TOKEN' => $refreshToken],
    [],
    false,
    ['example.test']
) !== null) {
    throw new RuntimeException('The authenticated refresh token must bypass stored Atlas HTML without a no-cache request header.');
}

$policy = new AtlasCache\Request\ResponsePolicy();
if ($policy->bypassReason(200, ['Content-Type: text/html', 'Cache-Control: private, no-store'], '<html></html>') !== 'PrivateHeaders') {
    throw new RuntimeException('Private response headers must still block cache storage.');
}

$middleware = (new ReflectionClass(AtlasCache\WordPress\PageCacheMiddleware::class))->newInstanceWithoutConstructor();
$headerMethod = new ReflectionMethod($middleware, 'privateCacheControlValue');
if ($headerMethod->invoke($middleware, ['Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private']) !== 'no-cache, must-revalidate, max-age=0, no-store, private') {
    throw new RuntimeException('The blocking Cache-Control value must be available for diagnostics.');
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-private-queue-' . bin2hex(random_bytes(6));
$logger = new AtlasCache\Debug\Logger(new AtlasCache\Storage\CachePaths($testRoot));
$keyGenerator = new AtlasCache\Cache\CacheKeyGenerator(new AtlasCache\Storage\PathSanitizer());
$storage = new class implements AtlasCache\Storage\CacheStorageInterface {
    public bool $failPurge = false;
    public function write(AtlasCache\Cache\CacheKey $key, string $html, array $metadata): void {}
    public function purge(AtlasCache\Cache\CacheKey $key): void
    {
        if ($this->failPurge) {
            throw new RuntimeException('Deletion failed');
        }
    }
    public function purgeAll(): void {}
    public function stats(): array { return ['files' => 0, 'size' => 0]; }
};

$database = new wpdb();
$worker = new AtlasCache\Queue\QueueWorker(new AtlasCache\Queue\QueueRepository($database), new AtlasCache\Config\SettingsRepository(), $logger, $keyGenerator, $storage);
$GLOBALS['atlas_private_test_response'] = [
    'code' => 200,
    'headers' => [
        'x-atlas-cache' => 'BYPASS',
        'x-atlas-cache-reason' => 'PrivateHeaders',
        'x-atlas-cache-blocked-by' => 'no-cache, must-revalidate, max-age=0, no-store, private',
    ],
];
$result = $worker->run(1);
$failure = end($database->updates);
if ($result['failed'] !== 1 || $failure['status'] !== 'failed' || strpos($failure['last_error'], 'cache-control=no-cache, must-revalidate, max-age=0, no-store, private') === false) {
    throw new RuntimeException('PrivateHeaders must include the blocking Cache-Control value and fail without retries.');
}

$database = new wpdb();
$worker = new AtlasCache\Queue\QueueWorker(new AtlasCache\Queue\QueueRepository($database), new AtlasCache\Config\SettingsRepository(), $logger, $keyGenerator, $storage);
$GLOBALS['atlas_private_test_response'] = ['code' => 503, 'headers' => ['x-atlas-cache' => 'BYPASS', 'x-atlas-cache-reason' => 'TemporaryError']];
$worker->run(1);
$failure = end($database->updates);
if ($failure['status'] !== 'pending') {
    throw new RuntimeException('Transient responses must retain the normal retry policy.');
}

foreach ([false, true] as $failPurge) {
    $database = new wpdb();
    $database->mode = 'purge';
    $storage->failPurge = $failPurge;
    $worker = new AtlasCache\Queue\QueueWorker(new AtlasCache\Queue\QueueRepository($database), new AtlasCache\Config\SettingsRepository(), $logger, $keyGenerator, $storage);
    $result = $worker->run(1);
    $update = end($database->updates);
    if ($failPurge) {
        if ($result !== ['processed' => 1, 'done' => 0, 'failed' => 1] || $update['status'] !== 'pending' || $update['last_error'] !== 'Deletion failed') {
            throw new RuntimeException('Failed purge must be reported and retried, never marked done.');
        }
    } elseif ($result !== ['processed' => 1, 'done' => 1, 'failed' => 0] || $update['status'] !== 'done') {
        throw new RuntimeException('Successful purge must be marked done.');
    }
}

foreach (glob($testRoot . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . '*.log') ?: [] as $logFile) {
    unlink($logFile);
}
rmdir($testRoot . DIRECTORY_SEPARATOR . 'logs');
rmdir($testRoot);

echo "Private-header queue regression test passed.\n";
