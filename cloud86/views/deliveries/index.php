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
    <div class="delivery-split">
    <section class="delivery-list-pane" aria-label="Leveringen">
      <div class="table-wrap delivery-sheet">
        <table>
            <thead><tr><th>Levering</th><th>Order</th><th>Datum</th><th>Toegevoegd</th><th>Ontvanger</th><th>Straat</th><th>Huisnr.</th><th>Postcode</th><th>Plaats</th><th>Land</th><th>Type</th><th>Vrachttype</th><th>Referentie</th><th>Dozen</th><th>Gewicht</th><th>Tracking</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <?php $deliveryUrl = '/?delivery=' . rawurlencode((string) $delivery['exact_entry_id']); ?>
                <tr class="delivery-row <?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'selected-row' : '' ?> <?= in_array($delivery['status'], ['ready', 'labels_created'], true) ? 'completed-row' : '' ?>" data-delivery-url="<?= e($deliveryUrl) ?>" tabindex="0" aria-selected="<?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'true' : 'false' ?>">
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
                    <td><?= e($delivery['shipment_reference'] ?: '—') ?></td>
                    <td><?= count($delivery['parcels']) ?></td>
                    <td><?= e(number_format(array_sum(array_map(static fn ($parcel): float => (float) $parcel['weight_kg'], $delivery['parcels'])), 1, ',', '.')) ?> kg</td>
                    <td><?= e(implode(', ', $delivery['tracking_numbers']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($delivery['status']) ?>"><?= e(['new' => 'Nieuw', 'ready' => 'Klaar voor labels', 'labels_created' => 'Labels gemaakt'][$delivery['status']] ?? $delivery['status']) ?></span></td>
                    <td><a href="<?= e($deliveryUrl) ?>">Openen →</a></td>
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

