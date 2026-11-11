<?php
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$canDeleteSelectedDelivery = isset($selectedDelivery)
    && in_array(($selectedDelivery['status'] ?? ''), ['new', 'ready'], true);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>PietPakket</title>
    <?php $cssVersion = (string) (filemtime(__DIR__ . '/../public/assets/app.css') ?: '1'); ?>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar-left">
        <a class="brand" href="/" aria-label="PietPakket – naar leveringen">
            <span class="brand-mark" aria-hidden="true">PP</span>
            <span class="brand-wordmark">Piet<span>Pakket</span></span>
        </a>
        <?php if (isset($exactStatus) && (\App\Security::user()['role'] ?? '') === 'admin'): ?>
            <span class="exact-info">
                <strong>Exact Online</strong>
                <?= $exactStatus['connected'] ? 'gekoppeld aan administratie ' . e($exactStatus['division']) : 'nog niet gekoppeld' ?>
            </span>
        <?php endif; ?>
    </div>
    <?php if (\App\Security::isAuthenticated()): ?>
        <nav>
            <?php if (\App\Security::user()): ?><span class="user-name"><?= e(\App\Security::user()['display_name']) ?></span><?php endif; ?>
            <a href="/">Leveringen</a>
            <?php if ((\App\Security::user()['role'] ?? '') === 'admin' || isset($selectedDelivery)): ?>
                <details class="maintenance-menu">
                    <summary>Onderhoud</summary>
                    <div class="maintenance-menu-panel">
                        <?php if ($canDeleteSelectedDelivery): ?>
                            <form method="post" action="/deliveries/<?= rawurlencode((string) $selectedDelivery['exact_entry_id']) ?>/delete" data-delivery-number="<?= e($selectedDelivery['delivery_number']) ?>" onsubmit="return confirmShipmentDelete(this)">
                                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                                <input type="hidden" name="confirm_delete" value="no">
                                <input type="hidden" name="supervisor_confirmation" value="no">
                                <button class="maintenance-delete" type="submit">Zending wissen</button>
                            </form>
                        <?php elseif (isset($selectedDelivery)): ?>
                            <button class="maintenance-delete" type="button" disabled title="Alleen een nog niet verzonden zending kan worden gewist.">Zending wissen (niet mogelijk)</button>
                        <?php endif; ?>
                        <?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
                            <a class="maintenance-link" href="/maintenance/tracking-import">Bestaande GLS-zending koppelen</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
            <form method="post" action="/logout">
                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                <button class="link-button" type="submit">Uitloggen</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
    <?= $content ?>
    <?php if ($flashMessage): ?>
        <div class="flash flash-<?= e($flashMessage['type']) ?>" role="status">
            <span class="flash-message"><?= e($flashMessage['message']) ?></span>
            <button class="button button-small flash-dismiss" type="button" onclick="this.closest('.flash').remove()">Gezien</button>
        </div>
    <?php endif; ?>
</main>
<script>
function confirmShipmentDelete(form) {
    const number = form.dataset.deliveryNumber || '';
    if (!confirm(`Weet u zeker dat u zending ${number} wilt wissen? Dit kan niet ongedaan worden gemaakt.`)) return false;
    if (!confirm(`Haal uw supervisor.\n\nLaat uw supervisor bevestigen dat zending ${number} definitief mag worden gewist.`)) return false;
    form.elements.confirm_delete.value = 'yes';
    form.elements.supervisor_confirmation.value = 'yes';
    return true;
}
function confirmTrackingImport(form) {
    if (!confirm('Bevestig dat deze zendingen al in GLS zijn aangemaakt. De werkplek maakt hiervoor geen nieuw labelbestand aan.')) return false;
    form.elements.confirm_import.value = 'yes';
    return true;
}
function confirmCollectedDelivery(form) {
    if (!form.elements.confirm_collected.checked) return false;
    return confirm('Deze levering wordt als afgehaald afgehandeld en niet naar GLS verzonden. Doorgaan?');
}
const exactSyncForm = document.getElementById('exact-sync-form');
if (exactSyncForm) {
    exactSyncForm.addEventListener('submit', () => {
        const button = exactSyncForm.querySelector('button[type="submit"]');
        const progress = document.getElementById('exact-sync-progress');
        if (button) {
            button.disabled = true;
            button.textContent = 'Bezig met ophalen…';
            button.setAttribute('aria-busy', 'true');
        }
        if (progress) progress.hidden = false;
    });
}
function openLabelAndPrint(url) {
    const printWindow = window.open(url, '_blank');
    if (!printWindow) {
        alert('Sta pop-ups toe om het GLS-label direct te kunnen afdrukken.');
        return false;
    }
    printLabelWindowWhenReady(printWindow);
    return false;
}
function printLabelWindowWhenReady(printWindow) {
    const startedAt = Date.now();
    const timer = window.setInterval(() => {
        if (printWindow.closed || Date.now() - startedAt > 120000) {
            window.clearInterval(timer);
            return;
        }
        try {
            const path = printWindow.location.pathname;
            if (!/\/deliveries\/[^/]+\/label$/.test(path)) return;
            window.clearInterval(timer);
            window.setTimeout(() => {
                try { printWindow.focus(); printWindow.print(); } catch (error) { /* De PDF-tab blijft beschikbaar. */ }
            }, 800);
        } catch (error) { /* Wacht totdat de nieuwe tab de labelroute heeft geladen. */ }
    }, 250);
}
function createAndPrintGlsLabel(form) {
    if (typeof validateShipmentDetails === 'function' && !validateShipmentDetails()) return false;
    const button = form.querySelector('button[type="submit"]');
    form.elements.confirm_gls_request.value = 'yes';
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
        alert('Sta pop-ups toe om het GLS-label direct te kunnen afdrukken.');
        return false;
    }
    const targetName = `gls-label-print-${Date.now()}-${Math.random().toString(36).slice(2)}`;
    printWindow.name = targetName;
    form.target = targetName;
    printWindow.document.write('<p style="font:16px sans-serif;padding:24px">GLS-label wordt aangemaakt…</p>');
    if (button) {
        button.disabled = true;
        button.textContent = 'Label wordt aangemaakt…';
    }
    printLabelWindowWhenReady(printWindow);
    return true;
}
function confirmGlsRelease(form) {
    if (!confirm('Heeft u in GLS gecontroleerd dat deze aanvraag géén zending heeft aangemaakt?')) return false;
    if (!confirm('Alleen na die controle mag een nieuwe poging worden vrijgegeven. Definitief vrijgeven?')) return false;
    form.elements.confirm_no_gls_shipment.value = 'yes';
    return true;
}
</script>
</body>
</html>

