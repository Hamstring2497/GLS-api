<?php

declare(strict_types=1);

use App\Database;
use App\DeliveryRepository;
use App\Env;
use App\ExactException;
use App\ExactService;
use App\GlsException;
use App\GlsService;
use App\HttpException;
use App\Security;
use App\UserRepository;

$root = require dirname(__DIR__) . '/src/bootstrap.php';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
$path = $path === '/' ? '/' : rtrim($path, '/');

try {
    if ($method === 'GET' && $path === '/health') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'gls_mode' => Env::get('GLS_MODE', 'mock')], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method === 'POST' && $path === '/webhooks/gls') {
        $expected = (string) Env::get('GLS_WEBHOOK_BEARER_TOKEN', '');
        $provided = preg_replace('/^Bearer\s+/i', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($expected === '' || !hash_equals($expected, $provided)) {
            abort(401, 'Ongeldige GLS-webhookautorisatie.');
        }
        $payload = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            abort(400, 'Ongeldige JSON.');
        }
        $stored = (new DeliveryRepository(Database::connect()))->recordWebhook($payload);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['accepted' => true, 'new' => $stored], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method === 'GET' && $path === '/login') {
        view('login');
    }
    if ($method === 'POST' && $path === '/login') {
        Security::verifyCsrf();
        $user = (new UserRepository(Database::connect()))->authenticate(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        if ($user === null) {
            usleep(random_int(150000, 350000));
            flash('error', 'Inloggen is mislukt. Controleer de gegevens of probeer het later opnieuw.');
            redirect('/login');
        }
        Security::login($user);
        redirect('/');
    }
    if ($method === 'POST' && $path === '/logout') {
        Security::verifyCsrf();
        $_SESSION = [];
        session_destroy();
        redirect('/login');
    }

    if (!Security::isAuthenticated()) {
        redirect('/login');
    }

    $repository = new DeliveryRepository(Database::connect());
    $gls = new GlsService($root . '/storage/labels');

    if ($method === 'GET' && $path === '/') {
        $exactStatus = (new ExactService(Database::connect()))->status();
        $deliveries = $repository->all();
        $selectedId = (string) ($_GET['delivery'] ?? '');
        $selected = $selectedId !== ''
            ? $repository->find($selectedId)
            : ($repository->firstAddedWithoutTracking() ?? ($deliveries[0] ?? null));
        if ($selectedId !== '' && ($selected === null || $selected['status'] === 'discarded')) {
            abort(404, 'Levering niet gevonden.');
        }
        if ($selected !== null && !empty($selected['merged_into_exact_entry_id'])) {
            redirect('/?delivery=' . rawurlencode((string) $selected['merged_into_exact_entry_id']));
        }
        view('deliveries/index', [
            'deliveries' => $deliveries,
            'selectedDelivery' => $selected,
            'exactStatus' => $exactStatus,
            'glsMode' => strtolower((string) Env::get('GLS_MODE', 'mock')),
            'mergeCandidates' => $selected !== null ? $repository->mergeCandidates((string) $selected['exact_entry_id']) : [],
        ]);
    }

    if ($method === 'GET' && $path === '/oauth/exact/start') {
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Alleen een beheerder kan Exact koppelen.');
        }
        redirect((new ExactService(Database::connect()))->authorizationUrl());
    }

    if ($method === 'GET' && $path === '/oauth/exact/callback') {
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Log als beheerder in om Exact te koppelen.');
        }
        if (!empty($_GET['error'])) {
            abort(400, 'Exact-autorisatie is geweigerd: ' . (string) $_GET['error']);
        }
        $division = (new ExactService(Database::connect()))->handleCallback(
            (string) ($_GET['code'] ?? ''),
            (string) ($_GET['state'] ?? '')
        );
        flash('success', "Exact Online is gekoppeld aan administratie {$division}.");
        redirect('/');
    }

    if ($method === 'POST' && $path === '/exact/sync') {
        Security::verifyCsrf();
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Alleen een beheerder kan Exact synchroniseren.');
        }
        try {
            $result = (new ExactService(Database::connect()))->syncGoodsDeliveries($repository);
        } catch (ExactException $error) {
            error_log('Exact synchronisatie: ' . $error->getMessage());
            flash('error', 'Ophalen uit Exact is mislukt: ' . $error->getMessage());
            redirect('/');
        }
        $message = sprintf(
            '%d levering(en) uit Exact toegevoegd; %d reeds bekend of al verzonden.',
            $result['imported'],
            $result['skipped']
        );
        if ($result['errors'] !== []) {
            $message .= ' Controle nodig: ' . implode(' | ', array_slice($result['errors'], 0, 3));
            // De synchronisatie is uitgevoerd, maar enkele regels vragen aandacht. Dit is geen
            // volledige applicatiefout en krijgt daarom een waarschuwing in plaats van rood.
            flash('warning', $message);
        } elseif ($result['imported'] === 0) {
            flash('info', $message);
        } else {
            flash('success', $message);
        }
        redirect('/');
    }

    if ($method === 'GET' && $path === '/maintenance/tracking-import') {
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Alleen een beheerder kan bestaande GLS-zendingen koppelen.');
        }
        view('maintenance/tracking-import', [
            'deliveries' => array_values(array_filter(
                $repository->all(),
                static fn (array $delivery): bool => $delivery['tracking_numbers'] === []
            )),
        ]);
    }

    if ($method === 'POST' && $path === '/maintenance/tracking-import') {
        Security::verifyCsrf();
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Alleen een beheerder kan bestaande GLS-zendingen koppelen.');
        }
        if (($_POST['confirm_import'] ?? '') !== 'yes') {
            abort(422, 'Bevestig eerst dat de zendingen al bij GLS bestaan.');
        }

        $assignments = [];
        $lines = preg_split('/\R/', trim((string) ($_POST['assignments'] ?? ''))) ?: [];
        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^([^=]+)=([^=]+)$/', $line, $parts)) {
                abort(422, 'Regel ' . ($lineNumber + 1) . ' heeft niet de vorm levering=trackingnummer.');
            }
            $deliveryIds = array_values(array_filter(preg_split('/[\s,;]+/', trim($parts[1])) ?: []));
            $trackingNumbers = array_values(array_filter(preg_split('/[\s,;]+/', trim($parts[2])) ?: []));
            if ($deliveryIds === [] || $trackingNumbers === []) {
                abort(422, 'Regel ' . ($lineNumber + 1) . ' mist een levering of trackingnummer.');
            }
            $assignments[] = [
                'delivery_ids' => $deliveryIds,
                'tracking_numbers' => $trackingNumbers,
            ];
        }
        if ($assignments === []) {
            abort(422, 'Vul minimaal één koppeling in.');
        }

        try {
            $updated = $repository->importExistingTracking($assignments);
        } catch (InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }
        flash('success', "{$updated} levering(en) aan bestaande GLS-tracking gekoppeld.");
        redirect('/');
    }

    if ($method === 'GET' && $path === '/deliveries/new') {
        view('deliveries/new');
    }

    if ($method === 'POST' && $path === '/deliveries') {
        Security::verifyCsrf();
        $data = array_map(
            static fn ($value): string => trim((string) $value),
            $_POST
        );
        [$data['name'], $data['name_2'], $data['name_3']] = GlsService::fitAddressNameLines([
            $data['name'] ?? '',
            $data['name_2'] ?? '',
            $data['name_3'] ?? '',
        ]);
        $required = ['exact_entry_id', 'delivery_number', 'order_number', 'name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'];
        foreach ($required as $field) {
            if (($data[$field] ?? '') === '') {
                abort(422, "Veld {$field} is verplicht.");
            }
        }
        if (filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul een geldig e-mailadres in.');
        }
        foreach (GlsService::ADDRESS_FIELD_LIMITS as $field => $maxLength) {
            if (mb_strlen((string) ($data[$field] ?? '')) > $maxLength) {
                abort(422, sprintf('Adresveld %s mag maximaal %d tekens bevatten.', $field, $maxLength));
            }
        }
        $repository->save($data);
        flash('success', 'Levering is opgeslagen.');
        redirect('/deliveries/' . rawurlencode($data['exact_entry_id']));
    }

    if ($method === 'GET' && preg_match('#^/deliveries/([^/]+)$#', $path, $matches)) {
        $delivery = $repository->find(rawurldecode($matches[1]));
        if ($delivery === null || $delivery['status'] === 'discarded') {
            abort(404, 'Levering niet gevonden.');
        }
        redirect('/?delivery=' . rawurlencode((string) $delivery['exact_entry_id']));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/address$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if (!empty($delivery['merged_into_exact_entry_id'])) {
            abort(409, 'Bewerk het adres via de hoofdlevering van deze samengevoegde zending.');
        }
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'Het adres kan na het aanmaken van labels niet meer worden gewijzigd.');
        }
        $required = ['name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'];
        $address = [];
        foreach ([
            'name', 'name_2', 'name_3', 'street', 'house_no', 'house_no_addition',
            'zip_code', 'city', 'country_code', 'contact', 'phone', 'email', 'note_1', 'note_2',
        ] as $field) {
            $address[$field] = trim((string) ($_POST[$field] ?? ''));
            if (in_array($field, $required, true) && $address[$field] === '') {
                abort(422, "Adresveld {$field} is verplicht.");
            }
        }
        $address['contact'] = GlsService::cleanContactPerson($address['contact']);
        [$address['name'], $address['name_2'], $address['name_3']] = GlsService::fitAddressNameLines([
            $address['name'],
            $address['name_2'],
            $address['name_3'],
        ]);
        $address['country_code'] = strtoupper($address['country_code']);
        if (preg_match('/^[A-Z]{2}$/', $address['country_code']) !== 1) {
            abort(422, 'Landcode moet uit twee letters bestaan.');
        }
        if (filter_var($address['email'], FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul een geldig e-mailadres in.');
        }
        foreach (GlsService::ADDRESS_FIELD_LIMITS as $field => $maxLength) {
            if (mb_strlen($address[$field]) > $maxLength) {
                abort(422, sprintf('Adresveld %s mag maximaal %d tekens bevatten.', $field, $maxLength));
            }
        }
        $address['addressee_type'] = (string) ($delivery['address']['addressee_type'] ?? 'B');
        $repository->updateAddress($id, $address);
        flash('success', 'Afleveradres opgeslagen.');
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/shipping$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if (!empty($delivery['merged_into_exact_entry_id'])) {
            abort(409, 'Bewerk de verzendgegevens via de hoofdlevering van deze samengevoegde zending.');
        }
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'De verzendgegevens kunnen na labelaanmaak niet meer worden gewijzigd.');
        }
        $requiredAddressFields = ['name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'];
        $address = [];
        foreach ([
            'name', 'name_2', 'name_3', 'street', 'house_no', 'house_no_addition',
            'zip_code', 'city', 'country_code', 'contact', 'phone', 'email', 'note_1', 'note_2',
        ] as $field) {
            $address[$field] = trim((string) ($_POST[$field] ?? ''));
            if (in_array($field, $requiredAddressFields, true) && $address[$field] === '') {
                abort(422, "Adresveld {$field} is verplicht.");
            }
        }
        [$address['name'], $address['name_2'], $address['name_3']] = GlsService::fitAddressNameLines([
            $address['name'],
            $address['name_2'],
            $address['name_3'],
        ]);
        $address['contact'] = GlsService::cleanContactPerson($address['contact']);
        $address['country_code'] = strtoupper($address['country_code']);
        if (preg_match('/^[A-Z]{2}$/', $address['country_code']) !== 1) {
            abort(422, 'Landcode moet uit twee letters bestaan.');
        }
        if (filter_var($address['email'], FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul een geldig e-mailadres in.');
        }
        foreach (GlsService::ADDRESS_FIELD_LIMITS as $field => $maxLength) {
            if (mb_strlen($address[$field]) > $maxLength) {
                abort(422, sprintf('Adresveld %s mag maximaal %d tekens bevatten.', $field, $maxLength));
            }
        }
        $address['addressee_type'] = (string) ($delivery['address']['addressee_type'] ?? 'B');
        $shipType = (string) ($_POST['ship_type'] ?? '');
        $reference = trim((string) ($_POST['shipment_reference'] ?? ''));
        if (!in_array($shipType, ['P', 'F'], true)) {
            abort(422, 'Kies Parcel of Freight.');
        }
        $freightUnitType = (string) ($_POST['freight_unit_type'] ?? $delivery['freight_unit_type']);
        if (!isset(GlsService::FREIGHT_UNITS[$freightUnitType])) {
            abort(422, 'Kies een geldig vrachttype.');
        }
        if ($reference === '' || mb_strlen($reference) > GlsService::REFERENCE_MAX_LENGTH) {
            abort(422, 'Vul een referentie van maximaal 20 tekens in.');
        }
        $glsProduct = (string) ($_POST['gls_product'] ?? 'direct_delivery_plus');
        $selectedServices = is_array($_POST['gls_services'] ?? null)
            ? array_values(array_unique(array_map('strval', $_POST['gls_services'])))
            : [];
        $allowedServices = ['saturday', 'addressee_only', 'point_delivery_flex', 'point_return'];
        if (!in_array($glsProduct, GlsService::PRODUCTS, true)) {
            abort(422, 'Kies een geldig GLS-product.');
        }
        if (array_diff($selectedServices, $allowedServices) !== []) {
            abort(422, 'Er is een onbekende GLS-service gekozen.');
        }
        $glsOptions = [
            'saturday' => in_array('saturday', $selectedServices, true),
            'addressee_only' => in_array('addressee_only', $selectedServices, true),
            'point_delivery_flex' => in_array('point_delivery_flex', $selectedServices, true),
            'point_return' => in_array('point_return', $selectedServices, true),
            'parcel_shop_id' => trim((string) ($_POST['gls_parcel_shop_id'] ?? '')),
            'express_service' => strtolower(trim((string) ($_POST['gls_express_service'] ?? 't17'))),
        ];
        if ($shipType === 'F') {
            $glsProduct = 'direct_delivery_plus';
            $glsOptions = [];
        } else {
            if ($glsProduct === 'point_delivery' && $glsOptions['parcel_shop_id'] === '') {
                abort(422, 'Vul voor PointDelivery het GLS Point-ID in.');
            }
            if (strlen((string) $glsOptions['parcel_shop_id']) > 32) {
                abort(422, 'Het GLS Point-ID mag maximaal 32 tekens bevatten.');
            }
            if ($glsProduct === 'express_delivery' && !in_array($glsOptions['express_service'], GlsService::EXPRESS_SERVICES, true)) {
                abort(422, 'Kies een geldige ExpressDelivery-tijd.');
            }
            if ($glsOptions['point_delivery_flex'] && $glsProduct !== 'point_delivery') {
                abort(422, 'PointDeliveryFlex kan alleen samen met PointDelivery worden gekozen.');
            }
        }
        $weights = [];
        foreach ($_POST['weights'] ?? [] as $index => $rawWeight) {
            $normalizedWeight = trim(str_replace(',', '.', (string) $rawWeight));
            if ($normalizedWeight === '') {
                continue;
            }
            if (!is_numeric($normalizedWeight) || (float) $normalizedWeight < 1 || floor((float) $normalizedWeight) !== (float) $normalizedWeight) {
                abort(422, sprintf('Vul voor doos %d een gewicht in hele kilo\'s in.', $index + 1));
            }
            $weights[] = (float) (int) $normalizedWeight;
        }
        $maxWeight = $shipType === 'F'
            ? (GlsService::FREIGHT_UNITS[$freightUnitType] ?? 32)
            : 32;
        foreach ($weights as $index => $weight) {
            if ($weight > $maxWeight) {
                abort(422, sprintf('Doos %d is zwaarder dan %d kg.', $index + 1, $maxWeight));
            }
        }
        $repository->updateShippingDetails(
            $id,
            $shipType,
            $reference,
            $freightUnitType,
            $glsProduct,
            $glsOptions
        );
        $repository->updateAddress($id, $address);
        $repository->replaceParcels($id, $weights);
        flash(
            $weights === [] ? 'info' : 'success',
            $weights === []
                ? 'Adres en zending opgeslagen. Vul minimaal één doos met gewicht in om een label te kunnen maken.'
                : 'Adres, zending en dozen opgeslagen.'
        );
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/parcels$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if (!empty($delivery['merged_into_exact_entry_id'])) {
            abort(409, 'Bewerk de dozen via de hoofdlevering van deze samengevoegde zending.');
        }
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'De dozen kunnen na het aanmaken van labels niet meer worden gewijzigd.');
        }
        $weights = [];
        foreach ($_POST['weights'] ?? [] as $index => $rawWeight) {
            $normalizedWeight = trim(str_replace(',', '.', (string) $rawWeight));
            if ($normalizedWeight === '') {
                continue;
            }
            if (!is_numeric($normalizedWeight) || (float) $normalizedWeight < 1 || floor((float) $normalizedWeight) !== (float) $normalizedWeight) {
                abort(422, sprintf('Vul voor doos %d een gewicht in hele kilo\'s in.', $index + 1));
            }
            $weights[] = (float) (int) $normalizedWeight;
        }
        $maxWeight = $delivery['ship_type'] === 'F'
            ? (GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32)
            : 32;
        foreach ($weights as $index => $weight) {
            if ($weight > $maxWeight) {
                abort(422, sprintf('Doos %d is zwaarder dan %d kg.', $index + 1, $maxWeight));
            }
        }
        $repository->replaceParcels($id, $weights);
        flash(
            $weights === [] ? 'info' : 'success',
            $weights === [] ? 'Geen gewicht ingevuld; er is geen doos opgeslagen.' : count($weights) . ' doos/dozen opgeslagen.'
        );
        redirect('/deliveries/' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/collected$#', $path, $matches)) {
        Security::verifyCsrf();
        if (($_POST['confirm_collected'] ?? '') !== 'yes') {
            abort(422, 'Vink eerst aan dat deze levering is afgehaald.');
        }
        $id = rawurldecode($matches[1]);
        if (!$repository->markCollected($id)) {
            abort(409, 'Deze levering kan niet meer als afgehaald worden gemarkeerd.');
        }

        try {
            $delivery = $repository->find($id);
            if ($delivery === null) {
                throw new ExactException('De afgehaalde levering kon niet opnieuw worden geladen.');
            }
            $result = (new ExactService(Database::connect()))->syncDeliveryTracking($delivery, $repository);
            if ($result['errors'] !== []) {
                flash('warning', 'Afgehaald en niet naar GLS verzonden. Exact is nog niet volledig bijgewerkt: ' . implode(' | ', array_slice($result['errors'], 0, 3)));
            } else {
                flash('success', 'Levering afgehandeld als afgehaald. Er is geen GLS-zending aangemaakt; Exact is bijgewerkt.');
            }
        } catch (Throwable $error) {
            error_log('Afhaalnummer naar Exact schrijven: ' . $error->getMessage());
            flash('warning', 'Levering is afgehaald en niet naar GLS verzonden. Exact kon nog niet worden bijgewerkt: ' . $error->getMessage());
        }
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/merge$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $includedIds = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            is_array($_POST['merged_delivery_ids'] ?? null) ? $_POST['merged_delivery_ids'] : []
        )));
        try {
            $repository->setMergedDeliveries($id, $includedIds);
        } catch (InvalidArgumentException $error) {
            abort(422, $error->getMessage());
        }
        flash(
            $includedIds === [] ? 'info' : 'success',
            $includedIds === []
                ? 'Er zijn geen extra leveringen aan deze zending gekoppeld.'
                : count($includedIds) . ' extra levering(en) aan deze GLS-zending gekoppeld.'
        );
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/delete$#', $path, $matches)) {
        Security::verifyCsrf();
        if (($_POST['confirm_delete'] ?? '') !== 'yes' || ($_POST['supervisor_confirmation'] ?? '') !== 'yes') {
            abort(422, 'Bevestig eerst samen met uw supervisor het verwijderen van de zending.');
        }
        if (!$repository->discard(rawurldecode($matches[1]))) {
            abort(409, 'Deze zending kan niet worden gewist. Maak eerst de samenvoeging ongedaan of controleer of GLS-labels al zijn aangemaakt.');
        }
        flash('success', 'Zending uit de werkvoorraad verwijderd. Exact importeert deze niet opnieuw.');
        redirect('/');
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/labels$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'Deze levering is niet gereed voor labels.');
        }
        $missing = delivery_missing_data($delivery);
        if ($missing !== []) {
            abort(422, 'Vul eerst de ontbrekende gegevens in: ' . implode(', ', $missing) . '.');
        }
        $glsMode = strtolower((string) Env::get('GLS_MODE', 'mock'));
        if (!in_array($glsMode, ['mock', 'test', 'live'], true)) {
            abort(500, 'De GLS-modus is ongeldig geconfigureerd.');
        }
        if ($glsMode !== 'mock' && ($_POST['confirm_gls_request'] ?? '') !== 'yes') {
            abort(422, 'Bevestig eerst expliciet dat deze zending naar GLS mag worden verstuurd.');
        }
        $gls->validateLabelConfiguration();
        if ($glsMode === 'live' && ($_POST['shipping_date_confirmed'] ?? '') !== date('Y-m-d')) {
            abort(422, 'De verzenddatum is gewijzigd. Vernieuw de pagina en bevestig de zending opnieuw.');
        }
        $payload = $gls->buildPayload($delivery);
        $redactedPayload = $gls->redact($payload);
        if (!$repository->beginGlsRequest($id, $redactedPayload)) {
            abort(409, 'Deze zending wordt al door GLS verwerkt of is niet meer beschikbaar voor labelaanmaak.');
        }
        try {
            $result = $gls->createLabels($payload, $id);
        } catch (GlsException $error) {
            if ($error->outcomeUnknown) {
                $repository->markGlsOutcomeUnknown($id, $error->getMessage());
                flash(
                    'warning',
                    'De uitkomst bij GLS is onzeker. Maak de zending niet opnieuw aan; controleer eerst in GLS en laat haar daarna door een beheerder vrijgeven.'
                );
            } else {
                $repository->resetRejectedGlsRequest($id, $error->getMessage());
                flash('error', 'GLS heeft de aanvraag afgewezen: ' . $error->getMessage());
            }
            redirect('/?delivery=' . rawurlencode($id));
        } catch (Throwable $error) {
            error_log($error->__toString());
            if ($glsMode === 'mock') {
                $repository->resetRejectedGlsRequest($id, $error->getMessage());
                flash('error', 'Het afdrukbare testlabel kon niet worden gemaakt. De zending is vrijgegeven voor een nieuwe poging.');
            } else {
                $repository->markGlsOutcomeUnknown($id, $error->getMessage());
                flash(
                    'warning',
                    'De GLS-aanvraag gaf geen betrouwbaar eindresultaat. Maak geen tweede zending aan voordat GLS is gecontroleerd.'
                );
            }
            redirect('/?delivery=' . rawurlencode($id));
        }
        $repository->saveGlsResult(
            $id,
            $redactedPayload,
            $result['response'],
            $result['tracking_numbers'],
            $result['label_path']
        );
        if ($glsMode === 'mock') {
            flash('success', count($result['tracking_numbers']) . ' ongeldig(e) GLS-testlabel(s) aangemaakt; Exact is niet bijgewerkt.');
        } elseif ($glsMode === 'test') {
            flash('success', count($result['tracking_numbers']) . ' GLS-testlabel(s) aangemaakt; Exact is niet bijgewerkt.');
        } else {
            try {
                $deliveryWithTracking = $repository->find($id);
                if ($deliveryWithTracking === null) {
                    throw new ExactException('De opgeslagen levering kon niet opnieuw worden geladen.');
                }
                $exactResult = (new ExactService(Database::connect()))->syncDeliveryTracking(
                    $deliveryWithTracking,
                    $repository
                );
                if ($exactResult['errors'] !== []) {
                    flash(
                        'warning',
                        count($result['tracking_numbers']) . ' GLS-label(s) aangemaakt, maar niet alle trackingnummers zijn naar Exact geschreven. '
                        . implode(' | ', array_slice($exactResult['errors'], 0, 3))
                        . ' Gebruik de knop “Opnieuw naar Exact” en maak géén nieuwe GLS-zending.'
                    );
                } else {
                    flash(
                        'success',
                        count($result['tracking_numbers']) . ' GLS-label(s) aangemaakt en tracking naar '
                        . $exactResult['synced'] . ' Exact-levering(en) geschreven.'
                    );
                }
            } catch (Throwable $error) {
                error_log('Exact tracking terugschrijven na GLS-succes: ' . $error->getMessage());
                flash(
                    'warning',
                    count($result['tracking_numbers']) . ' GLS-label(s) zijn veilig opgeslagen, maar Exact kon niet worden bijgewerkt: '
                    . $error->getMessage() . ' Gebruik “Opnieuw naar Exact” en maak géén nieuwe GLS-zending.'
                );
            }
        }
        if (!empty($result['label_path'])) {
            redirect('/deliveries/' . rawurlencode($id) . '/label');
        }
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/tracking/exact$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if ($delivery['status'] === 'labels_created' && strtolower((string) Env::get('GLS_MODE', 'mock')) !== 'live') {
            abort(409, 'Tracking naar Exact schrijven is alleen beschikbaar in GLS-livemodus.');
        }
        if (!in_array($delivery['status'], ['labels_created', 'collected'], true) || $delivery['tracking_numbers'] === []) {
            abort(409, 'Deze levering heeft nog geen tracking- of afhaalnummer om naar Exact te schrijven.');
        }
        try {
            $result = (new ExactService(Database::connect()))->syncDeliveryTracking($delivery, $repository);
        } catch (Throwable $error) {
            error_log('Exact tracking handmatig terugschrijven: ' . $error->getMessage());
            flash('warning', 'Exact kon niet worden bijgewerkt: ' . $error->getMessage());
            redirect('/?delivery=' . rawurlencode($id));
        }
        if ($result['errors'] !== []) {
            flash('warning', 'Niet alle leveringen zijn naar Exact geschreven: ' . implode(' | ', array_slice($result['errors'], 0, 3)));
        } else {
            flash(
                'success',
                $result['synced'] > 0
                    ? $result['synced'] . ' Exact-levering(en) bijgewerkt met het tracking- of afhaalnummer.'
                    : 'Het tracking- of afhaalnummer stond al in Exact.'
            );
        }
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/labels/release$#', $path, $matches)) {
        Security::verifyCsrf();
        if ((Security::user()['role'] ?? '') !== 'admin') {
            abort(403, 'Alleen een beheerder kan een onzekere GLS-aanvraag vrijgeven.');
        }
        if (($_POST['confirm_no_gls_shipment'] ?? '') !== 'yes') {
            abort(422, 'Bevestig eerst dat in GLS is gecontroleerd dat er geen zending is aangemaakt.');
        }
        $id = rawurldecode($matches[1]);
        if (!$repository->releaseGlsRequestAfterCheck($id)) {
            abort(409, 'Deze zending heeft geen onzekere GLS-aanvraag die kan worden vrijgegeven.');
        }
        flash('success', 'De zending is na handmatige GLS-controle vrijgegeven voor een nieuwe poging.');
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'GET' && preg_match('#^/deliveries/([^/]+)/label$#', $path, $matches)) {
        $delivery = $repository->find(rawurldecode($matches[1]));
        if ($delivery === null || empty($delivery['label_path'])) {
            abort(404, 'Label niet gevonden.');
        }
        $filename = basename((string) $delivery['label_path']);
        $file = $root . '/storage/labels/' . $filename;
        if (!is_file($file)) {
            abort(404, 'Labelbestand niet gevonden.');
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    abort(404, 'Pagina niet gevonden.');
} catch (HttpException $error) {
    http_response_code($error->status);
    view('error', ['status' => $error->status, 'message' => $error->getMessage()]);
} catch (JsonException $error) {
    http_response_code(400);
    view('error', ['status' => 400, 'message' => 'Ongeldige JSON ontvangen.']);
} catch (Throwable $error) {
    error_log($error->__toString());
    http_response_code(500);
    $message = Env::get('APP_ENV', 'production') === 'local'
        ? $error->getMessage()
        : 'Er ging iets mis. De technische details zijn veilig gelogd.';
    view('error', ['status' => 500, 'message' => $message]);
}

