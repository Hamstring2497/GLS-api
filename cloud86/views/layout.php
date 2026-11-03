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
    <title>ParcelPilot</title>
    <?php $cssVersion = (string) (filemtime(__DIR__ . '/../public/assets/app.css') ?: '1'); ?>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar-left">
        <a class="brand" href="/" aria-label="ParcelPilot – naar leveringen">
            <span class="brand-mark" aria-hidden="true">PP</span>
            <span class="brand-wordmark">Parcel<span>Pilot</span></span>
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
            <?php if ((\App\Security::user()['role'] ?? '') === 'admin' || $canDeleteSelectedDelivery): ?>
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
                        <?php endif; ?>
                        <?php if ((\App\Security::user()['role'] ?? '') === 'admin'): ?>
                            <a class="maintenance-link" href="/maintenance/tracking-import">Bestaande GLS-zending koppelen</a>
                            <form method="post" action="/maintenance/refresh-deliveries" onsubmit="return confirmTableRefresh(this)">
                                <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                                <input type="hidden" name="confirm_refresh" value="no">
                                <button class="maintenance-refresh" type="submit">Tabel leegmaken en opnieuw ophalen</button>
                            </form>
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
function confirmTableRefresh(form) {
    if (!confirm('De volledige levertabel, inclusief dozen en statussen, wordt verwijderd. Wilt u doorgaan?')) return false;
    if (!confirm('Na het leegmaken worden de nieuwste leveringen direct opnieuw uit Exact opgehaald. Definitief uitvoeren?')) return false;
    form.elements.confirm_refresh.value = 'yes';
    return true;
}
function confirmTrackingImport(form) {
    if (!confirm('Bevestig dat deze zendingen al in GLS zijn aangemaakt. De werkplek maakt hiervoor geen nieuw labelbestand aan.')) return false;
    form.elements.confirm_import.value = 'yes';
    return true;
}
function openLabelAndPrint(url) {
    const printWindow = window.open('', 'gls-label-print');
    if (!printWindow) {
        alert('Sta pop-ups toe om het GLS-label direct te kunnen afdrukken.');
        return false;
    }
    printWindow.document.write('<p style="font:16px sans-serif;padding:24px">GLS-label wordt geopend…</p>');
    printWindow.location.href = url;
    window.setTimeout(() => {
        try { printWindow.focus(); printWindow.print(); } catch (error) { /* Het PDF-venster blijft beschikbaar. */ }
    }, 1200);
    return false;
}
function createAndPrintGlsLabel(form) {
    const button = form.querySelector('button[type="submit"]');
    form.elements.confirm_gls_request.value = 'yes';
    if (button) button.disabled = true;
    const printWindow = window.open('', 'gls-label-print');
    if (!printWindow) {
        if (button) button.disabled = false;
        alert('Sta pop-ups toe om het GLS-label direct te kunnen afdrukken.');
        return false;
    }
    printWindow.document.write('<p style="font:16px sans-serif;padding:24px">GLS-label wordt aangemaakt…</p>');
    fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        redirect: 'follow'
    }).then(async response => {
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok || !contentType.includes('application/pdf')) {
            const html = await response.text();
            printWindow.document.open();
            printWindow.document.write(html);
            printWindow.document.close();
            if (button) button.disabled = false;
            return;
        }
        const pdfUrl = URL.createObjectURL(await response.blob());
        printWindow.location.href = pdfUrl;
        window.setTimeout(() => {
            try { printWindow.focus(); printWindow.print(); } catch (error) { /* Het PDF-venster blijft beschikbaar. */ }
        }, 1200);
        window.setTimeout(() => { window.location.reload(); }, 1800);
    }).catch(() => {
        printWindow.document.open();
        printWindow.document.write('<p style="font:16px sans-serif;padding:24px">Het label kon niet worden geopend. Sluit dit venster en probeer het opnieuw.</p>');
        printWindow.document.close();
        if (button) button.disabled = false;
    });
    return false;
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

