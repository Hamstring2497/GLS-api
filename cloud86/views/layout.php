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
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/">Exact <span>→</span> GLS</a>
    <?php if (\App\Security::isAuthenticated()): ?>
        <nav>
            <?php if (\App\Security::user()): ?><span class="user-name"><?= e(\App\Security::user()['display_name']) ?></span><?php endif; ?>
            <a href="/">Leveringen</a>
            <a class="button button-small" href="/deliveries/new">Nieuwe levering</a>
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
</body>
</html>

