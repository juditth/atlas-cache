<?php

declare(strict_types=1);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atlas-wp-config-' . bin2hex(random_bytes(6));
mkdir($testRoot, 0777, true);
define('ABSPATH', $testRoot . DIRECTORY_SEPARATOR);

function update_option(string $option, $value, bool $autoload = false): bool
{
    return true;
}

require dirname(__DIR__) . '/src/WordPress/WpConfigEditor.php';

function atlas_wp_config_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = ABSPATH . 'wp-config.php';
$original = "<?php\ndefine('WP_CACHE', false);\nrequire_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($path, $original);

$editor = new AtlasCache\WordPress\WpConfigEditor();
$editor->enableCache();
$enabled = (string) file_get_contents($path);
atlas_wp_config_test_assert(strpos($enabled, 'BEGIN Atlas Cache WP_CACHE') !== false, 'Enable must add the Atlas marker.');
atlas_wp_config_test_assert(strpos($enabled, "define('WP_CACHE', true);") !== false, 'Enable must write WP_CACHE=true.');

$editor->disableCache();
$disabled = (string) file_get_contents($path);
atlas_wp_config_test_assert(strpos($disabled, 'Atlas Cache WP_CACHE') === false, 'Disable must remove the Atlas marker.');
atlas_wp_config_test_assert(strpos($disabled, "define('WP_CACHE', false);") !== false, 'Disable must restore the original WP_CACHE line.');

file_put_contents($path, $original);
$editor->enableCache();
$editor->disableCacheExplicitly();
atlas_wp_config_test_assert(strpos((string) file_get_contents($path), "define('WP_CACHE', false);") !== false, 'Explicit disable must turn off an Atlas-managed definition.');

file_put_contents($path, "<?php\ndefine('WP_CACHE', true);\nrequire_once ABSPATH . 'wp-settings.php';\n");
$editor->disableCacheExplicitly();
atlas_wp_config_test_assert(strpos((string) file_get_contents($path), "define('WP_CACHE', false);") !== false, 'Explicit disable must turn off a simple external WP_CACHE definition.');

file_put_contents($path, "<?php\ndefine('WP_CACHE', true);\ndefine('WP_CACHE', true);\nrequire_once ABSPATH . 'wp-settings.php';\n");
try {
    $editor->disableCacheExplicitly();
    throw new RuntimeException('Multiple WP_CACHE definitions must be rejected.');
} catch (RuntimeException $exception) {
    atlas_wp_config_test_assert(strpos($exception->getMessage(), 'multiple WP_CACHE definitions') !== false, 'Multiple definitions must be rejected without editing the file.');
}

unlink($path);
rmdir($testRoot);

echo "WP_CACHE lifecycle regression test passed.\n";
