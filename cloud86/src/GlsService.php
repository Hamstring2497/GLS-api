<?php

declare(strict_types=1);

namespace App;

final class GlsService
{
    public const FREIGHT_UNITS = ['CO' => 32, 'LG' => 25, 'MP' => 300, 'PL' => 675, 'BP' => 850, 'RL' => 350, 'XL' => 1000];

    public function __construct(private readonly string $storagePath)
    {
    }

    /** @param array<string, mixed> $delivery @return array<string, mixed> */
    public function buildPayload(array $delivery): array
    {
        $address = $delivery['address'];
        $required = [
            'naam' => $address['name'] ?? '',
            'straat' => $address['street'] ?? '',
            'huisnummer' => $address['house_no'] ?? '',
            'postcode' => $address['zip_code'] ?? '',
            'plaats' => $address['city'] ?? '',
            'landcode' => $address['country_code'] ?? '',
        ];
        $missing = array_keys(array_filter($required, static fn ($value): bool => trim((string) $value) === ''));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Ontbrekende adresvelden: ' . implode(', ', $missing));
        }
        if (strlen(trim((string) $address['country_code'])) !== 2) {
            throw new \InvalidArgumentException('Landcode moet een ISO-code van twee letters zijn.');
        }
        if (filter_var(trim((string) ($address['email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Vul eerst een geldig e-mailadres in bij het afleveradres.');
        }
        if ($delivery['parcels'] === []) {
            throw new \InvalidArgumentException('Voeg minimaal één doos toe.');
        }
        $shipType = (string) ($delivery['ship_type'] ?? 'P');
        $reference = trim((string) ($delivery['effective_reference'] ?? $delivery['shipment_reference'] ?? ''));
        if (!in_array($shipType, ['P', 'F'], true) || $reference === '') {
            throw new \InvalidArgumentException('Controleer het verzendtype en de referentie.');
        }
        $freightUnitType = (string) ($delivery['freight_unit_type'] ?? 'CO');
        if ($shipType === 'F' && !isset(self::FREIGHT_UNITS[$freightUnitType])) {
            throw new \InvalidArgumentException('Ongeldig vrachttype.');
        }
        $maxWeight = $shipType === 'F' ? self::FREIGHT_UNITS[$freightUnitType] : 32;
        foreach (['note_1', 'note_2'] as $noteField) {
            if (mb_strlen(trim((string) ($address[$noteField] ?? ''))) > 50) {
                throw new \InvalidArgumentException('Notitie 1 en Notitie 2 mogen maximaal 50 tekens bevatten.');
            }
        }

        $units = [];
        foreach ($delivery['parcels'] as $index => $parcel) {
            $weight = (float) $parcel['weight_kg'];
            if ($weight <= 0 || $weight > $maxWeight) {
                throw new \InvalidArgumentException(
                    sprintf('Gewicht van doos %d moet groter dan 0 en maximaal %d kg zijn.', $index + 1, $maxWeight)
                );
            }
            $unit = [
                'unitId' => $this->unitId($index + 1),
                'weight' => $weight,
            ];
            if ($shipType === 'F') {
                $unit['unitType'] = strtolower($freightUnitType);
            }
            $note1 = trim((string) ($address['note_1'] ?? ''));
            $note2 = trim((string) ($address['note_2'] ?? ''));
            $unit['additionalInfo1'] = $note1 !== '' ? $note1 : 'Order: ' . $delivery['order_number'];
            if ($note2 !== '') {
                $unit['additionalInfo2'] = $note2;
            }
            $units[] = $unit;
        }

        $deliveryAddress = [
            'name1' => $address['name'],
            'street' => $address['street'],
            'houseNo' => trim((string) $address['house_no']),
            'zipCode' => strtoupper(str_replace(' ', '', (string) $address['zip_code'])),
            'city' => $address['city'],
            'countryCode' => strtoupper((string) $address['country_code']),
            // Deze werkplek verwerkt zakelijke Exact-relaties; GLS-code "b" staat voor business.
            'addresseeType' => 'b',
        ];
        if (trim((string) ($address['house_no_addition'] ?? '')) !== '') {
            $deliveryAddress['houseNoExt'] = trim((string) $address['house_no_addition']);
        }
        foreach (['name2' => 'name_2', 'name3' => 'name_3'] as $gls => $local) {
            if (trim((string) ($address[$local] ?? '')) !== '') {
                $deliveryAddress[$gls] = trim((string) $address[$local]);
            }
        }
        foreach (['contact' => 'contact', 'phone' => 'phone', 'email' => 'email'] as $gls => $local) {
            if (!empty($address[$local])) {
                $deliveryAddress[$gls] = $address[$local];
            }
        }

        $payload = [
            'username' => Env::get('GLS_USERNAME', 'mock-user'),
            'password' => Env::get('GLS_PASSWORD', 'mock-password'),
            'shippingSystemName' => 'Exact GLS Werkplek',
            'shippingSystemVersion' => '0.3.0',
            'shiptype' => strtolower($shipType),
            'reference' => $reference,
            'trackingLinkType' => 'u',
            'units' => $units,
            'labelType' => strtolower((string) Env::get('GLS_LABEL_TYPE', 'pdfa6s')),
            'addresses' => ['deliveryAddress' => $deliveryAddress],
        ];
        if ($this->sendShippingDate() && !empty($delivery['shipping_date'])) {
            $payload['shippingDate'] = $delivery['shipping_date'] . 'T00:00:00';
        }
        if (trim((string) Env::get('GLS_CUSTOMER_NO', '')) !== '') {
            $payload['customerNo'] = Env::get('GLS_CUSTOMER_NO');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array{response: array<string, mixed>, tracking_numbers: list<string>, label_path: ?string} */
    public function createLabels(array $payload, string $deliveryId): array
    {
        $mode = strtolower((string) Env::get('GLS_MODE', 'mock'));
        if ($mode === 'mock') {
            $units = array_map(static fn (array $unit): array => [
                'unitId' => $unit['unitId'],
                'unitNo' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
                'unitTrackingLink' => 'https://example.invalid/tracking',
            ], $payload['units']);
            return [
                'response' => ['mock' => true, 'units' => $units],
                'tracking_numbers' => array_column($units, 'unitNo'),
                'label_path' => null,
            ];
        }

        $response = $this->request(Env::require('GLS_CREATE_LABEL_URL'), $payload, 'CreateLabel');
        if (($response['error'] ?? false) === true || empty($response['units'])) {
            throw new \RuntimeException((string) ($response['message'] ?? 'GLS gaf geen units terug.'));
        }
        $numbers = [];
        foreach ($response['units'] as $unit) {
            if (empty($unit['unitNo'])) {
                throw new \RuntimeException('Een GLS-unit bevat geen unitNo.');
            }
            $numbers[] = (string) $unit['unitNo'];
        }

        $label = (string) ($response['labels'] ?? '');
        if ($label === '' && count($response['units']) === 1) {
            $label = (string) ($response['units'][0]['label'] ?? '');
        }
        $labelPath = $label === '' ? null : $this->storeLabel($deliveryId, $label);

        return ['response' => $response, 'tracking_numbers' => $numbers, 'label_path' => $labelPath];
    }

    /** @return array<string, mixed> */
    public function validateLogin(): array
    {
        $response = $this->request(Env::require('GLS_VALIDATE_LOGIN_URL'), [
            'username' => Env::require('GLS_USERNAME'),
            'password' => Env::require('GLS_PASSWORD'),
        ], 'ValidateLogin');
        if (($response['error'] ?? false) === true) {
            throw new \RuntimeException((string) ($response['message'] ?? 'GLS heeft de login afgekeurd.'));
        }
        return $response;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $url, array $payload, string $operation): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException("GLS {$operation} vereist een HTTPS-endpoint.");
        }
        $apiKey = Env::require('GLS_API_KEY');
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                Env::get('GLS_API_KEY_HEADER', 'Ocp-Apim-Subscription-Key') . ': ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status < 200 || $status >= 300) {
            $message = '';
            if (is_string($body) && $body !== '') {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && is_string($decoded['message'] ?? null)) {
                    $message = ' ' . trim($decoded['message']);
                }
            }
            throw new \RuntimeException(
                "GLS {$operation} gaf HTTP {$status}.{$message} {$error}"
            );
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new \RuntimeException("GLS {$operation} gaf geen geldig JSON-object terug.");
        }
        return $response;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function redact(array $payload): array
    {
        $payload['username'] = '***';
        $payload['password'] = '***';
        return $payload;
    }

    private function sendShippingDate(): bool
    {
        return filter_var(Env::get('GLS_SEND_SHIPPING_DATE', 'false'), FILTER_VALIDATE_BOOL);
    }

    private function storeLabel(string $deliveryId, string $base64): string
    {
        $decoded = base64_decode($base64, true);
        if ($decoded === false || !str_starts_with($decoded, '%PDF')) {
            throw new \RuntimeException('GLS-label is geen geldig Base64 PDF-bestand.');
        }
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $deliveryId) . '-' . date('Ymd-His') . '.pdf';
        $path = rtrim($this->storagePath, '/') . '/' . $filename;
        if (file_put_contents($path, $decoded, LOCK_EX) === false) {
            throw new \RuntimeException('Het label kon niet veilig worden opgeslagen.');
        }
        return $filename;
    }

    private function unitId(int $position): string
    {
        $result = '';
        while ($position > 0) {
            $position--;
            $result = chr(65 + ($position % 26)) . $result;
            $position = intdiv($position, 26);
        }
        return $result;
    }
}

