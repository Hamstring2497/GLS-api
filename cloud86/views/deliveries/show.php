<?php
$locked = $delivery['status'] === 'labels_created';
$freightUnits = ['CO' => 'Collo (max. 32 kg)', 'LG' => 'Lang collo (25 kg)', 'MP' => 'Minipallet (300 kg)', 'PL' => 'Pallet (675 kg)', 'BP' => 'Blokpallet (850 kg)', 'RL' => 'Rolcontainer (350 kg)', 'XL' => 'XL-pallet (1000 kg)'];
$maxWeight = $delivery['ship_type'] === 'F' ? (\App\GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32) : 32;
$missingData = delivery_missing_data($delivery);
$missingKeys = array_keys($missingData);
$statusLabel = $locked ? 'Labels gemaakt' : ($missingData === [] ? 'Klaar voor labels' : 'Gegevens ontbreken');
$statusClass = $locked ? 'labels_created' : ($missingData === [] ? 'ready' : 'missing');
$mergeCandidates = $mergeCandidates ?? [];
$glsProduct = (string) ($delivery['gls_product'] ?? 'direct_delivery_plus');
$glsOptions = is_array($delivery['gls_options'] ?? null) ? $delivery['gls_options'] : [];
$parcelOptionsDisabled = $locked || $delivery['ship_type'] !== 'P';
$glsProducts = [
    'direct_delivery_plus' => 'DirectDeliveryPlus',
    'direct_delivery' => 'DirectDelivery',
    'letterbox_delivery' => 'LetterboxDelivery',
    'point_delivery' => 'PointDelivery',
    'express_delivery' => 'ExpressDelivery',
];
$expressServices = [
    't9' => 'Maandag–vrijdag vóór 09:00',
    't10' => 'Maandag–vrijdag vóór 10:00',
    't12' => 'Maandag–vrijdag vóór 12:00',
    't17' => 'Maandag–vrijdag vóór 17:00',
    's9' => 'Zaterdag vóór 09:00',
    's10' => 'Zaterdag vóór 10:00',
    's12' => 'Zaterdag vóór 12:00',
    's17' => 'Zaterdag vóór 17:00',
];
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
            <span class="badge badge-<?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
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
            <input class="<?= in_array('shipment_reference', $missingKeys, true) ? 'is-missing' : '' ?>" type="text" name="shipment_reference" value="<?= e($delivery['shipment_reference']) ?>" maxlength="64" required <?= $locked ? 'disabled' : '' ?>>
        </label>
        <div class="gls-options <?= $delivery['ship_type'] !== 'P' ? 'gls-options-disabled' : '' ?>" id="gls-options">
            <div class="gls-options-heading">
                <strong>Aanvullende producten en services</strong>
                <span>Beschikbaar voor Parcel; DirectDeliveryPlus is de standaard.</span>
            </div>
            <div class="gls-options-grid">
                <fieldset class="gls-choice-group">
                    <legend>Product</legend>
                    <?php foreach ($glsProducts as $value => $label): ?>
                        <label class="choice-item">
                            <input type="radio" name="gls_product" value="<?= e($value) ?>" <?= $glsProduct === $value ? 'checked' : '' ?> <?= $parcelOptionsDisabled ? 'disabled' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <fieldset class="gls-choice-group">
                    <legend>Services</legend>
                    <?php foreach ([
                        'saturday' => 'Saturday',
                        'addressee_only' => 'AddresseeOnly',
                        'point_delivery_flex' => 'PointDeliveryFlex',
                        'point_return' => 'PointReturn',
                    ] as $value => $label): ?>
                        <label class="choice-item">
                            <input type="checkbox" name="gls_services[]" value="<?= e($value) ?>" <?= !empty($glsOptions[$value]) ? 'checked' : '' ?> <?= $parcelOptionsDisabled || ($value === 'point_delivery_flex' && $glsProduct !== 'point_delivery') ? 'disabled' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="gls-product-details">
                    <label id="point-delivery-fields" <?= $glsProduct !== 'point_delivery' ? 'hidden' : '' ?>>GLS Point-ID
                        <input type="text" name="gls_parcel_shop_id" value="<?= e((string) ($glsOptions['parcel_shop_id'] ?? '')) ?>" maxlength="32" placeholder="Bijv. 34310025" <?= $parcelOptionsDisabled ? 'disabled' : '' ?>>
                    </label>
                    <label id="express-delivery-fields" <?= $glsProduct !== 'express_delivery' ? 'hidden' : '' ?>>Express tijd
                        <select name="gls_express_service" <?= $parcelOptionsDisabled ? 'disabled' : '' ?>>
                            <?php $selectedExpress = (string) ($glsOptions['express_service'] ?? 't17'); ?>
                            <?php foreach ($expressServices as $value => $label): ?>
                                <option value="<?= e($value) ?>" <?= $selectedExpress === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
            </div>
        </div>
    </form>
    <?php if (($delivery['merged_deliveries'] ?? []) !== []): ?>
        <div class="effective-reference">
            <strong>Gezamenlijke GLS-referentie:</strong> <?= e($delivery['effective_reference']) ?>
        </div>
    <?php endif; ?>
    <?php if (!$locked && $mergeCandidates !== []): ?>
        <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/merge" class="merge-form">
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <div class="merge-heading">
                <strong>Leveringen op hetzelfde adres samenvoegen</strong>
                <span>De gezamenlijke dozen voert u alleen bij deze hoofdlevering in.</span>
            </div>
            <div class="merge-options">
                <?php foreach ($mergeCandidates as $candidate): ?>
                    <?php
                    $isIncluded = ($candidate['merged_into_exact_entry_id'] ?? null) === $delivery['exact_entry_id'];
                    $hasParcels = $candidate['parcels'] !== [];
                    ?>
                    <label class="merge-option <?= $hasParcels && !$isIncluded ? 'merge-option-disabled' : '' ?>">
                        <input type="checkbox" name="merged_delivery_ids[]" value="<?= e($candidate['exact_entry_id']) ?>" <?= $isIncluded ? 'checked' : '' ?> <?= $hasParcels && !$isIncluded ? 'disabled' : '' ?>>
                        <span>
                            <strong>Levering <?= e($candidate['delivery_number']) ?></strong>
                            · order <?= e($candidate['order_number']) ?>
                            <?php if ($hasParcels && !$isIncluded): ?>· wis eerst de eigen dozen<?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <button class="button button-small" type="submit">Samenvoeging opslaan</button>
        </form>
    <?php endif; ?>
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
                <?php $fieldMissing = in_array($field, $missingKeys, true); ?>
                <label class="<?= e($className) ?> <?= $fieldMissing ? 'field-missing' : '' ?>"><?= e($label) ?><?= $required ? ' *' : '' ?><?= $fieldMissing ? ' — ontbreekt' : '' ?>
                    <input class="<?= $fieldMissing ? 'is-missing' : '' ?>" name="<?= e($field) ?>" value="<?= e($delivery['address'][$field] ?? '') ?>" <?= $locked ? 'disabled' : '' ?> <?= $required ? 'required' : '' ?> type="<?= $field === 'email' ? 'email' : 'text' ?>" <?= $maxLength !== null ? 'maxlength="' . e($maxLength) . '"' : '' ?>>
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
                <?php elseif ($missingData === []): ?>
                    <span><strong>Klaar om labels te maken</strong></span>
                    <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/labels" onsubmit="this.querySelector('button').disabled=true">
                        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                        <button class="button button-small" type="submit">Maak <?= count($delivery['parcels']) ?> GLS-label(s)</button>
                    </form>
                <?php else: ?>
                    <span class="missing-summary"><strong>Ontbreekt</strong> · <?= e(implode(', ', $missingData)) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($locked): ?>
            <ol class="parcel-list">
                <?php foreach ($delivery['parcels'] as $parcel): ?><li>Doos <?= e($parcel['position']) ?><strong><?= e(number_format((float) $parcel['weight_kg'], 1, ',', '.')) ?> kg</strong></li><?php endforeach; ?>
            </ol>
        <?php else: ?>
            <?php $parcelRows = $delivery['parcels'] !== [] ? $delivery['parcels'] : [['weight_kg' => '']]; ?>
            <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/parcels" class="stack">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <div id="weights">
                    <?php foreach ($parcelRows as $index => $parcel): ?>
                        <div class="weight-row">
                            <label class="weight-label"><span>Doos <?= $index + 1 ?></span><span class="weight-input"><input type="number" name="weights[]" value="<?= $parcel['weight_kg'] !== '' ? e(number_format((float) $parcel['weight_kg'], 1, '.', '')) : '' ?>" min="0.1" max="<?= $maxWeight ?>" step="0.1" inputmode="decimal" placeholder="0,0"><span>kg</span></span></label>
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
                row.innerHTML = '<label class="weight-label"><span>Doos</span><span class="weight-input"><input type="number" name="weights[]" min="0.1" max="<?= $maxWeight ?>" step="0.1" inputmode="decimal" placeholder="0,0"><span>kg</span></span></label><button class="button button-secondary button-small" type="button" onclick="removeParcel(this)">Wissen</button>';
                list.appendChild(row);
                renumberParcels();
            }
            function removeParcel(button) {
                const rows = document.querySelectorAll('#weights .weight-row');
                if (rows.length === 1) {
                    const input = rows[0].querySelector('input[name="weights[]"]');
                    if (input) input.value = '';
                    return;
                }
                button.closest('.weight-row').remove();
                renumberParcels();
            }
            function renumberParcels() { document.querySelectorAll('#weights .weight-label > span:first-child').forEach((label, index) => { label.textContent = `Doos ${index + 1}`; }); }
            document.getElementById('ship-type').addEventListener('change', function () {
                document.getElementById('freight-unit-type').disabled = this.value !== 'F';
                updateGlsOptions();
            });
            function updateGlsOptions() {
                const parcel = document.getElementById('ship-type').value === 'P';
                const options = document.getElementById('gls-options');
                const selected = document.querySelector('input[name="gls_product"]:checked')?.value || 'direct_delivery_plus';
                options.classList.toggle('gls-options-disabled', !parcel);
                options.querySelectorAll('input[name="gls_product"], input[name="gls_services[]"], input[name="gls_parcel_shop_id"], select[name="gls_express_service"]').forEach((field) => {
                    field.disabled = !parcel;
                });
                const flex = options.querySelector('input[value="point_delivery_flex"]');
                if (flex) {
                    flex.disabled = !parcel || selected !== 'point_delivery';
                    if (selected !== 'point_delivery') flex.checked = false;
                }
                document.getElementById('point-delivery-fields').hidden = !parcel || selected !== 'point_delivery';
                document.getElementById('express-delivery-fields').hidden = !parcel || selected !== 'express_delivery';
            }
            document.querySelectorAll('input[name="gls_product"]').forEach((field) => field.addEventListener('change', updateGlsOptions));
            updateGlsOptions();
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
