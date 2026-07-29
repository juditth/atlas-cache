<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('atlas_cache_settings');
delete_option('atlas_cache_diagnostics');
delete_option('atlas_cache_db_migrations');
delete_option('atlas_cache_installed_version');

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
