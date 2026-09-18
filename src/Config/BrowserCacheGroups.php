<?php

declare(strict_types=1);

namespace AtlasCache\Config;

final class BrowserCacheGroups
{
    /**
     * Supported static file groups. Zero days leaves the server's existing policy in place.
     *
     * @return array<string, array{label:string, description:string, extensions:string, days:int}>
     */
    public static function all(): array
    {
        return [
            'stylesheets' => ['label' => 'Stylesheets', 'description' => 'CSS files.', 'extensions' => 'css', 'days' => 1],
            'javascript' => ['label' => 'JavaScript', 'description' => 'Scripts and WebAssembly.', 'extensions' => 'js|mjs|wasm', 'days' => 1],
            'images' => ['label' => 'Images', 'description' => 'Photos, graphics and icons.', 'extensions' => 'avif|bmp|gif|ico|jpe?g|png|svg|webp', 'days' => 7],
            'fonts' => ['label' => 'Fonts', 'description' => 'Web fonts.', 'extensions' => 'eot|otf|ttf|woff2?', 'days' => 30],
            'media' => ['label' => 'Audio and video', 'description' => 'Playable media files.', 'extensions' => 'm4a|mov|mp3|mp4|ogg|wav|webm', 'days' => 0],
            'documents' => ['label' => 'Documents', 'description' => 'Downloads such as PDF and office files.', 'extensions' => 'csv|docx?|pdf|pptx?|txt|xlsx?', 'days' => 0],
        ];
    }

    /** @return array<string, int> */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::all() as $key => $group) {
            $defaults[$key] = $group['days'];
        }

        return $defaults;
    }

    /**
     * @param mixed $value
     * @return array<string, int>
     */
    public static function normalize($value): array
    {
        $raw = is_array($value) ? $value : [];
        $days = [];
        foreach (self::defaults() as $key => $default) {
            $days[$key] = max(0, min(365, (int) ($raw[$key] ?? $default)));
        }

        return $days;
    }
}
