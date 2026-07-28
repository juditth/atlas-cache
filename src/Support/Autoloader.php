<?php

declare(strict_types=1);

namespace AtlasCache\Support;

final class Autoloader
{
    public static function register(string $prefix, string $baseDir): void
    {
        $baseDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            $length = strlen($prefix);

            if (strncmp($prefix, $class, $length) !== 0) {
                return;
            }

            $relativeClass = substr($class, $length);
            $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            if (is_file($file)) {
                require $file;
            }
        });
    }
}
