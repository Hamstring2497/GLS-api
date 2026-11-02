<div class="warehouse-page">
<div class="page-heading overview-heading">
    <div>
        <p class="eyebrow">Verzendoverzicht</p>
        <h1>Leveringen</h1>
        <p class="muted">Selecteer een regel, controleer het adres en voer de dozen in.</p>
    </div>
    <div class="overview-actions">
        <a class="button" href="/deliveries/new">Nieuwe levering</a>
        <?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
            <?php if (!$exactStatus['connected']): ?>
                <a class="button button-secondary" href="/oauth/exact/start">Exact koppelen</a>
            <?php else: ?>
                <form method="post" action="/exact/sync">
                    <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                    <button class="button" type="submit">Leveringen ophalen</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($deliveries === []): ?>
    <section class="empty-state">
        <h2>Nog geen leveringen</h2>
        <p>Haal leveringen op uit Exact of voeg een testlevering toe.</p>
        <a class="button" href="/deliveries/new">Testlevering toevoegen</a>
    </section>
<?php else: ?>
    <?php
    $deliveryNumbersById = [];
    foreach ($deliveries as $listedDelivery) {
        $deliveryNumbersById[(string) $listedDelivery['exact_entry_id']] = (string) $listedDelivery['delivery_number'];
    }
    $quickMergeCandidates = [];
    foreach (($mergeCandidates ?? []) as $candidate) {
        if (
            empty($candidate['merged_into_exact_entry_id'])
            && $candidate['status'] === 'new'
            && $candidate['parcels'] === []
        ) {
            $quickMergeCandidates[(string) $candidate['exact_entry_id']] = $candidate;
        }
    }
    ?>
    <div class="delivery-split">
    <section class="delivery-list-pane" aria-label="Leveringen">
      <div class="table-wrap delivery-sheet">
        <table>
            <thead><tr><th>Levering</th><th>Order</th><th>Datum</th><th>Toegevoegd</th><th>Ontvanger</th><th>Straat</th><th>Huisnr.</th><th>Postcode</th><th>Plaats</th><th>Land</th><th>Type</th><th>Vrachttype</th><th>Referentie</th><th>Dozen</th><th>Gewicht</th><th>Tracking</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <?php
                $mergedIntoId = (string) ($delivery['merged_into_exact_entry_id'] ?? '');
                $isQuickMergeCandidate = isset($quickMergeCandidates[(string) $delivery['exact_entry_id']]);
                $targetId = $mergedIntoId !== '' ? $mergedIntoId : (string) $delivery['exact_entry_id'];
                $deliveryUrl = '/?delivery=' . rawurlencode($targetId);
                $missingData = delivery_missing_data($delivery);
                if ($mergedIntoId !== '') {
                    $tableStatus = 'Bij levering ' . ($deliveryNumbersById[$mergedIntoId] ?? $mergedIntoId);
                    $tableStatusClass = 'merged';
                } elseif ($isQuickMergeCandidate) {
                    $tableStatus = 'Samenvoegen?';
                    $tableStatusClass = 'merge-question';
                } elseif ($delivery['status'] === 'labels_created') {
                    $tableStatus = 'Labels gemaakt';
                    $tableStatusClass = 'labels_created';
                } elseif ($delivery['status'] === 'gls_processing') {
                    $tableStatus = 'Bezig met GLS';
                    $tableStatusClass = 'processing';
                } elseif ($delivery['status'] === 'gls_unknown') {
                    $tableStatus = 'GLS-controle nodig';
                    $tableStatusClass = 'unknown';
                } elseif ($missingData !== []) {
                    $tableStatus = 'Ontbreekt: ' . implode(', ', $missingData);
                    $tableStatusClass = 'missing';
                } else {
                    $tableStatus = 'Klaar voor labels';
                    $tableStatusClass = 'ready';
                }
                ?>
                <tr class="delivery-row <?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'selected-row' : '' ?> <?= in_array($delivery['status'], ['ready', 'labels_created'], true) ? 'completed-row' : '' ?> <?= in_array($delivery['status'], ['gls_processing', 'gls_unknown'], true) ? 'gls-pending-row' : '' ?> <?= $isQuickMergeCandidate ? 'merge-candidate-row' : '' ?>" data-delivery-url="<?= e($deliveryUrl) ?>" data-merge-candidate="<?= $isQuickMergeCandidate ? '1' : '0' ?>" data-delivery-id="<?= e($delivery['exact_entry_id']) ?>" data-delivery-number="<?= e($delivery['delivery_number']) ?>" data-order-number="<?= e($delivery['order_number']) ?>" tabindex="0" aria-label="Selecteer levering <?= e($delivery['delivery_number']) ?>" aria-selected="<?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'true' : 'false' ?>">
                    <td><strong><?= e($delivery['delivery_number']) ?></strong></td>
                    <td><?= e($delivery['order_number']) ?></td>
                    <td><?= e($delivery['shipping_date'] ?: '—') ?></td>
                    <td><?= e(($delivery['created_at'] ?? '') !== '' ? substr((string) $delivery['created_at'], 0, 10) : '—') ?></td>
                    <td><?= e($delivery['address']['name']) ?></td>
                    <td><?= e($delivery['address']['street']) ?></td>
                    <td><?= e(trim((string) $delivery['address']['house_no'] . ' ' . (string) ($delivery['address']['house_no_addition'] ?? ''))) ?></td>
                    <td><?= e($delivery['address']['zip_code']) ?></td>
                    <td><?= e($delivery['address']['city']) ?></td>
                    <td><?= e($delivery['address']['country_code']) ?></td>
                    <td><?= $delivery['ship_type'] === 'F' ? 'Freight' : 'Parcel' ?></td>
                    <td><?= $delivery['ship_type'] === 'F' ? e($delivery['freight_unit_type']) : '—' ?></td>
                    <td><?= e($delivery['effective_reference'] ?: '—') ?></td>
                    <td><?= count($delivery['parcels']) ?></td>
                    <td><?= e(number_format(array_sum(array_map(static fn ($parcel): float => (float) $parcel['weight_kg'], $delivery['parcels'])), 1, ',', '.')) ?> kg</td>
                    <td><?= e(implode(', ', $delivery['tracking_numbers']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($tableStatusClass) ?>"><?= e($tableStatus) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
      </div>
    </section>
    <section class="delivery-detail-pane" aria-label="Geselecteerde levering">
        <?php if ($selectedDelivery): ?>
            <?php $delivery = $selectedDelivery; require __DIR__ . '/show.php'; ?>
        <?php endif; ?>
    </section>
    </div>
    <?php if ($selectedDelivery && $quickMergeCandidates !== []): ?>
        <form id="quick-merge-form" method="post" action="/deliveries/<?= rawurlencode((string) $selectedDelivery['exact_entry_id']) ?>/merge" hidden>
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <?php foreach (($selectedDelivery['merged_deliveries'] ?? []) as $mergedDelivery): ?>
                <input type="hidden" name="merged_delivery_ids[]" value="<?= e($mergedDelivery['exact_entry_id']) ?>">
            <?php endforeach; ?>
            <input id="quick-merge-delivery-id" type="hidden" name="merged_delivery_ids[]" value="">
        </form>
    <?php endif; ?>
    <script>
    (() => {
        let detailsDirty = false;
        const detailsPane = document.querySelector('.delivery-detail-pane');

        if (detailsPane) {
            detailsPane.addEventListener('input', event => {
                if (event.target.matches('input:not([type="hidden"]), select, textarea')) detailsDirty = true;
            });
            detailsPane.addEventListener('change', event => {
                if (event.target.matches('input:not([type="hidden"]), select, textarea')) detailsDirty = true;
            });
            detailsPane.addEventListener('click', event => {
                if (event.target.closest('.units-panel button[type="button"]')) detailsDirty = true;
            });
            detailsPane.querySelectorAll('form').forEach(form => form.addEventListener('submit', () => {
                detailsDirty = false;
            }));
        }

        const openDelivery = (row, event) => {
            const url = row.dataset.deliveryUrl;
            if (!url || url === `${location.pathname}${location.search}${location.hash}`) return;
            if (detailsDirty && !confirm('Er zijn nog niet-opgeslagen wijzigingen. Wilt u deze levering verlaten?')) {
                event?.preventDefault();
                return;
            }
            if (row.dataset.mergeCandidate === '1') {
                const deliveryNumber = row.dataset.deliveryNumber || '';
                const orderNumber = row.dataset.orderNumber || '';
                const merge = confirm(
                    `Levering ${deliveryNumber} (order ${orderNumber}) gaat naar hetzelfde adres.\n\n` +
                    'Samenvoegen met de geselecteerde zending?\n\n' +
                    'OK = samenvoegen · Annuleren = afzonderlijk openen'
                );
                if (merge) {
                    const form = document.getElementById('quick-merge-form');
                    const input = document.getElementById('quick-merge-delivery-id');
                    if (form && input) {
                        detailsDirty = false;
                        input.value = row.dataset.deliveryId || '';
                        form.submit();
                        return;
                    }
                }
            }
            detailsDirty = false;
            location.href = url;
        };

        document.querySelectorAll('.delivery-row').forEach(row => {
            row.addEventListener('click', event => {
                if (event.target.closest('button, input, select, textarea')) return;
                event.preventDefault();
                openDelivery(row, event);
            });
            row.addEventListener('keydown', event => {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDelivery(row, event);
                }
            });
        });

        window.addEventListener('beforeunload', event => {
            if (!detailsDirty) return;
            event.preventDefault();
            event.returnValue = '';
        });
    })();
    </script>
<?php endif; ?>
</div>

