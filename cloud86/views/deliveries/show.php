<?php $locked = $delivery['status'] === 'labels_created'; ?>
<div class="page-heading">
    <div>
        <p class="eyebrow">Levering <?= e($delivery['delivery_number']) ?></p>
        <h1><?= e($delivery['address']['name']) ?></h1>
        <p class="muted">Order <?= e($delivery['order_number']) ?> · <?= e($delivery['shipping_date'] ?: 'geen verzenddatum') ?></p>
    </div>
    <span class="badge badge-<?= e($delivery['status']) ?>"><?= e($delivery['status']) ?></span>
</div>

<div class="detail-grid">
    <section class="panel">
        <h2>Afleveradres</h2>
        <address>
            <?= e($delivery['address']['name']) ?><br>
            <?= e($delivery['address']['street']) ?> <?= e($delivery['address']['house_no']) ?><br>
            <?= e($delivery['address']['zip_code']) ?> <?= e($delivery['address']['city']) ?><br>
            <?= e($delivery['address']['country_code']) ?>
        </address>
        <?php if (!empty($delivery['address']['email'])): ?><p><?= e($delivery['address']['email']) ?></p><?php endif; ?>
    </section>

    <section class="panel">
        <h2>Dozen en gewicht</h2>
        <?php if ($locked): ?>
            <ol class="parcel-list">
                <?php foreach ($delivery['parcels'] as $parcel): ?><li>Doos <?= e($parcel['position']) ?><strong><?= e($parcel['weight_kg']) ?> kg</strong></li><?php endforeach; ?>
            </ol>
        <?php else: ?>
            <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/parcels" class="stack">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <div id="weights">
                    <?php $parcels = $delivery['parcels'] ?: [['weight_kg' => '']]; ?>
                    <?php foreach ($parcels as $index => $parcel): ?>
                        <label>Doos <?= $index + 1 ?><input type="number" name="weights[]" value="<?= e($parcel['weight_kg']) ?>" min="0.001" max="32" step="0.001" required></label>
                    <?php endforeach; ?>
                </div>
                <button class="button button-secondary" type="button" onclick="addParcel()">+ Doos toevoegen</button>
                <button class="button" type="submit">Dozen opslaan</button>
            </form>
            <script>
            function addParcel() {
                const list = document.getElementById('weights');
                const label = document.createElement('label');
                label.textContent = `Doos ${list.children.length + 1}`;
                label.innerHTML += '<input type="number" name="weights[]" min="0.001" max="32" step="0.001" required>';
                list.appendChild(label);
            }
            </script>
        <?php endif; ?>
    </section>
</div>

<section class="panel action-panel">
    <?php if ($locked): ?>
        <div>
            <p class="eyebrow">GLS gereed</p>
            <h2><?= count($delivery['tracking_numbers']) ?> track-en-tracecode(s)</h2>
            <?php foreach ($delivery['tracking_numbers'] as $number): ?><code><?= e($number) ?></code><?php endforeach; ?>
            <?php if (!empty($delivery['last_gls_state'])): ?><p>Laatste status: <strong><?= e($delivery['last_gls_state']) ?></strong></p><?php endif; ?>
        </div>
        <?php if (!empty($delivery['label_path'])): ?><a class="button" target="_blank" href="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/label">Label openen en afdrukken</a><?php endif; ?>
    <?php elseif ($delivery['status'] === 'ready'): ?>
        <div><p class="eyebrow">Laatste controle</p><h2>Klaar om labels te maken</h2><p>Deze handeling maakt in test- of productiemodus een echte GLS-zending aan.</p></div>
        <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/labels" onsubmit="this.querySelector('button').disabled=true">
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <button class="button" type="submit">Maak <?= count($delivery['parcels']) ?> GLS-label(s)</button>
        </form>
    <?php else: ?>
        <div><p class="eyebrow">Nog niet gereed</p><h2>Voeg eerst de dozen toe</h2></div>
    <?php endif; ?>
</section>

