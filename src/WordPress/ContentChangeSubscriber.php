<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Config\SettingsRepository;
use AtlasCache\Debug\Logger;
use AtlasCache\Queue\QueueRepository;

final class ContentChangeSubscriber
{
    private QueueRepository $queue;
    private SettingsRepository $settings;
    private Logger $logger;
    private SitemapUrlCollector $sitemapUrlCollector;
    private CacheWarmupPriorityResolver $priorityResolver;

    public function __construct(
        QueueRepository $queue,
        SettingsRepository $settings,
        Logger $logger,
        SitemapUrlCollector $sitemapUrlCollector,
        CacheWarmupPriorityResolver $priorityResolver
    )
    {
        $this->queue = $queue;
        $this->settings = $settings;
        $this->logger = $logger;
        $this->sitemapUrlCollector = $sitemapUrlCollector;
        $this->priorityResolver = $priorityResolver;
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'onSavePost'], 20, 3);
        add_action('wp_update_nav_menu', [$this, 'onGlobalChange']);
        add_action('customize_save_after', [$this, 'onGlobalChange']);
        add_action('switch_theme', [$this, 'onGlobalChange']);
    }

    public function onSavePost(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $delay = $this->debounceSeconds();

        if ($this->isGlobalPostType($post->post_type)) {
            $count = $this->enqueueSiteUrlsWithPriorities($this->collectSiteUrls(), $delay);
            $this->logger->log('revalidate', 'Global content change queued from sitemap: post_type=' . $post->post_type . ', urls=' . $count);
            return;
        }

        if (!$this->isCacheablePostType($post->post_type)) {
            return;
        }

        $urls = $this->urlsForPost($postId);
        if ($urls === []) {
            return;
        }

        $count = $this->queue->enqueueMany($urls, $this->priorityResolver->priorityForPostType($post->post_type), 'revalidate', $delay);
        $this->logger->log('revalidate', 'Content change queued: post_id=' . $postId . ', post_type=' . $post->post_type . ', urls=' . $count);
    }

    public function onGlobalChange(): void
    {
        $count = $this->enqueueSiteUrlsWithPriorities($this->collectSiteUrls(), $this->debounceSeconds());
        $this->logger->log('revalidate', 'Global change queued from sitemap: urls=' . $count);
    }

    /**
     * @return list<string>
     */
    private function urlsForPost(int $postId): array
    {
        $urls = [];
        $permalink = get_permalink($postId);
        if (is_string($permalink) && $this->isCacheablePublicUrl($permalink)) {
            $urls[] = $permalink;
        }

        if ((int) get_option('page_on_front') === $postId) {
            $urls[] = home_url('/');
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @return list<string>
     */
    private function collectSiteUrls(): array
    {
        return $this->sitemapUrlCollector->collect();
    }

    private function isCacheablePostType(string $postType): bool
    {
        if (in_array($postType, $this->globalPostTypes(), true)) {
            return false;
        }

        if ($postType === 'attachment') {
            return false;
        }

        $object = get_post_type_object($postType);

        return $object instanceof \WP_Post_Type && is_post_type_viewable($object);
    }

    private function isGlobalPostType(string $postType): bool
    {
        return in_array($postType, $this->globalPostTypes(), true);
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
     * @return list<string>
     */
    private function globalPostTypes(): array
    {
        return [
            'bricks_template',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
        ];
    }

    private function debounceSeconds(): int
    {
        $settings = $this->settings->all();

        return (int) $settings['content_change_debounce_minutes'] * MINUTE_IN_SECONDS;
    }

    /**
     * @param list<string> $urls
     */
    private function enqueueSiteUrlsWithPriorities(array $urls, int $delay): int
    {
        $created = 0;
        $priorities = $this->priorityResolver->priorities();
        $taxonomyPriorities = $this->priorityResolver->taxonomyPriorities();

        foreach ($urls as $url) {
            $status = $this->queue->enqueueUrlDetailed($url, $this->priorityResolver->priorityForUrl($url, $priorities, $taxonomyPriorities), 'revalidate', $delay);
            if ($status === 'created' || $status === 'requeued') {
                $created++;
            }
        }

        return $created;
    }
}
