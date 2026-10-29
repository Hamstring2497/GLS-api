<?php

declare(strict_types=1);

use App\Database;
use App\UserRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag alleen via de terminal worden uitgevoerd.\n");
    exit(1);
}

$username = trim((string) ($argv[1] ?? ''));
$displayName = trim((string) ($argv[2] ?? $username));
$role = trim((string) ($argv[3] ?? 'warehouse'));
if ($username === '') {
    fwrite(STDERR, "Gebruik: php bin/create-user.php gebruikersnaam \"Volledige naam\" [admin|warehouse]\n");
    exit(1);
}

// 10 willekeurige tekens met gegarandeerd iedere vereiste categorie.
$lower = 'abcdefghijkmnopqrstuvwxyz';
$upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
$digits = '23456789';
$symbols = '!@#$%*-_=+';
$all = $lower . $upper . $digits . $symbols;
$password = $lower[random_int(0, strlen($lower) - 1)]
    . $upper[random_int(0, strlen($upper) - 1)]
    . $digits[random_int(0, strlen($digits) - 1)]
    . $symbols[random_int(0, strlen($symbols) - 1)];
while (strlen($password) < 10) {
    $password .= $all[random_int(0, strlen($all) - 1)];
}
$characters = str_split($password);
for ($index = count($characters) - 1; $index > 0; $index--) {
    $swap = random_int(0, $index);
    [$characters[$index], $characters[$swap]] = [$characters[$swap], $characters[$index]];
}
$password = implode('', $characters);

try {
    (new UserRepository(Database::connect()))->create($username, $displayName, $password, $role);
} catch (Throwable $error) {
    fwrite(STDERR, "Gebruiker niet aangemaakt: {$error->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "Gebruiker aangemaakt: {$username}\n");
fwrite(STDOUT, "Wachtwoord: {$password}\n");
fwrite(STDOUT, "Sla dit nu op in een wachtwoordmanager; het wordt niet opnieuw getoond.\n");

