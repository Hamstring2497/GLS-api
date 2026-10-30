<?php

declare(strict_types=1);

use App\Env;
use App\GlsService;

$root = require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag alleen via de terminal worden uitgevoerd.\n");
    exit(1);
}

$mode = strtolower((string) Env::get('GLS_MODE', 'mock'));
if ($mode !== 'test') {
    fwrite(STDERR, "Verbinding niet getest: zet GLS_MODE uitsluitend voor deze acceptatietest op test.\n");
    exit(1);
}

try {
    $response = (new GlsService($root . '/storage/labels'))->validateLogin();
} catch (Throwable $error) {
    fwrite(STDERR, "GLS-login mislukt: {$error->getMessage()}\n");
    exit(1);
}

$transactionId = trim((string) ($response['transactionId'] ?? ''));
fwrite(STDOUT, "GLS-testlogin geslaagd.\n");
if ($transactionId !== '') {
    fwrite(STDOUT, "Transactie-ID: {$transactionId}\n");
}
