<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

use AtlasCache\Config\SettingsRepository;

final class CacheWarmupPriorityResolver
{
    private SettingsRepository $settings;
    /**
     * @var array<string, string>|null
     */
    private ?array $termArchiveMap = null;

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
     * @return list<\WP_Taxonomy>
     */
    public function taxonomies(): array
    {
        $taxonomies = get_taxonomies(['public' => true], 'objects');
        if (!is_array($taxonomies)) {
            return [];
        }

        $items = [];
        foreach ($taxonomies as $taxonomy) {
            if (!$taxonomy instanceof \WP_Taxonomy || !is_taxonomy_viewable($taxonomy) || $taxonomy->rewrite === false || !$this->hasTermArchive($taxonomy->name)) {
                continue;
            }

            $items[] = $taxonomy;
        }

        usort($items, static function (\WP_Taxonomy $a, \WP_Taxonomy $b): int {
            return strcmp((string) ($a->labels->name ?? $a->name), (string) ($b->labels->name ?? $b->name));
        });

        return array_values($items);
    }

    private function hasTermArchive(string $taxonomy): bool
    {
        $termIds = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'fields' => 'ids',
            'number' => 1,
        ]);

        if (!is_array($termIds) || $termIds === []) {
            return false;
        }

        $link = get_term_link((int) $termIds[0], $taxonomy);

        return is_string($link) && $this->normalizedPath($link) !== '';
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
     * @return array<string, int>
     */
    public function taxonomyPriorities(): array
    {
        $settings = $this->settings->all();
        $stored = is_array($settings['taxonomy_priorities'] ?? null) ? $settings['taxonomy_priorities'] : [];
        $priorities = [];

        foreach ($this->taxonomies() as $taxonomy) {
            $name = (string) $taxonomy->name;
            $priorities[$name] = max(1, min(100, (int) ($stored[$name] ?? $this->defaultTaxonomyPriority($name))));
        }

        return $priorities;
    }

    /**
     * @param array<string, int>|null $priorities
     * @param array<string, int>|null $taxonomyPriorities
     */
    public function priorityForUrl(string $url, ?array $priorities = null, ?array $taxonomyPriorities = null): int
    {
        $priorities = $priorities ?? $this->priorities();
        $postType = $this->postTypeForUrl($url);
        if ($postType !== '' && isset($priorities[$postType])) {
            return $priorities[$postType];
        }

        $taxonomyPriorities = $taxonomyPriorities ?? $this->taxonomyPriorities();
        $taxonomy = $this->taxonomyForUrl($url);
        if ($taxonomy !== '' && isset($taxonomyPriorities[$taxonomy])) {
            return $taxonomyPriorities[$taxonomy];
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

    public function defaultTaxonomyPriority(string $taxonomy): int
    {
        if ($taxonomy === 'category') {
            return 15;
        }

        if ($taxonomy === 'post_tag') {
            return 25;
        }

        return 15;
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

    private function taxonomyForUrl(string $url): string
    {
        $path = $this->normalizedPath($url);
        if ($path === '') {
            return '';
        }

        $map = $this->termArchiveMap();
        if (isset($map[$path])) {
            return $map[$path];
        }

        foreach ($map as $termPath => $taxonomy) {
            if (strpos($path, $termPath . '/page/') === 0) {
                return $taxonomy;
            }
        }

        return '';
    }

    /**
     * @return array<string, string>
     */
    private function termArchiveMap(): array
    {
        if ($this->termArchiveMap !== null) {
            return $this->termArchiveMap;
        }

        $map = [];
        foreach ($this->taxonomies() as $taxonomy) {
            $termIds = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'fields' => 'ids',
                'number' => 10000,
            ]);

            if (!is_array($termIds)) {
                continue;
            }

            foreach ($termIds as $termId) {
                $link = get_term_link((int) $termId, $taxonomy->name);
                if (!is_string($link)) {
                    continue;
                }

                $path = $this->normalizedPath($link);
                if ($path !== '') {
                    $map[$path] = (string) $taxonomy->name;
                }
            }
        }

        uksort($map, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        $this->termArchiveMap = $map;

        return $this->termArchiveMap;
    }

    private function normalizedPath(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($homeHost === '' || $host !== $homeHost) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '/');

        return trim(untrailingslashit($path), '/');
    }
}
