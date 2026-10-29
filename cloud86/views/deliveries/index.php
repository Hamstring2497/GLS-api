<div class="page-heading">
    <div>
        <p class="eyebrow">Verzendoverzicht</p>
        <h1>Leveringen</h1>
        <p class="muted">Selecteer een regel, controleer het adres en voer de dozen in.</p>
    </div>
    <a class="button" href="/deliveries/new">Nieuwe levering</a>
</div>

<?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
    <section class="connection-bar <?= $exactStatus['connected'] ? 'connection-ok' : '' ?>">
        <div>
            <strong>Exact Online</strong>
            <?php if ($exactStatus['connected']): ?>
                <span>Gekoppeld aan administratie <?= e($exactStatus['division']) ?></span>
            <?php else: ?>
                <span>Nog niet gekoppeld</span>
            <?php endif; ?>
        </div>
        <?php if (!$exactStatus['connected']): ?>
            <a class="button button-small" href="/oauth/exact/start">Exact koppelen</a>
        <?php else: ?>
            <form method="post" action="/exact/sync">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <button class="button button-small" type="submit">Leveringen ophalen</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($deliveries === []): ?>
    <section class="empty-state">
        <h2>Nog geen leveringen</h2>
        <p>Haal leveringen op uit Exact of voeg een testlevering toe.</p>
        <a class="button" href="/deliveries/new">Testlevering toevoegen</a>
    </section>
<?php else: ?>
    <div class="table-wrap delivery-sheet">
        <table>
            <thead><tr><th>Levering</th><th>Order</th><th>Datum</th><th>Ontvanger</th><th>Straat</th><th>Huisnr.</th><th>Postcode</th><th>Plaats</th><th>Land</th><th>Type</th><th>Vrachttype</th><th>Referentie</th><th>Eenheden</th><th>Gewicht</th><th>Tracking</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <tr class="<?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'selected-row' : '' ?> <?= in_array($delivery['status'], ['ready', 'labels_created'], true) ? 'completed-row' : '' ?>">
                    <td><strong><?= e($delivery['delivery_number']) ?></strong></td>
                    <td><?= e($delivery['order_number']) ?></td>
                    <td><?= e($delivery['shipping_date'] ?: '—') ?></td>
                    <td><?= e($delivery['address']['name']) ?></td>
                    <td><?= e($delivery['address']['street']) ?></td>
                    <td><?= e($delivery['address']['house_no']) ?></td>
                    <td><?= e($delivery['address']['zip_code']) ?></td>
                    <td><?= e($delivery['address']['city']) ?></td>
                    <td><?= e($delivery['address']['country_code']) ?></td>
                    <td><?= $delivery['ship_type'] === 'F' ? 'Freight' : 'Parcel' ?></td>
                    <td><?= $delivery['ship_type'] === 'F' ? e($delivery['freight_unit_type']) : '—' ?></td>
                    <td><?= e($delivery['shipment_reference'] ?: '—') ?></td>
                    <td><?= count($delivery['parcels']) ?></td>
                    <td><?= e(number_format(array_sum(array_map(static fn ($parcel): float => (float) $parcel['weight_kg'], $delivery['parcels'])), 3, ',', '.')) ?> kg</td>
                    <td><?= e(implode(', ', $delivery['tracking_numbers']) ?: '—') ?></td>
                    <td><span class="badge badge-<?= e($delivery['status']) ?>"><?= e(['new' => 'Nieuw', 'ready' => 'Klaar voor labels', 'labels_created' => 'Labels gemaakt'][$delivery['status']] ?? $delivery['status']) ?></span></td>
                    <td><a href="/?delivery=<?= rawurlencode((string) $delivery['exact_entry_id']) ?>#selected-delivery">Selecteren →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($selectedDelivery): ?>
        <?php $delivery = $selectedDelivery; require __DIR__ . '/show.php'; ?>
    <?php endif; ?>
<?php endif; ?>

