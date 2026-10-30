<?php
$locked = $delivery['status'] === 'labels_created';
$freightUnits = ['CO' => 'Collo (32 kg)', 'LG' => 'Lang collo (25 kg)', 'MP' => 'Minipallet (300 kg)', 'PL' => 'Pallet (675 kg)', 'BP' => 'Blokpallet (850 kg)', 'RL' => 'Rolcontainer (350 kg)', 'XL' => 'XL-pallet (1000 kg)'];
$maxWeight = $delivery['ship_type'] === 'F' ? (\App\GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32) : 32;
?>
<div class="page-heading selected-heading" id="selected-delivery">
    <div>
        <p class="eyebrow">Levering <?= e($delivery['delivery_number']) ?></p>
        <h1><?= e($delivery['address']['name']) ?></h1>
        <p class="muted">Order <?= e($delivery['order_number']) ?> · <?= e($delivery['shipping_date'] ?: 'geen verzenddatum') ?></p>
    </div>
    <div class="heading-actions">
        <span class="badge badge-<?= e($delivery['status']) ?>"><?= e(['new' => 'Nieuw', 'ready' => 'Klaar voor labels', 'labels_created' => 'Labels gemaakt'][$delivery['status']] ?? $delivery['status']) ?></span>
        <?php if (!$locked): ?>
            <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/delete" onsubmit="return confirm('Deze zending uit de werkvoorraad wissen? Dit kan niet ongedaan worden gemaakt.')">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <input type="hidden" name="confirm_delete" value="yes">
                <button class="button button-danger" type="submit">Zending wissen</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<section class="panel shipping-panel">
    <h2>Zending</h2>
    <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/shipping" class="shipping-form">
        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
        <label>Type
            <select name="ship_type" id="ship-type" <?= $locked ? 'disabled' : '' ?>>
                <option value="P" <?= $delivery['ship_type'] === 'P' ? 'selected' : '' ?>>Parcel</option>
                <option value="F" <?= $delivery['ship_type'] === 'F' ? 'selected' : '' ?>>Freight</option>
            </select>
        </label>
        <label>Vrachttype
            <select name="freight_unit_type" id="freight-unit-type" <?= $locked || $delivery['ship_type'] !== 'F' ? 'disabled' : '' ?>>
                <?php foreach ($freightUnits as $code => $description): ?>
                    <option value="<?= e($code) ?>" <?= $delivery['freight_unit_type'] === $code ? 'selected' : '' ?>><?= e($description) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Referentie
            <input type="text" name="shipment_reference" value="<?= e($delivery['shipment_reference']) ?>" maxlength="64" required <?= $locked ? 'disabled' : '' ?>>
        </label>
        <?php if (!$locked): ?><button class="button" type="submit">Zending opslaan</button><?php endif; ?>
    </form>
    <?php if (!$locked): ?><p class="muted">De referentie is vooraf ingevuld met de laatste vier cijfers van het ordernummer.</p><?php endif; ?>
</section>

<div class="detail-stack">
    <section class="panel address-panel">
        <h2>Afleveradres</h2>
        <p class="muted">Overgenomen uit Exact. Controleer en pas zo nodig aan vóór de labels worden gemaakt.</p>
        <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/address" class="address-form">
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <?php foreach ([
                'name' => 'Naam', 'street' => 'Straat', 'house_no' => 'Huisnummer',
                'zip_code' => 'Postcode', 'city' => 'Plaats', 'country_code' => 'Landcode',
                'contact' => 'Contactpersoon', 'phone' => 'Telefoonnummer', 'email' => 'E-mailadres',
            ] as $field => $label): ?>
                <label><?= e($label) ?><?= in_array($field, ['name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'], true) ? ' *' : '' ?>
                    <input name="<?= e($field) ?>" value="<?= e($delivery['address'][$field] ?? '') ?>" <?= $locked ? 'disabled' : '' ?> <?= in_array($field, ['name', 'street', 'house_no', 'zip_code', 'city', 'country_code', 'email'], true) ? 'required' : '' ?> <?= $field === 'email' ? 'type="email"' : 'type="text"' ?> <?= $field === 'country_code' ? 'maxlength="2"' : '' ?>>
                </label>
            <?php endforeach; ?>
            <?php if (!$locked): ?><button class="button" type="submit">Adres opslaan</button><?php endif; ?>
        </form>
    </section>

    <section class="panel units-panel">
        <h2><?= $delivery['ship_type'] === 'F' ? 'Vrachteenheden en gewicht' : 'Dozen en gewicht' ?></h2>
        <?php if ($locked): ?>
            <ol class="parcel-list">
                <?php foreach ($delivery['parcels'] as $parcel): ?><li>Doos <?= e($parcel['position']) ?><strong><?= e($parcel['weight_kg']) ?> kg</strong></li><?php endforeach; ?>
            </ol>
        <?php else: ?>
            <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/parcels" class="stack">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <div id="weights">
                    <?php foreach ($delivery['parcels'] as $index => $parcel): ?>
                        <div class="weight-row"><label>Doos <?= $index + 1 ?><input type="number" name="weights[]" value="<?= e($parcel['weight_kg']) ?>" min="0.001" max="<?= $maxWeight ?>" step="0.001" required></label><button class="button button-secondary" type="button" onclick="removeParcel(this)">Wissen</button></div>
                    <?php endforeach; ?>
                </div>
                <div class="units-actions">
                    <button class="button button-secondary" type="button" onclick="addParcel()">+ Doos toevoegen</button>
                    <button class="button" type="submit">Dozen opslaan</button>
                </div>
            </form>
            <script>
            function addParcel() {
                const list = document.getElementById('weights');
                const row = document.createElement('div');
                row.className = 'weight-row';
                row.innerHTML = '<label>Doos <input type="number" name="weights[]" min="0.001" max="<?= $maxWeight ?>" step="0.001" required></label><button class="button button-secondary" type="button" onclick="removeParcel(this)">Wissen</button>';
                list.appendChild(row);
                renumberParcels();
            }
            function removeParcel(button) { button.closest('.weight-row').remove(); renumberParcels(); }
            function renumberParcels() { document.querySelectorAll('#weights .weight-row label').forEach((label, index) => { label.firstChild.textContent = `Doos ${index + 1} `; }); }
            document.getElementById('ship-type').addEventListener('change', function () {
                document.getElementById('freight-unit-type').disabled = this.value !== 'F';
            });
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
    <?php elseif ($delivery['status'] === 'ready' && filter_var((string) ($delivery['address']['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false): ?>
        <div><p class="eyebrow">Nog niet gereed</p><h2>E-mailadres ontbreekt</h2><p>Vul hierboven eerst een geldig e-mailadres in en sla het adres op.</p></div>
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

