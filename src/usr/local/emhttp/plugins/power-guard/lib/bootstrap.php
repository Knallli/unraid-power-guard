<?php
declare(strict_types=1);

namespace PowerGuard;

const PLUGIN = 'power-guard';
const PLUGIN_DIR = '/usr/local/emhttp/plugins/power-guard';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, __NAMESPACE__ . '\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen(__NAMESPACE__) + 1));
    $file = __DIR__ . '/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Test hook: run everything against a fixture tree instead of the live system (CLI only).
if (PHP_SAPI === 'cli' && ($fakeRoot = getenv('POWER_GUARD_ROOT')) && is_dir($fakeRoot)) {
    Sys::$root = rtrim($fakeRoot, '/');
}
