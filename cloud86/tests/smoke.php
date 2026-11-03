<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Security.php';
require dirname(__DIR__) . '/src/DeliveryRepository.php';
require dirname(__DIR__) . '/src/Env.php';
require dirname(__DIR__) . '/src/GlsException.php';
require dirname(__DIR__) . '/src/GlsService.php';
require dirname(__DIR__) . '/src/ExactException.php';
require dirname(__DIR__) . '/src/ExactService.php';
require dirname(__DIR__) . '/src/helpers.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE deliveries (
    exact_entry_id TEXT PRIMARY KEY, merged_into_exact_entry_id TEXT, delivery_number TEXT, order_number TEXT,
    ship_type TEXT, freight_unit_type TEXT, shipment_reference TEXT, address_json TEXT, shipping_date TEXT,
    gls_product TEXT DEFAULT \'direct_delivery_plus\', gls_options_json TEXT,
    status TEXT, tracking_numbers_json TEXT, gls_request_json TEXT, gls_response_json TEXT,
    label_path TEXT, exact_tracking_synced_at TEXT, last_gls_state TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT
)');
$db->exec('CREATE TABLE parcels (exact_entry_id TEXT, position INTEGER, weight_kg REAL)');

$repository = new App\DeliveryRepository($db);
$exactReflection = new ReflectionClass(App\ExactService::class);
$exactWithoutConstructor = $exactReflection->newInstanceWithoutConstructor();
$contactDisplayName = $exactReflection->getMethod('contactDisplayName');
$contactDisplayName->setAccessible(true);
assert($contactDisplayName->invoke($exactWithoutConstructor, [
    'Title' => 'de heer', 'FirstName' => 'Fred', 'MiddleName' => 'van der', 'LastName' => 'Steen',
]) === 'de heer van der Steen');
assert($contactDisplayName->invoke($exactWithoutConstructor, [
    'FirstName' => 'Fred', 'MiddleName' => 'van der', 'LastName' => 'Steen',
]) === 'Fred van der Steen');
assert(App\ExactService::formatTrackingNumber([
    ' 73220023887426 ', '73220023887419', '73220023887426', '',
]) === '73220023887426, 73220023887419');
assert(App\ExactService::isMockTracking([
    'tracking_numbers' => ['MOCK-ABC123'],
    'gls_response_json' => '{"mock":true}',
]));
assert(App\ExactService::isMockTracking([
    'tracking_numbers' => ['123456789'], 'gls_response_json' => '{"test":true}',
]));
assert(!App\ExactService::isMockTracking([
    'tracking_numbers' => ['73220023887426'],
    'gls_response_json' => '{"source":"GLS Track & Trace"}',
]));
$repository->save([
    'exact_entry_id' => 'test-delivery', 'delivery_number' => 'DEL-9',
    'order_number' => 'ORD-2026-1234', 'shipping_date' => null,
    'name' => 'Voorbeeld BV', 'name_2' => 'Afdeling Logistiek', 'name_3' => 'Dock 4',
    'street' => 'Teststraat', 'house_no' => '12', 'house_no_addition' => 'A',
    'zip_code' => '1234 AB', 'city' => 'Utrecht', 'country_code' => 'NL',
    'email' => 'magazijn@example.com', 'note_1' => 'Voorzijde', 'note_2' => 'Melden bij receptie',
]);
$delivery = $repository->find('test-delivery');
assert($delivery !== null);
assert($delivery['ship_type'] === 'P');
assert($delivery['shipment_reference'] === '1234');
assert($delivery['gls_product'] === 'direct_delivery_plus');

$address = $delivery['address'];
$address['street'] = 'Nieuwe straat';
$repository->updateAddress('test-delivery', $address);
$repository->replaceParcels('test-delivery', [4.5, 7.2]);
$delivery = $repository->find('test-delivery');
assert($delivery['address']['street'] === 'Nieuwe straat');

$gls = new App\GlsService(sys_get_temp_dir());
putenv('GLS_PARCEL_CUSTOMER_NO=parcel-test-123');
putenv('GLS_FREIGHT_CUSTOMER_NO=freight-test-456');
$parcel = $gls->buildPayload($delivery);
assert($parcel['shiptype'] === 'p');
assert($parcel['customerNo'] === 'parcel-test-123');
assert($parcel['reference'] === '1234');
assert($parcel['trackingLinkType'] === 'u');
assert($parcel['labelType'] === 'pdfa6s');
assert($parcel['addresses']['deliveryAddress']['email'] === 'magazijn@example.com');
assert($parcel['addresses']['deliveryAddress']['name2'] === 'Afdeling Logistiek');
assert($parcel['addresses']['deliveryAddress']['name3'] === 'Dock 4');
assert($parcel['addresses']['deliveryAddress']['houseNo'] === '12');
assert($parcel['addresses']['deliveryAddress']['houseNoExt'] === 'A');
assert($parcel['addresses']['deliveryAddress']['addresseeType'] === 'b');
assert($parcel['units'][0]['additionalInfo1'] === 'Voorzijde');
assert($parcel['units'][0]['additionalInfo2'] === 'Melden bij receptie');
assert(!isset($parcel['units'][0]['unitType']));
assert(count($parcel['units']) === 2);
assert(!isset($parcel['shippingDate']));
assert(!isset($parcel['services']));
$redactedParcel = $gls->redact($parcel);
assert($redactedParcel['username'] === '***');
assert($redactedParcel['password'] === '***');

$datedDelivery = $delivery;
$datedDelivery['shipping_date'] = '2026-10-30';
putenv('GLS_SEND_SHIPPING_DATE=true');
assert($gls->buildPayload($datedDelivery)['shippingDate'] === date('Y-m-d') . 'T00:00:00');
assert($gls->buildPayload($delivery)['shippingDate'] === date('Y-m-d') . 'T00:00:00');
putenv('GLS_SEND_SHIPPING_DATE=false');

// Configuratiefouten worden vóór de externe call en de leveringsvergrendeling gevonden.
putenv('GLS_MODE=live');
putenv('GLS_USERNAME=test-user');
putenv('GLS_PASSWORD=test-password');
putenv('GLS_CREATE_LABEL_URL=https://api.gls.nl/test/v1/api/Label/Create?api-version=1.0');
try {
    $gls->validateLabelConfiguration();
    assert(false, 'Live mag niet via het testendpoint verzenden.');
} catch (RuntimeException $error) {
    assert(str_contains($error->getMessage(), 'endpoint'));
}
putenv('GLS_CREATE_LABEL_URL=https://api.gls.nl/v1/api/Label/Create?api-version=1.0');
try {
    $gls->validateLabelConfiguration();
    assert(false, 'Live mag niet zonder verzendbevestiging starten.');
} catch (RuntimeException $error) {
    assert(str_contains($error->getMessage(), 'verzenddatum'));
}
putenv('GLS_SEND_SHIPPING_DATE=true');
$gls->validateLabelConfiguration();
putenv('GLS_MODE=mock');
putenv('GLS_SEND_SHIPPING_DATE=false');

$serviceDelivery = $delivery;
$serviceDelivery['gls_product'] = 'point_delivery';
$serviceDelivery['gls_options'] = [
    'parcel_shop_id' => '34310025',
    'point_delivery_flex' => true,
    'saturday' => true,
    'addressee_only' => true,
    'point_return' => true,
];
$servicePayload = $gls->buildPayload($serviceDelivery);
assert($servicePayload['services']['shopDeliveryService'] === true);
assert($servicePayload['services']['shopDeliveryParcelShopId'] === '34310025');
assert($servicePayload['services']['shopDeliveryPlusService'] === true);
assert($servicePayload['services']['saturdayService'] === true);
assert($servicePayload['services']['addresseeOnlyService'] === true);
assert($servicePayload['services']['shopReturnService'] === true);

$expressDelivery = $delivery;
$expressDelivery['gls_product'] = 'express_delivery';
$expressDelivery['gls_options'] = ['express_service' => 't12'];
assert($gls->buildPayload($expressDelivery)['services']['expressService'] === 't12');

$letterboxDelivery = $delivery;
$letterboxDelivery['gls_product'] = 'letterbox_delivery';
$letterboxDelivery['gls_options'] = [];
assert($gls->buildPayload($letterboxDelivery)['services']['letterBoxParcel'] === true);

$economyDelivery = $delivery;
$economyDelivery['gls_product'] = 'direct_delivery';
$economyDelivery['gls_options'] = [];
assert($gls->buildPayload($economyDelivery)['services']['economyParcel'] === true);

$repository->updateShippingDetails('test-delivery', 'F', 'ANDERS', 'PL');
$freight = $gls->buildPayload($repository->find('test-delivery'));
assert($freight['shiptype'] === 'f');
assert($freight['customerNo'] === 'freight-test-456');
assert($freight['reference'] === 'ANDERS');
assert($freight['units'][0]['unitType'] === 'pl');
assert($freight['units'][1]['unitType'] === 'pl');
putenv('GLS_PARCEL_CUSTOMER_NO');
putenv('GLS_FREIGHT_CUSTOMER_NO');

$mock = $gls->createLabels($freight, 'test-delivery');
assert(count($mock['tracking_numbers']) === 2);
assert(is_string($mock['label_path']) && $mock['label_path'] !== '');
$mockLabel = sys_get_temp_dir() . '/' . $mock['label_path'];
assert(is_file($mockLabel));
$mockPdf = (string) file_get_contents($mockLabel);
assert(str_starts_with($mockPdf, '%PDF-1.4'));
assert(str_contains($mockPdf, '/MediaBox [0 0 283.46 425.20]'));
assert(str_contains($mockPdf, '/Count 2'));
assert(substr_count($mockPdf, '/Type /Page ') === 2);
assert(str_contains($mockPdf, 'GLS TEST'));
assert(str_contains($mockPdf, 'ONGELDIG - NIET VERZENDEN'));
assert(str_contains($mockPdf, 'DirectDeliveryPlus'));
assert(str_contains($mockPdf, 'REFERENTIE / ORDER'));
assert(str_contains($mockPdf, 'Voorbeeld BV'));
assert(str_contains($mockPdf, 'Nieuwe straat 12A'));
assert(str_contains($mockPdf, '7,2 kg'));
unlink($mockLabel);

$redactedFreight = $gls->redact($freight);
assert($repository->beginGlsRequest('test-delivery', $redactedFreight));
assert($repository->find('test-delivery')['status'] === 'gls_processing');
assert(!$repository->beginGlsRequest('test-delivery', $redactedFreight));
$repository->markGlsOutcomeUnknown('test-delivery', 'Timeout tijdens de test.');
assert($repository->find('test-delivery')['status'] === 'gls_unknown');
assert($repository->releaseGlsRequestAfterCheck('test-delivery'));
assert($repository->find('test-delivery')['status'] === 'ready');
assert(!$repository->releaseGlsRequestAfterCheck('test-delivery'));
$repository->beginGlsRequest('test-delivery', $redactedFreight);
$repository->resetRejectedGlsRequest('test-delivery', 'Aanvraag afgewezen.');
assert($repository->find('test-delivery')['status'] === 'ready');

$deliveries = [$repository->find('test-delivery')];
$selectedDelivery = $deliveries[0];
$exactStatus = ['connected' => false, 'division' => null];
$_SESSION = [];
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$html = (string) ob_get_clean();
assert(str_contains($html, 'Vrachttype'));
assert(str_contains($html, 'Collo (max. 32 kg)'));
assert(str_contains($html, 'Aanvullende producten en services'));
assert(str_contains($html, 'DirectDeliveryPlus'));
assert(str_contains($html, 'PointDeliveryFlex'));
assert(str_contains($html, 'GLS Point-ID'));
assert(str_contains($html, 'Express tijd'));
assert(!str_contains($html, 'Zending wissen'));
assert(str_contains($html, 'Wissen'));
assert(str_contains($html, 'value="ANDERS"'));
assert(str_contains($html, 'Nieuwe straat'));
assert(str_contains($html, 'selected-row'));
assert(str_contains($html, 'E-mailadres *'));
assert(str_contains($html, 'Toegevoegd'));
assert(str_contains($html, 'Dozen opslaan'));
assert(str_contains($html, 'Maak afdrukbaar testlabel'));
assert(str_contains($html, 'confirmGlsLabelRequest'));
assert(str_contains($html, 'form="address-details-form">Adres opslaan'));
assert(str_contains($html, 'niet-opgeslagen wijzigingen'));
assert(str_contains($html, 'Naam 2'));
assert(str_contains($html, 'Naam 3'));
assert(str_contains($html, 'Toevoeging'));
assert(str_contains($html, 'Notitie 1'));
assert(str_contains($html, 'Notitie 2'));
assert(str_contains($html, 'step="0.1"'));
assert(str_contains($html, 'Levering DEL-9'));
assert(!str_contains($html, 'Openen →'));

$layout = file_get_contents(dirname(__DIR__) . '/views/layout.php');
assert(is_string($layout));
assert(str_contains($layout, '>Gezien</button>'));
assert(str_contains($layout, "this.closest('.flash').remove()"));
assert(str_contains($layout, 'Onderhoud'));
assert(str_contains($layout, 'Zending wissen'));
assert(str_contains($layout, 'Tabel leegmaken en opnieuw ophalen'));
assert(str_contains($layout, 'confirmTableRefresh'));
assert(str_contains($layout, 'Haal uw supervisor'));
assert(str_contains($layout, 'supervisor_confirmation'));
assert(str_contains($layout, 'Dit kan een echte betaalde GLS-zending aanmaken'));
assert(str_contains($layout, 'confirmGlsRelease'));

$db->exec("UPDATE deliveries SET status = 'labels_created' WHERE exact_entry_id = 'test-delivery'");
$repository->updateShippingDetails('test-delivery', 'P', 'GEWIJZIGD');
assert($repository->find('test-delivery')['shipment_reference'] === 'ANDERS');
assert(!$repository->discard('test-delivery'));

$repository->save([
    'exact_entry_id' => 'second', 'delivery_number' => 'DEL-10',
    'order_number' => 'ORD-2026-5678', 'shipping_date' => null,
    'name' => 'Tweede BV', 'street' => 'Teststraat', 'house_no' => '1',
    'zip_code' => '1234 AB', 'city' => 'Utrecht', 'country_code' => 'NL',
    'email' => 'tweede@example.com',
]);
$repository->replaceParcels('second', [12.5]);
$repository->replaceParcels('second', []);
assert($repository->find('second')['status'] === 'new');
assert($repository->find('second')['parcels'] === []);
$deliveries = [$repository->find('second')];
$selectedDelivery = $deliveries[0];
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$emptyParcelHtml = (string) ob_get_clean();
assert(str_contains($emptyParcelHtml, '<span>Doos 1</span>'));
assert(str_contains($emptyParcelHtml, 'name="weights[]" value=""'));
assert(!str_contains($emptyParcelHtml, 'name="weights[]" value="" min="0.1" max="32" step="0.1" inputmode="decimal" placeholder="0,0" required'));
assert($repository->discard('second'));
assert(count($repository->all()) === 1);
assert($repository->find('second')['status'] === 'discarded');

$repository->save([
    'exact_entry_id' => 'merge-primary', 'delivery_number' => 'DEL-20',
    'order_number' => 'ORD-2000', 'shipping_date' => null,
    'name' => 'Samen BV', 'street' => 'Samenstraat', 'house_no' => '8',
    'zip_code' => '1111 AA', 'city' => 'Utrecht', 'country_code' => 'NL',
    'email' => 'samen@example.com',
]);
$repository->save([
    'exact_entry_id' => 'merge-child', 'delivery_number' => 'DEL-21',
    'order_number' => 'ORD-2001', 'shipping_date' => null,
    'name' => 'Samen BV', 'street' => 'Samenstraat', 'house_no' => '8',
    'zip_code' => '1111 AA', 'city' => 'Utrecht', 'country_code' => 'NL',
    'email' => '',
]);
$repository->replaceParcels('merge-primary', [8.0]);
assert(count($repository->mergeCandidates('merge-primary')) === 1);
$deliveries = $repository->all();
$selectedDelivery = $repository->find('merge-primary');
$mergeCandidates = $repository->mergeCandidates('merge-primary');
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$mergeCandidateHtml = (string) ob_get_clean();
assert(str_contains($mergeCandidateHtml, 'merge-candidate-row'));
assert(str_contains($mergeCandidateHtml, 'Samenvoegen?'));
assert(str_contains($mergeCandidateHtml, 'quick-merge-form'));
$repository->setMergedDeliveries('merge-primary', ['merge-child']);
$merged = $repository->find('merge-primary');
assert(count($merged['merged_deliveries']) === 1);
assert($merged['effective_reference'] === '2000 / ORD-2001');
assert($repository->find('merge-child')['merged_into_exact_entry_id'] === 'merge-primary');
assert($gls->buildPayload($merged)['reference'] === '2000 / ORD-2001');
assert(delivery_missing_data($merged) === []);
$imported = $repository->importExistingTracking([[
    'delivery_ids' => ['DEL-20', 'DEL-21'],
    'tracking_numbers' => ['73220023886849'],
]]);
assert($imported === 2);
assert($repository->find('merge-primary')['tracking_numbers'] === ['73220023886849']);
assert($repository->find('merge-child')['status'] === 'labels_created');
assert($repository->find('merge-child')['last_gls_state'] === 'tracking_imported');
$deliveries = [$repository->find('merge-primary')];
$selectedDelivery = $deliveries[0];
$mergeCandidates = [];
$glsMode = 'live';
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$exactPendingHtml = (string) ob_get_clean();
assert(str_contains($exactPendingHtml, 'Exact nog niet bijgewerkt'));
assert(str_contains($exactPendingHtml, 'Opnieuw naar Exact'));
$repository->markExactTrackingSynced('merge-primary');
$repository->markExactTrackingSynced('merge-child');
assert(!empty($repository->find('merge-primary')['exact_tracking_synced_at']));
$deliveries = [$repository->find('merge-primary')];
$selectedDelivery = $deliveries[0];
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$exactSyncedHtml = (string) ob_get_clean();
assert(str_contains($exactSyncedHtml, 'Exact bijgewerkt'));
assert(!str_contains($exactSyncedHtml, 'Opnieuw naar Exact'));
$missingMergeEmail = $merged;
$missingMergeEmail['address']['email'] = '';
assert(delivery_missing_data($missingMergeEmail)['email'] === 'geldig e-mailadres');

$missingEmail = $repository->find('test-delivery');
$missingEmail['address']['email'] = '';
try {
    $gls->buildPayload($missingEmail);
    assert(false, 'GLS-label zonder e-mailadres mocht niet worden aangemaakt.');
} catch (InvalidArgumentException $error) {
    assert(str_contains($error->getMessage(), 'e-mailadres'));
}

assert($repository->clearAll() === 4);
assert($repository->all() === []);
assert($db->query('SELECT COUNT(*) FROM parcels')->fetchColumn() == 0);

echo "Smoke test geslaagd: adresregels, notities, vrachttype, dozen, trackingimport en vergrendeling.\n";
