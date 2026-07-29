<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('atlas_cache_settings');
delete_option('atlas_cache_diagnostics');
delete_option('atlas_cache_db_migrations');
delete_option('atlas_cache_installed_version');

wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
wp_clear_scheduled_hook('atlas_cache_process_queue');
wp_clear_scheduled_hook('puc_cron_check_updates-atlas-cache');

global $wpdb;

$table = $wpdb->prefix . 'atlas_cache_queue';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

$dropIn = WP_CONTENT_DIR . '/advanced-cache.php';
if (is_file($dropIn)) {
    $content = file_get_contents($dropIn);
    if (is_string($content) && strpos($content, 'Atlas Cache drop-in') !== false) {
        @unlink($dropIn);
    }
}

$cacheRoot = WP_CONTENT_DIR . '/cache/atlas-cache';
atlas_cache_uninstall_remove_directory($cacheRoot);

function atlas_cache_uninstall_remove_directory(string $directory): void
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $expected = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/cache/atlas-cache';

    if ($directory !== $expected || !is_dir($directory) || is_link($directory)) {
        return;
    }

    atlas_cache_uninstall_remove_children($directory);
    @rmdir($directory);
}

function atlas_cache_uninstall_remove_children(string $directory): void
{
    $items = scandir($directory);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . '/' . $item;
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            continue;
        }

        if (is_dir($path)) {
            atlas_cache_uninstall_remove_children($path);
            @rmdir($path);
        }
    }
}
