<?php

declare(strict_types=1);

const HOUR_IN_SECONDS = 3600;

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-uninstall-' . bin2hex(random_bytes(6));
$contentRoot = $testRoot . DIRECTORY_SEPARATOR . 'wp-content';
mkdir($contentRoot . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'atlas-cache' . DIRECTORY_SEPARATOR . 'logs', 0777, true);

define('WP_UNINSTALL_PLUGIN', true);
define('WP_CONTENT_DIR', $contentRoot);
define('ABSPATH', $testRoot . DIRECTORY_SEPARATOR);

file_put_contents(
    ABSPATH . 'wp-config.php',
    "<?php\n/* BEGIN Atlas Cache WP_CACHE original=none */\ndefine('WP_CACHE', true);\n/* END Atlas Cache WP_CACHE */\n"
);
file_put_contents(
    ABSPATH . '.htaccess',
    "# Existing rule\n# BEGIN Atlas Cache Browser Cache\nAtlas test rule\n# END Atlas Cache Browser Cache\n"
);
file_put_contents(WP_CONTENT_DIR . '/advanced-cache.php', "<?php\n/* Atlas Cache drop-in */\n");
file_put_contents(WP_CONTENT_DIR . '/cache/atlas-cache/config.php', "<?php return ['enabled' => true];\n");
file_put_contents(WP_CONTENT_DIR . '/cache/atlas-cache/logs/test.log', "test\n");

$GLOBALS['atlas_uninstall_test'] = [
    'cleared_hooks' => [],
    'deleted_options' => [],
    'deleted_site_options' => [],
    'deleted_transients' => [],
    'updated_transient' => null,
];

function wp_clear_scheduled_hook(string $hook): int
{
    $GLOBALS['atlas_uninstall_test']['cleared_hooks'][] = $hook;
    return 1;
}

function delete_option(string $option): bool
{
    $GLOBALS['atlas_uninstall_test']['deleted_options'][] = $option;
    return true;
}

function delete_site_option(string $option): bool
{
    $GLOBALS['atlas_uninstall_test']['deleted_site_options'][] = $option;
    return true;
}

function delete_transient(string $transient): bool
{
    $GLOBALS['atlas_uninstall_test']['deleted_transients'][] = $transient;
    return true;
}

function get_site_transient(string $transient)
{
    return (object) [
        'response' => [
            'atlas-cache/atlas-cache.php' => (object) ['new_version' => '9.9.9'],
            'another/plugin.php' => (object) ['new_version' => '1.0.0'],
        ],
        'no_update' => [
            'atlas-cache/atlas-cache.php' => (object) ['new_version' => '0.1.14'],
        ],
    ];
}

function set_site_transient(string $transient, $value, int $expiration = 0): bool
{
    $GLOBALS['atlas_uninstall_test']['updated_transient'] = $value;
    return true;
}

class wpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';
    public array $queries = [];

    public function query(string $query): int
    {
        $this->queries[] = $query;
        return 1;
    }
}

$wpdb = new wpdb();

require dirname(__DIR__) . '/uninstall.php';

function atlas_uninstall_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

atlas_uninstall_test_assert(!is_file(WP_CONTENT_DIR . '/advanced-cache.php'), 'Owned drop-in must be removed.');
atlas_uninstall_test_assert(!is_dir(WP_CONTENT_DIR . '/cache/atlas-cache'), 'Atlas cache directory must be removed.');
atlas_uninstall_test_assert(strpos((string) file_get_contents(ABSPATH . 'wp-config.php'), 'Atlas Cache WP_CACHE') === false, 'WP_CACHE marker must be removed.');
atlas_uninstall_test_assert(strpos((string) file_get_contents(ABSPATH . '.htaccess'), 'Atlas Cache Browser Cache') === false, '.htaccess marker must be removed.');
atlas_uninstall_test_assert(count($GLOBALS['atlas_uninstall_test']['cleared_hooks']) === 3, 'All Atlas cron hooks must be cleared.');
atlas_uninstall_test_assert(in_array('atlas_cache_settings', $GLOBALS['atlas_uninstall_test']['deleted_options'], true), 'Settings option must be deleted.');
atlas_uninstall_test_assert(in_array('external_updates-atlas-cache', $GLOBALS['atlas_uninstall_test']['deleted_site_options'], true), 'Updater state must be deleted.');
atlas_uninstall_test_assert(count($wpdb->queries) === 1 && strpos($wpdb->queries[0], 'DROP TABLE IF EXISTS wp_atlas_cache_queue') !== false, 'Queue table must be dropped.');

$updated = $GLOBALS['atlas_uninstall_test']['updated_transient'];
atlas_uninstall_test_assert(is_object($updated), 'Plugin update transient must be updated.');
atlas_uninstall_test_assert(!isset($updated->response['atlas-cache/atlas-cache.php']), 'Atlas update response must be removed.');
atlas_uninstall_test_assert(isset($updated->response['another/plugin.php']), 'Other plugin update data must remain untouched.');
atlas_uninstall_test_assert(!isset($updated->no_update['atlas-cache/atlas-cache.php']), 'Atlas no-update response must be removed.');

function atlas_uninstall_test_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            atlas_uninstall_test_remove_directory($item->getPathname());
            continue;
        }

        unlink($item->getPathname());
    }

    rmdir($directory);
}

atlas_uninstall_test_remove_directory($testRoot);

echo "Uninstall cleanup regression test passed.\n";
