<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Admin\AdminMenu;
use AtlasCache\Config\RuntimeConfigWriter;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\DropIn\DropInInstaller;
use AtlasCache\Queue\QueueRepository;
use AtlasCache\Queue\QueueWorker;

final class Plugin
{
    private const INSTALLED_VERSION_OPTION = 'atlas_cache_installed_version';

    private SettingsRepository $settings;
    private PageCacheMiddleware $middleware;
    private AdminMenu $adminMenu;
    private RuntimeConfigWriter $runtimeConfigWriter;
    private DropInInstaller $dropInInstaller;
    private HtaccessBrowserCacheRules $htaccessRules;
    private WpConfigEditor $wpConfigEditor;
    private Logger $logger;
    private QueueRepository $queue;
    private QueueWorker $worker;
    private ContentChangeSubscriber $contentChangeSubscriber;
    private SelfHostedUpdater $updater;

    public function __construct(
        SettingsRepository $settings,
        PageCacheMiddleware $middleware,
        AdminMenu $adminMenu,
        RuntimeConfigWriter $runtimeConfigWriter,
        DropInInstaller $dropInInstaller,
        HtaccessBrowserCacheRules $htaccessRules,
        WpConfigEditor $wpConfigEditor,
        Logger $logger,
        QueueRepository $queue,
        QueueWorker $worker,
        ContentChangeSubscriber $contentChangeSubscriber,
        SelfHostedUpdater $updater
    ) {
        $this->settings = $settings;
        $this->middleware = $middleware;
        $this->adminMenu = $adminMenu;
        $this->runtimeConfigWriter = $runtimeConfigWriter;
        $this->dropInInstaller = $dropInInstaller;
        $this->htaccessRules = $htaccessRules;
        $this->wpConfigEditor = $wpConfigEditor;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->worker = $worker;
        $this->contentChangeSubscriber = $contentChangeSubscriber;
        $this->updater = $updater;
    }

    public function register(): void
    {
        $this->settings->ensureDefaults();
        $this->ensureRuntimeState();

        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [$this, 'syncSchedules']);
        add_action('template_redirect', [$this->middleware, 'maybeStartBuffer'], 0);
        add_action('shutdown', [$this->middleware, 'shutdown'], 0);
        add_action('admin_menu', [$this->adminMenu, 'register']);
        add_action('admin_enqueue_scripts', [$this->adminMenu, 'enqueueAssets']);
        add_action('admin_init', [$this->adminMenu, 'handleActions']);
        add_action('admin_bar_menu', [$this->adminMenu, 'registerAdminBar'], 90);
        add_action('admin_post_atlas_cache_toolbar', [$this->adminMenu, 'handleToolbarAction']);
        add_filter('plugin_action_links_' . plugin_basename(ATLAS_CACHE_FILE), [$this, 'pluginActionLinks']);
        add_action('atlas_cache_process_queue', [$this, 'processQueue']);
        add_action('atlas_cache_cleanup_logs', [$this, 'cleanupLogs']);
        $this->contentChangeSubscriber->register();
        $this->updater->register();

        add_action('update_option_' . SettingsRepository::OPTION_NAME, [$this, 'settingsUpdated'], 10, 2);
    }

    private function ensureRuntimeState(): void
    {
        try {
            $this->queue->install();
        } catch (\RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
            $this->logger->log('error', $exception->getMessage());
        }

        $installedVersion = (string) get_option(self::INSTALLED_VERSION_OPTION, '');
        $enabled = $this->settings->isEnabled();
        $dropInNeedsSync = $enabled
            ? !$this->dropInInstaller->isCurrent()
            : $this->dropInInstaller->isOwnedByAtlas();

        if ($installedVersion === ATLAS_CACHE_VERSION && !$dropInNeedsSync) {
            return;
        }

        if ($this->applyRuntimeState(!$enabled)) {
            update_option(self::INSTALLED_VERSION_OPTION, ATLAS_CACHE_VERSION, false);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $schedules
     * @return array<string, array<string, mixed>>
     */
    public function cronSchedules(array $schedules): array
    {
        $schedules['atlas_cache_every_minute'] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => 'Atlas Cache every minute',
        ];

        return $schedules;
    }

    public function syncSchedules(): void
    {
        if (!$this->settings->isEnabled() || !$this->dropInInstaller->isCurrent()) {
            wp_clear_scheduled_hook('atlas_cache_process_queue');
            wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
            return;
        }

        if (!wp_next_scheduled('atlas_cache_process_queue')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'atlas_cache_every_minute', 'atlas_cache_process_queue');
        }

        if (!wp_next_scheduled('atlas_cache_cleanup_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'atlas_cache_cleanup_logs');
        }
    }

    public function processQueue(): void
    {
        if (!$this->settings->isEnabled() || !$this->dropInInstaller->isCurrent()) {
            return;
        }

        $this->worker->run();
    }

    /**
     * @param mixed $oldValue
     * @param mixed $newValue
     */
    public function settingsUpdated($oldValue, $newValue): void
    {
        $enabled = is_array($newValue) && !empty($newValue['enabled']);
        $this->applyRuntimeState(!$enabled);
    }

    public function cleanupLogs(): void
    {
        if (!$this->settings->isEnabled()) {
            return;
        }

        $settings = $this->settings->all();
        $this->logger->cleanup((int) $settings['debug_log_retention_days']);
        $this->queue->cleanupFinished((int) $settings['queue_retention_days']);
    }

    private function applyRuntimeState(bool $clearQueue): bool
    {
        if (!$this->settings->isEnabled()) {
            wp_clear_scheduled_hook('atlas_cache_process_queue');
            wp_clear_scheduled_hook('atlas_cache_cleanup_logs');

            if ($clearQueue) {
                $this->queue->clearAll();
            }

            $success = true;
            try {
                $this->runtimeConfigWriter->write();
            } catch (\RuntimeException $exception) {
                $success = false;
                $this->recordRuntimeError($exception);
            }

            try {
                $this->dropInInstaller->uninstall();
            } catch (\RuntimeException $exception) {
                $success = false;
                $this->recordRuntimeError($exception);
            }

            try {
                $this->htaccessRules->uninstall();
            } catch (\RuntimeException $exception) {
                $success = false;
                $this->recordRuntimeError($exception);
            }

            try {
                $this->wpConfigEditor->disableCache();
            } catch (\RuntimeException $exception) {
                $success = false;
                $this->recordRuntimeError($exception);
            }

            return $success;
        }

        try {
            $this->runtimeConfigWriter->write();
            $this->dropInInstaller->install();
            $this->wpConfigEditor->enableCache();
            $this->syncSchedules();
            return true;
        } catch (\RuntimeException $exception) {
            wp_clear_scheduled_hook('atlas_cache_process_queue');
            wp_clear_scheduled_hook('atlas_cache_cleanup_logs');
            $this->recordRuntimeError($exception);

            try {
                $this->dropInInstaller->uninstall();
            } catch (\RuntimeException $rollbackException) {
                $this->recordRuntimeError($rollbackException);
            }

            $settings = $this->settings->all();
            if (!empty($settings['enabled'])) {
                $settings['enabled'] = false;
                $this->settings->save($settings);
                $this->applyRuntimeState(true);
            }

            return false;
        }
    }

    private function recordRuntimeError(\RuntimeException $exception): void
    {
        update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage()], false);
        $this->logger->log('error', $exception->getMessage());
    }

    /**
     * @param list<string> $links
     * @return list<string>
     */
    public function pluginActionLinks(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=atlas-cache-settings')) . '">' . esc_html__('Settings', 'atlas-cache') . '</a>'
        );

        return $links;
    }
}
