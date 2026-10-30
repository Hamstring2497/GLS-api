<?php
$locked = $delivery['status'] === 'labels_created';
$freightUnits = ['CO' => 'Collo (max. 32 kg)', 'LG' => 'Lang collo (25 kg)', 'MP' => 'Minipallet (300 kg)', 'PL' => 'Pallet (675 kg)', 'BP' => 'Blokpallet (850 kg)', 'RL' => 'Rolcontainer (350 kg)', 'XL' => 'XL-pallet (1000 kg)'];
$maxWeight = $delivery['ship_type'] === 'F' ? (\App\GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32) : 32;
$statusLabel = ['new' => 'Nieuw', 'ready' => 'Klaar voor labels', 'labels_created' => 'Labels gemaakt'][$delivery['status']] ?? $delivery['status'];
$addressFields = [
    ['name', 'Naam', 'field-name', true, null],
    ['name_2', 'Naam 2', 'field-name-2', false, null],
    ['name_3', 'Naam 3', 'field-name-3', false, null],
    ['street', 'Straat', 'field-street', true, null],
    ['house_no', 'Huisnummer', 'field-house-no', true, null],
    ['house_no_addition', 'Toevoeging', 'field-house-addition', false, null],
    ['zip_code', 'Postcode', 'field-zip', true, null],
    ['city', 'Plaats', 'field-city', true, null],
    ['country_code', 'Landcode', 'field-country', true, 2],
    ['contact', 'Contactpersoon', 'field-contact', false, null],
    ['phone', 'Telefoonnummer', 'field-phone', false, null],
    ['email', 'E-mailadres', 'field-email', true, null],
    ['note_1', 'Notitie 1', 'field-note-1', false, 50],
    ['note_2', 'Notitie 2', 'field-note-2', false, 50],
];
?>

<section class="panel shipping-panel" id="selected-delivery">
    <div class="section-bar shipping-bar">
        <div class="shipment-title">
            <h2>Zending</h2>
            <div>
                <strong>Levering <?= e($delivery['delivery_number']) ?> · <?= e($delivery['address']['name']) ?></strong>
                <span>Order <?= e($delivery['order_number']) ?> · <?= e($delivery['shipping_date'] ?: 'geen verzenddatum') ?></span>
            </div>
        </div>
        <div class="heading-actions">
            <span class="badge badge-<?= e($delivery['status']) ?>"><?= e($statusLabel) ?></span>
        </div>
    </div>
    <form id="shipping-details-form" method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/shipping" class="shipping-form">
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
    </form>
</section>

<div class="detail-stack">
    <section class="panel address-panel">
        <div class="section-bar">
            <h2>Afleveradres</h2>
            <span>Overgenomen uit Exact · controleer vóór het maken van labels</span>
        </div>
        <form id="address-details-form" method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/address" class="address-form">
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <?php foreach ($addressFields as [$field, $label, $className, $required, $maxLength]): ?>
                <label class="<?= e($className) ?>"><?= e($label) ?><?= $required ? ' *' : '' ?>
                    <input name="<?= e($field) ?>" value="<?= e($delivery['address'][$field] ?? '') ?>" <?= $locked ? 'disabled' : '' ?> <?= $required ? 'required' : '' ?> type="<?= $field === 'email' ? 'email' : 'text' ?>" <?= $maxLength !== null ? 'maxlength="' . e($maxLength) . '"' : '' ?>>
                </label>
            <?php endforeach; ?>
        </form>
    </section>

    <section class="panel units-panel">
        <div class="section-bar units-bar">
            <h2><?= $delivery['ship_type'] === 'F' ? 'Vrachteenheden en gewicht' : 'Dozen en gewicht' ?></h2>
            <div class="units-status">
                <?php if ($locked): ?>
                    <span><strong>GLS gereed</strong> · <?= count($delivery['tracking_numbers']) ?> track-en-tracecode(s)</span>
                    <?php if (!empty($delivery['label_path'])): ?><a class="button button-small" target="_blank" href="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/label">Label openen</a><?php endif; ?>
                <?php elseif ($delivery['status'] === 'ready' && filter_var((string) ($delivery['address']['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false): ?>
                    <span><strong>Nog niet gereed</strong> · e-mailadres ontbreekt</span>
                <?php elseif ($delivery['status'] === 'ready'): ?>
                    <span><strong>Klaar om labels te maken</strong></span>
                    <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/labels" onsubmit="this.querySelector('button').disabled=true">
                        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                        <button class="button button-small" type="submit">Maak <?= count($delivery['parcels']) ?> GLS-label(s)</button>
                    </form>
                <?php else: ?>
                    <span><strong>Nog niet gereed</strong> · voeg eerst de dozen toe</span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($locked): ?>
            <ol class="parcel-list">
                <?php foreach ($delivery['parcels'] as $parcel): ?><li>Doos <?= e($parcel['position']) ?><strong><?= e(number_format((float) $parcel['weight_kg'], 1, ',', '.')) ?> kg</strong></li><?php endforeach; ?>
            </ol>
        <?php else: ?>
            <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/parcels" class="stack">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <div id="weights">
                    <?php foreach ($delivery['parcels'] as $index => $parcel): ?>
                        <div class="weight-row">
                            <label class="weight-label"><span>Doos <?= $index + 1 ?></span><span class="weight-input"><input type="number" name="weights[]" value="<?= e(number_format((float) $parcel['weight_kg'], 1, '.', '')) ?>" min="0.1" max="<?= $maxWeight ?>" step="0.1" inputmode="decimal" required><span>kg</span></span></label>
                            <button class="button button-secondary button-small" type="button" onclick="removeParcel(this)">Wissen</button>
                        </div>
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
                row.innerHTML = '<label class="weight-label"><span>Doos</span><span class="weight-input"><input type="number" name="weights[]" min="0.1" max="<?= $maxWeight ?>" step="0.1" inputmode="decimal" required><span>kg</span></span></label><button class="button button-secondary button-small" type="button" onclick="removeParcel(this)">Wissen</button>';
                list.appendChild(row);
                renumberParcels();
            }
            function removeParcel(button) { button.closest('.weight-row').remove(); renumberParcels(); }
            function renumberParcels() { document.querySelectorAll('#weights .weight-label > span:first-child').forEach((label, index) => { label.textContent = `Doos ${index + 1}`; }); }
            document.getElementById('ship-type').addEventListener('change', function () {
                document.getElementById('freight-unit-type').disabled = this.value !== 'F';
            });
            </script>
        <?php endif; ?>
        <?php if (!$locked): ?>
            <div class="shipment-footer-actions">
                <button class="button" type="submit" form="shipping-details-form">Zending opslaan</button>
                <button class="button" type="submit" form="address-details-form">Adres opslaan</button>
            </div>
        <?php endif; ?>
    </section>
</div>
