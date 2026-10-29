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
        view('deliveries/index', [
            'deliveries' => $repository->all(),
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
        $required = ['exact_entry_id', 'delivery_number', 'order_number', 'name', 'street', 'house_no', 'zip_code', 'city', 'country_code'];
        foreach ($required as $field) {
            if (trim((string) ($_POST[$field] ?? '')) === '') {
                abort(422, "Veld {$field} is verplicht.");
            }
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
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        view('deliveries/show', ['delivery' => $delivery]);
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/parcels$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if ($delivery['status'] === 'labels_created') {
            abort(409, 'De dozen kunnen na het aanmaken van labels niet meer worden gewijzigd.');
        }
        $weights = array_values(array_filter(
            array_map(static fn ($value): float => (float) str_replace(',', '.', (string) $value), $_POST['weights'] ?? []),
            static fn (float $value): bool => $value > 0
        ));
        if ($weights === []) {
            abort(422, 'Vul minimaal één geldig gewicht in.');
        }
        foreach ($weights as $index => $weight) {
            if ($weight > 32) {
                abort(422, sprintf('Doos %d is zwaarder dan 32 kg.', $index + 1));
            }
        }
        $repository->replaceParcels($id, $weights);
        flash('success', count($weights) . ' doos/dozen opgeslagen.');
        redirect('/deliveries/' . rawurlencode($id));
    }

    if ($method === 'POST' && preg_match('#^/deliveries/([^/]+)/labels$#', $path, $matches)) {
        Security::verifyCsrf();
        $id = rawurldecode($matches[1]);
        $delivery = $repository->find($id);
        if ($delivery === null) {
            abort(404, 'Levering niet gevonden.');
        }
        if ($delivery['status'] === 'labels_created') {
            abort(409, 'Voor deze levering zijn al labels gemaakt.');
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

