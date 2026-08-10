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
        $wpConfigEditor = new WpConfigEditor();
        $queue = new QueueRepository($GLOBALS['wpdb']);
        $logger = new Logger($paths);
        $wpCacheError = '';

        try {
            $settings->ensureDefaults();
            $storage->ensureBaseDirectories();
            $queue->install();
            $runtimeConfigWriter->write();
            if ($settings->isEnabled()) {
                $dropInInstaller->install();
                try {
                    $wpConfigEditor->enableCache();
                } catch (RuntimeException $exception) {
                    $wpCacheError = $exception->getMessage();
                    $logger->log('error', 'WP_CACHE enable failed: ' . $wpCacheError);
                }
            } else {
                $dropInInstaller->uninstall();
                (new HtaccessBrowserCacheRules())->uninstall();
                $wpConfigEditor->disableCache();
                $queue->clearAll();
                wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
                wp_clear_scheduled_hook('atlas_cache_process_queue');
            }
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
        $queue = new QueueRepository($GLOBALS['wpdb']);
        $dropInInstaller = new DropInInstaller(ATLAS_CACHE_DIR . 'bin/advanced-cache.php', WP_CONTENT_DIR . '/advanced-cache.php');
        $current = $settings->all();
        $current['enabled'] = false;
        $settings->save($current);

        try {
            (new RuntimeConfigWriter($paths, $settings))->write();
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
        }

        try {
            $dropInInstaller->uninstall();
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
        }

        try {
            (new HtaccessBrowserCacheRules())->uninstall();
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
        }

        try {
            (new WpConfigEditor())->disableCache();
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
        }

        $queue->clearAll();
        wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
        wp_clear_scheduled_hook('atlas_cache_process_queue');
        wp_clear_scheduled_hook('puc_cron_check_updates-atlas-cache');
    }
}
