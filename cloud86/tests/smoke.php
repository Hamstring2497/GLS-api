<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Security.php';
require dirname(__DIR__) . '/src/DeliveryRepository.php';
require dirname(__DIR__) . '/src/Env.php';
require dirname(__DIR__) . '/src/GlsService.php';
require dirname(__DIR__) . '/src/ExactException.php';
require dirname(__DIR__) . '/src/ExactService.php';
require dirname(__DIR__) . '/src/helpers.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE deliveries (
    exact_entry_id TEXT PRIMARY KEY, delivery_number TEXT, order_number TEXT,
    ship_type TEXT, freight_unit_type TEXT, shipment_reference TEXT, address_json TEXT, shipping_date TEXT,
    status TEXT, tracking_numbers_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT
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

$address = $delivery['address'];
$address['street'] = 'Nieuwe straat';
$repository->updateAddress('test-delivery', $address);
$repository->replaceParcels('test-delivery', [4.5, 7.2]);
$delivery = $repository->find('test-delivery');
assert($delivery['address']['street'] === 'Nieuwe straat');

$gls = new App\GlsService(sys_get_temp_dir());
$parcel = $gls->buildPayload($delivery);
assert($parcel['ShipType'] === 'P');
assert($parcel['Reference'] === '1234');
assert($parcel['Addresses']['DeliveryAddress']['Email'] === 'magazijn@example.com');
assert($parcel['Addresses']['DeliveryAddress']['Name2'] === 'Afdeling Logistiek');
assert($parcel['Addresses']['DeliveryAddress']['Name3'] === 'Dock 4');
assert($parcel['Addresses']['DeliveryAddress']['HouseNo'] === '12 A');
assert($parcel['Units'][0]['AdditionalInfo1'] === 'Voorzijde');
assert($parcel['Units'][0]['AdditionalInfo2'] === 'Melden bij receptie');
assert(count($parcel['Units']) === 2);
assert(!isset($parcel['Units'][0]['UnitType']));

$repository->updateShippingDetails('test-delivery', 'F', 'ANDERS', 'PL');
$freight = $gls->buildPayload($repository->find('test-delivery'));
assert($freight['ShipType'] === 'F');
assert($freight['Reference'] === 'ANDERS');
assert($freight['Units'][0]['UnitType'] === 'PL');
assert($freight['Units'][1]['UnitType'] === 'PL');

$mock = $gls->createLabels($freight, 'test-delivery');
assert(count($mock['tracking_numbers']) === 2);
assert($mock['label_path'] === null);

$deliveries = [$repository->find('test-delivery')];
$selectedDelivery = $deliveries[0];
$exactStatus = ['connected' => false, 'division' => null];
$_SESSION = [];
ob_start();
require dirname(__DIR__) . '/views/deliveries/index.php';
$html = (string) ob_get_clean();
assert(str_contains($html, 'Vrachttype'));
assert(str_contains($html, 'Collo (max. 32 kg)'));
assert(!str_contains($html, 'Zending wissen'));
assert(str_contains($html, 'Wissen'));
assert(str_contains($html, 'value="ANDERS"'));
assert(str_contains($html, 'Nieuwe straat'));
assert(str_contains($html, 'selected-row'));
assert(str_contains($html, 'E-mailadres *'));
assert(str_contains($html, 'Toegevoegd'));
assert(str_contains($html, 'Dozen opslaan'));
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

$missingEmail = $repository->find('test-delivery');
$missingEmail['address']['email'] = '';
try {
    $gls->buildPayload($missingEmail);
    assert(false, 'GLS-label zonder e-mailadres mocht niet worden aangemaakt.');
} catch (InvalidArgumentException $error) {
    assert(str_contains($error->getMessage(), 'e-mailadres'));
}

assert($repository->clearAll() === 2);
assert($repository->all() === []);
assert($db->query('SELECT COUNT(*) FROM parcels')->fetchColumn() == 0);

echo "Smoke test geslaagd: adresregels, notities, vrachttype, dozen en vergrendeling.\n";
