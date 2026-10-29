<div class="page-heading">
    <div>
        <p class="eyebrow">Verzendoverzicht</p>
        <h1>Leveringen</h1>
        <p class="muted">Controleer de zending, voeg dozen toe en maak de GLS-labels.</p>
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
        <p>Voeg voorlopig handmatig een testlevering toe. Later komen deze automatisch uit Exact.</p>
        <a class="button" href="/deliveries/new">Testlevering toevoegen</a>
    </section>
<?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Levering</th><th>Order</th><th>Ontvanger</th><th>Dozen</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($deliveries as $delivery): ?>
                <tr>
                    <td><strong><?= e($delivery['delivery_number']) ?></strong><br><small><?= e($delivery['shipping_date']) ?></small></td>
                    <td><?= e($delivery['order_number']) ?></td>
                    <td><?= e($delivery['address']['name']) ?><br><small><?= e($delivery['address']['city']) ?></small></td>
                    <td><?= count($delivery['parcels']) ?></td>
                    <td><span class="badge badge-<?= e($delivery['status']) ?>"><?= e($delivery['status']) ?></span></td>
                    <td><a href="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>">Openen →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

