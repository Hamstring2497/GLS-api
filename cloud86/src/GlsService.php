<?php

declare(strict_types=1);

namespace App;

final class GlsService
{
    public const REFERENCE_MAX_LENGTH = 20;
    public const ADDRESS_FIELD_LIMITS = [
        'name' => 30,
        'name_2' => 30,
        'name_3' => 30,
        'street' => 40,
        'house_no' => 5,
        'house_no_addition' => 5,
        'zip_code' => 8,
        'city' => 30,
        'country_code' => 2,
        'contact' => 30,
        'phone' => 25,
        'email' => 320,
        'note_1' => 30,
        'note_2' => 30,
    ];
    public const FREIGHT_UNITS = ['CO' => 32, 'LG' => 25, 'MP' => 300, 'PL' => 675, 'BP' => 850, 'RL' => 350, 'XL' => 1000];
    public const PRODUCTS = [
        'direct_delivery_plus',
        'direct_delivery',
        'letterbox_delivery',
        'point_delivery',
        'express_delivery',
    ];
    public const EXPRESS_SERVICES = ['t9', 't10', 't12', 't17', 's9', 's10', 's12', 's17'];

    public function __construct(private readonly string $storagePath)
    {
    }

    /**
     * Laat iedere GLS-naamregel in zijn eigen invoerveld en begrenst die tot 30 tekens.
     *
     * Een vooraf ingevulde waarde kan langer zijn dan het HTML-attribuut `maxlength`. Bij het
     * opslaan van een ander adresveld zou die oude waarde dan alsnog een 422-fout veroorzaken.
     * We korten daarom iedere naamregel afzonderlijk en bij voorkeur op een woordgrens in. Tekst
     * uit Naam wordt nadrukkelijk niet naar Naam 2 of Naam 3 doorgeschoven.
     *
     * @param list<mixed> $nameLines
     * @return array{0: string, 1: string, 2: string}
     */
    public static function fitAddressNameLines(array $nameLines): array
    {
        $maxLength = self::ADDRESS_FIELD_LIMITS['name'];
        $fitted = [];

        foreach (array_slice(array_pad($nameLines, 3, ''), 0, 3) as $sourceLine) {
            $line = trim((string) $sourceLine);
            if (mb_strlen($line) > $maxLength) {
                $line = mb_substr($line, 0, $maxLength);
                $lastSpace = mb_strrpos($line, ' ');
                if ($lastSpace !== false && $lastSpace > (int) floor($maxLength * 0.6)) {
                    $line = rtrim(mb_substr($line, 0, $lastSpace));
                }
            }
            $fitted[] = $line;
        }

        return [$fitted[0], $fitted[1], $fitted[2]];
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
        foreach (self::ADDRESS_FIELD_LIMITS as $field => $maxLength) {
            if (mb_strlen(trim((string) ($address[$field] ?? ''))) > $maxLength) {
                throw new \InvalidArgumentException(
                    sprintf('Adresveld %s mag maximaal %d tekens bevatten.', $field, $maxLength)
                );
            }
        }
        if ($delivery['parcels'] === []) {
            throw new \InvalidArgumentException('Voeg minimaal één doos toe.');
        }
        $shipType = (string) ($delivery['ship_type'] ?? 'P');
        $reference = trim((string) ($delivery['effective_reference'] ?? $delivery['shipment_reference'] ?? ''));
        if (!in_array($shipType, ['P', 'F'], true) || $reference === '') {
            throw new \InvalidArgumentException('Controleer het verzendtype en de referentie.');
        }
        if (mb_strlen($reference) > self::REFERENCE_MAX_LENGTH) {
            throw new \InvalidArgumentException('De gezamenlijke GLS-referentie mag maximaal 20 tekens bevatten.');
        }
        $freightUnitType = (string) ($delivery['freight_unit_type'] ?? 'CO');
        if ($shipType === 'F' && !isset(self::FREIGHT_UNITS[$freightUnitType])) {
            throw new \InvalidArgumentException('Ongeldig vrachttype.');
        }
        $product = (string) ($delivery['gls_product'] ?? 'direct_delivery_plus');
        $glsOptions = is_array($delivery['gls_options'] ?? null) ? $delivery['gls_options'] : [];
        if (!in_array($product, self::PRODUCTS, true)) {
            throw new \InvalidArgumentException('Kies een geldig GLS-product.');
        }
        if ($shipType === 'F' && ($product !== 'direct_delivery_plus' || $this->hasSelectedServices($glsOptions))) {
            throw new \InvalidArgumentException('Aanvullende GLS-producten en services zijn alleen beschikbaar voor Parcel.');
        }
        $maxWeight = $shipType === 'F' ? self::FREIGHT_UNITS[$freightUnitType] : 32;
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
        if ($this->sendShippingDate()) {
            // De Exact-leverdatum kan in het verleden liggen; het magazijn verzendt vandaag.
            $payload['shippingDate'] = date('Y-m-d') . 'T00:00:00';
        }
        $customerNoKey = $shipType === 'F' ? 'GLS_FREIGHT_CUSTOMER_NO' : 'GLS_PARCEL_CUSTOMER_NO';
        $customerNo = trim((string) Env::get($customerNoKey, ''));
        if ($customerNo === '') {
            $customerNo = trim((string) Env::get('GLS_CUSTOMER_NO', ''));
        }
        if ($customerNo !== '') {
            $payload['customerNo'] = $customerNo;
        }
        $services = $this->buildServices($product, $glsOptions);
        if ($services !== []) {
            $payload['services'] = $services;
        }

        return $payload;
    }

    /** @param array<string, mixed> $options @return array<string, bool|string> */
    private function buildServices(string $product, array $options): array
    {
        $services = match ($product) {
            'direct_delivery_plus' => [],
            'direct_delivery' => ['economyParcel' => true],
            'letterbox_delivery' => ['letterBoxParcel' => true],
            'point_delivery' => [
                'shopDeliveryService' => true,
                'shopDeliveryParcelShopId' => trim((string) ($options['parcel_shop_id'] ?? '')),
            ],
            'express_delivery' => [
                'expressService' => strtolower(trim((string) ($options['express_service'] ?? 't17'))),
            ],
            default => throw new \InvalidArgumentException('Kies een geldig GLS-product.'),
        };

        if ($product === 'point_delivery' && $services['shopDeliveryParcelShopId'] === '') {
            throw new \InvalidArgumentException('Vul voor PointDelivery het GLS Point-ID in.');
        }
        if ($product === 'express_delivery' && !in_array($services['expressService'], self::EXPRESS_SERVICES, true)) {
            throw new \InvalidArgumentException('Kies een geldige ExpressDelivery-tijd.');
        }
        if (!empty($options['saturday'])) {
            $services['saturdayService'] = true;
        }
        if (!empty($options['addressee_only'])) {
            $services['addresseeOnlyService'] = true;
        }
        if (!empty($options['point_delivery_flex'])) {
            if ($product !== 'point_delivery') {
                throw new \InvalidArgumentException('PointDeliveryFlex kan alleen samen met PointDelivery worden gekozen.');
            }
            $services['shopDeliveryPlusService'] = true;
        }
        if (!empty($options['point_return'])) {
            $services['shopReturnService'] = true;
        }

        return $services;
    }

    /** @param array<string, mixed> $options */
    private function hasSelectedServices(array $options): bool
    {
        foreach (['saturday', 'addressee_only', 'point_delivery_flex', 'point_return'] as $key) {
            if (!empty($options[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $payload @return array{response: array<string, mixed>, tracking_numbers: list<string>, label_path: ?string} */
    public function createLabels(array $payload, string $deliveryId): array
    {
        $this->validateLabelConfiguration();
        $mode = strtolower((string) Env::get('GLS_MODE', 'mock'));
        if ($mode === 'mock') {
            $units = array_map(static fn (array $unit): array => [
                'unitId' => $unit['unitId'],
                'unitNo' => 'MOCK-' . strtoupper(bin2hex(random_bytes(6))),
                'unitTrackingLink' => 'https://example.invalid/tracking',
                'weight' => (float) ($unit['weight'] ?? 0),
                'unitType' => (string) ($unit['unitType'] ?? ''),
            ], $payload['units']);
            $labelPath = $this->storeMockLabel($deliveryId, $payload, $units);
            return [
                'response' => ['mock' => true, 'units' => $units],
                'tracking_numbers' => array_column($units, 'unitNo'),
                'label_path' => $labelPath,
            ];
        }

        $response = $this->request(Env::require('GLS_CREATE_LABEL_URL'), $payload, 'CreateLabel');
        if (($response['error'] ?? false) === true || empty($response['units'])) {
            throw new GlsException(
                (string) ($response['message'] ?? 'GLS gaf geen units terug.'),
                empty($response['error'])
            );
        }
        $numbers = [];
        if (count($response['units']) !== count($payload['units'])) {
            throw new GlsException('GLS gaf een afwijkend aantal dozen terug. Controleer de zending in GLS.', true);
        }
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
        if ($label === '') {
            throw new GlsException('GLS gaf geen PDF-label terug. Controleer de zending in GLS.', true);
        }
        $labelPath = $this->storeLabel($deliveryId, $label);
        if ($mode === 'test') {
            $response['test'] = true;
        }

        return ['response' => $response, 'tracking_numbers' => $numbers, 'label_path' => $labelPath];
    }

    /** Controleer vóór de databasevergrendeling en vóór iedere externe labelaanvraag. */
    public function validateLabelConfiguration(): void
    {
        $mode = strtolower((string) Env::get('GLS_MODE', 'mock'));
        if ($mode === 'mock') {
            return;
        }
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new \RuntimeException('Ongeldige GLS-modus.');
        }
        $url = parse_url(Env::require('GLS_CREATE_LABEL_URL'));
        $path = $mode === 'live' ? '/v1/api/Label/Create' : '/test/v1/api/Label/Create';
        if (($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== 'api.gls.nl'
            || ($url['path'] ?? '') !== $path || isset($url['user']) || isset($url['pass'])) {
            throw new \RuntimeException('Het GLS-labelendpoint past niet bij de ingestelde modus.');
        }
        Env::require('GLS_USERNAME');
        Env::require('GLS_PASSWORD');
        if (strtolower((string) Env::get('GLS_LABEL_TYPE', 'pdfa6s')) !== 'pdfa6s') {
            throw new \RuntimeException('Deze werkplek vereist GLS-labeltype pdfa6s.');
        }
        if ($mode === 'live' && !$this->sendShippingDate()) {
            throw new \RuntimeException('Verzenden is geblokkeerd: activeer de GLS-verzenddatum.');
        }
        if (!is_dir($this->storagePath) || !is_writable($this->storagePath)) {
            throw new \RuntimeException('De map voor GLS-labels is niet schrijfbaar.');
        }
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
            throw new GlsException("GLS {$operation} vereist een HTTPS-endpoint.");
        }
        $headers = ['Content-Type: application/json'];
        $apiKey = trim((string) Env::get('GLS_API_KEY', ''));
        if ($apiKey !== '') {
            $headers[] = Env::get('GLS_API_KEY_HEADER', 'Ocp-Apim-Subscription-Key') . ': ' . $apiKey;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
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
            $outcomeUnknown = $body === false
                || $status === 0
                || in_array($status, [408, 409, 425, 429], true)
                || $status >= 500;
            throw new GlsException(
                trim("GLS {$operation} gaf HTTP {$status}.{$message} {$error}"),
                $outcomeUnknown,
                $status
            );
        }

        try {
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new GlsException(
                "GLS {$operation} gaf geen geldige JSON-respons.",
                true,
                $status,
                $error
            );
        }
        if (!is_array($response)) {
            throw new GlsException("GLS {$operation} gaf geen geldig JSON-object terug.", true, $status);
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

    /** @param array<string, mixed> $payload @param list<array<string, mixed>> $units */
    private function storeMockLabel(string $deliveryId, array $payload, array $units): string
    {
        $pageNumbers = [];
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        ];
        foreach ($units as $index => $unit) {
            $pageNumber = 5 + ($index * 2);
            $contentNumber = $pageNumber + 1;
            $pageNumbers[] = $pageNumber . ' 0 R';
            $stream = $this->mockLabelPage($deliveryId, $payload, $unit, $index + 1, count($units));
            $objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 283.46 425.20] '
                . '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '
                . $contentNumber . ' 0 R >>';
            $objects[$contentNumber] = '<< /Length ' . strlen($stream) . ">>\nstream\n{$stream}\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageNumbers) . '] /Count ' . count($units) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($number = 1; $number <= count($objects); $number++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$number]) . "\n";
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";

        return $this->storeLabel($deliveryId, base64_encode($pdf));
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $unit */
    private function mockLabelPage(
        string $deliveryId,
        array $payload,
        array $unit,
        int $position,
        int $total
    ): string {
        $address = is_array($payload['addresses']['deliveryAddress'] ?? null)
            ? $payload['addresses']['deliveryAddress']
            : [];
        $nameLines = array_values(array_filter([
            trim((string) ($address['name1'] ?? '')),
            trim((string) ($address['name2'] ?? '')),
            trim((string) ($address['name3'] ?? '')),
        ], static fn (string $value): bool => $value !== ''));
        $house = trim((string) ($address['houseNo'] ?? ''));
        $extension = trim((string) ($address['houseNoExt'] ?? ''));
        $streetLine = trim((string) ($address['street'] ?? '') . ' ' . $house . $extension);
        $postalLine = trim((string) ($address['zipCode'] ?? '') . '  ' . (string) ($address['city'] ?? ''));
        $country = strtoupper((string) ($address['countryCode'] ?? ''));
        $unitNo = (string) ($unit['unitNo'] ?? 'MOCK');
        $weight = number_format((float) ($unit['weight'] ?? 0), 0, ',', '.') . ' kg';
        $reference = (string) ($payload['reference'] ?? $deliveryId);
        $shipType = strtoupper((string) ($payload['shiptype'] ?? 'P'));
        $customerNo = (string) ($payload['customerNo'] ?? 'TEST');
        $services = is_array($payload['services'] ?? null) ? $payload['services'] : [];
        $product = !empty($services['economyParcel']) ? 'DirectDelivery'
            : (!empty($services['letterBoxParcel']) ? 'LetterboxDelivery'
                : (!empty($services['shopDeliveryService']) ? 'PointDelivery'
                    : (!empty($services['expressService']) ? 'ExpressDelivery' : 'DirectDeliveryPlus')));
        $route = strtoupper(substr((string) preg_replace('/\s+/', '', (string) ($address['zipCode'] ?? 'TEST')), 0, 4));

        $commands = [
            '1 1 1 rg 0 0 283.46 425.20 re f',
            '0 0 0 RG 1.4 w 6 6 271.46 413.20 re S',
            '0 0 0 rg 6 366 271.46 53.2 re f',
            $this->pdfText(14, 397, 22, 'GLS TEST', true, '1 1 1 rg'),
            $this->pdfText(14, 380, 8, 'ONGELDIG - NIET VERZENDEN', true, '1 1 1 rg'),
            $this->pdfText(167, 386, 28, $country !== '' ? $country : 'XX', true, '1 1 1 rg'),
            $this->pdfText(167, 371, 12, $route, true, '1 1 1 rg'),
            '0 0 0 RG 1 w 6 327 m 277.46 327 l S',
            $this->pdfText(14, 349, 7, 'PRODUCT', true),
            $this->pdfText(14, 334, $this->mockFitFontSize($product, 118, 11, 7), $product, true),
            $this->pdfText(145, 349, 7, 'TYPE', true),
            $this->pdfText(145, 334, 12, $shipType, true),
            $this->pdfText(183, 349, 7, 'DOOS', true),
            $this->pdfText(183, 334, 12, $position . '/' . $total, true),
            $this->pdfText(228, 349, 7, 'GEWICHT', true),
            $this->pdfText(222, 334, 11, $weight, true),
            '0.84 g 6 310 271.46 17 re f',
            $this->pdfText(14, 315, 8, 'AFLEVERADRES', true),
            $this->mockMatrix($unitNo, 228, 370, 38),
        ];

        $y = 286.0;
        foreach ($nameLines as $lineIndex => $name) {
            $preferredSize = $this->mockAddressFontSize($name, $lineIndex === 0);
            $commands[] = $this->pdfText(
                14,
                $y,
                $this->mockFitFontSize($name, 195, $preferredSize, 8),
                $name,
                true
            );
            $y -= $lineIndex === 0 ? 20 : 15;
        }
        $commands[] = $this->pdfText(
            14,
            min($y - 3, 245),
            $this->mockFitFontSize($streetLine, 195, 13, 8),
            $streetLine,
            false
        );
        $commands[] = $this->pdfText(
            14,
            min($y - 24, 223),
            $this->mockFitFontSize($postalLine, 195, 15, 9),
            $postalLine,
            true
        );
        $commands[] = $this->pdfText(14, min($y - 41, 206), 9, $country, true);
        $commands[] = $this->mockMatrix($reference, 226, 252, 42);
        $commands[] = '0 0 0 RG 1 w 6 178 m 277.46 178 l S';
        $commands[] = $this->pdfText(14, 166, 7, 'REFERENTIE / ORDER', true);
        $commands[] = $this->pdfText(14, 150, $this->mockFitFontSize($reference, 180, 12, 7), $reference, true);
        $commands[] = $this->pdfText(210, 166, 7, 'ROUTE', true);
        $commands[] = $this->pdfText(210, 149, 14, $route, true);
        $commands[] = '0 0 0 RG 1 w 6 140 m 277.46 140 l S';
        $commands[] = $this->pdfText(14, 128, 7, 'TRACKINGNUMMER', true);
        $commands[] = $this->pdfText(14, 113, $this->mockFitFontSize($unitNo, 255, 12, 8), $unitNo, true);
        $commands[] = $this->mockBarcode($unitNo);
        $commands[] = $this->pdfText(14, 24, 7, 'Klant ' . $customerNo . ' | Levering ' . $deliveryId, false);
        $commands[] = $this->pdfText(14, 11, 7, 'TESTLABEL - GEEN GELDIGE GLS-ZENDING', true);

        return implode("\n", $commands);
    }

    private function mockAddressFontSize(string $value, bool $primary): float
    {
        $length = strlen($this->pdfEscape($value));
        if ($length > 42) {
            return 10;
        }
        if ($length > 30) {
            return 12;
        }
        return $primary ? 16 : 12;
    }

    private function mockFitFontSize(string $value, float $maxWidth, float $preferred, float $minimum): float
    {
        $length = max(1, strlen($this->pdfEscape($value)));
        $fitted = $maxWidth / ($length * 0.58);
        return max($minimum, min($preferred, floor($fitted * 2) / 2));
    }

    private function pdfText(float $x, float $y, float $size, string $value, bool $bold, string $color = '0 0 0 rg'): string
    {
        $font = $bold ? 'F1' : 'F2';
        return 'BT ' . $color . ' /' . $font . ' ' . $this->pdfNumber($size) . ' Tf 1 0 0 1 '
            . $this->pdfNumber($x) . ' ' . $this->pdfNumber($y) . ' Tm (' . $this->pdfEscape($value) . ') Tj ET';
    }

    private function pdfEscape(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private function pdfNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function mockBarcode(string $value): string
    {
        $hash = hash('sha256', $value);
        $x = 18.0;
        $commands = ['0 0 0 rg'];
        foreach (str_split(substr($hash, 0, 56)) as $index => $digit) {
            $number = hexdec($digit);
            $width = 1.0 + ($number % 3);
            $height = 60.0 + (($number + $index) % 3) * 4;
            if ($x + $width > 266) {
                break;
            }
            $commands[] = $this->pdfNumber($x) . ' 34 ' . $this->pdfNumber($width) . ' '
                . $this->pdfNumber($height) . ' re f';
            $x += $width + 1.2 + (($number >> 2) % 2);
        }
        return implode("\n", $commands);
    }

    private function mockMatrix(string $value, float $x, float $y, float $size): string
    {
        $cells = 21;
        $cell = $size / $cells;
        $bits = '';
        foreach (str_split(hash('sha256', $value)) as $digit) {
            $bits .= str_pad(decbin(hexdec($digit)), 4, '0', STR_PAD_LEFT);
        }
        $commands = ['1 1 1 rg ' . $this->pdfNumber($x) . ' ' . $this->pdfNumber($y) . ' '
            . $this->pdfNumber($size) . ' ' . $this->pdfNumber($size) . ' re f', '0 0 0 rg'];
        for ($row = 0; $row < $cells; $row++) {
            for ($column = 0; $column < $cells; $column++) {
                $finder = ($row < 5 && $column < 5)
                    || ($row < 5 && $column >= $cells - 5)
                    || ($row >= $cells - 5 && $column < 5);
                $finderFill = $finder && ($row % 4 !== 1 || $column % 4 !== 1);
                $index = (($row * $cells) + $column) % strlen($bits);
                if (!$finderFill && (!$finder || $bits[$index] !== '1')) {
                    continue;
                }
                $commands[] = $this->pdfNumber($x + ($column * $cell)) . ' '
                    . $this->pdfNumber($y + (($cells - $row - 1) * $cell)) . ' '
                    . $this->pdfNumber($cell + 0.05) . ' ' . $this->pdfNumber($cell + 0.05) . ' re f';
            }
        }
        return implode("\n", $commands);
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

