<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Admin\AdminMenu;
use AtlasCache\Cache\CacheKeyGenerator;
use AtlasCache\Config\RuntimeConfigWriter;
use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\DropIn\DropInInstaller;
use AtlasCache\Queue\QueueRepository;
use AtlasCache\Queue\QueueWorker;
use AtlasCache\Request\RequestPolicy;
use AtlasCache\Request\ResponsePolicy;
use AtlasCache\Storage\CachePaths;
use AtlasCache\Storage\FileCacheStorage;
use AtlasCache\Storage\PathSanitizer;
use AtlasCache\Support\SystemClock;

final class PluginFactory
{
    public static function create(): Plugin
    {
        $paths = self::paths();
        $settings = new SettingsRepository();
        $storage = new FileCacheStorage($paths);
        $logger = new Logger($paths);
        $runtimeConfigWriter = new RuntimeConfigWriter($paths, $settings);
        $dropInInstaller = new DropInInstaller(
            ATLAS_CACHE_DIR . 'bin/advanced-cache.php',
            WP_CONTENT_DIR . '/advanced-cache.php'
        );
        $cacheKeyGenerator = new CacheKeyGenerator(new PathSanitizer());
        $requestPolicy = new RequestPolicy();
        $responsePolicy = new ResponsePolicy();
        $sitemapUrlCollector = new SitemapUrlCollector();
        $wpConfigEditor = new WpConfigEditor();
        $queue = new QueueRepository($GLOBALS['wpdb']);
        $worker = new QueueWorker($queue, $settings, $logger, $cacheKeyGenerator, $storage);
        $contentChangeSubscriber = new ContentChangeSubscriber($queue, $settings, $logger, $sitemapUrlCollector);
        $middleware = new PageCacheMiddleware(
            $settings,
            $cacheKeyGenerator,
            $storage,
            $requestPolicy,
            $responsePolicy,
            $logger,
            new SystemClock()
        );
        $adminMenu = new AdminMenu($settings, $storage, $runtimeConfigWriter, $dropInInstaller, $queue, $worker, $logger, $sitemapUrlCollector, $wpConfigEditor);
        $updater = new SelfHostedUpdater(ATLAS_CACHE_FILE, ATLAS_CACHE_VERSION, (string) ATLAS_CACHE_UPDATE_INFO_URL);

        return new Plugin($settings, $middleware, $adminMenu, $runtimeConfigWriter, $logger, $queue, $worker, $contentChangeSubscriber, $updater);
    }

    public static function paths(): CachePaths
    {
        return new CachePaths(WP_CONTENT_DIR . '/cache/atlas-cache');
    }
}
