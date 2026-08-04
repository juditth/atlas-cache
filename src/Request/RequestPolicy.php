<?php

declare(strict_types=1);

namespace AtlasCache\Request;

final class RequestPolicy
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, string> $server
     * @param array<string, mixed> $cookies
     * @param list<string> $allowedHosts
     */
    public function bypassReason(array $settings, array $server, array $cookies, bool $wordpressAdmin = false, array $allowedHosts = []): ?string
    {
        if (empty($settings['enabled'])) {
            return 'Disabled';
        }

        if ($allowedHosts !== [] && !$this->hostAllowed((string) ($server['HTTP_HOST'] ?? ''), $allowedHosts)) {
            return 'HostMismatch';
        }

        if ($this->isRefreshRequest($settings, $server)) {
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
     * @param array<string, mixed> $server
     */
    public function isRefreshRequest(array $settings, array $server): bool
    {
        $token = (string) ($settings['refresh_token'] ?? '');
        $requestToken = isset($server['HTTP_X_ATLAS_CACHE_REFRESH_TOKEN'])
            ? (string) wp_unslash($server['HTTP_X_ATLAS_CACHE_REFRESH_TOKEN'])
            : '';

        return $token !== '' && $requestToken !== '' && hash_equals($token, $requestToken);
    }

    /**
     * @param list<string> $allowedHosts
     */
    private function hostAllowed(string $host, array $allowedHosts): bool
    {
        $host = strtolower($host);
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        return in_array($host, $allowedHosts, true);
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
