<?php
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Exact GLS Werkplek</title>
    <?php $cssVersion = (string) (filemtime(__DIR__ . '/../public/assets/app.css') ?: '1'); ?>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($cssVersion) ?>">
</head>
<body>
<header class="topbar">
    <div class="topbar-left">
        <a class="brand" href="/">Exact <span>→</span> GLS</a>
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
            <?php if (isset($selectedDelivery) && ($selectedDelivery['status'] ?? '') !== 'labels_created'): ?>
                <details class="maintenance-menu">
                    <summary>Onderhoud</summary>
                    <div class="maintenance-menu-panel">
                        <form method="post" action="/deliveries/<?= rawurlencode((string) $selectedDelivery['exact_entry_id']) ?>/delete" data-delivery-number="<?= e($selectedDelivery['delivery_number']) ?>" onsubmit="return confirmShipmentDelete(this)">
                            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
                            <input type="hidden" name="confirm_delete" value="no">
                            <input type="hidden" name="supervisor_confirmation" value="no">
                            <button class="maintenance-delete" type="submit">Zending wissen</button>
                        </form>
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
    <?php if ($flashMessage): ?>
        <div class="flash flash-<?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<?php if (isset($selectedDelivery) && ($selectedDelivery['status'] ?? '') !== 'labels_created'): ?>
<script>
function confirmShipmentDelete(form) {
    const number = form.dataset.deliveryNumber || '';
    if (!confirm(`Weet u zeker dat u zending ${number} wilt wissen? Dit kan niet ongedaan worden gemaakt.`)) return false;
    if (!confirm(`Haal uw supervisor.\n\nLaat uw supervisor bevestigen dat zending ${number} definitief mag worden gewist.`)) return false;
    form.elements.confirm_delete.value = 'yes';
    form.elements.supervisor_confirmation.value = 'yes';
    return true;
}
</script>
<?php endif; ?>
</body>
</html>

