<?php

declare(strict_types=1);

namespace App;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verifyCsrf(): void
    {
        $provided = (string) ($_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $provided)) {
            throw new HttpException(419, 'Sessie verlopen. Vernieuw de pagina en probeer opnieuw.');
        }
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = $user;
    }

    public static function isAuthenticated(): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
    }
}

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

