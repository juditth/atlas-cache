<?php
/**
 * Plugin Name: Atlas Cache
 * Description: Jednoduchá a bezpečná HTML page cache přes advanced-cache.php drop-in.
 * Version: 0.1.4
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: atlas-cache
 * Author: Atlas Cache
 * Text Domain: atlas-cache
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('ATLAS_CACHE_VERSION', '0.1.4');
define('ATLAS_CACHE_FILE', __FILE__);
define('ATLAS_CACHE_DIR', plugin_dir_path(__FILE__));
define('ATLAS_CACHE_URL', plugin_dir_url(__FILE__));

if (!defined('ATLAS_CACHE_UPDATE_INFO_URL')) {
    define('ATLAS_CACHE_UPDATE_INFO_URL', 'https://vyladeny-web.cz/plugins/atlas-cache/info.json');
}

require_once ATLAS_CACHE_DIR . 'src/Support/Autoloader.php';

AtlasCache\Support\Autoloader::register('AtlasCache\\', ATLAS_CACHE_DIR . 'src');

register_activation_hook(__FILE__, static function (): void {
    AtlasCache\WordPress\Activator::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    AtlasCache\WordPress\Activator::deactivate();
});

add_action('plugins_loaded', static function (): void {
    $plugin = AtlasCache\WordPress\PluginFactory::create();
    $plugin->register();
});
