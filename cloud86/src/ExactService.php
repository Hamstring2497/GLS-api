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

    /**
     * Haal de nieuwste goederenleveringen op en zet alleen complete, nog onbekende leveringen lokaal klaar.
     * Er wordt in deze stap nadrukkelijk niets naar GLS gestuurd.
     *
     * @return array{imported: int, skipped: int, errors: list<string>}
     */
    public function syncGoodsDeliveries(DeliveryRepository $deliveries): array
    {
        $status = $this->status();
        if (!$status['connected'] || $status['division'] === null) {
            throw new ExactException('Exact Online is nog niet gekoppeld.');
        }

        $limit = max(1, min(100, (int) Env::get('EXACT_SYNC_LIMIT', '50')));
        $path = sprintf('/api/v1/%d/salesorder/GoodsDeliveries', $status['division']);
        $select = implode(',', [
            'EntryID',
            'EntryNumber',
            'DeliveryNumber',
            'DeliveryDate',
            'DeliveryAccount',
            'DeliveryAccountName',
            'DeliveryAddress',
            'DeliveryContactPersonFullName',
            'TrackingNumber',
        ]);
        try {
            $payload = $this->apiGet(
                $path,
                [
                    '$select' => $select,
                    '$top' => (string) $limit,
                    '$orderby' => 'DeliveryDate desc',
                ]
            );
        } catch (ExactException $error) {
            // Niet iedere Exact-administratie accepteert DeliveryDate als OData-sorteerveld.
            // Probeer bij een ongeldige query één keer zonder server-side sortering.
            if ($error->httpStatus !== 400) {
                throw $error;
            }
            $payload = $this->apiGet($path, [
                '$select' => $select,
                '$top' => (string) $limit,
            ]);
        }
        $rows = $this->results($payload);
        usort($rows, static function (array $left, array $right): int {
            $leftDate = strtotime((string) ($left['DeliveryDate'] ?? $left['EntryDate'] ?? '')) ?: 0;
            $rightDate = strtotime((string) ($right['DeliveryDate'] ?? $right['EntryDate'] ?? '')) ?: 0;
            return $rightDate <=> $leftDate;
        });
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $entryId = trim((string) ($row['EntryID'] ?? $row['ID'] ?? ''));
            if ($entryId === '') {
                $errors[] = 'Exact-levering zonder EntryID overgeslagen.';
                continue;
            }
            if ($deliveries->find($entryId) !== null) {
                $skipped++;
                continue;
            }
            if (trim((string) ($row['TrackingNumber'] ?? '')) !== '') {
                $skipped++;
                continue;
            }

            try {
                $address = $this->deliveryAddress($row, (int) $status['division']);
                [$street, $houseNo] = $this->splitStreetAndHouseNumber(
                    (string) ($address['Street'] ?? $address['AddressLine1'] ?? ''),
                    (string) ($address['HouseNumber'] ?? ''),
                    (string) ($address['HouseNumberAddition'] ?? '')
                );
                $deliveryNumber = trim((string) ($row['DeliveryNumber'] ?? $row['EntryNumber'] ?? ''));
                if ($deliveryNumber === '') {
                    $deliveryNumber = substr($entryId, 0, 12);
                }
                $orderNumber = trim((string) ($row['OrderNumber'] ?? $row['YourRef'] ?? ''));
                if ($orderNumber === '') {
                    $orderNumber = $deliveryNumber;
                }
                $date = $this->exactDate($row['DeliveryDate'] ?? null);
                $name = trim((string) (
                    $row['DeliveryAccountName']
                    ?? $row['DeliverToName']
                    ?? $address['Name']
                    ?? $address['AccountName']
                    ?? ''
                ));

                $data = [
                    'exact_entry_id' => $entryId,
                    'delivery_number' => $deliveryNumber,
                    'order_number' => $orderNumber,
                    'shipping_date' => $date,
                    'name' => $name,
                    'street' => $street,
                    'house_no' => $houseNo,
                    'zip_code' => trim((string) ($address['Postcode'] ?? $address['ZipCode'] ?? '')),
                    'city' => trim((string) ($address['City'] ?? '')),
                    'country_code' => strtoupper(trim((string) ($address['Country'] ?? $address['CountryCode'] ?? 'NL'))),
                    'contact' => trim((string) (
                        $row['DeliveryContactPersonFullName']
                        ?? $row['DeliverToContactPersonFullName']
                        ?? $address['ContactName']
                        ?? ''
                    )),
                    'phone' => trim((string) ($address['Phone'] ?? $address['PhoneNumber'] ?? '')),
                    'email' => $this->deliveryEmail($address, $row, (int) $status['division']),
                ];
                $this->validateImportedDelivery($data, $deliveryNumber);
                $deliveries->save($data);
                $imported++;
            } catch (\Throwable $error) {
                $reference = (string) ($row['DeliveryNumber'] ?? substr($entryId, 0, 8));
                $errors[] = "Levering {$reference}: {$error->getMessage()}";
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
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

    /** @param array<string, string> $query @return array<string, mixed> */
    private function apiGet(string $path, array $query = []): array
    {
        $url = rtrim(Env::require('EXACT_BASE_URL'), '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $accessToken = $this->accessToken();
        $response = $this->sendGet($url, $accessToken);
        if ($response['status'] === 401) {
            $accessToken = $this->accessToken(true);
            $response = $this->sendGet($url, $accessToken);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ExactException(
                sprintf('Exact API gaf HTTP %d: %s', $response['status'], $this->apiError($response['body'])),
                $response['status']
            );
        }
        return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{status: int, body: string} */
    private function sendGet(string $url, string $accessToken): array
    {
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
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) {
            throw new ExactException('Exact API is niet bereikbaar: ' . $error);
        }
        return ['status' => $status, 'body' => $body];
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $statement = $this->db->query('SELECT * FROM exact_tokens WHERE id = 1');
        $row = $statement->fetch();
        if (!$row) {
            throw new ExactException('Exact Online is nog niet gekoppeld.');
        }
        if (!$forceRefresh && strtotime((string) $row['expires_at']) > time() + 30) {
            return Crypto::decrypt((string) $row['access_token']);
        }

        // Exact roteert refresh tokens. Sla daarom iedere nieuwe refresh token direct op.
        $tokens = $this->tokenRequest([
            'refresh_token' => Crypto::decrypt((string) $row['refresh_token']),
            'grant_type' => 'refresh_token',
            'client_id' => Env::require('EXACT_CLIENT_ID'),
            'client_secret' => Env::require('EXACT_CLIENT_SECRET'),
        ]);
        $this->saveTokens($tokens, (int) $row['division']);
        return (string) $tokens['access_token'];
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    private function deliveryAddress(array $delivery, int $division): array
    {
        $addressId = trim((string) ($delivery['DeliveryAddress'] ?? ''));
        if ($addressId !== '') {
            $payload = $this->apiGet(
                sprintf("/api/v1/%d/crm/Addresses(guid'%s')", $division, rawurlencode($addressId))
            );
            $entity = $payload['d'] ?? $payload;
            if (is_array($entity)) {
                return $entity;
            }
        }

        $accountId = trim((string) ($delivery['DeliveryAccount'] ?? $delivery['DeliverTo'] ?? ''));
        if ($accountId !== '') {
            $payload = $this->apiGet(
                sprintf('/api/v1/%d/crm/Addresses', $division),
                ['$top' => '1', '$filter' => "Account eq guid'{$accountId}' and Main eq true"]
            );
            $results = $this->results($payload);
            if ($results !== []) {
                return $results[0];
            }
        }
        throw new \RuntimeException('geen afleveradres gevonden in Exact');
    }

    /** @param array<string, mixed> $address @param array<string, mixed> $delivery */
    private function deliveryEmail(array $address, array $delivery, int $division): string
    {
        foreach (['Email', 'EmailAddress'] as $field) {
            $email = trim((string) ($address[$field] ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }
        $accountId = trim((string) ($delivery['DeliveryAccount'] ?? ''));
        if (preg_match('/^[a-f0-9-]{36}$/i', $accountId) !== 1) {
            return '';
        }
        try {
            $payload = $this->apiGet(sprintf(
                "/api/v1/%d/crm/Accounts(guid'%s')",
                $division,
                rawurlencode($accountId)
            ));
            $account = $payload['d'] ?? $payload;
            $email = is_array($account) ? trim((string) ($account['Email'] ?? '')) : '';
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
        } catch (ExactException $error) {
            error_log('Exact account-e-mail niet beschikbaar: HTTP ' . ($error->httpStatus ?? 'onbekend'));
            return '';
        }
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function results(array $payload): array
    {
        $results = $payload['d']['results'] ?? $payload['value'] ?? $payload['d'] ?? [];
        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /** @return array{0: string, 1: string} */
    private function splitStreetAndHouseNumber(string $line, string $number, string $addition): array
    {
        $line = trim($line);
        $number = trim($number . $addition);
        if ($number !== '') {
            return [$line, $number];
        }
        if (preg_match('/^(.+?)\s+(\d+[a-zA-Z0-9\-\/]*)$/u', $line, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }
        return [$line, ''];
    }

    private function exactDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = (string) $value;
        if (preg_match('/\/Date\((\d+)/', $text, $matches) === 1) {
            return date('Y-m-d', (int) floor(((int) $matches[1]) / 1000));
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /** @param array<string, string|null> $data */
    private function validateImportedDelivery(array $data, string $reference): void
    {
        $required = ['name', 'street', 'house_no', 'zip_code', 'city', 'country_code'];
        $missing = array_values(array_filter(
            $required,
            static fn (string $field): bool => trim((string) ($data[$field] ?? '')) === ''
        ));
        if ($missing !== []) {
            throw new \RuntimeException('onvolledig afleveradres (' . implode(', ', $missing) . ')');
        }
        if (strlen((string) $data['country_code']) !== 2) {
            throw new \RuntimeException("ongeldige landcode bij {$reference}");
        }
    }

    private function apiError(string $body): string
    {
        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            return (string) (
                $payload['error']['message']['value']
                ?? $payload['error']['message']
                ?? $payload['message']
                ?? 'onbekende fout'
            );
        } catch (\Throwable) {
            return substr(trim($body), 0, 300);
        }
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
            throw new ExactException("Exact tokenaanvraag gaf HTTP {$status}. {$error}", $status ?: null);
        }
        $tokens = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new ExactException('Exact gaf geen complete OAuth-tokens terug.');
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
            throw new ExactException("Exact current/Me gaf HTTP {$status}.", $status ?: null);
        }
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $division = $payload['d']['results'][0]['CurrentDivision']
            ?? $payload['d']['CurrentDivision']
            ?? $payload['value'][0]['CurrentDivision']
            ?? null;
        if (!is_numeric($division)) {
            throw new ExactException('Exact current/Me bevat geen CurrentDivision.');
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

