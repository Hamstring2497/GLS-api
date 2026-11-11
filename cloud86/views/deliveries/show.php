<?php
$glsMode = strtolower((string) ($glsMode ?? 'mock'));
$labelsCreated = $delivery['status'] === 'labels_created';
$collected = $delivery['status'] === 'collected';
$glsPending = in_array($delivery['status'], ['gls_processing', 'gls_unknown'], true);
$locked = $labelsCreated || $collected || $glsPending;
$mockTracking = \App\ExactService::isMockTracking($delivery);
$exactTrackingSynced = !empty($delivery['exact_tracking_synced_at']);
foreach (($delivery['merged_deliveries'] ?? []) as $mergedDelivery) {
    $exactTrackingSynced = $exactTrackingSynced && !empty($mergedDelivery['exact_tracking_synced_at']);
}
$freightUnits = ['CO' => 'Collo (max. 32 kg)', 'LG' => 'Lang collo (25 kg)', 'MP' => 'Minipallet (300 kg)', 'PL' => 'Pallet (675 kg)', 'BP' => 'Blokpallet (850 kg)', 'RL' => 'Rolcontainer (350 kg)', 'XL' => 'XL-pallet (1000 kg)'];
$maxWeight = $delivery['ship_type'] === 'F' ? (\App\GlsService::FREIGHT_UNITS[$delivery['freight_unit_type']] ?? 32) : 32;
$missingData = delivery_missing_data($delivery);
$missingKeys = array_keys($missingData);
$statusLabel = match ($delivery['status']) {
    'labels_created' => 'Zending klaar ✓',
    'collected' => 'Afgehaald ✓',
    'gls_processing' => 'Bezig met GLS',
    'gls_unknown' => 'GLS-controle nodig',
    default => $missingData === [] ? 'Klaar voor labels' : 'Gegevens ontbreken',
};
$statusClass = match ($delivery['status']) {
    'labels_created' => 'labels_created',
    'collected' => 'collected',
    'gls_processing' => 'processing',
    'gls_unknown' => 'unknown',
    default => $missingData === [] ? 'ready' : 'missing',
};
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
    ['name', 'Naam', 'field-name', true, 30],
    ['name_2', 'Naam 2', 'field-name-2', false, 30],
    ['name_3', 'Naam 3', 'field-name-3', false, 30],
    ['street', 'Straat', 'field-street', true, 40],
    ['house_no', 'Huisnummer', 'field-house-no', true, 5],
    ['house_no_addition', 'Toevoeging', 'field-house-addition', false, 5],
    ['zip_code', 'Postcode', 'field-zip', true, 8],
    ['city', 'Plaats', 'field-city', true, 30],
    ['country_code', 'Landcode', 'field-country', true, 2],
    ['contact', 'Contactpersoon', 'field-contact', false, 30],
    ['phone', 'Telefoonnummer', 'field-phone', false, 25],
    ['email', 'E-mailadres', 'field-email', true, 320],
    ['note_1', 'Notitie 1', 'field-note-1', false, 30],
    ['note_2', 'Notitie 2', 'field-note-2', false, 30],
];
?>

<div class="detail-stack">
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
            <?php if (!$locked): ?>
                <form class="collected-form" method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/collected" onsubmit="return confirmCollectedDelivery(this)">
                    <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                    <label class="collected-check">
                        <input type="checkbox" name="confirm_collected" value="yes">
                        <span>Afgehaald</span>
                    </label>
                    <button class="button button-secondary button-small" type="submit">Afronden</button>
                </form>
            <?php endif; ?>
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
        <div class="gls-options-field">
            <span class="gls-options-label">Aanvullende producten</span>
            <details class="gls-options <?= $delivery['ship_type'] !== 'P' ? 'gls-options-disabled' : '' ?>" id="gls-options">
                <summary>
                    <strong id="gls-product-summary"><?= e($glsProducts[$glsProduct] ?? 'DirectDeliveryPlus') ?></strong>
                </summary>
                <div class="gls-options-body">
                <div class="gls-options-heading">
                    <strong>Aanvullende producten en services</strong>
                    <span>Beschikbaar voor Parcel; DirectDeliveryPlus is de standaard.</span>
                </div>
                <div class="gls-options-grid">
                    <fieldset class="gls-choice-group">
                        <legend>Product</legend>
                        <?php foreach ($glsProducts as $value => $label): ?>
                            <label class="choice-item">
                                <input type="radio" name="gls_product" value="<?= e($value) ?>" data-label="<?= e($label) ?>" <?= $glsProduct === $value ? 'checked' : '' ?> <?= $parcelOptionsDisabled ? 'disabled' : '' ?>>
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
            </details>
        </div>
        <label>Referentie
            <input class="<?= in_array('shipment_reference', $missingKeys, true) ? 'is-missing' : '' ?>" type="text" name="shipment_reference" value="<?= e($delivery['shipment_reference']) ?>" maxlength="20" required <?= $locked ? 'disabled' : '' ?>>
        </label>
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

    <section class="panel address-panel">
        <div class="section-bar">
            <h2>Afleveradres</h2>
            <span>Overgenomen uit Exact · controleer vóór het maken van labels</span>
        </div>
        <div class="address-form">
            <?php foreach ($addressFields as [$field, $label, $className, $required, $maxLength]): ?>
                <?php $fieldIssue = $missingData[$field] ?? null; ?>
                <label class="<?= e($className) ?> <?= $fieldIssue !== null ? 'field-missing' : '' ?>"><?= e($label) ?><?= $required ? ' *' : '' ?><?= $fieldIssue !== null ? ' — controleer' : '' ?>
                    <input form="shipping-details-form" class="<?= $fieldIssue !== null ? 'is-missing' : '' ?>" name="<?= e($field) ?>" value="<?= e($delivery['address'][$field] ?? '') ?>" <?= $locked ? 'disabled' : '' ?> <?= $required ? 'required' : '' ?> type="<?= $field === 'email' ? 'email' : 'text' ?>" <?= $maxLength !== null ? 'maxlength="' . e($maxLength) . '"' : '' ?><?= $maxLength !== null ? ' title="Maximaal ' . e($maxLength) . ' tekens"' : '' ?>>
                </label>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel units-panel <?= ($labelsCreated || $collected) ? 'shipment-ready-panel' : '' ?>">
        <div class="section-bar units-bar">
            <h2><?= $delivery['ship_type'] === 'F' ? 'Vrachteenheden en gewicht' : 'Dozen en gewicht' ?></h2>
            <div class="units-status">
                <?php if ($collected): ?>
                    <span>
                        <strong>✓ Afgehaald · niet naar GLS</strong>
                        <?php if ($exactTrackingSynced): ?>· Exact bijgewerkt<?php else: ?>· Exact nog niet bijgewerkt<?php endif; ?>
                        · <?= e(implode(', ', $delivery['tracking_numbers'])) ?>
                    </span>
                    <?php if (!$exactTrackingSynced): ?>
                        <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/tracking/exact">
                            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                            <button class="button button-secondary button-small" type="submit">Opnieuw naar Exact</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($labelsCreated): ?>
                    <span>
                        <strong>✓ Zending klaar · GLS gereed</strong> · <?= count($delivery['tracking_numbers']) ?> track-en-tracecode(s)
                        <?php if ($mockTracking): ?>· test, niet naar Exact
                        <?php elseif ($exactTrackingSynced): ?>· Exact bijgewerkt
                        <?php elseif ($glsMode === 'mock'): ?>· Exact wacht op actieve GLS-koppeling
                        <?php else: ?>· Exact nog niet bijgewerkt<?php endif; ?>
                        <?php foreach ($delivery['tracking_numbers'] as $trackingNumber): ?>
                            <?php $trackingUrl = gls_tracking_url($trackingNumber); ?>
                            <?php if ($trackingUrl !== null): ?> · <a class="tracking-link" href="<?= e($trackingUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open GLS Track & Trace voor <?= e($trackingNumber) ?>"><?= e($trackingNumber) ?></a><?php endif; ?>
                        <?php endforeach; ?>
                    </span>
                    <?php if (!empty($delivery['label_path'])): ?><a class="button button-small" href="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/label" onclick="return openLabelAndPrint(this.href)">Label openen en afdrukken</a><?php endif; ?>
                    <?php if ($glsMode === 'live' && !$mockTracking && !$exactTrackingSynced): ?>
                        <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/tracking/exact">
                            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                            <button class="button button-secondary button-small" type="submit">Opnieuw naar Exact</button>
                        </form>
                    <?php endif; ?>
                <?php elseif ($glsPending): ?>
                    <span class="gls-uncertain-status"><strong><?= $delivery['status'] === 'gls_unknown' ? 'Eerst GLS controleren' : 'Aanvraag wordt verwerkt' ?></strong> · niet opnieuw versturen</span>
                <?php elseif ($missingData === []): ?>
                    <span><strong><?= $glsMode === 'mock' ? 'Klaar voor afdrukbare testlabels' : 'Klaar om naar GLS te versturen' ?></strong><?php if ($glsMode === 'live'): ?> · Verzenden op <?= e(date('d-m-Y')) ?><?php endif; ?></span>
                    <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/labels" data-gls-mode="<?= e($glsMode) ?>" data-delivery-number="<?= e($delivery['delivery_number']) ?>" data-parcel-count="<?= count($delivery['parcels']) ?>" onsubmit="return createAndPrintGlsLabel(this)">
                        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                        <input type="hidden" name="confirm_gls_request" value="no">
                        <input type="hidden" name="shipping_date_confirmed" value="<?= e(date('Y-m-d')) ?>">
                        <button class="button button-small" type="submit"><?= $glsMode === 'mock' ? 'Testlabel maken en afdrukken' : 'Label maken en afdrukken' ?></button>
                    </form>
                <?php else: ?>
                    <span class="missing-summary"><strong>Ontbreekt</strong> · <?= e(implode(', ', $missingData)) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($collected): ?>
            <p class="label-print-note">Deze levering is afgehaald. Er is geen GLS-zending of label aangemaakt.</p>
        <?php elseif ($labelsCreated): ?>
            <ol class="parcel-list">
                <?php foreach ($delivery['parcels'] as $parcel): ?><li>Doos <?= e($parcel['position']) ?><strong><?= e(number_format((float) $parcel['weight_kg'], 0, ',', '.')) ?> kg</strong></li><?php endforeach; ?>
            </ol>
            <?php if (!empty($delivery['label_path'])): ?>
                <p class="label-print-note">Opnieuw afdrukken gebruikt het opgeslagen PDF-bestand en maakt geen nieuwe GLS-zending aan.</p>
            <?php endif; ?>
        <?php elseif ($glsPending): ?>
            <div class="gls-uncertain-panel">
                <strong><?= $delivery['status'] === 'gls_unknown' ? 'De uitkomst van de GLS-aanvraag is onbekend.' : 'De GLS-aanvraag is nog vergrendeld.' ?></strong>
                <p>Controleer eerst in GLS op leveringsnummer <?= e($delivery['delivery_number']) ?> en referentie <?= e($delivery['effective_reference']) ?>. Maak niet opnieuw aan zolang dit niet is gecontroleerd.</p>
                <?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
                    <form method="post" action="/deliveries/<?= rawurlencode((string) $delivery['exact_entry_id']) ?>/labels/release" onsubmit="return confirmGlsRelease(this)">
                        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                        <input type="hidden" name="confirm_no_gls_shipment" value="no">
                        <button class="button button-secondary" type="submit">Na GLS-controle vrijgeven</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php $parcelRows = $delivery['parcels'] !== [] ? $delivery['parcels'] : [['weight_kg' => '']]; ?>
            <div class="stack">
                <p class="muted weight-limit-hint" id="weight-limit-hint">Maximaal gewicht per doos of vrachteenheid: <strong><?= $maxWeight ?> kg</strong>.</p>
                <div id="weights">
                    <?php foreach ($parcelRows as $index => $parcel): ?>
                        <div class="weight-row">
                            <label class="weight-label"><span>Doos <?= $index + 1 ?></span><span class="weight-input"><input form="shipping-details-form" type="number" name="weights[]" value="<?= $parcel['weight_kg'] !== '' ? e(number_format((float) $parcel['weight_kg'], 0, '.', '')) : '' ?>" min="1" max="<?= $maxWeight ?>" step="1" inputmode="numeric" placeholder="0"><span>kg</span></span></label>
                            <button class="button button-secondary button-small" type="button" onclick="removeParcel(this)">Wissen</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="units-actions">
                    <button class="button button-secondary" type="button" onclick="addParcel()">+ Doos toevoegen</button>
                    <button class="button" type="submit" form="shipping-details-form">Zending opslaan</button>
                </div>
            </div>
            <script>
            const freightMaxWeights = <?= json_encode(\App\GlsService::FREIGHT_UNITS, JSON_UNESCAPED_SLASHES) ?>;
            function currentMaxWeight() {
                if (document.getElementById('ship-type').value !== 'F') return 32;
                return Number(freightMaxWeights[document.getElementById('freight-unit-type').value] || 32);
            }
            function updateWeightLimits() {
                const maxWeight = currentMaxWeight();
                document.querySelectorAll('#weights input[name="weights[]"]').forEach((input) => {
                    input.max = String(maxWeight);
                });
                const hint = document.querySelector('#weight-limit-hint strong');
                if (hint) hint.textContent = `${maxWeight} kg`;
            }
            function addParcel() {
                const list = document.getElementById('weights');
                const row = document.createElement('div');
                row.className = 'weight-row';
                row.innerHTML = `<label class="weight-label"><span>Doos</span><span class="weight-input"><input form="shipping-details-form" type="number" name="weights[]" min="1" max="${currentMaxWeight()}" step="1" inputmode="numeric" placeholder="0"><span>kg</span></span></label><button class="button button-secondary button-small" type="button" onclick="removeParcel(this)">Wissen</button>`;
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
                updateWeightLimits();
                updateGlsOptions();
            });
            document.getElementById('freight-unit-type').addEventListener('change', updateWeightLimits);
            function updateGlsOptions() {
                const parcel = document.getElementById('ship-type').value === 'P';
                const options = document.getElementById('gls-options');
                const selected = document.querySelector('input[name="gls_product"]:checked')?.value || 'direct_delivery_plus';
                const selectedProduct = document.querySelector('input[name="gls_product"]:checked');
                const summary = document.getElementById('gls-product-summary');
                if (summary) summary.textContent = selectedProduct?.dataset.label || 'DirectDeliveryPlus';
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
            updateWeightLimits();
            updateGlsOptions();
            </script>
        <?php endif; ?>
    </section>
</div>
