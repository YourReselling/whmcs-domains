<?php

declare(strict_types=1);

/** Load only this module's private namespace from this module directory. */
spl_autoload_register(static function (string $class): void {
    $prefix = 'YourReselling\\Whmcs\\Domains\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if (! preg_match('/^[a-zA-Z0-9_\\\\]+$/', $relative)) {
        return;
    }
    $file = __DIR__.'/lib/'.str_replace('\\', '/', $relative).'.php';
    if (is_file($file)) {
        require_once $file;
    }
});
