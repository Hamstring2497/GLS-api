<?php

declare(strict_types=1);

use App\Env;
use App\Security;

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . substr($class, strlen($prefix)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require $root . '/src/helpers.php';
Env::load($root . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Europe/Amsterdam'));
Security::startSession();

return $root;

