<?php

declare(strict_types=1);

$options = [];

function get_option(string $name, $default = false)
{
    global $options;
    return $options[$name] ?? $default;
}

function update_option(string $name, $value, bool $autoload = false): bool
{
    global $options;
    $options[$name] = $value;
    return true;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?? '';
}

require dirname(__DIR__) . '/src/Config/BrowserCacheGroups.php';
require dirname(__DIR__) . '/src/Config/SettingsRepository.php';

$settings = new AtlasCache\Config\SettingsRepository();
if ($settings->all()['site_revalidation_days'] !== 7) {
    throw new RuntimeException('Site revalidation should default to seven days.');
}
if ($settings->all()['excluded_post_types'] !== ['bricks_template']) {
    throw new RuntimeException('Bricks templates should be excluded by default.');
}

$settings->save(['excluded_post_types' => []]);
if ($settings->all()['excluded_post_types'] !== []) {
    throw new RuntimeException('Unchecking Exclude must remove the default exclusion.');
}

$settings->save(['excluded_post_types' => ['Bricks_Template', 'bricks_template', 'bad/slash']]);
if ($settings->all()['excluded_post_types'] !== ['bricks_template', 'badslash']) {
    throw new RuntimeException('Excluded post types must be normalized and unique.');
}

echo "Excluded post type settings regression test passed.\n";
