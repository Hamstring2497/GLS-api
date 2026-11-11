<div class="warehouse-page">
<div class="page-heading overview-heading">
    <div>
        <p class="eyebrow">Verzendoverzicht</p>
        <h1>Leveringen</h1>
        <p class="muted">Selecteer een regel, controleer het adres en voer de dozen in.</p>
    </div>
    <div class="overview-toolbar">
        <label class="delivery-filter-check">
            <input id="delivery-unshipped-only" type="checkbox">
            <span>Openstaande zendingen</span>
        </label>
        <label class="delivery-search">Zoeken
            <input id="delivery-search" type="search" placeholder="Order, ontvanger, adres…" autocomplete="off">
        </label>
        <button class="button" id="delivery-reset" type="button">Reset filter</button>
        <a class="button" href="/deliveries/new">Nieuwe levering</a>
        <?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
            <?php if (!$exactStatus['connected']): ?>
                <a class="button button-secondary" href="/oauth/exact/start">Exact koppelen</a>
            <?php else: ?>
                <form id="exact-sync-form" method="post" action="/exact/sync">
                    <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                    <button class="button" type="submit" data-idle-label="Leveringen ophalen">Leveringen ophalen</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="exact-sync-progress" class="sync-progress" role="status" aria-live="assertive" hidden>
    <span class="sync-spinner" aria-hidden="true"></span>
    <span><strong>PietPakket haalt leveringen op…</strong><small>Even geduld; gegevens en afleveradressen worden gecontroleerd.</small></span>
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
            <thead><tr>
                <th>Levering</th>
                <th><button class="table-sort" type="button" data-sort-key="order">Order</button></th>
                <th aria-sort="descending"><button class="table-sort active" type="button" data-sort-key="date" title="Geplande leverdatum uit Exact">Leverdatum</button></th>
                <th><button class="table-sort" type="button" data-sort-key="added" title="Datum waarop PietPakket de levering heeft ingelezen">Toegevoegd</button></th>
                <th><button class="table-sort" type="button" data-sort-key="recipient">Ontvanger</button></th>
                <th><button class="table-sort" type="button" data-sort-key="street">Straat</button></th>
                <th>Huisnr.</th>
                <th><button class="table-sort" type="button" data-sort-key="postcode">Postcode</button></th>
                <th>Plaats</th><th>Land</th><th>Type</th><th>Vrachttype</th><th>Referentie</th><th>Dozen</th><th>Gewicht</th><th>Tracking</th><th>Status</th>
            </tr></thead>
            <tbody id="delivery-table-body">
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
                } elseif ($delivery['status'] === 'collected') {
                    $tableStatus = 'Afgehaald ✓';
                    $tableStatusClass = 'collected';
                } elseif ($delivery['status'] === 'labels_created') {
                    $tableStatus = 'Zending klaar ✓';
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
                <tr class="delivery-row <?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'selected-row' : '' ?> <?= in_array($delivery['status'], ['ready', 'labels_created', 'collected'], true) ? 'completed-row' : '' ?> <?= in_array($delivery['status'], ['labels_created', 'collected'], true) ? 'shipment-ready-row' : '' ?> <?= in_array($delivery['status'], ['gls_processing', 'gls_unknown'], true) ? 'gls-pending-row' : '' ?> <?= $isQuickMergeCandidate ? 'merge-candidate-row' : '' ?>" data-delivery-url="<?= e($deliveryUrl) ?>" data-merge-candidate="<?= $isQuickMergeCandidate ? '1' : '0' ?>" data-unshipped="<?= $delivery['tracking_numbers'] === [] && $mergedIntoId === '' && !in_array($delivery['status'], ['labels_created', 'collected'], true) ? '1' : '0' ?>" data-delivery-id="<?= e($delivery['exact_entry_id']) ?>" data-delivery-number="<?= e($delivery['delivery_number']) ?>" data-order-number="<?= e($delivery['order_number']) ?>" data-sort-order="<?= e($delivery['order_number']) ?>" data-sort-date="<?= e($delivery['shipping_date'] ?? '') ?>" data-sort-added="<?= e($delivery['created_at'] ?? '') ?>" data-sort-recipient="<?= e($delivery['address']['name']) ?>" data-sort-street="<?= e($delivery['address']['street']) ?>" data-sort-postcode="<?= e($delivery['address']['zip_code']) ?>" tabindex="0" aria-label="Selecteer levering <?= e($delivery['delivery_number']) ?>" aria-selected="<?= $selectedDelivery && $selectedDelivery['exact_entry_id'] === $delivery['exact_entry_id'] ? 'true' : 'false' ?>">
                    <td><strong><?= e($delivery['delivery_number']) ?></strong></td>
                    <td><?= e($delivery['order_number']) ?></td>
                    <td><?= e(format_nl_date($delivery['shipping_date'] ?? '')) ?></td>
                    <td><?= e(format_nl_date($delivery['created_at'] ?? '')) ?></td>
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
                    <td><?= e(number_format(array_sum(array_map(static fn ($parcel): float => (float) $parcel['weight_kg'], $delivery['parcels'])), 0, ',', '.')) ?> kg</td>
                    <td class="tracking-cell">
                        <?php if ($delivery['tracking_numbers'] === []): ?>—<?php else: ?>
                            <?php foreach ($delivery['tracking_numbers'] as $trackingIndex => $trackingNumber): ?>
                                <?php $trackingUrl = gls_tracking_url($trackingNumber); ?>
                                <?php if ($trackingIndex > 0): ?>, <?php endif; ?>
                                <?php if ($trackingUrl !== null): ?>
                                    <a class="tracking-link" href="<?= e($trackingUrl) ?>" target="_blank" rel="noopener noreferrer" title="Open GLS Track & Trace voor <?= e($trackingNumber) ?>"><?= e($trackingNumber) ?></a>
                                <?php else: ?><?= e($trackingNumber) ?><?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
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
        const tableBody = document.getElementById('delivery-table-body');
        const tableRows = tableBody ? Array.from(tableBody.querySelectorAll('.delivery-row')) : [];
        const searchInput = document.getElementById('delivery-search');
        const unshippedOnly = document.getElementById('delivery-unshipped-only');
        const resetButton = document.getElementById('delivery-reset');
        const sortButtons = Array.from(document.querySelectorAll('.table-sort'));
        const openFilterStorageKey = 'pietpakket.openDeliveriesOnly';
        let sortKey = 'date';
        let sortDirection = 'desc';

        const readOpenFilter = () => {
            try {
                return sessionStorage.getItem(openFilterStorageKey) === '1';
            } catch (error) {
                return false;
            }
        };
        const storeOpenFilter = (active) => {
            try {
                sessionStorage.setItem(openFilterStorageKey, active ? '1' : '0');
            } catch (error) {
                // Filtering blijft werken wanneer browseropslag is geblokkeerd.
            }
        };
        if (unshippedOnly) unshippedOnly.checked = readOpenFilter();

        const normalized = value => String(value || '').toLocaleLowerCase('nl-NL').trim();
        const filterRows = () => {
            const query = normalized(searchInput?.value);
            const onlyOpen = Boolean(unshippedOnly?.checked);
            tableRows.forEach(row => {
                const missesSearch = query !== '' && !normalized(row.textContent).includes(query);
                const isAlreadyHandled = onlyOpen && row.dataset.unshipped !== '1';
                row.hidden = missesSearch || isAlreadyHandled;
            });
        };
        const updateSortState = () => {
            sortButtons.forEach(button => {
                const active = button.dataset.sortKey === sortKey;
                button.classList.toggle('active', active);
                button.dataset.sortDirection = active ? sortDirection : '';
                const heading = button.closest('th');
                if (heading) heading.setAttribute('aria-sort', active ? (sortDirection === 'desc' ? 'descending' : 'ascending') : 'none');
            });
        };
        const sortRows = () => {
            if (!tableBody) return;
            const attribute = `data-sort-${sortKey}`;
            tableRows.sort((left, right) => {
                const leftValue = normalized(left.getAttribute(attribute));
                const rightValue = normalized(right.getAttribute(attribute));
                if (leftValue === '' && rightValue !== '') return 1;
                if (leftValue !== '' && rightValue === '') return -1;
                const result = leftValue.localeCompare(rightValue, 'nl-NL', { numeric: true, sensitivity: 'base' });
                return sortDirection === 'desc' ? -result : result;
            });
            tableRows.forEach(row => tableBody.appendChild(row));
            updateSortState();
            filterRows();
        };

        sortButtons.forEach(button => button.addEventListener('click', () => {
            const nextKey = button.dataset.sortKey || 'date';
            if (nextKey === sortKey) {
                sortDirection = sortDirection === 'desc' ? 'asc' : 'desc';
            } else {
                sortKey = nextKey;
                sortDirection = ['date', 'added'].includes(nextKey) ? 'desc' : 'asc';
            }
            sortRows();
        }));
        searchInput?.addEventListener('input', filterRows);
        unshippedOnly?.addEventListener('change', () => {
            storeOpenFilter(unshippedOnly.checked);
            filterRows();
        });
        resetButton?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (unshippedOnly) unshippedOnly.checked = false;
            storeOpenFilter(false);
            sortKey = 'date';
            sortDirection = 'desc';
            sortRows();
            searchInput?.focus();
        });
        sortRows();

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
                if (event.target.closest('a, button, input, select, textarea')) return;
                event.preventDefault();
                openDelivery(row, event);
            });
            row.addEventListener('keydown', event => {
                if (event.target.closest('a, button, input, select, textarea')) return;
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

