<?php

declare(strict_types=1);

namespace AtlasCache\Config;

final class SettingsRepository
{
    public const OPTION_NAME = 'atlas_cache_settings';

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'ttl' => 86400,
            'stale_while_revalidate' => true,
            'worker_batch_size' => 4,
            'content_change_debounce_minutes' => 10,
            'debug_headers' => true,
            'frontend_debug_enabled' => false,
            'frontend_debug_enabled_at' => 0,
            'frontend_debug_expires_after_days' => 14,
            'debug_log' => true,
            'debug_log_retention_days' => 14,
            'refresh_token' => '',
            'post_type_priorities' => [
                'page' => 5,
                'post' => 20,
            ],
            'excluded_url_patterns' => [
                '/wp-admin',
                '/wp-login.php',
                '/cart',
                '/checkout',
                '/my-account',
            ],
            'sensitive_cookies' => [
                'wordpress_logged_in_',
                'wordpress_sec_',
                'wp-postpass_',
                'comment_author_',
                'woocommerce_items_in_cart',
                'woocommerce_cart_hash',
                'wp_woocommerce_session_',
            ],
            'query_string_whitelist' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $settings = get_option(self::OPTION_NAME, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        return $this->normalize(array_replace_recursive($this->defaults(), $settings));
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function save(array $settings): void
    {
        update_option(self::OPTION_NAME, $this->normalize(array_replace_recursive($this->defaults(), $settings)), false);
    }

    public function ensureDefaults(): void
    {
        if (get_option(self::OPTION_NAME, null) === null) {
            add_option(self::OPTION_NAME, $this->defaults(), '', false);
        }
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function normalize(array $settings): array
    {
        $settings['enabled'] = !empty($settings['enabled']);
        $settings['ttl'] = max(60, (int) $settings['ttl']);
        $settings['stale_while_revalidate'] = !empty($settings['stale_while_revalidate']);
        $settings['worker_batch_size'] = max(1, min(50, (int) $settings['worker_batch_size']));
        $settings['content_change_debounce_minutes'] = max(0, min(1440, (int) $settings['content_change_debounce_minutes']));
        $settings['debug_headers'] = !empty($settings['debug_headers']);
        $settings['frontend_debug_enabled'] = !empty($settings['frontend_debug_enabled']);
        $settings['frontend_debug_enabled_at'] = max(0, (int) $settings['frontend_debug_enabled_at']);
        $settings['frontend_debug_expires_after_days'] = max(1, min(365, (int) $settings['frontend_debug_expires_after_days']));
        $settings['debug_log'] = !empty($settings['debug_log']);
        $settings['debug_log_retention_days'] = max(1, min(365, (int) $settings['debug_log_retention_days']));
        $settings['refresh_token'] = $this->normalizeToken((string) ($settings['refresh_token'] ?? ''));
        $settings['post_type_priorities'] = $this->normalizePostTypePriorities($settings['post_type_priorities'] ?? []);
        $settings['excluded_url_patterns'] = $this->normalizeStringList($settings['excluded_url_patterns'] ?? []);
        $settings['sensitive_cookies'] = $this->normalizeStringList($settings['sensitive_cookies'] ?? []);
        $settings['query_string_whitelist'] = $this->normalizeStringList($settings['query_string_whitelist'] ?? []);

        return $settings;
    }

    public function ensureRefreshToken(): string
    {
        $settings = $this->all();
        if ((string) $settings['refresh_token'] !== '') {
            return (string) $settings['refresh_token'];
        }

        $settings['refresh_token'] = bin2hex(random_bytes(24));
        $this->save($settings);

        return (string) $settings['refresh_token'];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeStringList($value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\R/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    private function normalizeToken(string $token): string
    {
        return preg_match('/^[a-f0-9]{48}$/', $token) === 1 ? $token : '';
    }

    /**
     * @param mixed $value
     * @return array<string, int>
     */
    private function normalizePostTypePriorities($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $priorities = [];
        foreach ($value as $postType => $priority) {
            $postType = sanitize_key((string) $postType);
            if ($postType !== '') {
                $priorities[$postType] = max(1, min(100, (int) $priority));
            }
        }

        return $priorities;
    }
}
