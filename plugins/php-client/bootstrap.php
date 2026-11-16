<?php

declare(strict_types=1);

// Optional loader for applications that do not use Composer.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Namingo\\ResellerClient\\';
    if (!str_starts_with($class, $prefix)) { return; }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name) !== 1) { return; }
    $file = __DIR__ . '/src/' . $name . '.php';
    if (is_file($file)) { require_once $file; }
});
