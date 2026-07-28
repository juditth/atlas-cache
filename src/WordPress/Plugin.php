<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Admin\AdminMenu;
use AtlasCache\Config\RuntimeConfigWriter;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\Queue\QueueWorker;

final class Plugin
{
    private SettingsRepository $settings;
    private PageCacheMiddleware $middleware;
    private AdminMenu $adminMenu;
    private RuntimeConfigWriter $runtimeConfigWriter;
    private Logger $logger;
    private QueueWorker $worker;
    private ContentChangeSubscriber $contentChangeSubscriber;
    private SelfHostedUpdater $updater;

    public function __construct(
        SettingsRepository $settings,
        PageCacheMiddleware $middleware,
        AdminMenu $adminMenu,
        RuntimeConfigWriter $runtimeConfigWriter,
        Logger $logger,
        QueueWorker $worker,
        ContentChangeSubscriber $contentChangeSubscriber,
        SelfHostedUpdater $updater
    ) {
        $this->settings = $settings;
        $this->middleware = $middleware;
        $this->adminMenu = $adminMenu;
        $this->runtimeConfigWriter = $runtimeConfigWriter;
        $this->logger = $logger;
        $this->worker = $worker;
        $this->contentChangeSubscriber = $contentChangeSubscriber;
        $this->updater = $updater;
    }

    public function register(): void
    {
        $this->settings->ensureDefaults();

        add_filter('cron_schedules', [$this, 'cronSchedules']);
        add_action('init', [$this, 'ensureWorkerScheduled']);
        add_action('template_redirect', [$this->middleware, 'maybeStartBuffer'], 0);
        add_action('shutdown', [$this->middleware, 'shutdown'], 0);
        add_action('admin_menu', [$this->adminMenu, 'register']);
        add_action('admin_enqueue_scripts', [$this->adminMenu, 'enqueueAssets']);
        add_action('admin_init', [$this->adminMenu, 'handleActions']);
        add_action('admin_bar_menu', [$this->adminMenu, 'registerAdminBar'], 90);
        add_action('admin_post_atlas_cache_toolbar', [$this->adminMenu, 'handleToolbarAction']);
        add_action('admin_post_atlas_cache_check_updates', [$this, 'checkUpdatesNow']);
        add_action('admin_notices', [$this, 'updateCheckNotice']);
        add_filter('plugin_action_links_' . plugin_basename(ATLAS_CACHE_FILE), [$this, 'pluginActionLinks']);
        add_action('atlas_cache_process_queue', [$this, 'processQueue']);
        add_action('atlas_cache_cleanup_logs', [$this, 'cleanupLogs']);
        $this->contentChangeSubscriber->register();
        $this->updater->register();

        add_action('update_option_' . SettingsRepository::OPTION_NAME, function (): void {
            $this->runtimeConfigWriter->write();
        });
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

    public function ensureWorkerScheduled(): void
    {
        if (!wp_next_scheduled('atlas_cache_process_queue')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'atlas_cache_every_minute', 'atlas_cache_process_queue');
        }
    }

    public function processQueue(): void
    {
        $this->worker->run();
    }

    public function cleanupLogs(): void
    {
        $settings = $this->settings->all();
        $this->logger->cleanup((int) $settings['debug_log_retention_days']);
    }

    /**
     * @param list<string> $links
     * @return list<string>
     */
    public function pluginActionLinks(array $links): array
    {
        $checkUrl = wp_nonce_url(
            admin_url('admin-post.php?action=atlas_cache_check_updates'),
            'atlas_cache_check_updates'
        );

        array_unshift(
            $links,
            '<a href="' . esc_url($checkUrl) . '">' . esc_html__('Check for updates', 'atlas-cache') . '</a>',
            '<a href="' . esc_url(admin_url('admin.php?page=atlas-cache-settings')) . '">' . esc_html__('Settings', 'atlas-cache') . '</a>'
        );

        return $links;
    }

    public function checkUpdatesNow(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to check plugin updates.', 'atlas-cache'));
        }

        check_admin_referer('atlas_cache_check_updates');

        $this->updater->clearUpdateCache();
        wp_update_plugins();

        wp_safe_redirect(add_query_arg('atlas-cache-update-check', '1', admin_url('plugins.php')));
        exit;
    }

    public function updateCheckNotice(): void
    {
        if (!isset($_GET['atlas-cache-update-check']) || !current_user_can('update_plugins')) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Atlas Cache update check completed. If no update is shown, the installed version is already current or the manifest does not contain a newer version.', 'atlas-cache') . '</p></div>';
    }
}
