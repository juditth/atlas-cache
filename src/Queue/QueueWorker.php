<?php

declare(strict_types=1);

namespace AtlasCache\Queue;

use AtlasCache\Cache\CacheKeyGenerator;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\Storage\CacheStorageInterface;

final class QueueWorker
{
    private QueueRepository $queue;
    private SettingsRepository $settings;
    private Logger $logger;
    private CacheKeyGenerator $cacheKeyGenerator;
    private CacheStorageInterface $storage;

    public function __construct(
        QueueRepository $queue,
        SettingsRepository $settings,
        Logger $logger,
        CacheKeyGenerator $cacheKeyGenerator,
        CacheStorageInterface $storage
    )
    {
        $this->queue = $queue;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->cacheKeyGenerator = $cacheKeyGenerator;
        $this->storage = $storage;
    }

    /**
     * @return array{processed:int,done:int,failed:int}
     */
    public function run(?int $limit = null): array
    {
        $settings = $this->settings->all();
        $token = $this->settings->ensureRefreshToken();
        $limit = $limit ?? (int) $settings['worker_batch_size'];
        $limit = max(1, min(50, $limit));
        $done = 0;
        $failed = 0;

        $this->queue->releaseStaleLocks();

        for ($i = 0; $i < $limit; $i++) {
            $item = $this->queue->claimNext();
            if ($item === null) {
                break;
            }

            $id = (int) $item['id'];
            $url = (string) $item['url'];
            $mode = (string) ($item['mode'] ?? 'revalidate');

            if ($mode === 'purge') {
                $this->purgeUrl($url);
                $this->queue->markDone($id);
                $this->logger->log('purge', 'DONE ' . $url);
                $done++;
                continue;
            }

            $refreshUrl = add_query_arg('atlas_cache_refresh', $token, $url);

            $response = wp_remote_get($refreshUrl, [
                'timeout' => 20,
                'redirection' => 0,
                'headers' => [
                    'X-Atlas-Cache-Refresh' => '1',
                    'Cache-Control' => 'no-cache',
                ],
                'cookies' => [],
            ]);

            if (is_wp_error($response)) {
                $this->queue->markFailed($id, $response->get_error_message(), (int) $item['attempts'] < 3);
                $this->logger->log('revalidate', 'FAILED ' . $url . ' ' . $response->get_error_message());
                $failed++;
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $cacheStatus = (string) wp_remote_retrieve_header($response, 'x-atlas-cache');
            $cacheReason = (string) wp_remote_retrieve_header($response, 'x-atlas-cache-reason');

            if ($code >= 200 && $code < 300 && strtoupper($cacheStatus) === 'MISS' && ($cacheReason === '' || $cacheReason === 'Stored')) {
                $this->queue->markDone($id);
                $this->logger->log('revalidate', 'DONE ' . $url);
                $done++;
                continue;
            }

            $error = 'Unexpected response: HTTP ' . $code . ', cache=' . $cacheStatus . ', reason=' . $cacheReason;
            $this->queue->markFailed($id, $error, (int) $item['attempts'] < 3);
            $this->logger->log('revalidate', 'FAILED ' . $url . ' ' . $error);
            $failed++;
        }

        return [
            'processed' => $done + $failed,
            'done' => $done,
            'failed' => $failed,
        ];
    }

    private function purgeUrl(string $url): void
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return;
        }

        $host = (string) ($parts['host'] ?? wp_parse_url(home_url('/'), PHP_URL_HOST) ?: 'localhost');
        $path = (string) ($parts['path'] ?? '/');
        $key = $this->cacheKeyGenerator->generate($host, $path, 'default', 'public');

        $this->storage->purge($key);
    }
}
