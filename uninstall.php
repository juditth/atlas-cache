<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

atlas_cache_uninstall_restore_wp_cache();
atlas_cache_uninstall_remove_htaccess_browser_cache();
atlas_cache_uninstall_database_state();

$dropIn = WP_CONTENT_DIR . '/advanced-cache.php';
if (is_file($dropIn)) {
    $content = file_get_contents($dropIn);
    if (is_string($content) && strpos($content, 'Atlas Cache drop-in') !== false) {
        if (!unlink($dropIn)) {
            error_log('Atlas Cache uninstall could not remove advanced-cache.php.');
        }
    }
}

$cacheRoot = WP_CONTENT_DIR . '/cache/atlas-cache';
atlas_cache_uninstall_remove_directory($cacheRoot);

atlas_cache_uninstall_remove_update_transient();

delete_site_option('external_updates-atlas-cache');

function atlas_cache_uninstall_database_state(): void
{
    $siteIds = [null];
    if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
        $siteIds = get_sites(['fields' => 'ids', 'number' => 0]);
    }

    foreach ($siteIds as $siteId) {
        $switched = $siteId !== null && function_exists('switch_to_blog');
        if ($switched) {
            switch_to_blog((int) $siteId);
        }

        wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
        wp_clear_scheduled_hook('atlas_cache_process_queue');
        wp_clear_scheduled_hook('atlas_cache_revalidate_site');
        wp_clear_scheduled_hook('puc_cron_check_updates-atlas-cache');

        global $wpdb;
        $table = $wpdb->prefix . 'atlas_cache_queue';
        if ($wpdb->query("DROP TABLE IF EXISTS {$table}") === false) {
            error_log('Atlas Cache uninstall could not drop queue table: ' . $wpdb->last_error);
        }

        delete_transient('atlas_cache_external_cache_headers');
        delete_transient('atlas_cache_compression_status');
        delete_option('external_updates-atlas-cache');
        foreach ([
            'atlas_cache_settings',
            'atlas_cache_diagnostics',
            'atlas_cache_db_migrations',
            'atlas_cache_installed_version',
            'atlas_cache_browser_rules_version',
            'atlas_cache_scheduled_revalidation_days',
            'atlas_cache_wp_config_backup',
        ] as $option) {
            delete_option($option);
        }

        if ($switched && function_exists('restore_current_blog')) {
            restore_current_blog();
        }
    }
}

function atlas_cache_uninstall_remove_directory(string $directory): void
{
    $directory = rtrim(str_replace('\\', '/', $directory), '/');
    $expected = rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/cache/atlas-cache';

    if ($directory !== $expected) {
        return;
    }

    if (is_link($directory)) {
        if (!unlink($directory)) {
            error_log('Atlas Cache uninstall could not remove cache directory symlink: ' . $directory);
        }
        return;
    }

    if (!is_dir($directory)) {
        return;
    }

    atlas_cache_uninstall_remove_children($directory);
    if (is_dir($directory) && !rmdir($directory)) {
        error_log('Atlas Cache uninstall could not remove directory: ' . $directory);
    }
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
            if (!unlink($path)) {
                error_log('Atlas Cache uninstall could not remove file: ' . $path);
            }
            continue;
        }

        if (is_dir($path)) {
            atlas_cache_uninstall_remove_children($path);
            if (is_dir($path) && !rmdir($path)) {
                error_log('Atlas Cache uninstall could not remove directory: ' . $path);
            }
        }
    }
}

function atlas_cache_uninstall_restore_wp_cache(): void
{
    $path = atlas_cache_uninstall_wp_config_path();
    if ($path === '') {
        return;
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || strpos($contents, 'BEGIN Atlas Cache WP_CACHE') === false) {
        return;
    }

    if (!is_writable($path)) {
        error_log('Atlas Cache uninstall found its WP_CACHE marker but wp-config.php is not writable.');
        return;
    }

    $pattern = '~\R?/\* BEGIN Atlas Cache WP_CACHE original=([A-Za-z0-9+/=]+|none) \*/\Rdefine\(\'WP_CACHE\', true\);\R/\* END Atlas Cache WP_CACHE \*/\R?~';
    $contents = (string) preg_replace_callback($pattern, static function (array $matches): string {
        $original = (string) ($matches[1] ?? 'none');
        if ($original === 'none') {
            return "\n";
        }

        $decoded = base64_decode($original, true);
        if (!is_string($decoded) || $decoded === '') {
            return "\n";
        }

        return "\n" . $decoded . "\n";
    }, $contents);

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        error_log('Atlas Cache uninstall could not restore wp-config.php.');
    }
}

function atlas_cache_uninstall_wp_config_path(): string
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

    return '';
}

function atlas_cache_uninstall_remove_htaccess_browser_cache(): void
{
    $path = ABSPATH . '.htaccess';
    if (!is_file($path)) {
        return;
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || strpos($contents, '# BEGIN Atlas Cache Browser Cache') === false) {
        return;
    }

    if (!is_writable($path)) {
        error_log('Atlas Cache uninstall found its .htaccess block but the file is not writable.');
        return;
    }

    $pattern = '~\R?\# BEGIN Atlas Cache Browser Cache.*?\# END Atlas Cache Browser Cache\R?~s';
    $contents = rtrim((string) preg_replace($pattern, "\n", $contents)) . "\n";

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        error_log('Atlas Cache uninstall could not remove its .htaccess block.');
    }
}

function atlas_cache_uninstall_remove_update_transient(): void
{
    $updates = get_site_transient('update_plugins');
    if (!is_object($updates)) {
        return;
    }

    $plugin = 'atlas-cache/atlas-cache.php';
    if (isset($updates->response) && is_array($updates->response)) {
        unset($updates->response[$plugin]);
    }

    if (isset($updates->no_update) && is_array($updates->no_update)) {
        unset($updates->no_update[$plugin]);
    }

    set_site_transient('update_plugins', $updates, 12 * HOUR_IN_SECONDS);
}
