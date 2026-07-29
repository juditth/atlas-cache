<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Config\SettingsRepository;

final class CacheWarmupPriorityResolver
{
    private SettingsRepository $settings;

    public function __construct(SettingsRepository $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @return list<\WP_Post_Type>
     */
    public function postTypes(): array
    {
        $postTypes = get_post_types(['public' => true], 'objects');
        if (!is_array($postTypes)) {
            return [];
        }

        $items = [];
        foreach ($postTypes as $postType) {
            if (!$postType instanceof \WP_Post_Type || $postType->name === 'attachment' || !is_post_type_viewable($postType)) {
                continue;
            }

            $items[] = $postType;
        }

        usort($items, static function (\WP_Post_Type $a, \WP_Post_Type $b): int {
            return strcmp((string) ($a->labels->name ?? $a->name), (string) ($b->labels->name ?? $b->name));
        });

        return array_values($items);
    }

    /**
     * @return array<string, int>
     */
    public function priorities(): array
    {
        $settings = $this->settings->all();
        $stored = is_array($settings['post_type_priorities'] ?? null) ? $settings['post_type_priorities'] : [];
        $priorities = [];

        foreach ($this->postTypes() as $postType) {
            $name = (string) $postType->name;
            $priorities[$name] = max(1, min(100, (int) ($stored[$name] ?? $this->defaultPriority($name))));
        }

        return $priorities;
    }

    /**
     * @param array<string, int>|null $priorities
     */
    public function priorityForUrl(string $url, ?array $priorities = null): int
    {
        $priorities = $priorities ?? $this->priorities();
        $postType = $this->postTypeForUrl($url);
        if ($postType !== '' && isset($priorities[$postType])) {
            return $priorities[$postType];
        }

        return 10;
    }

    public function priorityForPostType(string $postType): int
    {
        $priorities = $this->priorities();

        return isset($priorities[$postType]) ? $priorities[$postType] : $this->defaultPriority($postType);
    }

    public function defaultPriority(string $postType): int
    {
        if ($postType === 'page') {
            return 5;
        }

        if ($postType === 'post') {
            return 20;
        }

        return 10;
    }

    private function postTypeForUrl(string $url): string
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $homePath = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($path === '' || untrailingslashit($path) === untrailingslashit($homePath === '' ? '/' : $homePath)) {
            $frontPageId = (int) get_option('page_on_front');
            if ($frontPageId > 0) {
                $frontPostType = get_post_type($frontPageId);
                return is_string($frontPostType) ? $frontPostType : 'page';
            }

            return 'page';
        }

        $postId = url_to_postid($url);
        if ($postId <= 0) {
            return '';
        }

        $postType = get_post_type($postId);

        return is_string($postType) ? $postType : '';
    }
}
