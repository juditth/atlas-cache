<?php

declare(strict_types=1);

namespace AtlasCache\Request;

final class ResponsePolicy
{
    /**
     * @param list<string> $headers
     */
    public function bypassReason(int $statusCode, array $headers, string $html): ?string
    {
        if ($statusCode !== 200) {
            return 'UnsupportedStatus';
        }

        if (trim($html) === '') {
            return 'EmptyResponse';
        }

        $hasHtmlContentType = false;

        foreach ($headers as $header) {
            $lower = strtolower($header);

            if (strpos($lower, 'content-type:') === 0 && strpos($lower, 'text/html') !== false) {
                $hasHtmlContentType = true;
            }

            if (strpos($lower, 'set-cookie:') === 0) {
                return 'SetCookie';
            }

            if (strpos($lower, 'cache-control:') === 0) {
                foreach (['private', 'no-store'] as $directive) {
                    if (strpos($lower, $directive) !== false) {
                        return 'PrivateHeaders';
                    }
                }
            }
        }

        if (!$hasHtmlContentType && stripos($html, '<html') === false) {
            return 'NotHtml';
        }

        return null;
    }
}
