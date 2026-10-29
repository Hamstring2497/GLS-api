<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ExactService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function authorizationUrl(): string
    {
        $state = bin2hex(random_bytes(32));
        $_SESSION['exact_oauth_state'] = $state;
        return rtrim(Env::require('EXACT_BASE_URL'), '/') . '/api/oauth2/auth?' . http_build_query([
            'client_id' => Env::require('EXACT_CLIENT_ID'),
            'redirect_uri' => Env::require('EXACT_REDIRECT_URI'),
            'response_type' => 'code',
            'force_login' => '0',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code, string $state): int
    {
        $expectedState = (string) ($_SESSION['exact_oauth_state'] ?? '');
        unset($_SESSION['exact_oauth_state']);
        if ($expectedState === '' || !hash_equals($expectedState, $state)) {
            throw new HttpException(400, 'De Exact OAuth-state is ongeldig of verlopen.');
        }
        if ($code === '') {
            throw new HttpException(400, 'Exact heeft geen autorisatiecode teruggestuurd.');
        }

        $tokens = $this->tokenRequest([
            'code' => $code,
            'redirect_uri' => Env::require('EXACT_REDIRECT_URI'),
            'grant_type' => 'authorization_code',
            'client_id' => Env::require('EXACT_CLIENT_ID'),
            'client_secret' => Env::require('EXACT_CLIENT_SECRET'),
        ]);
        $division = $this->fetchCurrentDivision((string) $tokens['access_token']);
        $this->saveTokens($tokens, $division);
        return $division;
    }

    /** @return array{connected: bool, division: ?int, expires_at: ?string} */
    public function status(): array
    {
        $row = $this->db->query('SELECT division, expires_at FROM exact_tokens WHERE id = 1')->fetch();
        return [
            'connected' => (bool) $row,
            'division' => $row ? (int) $row['division'] : null,
            'expires_at' => $row ? (string) $row['expires_at'] : null,
        ];
    }

    /** @param array<string, string> $fields @return array<string, mixed> */
    private function tokenRequest(array $fields): array
    {
        $url = rtrim(Env::require('EXACT_BASE_URL'), '/') . '/api/oauth2/token';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException("Exact tokenaanvraag gaf HTTP {$status}. {$error}");
        }
        $tokens = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new \RuntimeException('Exact gaf geen complete OAuth-tokens terug.');
        }
        return $tokens;
    }

    private function fetchCurrentDivision(string $accessToken): int
    {
        $url = rtrim(Env::require('EXACT_BASE_URL'), '/') . '/api/v1/current/Me?$select=CurrentDivision';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body === false || $status !== 200) {
            throw new \RuntimeException("Exact current/Me gaf HTTP {$status}.");
        }
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $division = $payload['d']['results'][0]['CurrentDivision']
            ?? $payload['d']['CurrentDivision']
            ?? $payload['value'][0]['CurrentDivision']
            ?? null;
        if (!is_numeric($division)) {
            throw new \RuntimeException('Exact current/Me bevat geen CurrentDivision.');
        }
        return (int) $division;
    }

    /** @param array<string, mixed> $tokens */
    private function saveTokens(array $tokens, int $division): void
    {
        $expiresIn = max(60, (int) ($tokens['expires_in'] ?? 600));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn - 30);
        $statement = $this->db->prepare(
            'INSERT INTO exact_tokens (id, access_token, refresh_token, expires_at, division) '
            . 'VALUES (1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), '
            . 'refresh_token = VALUES(refresh_token), expires_at = VALUES(expires_at), '
            . 'division = VALUES(division)'
        );
        $statement->execute([
            Crypto::encrypt((string) $tokens['access_token']),
            Crypto::encrypt((string) $tokens['refresh_token']),
            $expiresAt,
            $division,
        ]);
    }
}

