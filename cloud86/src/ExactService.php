<?php

declare(strict_types=1);

namespace App;

use PDO;

final class ExactService
{
    public const DELIVERY_SYNC_START_DATE = '2026-11-01';

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

        $pageSize = max(1, min(100, (int) Env::get('EXACT_SYNC_LIMIT', '50')));
        $today = date('Y-m-d');
        if ($today < self::DELIVERY_SYNC_START_DATE) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => []];
        }
        $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
        $path = sprintf('/api/v1/%d/salesorder/GoodsDeliveries', $status['division']);
        $select = implode(',', [
            'EntryID',
            'EntryNumber',
            'DeliveryNumber',
            'Created',
            'DeliveryDate',
            'DeliveryAccount',
            'DeliveryAccountName',
            'DeliveryAddress',
            'DeliveryContact',
            'DeliveryContactPersonFullName',
            'TrackingNumber',
        ]);
        $baseQuery = [
            '$select' => $select,
            '$top' => (string) $pageSize,
            '$filter' => "Created ge datetime'" . self::DELIVERY_SYNC_START_DATE
                . "T00:00:00' and Created lt datetime'{$tomorrow}T00:00:00'",
        ];
        try {
            $rows = $this->apiGetAll(
                $path,
                $baseQuery + ['$orderby' => 'Created desc,DeliveryNumber desc']
            );
        } catch (ExactException $error) {
            // Sommige Exact-omgevingen accepteren geen samengestelde OData-sortering.
            // De Created-filter blijft altijd staan: nieuwe pakbonnen van vandaag en openstaande
            // pakbonnen vanaf de vaste startdatum horen in de magazijnwerkvoorraad.
            if ($error->httpStatus !== 400) {
                throw $error;
            }
            $rows = $this->apiGetAll($path, $baseQuery + ['$orderby' => 'Created desc']);
        }
        usort($rows, static function (array $left, array $right): int {
            $leftCreated = strtotime((string) ($left['Created'] ?? '')) ?: 0;
            $rightCreated = strtotime((string) ($right['Created'] ?? '')) ?: 0;
            return ($rightCreated <=> $leftCreated)
                ?: strnatcasecmp((string) ($right['DeliveryNumber'] ?? ''), (string) ($left['DeliveryNumber'] ?? ''));
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
            $createdDate = $this->exactDate($row['Created'] ?? null);
            if ($createdDate === null) {
                $errors[] = 'Exact-levering zonder geldige aanmaakdatum overgeslagen.';
                continue;
            }
            if ($createdDate < self::DELIVERY_SYNC_START_DATE || $createdDate > $today) {
                $skipped++;
                continue;
            }
            $date = $this->exactDate($row['DeliveryDate'] ?? null);
            if ($date === null) {
                $errors[] = 'Exact-levering zonder geldige leverdatum overgeslagen.';
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
                $orderContext = $this->salesOrderContext($entryId, (int) $status['division']);
                $orderedByAddress = $orderContext['address'];
                $deliveryContact = [];
                if ($orderContext['contact'] === []) {
                    $deliveryContact = $this->contactDetails(
                        (string) ($row['DeliveryContact'] ?? ''),
                        (int) $status['division']
                    );
                }
                [$street, $houseNo, $houseNoAddition] = $this->splitStreetAndHouseNumber(
                    (string) ($address['Street'] ?? $address['AddressLine1'] ?? ''),
                    (string) ($address['HouseNumber'] ?? ''),
                    (string) ($address['HouseNumberAddition'] ?? '')
                );
                $deliveryNumber = trim((string) ($row['DeliveryNumber'] ?? $row['EntryNumber'] ?? ''));
                if ($deliveryNumber === '') {
                    $deliveryNumber = substr($entryId, 0, 12);
                }
                $orderNumber = $this->firstNonEmpty([
                    $orderContext['order_number'],
                    $row['OrderNumber'] ?? null,
                    $row['YourRef'] ?? null,
                ]);
                if ($orderNumber === '') {
                    $orderNumber = $deliveryNumber;
                }
                $name = trim((string) (
                    $row['DeliveryAccountName']
                    ?? $row['DeliverToName']
                    ?? $address['Name']
                    ?? $address['AccountName']
                    ?? ''
                ));
                $email = $this->firstValidEmail([
                    $orderContext['contact']['Email'] ?? null,
                    $deliveryContact['Email'] ?? null,
                    $address['Email'] ?? null,
                    $address['EmailAddress'] ?? null,
                ]);
                if ($email === '') {
                    $email = $this->deliveryEmail($address, $row, (int) $status['division']);
                }

                $data = [
                    'exact_entry_id' => $entryId,
                    'delivery_number' => $deliveryNumber,
                    'order_number' => $orderNumber,
                    'shipping_date' => $date,
                    'name' => $name,
                    'name_2' => $this->firstNonEmpty([
                        $address['AddressLine2'] ?? null,
                        $address['Name2'] ?? null,
                        $orderedByAddress['AddressLine2'] ?? null,
                        $orderedByAddress['Name2'] ?? null,
                    ]),
                    'name_3' => $this->firstNonEmpty([
                        $address['AddressLine3'] ?? null,
                        $address['Name3'] ?? null,
                        $orderedByAddress['AddressLine3'] ?? null,
                        $orderedByAddress['Name3'] ?? null,
                    ]),
                    'street' => $street,
                    'house_no' => $houseNo,
                    'house_no_addition' => $houseNoAddition,
                    'zip_code' => trim((string) ($address['Postcode'] ?? $address['ZipCode'] ?? '')),
                    'city' => trim((string) ($address['City'] ?? '')),
                    'country_code' => strtoupper(trim((string) ($address['Country'] ?? $address['CountryCode'] ?? 'NL'))),
                    'contact' => $this->firstNonEmpty([
                        $this->contactDisplayName($orderContext['contact']),
                        $orderContext['contact_name'],
                        $row['DeliveryContactPersonFullName'] ?? null,
                        $this->contactDisplayName($deliveryContact),
                        $row['DeliverToContactPersonFullName'] ?? null,
                        $address['ContactName'] ?? null,
                    ]),
                    'phone' => $this->firstNonEmpty([
                        $orderContext['contact']['Phone'] ?? null,
                        $orderContext['contact']['PhoneMobile'] ?? null,
                        $deliveryContact['Phone'] ?? null,
                        $deliveryContact['PhoneMobile'] ?? null,
                        $address['Phone'] ?? null,
                        $address['PhoneNumber'] ?? null,
                    ]),
                    'email' => $email,
                    'note_1' => '',
                    'note_2' => '',
                ];
                // Ook een onvolledig Exact-adres hoort zichtbaar in de werkvoorraad te komen.
                // Het overzicht markeert ontbrekende velden en blokkeert het GLS-label totdat de
                // magazijnmedewerker ze heeft aangevuld. Een regel volledig overslaan maakt het
                // anders onduidelijk waarom een afgedrukte pakbon niet in ParcelPilot staat.
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

    /**
     * Schrijf de GLS-track-en-tracecode terug naar de Exact-goederenlevering.
     *
     * Bij een samengevoegde zending wordt iedere onderliggende Exact-levering apart bijgewerkt.
     * Reeds bevestigde leveringen worden overgeslagen, zodat een veilige herhaalactie mogelijk is
     * zonder opnieuw een GLS-zending aan te maken.
     *
     * @param array<string, mixed> $delivery
     * @return array{synced: int, skipped: int, errors: list<string>}
     */
    public function syncDeliveryTracking(array $delivery, DeliveryRepository $deliveries): array
    {
        if (self::isMockTracking($delivery)) {
            throw new ExactException('Een ongeldig GLS-testtrackingnummer mag niet naar Exact worden geschreven.');
        }

        $status = $this->status();
        if (!$status['connected'] || $status['division'] === null) {
            throw new ExactException('Exact Online is nog niet gekoppeld.');
        }

        $trackingNumber = self::formatTrackingNumber((array) ($delivery['tracking_numbers'] ?? []));
        if ($trackingNumber === '') {
            throw new ExactException('Er is geen GLS-trackingnummer om naar Exact te schrijven.');
        }
        if (mb_strlen($trackingNumber) > 100) {
            throw new ExactException('De gezamenlijke GLS-trackingnummers zijn langer dan de 100 tekens die Exact toestaat.');
        }

        $members = array_merge([$delivery], (array) ($delivery['merged_deliveries'] ?? []));
        $synced = 0;
        $skipped = 0;
        $errors = [];

        foreach ($members as $member) {
            $entryId = trim((string) ($member['exact_entry_id'] ?? ''));
            $deliveryNumber = trim((string) ($member['delivery_number'] ?? $entryId));
            if (!empty($member['exact_tracking_synced_at'])) {
                $skipped++;
                continue;
            }
            if (preg_match('/^[a-f0-9-]{36}$/i', $entryId) !== 1) {
                $errors[] = "Levering {$deliveryNumber}: geen geldig Exact EntryID.";
                continue;
            }

            try {
                $this->apiPut(
                    sprintf(
                        "/api/v1/%d/salesorder/GoodsDeliveries(guid'%s')",
                        $status['division'],
                        rawurlencode($entryId)
                    ),
                    ['TrackingNumber' => $trackingNumber]
                );
                $deliveries->markExactTrackingSynced($entryId);
                $synced++;
            } catch (\Throwable $error) {
                error_log("Exact tracking terugschrijven voor {$deliveryNumber}: " . $error->getMessage());
                $errors[] = "Levering {$deliveryNumber}: " . $error->getMessage();
            }
        }

        return ['synced' => $synced, 'skipped' => $skipped, 'errors' => $errors];
    }

    /** @param list<mixed> $numbers */
    public static function formatTrackingNumber(array $numbers): string
    {
        $numbers = array_values(array_unique(array_filter(array_map(
            static fn (mixed $number): string => trim((string) $number),
            $numbers
        ))));
        return implode(', ', $numbers);
    }

    /** @param array<string, mixed> $delivery */
    public static function isMockTracking(array $delivery): bool
    {
        $response = $delivery['gls_response_json'] ?? null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
            if (is_array($decoded) && (($decoded['mock'] ?? false) === true || ($decoded['test'] ?? false) === true)) {
                return true;
            }
        } elseif (is_array($response) && (($response['mock'] ?? false) === true || ($response['test'] ?? false) === true)) {
            return true;
        }

        foreach ((array) ($delivery['tracking_numbers'] ?? []) as $trackingNumber) {
            if (str_starts_with(strtoupper(trim((string) $trackingNumber)), 'MOCK-')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, string> $query @return array<string, mixed> */
    private function apiGet(string $path, array $query = []): array
    {
        $baseUrl = rtrim(Env::require('EXACT_BASE_URL'), '/');
        $url = str_starts_with($path, $baseUrl . '/') ? $path : $baseUrl . $path;
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

    /** @param array<string, string> $query @return list<array<string, mixed>> */
    private function apiGetAll(string $path, array $query): array
    {
        $rows = [];
        $next = $path;
        $nextQuery = $query;
        $baseUrl = rtrim(Env::require('EXACT_BASE_URL'), '/');

        for ($page = 0; $page < 100 && $next !== ''; $page++) {
            $payload = $this->apiGet($next, $nextQuery);
            array_push($rows, ...$this->results($payload));
            $nextQuery = [];
            $nextLink = trim((string) (
                $payload['d']['__next']
                ?? $payload['@odata.nextLink']
                ?? $payload['odata.nextLink']
                ?? ''
            ));
            if ($nextLink === '') {
                $next = '';
                continue;
            }
            if (str_starts_with($nextLink, '/')) {
                $next = $nextLink;
                continue;
            }
            if (!str_starts_with($nextLink, $baseUrl . '/')) {
                throw new ExactException('Exact gaf een ongeldige verwijzing naar de volgende resultatenpagina.');
            }
            $next = $nextLink;
        }

        if ($next !== '') {
            throw new ExactException('Exact gaf meer dan 100 pagina\'s met pakbonnen vanaf de startdatum; synchronisatie afgebroken.');
        }

        return $rows;
    }

    /** @param array<string, mixed> $payload */
    private function apiPut(string $path, array $payload): void
    {
        $url = rtrim(Env::require('EXACT_BASE_URL'), '/') . $path;
        $accessToken = $this->accessToken();
        $response = $this->sendPut($url, $accessToken, $payload);
        if ($response['status'] === 401) {
            $accessToken = $this->accessToken(true);
            $response = $this->sendPut($url, $accessToken, $payload);
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new ExactException(
                sprintf('Exact API gaf HTTP %d: %s', $response['status'], $this->apiError($response['body'])),
                $response['status']
            );
        }
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

    /** @param array<string, mixed> $payload @return array{status: int, body: string} */
    private function sendPut(string $url, string $accessToken, array $payload): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
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
            $address = $this->accountMainAddress($accountId, $division);
            if ($address !== []) {
                return $address;
            }
        }
        throw new \RuntimeException('geen afleveradres gevonden in Exact');
    }

    /**
     * Zoek via de eerste goederenleveringsregel de gekoppelde verkooporder.
     * GoodsDeliveries bevat zelf geen verkoopordernummer; GoodsDeliveryLines wel.
     *
     * @return array{order_number: string, contact_name: string, address: array<string, mixed>, contact: array<string, mixed>}
     */
    private function salesOrderContext(string $entryId, int $division): array
    {
        $context = ['order_number' => '', 'contact_name' => '', 'address' => [], 'contact' => []];
        if (preg_match('/^[a-f0-9-]{36}$/i', $entryId) !== 1) {
            return $context;
        }

        try {
            $linePayload = $this->apiGet(
                sprintf('/api/v1/%d/salesorder/GoodsDeliveryLines', $division),
                [
                    '$select' => 'SalesOrderNumber',
                    '$filter' => "EntryID eq guid'{$entryId}'",
                    '$top' => '1',
                ]
            );
            $lines = $this->results($linePayload);
            $orderNumber = trim((string) ($lines[0]['SalesOrderNumber'] ?? ''));
            if ($orderNumber === '' || preg_match('/^\d+$/', $orderNumber) !== 1) {
                return $context;
            }
            $context['order_number'] = $orderNumber;

            $orderPayload = $this->apiGet(
                sprintf('/api/v1/%d/salesorder/SalesOrders', $division),
                [
                    '$select' => implode(',', [
                        'OrderNumber',
                        'OrderedBy',
                        'OrderedByContactPerson',
                        'OrderedByContactPersonFullName',
                    ]),
                    '$filter' => 'OrderNumber eq ' . $orderNumber,
                    '$top' => '1',
                ]
            );
            $orders = $this->results($orderPayload);
            if ($orders === []) {
                return $context;
            }
            $order = $orders[0];
            $context['order_number'] = trim((string) ($order['OrderNumber'] ?? $orderNumber));
            $context['contact_name'] = trim((string) ($order['OrderedByContactPersonFullName'] ?? ''));
            $context['contact'] = $this->contactDetails(
                (string) ($order['OrderedByContactPerson'] ?? ''),
                $division
            );
            $context['address'] = $this->accountMainAddress((string) ($order['OrderedBy'] ?? ''), $division);
        } catch (ExactException $error) {
            error_log('Exact verkoopordercontext niet beschikbaar: HTTP ' . ($error->httpStatus ?? 'onbekend'));
        }
        return $context;
    }

    /** @return array<string, mixed> */
    private function accountMainAddress(string $accountId, int $division): array
    {
        $accountId = trim($accountId);
        if (preg_match('/^[a-f0-9-]{36}$/i', $accountId) !== 1) {
            return [];
        }
        $payload = $this->apiGet(
            sprintf('/api/v1/%d/crm/Addresses', $division),
            ['$top' => '1', '$filter' => "Account eq guid'{$accountId}' and Main eq true"]
        );
        $results = $this->results($payload);
        return $results[0] ?? [];
    }

    /** @return array<string, mixed> */
    private function contactDetails(string $contactId, int $division): array
    {
        $contactId = trim($contactId);
        if (preg_match('/^[a-f0-9-]{36}$/i', $contactId) !== 1) {
            return [];
        }
        try {
            $payload = $this->apiGet(sprintf(
                "/api/v1/%d/crm/Contacts(guid'%s')",
                $division,
                rawurlencode($contactId)
            ));
            $contact = $payload['d'] ?? $payload;
            return is_array($contact) ? $contact : [];
        } catch (ExactException $error) {
            error_log('Exact contactpersoon niet beschikbaar: HTTP ' . ($error->httpStatus ?? 'onbekend'));
            return [];
        }
    }

    /**
     * Bouw de zichtbare contactnaam op met de leesbare titelafkorting uit Exact:
     * titelafkorting + tussenvoegsel + achternaam, of - als die ontbreekt -
     * voornaam + tussenvoegsel + achternaam. Het veld Title bevat de interne code
     * en is daarom bewust niet geschikt voor weergave op het GLS-label.
     *
     * @param array<string, mixed> $contact
     */
    private function contactDisplayName(array $contact): string
    {
        $titleAbbreviation = trim((string) ($contact['TitleAbbreviation'] ?? ''));
        $firstName = trim((string) ($contact['FirstName'] ?? ''));
        $middleName = trim((string) ($contact['MiddleName'] ?? ''));
        $lastName = trim((string) ($contact['LastName'] ?? ''));
        $parts = array_values(array_filter(
            [$titleAbbreviation !== '' ? $titleAbbreviation : $firstName, $middleName, $lastName],
            static fn (string $part): bool => $part !== ''
        ));
        if ($parts !== []) {
            return implode(' ', $parts);
        }
        return trim((string) ($contact['FullName'] ?? ''));
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

    /** @param list<mixed> $values */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    /** @param list<mixed> $values */
    private function firstValidEmail(array $values): string
    {
        foreach ($values as $value) {
            $email = trim((string) ($value ?? ''));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }
        return '';
    }

    /** @param array<string, mixed> $payload @return list<array<string, mixed>> */
    private function results(array $payload): array
    {
        $results = $payload['d']['results'] ?? $payload['value'] ?? $payload['d'] ?? [];
        return is_array($results) ? array_values(array_filter($results, 'is_array')) : [];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function splitStreetAndHouseNumber(string $line, string $number, string $addition): array
    {
        $line = trim($line);
        $number = trim($number);
        $addition = trim($addition);
        if ($number !== '') {
            return [$line, $number, $addition];
        }
        if (preg_match('/^(.+?)\s+(\d+[a-zA-Z0-9\-\/]*)$/u', $line, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2]), ''];
        }
        return [$line, '', ''];
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
        $countryCode = trim((string) ($data['country_code'] ?? ''));
        if ($countryCode !== '' && preg_match('/^[A-Za-z]{2}$/', $countryCode) !== 1) {
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

