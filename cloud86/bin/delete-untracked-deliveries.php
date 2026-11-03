<?php

declare(strict_types=1);

use App\Database;
use App\DeliveryRepository;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dit script mag alleen via de terminal worden uitgevoerd.\n");
    exit(1);
}

$execute = in_array('--execute', $argv, true);
$repository = new DeliveryRepository(Database::connect());
$deliveries = $repository->withoutTracking();

if ($deliveries === []) {
    fwrite(STDOUT, "Geen leveringen zonder trackingnummer gevonden.\n");
    exit(0);
}

fwrite(STDOUT, "Leveringen zonder trackingnummer: " . count($deliveries) . "\n");
foreach ($deliveries as $delivery) {
    fwrite(STDOUT, sprintf(
        "- %s | %s | %s | %s\n",
        (string) $delivery['delivery_number'],
        (string) ($delivery['shipping_date'] ?? 'geen datum'),
        (string) $delivery['status'],
        (string) $delivery['exact_entry_id']
    ));
}

if (!$execute) {
    fwrite(STDOUT, "Controlemodus: niets verwijderd. Gebruik --execute om exact deze selectie op te schonen.\n");
    exit(0);
}

$ids = array_map(
    static fn (array $delivery): string => (string) $delivery['exact_entry_id'],
    $deliveries
);
$deleted = $repository->deleteWithoutTracking($ids);
fwrite(STDOUT, "Verwijderd: {$deleted} levering(en) zonder trackingnummer.\n");
