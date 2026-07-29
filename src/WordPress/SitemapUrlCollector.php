<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

final class SitemapUrlCollector
{
    private const MAX_SITEMAPS = 100;
    private const MAX_URLS = 10000;

    /**
     * @return list<string>
     */
    public function collect(): array
    {
        $urls = [home_url('/')];

        if (!function_exists('simplexml_load_string')) {
            return $urls;
        }

        $sitemaps = apply_filters('atlas_cache_sitemap_urls', [
            home_url('/wp-sitemap.xml'),
            home_url('/sitemap_index.xml'),
        ]);

        if (!is_array($sitemaps)) {
            return $urls;
        }

        $visited = [];
        foreach ($sitemaps as $sitemap) {
            $urls = array_merge($urls, $this->collectFromSitemap((string) $sitemap, $visited, 0));
            $urls = $this->uniqueUrls($urls);
            if (count($urls) >= self::MAX_URLS) {
                break;
            }
        }

        return array_slice($this->uniqueUrls($urls), 0, self::MAX_URLS);
    }

    /**
     * @param array<string, bool> $visited
     * @return list<string>
     */
    private function collectFromSitemap(string $url, array &$visited, int $depth): array
    {
        $url = esc_url_raw($url);
        if ($url === '' || isset($visited[$url]) || count($visited) >= self::MAX_SITEMAPS || $depth > 4) {
            return [];
        }

        $visited[$url] = true;
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'redirection' => 3,
            'headers' => [
                'X-Atlas-Cache-Diagnostic' => '1',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || trim($body) === '') {
            return [];
        }

        $xml = @simplexml_load_string($body);
        if (!$xml instanceof \SimpleXMLElement) {
            return [];
        }

        $root = strtolower($xml->getName());
        if ($root === 'sitemapindex') {
            $urls = [];
            foreach ($this->locations($xml, 'sitemap') as $location) {
                $urls = array_merge($urls, $this->collectFromSitemap($location, $visited, $depth + 1));
                if (count($urls) >= self::MAX_URLS) {
                    break;
                }
            }

            return array_slice($this->uniqueUrls($urls), 0, self::MAX_URLS);
        }

        return array_values(array_filter($this->locations($xml, 'url'), function (string $location): bool {
            return $this->isCacheableSitemapUrl($location);
        }));
    }

    /**
     * @return list<string>
     */
    private function locations(\SimpleXMLElement $xml, string $container): array
    {
        $container = $container === 'sitemap' ? 'sitemap' : 'url';
        $nodes = $xml->xpath('/*[local-name()="' . $xml->getName() . '"]/*[local-name()="' . $container . '"]/*[local-name()="loc"]');
        if (!is_array($nodes)) {
            return [];
        }

        $locations = [];
        foreach ($nodes as $node) {
            $location = esc_url_raw(trim((string) $node));
            if ($location !== '') {
                $locations[] = $location;
            }
        }

        return $this->uniqueUrls($locations);
    }

    private function isCacheableSitemapUrl(string $url): bool
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !empty($parts['query'])) {
            return false;
        }

        if ($this->isStaticAssetPath((string) ($parts['path'] ?? ''))) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return $homeHost !== '' && $host === $homeHost;
    }

    private function isStaticAssetPath(string $path): bool
    {
        return preg_match('/\.(?:avif|bmp|css|eot|gif|ico|jpe?g|js|json|map|mp3|mp4|ogg|otf|pdf|png|svg|ttf|txt|wav|webm|webp|woff2?|xml|zip)$/i', $path) === 1;
    }

    /**
     * @param list<string> $urls
     * @return list<string>
     */
    private function uniqueUrls(array $urls): array
    {
        return array_values(array_unique(array_filter($urls, static fn (string $url): bool => $url !== '')));
    }
}
