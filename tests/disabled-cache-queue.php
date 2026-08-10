<?php

declare(strict_types=1);

const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;

$GLOBALS['atlas_cache_test_settings'] = ['enabled' => false];
$GLOBALS['atlas_cache_test_cron'] = [
    'cleared' => [],
    'scheduled' => [],
];

function get_option(string $name, $default = false)
{
    if ($name === 'atlas_cache_settings') {
        return $GLOBALS['atlas_cache_test_settings'];
    }

    return $default;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
}

function wp_clear_scheduled_hook(string $hook): int
{
    $GLOBALS['atlas_cache_test_cron']['cleared'][$hook] = ($GLOBALS['atlas_cache_test_cron']['cleared'][$hook] ?? 0) + 1;

    return 1;
}

function wp_next_scheduled(string $hook)
{
    return false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    $GLOBALS['atlas_cache_test_cron']['scheduled'][$hook] = ($GLOBALS['atlas_cache_test_cron']['scheduled'][$hook] ?? 0) + 1;

    return true;
}

class WP_Post
{
}

require_once dirname(__DIR__) . '/src/Config/SettingsRepository.php';
require_once dirname(__DIR__) . '/src/DropIn/DropInInstaller.php';
require_once dirname(__DIR__) . '/src/Queue/QueueWorker.php';
require_once dirname(__DIR__) . '/src/WordPress/PageCacheMiddleware.php';
require_once dirname(__DIR__) . '/src/WordPress/ContentChangeSubscriber.php';
require_once dirname(__DIR__) . '/src/WordPress/Plugin.php';

function atlas_cache_test_set_property(object $object, string $property, object $value): void
{
    $reflection = new ReflectionProperty($object, $property);
    $reflection->setValue($object, $value);
}

function atlas_cache_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$settings = new AtlasCache\Config\SettingsRepository();
atlas_cache_test_assert(!$settings->isEnabled(), 'Disabled settings must be reported as disabled.');

$worker = (new ReflectionClass(AtlasCache\Queue\QueueWorker::class))->newInstanceWithoutConstructor();
atlas_cache_test_set_property($worker, 'settings', $settings);
atlas_cache_test_assert(
    $worker->run() === ['processed' => 0, 'done' => 0, 'failed' => 0],
    'A disabled worker must not process queue items.'
);

$subscriber = (new ReflectionClass(AtlasCache\WordPress\ContentChangeSubscriber::class))->newInstanceWithoutConstructor();
atlas_cache_test_set_property($subscriber, 'settings', $settings);
$subscriber->onSavePost(1, new WP_Post(), true);
$subscriber->onGlobalChange();

$middleware = (new ReflectionClass(AtlasCache\WordPress\PageCacheMiddleware::class))->newInstanceWithoutConstructor();
atlas_cache_test_set_property($middleware, 'settings', $settings);
$middleware->maybeStartBuffer();

$plugin = (new ReflectionClass(AtlasCache\WordPress\Plugin::class))->newInstanceWithoutConstructor();
atlas_cache_test_set_property($plugin, 'settings', $settings);
$plugin->syncSchedules();
$plugin->processQueue();

atlas_cache_test_assert(($GLOBALS['atlas_cache_test_cron']['cleared']['atlas_cache_process_queue'] ?? 0) === 1, 'Disabled cache must clear the worker hook.');
atlas_cache_test_assert(($GLOBALS['atlas_cache_test_cron']['cleared']['atlas_cache_cleanup_logs'] ?? 0) === 1, 'Disabled cache must clear the cleanup hook.');
atlas_cache_test_assert($GLOBALS['atlas_cache_test_cron']['scheduled'] === [], 'Disabled cache must not schedule Atlas jobs.');

$source = tempnam(sys_get_temp_dir(), 'atlas-source-');
$target = tempnam(sys_get_temp_dir(), 'atlas-target-');
atlas_cache_test_assert(is_string($source) && is_string($target), 'Temporary drop-in files must be created.');
$dropInContents = "<?php\n/* Atlas Cache drop-in */\n";
file_put_contents($source, $dropInContents);
file_put_contents($target, $dropInContents);
$dropInInstaller = new AtlasCache\DropIn\DropInInstaller($source, $target);
atlas_cache_test_set_property($plugin, 'dropInInstaller', $dropInInstaller);
$GLOBALS['atlas_cache_test_settings'] = ['enabled' => true];
$plugin->syncSchedules();
atlas_cache_test_assert(($GLOBALS['atlas_cache_test_cron']['scheduled']['atlas_cache_process_queue'] ?? 0) === 1, 'Enabled cache must schedule the worker.');
atlas_cache_test_assert(($GLOBALS['atlas_cache_test_cron']['scheduled']['atlas_cache_cleanup_logs'] ?? 0) === 1, 'Enabled cache must schedule cleanup.');

file_put_contents($target, "<?php\n/* Atlas Cache drop-in: outdated */\n");
atlas_cache_test_assert(!$dropInInstaller->isCurrent(), 'An outdated owned drop-in must be detected.');
$dropInInstaller->install();
atlas_cache_test_assert($dropInInstaller->isCurrent(), 'Installing must synchronize the owned drop-in.');
$dropInInstaller->uninstall();
atlas_cache_test_assert(!is_file($target), 'Uninstalling must remove the owned drop-in.');

unlink($source);

echo "Disabled-cache queue regression test passed.\n";
