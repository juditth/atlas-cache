<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use stdClass;
use WP_Error;

final class SelfHostedUpdater
{
    private const CACHE_KEY = 'atlas_cache_update_info';
    private const CACHE_TTL = 21600;

    private string $pluginFile;
    private string $pluginBasename;
    private string $slug;
    private string $currentVersion;
    private string $updateInfoUrl;

    public function __construct(string $pluginFile, string $currentVersion, string $updateInfoUrl)
    {
        $this->pluginFile = $pluginFile;
        $this->pluginBasename = plugin_basename($pluginFile);
        $this->slug = dirname($this->pluginBasename);
        $this->currentVersion = $currentVersion;
        $this->updateInfoUrl = $updateInfoUrl;
    }

    public function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);
        add_filter('plugins_api', [$this, 'pluginInformation'], 10, 3);
        add_filter('upgrader_pre_download', [$this, 'verifyPackageDownload'], 10, 4);
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function checkForUpdate($transient)
    {
        if (!is_object($transient) || empty($transient->checked) || !isset($transient->checked[$this->pluginBasename])) {
            return $transient;
        }

        $info = $this->remoteInfo();
        if ($info === null || version_compare($this->currentVersion, (string) $info['version'], '>=')) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[$this->pluginBasename] = $this->updateObject($info);

        return $transient;
    }

    /**
     * @param mixed $result
     * @param mixed $args
     * @return mixed
     */
    public function pluginInformation($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $info = $this->remoteInfo();
        if ($info === null) {
            return $result;
        }

        return $this->informationObject($info);
    }

    /**
     * @param mixed $reply
     * @param mixed $upgrader
     * @param mixed $hookExtra
     * @return mixed
     */
    public function verifyPackageDownload($reply, string $package, $upgrader, $hookExtra)
    {
        if ($reply !== false || $package === '') {
            return $reply;
        }

        $info = $this->remoteInfo();
        if ($info === null || (string) $info['download_url'] !== $package || empty($info['sha256'])) {
            return false;
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file = download_url($package);
        if (is_wp_error($file)) {
            return $file;
        }

        $expected = strtolower((string) $info['sha256']);
        $actual = strtolower((string) hash_file('sha256', (string) $file));
        if (!hash_equals($expected, $actual)) {
            wp_delete_file((string) $file);
            return new WP_Error('atlas_cache_bad_package_hash', 'Atlas Cache update package hash verification failed.');
        }

        return $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function remoteInfo(): ?array
    {
        $url = $this->updateInfoUrl();
        if ($url === '') {
            return null;
        }

        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached) && ($cached['_source_url'] ?? '') === $url) {
            return $cached;
        }

        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Atlas Cache/' . $this->currentVersion . '; ' . home_url('/'),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }

        $info = $this->normalizeInfo($data);
        if ($info === null) {
            return null;
        }

        $info['_source_url'] = $url;
        set_site_transient(self::CACHE_KEY, $info, self::CACHE_TTL);

        return $info;
    }

    private function updateInfoUrl(): string
    {
        $url = (string) apply_filters('atlas_cache_update_info_url', $this->updateInfoUrl);
        $url = trim($url);

        return $this->isHttpsUrl($url) ? $url : '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function normalizeInfo(array $data): ?array
    {
        $version = isset($data['version']) ? trim((string) $data['version']) : '';
        $downloadUrl = isset($data['download_url']) ? trim((string) $data['download_url']) : '';

        if ($version === '' || !$this->isHttpsUrl($downloadUrl)) {
            return null;
        }

        $sections = isset($data['sections']) && is_array($data['sections']) ? $data['sections'] : [];

        return [
            'version' => $version,
            'download_url' => $downloadUrl,
            'homepage' => $this->stringOrDefault($data['homepage'] ?? '', ''),
            'requires' => $this->stringOrDefault($data['requires'] ?? '', '6.0'),
            'tested' => $this->stringOrDefault($data['tested'] ?? '', ''),
            'requires_php' => $this->stringOrDefault($data['requires_php'] ?? '', '8.0'),
            'last_updated' => $this->stringOrDefault($data['last_updated'] ?? '', ''),
            'author' => $this->stringOrDefault($data['author'] ?? '', 'Atlas Cache'),
            'sections' => [
                'description' => $this->stringOrDefault($sections['description'] ?? '', 'Simple and safe HTML page cache.'),
                'changelog' => $this->stringOrDefault($sections['changelog'] ?? '', ''),
            ],
            'sha256' => isset($data['sha256']) ? strtolower(trim((string) $data['sha256'])) : '',
        ];
    }

    /**
     * @param array<string, mixed> $info
     */
    private function updateObject(array $info): stdClass
    {
        $update = new stdClass();
        $update->id = $this->pluginBasename;
        $update->slug = $this->slug;
        $update->plugin = $this->pluginBasename;
        $update->new_version = (string) $info['version'];
        $update->url = (string) $info['homepage'];
        $update->package = (string) $info['download_url'];
        $update->requires = (string) $info['requires'];
        $update->tested = (string) $info['tested'];
        $update->requires_php = (string) $info['requires_php'];

        return $update;
    }

    /**
     * @param array<string, mixed> $info
     */
    private function informationObject(array $info): stdClass
    {
        $information = new stdClass();
        $information->name = 'Atlas Cache';
        $information->slug = $this->slug;
        $information->version = (string) $info['version'];
        $information->author = (string) $info['author'];
        $information->homepage = (string) $info['homepage'];
        $information->download_link = (string) $info['download_url'];
        $information->requires = (string) $info['requires'];
        $information->tested = (string) $info['tested'];
        $information->requires_php = (string) $info['requires_php'];
        $information->last_updated = (string) $info['last_updated'];
        $information->sections = $info['sections'];

        return $information;
    }

    private function stringOrDefault($value, string $default): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = wp_parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']);
    }
}
