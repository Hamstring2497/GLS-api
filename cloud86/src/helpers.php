<?php

declare(strict_types=1);

use App\HttpException;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string, mixed> $data */
function view(string $name, array $data = []): never
{
    extract($data, EXTR_SKIP);
    $viewPath = dirname(__DIR__) . '/views/' . $name . '.php';
    if (!is_file($viewPath)) {
        throw new RuntimeException("View niet gevonden: {$name}");
    }
    ob_start();
    require $viewPath;
    $content = (string) ob_get_clean();
    require dirname(__DIR__) . '/views/layout.php';
    exit;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function abort(int $status, string $message): never
{
    throw new HttpException($status, $message);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

