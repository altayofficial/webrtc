<?php

/**
 * Loads every class the package ships.
 *
 * Linting catches parse errors, but a bad downgrade can also produce code that only fails when the
 * engine binds it - an inherited signature that no longer matches, or a constant expression using
 * syntax the target version cannot evaluate. Loading each class surfaces those.
 *
 * Usage: php tools/smoke.php
 */

declare(strict_types=1);

require __DIR__ . "/../vendor/autoload.php";

$manifest = json_decode(file_get_contents(__DIR__ . "/../composer.json"), true, flags: JSON_THROW_ON_ERROR);

$loaded = 0;
$failed = [];

foreach ($manifest["autoload"]["psr-4"] as $prefix => $directory) {
    $root = __DIR__ . "/../" . rtrim($directory, "/");
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== "php") {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $class = $prefix . str_replace("/", "\\", substr($relative, 0, -4));

        try {
            if (!class_exists($class) && !interface_exists($class) && !trait_exists($class) && !enum_exists($class)) {
                // Not every file declares a type named after itself; the build classmaps those.
                continue;
            }
            $loaded++;
        } catch (\Throwable $e) {
            $failed[$class] = $e->getMessage();
        }
    }
}

foreach ($failed as $class => $message) {
    fwrite(STDERR, "$class: $message\n");
}

printf("loaded %d types, %d failed\n", $loaded, count($failed));
exit($failed === [] ? 0 : 1);
