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
            "SELECT * FROM deliveries WHERE status <> 'discarded' "
            . 'ORDER BY shipping_date DESC, created_at DESC, delivery_number DESC LIMIT 250'
        )->fetchAll();

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    /** @return array<string, mixed>|null */
    public function firstAddedWithoutTracking(): ?array
    {
        $rows = $this->db->query(
            "SELECT * FROM deliveries WHERE status <> 'discarded' "
            . 'AND merged_into_exact_entry_id IS NULL ORDER BY created_at ASC, delivery_number ASC'
        )->fetchAll();
        foreach ($rows as $row) {
            $delivery = $this->hydrate($row);
            if ($delivery['tracking_numbers'] === []) {
                return $delivery;
            }
        }
        return null;
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
            $referenceParts[] = $this->referenceFromOrder((string) $member['order_number']);
        }
        if (mb_strlen(implode(' / ', array_values(array_unique(array_filter($referenceParts))))) > GlsService::REFERENCE_MAX_LENGTH) {
            throw new \InvalidArgumentException('De gezamenlijke GLS-referentie is langer dan 20 tekens.');
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
        $defaultReference = $this->referenceFromOrder((string) $data['order_number']);
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

    /** @param array<string, bool|string> $glsOptions */
    public function updateShippingDetails(
        string $id,
        string $shipType,
        string $reference,
        string $freightUnitType = 'CO',
        string $glsProduct = 'direct_delivery_plus',
        array $glsOptions = []
    ): void
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                "UPDATE deliveries SET ship_type = ?, freight_unit_type = ?, gls_product = ?, "
                . "gls_options_json = ?, updated_at = CURRENT_TIMESTAMP "
                . "WHERE (exact_entry_id = ? OR merged_into_exact_entry_id = ?) AND status IN ('new', 'ready')"
            );
            $statement->execute([
                $shipType,
                $freightUnitType,
                $glsProduct,
                json_encode($glsOptions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $id,
                $id,
            ]);
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

    /** @return list<array<string, mixed>> */
    public function withoutTracking(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM deliveries ORDER BY shipping_date, created_at, delivery_number'
        )->fetchAll();

        $deliveries = array_map(fn (array $row): array => $this->hydrate($row, false), $rows);
        return array_values(array_filter(
            $deliveries,
            static fn (array $delivery): bool => $delivery['tracking_numbers'] === []
        ));
    }

    /**
     * Verwijder uitsluitend de vooraf geselecteerde leveringen die op het moment van verwijderen
     * nog steeds geen trackingnummer hebben. Zo kan een intussen afgeronde zending nooit worden
     * geraakt door een oudere opschoonselectie.
     *
     * @param list<string> $ids
     */
    public function deleteWithoutTracking(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('trim', $ids),
            static fn (string $id): bool => $id !== ''
        )));
        if ($ids === []) {
            return 0;
        }

        $this->db->beginTransaction();
        try {
            $deleteParcels = $this->db->prepare('DELETE FROM parcels WHERE exact_entry_id = ?');
            $detachChildren = $this->db->prepare(
                'UPDATE deliveries SET merged_into_exact_entry_id = NULL, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE merged_into_exact_entry_id = ?'
            );
            $deleteDelivery = $this->db->prepare('DELETE FROM deliveries WHERE exact_entry_id = ?');
            $deleted = 0;

            foreach ($ids as $id) {
                $delivery = $this->find($id);
                if ($delivery === null || $delivery['tracking_numbers'] !== []) {
                    continue;
                }
                $detachChildren->execute([$id]);
                $deleteParcels->execute([$id]);
                $deleteDelivery->execute([$id]);
                $deleted += $deleteDelivery->rowCount();
            }

            $this->db->commit();
            return $deleted;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $request */
    public function beginGlsRequest(string $id, array $request): bool
    {
        $this->db->beginTransaction();
        try {
            $statement = $this->db->prepare(
                "UPDATE deliveries SET status = 'gls_processing', gls_request_json = ?, "
                . "gls_response_json = NULL, last_gls_state = 'request_started', updated_at = CURRENT_TIMESTAMP "
                . "WHERE exact_entry_id = ? AND status IN ('new', 'ready')"
            );
            $statement->execute([
                json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $id,
            ]);
            if ($statement->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            $children = $this->db->prepare(
                "UPDATE deliveries SET status = 'gls_processing', gls_request_json = ?, "
                . "gls_response_json = NULL, last_gls_state = 'request_started', updated_at = CURRENT_TIMESTAMP "
                . 'WHERE merged_into_exact_entry_id = ?'
            );
            $children->execute([
                json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $id,
            ]);
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function markGlsOutcomeUnknown(string $id, string $message): void
    {
        $statement = $this->db->prepare(
            "UPDATE deliveries SET status = 'gls_unknown', gls_response_json = ?, "
            . "last_gls_state = 'outcome_unknown', updated_at = CURRENT_TIMESTAMP "
            . 'WHERE exact_entry_id = ? OR merged_into_exact_entry_id = ?'
        );
        $statement->execute([
            json_encode(['error' => $message, 'outcome_unknown' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $id,
            $id,
        ]);
    }

    public function resetRejectedGlsRequest(string $id, string $message): void
    {
        $this->resetGlsRequest($id, $message, 'request_rejected');
    }

    public function releaseGlsRequestAfterCheck(string $id): bool
    {
        $delivery = $this->find($id);
        if ($delivery === null || !in_array($delivery['status'], ['gls_processing', 'gls_unknown'], true)) {
            return false;
        }
        $this->resetGlsRequest($id, 'Handmatig vrijgegeven na controle bij GLS.', 'released_after_check');
        return true;
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
            . "last_gls_state = 'labels_created', updated_at = CURRENT_TIMESTAMP "
            . 'WHERE exact_entry_id = ? OR merged_into_exact_entry_id = ?'
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

    public function markCollected(string $id): bool
    {
        $delivery = $this->find($id);
        if (
            $delivery === null
            || !in_array($delivery['status'], ['new', 'ready'], true)
            || !empty($delivery['merged_into_exact_entry_id'])
        ) {
            return false;
        }

        $trackingNumber = 'AFHALEN-' . preg_replace(
            '/[^A-Za-z0-9_-]/',
            '',
            (string) $delivery['delivery_number']
        );
        $response = [
            'source' => 'PietPakket',
            'collected' => true,
            'tracking_number' => $trackingNumber,
        ];

        $statement = $this->db->prepare(
            "UPDATE deliveries SET status = 'collected', tracking_numbers_json = ?, "
            . 'gls_request_json = NULL, gls_response_json = ?, label_path = NULL, '
            . "last_gls_state = 'collected', exact_tracking_synced_at = NULL, updated_at = CURRENT_TIMESTAMP "
            . "WHERE (exact_entry_id = ? OR merged_into_exact_entry_id = ?) AND status IN ('new', 'ready')"
        );
        $statement->execute([
            json_encode([$trackingNumber], JSON_THROW_ON_ERROR),
            json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $id,
            $id,
        ]);

        return $statement->rowCount() >= 1;
    }

    public function markExactTrackingSynced(string $id): void
    {
        $statement = $this->db->prepare(
            'UPDATE deliveries SET exact_tracking_synced_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE exact_entry_id = ?'
        );
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('De lokale Exact-terugkoppeling kon niet worden bevestigd.');
        }
    }

    /**
     * Koppelt buiten deze werkplek aangemaakte GLS-zendingen aan bestaande Exact-leveringen.
     *
     * @param list<array{delivery_ids: list<string>, tracking_numbers: list<string>}> $assignments
     */
    public function importExistingTracking(array $assignments): int
    {
        $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $select = $this->db->prepare(
                'SELECT exact_entry_id, status, tracking_numbers_json FROM deliveries '
                . 'WHERE exact_entry_id = ? OR delivery_number = ?' . $lock
            );
            $update = $this->db->prepare(
                "UPDATE deliveries SET status = 'labels_created', tracking_numbers_json = ?, "
                . 'gls_response_json = ?, label_path = NULL, last_gls_state = ?, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE exact_entry_id = ?'
            );
            $updated = 0;

            foreach ($assignments as $assignment) {
                $numbers = array_values(array_unique(array_filter(array_map(
                    static fn (string $number): string => trim($number),
                    $assignment['tracking_numbers']
                ))));
                if ($numbers === []) {
                    throw new \InvalidArgumentException('Vul minimaal één GLS-trackingnummer in.');
                }
                foreach ($numbers as $number) {
                    if (!preg_match('/^\d{8,32}$/', $number)) {
                        throw new \InvalidArgumentException('GLS-trackingnummers mogen alleen uit 8 tot 32 cijfers bestaan.');
                    }
                }

                foreach (array_values(array_unique($assignment['delivery_ids'])) as $deliveryId) {
                    $select->execute([$deliveryId, $deliveryId]);
                    $row = $select->fetch();
                    if (!$row) {
                        throw new \InvalidArgumentException("Levering {$deliveryId} bestaat niet.");
                    }
                    if (in_array((string) $row['status'], ['gls_processing', 'gls_unknown'], true)) {
                        throw new \InvalidArgumentException(
                            "Levering {$deliveryId} heeft eerst handmatige GLS-controle nodig."
                        );
                    }
                    $existing = json_decode((string) $row['tracking_numbers_json'], true, 512, JSON_THROW_ON_ERROR);
                    if ($existing !== [] && $existing !== $numbers) {
                        throw new \InvalidArgumentException(
                            "Levering {$deliveryId} heeft al andere trackingnummers."
                        );
                    }

                    $response = [
                        'source' => 'GLS Track & Trace',
                        'imported_existing_shipment' => true,
                        'tracking_numbers' => $numbers,
                    ];
                    $update->execute([
                        json_encode($numbers, JSON_THROW_ON_ERROR),
                        json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'tracking_imported',
                        (string) $row['exact_entry_id'],
                    ]);
                    $updated += $update->rowCount();
                }
            }

            $this->db->commit();
            return $updated;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    private function resetGlsRequest(string $id, string $message, string $state): void
    {
        $this->db->beginTransaction();
        try {
            $parcelCount = $this->db->prepare('SELECT COUNT(*) FROM parcels WHERE exact_entry_id = ?');
            $parcelCount->execute([$id]);
            $primaryStatus = (int) $parcelCount->fetchColumn() > 0 ? 'ready' : 'new';
            $response = json_encode(['message' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $primary = $this->db->prepare(
                'UPDATE deliveries SET status = ?, gls_response_json = ?, last_gls_state = ?, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE exact_entry_id = ?'
            );
            $primary->execute([$primaryStatus, $response, $state, $id]);
            $children = $this->db->prepare(
                "UPDATE deliveries SET status = 'new', gls_response_json = ?, last_gls_state = ?, "
                . 'updated_at = CURRENT_TIMESTAMP WHERE merged_into_exact_entry_id = ?'
            );
            $children->execute([$response, $state, $id]);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
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
        $glsOptions = !empty($row['gls_options_json'])
            ? json_decode((string) $row['gls_options_json'], true, 512, JSON_THROW_ON_ERROR)
            : [];
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
                : $this->referenceFromOrder((string) $row['order_number']),
        ];
        foreach ($mergedDeliveries as $mergedDelivery) {
            $referenceParts[] = $this->referenceFromOrder((string) $mergedDelivery['order_number']);
        }

        return array_merge($row, [
            'ship_type' => $row['ship_type'] ?? 'P',
            'freight_unit_type' => $row['freight_unit_type'] ?? 'CO',
            'gls_product' => $row['gls_product'] ?? 'direct_delivery_plus',
            'gls_options' => is_array($glsOptions) ? $glsOptions : [],
            'shipment_reference' => ($row['shipment_reference'] ?? '') !== ''
                ? $row['shipment_reference']
                : $this->referenceFromOrder((string) $row['order_number']),
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

    private function referenceFromOrder(string $orderNumber): string
    {
        $digits = preg_replace('/\D/', '', $orderNumber) ?? '';
        return substr($digits, -4);
    }
}

