<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4));
    $file = __DIR__ . '/../app/' . $relative . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/../app/Support/helpers.php';
