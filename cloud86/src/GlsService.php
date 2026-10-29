<?php

declare(strict_types=1);

namespace App;

final class GlsService
{
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
        if ($delivery['parcels'] === []) {
            throw new \InvalidArgumentException('Voeg minimaal één doos toe.');
        }
        $shipType = (string) ($delivery['ship_type'] ?? 'P');
        $reference = trim((string) ($delivery['shipment_reference'] ?? ''));
        if (!in_array($shipType, ['P', 'F'], true) || $reference === '') {
            throw new \InvalidArgumentException('Controleer het verzendtype en de referentie.');
        }

        $units = [];
        foreach ($delivery['parcels'] as $index => $parcel) {
            $weight = (float) $parcel['weight_kg'];
            if ($weight <= 0 || $weight > 32) {
                throw new \InvalidArgumentException(
                    sprintf('Gewicht van doos %d moet groter dan 0 en maximaal 32 kg zijn.', $index + 1)
                );
            }
            $units[] = [
                'UnitID' => $this->unitId($index + 1),
                'Weight' => $weight,
                'AdditionalInfo1' => 'Order: ' . $delivery['order_number'],
            ];
            if ($shipType === 'F') {
                // Freight met de huidige doosinvoer gebruikt GLS-unitsoort Collo (maximaal 32 kg).
                $units[$index]['UnitType'] = 'CO';
            }
        }

        $deliveryAddress = [
            'Name1' => $address['name'],
            'Street' => $address['street'],
            'HouseNo' => $address['house_no'],
            'CountryCode' => strtoupper((string) $address['country_code']),
            'ZipCode' => strtoupper(str_replace(' ', '', (string) $address['zip_code'])),
            'City' => $address['city'],
        ];
        if ($shipType === 'P') {
            $deliveryAddress['AddresseeType'] = strtoupper((string) ($address['addressee_type'] ?? 'P'));
        }
        foreach (['Contact' => 'contact', 'Phone' => 'phone', 'Email' => 'email'] as $gls => $local) {
            if (!empty($address[$local])) {
                $deliveryAddress[$gls] = $address[$local];
            }
        }

        $payload = [
            'Username' => Env::get('GLS_USERNAME', 'mock-user'),
            'Password' => Env::get('GLS_PASSWORD', 'mock-password'),
            'ShippingSystemName' => 'Exact GLS Werkplek',
            'ShippingSystemVersion' => '0.2.0',
            'ShipType' => $shipType,
            'Reference' => $reference,
            'LabelType' => Env::get('GLS_LABEL_TYPE', 'pdfa6s'),
            'TrackingLinkType' => 'U',
            'Addresses' => ['DeliveryAddress' => $deliveryAddress],
            'Units' => $units,
        ];
        if (!empty($delivery['shipping_date'])) {
            $payload['ShippingDate'] = $delivery['shipping_date'];
        }
        if (trim((string) Env::get('GLS_CUSTOMER_NO', '')) !== '') {
            $payload['CustomerNo'] = Env::get('GLS_CUSTOMER_NO');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload @return array{response: array<string, mixed>, tracking_numbers: list<string>, label_path: ?string} */
    public function createLabels(array $payload, string $deliveryId): array
    {
        $mode = strtolower((string) Env::get('GLS_MODE', 'mock'));
        if ($mode === 'mock') {
            $units = array_map(static fn (array $unit): array => [
                'unitId' => $unit['UnitID'],
                'unitNo' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
                'unitTrackingLink' => 'https://example.invalid/tracking',
            ], $payload['Units']);
            return [
                'response' => ['mock' => true, 'units' => $units],
                'tracking_numbers' => array_column($units, 'unitNo'),
                'label_path' => null,
            ];
        }

        $url = Env::require('GLS_CREATE_LABEL_URL');
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
            throw new \RuntimeException(
                "GLS CreateLabel gaf HTTP {$status}. Herhaal niet blind; controleer eerst in MyGLS. {$error}"
            );
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function redact(array $payload): array
    {
        $payload['Username'] = '***';
        $payload['Password'] = '***';
        return $payload;
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

