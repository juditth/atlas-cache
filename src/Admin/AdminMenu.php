<?php

declare(strict_types=1);

namespace AtlasCache\Admin;

use AtlasCache\Config\RuntimeConfigWriter;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\DropIn\DropInInstaller;
use AtlasCache\Queue\QueueRepository;
use AtlasCache\Queue\QueueWorker;
use AtlasCache\Storage\CacheStorageInterface;
use AtlasCache\WordPress\SitemapUrlCollector;
use AtlasCache\WordPress\WpConfigEditor;
use RuntimeException;

final class AdminMenu
{
    private SettingsRepository $settings;
    private CacheStorageInterface $storage;
    private RuntimeConfigWriter $runtimeConfigWriter;
    private DropInInstaller $dropInInstaller;
    private QueueRepository $queue;
    private QueueWorker $worker;
    private Logger $logger;
    private SitemapUrlCollector $sitemapUrlCollector;
    private WpConfigEditor $wpConfigEditor;

    public function __construct(
        SettingsRepository $settings,
        CacheStorageInterface $storage,
        RuntimeConfigWriter $runtimeConfigWriter,
        DropInInstaller $dropInInstaller,
        QueueRepository $queue,
        QueueWorker $worker,
        Logger $logger,
        SitemapUrlCollector $sitemapUrlCollector,
        WpConfigEditor $wpConfigEditor
    ) {
        $this->settings = $settings;
        $this->storage = $storage;
        $this->runtimeConfigWriter = $runtimeConfigWriter;
        $this->dropInInstaller = $dropInInstaller;
        $this->queue = $queue;
        $this->worker = $worker;
        $this->logger = $logger;
        $this->sitemapUrlCollector = $sitemapUrlCollector;
        $this->wpConfigEditor = $wpConfigEditor;
    }

    public function register(): void
    {
        add_menu_page('Atlas Cache', 'Atlas Cache', 'manage_options', 'atlas-cache', [$this, 'overview'], 'dashicons-performance', 58);
        add_submenu_page('atlas-cache', 'Overview', 'Overview', 'manage_options', 'atlas-cache', [$this, 'overview']);
        add_submenu_page('atlas-cache', 'Settings', 'Settings', 'manage_options', 'atlas-cache-settings', [$this, 'settings']);
        add_submenu_page('atlas-cache', 'Cache rules', 'Cache rules', 'manage_options', 'atlas-cache-rules', [$this, 'rules']);
        add_submenu_page('atlas-cache', 'Queue', 'Queue', 'manage_options', 'atlas-cache-queue', [$this, 'queue']);
        add_submenu_page('atlas-cache', 'Log', 'Log', 'manage_options', 'atlas-cache-log', [$this, 'log']);
        add_submenu_page('atlas-cache', 'Tools', 'Tools', 'manage_options', 'atlas-cache-tools', [$this, 'tools']);
        add_submenu_page('atlas-cache', 'Diagnostics', 'Diagnostics', 'manage_options', 'atlas-cache-diagnostics', [$this, 'diagnostics']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (strpos($hook, 'atlas-cache') === false) {
            return;
        }

        wp_enqueue_style(
            'atlas-cache-admin',
            ATLAS_CACHE_URL . 'assets/admin.css',
            [],
            ATLAS_CACHE_VERSION
        );
    }

    public function registerAdminBar(\WP_Admin_Bar $adminBar): void
    {
        if (!current_user_can('manage_options') || !is_admin_bar_showing()) {
            return;
        }

        $adminBar->add_node([
            'id' => 'atlas-cache',
            'title' => 'Atlas Cache',
            'href' => admin_url('admin.php?page=atlas-cache'),
        ]);

        $currentUrl = $this->currentCacheTargetUrl();
        if ($currentUrl !== '') {
            $adminBar->add_node([
                'id' => 'atlas-cache-revalidate-page',
                'parent' => 'atlas-cache',
                'title' => 'Revalidate page',
                'href' => $this->toolbarActionUrl('revalidate-page', $currentUrl, $this->currentRequestUrl()),
            ]);
            $adminBar->add_node([
                'id' => 'atlas-cache-purge-page',
                'parent' => 'atlas-cache',
                'title' => 'Purge page',
                'href' => $this->toolbarActionUrl('purge-page', $currentUrl, $this->currentRequestUrl()),
            ]);
        }

        $adminBar->add_node([
            'id' => 'atlas-cache-revalidate-site',
            'parent' => 'atlas-cache',
            'title' => 'Revalidate site',
            'href' => $this->toolbarActionUrl('revalidate-site', '', $this->currentRequestUrl()),
        ]);
        $adminBar->add_node([
            'id' => 'atlas-cache-purge-all',
            'parent' => 'atlas-cache',
            'title' => 'Clear cache files',
            'href' => $this->toolbarActionUrl('purge-all', '', $this->currentRequestUrl()),
            'meta' => [
                'onclick' => 'return confirm("Clear all Atlas Cache HTML files now? New cache files can be created again if Enable cache is on.");',
            ],
        ]);
        $adminBar->add_node([
            'id' => 'atlas-cache-queue',
            'parent' => 'atlas-cache',
            'title' => 'Queue',
            'href' => admin_url('admin.php?page=atlas-cache-queue'),
        ]);
        $adminBar->add_node([
            'id' => 'atlas-cache-settings',
            'parent' => 'atlas-cache',
            'title' => 'Settings',
            'href' => admin_url('admin.php?page=atlas-cache-settings'),
        ]);
    }

    public function handleToolbarAction(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to run Atlas Cache actions.', 'atlas-cache'));
        }

        check_admin_referer('atlas_cache_toolbar');

        $tool = isset($_GET['atlas_cache_tool']) ? sanitize_key((string) wp_unslash($_GET['atlas_cache_tool'])) : '';
        $url = isset($_GET['atlas_cache_url']) ? esc_url_raw((string) wp_unslash($_GET['atlas_cache_url'])) : '';
        if ($url !== '' && !$this->isCacheablePublicUrl($url)) {
            $url = '';
        }

        if ($url !== '' && $tool === 'revalidate-page') {
            $this->queue->enqueueUrl($url, 1, 'revalidate');
            $this->logger->log('revalidate', 'Queued page revalidate: ' . $url);
        }

        if ($url !== '' && $tool === 'purge-page') {
            $this->queue->enqueueUrl($url, 1, 'purge');
            $this->logger->log('purge', 'Queued page purge: ' . $url);
        }

        if ($tool === 'revalidate-site') {
            $urls = $this->collectRefreshUrls();
            $created = $this->queue->enqueueMany($urls, 10, 'revalidate');
            $this->logger->log('revalidate', 'Queued sitemap revalidate from toolbar: ' . $created . ' new / ' . count($urls) . ' total');
        }

        if ($tool === 'purge-all') {
            $this->storage->purgeAll();
            $this->logger->log('purge', 'Toolbar clear cache files');
        }

        $redirect = isset($_GET['atlas_cache_redirect']) ? esc_url_raw((string) wp_unslash($_GET['atlas_cache_redirect'])) : '';
        wp_safe_redirect($redirect !== '' ? $redirect : admin_url('admin.php?page=atlas-cache'));
        exit;
    }

    public function handleActions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['atlas_cache_save_settings'])) {
            check_admin_referer('atlas_cache_save_settings');
            $this->saveSettings();
            wp_safe_redirect(add_query_arg('atlas-cache-updated', '1', wp_get_referer() ?: admin_url('admin.php?page=atlas-cache-settings')));
            exit;
        }

        if (isset($_POST['atlas_cache_save_rules'])) {
            check_admin_referer('atlas_cache_save_rules');
            $this->saveRules();
            wp_safe_redirect(add_query_arg('atlas-cache-updated', '1', wp_get_referer() ?: admin_url('admin.php?page=atlas-cache-rules')));
            exit;
        }

        if (isset($_POST['atlas_cache_tool'])) {
            check_admin_referer('atlas_cache_tools');
            $this->runTool((string) $_POST['atlas_cache_tool']);
            wp_safe_redirect(add_query_arg(['atlas-cache-updated' => '1', 'atlas-cache-tool' => '1'], wp_get_referer() ?: admin_url('admin.php?page=atlas-cache-tools')));
            exit;
        }
    }

    public function overview(): void
    {
        $settings = $this->settings->all();
        $stats = $this->storage->stats();
        $this->header('Overview');
        $this->notice();
        echo '<div class="atlas-cache-grid">';
        $this->card('Cache', !empty($settings['enabled']) ? 'Enabled' : 'Disabled');
        $this->card('Drop-in', $this->dropInInstaller->isOwnedByAtlas() ? 'Atlas Cache drop-in is active' : 'Missing or owned by another plugin');
        $this->card('WP_CACHE', (defined('WP_CACHE') && WP_CACHE) ? 'Enabled' : 'Disabled');
        $this->card('Cache size', esc_html(size_format((int) $stats['size'])));
        $this->card('Cache files', (string) (int) $stats['files']);
        $this->card('Queue', (string) $this->queue->countPending());
        echo '</div>';
        $this->footer();
    }

    public function settings(): void
    {
        $settings = $this->settings->all();
        $this->header('Settings');
        $this->notice();
        echo '<form method="post" class="atlas-cache-panel atlas-cache-form">';
        wp_nonce_field('atlas_cache_save_settings');
        echo '<input type="hidden" name="atlas_cache_save_settings" value="1">';
        $this->mainCheckbox('enabled', 'Enable cache', $settings, 'Master switch for Atlas Cache. When off, Atlas Cache does not serve existing HTML cache files and does not store new ones.');
        $this->number('ttl', 'TTL in seconds', $settings, 60, 31536000, 'After this time cached HTML is considered stale. With stale mode enabled, the old version can still be served while a new one is prepared.');
        $this->checkbox('stale_while_revalidate', 'Stale while revalidate', $settings, false, 'Visitors can keep receiving the last complete cached HTML while revalidation runs in the background.');
        $this->number('worker_batch_size', 'URLs per worker run', $settings, 1, 50, 'How many queued URLs the worker may process in one run.');
        $this->number('content_change_debounce_minutes', 'Revalidate delay after content changes', $settings, 0, 1440, 'When content is saved repeatedly, Atlas Cache waits this many minutes after the last save before processing the queued revalidation.');
        $this->checkbox('debug_headers', 'Debug HTTP headers', $settings, false, 'The basic X-Atlas-Cache status header is always sent. Enable this to add detailed reason, key and age headers.');
        $this->renderCacheStatusLegend();
        $this->checkbox('frontend_debug_enabled', 'Frontend debug comment', $settings, false, 'Adds a diagnostic HTML comment to public pages. Use temporarily only.');
        $this->number('frontend_debug_expires_after_days', 'Auto-disable frontend debug after days', $settings, 1, 365, 'Public HTML debug output turns itself off after this time. Default is 14 days.');
        $this->checkbox('debug_log', 'File debug log', $settings, false, 'Writes STORE, BYPASS, PURGE and REVALIDATE events into log files in the cache directory.');
        $this->number('debug_log_retention_days', 'Delete logs older than days', $settings, 1, 365, 'Older log files are deleted during scheduled cleanup.');
        echo '<div class="atlas-cache-info">Logged-in users are always bypassed in this version. Atlas Cache currently stores and serves public HTML only.</div>';
        submit_button('Save settings');
        echo '</form>';
        $this->footer();
    }

    public function rules(): void
    {
        $settings = $this->settings->all();
        $this->header('Cache rules');
        $this->notice();
        echo '<form method="post" class="atlas-cache-panel atlas-cache-form">';
        wp_nonce_field('atlas_cache_save_rules');
        echo '<input type="hidden" name="atlas_cache_save_rules" value="1">';
        $this->textarea('excluded_url_patterns', 'Excluded URL patterns', $settings, 'One URL fragment per line. Matching requests bypass cache.');
        $this->textarea('sensitive_cookies', 'Sensitive cookies', $settings, 'Cookie prefixes that force BYPASS, such as login, cart and session cookies.');
        $this->textarea('query_string_whitelist', 'Allowed query parameters', $settings, 'By default, query strings bypass cache. Add only parameters that are explicitly safe to cache.');
        submit_button('Save rules');
        echo '</form>';
        $this->footer();
    }

    public function queue(): void
    {
        $this->header('Queue');
        $this->notice();
        $counts = $this->queue->countsByStatus();
        echo '<div class="atlas-cache-grid">';
        $this->card('Pending items', (string) ($counts['pending'] ?? 0));
        $this->card('Done items', (string) ($counts['done'] ?? 0));
        $this->card('Failed items', (string) ($counts['failed'] ?? 0));
        echo '</div>';
        echo '<div class="atlas-cache-panel">';
        $this->renderQueueSummary();
        $this->renderQueueTable();
        echo '</div>';
        $this->footer();
    }

    public function log(): void
    {
        $this->header('Log');
        echo '<div class="atlas-cache-panel">';
        echo '<p>Logs are stored in <code>wp-content/cache/atlas-cache/logs/</code> and are deleted automatically according to the retention setting.</p>';
        $this->renderLogViewer();
        echo '</div>';
        $this->footer();
    }

    public function tools(): void
    {
        $this->header('Tools');
        $this->notice();
        echo '<form method="post" class="atlas-cache-tools">';
        wp_nonce_field('atlas_cache_tools');
        $this->toolButton('queue-revalidate-all', 'Revalidate cache of site', 'Queues URLs found in the sitemap for background revalidation. Existing cache remains available until the worker stores the new version.', 'primary');
        $this->toolButton('run-worker', 'Run worker now', 'Processes pending queue items immediately, using the configured URLs-per-run limit. Revalidate jobs use an internal request.', 'secondary');
        $this->toolButton('enable-wp-cache', 'Enable WP_CACHE', 'Writes a small Atlas Cache marker block into wp-config.php so WordPress loads advanced-cache.php before bootstrapping. Reload the admin after running it.', 'secondary');
        $this->toolButton('rewrite-config', 'Repair fast-cache settings file', 'Usually not needed. Settings are written automatically. Use this only when diagnostics reports a missing or broken advanced-cache.php config file.', 'secondary');
        $this->toolButton('install-dropin', 'Reinstall drop-in', 'Copies the Atlas Cache advanced-cache.php file into wp-content again. It will not overwrite another plugin’s drop-in unless the Atlas Cache ownership marker is present.', 'secondary');
        $this->toolButton('purge-all', 'Clear cache files', 'Immediately deletes all Atlas Cache HTML files without using the queue. If Enable cache is on, new cache files can be created again by future public visits and revalidation jobs.', 'primary', 'Clear all Atlas Cache HTML files now? New cache files can be created again if Enable cache is on.');
        echo '</form>';
        $this->footer();
    }

    public function diagnostics(): void
    {
        $this->header('Diagnostics');
        $diagnostics = get_option('atlas_cache_diagnostics', []);
        $pageCachePlugins = $this->detectKnownPageCachePlugins();
        $formPlugins = $this->detectKnownFormPlugins();
        $externalCacheHeaders = $this->detectExternalCacheHeaders();
        echo '<table class="widefat striped"><tbody>';
        $this->row('WP_CACHE', (defined('WP_CACHE') && WP_CACHE) ? 'Enabled' : 'Disabled - WordPress will not load the drop-in until WP_CACHE is true.');
        $this->row('wp-config.php', $this->wpConfigStatus());
        $this->row('advanced-cache.php', $this->dropInInstaller->exists() ? 'Exists' : 'Missing');
        $this->row('Drop-in owner', $this->dropInInstaller->isOwnedByAtlas() ? 'Atlas Cache' : 'Another plugin or unknown');
        $this->row('Cache directory', is_writable(WP_CONTENT_DIR . '/cache/atlas-cache') ? 'Writable' : 'Not writable');
        $this->row('Update endpoint', $this->updateEndpointStatus());
        if (is_array($diagnostics) && !empty($diagnostics['last_error'])) {
            $this->row('Last error', (string) $diagnostics['last_error']);
        }
        echo '</tbody></table>';

        $this->renderCacheStatusLegend();

        echo '<h2>Compatibility checks</h2>';
        echo '<table class="widefat striped"><tbody>';
        if ($pageCachePlugins !== []) {
            $this->row('Page cache conflict', 'Warning: another page-cache plugin is active: ' . implode(', ', $pageCachePlugins) . '. Only one plugin should own advanced-cache.php. Atlas Cache does not overwrite a foreign drop-in.');
        } else {
            $this->row('Page cache conflict', 'OK - no known active page-cache plugin detected.');
        }

        if ($externalCacheHeaders !== []) {
            $this->row('Server or proxy cache', 'Warning: cache-like response headers were detected on the homepage: ' . implode(', ', $externalCacheHeaders) . '. Atlas Cache only manages its own HTML files and will not purge this outer layer.');
        } else {
            $this->row('Server or proxy cache', 'OK - no known external cache headers detected on the homepage.');
        }

        if ($formPlugins !== []) {
            $this->row('Forms', 'Warning: active form plugin detected: ' . implode(', ', $formPlugins) . '. Exclude pages with forms unless the specific form is known to be cache-safe.');
        } else {
            $this->row('Forms', 'OK - no known active form plugin detected.');
        }

        if ($this->isPluginActiveByBasename('woocommerce/woocommerce.php') || class_exists('WooCommerce')) {
            $this->row('WooCommerce', 'Warning: WooCommerce is active. Atlas Cache bypasses common cart, checkout, account and session cookies, but stores should use one WooCommerce-safe page cache setup only.');
        } else {
            $this->row('WooCommerce', 'OK - WooCommerce is not active.');
        }
        echo '</tbody></table>';
        $this->footer();
    }

    private function saveSettings(): void
    {
        $current = $this->settings->all();
        $frontendDebugWasEnabled = !empty($current['frontend_debug_enabled']);
        $frontendDebugEnabled = !empty($_POST['frontend_debug_enabled']);

        $settings = [
            'enabled' => !empty($_POST['enabled']),
            'ttl' => (int) ($_POST['ttl'] ?? $current['ttl']),
            'stale_while_revalidate' => !empty($_POST['stale_while_revalidate']),
            'worker_batch_size' => (int) ($_POST['worker_batch_size'] ?? $current['worker_batch_size']),
            'content_change_debounce_minutes' => (int) ($_POST['content_change_debounce_minutes'] ?? $current['content_change_debounce_minutes']),
            'debug_headers' => !empty($_POST['debug_headers']),
            'frontend_debug_enabled' => $frontendDebugEnabled,
            'frontend_debug_enabled_at' => $frontendDebugEnabled && !$frontendDebugWasEnabled ? time() : (int) $current['frontend_debug_enabled_at'],
            'frontend_debug_expires_after_days' => (int) ($_POST['frontend_debug_expires_after_days'] ?? $current['frontend_debug_expires_after_days']),
            'debug_log' => !empty($_POST['debug_log']),
            'debug_log_retention_days' => (int) ($_POST['debug_log_retention_days'] ?? $current['debug_log_retention_days']),
            'excluded_url_patterns' => $this->postedLines('excluded_url_patterns', $current['excluded_url_patterns']),
            'sensitive_cookies' => $this->postedLines('sensitive_cookies', $current['sensitive_cookies']),
            'query_string_whitelist' => $this->postedLines('query_string_whitelist', $current['query_string_whitelist']),
        ];

        if (!$frontendDebugEnabled) {
            $settings['frontend_debug_enabled_at'] = 0;
        }

        $this->settings->save($settings);
        $this->runtimeConfigWriter->write();

        if (empty($current['enabled']) && !empty($settings['enabled'])) {
            $this->queueSitemapRevalidation('Settings enabled cache');
        }
    }

    private function saveRules(): void
    {
        $settings = $this->settings->all();
        $settings['excluded_url_patterns'] = $this->postedLines('excluded_url_patterns', $settings['excluded_url_patterns']);
        $settings['sensitive_cookies'] = $this->postedLines('sensitive_cookies', $settings['sensitive_cookies']);
        $settings['query_string_whitelist'] = $this->postedLines('query_string_whitelist', $settings['query_string_whitelist']);

        $this->settings->save($settings);
        $this->runtimeConfigWriter->write();
    }

    private function runTool(string $tool): void
    {
        try {
            if ($tool === 'purge-all') {
                $this->storage->purgeAll();
                $this->logger->log('purge', 'Manual clear cache files');
                update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_tool_message' => 'Cache files were cleared immediately. No queue item was created. If Enable cache is on, new cache files can be created again.'], false);
                return;
            }

            if ($tool === 'queue-revalidate-all') {
                $this->queueSitemapRevalidation('Manual sitemap revalidate');
                return;
            }

            if ($tool === 'run-worker') {
                $result = $this->worker->run();
                $message = 'Worker run completed: processed=' . $result['processed'] . ', done=' . $result['done'] . ', failed=' . $result['failed'] . '.';
                $this->logger->log('revalidate', $message);
                update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_worker_run' => time(), 'last_worker_result' => $result, 'last_tool_message' => $message], false);
                return;
            }

            if ($tool === 'enable-wp-cache') {
                $this->wpConfigEditor->enableCache();
                update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_tool_message' => 'WP_CACHE was enabled in wp-config.php. Reload WordPress admin for the status card to update.'], false);
                return;
            }

            if ($tool === 'rewrite-config') {
                $this->runtimeConfigWriter->write();
                update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_tool_message' => 'Fast-cache settings file was rewritten.'], false);
                return;
            }

            if ($tool === 'install-dropin') {
                $this->runtimeConfigWriter->write();
                $this->dropInInstaller->install();
                update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_tool_message' => 'Drop-in was reinstalled and fast-cache settings file was rewritten.'], false);
            }
        } catch (RuntimeException $exception) {
            update_option('atlas_cache_diagnostics', ['last_error' => $exception->getMessage(), 'last_tool_message' => 'Tool failed: ' . $exception->getMessage()], false);
        }
    }

    /**
     * @return list<string>
     */
    private function collectRefreshUrls(): array
    {
        return $this->sitemapUrlCollector->collect();
    }

    private function queueSitemapRevalidation(string $source): void
    {
        $urls = $this->collectRefreshUrls();
        $result = $this->queue->enqueueManyDetailed($urls, 10, 'revalidate');
        $message = 'Sitemap revalidate queued: ' . $this->formatQueueResult($result);
        $this->logger->log('revalidate', $source . ': ' . $message);
        update_option('atlas_cache_diagnostics', ['last_error' => '', 'last_revalidate_queued' => time(), 'last_revalidate_queued_result' => $result, 'last_tool_message' => $message], false);
    }

    /**
     * @param mixed $fallback
     * @return list<string>
     */
    private function postedLines(string $key, $fallback): array
    {
        if (!isset($_POST[$key])) {
            return is_array($fallback) ? array_values($fallback) : [];
        }

        $raw = sanitize_textarea_field(wp_unslash((string) $_POST[$key]));
        $lines = preg_split('/\R/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return list<string>
     */
    private function detectKnownPageCachePlugins(): array
    {
        $plugins = [
            'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
            'wp-super-cache/wp-cache.php' => 'WP Super Cache',
            'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache',
            'wp-rocket/wp-rocket.php' => 'WP Rocket',
            'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
            'cache-enabler/cache-enabler.php' => 'Cache Enabler',
            'breeze/breeze.php' => 'Breeze',
            'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer',
            'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
        ];

        return $this->detectActivePlugins($plugins);
    }

    /**
     * @return list<string>
     */
    private function detectKnownFormPlugins(): array
    {
        $plugins = [
            'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
            'gravityforms/gravityforms.php' => 'Gravity Forms',
            'wpforms-lite/wpforms.php' => 'WPForms Lite',
            'wpforms/wpforms.php' => 'WPForms',
            'ninja-forms/ninja-forms.php' => 'Ninja Forms',
            'fluentform/fluentform.php' => 'Fluent Forms',
            'formidable/formidable.php' => 'Formidable Forms',
            'forminator/forminator.php' => 'Forminator',
            'elementor-pro/elementor-pro.php' => 'Elementor Pro Forms',
        ];

        return $this->detectActivePlugins($plugins);
    }

    private function updateEndpointStatus(): string
    {
        $url = trim((string) apply_filters('atlas_cache_update_info_url', (string) ATLAS_CACHE_UPDATE_INFO_URL));
        if ($url === '') {
            return 'Not configured - define ATLAS_CACHE_UPDATE_INFO_URL or use the atlas_cache_update_info_url filter.';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return 'Configured, but ignored - the update manifest URL must use HTTPS.';
        }

        return 'Configured: ' . $url;
    }

    /**
     * @param array<string, string> $plugins
     * @return list<string>
     */
    private function detectActivePlugins(array $plugins): array
    {
        $detected = [];

        foreach ($plugins as $basename => $name) {
            if ($this->isPluginActiveByBasename($basename)) {
                $detected[] = $name;
            }
        }

        return $detected;
    }

    private function isPluginActiveByBasename(string $basename): bool
    {
        $activePlugins = (array) get_option('active_plugins', []);
        if (in_array($basename, $activePlugins, true)) {
            return true;
        }

        if (is_multisite()) {
            $networkPlugins = (array) get_site_option('active_sitewide_plugins', []);
            return array_key_exists($basename, $networkPlugins);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function detectExternalCacheHeaders(): array
    {
        $response = wp_remote_head(home_url('/'), [
            'timeout' => 5,
            'redirection' => 0,
            'headers' => [
                'X-Atlas-Cache-Diagnostic' => '1',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['homepage check failed: ' . $response->get_error_message()];
        }

        $headers = wp_remote_retrieve_headers($response);
        if (!is_object($headers) || !method_exists($headers, 'getAll')) {
            return [];
        }

        $cacheHeaderNames = [
            'age',
            'cf-cache-status',
            'server-timing',
            'x-cache',
            'x-cache-status',
            'x-fastcgi-cache',
            'x-litespeed-cache',
            'x-proxy-cache',
            'x-varnish',
        ];
        $detected = [];
        $headerValues = $headers->getAll();

        foreach ($cacheHeaderNames as $name) {
            if (!isset($headerValues[$name])) {
                continue;
            }

            $value = $headerValues[$name];
            if (is_array($value)) {
                $value = implode('; ', array_map('strval', $value));
            }

            $detected[] = $name . '=' . (string) $value;
        }

        return $detected;
    }

    private function header(string $title): void
    {
        echo '<div class="wrap atlas-cache"><div class="atlas-cache-header"><h1>' . esc_html($title) . '</h1></div>';
    }

    private function footer(): void
    {
        echo '</div>';
    }

    private function notice(): void
    {
        if (isset($_GET['atlas-cache-updated'])) {
            $message = 'Saved.';
            $class = 'notice-success';
            if (isset($_GET['atlas-cache-tool'])) {
                $diagnostics = get_option('atlas_cache_diagnostics', []);
                if (is_array($diagnostics)) {
                    if (!empty($diagnostics['last_error'])) {
                        $message = (string) $diagnostics['last_error'];
                        $class = 'notice-error';
                    } elseif (!empty($diagnostics['last_tool_message'])) {
                        $message = (string) $diagnostics['last_tool_message'];
                    }
                }
            }

            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * @param array{total:int, created:int, requeued:int, updated:int, skipped:int, failed:int} $result
     */
    private function formatQueueResult(array $result): string
    {
        return (int) $result['created'] . ' new, '
            . (int) $result['requeued'] . ' requeued, '
            . (int) $result['updated'] . ' already pending updated, '
            . (int) $result['skipped'] . ' skipped, '
            . (int) $result['failed'] . ' failed, '
            . (int) $result['total'] . ' total.';
    }

    private function card(string $label, string $value): void
    {
        echo '<div class="atlas-cache-card"><h2>' . esc_html($label) . '</h2><p>' . esc_html($value) . '</p></div>';
    }

    private function renderCacheStatusLegend(): void
    {
        echo '<div class="atlas-cache-info">';
        echo '<strong>X-Atlas-Cache status:</strong> ';
        echo '<code>HIT</code> means Atlas Cache served a stored HTML file. ';
        echo '<code>MISS</code> means no stored HTML was served and WordPress generated the response. ';
        echo '<code>BYPASS</code> means the request was intentionally skipped, for example because it is admin, logged-in, Ajax, POST, REST, excluded or has a sensitive cookie.';
        echo '</div>';
    }

    private function renderLogViewer(): void
    {
        $files = $this->logger->files();
        if ($files === []) {
            echo '<p>No logs are available yet.</p>';
            return;
        }

        $selected = isset($_GET['atlas_cache_log_file']) ? sanitize_file_name(wp_unslash((string) $_GET['atlas_cache_log_file'])) : basename($files[0]);
        $selectedPath = '';

        foreach ($files as $file) {
            if (basename($file) === $selected) {
                $selectedPath = $file;
                break;
            }
        }

        if ($selectedPath === '') {
            $selectedPath = $files[0];
            $selected = basename($selectedPath);
        }

        echo '<form method="get" class="atlas-cache-log-select">';
        echo '<input type="hidden" name="page" value="atlas-cache-log">';
        echo '<label for="atlas-cache-log-file">Log file</label>';
        echo '<select id="atlas-cache-log-file" name="atlas_cache_log_file">';
        foreach ($files as $file) {
            $name = basename($file);
            echo '<option value="' . esc_attr($name) . '" ' . selected($selected, $name, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select> ';
        submit_button('Show', 'secondary', '', false);
        echo '</form>';

        $lines = $this->logger->readLines($selectedPath, 300);
        echo '<pre class="atlas-cache-log-output">';
        echo esc_html(implode("\n", $lines));
        echo '</pre>';
    }

    private function toolButton(string $value, string $label, string $description, string $style, string $confirm = ''): void
    {
        $class = $style === 'primary' ? 'button button-primary' : 'button button-secondary';
        $onclick = $confirm !== '' ? ' onclick="return confirm(\'' . esc_js($confirm) . '\')"' : '';

        echo '<div class="atlas-cache-tool">';
        echo '<div><h2>' . esc_html($label) . '</h2><p>' . esc_html($description) . '</p></div>';
        echo '<button class="' . esc_attr($class) . '" name="atlas_cache_tool" value="' . esc_attr($value) . '"' . $onclick . '>' . esc_html($label) . '</button>';
        echo '</div>';
    }

    private function renderQueueTable(): void
    {
        $rows = $this->queue->latest(20);
        if ($rows === []) {
            $databaseError = $this->queue->lastDatabaseError();
            if ($databaseError !== '') {
                echo '<div class="notice notice-error"><p>Queue table could not be read: ' . esc_html($databaseError) . '</p></div>';
                return;
            }

            echo '<p><strong>No queue jobs found.</strong></p>';
            echo '<p class="description">This is expected after <strong>Clear cache files</strong>, because that action deletes stored HTML files immediately and does not create background jobs. Use <strong>Revalidate cache of site</strong> when you want Atlas Cache to create queue jobs from sitemap URLs.</p>';
            return;
        }

        echo '<div class="atlas-cache-table-wrap">';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>URL</th><th>Type</th><th>Status</th><th>Priority</th><th>Attempts</th><th>Available at</th><th>Updated at</th><th>Error</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html((string) $row['url']) . '</code></td>';
            echo '<td><span class="atlas-cache-badge atlas-cache-badge-' . esc_attr((string) $row['mode']) . '">' . esc_html((string) $row['mode']) . '</span></td>';
            echo '<td>' . esc_html((string) $row['status']) . '</td>';
            echo '<td>' . esc_html((string) $row['priority']) . '</td>';
            echo '<td>' . esc_html((string) $row['attempts']) . '</td>';
            echo '<td>' . esc_html((string) $row['available_at']) . '</td>';
            echo '<td>' . esc_html((string) $row['updated_at']) . '</td>';
            echo '<td>' . esc_html((string) $row['last_error']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function renderQueueSummary(): void
    {
        echo '<p>The queue contains background cache jobs. Site-wide revalidation uses sitemap URLs. <strong>revalidate</strong> rebuilds cached HTML without removing the old file first. <strong>purge</strong> can appear for page-level purge actions and removes cached HTML for that URL.</p>';

        $diagnostics = get_option('atlas_cache_diagnostics', []);
        if (is_array($diagnostics) && !empty($diagnostics['last_tool_message'])) {
            echo '<div class="atlas-cache-info"><strong>Last tool action:</strong> ' . esc_html((string) $diagnostics['last_tool_message']) . '</div>';
        }
    }

    private function currentCacheTargetUrl(): string
    {
        if (!is_admin()) {
            $url = $this->currentPublicUrl();
            return $this->isCacheablePublicUrl($url) ? $url : '';
        }

        $postId = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if ($postId <= 0) {
            return '';
        }

        $permalink = get_permalink($postId);

        return is_string($permalink) && $this->isCacheablePublicUrl($permalink) ? esc_url_raw($permalink) : '';
    }

    private function currentPublicUrl(): string
    {
        $scheme = is_ssl() ? 'https://' : 'http://';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? wp_parse_url(home_url('/'), PHP_URL_HOST));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return esc_url_raw($scheme . $host . $uri);
    }

    private function toolbarActionUrl(string $tool, string $url, string $redirect): string
    {
        return wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'atlas_cache_toolbar',
                    'atlas_cache_tool' => $tool,
                    'atlas_cache_url' => $url,
                    'atlas_cache_redirect' => $redirect,
                ],
                admin_url('admin-post.php')
            ),
            'atlas_cache_toolbar'
        );
    }

    private function currentRequestUrl(): string
    {
        if (is_admin()) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/wp-admin/');
            return esc_url_raw(admin_url(ltrim(preg_replace('#^/wp-admin/#', '', $uri) ?? '', '/')));
        }

        return $this->currentPublicUrl();
    }

    private function isCacheablePublicUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !empty($parts['query'])) {
            return false;
        }

        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $homeHost !== '' && $host === $homeHost;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function mainCheckbox(string $key, string $label, array $settings, string $description): void
    {
        echo '<div class="atlas-cache-main-toggle">';
        echo '<label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . '> <span>' . esc_html($label) . '</span></label>';
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function checkbox(string $key, string $label, array $settings, bool $disabled = false, string $description = ''): void
    {
        echo '<div class="atlas-cache-field"><label><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked(!empty($settings[$key]), true, false) . disabled($disabled, true, false) . '> ' . esc_html($label) . '</label>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function number(string $key, string $label, array $settings, int $min, int $max, string $description = ''): void
    {
        echo '<div class="atlas-cache-field"><label><span class="atlas-cache-label">' . esc_html($label) . '</span><input type="number" class="small-text" name="' . esc_attr($key) . '" value="' . esc_attr((string) $settings[$key]) . '" min="' . esc_attr((string) $min) . '" max="' . esc_attr((string) $max) . '"></label>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function textarea(string $key, string $label, array $settings, string $description = ''): void
    {
        $value = implode("\n", array_map('strval', (array) ($settings[$key] ?? [])));
        echo '<div class="atlas-cache-field"><label><span class="atlas-cache-label">' . esc_html($label) . '</span><textarea name="' . esc_attr($key) . '" rows="8" class="large-text code">' . esc_textarea($value) . '</textarea></label>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</div>';
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function wpConfigStatus(): string
    {
        try {
            $path = $this->wpConfigEditor->configPath();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return is_writable($path) ? 'Writable: ' . $path : 'Not writable: ' . $path;
    }
}
