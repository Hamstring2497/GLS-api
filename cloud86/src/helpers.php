<?php

declare(strict_types=1);

use App\HttpException;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Geeft alleen voor een echt, numeriek GLS-pakketnummer een veilige Track & Trace-URL terug.
 * Mockcodes en andere onverwachte waarden blijven gewone tekst.
 */
function gls_tracking_url(mixed $trackingNumber): ?string
{
    $trackingNumber = trim((string) $trackingNumber);
    if (preg_match('/^\d{8,32}$/', $trackingNumber) !== 1) {
        return null;
    }

    return 'https://tracking.gls.nl/customer-track-and-trace/parcels/' . rawurlencode($trackingNumber);
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

/**
 * Geeft de concrete, voor de magazijnmedewerker herkenbare ontbrekende gegevens terug.
 * De sleutels corresponderen met formulieronderdelen en worden ook gebruikt om velden te markeren.
 *
 * @param array<string, mixed> $delivery
 * @return array<string, string>
 */
function delivery_missing_data(array $delivery): array
{
    $address = is_array($delivery['address'] ?? null) ? $delivery['address'] : [];
    $missing = [];
    foreach ([
        'name' => 'naam',
        'street' => 'straat',
        'house_no' => 'huisnummer',
        'zip_code' => 'postcode',
        'city' => 'plaats',
        'country_code' => 'landcode',
    ] as $field => $label) {
        if (trim((string) ($address[$field] ?? '')) === '') {
            $missing[$field] = $label;
        }
    }
    $countryCode = trim((string) ($address['country_code'] ?? ''));
    if ($countryCode !== '' && preg_match('/^[A-Za-z]{2}$/', $countryCode) !== 1) {
        $missing['country_code'] = 'geldige landcode';
    }
    if (filter_var(trim((string) ($address['email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) {
        $missing['email'] = 'geldig e-mailadres';
    }
    if (trim((string) ($delivery['effective_reference'] ?? $delivery['shipment_reference'] ?? '')) === '') {
        $missing['shipment_reference'] = 'referentie';
    }
    if (($delivery['parcels'] ?? []) === []) {
        $missing['parcels'] = 'minimaal één doos met gewicht';
    }

    return $missing;
}

