<?php

declare(strict_types=1);

// Exercise the actual WordPress menu API without loading a site or database.
function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && $GLOBALS['canManageOptions'];
}

function plugin_basename(string $file): string { return $file; }
function sanitize_title(string $title): string { return strtolower(str_replace(' ', '-', $title)); }
function set_url_scheme(string $url): string { return $url; }
function add_action(string $hook, $callback): void { $GLOBALS['callbacks'][$hook] = $callback; }

require dirname(__DIR__, 4) . '/wp-admin/includes/plugin.php';
require dirname(__DIR__) . '/src/Admin/AdminMenu.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Menu registration does not use the services required by the constructor.
$admin = (new ReflectionClass(AtlasCache\Admin\AdminMenu::class))->newInstanceWithoutConstructor();
foreach ([false, true] as $canManageOptions) {
    $menu = $submenu = $admin_page_hooks = $_registered_pages = $_parent_pages = [];
    $_wp_real_parent_file = $_wp_submenu_nopriv = $callbacks = [];
    $pagenow = 'post-new.php';
    $parent_file = '';

    $admin->register();

    if (!$canManageOptions) {
        if (isset($submenu['atlas-cache-tools']) || $callbacks !== []) {
            throw new RuntimeException('Users without manage_options must not receive cache pages or callbacks.');
        }
        if (count($_wp_submenu_nopriv['atlas-cache-tools'] ?? []) !== 6) {
            throw new RuntimeException('WordPress must retain its access-denied registrations.');
        }
        echo "PASS: no cache submenu or crash without manage_options.\n";
        continue;
    }

    $expected = ['atlas-cache-settings', 'atlas-cache-rules', 'atlas-cache-queue', 'atlas-cache-log', 'atlas-cache-tools', 'atlas-cache-diagnostics'];
    if (array_column($submenu['atlas-cache-tools'] ?? [], 2) !== $expected) {
        throw new RuntimeException('Administrator submenu order changed.');
    }
    if (count($callbacks) !== 6) {
        throw new RuntimeException('Administrator page callbacks are missing.');
    }
    echo "PASS: administrator retains all six pages in the expected order.\n";
}

restore_error_handler();
