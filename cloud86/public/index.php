<?php

declare(strict_types=1);

use App\Database;
use App\DeliveryRepository;
use App\Env;
use App\ExactException;
use App\ExactService;
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
        $selected = $selectedId !== '' ? $repository->find($selectedId) : ($deliveries[0] ?? null);
        if ($selectedId !== '' && ($selected === null || $selected['status'] === 'discarded')) {
            abort(404, 'Levering niet gevonden.');
        }
        view('deliveries/index', [
            'deliveries' => $deliveries,
            'selectedDelivery' => $selected,
            'exactStatus' => $exactStatus,
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
            flash('error', $message);
        } else {
            flash('success', $message);
        }
        redirect('/');
    }

    if ($method === 'GET' && $path === '/deliveries/new') {
        view('deliveries/new');
    }

    if ($method === 'POST' && $path === '/deliveries') {
        Security::verifyCsrf();
        $required = ['exact_entry_id', 'delivery_number', 'order_number', 'name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'];
        foreach ($required as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                abort(422, "Veld {$field} is verplicht.");
            }
        }
        if (filter_var(trim((string) $_POST['email']), FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul een geldig e-mailadres in.');
        }
        $repository->save(array_map(
            static fn ($value): string => trim((string) $value),
            $_POST
        ));
        flash('success', 'Levering is opgeslagen.');
        redirect('/deliveries/' . rawurlencode((string) $_POST['exact_entry_id']));
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
        $address['country_code'] = strtoupper($address['country_code']);
        if (preg_match('/^[A-Z]{2}$/', $address['country_code']) !== 1) {
            abort(422, 'Landcode moet uit twee letters bestaan.');
        }
        if (filter_var($address['email'], FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul een geldig e-mailadres in.');
        }
        foreach (['note_1', 'note_2'] as $field) {
            if (mb_strlen($address[$field]) > 50) {
                abort(422, 'Notitie 1 en Notitie 2 mogen maximaal 50 tekens bevatten.');
            }
        }
        $address['addressee_type'] = (string) ($delivery['address']['addressee_type'] ?? 'P');
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
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'De verzendgegevens kunnen na labelaanmaak niet meer worden gewijzigd.');
        }
        $shipType = (string) ($_POST['ship_type'] ?? '');
        $reference = trim((string) ($_POST['shipment_reference'] ?? ''));
        if (!in_array($shipType, ['P', 'F'], true)) {
            abort(422, 'Kies Parcel of Freight.');
        }
        $freightUnitType = (string) ($_POST['freight_unit_type'] ?? $delivery['freight_unit_type']);
        if (!isset(GlsService::FREIGHT_UNITS[$freightUnitType])) {
            abort(422, 'Kies een geldig vrachttype.');
        }
        if ($reference === '' || strlen($reference) > 64) {
            abort(422, 'Vul een referentie van maximaal 64 tekens in.');
        }
        $repository->updateShippingDetails($id, $shipType, $reference, $freightUnitType);
        flash('success', 'Verzendgegevens opgeslagen.');
        redirect('/?delivery=' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/parcels$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if (!in_array($delivery['status'], ['new', 'ready'], true)) {
            abort(409, 'De dozen kunnen na het aanmaken van labels niet meer worden gewijzigd.');
        }
        $weights = array_values(array_filter(
            array_map(static fn ($value): float => round((float) str_replace(',', '.', (string) $value), 1), $_POST['weights'] ?? []),
            static fn (float $value): bool => $value > 0
        ));
        $maxWeight = $delivery['ship_type'] === 'F'
            ? (GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32)
            : 32;
        foreach ($weights as $index => $weight) {
            if ($weight > $maxWeight) {
                abort(422, sprintf('Doos %d is zwaarder dan %d kg.', $index + 1, $maxWeight));
            }
        }
        $repository->replaceParcels($id, $weights);
        flash('success', count($weights) . ' doos/dozen opgeslagen.');
        redirect('/deliveries/' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/delete$#', $path, $matches)) {
        Security::verifyCsrf();
        if (($_POST['confirm_delete'] ?? '') !== 'yes') {
            abort(422, 'Bevestig eerst het verwijderen van de zending.');
        }
        if (!$repository->discard(rawurldecode($matches[1]))) {
            abort(409, 'Deze zending kan niet worden gewist. GLS-labels zijn mogelijk al aangemaakt.');
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
        if ($delivery['status'] !== 'ready') {
            abort(409, 'Deze levering is niet gereed voor labels.');
        }
        if (filter_var((string) ($delivery['address']['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'Vul eerst een geldig e-mailadres in bij het afleveradres.');
        }
        $payload = $gls->buildPayload($delivery);
        $result = $gls->createLabels($payload, $id);
        $repository->saveGlsResult(
            $id,
            $gls->redact($payload),
            $result['response'],
            $result['tracking_numbers'],
            $result['label_path']
        );
        flash('success', count($result['tracking_numbers']) . ' GLS-label(s) aangemaakt.');
        redirect('/deliveries/' . rawurlencode($id));
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

