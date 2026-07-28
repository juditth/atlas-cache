<?php

declare(strict_types=1);

namespace AtlasCache\Request;

final class RequestPolicy
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $server
     * @param array<string, mixed> $cookies
     */
    public function bypassReason(array $settings, array $server, array $cookies, bool $wordpressAdmin = false): ?string
    {
        if (empty($settings['enabled'])) {
            return 'Disabled';
        }

        if ($this->isRefreshRequest($settings)) {
            return null;
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return 'PostRequest';
        }

        if ($wordpressAdmin || $this->pathStartsWith((string) ($server['REQUEST_URI'] ?? '/'), ['/wp-admin', '/wp-login.php'])) {
            return 'Admin';
        }

        if (isset($server['HTTP_X_REQUESTED_WITH']) && strtolower((string) $server['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return 'Ajax';
        }

        if (!$this->queryStringAllowed((string) ($server['QUERY_STRING'] ?? ''), $settings['query_string_whitelist'] ?? [])) {
            return 'QueryString';
        }

        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        foreach (($settings['excluded_url_patterns'] ?? []) as $pattern) {
            if ($pattern !== '' && stripos($uri, (string) $pattern) !== false) {
                return 'ExcludedUrl';
            }
        }

        foreach ($cookies as $name => $value) {
            $name = (string) $name;
            foreach (($settings['sensitive_cookies'] ?? []) as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix !== '' && strpos($name, $prefix) === 0) {
                    return strpos($name, 'wordpress_logged_in_') === 0 ? 'LoggedIn' : 'SensitiveCookie';
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function isRefreshRequest(array $settings): bool
    {
        $token = (string) ($settings['refresh_token'] ?? '');
        $requestToken = isset($_GET['atlas_cache_refresh']) ? (string) wp_unslash($_GET['atlas_cache_refresh']) : '';

        return $token !== '' && $requestToken !== '' && hash_equals($token, $requestToken);
    }

    /**
     * @param list<string> $prefixes
     */
    private function pathStartsWith(string $uri, array $prefixes): bool
    {
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        foreach ($prefixes as $prefix) {
            if (stripos($path, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $whitelist
     */
    private function queryStringAllowed(string $queryString, $whitelist): bool
    {
        if ($queryString === '') {
            return true;
        }

        if (!is_array($whitelist) || $whitelist === []) {
            return false;
        }

        parse_str($queryString, $query);
        foreach (array_keys($query) as $key) {
            if (!in_array((string) $key, $whitelist, true)) {
                return false;
            }
        }

        return true;
    }
}
