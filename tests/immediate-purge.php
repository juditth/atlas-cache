<?php
declare(strict_types=1);

const ARRAY_A = 'ARRAY_A';
$enabled = true;
$options = [];
function get_option($name, $default = false) { return $name === 'atlas_cache_settings' ? ['enabled' => $GLOBALS['enabled']] : ($GLOBALS['options'][$name] ?? $default); }
function update_option($name, $value, $autoload = false) { $GLOBALS['options'][$name] = $value; return true; }
function sanitize_key($key) { return $key; }
function esc_url_raw($url) { return $url; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_mkdir_p($path) { return mkdir($path, 0777, true); }
function apply_filters($name, $value) { return $name === 'atlas_cache_sitemap_urls' ? [] : $value; }
function get_posts($args) { return [1]; }
function get_permalink($id) { return home_url('/page/'); }
function get_post_types(...$args) { return []; }
function get_taxonomies(...$args) { return []; }
function get_post_type_archive_link($type) { return false; }
function url_to_postid($url) { return 0; }
function untrailingslashit($path) { return rtrim($path, '/'); }

spl_autoload_register(static function ($class) {
    if (strpos($class, 'AtlasCache\\') === 0) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
    }
});
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
class wpdb {
    public string $prefix = 'wp_';
    public array $jobs = [];
    public bool $fail = false;
    public $beforeInsert;
    public function prepare($query, ...$args) { return $query; }
    public function get_row(...$args) { return null; }
    public function insert($table, $data, $formats) {
        ($this->beforeInsert)();
        if ($this->fail) { return false; }
        $this->jobs[] = $data;
        return 1;
    }
}

$root = sys_get_temp_dir() . '/atlas-immediate-' . bin2hex(random_bytes(6));
$paths = new AtlasCache\Storage\CachePaths($root);
$storage = new AtlasCache\Storage\FileCacheStorage($paths);
$keys = new AtlasCache\Cache\CacheKeyGenerator(new AtlasCache\Storage\PathSanitizer());
$settings = new AtlasCache\Config\SettingsRepository();
$db = new wpdb();
$admin = (new ReflectionClass(AtlasCache\Admin\AdminMenu::class))->newInstanceWithoutConstructor();
foreach ([
    'storage' => $storage, 'settings' => $settings, 'cacheKeyGenerator' => $keys,
    'queue' => new AtlasCache\Queue\QueueRepository($db),
    'logger' => new AtlasCache\Debug\Logger($paths),
    'sitemapUrlCollector' => new AtlasCache\WordPress\SitemapUrlCollector(),
    'priorityResolver' => new AtlasCache\WordPress\CacheWarmupPriorityResolver($settings),
] as $property => $value) { (new ReflectionProperty($admin, $property))->setValue($admin, $value); }
$page = $keys->generate('example.test', '/page/');
$child = $keys->generate('example.test', '/page/child/');
$home = $keys->generate('example.test', '/');
$purge = new ReflectionMethod($admin, 'purgePage');
$tool = new ReflectionMethod($admin, 'runTool');
try {
    foreach ([$page, $child, $home] as $key) { $storage->write($key, 'old', []); }
    $db->beforeInsert = static function () use ($paths, $page, $child) {
        check(!is_file($paths->htmlFile($page)), 'Page must be deleted BEFORE enqueueing.');
        check(is_file($paths->htmlFile($child)), 'Page purge must preserve child URLs.');
    };
    $purge->invoke($admin, home_url('/page/'));
    check(count($db->jobs) === 1 && $db->jobs[0]['mode'] === 'revalidate', 'Purge must queue a rebuild, not deferred deletion.');
    $storage->write($page, 'old', []);
    $db->fail = true;
    try { $purge->invoke($admin, home_url('/page/')); throw new LogicException('Missing queue failure.'); }
    catch (RuntimeException $error) { check(strpos($error->getMessage(), 'could not be queued') !== false, 'Queue failure must be visible.'); }
    $db->fail = false;
    // A deletion error must stop before queue insertion.
    mkdir($paths->htmlFile($page));
    $count = count($db->jobs);
    try { $purge->invoke($admin, home_url('/page/')); throw new LogicException('Missing deletion failure.'); }
    catch (RuntimeException $error) { check(count($db->jobs) === $count, 'Failed deletion must not enqueue.'); }
    rmdir($paths->htmlFile($page));
    $db->jobs = [];
    $db->beforeInsert = static function () use ($storage) { check($storage->stats()['files'] === 0, 'Site must be cleared BEFORE enqueueing.'); };
    $tool->invoke($admin, 'purge-all');
    check(count($db->jobs) === 2, 'Site purge must queue homepage and collected page.');
    check(array_unique(array_column($db->jobs, 'mode')) === ['revalidate'], 'Site rebuild jobs must revalidate.');
    $storage->write($page, 'old', []);
    $db->beforeInsert = static function () use ($paths, $page) { check(file_get_contents($paths->htmlFile($page)) === 'old', 'Revalidate must keep old HTML.'); };
    $tool->invoke($admin, 'queue-revalidate-all');
    check(count($db->jobs) === 4, 'Revalidate must enqueue URLs.');
    $enabled = false;
    $tool->invoke($admin, 'purge-all');
    check($storage->stats()['files'] === 0 && count($db->jobs) === 4, 'Disabled cache must be purged without rebuild.');
    echo "Immediate purge and revalidation regression tests passed.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
