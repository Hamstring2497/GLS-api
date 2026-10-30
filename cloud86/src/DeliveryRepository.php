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

    /** @return list<array<string, mixed>> */
    public function mergeCandidates(string $primaryId): array
    {
        $primary = $this->find($primaryId);
        if ($primary === null || !empty($primary['merged_into_exact_entry_id'])) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            fn (array $candidate): bool =>
                $candidate['exact_entry_id'] !== $primaryId
                && in_array($candidate['status'], ['new', 'ready'], true)
                && (empty($candidate['merged_into_exact_entry_id']) || $candidate['merged_into_exact_entry_id'] === $primaryId)
                && ($candidate['merged_deliveries'] ?? []) === []
                && $this->sameDestination($primary, $candidate)
        ));
    }

    /** @param list<string> $includedIds */
    public function setMergedDeliveries(string $primaryId, array $includedIds): void
    {
        $includedIds = array_values(array_unique(array_filter(
            array_map('trim', $includedIds),
            static fn (string $id): bool => $id !== '' && $id !== $primaryId
        )));
        $primary = $this->find($primaryId);
        if ($primary === null || !in_array($primary['status'], ['new', 'ready'], true)) {
            throw new \InvalidArgumentException('De hoofdlevering kan niet meer worden samengevoegd.');
        }
        if (!empty($primary['merged_into_exact_entry_id'])) {
            throw new \InvalidArgumentException('Een gekoppelde levering kan niet zelf als hoofdlevering worden gebruikt.');
        }

        $members = [];
        foreach ($includedIds as $includedId) {
            $candidate = $this->find($includedId);
            if ($candidate === null || !in_array($candidate['status'], ['new', 'ready'], true)) {
                throw new \InvalidArgumentException('Een gekozen levering is niet meer beschikbaar om samen te voegen.');
            }
            if (!empty($candidate['merged_into_exact_entry_id']) && $candidate['merged_into_exact_entry_id'] !== $primaryId) {
                throw new \InvalidArgumentException('Een gekozen levering hoort al bij een andere zending.');
            }
            if (($candidate['merged_deliveries'] ?? []) !== []) {
                throw new \InvalidArgumentException('Een hoofdlevering met gekoppelde leveringen kan niet worden toegevoegd.');
            }
            if (!$this->sameDestination($primary, $candidate)) {
                throw new \InvalidArgumentException('Leveringen kunnen alleen worden samengevoegd bij exact hetzelfde afleveradres.');
            }
            if ($candidate['ship_type'] !== $primary['ship_type']) {
                throw new \InvalidArgumentException('Samengevoegde leveringen moeten hetzelfde verzendtype hebben.');
            }
            if ($candidate['parcels'] !== []) {
                throw new \InvalidArgumentException(
                    'Verwijder eerst de dozen bij levering ' . $candidate['delivery_number']
                    . '; voer daarna alle gezamenlijke dozen bij de hoofdlevering in.'
                );
            }
            $members[] = $candidate;
        }

        $referenceParts = [trim((string) $primary['shipment_reference'])];
        foreach ($members as $member) {
            $referenceParts[] = trim((string) $member['order_number']);
        }
        if (strlen(implode(' / ', array_values(array_unique(array_filter($referenceParts))))) > 64) {
            throw new \InvalidArgumentException('De gezamenlijke GLS-referentie is langer dan 64 tekens.');
        }

        $this->db->beginTransaction();
        try {
            $detach = $this->db->prepare(
                'UPDATE deliveries SET merged_into_exact_entry_id = NULL, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE merged_into_exact_entry_id = ?'
            );
            $detach->execute([$primaryId]);
            $attach = $this->db->prepare(
                'UPDATE deliveries SET merged_into_exact_entry_id = ?, updated_at = CURRENT_TIMESTAMP '
                . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
            );
            foreach ($includedIds as $includedId) {
                $attach->execute([$primaryId, $includedId]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
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
            'addressee_type' => 'B',
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
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                "UPDATE deliveries SET ship_type = ?, freight_unit_type = ?, updated_at = CURRENT_TIMESTAMP "
                . "WHERE (exact_entry_id = ? OR merged_into_exact_entry_id = ?) AND status IN ('new', 'ready')"
            );
            $statement->execute([$shipType, $freightUnitType, $id, $id]);
            $referenceStatement = $this->db->prepare(
                "UPDATE deliveries SET shipment_reference = ?, updated_at = CURRENT_TIMESTAMP "
                . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
            );
            $referenceStatement->execute([$reference, $id]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
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
            . "WHERE (exact_entry_id = ? OR merged_into_exact_entry_id = ?) AND status IN ('new', 'ready')"
        );
        $statement->execute([
            json_encode($address, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $id,
            $id,
        ]);
    }

    public function discard(string $id): bool
    {
        $children = $this->db->prepare('SELECT COUNT(*) FROM deliveries WHERE merged_into_exact_entry_id = ?');
        $children->execute([$id]);
        if ((int) $children->fetchColumn() > 0) {
            return false;
        }
        $statement = $this->db->prepare(
            "UPDATE deliveries SET status = 'discarded', updated_at = CURRENT_TIMESTAMP "
            . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
        );
        $statement->execute([$id]);
        return $statement->rowCount() === 1;
    }

    /**
     * Verwijder de volledige lokale werkvoorraad. Exact-tokens, gebruikers en
     * webhookhistorie vallen bewust buiten deze onderhoudsactie.
     */
    public function clearAll(): int
    {
        $this->db->beginTransaction();
        try {
            $count = (int) $this->db->query('SELECT COUNT(*) FROM deliveries')->fetchColumn();
            // Expliciet verwijderen houdt deze actie ook correct in testomgevingen
            // waar foreign-key cascades niet zijn ingeschakeld.
            $this->db->exec('DELETE FROM parcels');
            $this->db->exec('DELETE FROM deliveries');
            $this->db->commit();
            return $count;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
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
            . 'updated_at = CURRENT_TIMESTAMP WHERE exact_entry_id = ? OR merged_into_exact_entry_id = ?'
        );
        $statement->execute([
            json_encode($numbers, JSON_THROW_ON_ERROR),
            json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $labelPath,
            $id,
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
    private function hydrate(array $row, bool $includeMerged = true): array
    {
        $address = json_decode((string) $row['address_json'], true, 512, JSON_THROW_ON_ERROR);
        $numbers = json_decode((string) $row['tracking_numbers_json'], true, 512, JSON_THROW_ON_ERROR);
        $statement = $this->db->prepare(
            'SELECT position, weight_kg FROM parcels WHERE exact_entry_id = ? ORDER BY position'
        );
        $statement->execute([$row['exact_entry_id']]);

        $mergedDeliveries = [];
        if ($includeMerged && empty($row['merged_into_exact_entry_id'])) {
            $mergedStatement = $this->db->prepare(
                'SELECT * FROM deliveries WHERE merged_into_exact_entry_id = ? ORDER BY created_at, delivery_number'
            );
            $mergedStatement->execute([$row['exact_entry_id']]);
            $mergedDeliveries = array_map(
                fn (array $mergedRow): array => $this->hydrate($mergedRow, false),
                $mergedStatement->fetchAll()
            );
        }
        $referenceParts = [
            ($row['shipment_reference'] ?? '') !== ''
                ? (string) $row['shipment_reference']
                : substr((string) preg_replace('/\D/', '', (string) $row['order_number']), -4),
        ];
        foreach ($mergedDeliveries as $mergedDelivery) {
            $referenceParts[] = (string) $mergedDelivery['order_number'];
        }

        return array_merge($row, [
            'ship_type' => $row['ship_type'] ?? 'P',
            'freight_unit_type' => $row['freight_unit_type'] ?? 'CO',
            'shipment_reference' => ($row['shipment_reference'] ?? '') !== ''
                ? $row['shipment_reference']
                : substr((string) preg_replace('/\D/', '', (string) $row['order_number']), -4),
            'address' => $address,
            'tracking_numbers' => $numbers,
            'parcels' => $statement->fetchAll(),
            'merged_deliveries' => $mergedDeliveries,
            'effective_reference' => implode(
                ' / ',
                array_values(array_unique(array_filter(array_map('trim', $referenceParts))))
            ),
        ]);
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function sameDestination(array $left, array $right): bool
    {
        $normalize = static fn (mixed $value): string => preg_replace(
            '/[^\p{L}\p{N}]+/u',
            '',
            mb_strtolower(trim((string) $value))
        ) ?? '';
        foreach (['street', 'house_no', 'house_no_addition', 'zip_code', 'city', 'country_code'] as $field) {
            if ($normalize($left['address'][$field] ?? '') !== $normalize($right['address'][$field] ?? '')) {
                return false;
            }
        }
        return true;
    }
}

