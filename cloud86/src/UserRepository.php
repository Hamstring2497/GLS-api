<?php

declare(strict_types=1);

namespace App;

use PDO;

final class UserRepository
{
    private const MAX_FAILED_LOGINS = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{id: int, username: string, display_name: string, role: string}|null */
    public function authenticate(string $username, string $password): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM users WHERE username = ? AND active = 1 LIMIT 1');
        $statement->execute([trim($username)]);
        $user = $statement->fetch();

        // Houd de verwerking voor onbekende gebruikers zo veel mogelijk gelijk.
        $dummyHash = '$2y$12$wP4qWm0E9QG7nV6JgXVDKu4qn9hKz5NsJn6mP0VCz0NoGYG8d5d1S';
        $valid = password_verify($password, $user['password_hash'] ?? $dummyHash);
        if (!$user) {
            return null;
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            return null;
        }

        if (!$valid) {
            $failed = (int) $user['failed_login_count'] + 1;
            $lockedUntil = $failed >= self::MAX_FAILED_LOGINS
                ? date('Y-m-d H:i:s', strtotime('+' . self::LOCK_MINUTES . ' minutes'))
                : null;
            $update = $this->db->prepare(
                'UPDATE users SET failed_login_count = ?, locked_until = ? WHERE id = ?'
            );
            $update->execute([$failed, $lockedUntil, $user['id']]);
            return null;
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        $update = $this->db->prepare(
            'UPDATE users SET failed_login_count = 0, locked_until = NULL, last_login_at = CURRENT_TIMESTAMP WHERE id = ?'
        );
        $update->execute([$user['id']]);

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => (string) $user['display_name'],
            'role' => (string) $user['role'],
        ];
    }

    public function create(
        string $username,
        string $displayName,
        string $password,
        string $role = 'warehouse'
    ): int {
        self::assertStrongPassword($password);
        if (!preg_match('/\A[a-zA-Z0-9._-]{3,80}\z/', $username)) {
            throw new \InvalidArgumentException(
                'Gebruikersnaam: 3-80 tekens en alleen letters, cijfers, punt, streepje of underscore.'
            );
        }
        if (!in_array($role, ['admin', 'warehouse'], true)) {
            throw new \InvalidArgumentException('Rol moet admin of warehouse zijn.');
        }
        $statement = $this->db->prepare(
            'INSERT INTO users (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([
            $username,
            trim($displayName) !== '' ? trim($displayName) : $username,
            password_hash($password, PASSWORD_DEFAULT),
            $role,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public static function assertStrongPassword(string $password): void
    {
        $rules = [
            strlen($password) >= 8,
            strlen($password) <= 10,
            preg_match('/[a-z]/', $password) === 1,
            preg_match('/[A-Z]/', $password) === 1,
            preg_match('/[0-9]/', $password) === 1,
            preg_match('/[^a-zA-Z0-9]/', $password) === 1,
        ];
        if (in_array(false, $rules, true)) {
            throw new \InvalidArgumentException(
                'Wachtwoord moet 8-10 tekens met kleine letter, hoofdletter, cijfer en leesteken bevatten.'
            );
        }
    }
}

