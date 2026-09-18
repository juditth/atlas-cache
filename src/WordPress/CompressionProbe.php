<?php

declare(strict_types=1);

namespace AtlasCache\WordPress;

final class CompressionProbe
{
    /**
     * Returns true when the public homepage reports HTTP compression, false when
     * it does not, and null when the result cannot be trusted.
     */
    public function isCompressed(): ?bool
    {
        $response = wp_remote_get(home_url('/'), [
            'timeout' => 5,
            'redirection' => 0,
            'limit_response_size' => 1,
            'decompress' => false,
            'headers' => [
                'Accept-Encoding' => 'gzip, br',
                'X-Atlas-Cache-Diagnostic' => '1',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $encoding = wp_remote_retrieve_header($response, 'content-encoding');
        $encoding = is_array($encoding) ? implode(', ', $encoding) : (string) $encoding;

        return trim($encoding) !== '';
    }

    /** Apache is required because nginx ignores .htaccess. */
    public function isApache(): bool
    {
        return stripos((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 'apache') !== false;
    }
}
