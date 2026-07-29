<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Cache\CacheKeyGenerator;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\Request\RequestPolicy;
use AtlasCache\Request\ResponsePolicy;
use AtlasCache\Storage\CacheStorageInterface;
use AtlasCache\Support\ClockInterface;
use Throwable;

final class PageCacheMiddleware
{
    private SettingsRepository $settings;
    private CacheKeyGenerator $cacheKeyGenerator;
    private CacheStorageInterface $storage;
    private RequestPolicy $requestPolicy;
    private ResponsePolicy $responsePolicy;
    private Logger $logger;
    private ClockInterface $clock;
    private bool $bufferStarted = false;

    public function __construct(
        SettingsRepository $settings,
        CacheKeyGenerator $cacheKeyGenerator,
        CacheStorageInterface $storage,
        RequestPolicy $requestPolicy,
        ResponsePolicy $responsePolicy,
        Logger $logger,
        ClockInterface $clock
    ) {
        $this->settings = $settings;
        $this->cacheKeyGenerator = $cacheKeyGenerator;
        $this->storage = $storage;
        $this->requestPolicy = $requestPolicy;
        $this->responsePolicy = $responsePolicy;
        $this->logger = $logger;
        $this->clock = $clock;
    }

    public function maybeStartBuffer(): void
    {
        if ($this->bufferStarted || is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        if (is_preview()) {
            return;
        }

        $settings = $this->settings->all();
        $reason = $this->requestPolicy->bypassReason($settings, $_SERVER, $_COOKIE, false);
        if ($reason !== null) {
            $this->debugHeader('BYPASS', $reason);
            $this->maybeLog('bypass', $reason . ' ' . $this->currentUri());
            return;
        }

        $this->bufferStarted = true;
        ob_start(function (string $html) use ($settings): string {
            return $this->storeResponse($html, $settings);
        });
    }

    public function shutdown(): void
    {
        // The output buffer callback performs the actual write when PHP flushes buffers.
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function storeResponse(string $html, array $settings): string
    {
        $reason = $this->responsePolicy->bypassReason($this->statusCode(), headers_list(), $html);
        if ($reason !== null) {
            $this->debugHeader('BYPASS', $reason);
            $this->maybeLog('bypass', $reason . ' ' . $this->currentUri(), $settings);
            return $html;
        }

        try {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? parse_url(home_url('/'), PHP_URL_HOST) ?: 'localhost');
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            if ($path === '') {
                $path = '/';
            }
            $language = $this->language();
            $key = $this->cacheKeyGenerator->generate($host, $path, $language, 'public');
            $now = $this->clock->now();

            $url = home_url($path);
            $metadata = [
                'url' => $url,
                'host' => $host,
                'path' => $path,
                'language' => $language,
                'variant' => 'public',
                'generated_at' => gmdate('c', $now),
                'status' => $this->statusCode(),
                'content_type' => 'text/html',
                'hash' => 'sha256:' . hash('sha256', $html),
                'cache_key' => $key->debugKey(),
                'version' => ATLAS_CACHE_VERSION,
            ];

            $this->storage->write($key, $html, $metadata);
            $this->debugHeader('MISS', 'Stored');
            $this->maybeLog('store', 'url=' . $url . ' key=' . $key->debugKey(), $settings);

            if ($this->frontendDebugActive($settings)) {
                $html .= "\n<!-- Atlas Cache: MISS; stored=" . esc_html(gmdate('c', $now)) . '; key=' . esc_html($key->debugKey()) . " -->";
            }
        } catch (Throwable $exception) {
            $this->debugHeader('BYPASS', 'StorageError');
            $this->maybeLog('error', $exception->getMessage(), $settings);
        }

        return $html;
    }

    private function statusCode(): int
    {
        $status = http_response_code();

        return is_int($status) ? $status : 200;
    }

    private function currentUri(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    private function language(): string
    {
        // The drop-in cannot call Polylang before WordPress bootstraps.
        // Keep the first implementation consistent; language-specific URL paths
        // still produce separate cache files through the path part of the key.
        return 'default';
    }

    /**
     * @param array<string, mixed>|null $settings
     */
    private function maybeLog(string $event, string $message, ?array $settings = null): void
    {
        $settings = $settings ?? $this->settings->all();
        if (!empty($settings['debug_log'])) {
            $this->logger->log($event, $message);
        }
    }

    private function debugHeader(string $status, string $reason): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Atlas-Cache: ' . $status);

        $settings = $this->settings->all();
        if (!empty($settings['debug_headers'])) {
            header('X-Atlas-Cache-Reason: ' . $reason);
        }
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function frontendDebugActive(array $settings): bool
    {
        if (empty($settings['frontend_debug_enabled']) || empty($settings['frontend_debug_enabled_at'])) {
            return false;
        }

        $expiresAt = (int) $settings['frontend_debug_enabled_at'] + ((int) $settings['frontend_debug_expires_after_days'] * DAY_IN_SECONDS);

        return $expiresAt > time();
    }
}
