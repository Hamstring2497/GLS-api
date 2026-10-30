<?php

declare(strict_types=1);

namespace App;

use PDO;

final class DeliveryRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $rows = $this->db->query(
            "SELECT * FROM deliveries WHERE status <> 'discarded' ORDER BY created_at DESC LIMIT 250"
        )->fetchAll();

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM deliveries WHERE exact_entry_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @param array<string, string|null> $data */
    public function save(array $data): void
    {
        $existing = $this->find((string) $data['exact_entry_id']);
        $digits = preg_replace('/\D/', '', (string) $data['order_number']);
        $defaultReference = substr((string) $digits, -4);
        $address = json_encode([
            'name' => trim((string) $data['name']),
            'name_2' => trim((string) ($data['name_2'] ?? '')),
            'name_3' => trim((string) ($data['name_3'] ?? '')),
            'street' => trim((string) $data['street']),
            'house_no' => trim((string) $data['house_no']),
            'house_no_addition' => trim((string) ($data['house_no_addition'] ?? '')),
            'zip_code' => trim((string) $data['zip_code']),
            'city' => trim((string) $data['city']),
            'country_code' => strtoupper(trim((string) $data['country_code'])),
            'contact' => trim((string) ($data['contact'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'note_1' => trim((string) ($data['note_1'] ?? '')),
            'note_2' => trim((string) ($data['note_2'] ?? '')),
            'addressee_type' => 'P',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $statement = $this->db->prepare(
                'UPDATE deliveries SET delivery_number = ?, order_number = ?, address_json = ?, '
                . 'shipping_date = ?, updated_at = CURRENT_TIMESTAMP WHERE exact_entry_id = ?'
            );
            $statement->execute([
                $data['delivery_number'], $data['order_number'], $address,
                $data['shipping_date'] ?: null, $data['exact_entry_id'],
            ]);
            return;
        }

        $statement = $this->db->prepare(
            'INSERT INTO deliveries '
            . '(exact_entry_id, delivery_number, order_number, ship_type, shipment_reference, address_json, shipping_date, status, tracking_numbers_json) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['exact_entry_id'], $data['delivery_number'], $data['order_number'], 'P', $defaultReference, $address,
            $data['shipping_date'] ?: null, 'new', '[]',
        ]);
    }

    public function updateShippingDetails(string $id, string $shipType, string $reference, string $freightUnitType = 'CO'): void
    {
        $statement = $this->db->prepare(
            "UPDATE deliveries SET ship_type = ?, freight_unit_type = ?, shipment_reference = ?, updated_at = CURRENT_TIMESTAMP "
            . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
        );
        $statement->execute([$shipType, $freightUnitType, $reference, $id]);
    }

    /** @param list<float> $weights */
    public function replaceParcels(string $id, array $weights): void
    {
        $this->db->beginTransaction();
        try {
            $delete = $this->db->prepare('DELETE FROM parcels WHERE exact_entry_id = ?');
            $delete->execute([$id]);
            $insert = $this->db->prepare(
                'INSERT INTO parcels (exact_entry_id, position, weight_kg) VALUES (?, ?, ?)'
            );
            foreach ($weights as $index => $weight) {
                $insert->execute([$id, $index + 1, $weight]);
            }
            $update = $this->db->prepare(
                "UPDATE deliveries SET status = ?, updated_at = CURRENT_TIMESTAMP "
                . 'WHERE exact_entry_id = ?'
            );
            $update->execute([$weights === [] ? 'new' : 'ready', $id]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, string> $address */
    public function updateAddress(string $id, array $address): void
    {
        $statement = $this->db->prepare(
            "UPDATE deliveries SET address_json = ?, updated_at = CURRENT_TIMESTAMP "
            . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
        );
        $statement->execute([
            json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $id,
        ]);
    }

    public function discard(string $id): bool
    {
        $statement = $this->db->prepare(
            "UPDATE deliveries SET status = 'discarded', updated_at = CURRENT_TIMESTAMP "
            . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $request @param array<string, mixed> $response @param list<string> $numbers */
    public function saveGlsResult(
        string $id,
        array $request,
        array $response,
        array $numbers,
        ?string $labelPath
    ): void {
        $statement = $this->db->prepare(
            "UPDATE deliveries SET status = 'labels_created', tracking_numbers_json = ?, "
            . 'gls_request_json = ?, gls_response_json = ?, label_path = ?, '
            . 'updated_at = CURRENT_TIMESTAMP WHERE exact_entry_id = ?'
        );
        $statement->execute([
            json_encode($numbers, JSON_THROW_ON_ERROR),
            json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $labelPath,
            $id,
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function recordWebhook(array $payload): bool
    {
        $eventKey = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        try {
            $statement = $this->db->prepare(
                'INSERT INTO webhook_events (event_key, parcel_no, payload_json) VALUES (?, ?, ?)'
            );
            $statement->execute([
                $eventKey,
                (string) ($payload['parcelNo'] ?? ''),
                json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\PDOException $error) {
            if ((string) $error->getCode() === '23000') {
                return false;
            }
            throw $error;
        }

        $parcelNo = trim((string) ($payload['parcelNo'] ?? ''));
        if ($parcelNo !== '') {
            $statement = $this->db->prepare(
                'UPDATE deliveries SET last_gls_state = ?, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE JSON_CONTAINS(tracking_numbers_json, JSON_QUOTE(?))'
            );
            $statement->execute([(string) ($payload['state'] ?? ''), $parcelNo]);
        }

        return true;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function hydrate(array $row): array
    {
        $address = json_decode((string) $row['address_json'], true, 512, JSON_THROW_ON_ERROR);
        $numbers = json_decode((string) $row['tracking_numbers_json'], true, 512, JSON_THROW_ON_ERROR);
        $statement = $this->db->prepare(
            'SELECT position, weight_kg FROM parcels WHERE exact_entry_id = ? ORDER BY position'
        );
        $statement->execute([$row['exact_entry_id']]);

        return array_merge($row, [
            'ship_type' => $row['ship_type'] ?? 'P',
            'freight_unit_type' => $row['freight_unit_type'] ?? 'CO',
            'shipment_reference' => ($row['shipment_reference'] ?? '') !== ''
                ? $row['shipment_reference']
                : substr((string) preg_replace('/\D/', '', (string) $row['order_number']), -4),
            'address' => $address,
            'tracking_numbers' => $numbers,
            'parcels' => $statement->fetchAll(),
        ]);
    }
}

