<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Config/BrowserCacheGroups.php';
require dirname(__DIR__) . '/src/WordPress/HtaccessBrowserCacheRules.php';

use AtlasCache\Config\BrowserCacheGroups;
use AtlasCache\WordPress\HtaccessBrowserCacheRules;

$path = tempnam(sys_get_temp_dir(), 'atlas-htaccess-');
if (!is_string($path)) {
    throw new RuntimeException('Could not create test file.');
}

try {
    file_put_contents($path, "# Existing rule\n");
    $rules = new HtaccessBrowserCacheRules($path);
    $rules->install(BrowserCacheGroups::defaults());
    $contents = (string) file_get_contents($path);

    foreach ([86400, 604800, 2592000] as $seconds) {
        if (strpos($contents, 'max-age=' . $seconds) === false) {
            throw new RuntimeException('Expected duration missing: ' . $seconds);
        }
    }
    if (strpos($contents, 'immutable') !== false || strpos($contents, 'mp4') !== false || strpos($contents, 'pdf') !== false) {
        throw new RuntimeException('Unmanaged types or immutable directive were written.');
    }

    $custom = BrowserCacheGroups::defaults();
    $custom['stylesheets'] = 0;
    $custom['documents'] = 2;
    $rules->install($custom);
    $contents = (string) file_get_contents($path);
    if (substr_count($contents, '# BEGIN Atlas Cache Browser Cache') !== 1
        || strpos($contents, '# Existing rule') === false
        || strpos($contents, 'FilesMatch "\\.(css)$"') !== false
        || strpos($contents, 'max-age=172800') === false) {
        throw new RuntimeException('Reinstall did not replace only the Atlas block.');
    }

    $rules->install($custom, true);
    $contents = (string) file_get_contents($path);
    if (!$rules->hasGzipFallback() || strpos($contents, 'AddOutputFilterByType DEFLATE text/html') === false
        || strpos($contents, '<IfModule mod_filter.c>') === false) {
        throw new RuntimeException('Gzip fallback was not installed safely.');
    }
    $rules->install($custom, false);
    if ($rules->hasGzipFallback()) {
        throw new RuntimeException('Gzip fallback was not removed.');
    }

    $rules->uninstall();
    $contents = (string) file_get_contents($path);
    if (strpos($contents, 'Atlas Cache Browser Cache') !== false || strpos($contents, '# Existing rule') === false) {
        throw new RuntimeException('Uninstall did not preserve existing rules.');
    }
} finally {
    unlink($path);
}

echo "Browser cache rules regression test passed.\n";
