<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = require dirname(__DIR__) . '/src/bootstrap.php';

try {
    if (App\Env::get('GLS_MODE') !== 'live') {
        throw new RuntimeException('GLS_MODE is niet live.');
    }
    $gls = new App\GlsService($root . '/storage/labels');
    $gls->validateLabelConfiguration();
    App\Env::require('GLS_PARCEL_CUSTOMER_NO');
    $db = App\Database::connect();
    $db->query('SELECT exact_tracking_synced_at, gls_product, gls_options_json, merged_into_exact_entry_id FROM deliveries LIMIT 0');
    if (!(new App\ExactService($db))->status()['connected']) {
        throw new RuntimeException('Exact-koppeling ontbreekt.');
    }
    echo "Configuratie, labelopslag, database en Exact-koppeling: OK\n";
    echo 'Verzenddatum: ' . date('Y-m-d') . ' (' . date_default_timezone_get() . ")\n";
    if (in_array('--validate-login', $argv, true)) {
        // Bewust uitsluitend authenticatie; dit maakt geen zending of label aan.
        if (App\Env::get('GLS_VALIDATE_LOGIN_URL') !== 'https://api.gls.nl/v1/api/Authentication/ValidateLogin?api-version=1.0') {
            throw new RuntimeException('ValidateLogin staat niet op het productie-endpoint.');
        }
        $gls->validateLogin();
        echo "GLS-productielogin: OK\n";
    }
    echo "Geen zending aangemaakt. Eerste echte label/Exact-controle blijft nodig.\n";
} catch (Throwable $error) {
    // Geen API-responses, verbindingsdetails of geheimen in de uitvoer.
    fwrite(STDERR, "Controle niet geslaagd. Controleer configuratie, opslag, database en GLS-login.\n");
    exit(1);
}
