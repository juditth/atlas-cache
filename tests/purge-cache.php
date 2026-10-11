<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'AtlasCache\\';
    if (strpos($class, $prefix) === 0) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeFixture(string $directory): void
{
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) {
            removeFixture($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$fixture = sys_get_temp_dir() . '/atlas-purge-' . bin2hex(random_bytes(8));
mkdir($fixture);
$paths = new AtlasCache\Storage\CachePaths($fixture . '/cache');
$storage = new AtlasCache\Storage\FileCacheStorage($paths);
$generator = new AtlasCache\Cache\CacheKeyGenerator(new AtlasCache\Storage\PathSanitizer());
$home = $generator->generate('example.test', '/');
$parent = $generator->generate('example.test', '/parent/');
$child = $generator->generate('example.test', '/parent/child/');
$other = $generator->generate('other.test', '/');

try {
    $storage->purgeAll();
    $storage->purge($home);
    $storage->ensureBaseDirectories();
    file_put_contents($paths->configFile(), 'config');
    file_put_contents($paths->logsRoot() . '/test.log', 'log');
    file_put_contents($paths->globalStaleFile(), 'state');
    foreach ([$home, $parent, $child, $other] as $key) {
        $storage->write($key, '<html>cached</html>', ['url' => $key->debugKey()]);
    }

    $storage->purge($parent);
    check(!file_exists($paths->htmlFile($parent)), 'Purge must remove HTML.');
    check(!file_exists($paths->metaFile($parent)), 'Purge must remove metadata.');
    check(is_file($paths->htmlFile($child)), 'Purge must preserve child URLs.');
    $storage->purge($home);
    check(is_file($paths->htmlFile($child)), 'Homepage purge must preserve other URLs.');
    check(is_file($paths->htmlFile($other)), 'Purge must preserve other hosts.');
    $storage->purge($home);

    // An undeletable file target must never be reported as a successful purge.
    mkdir($paths->htmlFile($parent));
    $failed = false;
    try {
        $storage->purge($parent);
    } catch (RuntimeException $exception) {
        $failed = true;
    }
    check($failed, 'Purge must report a deletion failure.');
    rmdir($paths->htmlFile($parent));

    mkdir($fixture . '/outside');
    file_put_contents($fixture . '/outside/keep.txt', 'keep');
    $link = $paths->pagesRoot() . '/linked';
    $linked = @symlink($fixture . '/outside', $link);
    if (!$linked) {
        echo "SKIP: symbolic links are unavailable on this host.\n";
    }
    $storage->purgeAll();
    check($storage->stats() === ['files' => 0, 'size' => 0], 'Purge all must empty pages.');
    check(file_get_contents($fixture . '/outside/keep.txt') === 'keep', 'Purge must not follow links outside storage.');
    check(is_file($paths->configFile()), 'Purge all must preserve config.');
    check(is_file($paths->logsRoot() . '/test.log'), 'Purge all must preserve logs.');
    check(is_file($paths->globalStaleFile()), 'Purge all must preserve state.');
    $storage->purgeAll();

    if ($linked) {
        rmdir($paths->pagesRoot());
        check(symlink($fixture . '/outside', $paths->pagesRoot()), 'Create linked pages root.');
        $failed = false;
        try {
            $storage->purgeAll();
        } catch (RuntimeException $exception) {
            $failed = true;
        }
        check($failed, 'Purge must reject a linked pages root.');
        check(is_file($fixture . '/outside/keep.txt'), 'Linked root must preserve outside files.');
    }
    echo "Cache purge regression tests passed.\n";
} finally {
    removeFixture($fixture);
}
