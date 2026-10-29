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
            <thead><tr><th>Levering</th><th>Order</th><th>Type</th><th>Referentie</th><th>Datum</th><th>Ontvanger</th><th>Postcode</th><th>Plaats</th><th>Dozen</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <tr class="<?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'selected-row' : '' ?>">
                    <td><strong><?= e($delivery['delivery_number']) ?></strong></td>
                    <td><?= e($delivery['order_number']) ?></td>
                    <td><?= $delivery['ship_type'] === 'F' ? 'Freight' : 'Parcel' ?></td>
                    <td><?= e($delivery['shipment_reference'] ?: '—') ?></td>
                    <td><?= e($delivery['shipping_date'] ?: '—') ?></td>
                    <td><?= e($delivery['address']['name']) ?></td>
                    <td><?= e($delivery['address']['zip_code']) ?></td>
                    <td><?= e($delivery['address']['city']) ?></td>
                    <td><?= count($delivery['parcels']) ?></td>
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

