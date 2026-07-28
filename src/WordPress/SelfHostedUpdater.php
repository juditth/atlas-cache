<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class SelfHostedUpdater
{
    private string $pluginFile;
    private string $updateInfoUrl;

    public function __construct(string $pluginFile, string $currentVersion, string $updateInfoUrl)
    {
        $this->pluginFile = $pluginFile;
        $this->updateInfoUrl = $updateInfoUrl;
    }

    public function register(): void
    {
        $url = (string) apply_filters('atlas_cache_update_info_url', $this->updateInfoUrl);
        $url = trim($url);
        $parts = wp_parse_url($url);

        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            return;
        }

        $library = ATLAS_CACHE_DIR . 'plugin-update-checker/plugin-update-checker.php';
        if (!is_file($library)) {
            return;
        }

        require_once $library;

        PucFactory::buildUpdateChecker(
            $url,
            $this->pluginFile,
            'atlas-cache'
        );
    }
}
