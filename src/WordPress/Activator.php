<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Config\RuntimeConfigWriter;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\DropIn\DropInInstaller;
use AtlasCache\Queue\QueueRepository;
use AtlasCache\Storage\FileCacheStorage;
use RuntimeException;

final class Activator
{
    public static function activate(): void
    {
        if (PHP_VERSION_ID < 80000) {
            deactivate_plugins(plugin_basename(ATLAS_CACHE_FILE));
            wp_die(esc_html__('Atlas Cache vyžaduje PHP 8.0 nebo novější.', 'atlas-cache'));
        }

        global $wp_version;
        if (version_compare((string) $wp_version, '6.0', '<')) {
            deactivate_plugins(plugin_basename(ATLAS_CACHE_FILE));
            wp_die(esc_html__('Atlas Cache vyžaduje WordPress 6.0 nebo novější.', 'atlas-cache'));
        }

        $paths = PluginFactory::paths();
        $settings = new SettingsRepository();
        $storage = new FileCacheStorage($paths);
        $runtimeConfigWriter = new RuntimeConfigWriter($paths, $settings);
        $dropInInstaller = new DropInInstaller(ATLAS_CACHE_DIR . 'bin/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php');
        $queue = new QueueRepository($GLOBALS['wpdb']);
        $logger = new Logger($paths);
        $wpCacheError = '';

        try {
            $settings->ensureDefaults();
            $storage->ensureBaseDirectories();
            $queue->install();
            $runtimeConfigWriter->write();
            $dropInInstaller->install();
            try {
                (new WpConfigEditor())->enableCache();
            } catch (RuntimeException $exception) {
                $wpCacheError = $exception->getMessage();
                $logger->log('error', 'WP_CACHE enable failed: ' . $wpCacheError);
            }
            self::scheduleCleanup();
            update_option('atlas_cache_installed_version', ATLAS_CACHE_VERSION, false);
            update_option('atlas_cache_diagnostics', ['last_activation' => time(), 'last_error' => $wpCacheError], false);
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_activation' => time(), 'last_error' => $exception->getMessage()], false);
            $logger->log('error', 'Activation failed: ' . $exception->getMessage());
        }
    }

    public static function deactivate(): void
    {
        $paths = PluginFactory::paths();
        $settings = new SettingsRepository();
        $current = $settings->all();
        $current['enabled'] = false;
        $settings->save($current);

        (new RuntimeConfigWriter($paths, $settings))->write();
        (new DropInInstaller(ATLAS_CACHE_DIR . 'bin/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php'))->uninstall();

        $timestamp = wp_next_scheduled('atlas_cache_cleanup_logs');
        if (is_int($timestamp)) {
            wp_unschedule_event($timestamp, 'atlas_cache_cleanup_logs');
        }

        $workerTimestamp = wp_next_scheduled('atlas_cache_process_queue');
        if (is_int($workerTimestamp)) {
            wp_unschedule_event($workerTimestamp, 'atlas_cache_process_queue');
        }
    }

    private static function scheduleCleanup(): void
    {
        if (!wp_next_scheduled('atlas_cache_cleanup_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'atlas_cache_cleanup_logs');
        }
    }
}
