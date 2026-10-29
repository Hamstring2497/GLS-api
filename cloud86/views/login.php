<section class="auth-card">
    <p class="eyebrow">Magazijnwerkplek</p>
    <h1>Inloggen</h1>
    <p class="muted">Log in om leveringen te verwerken en GLS-labels aan te maken.</p>
    <form method="post" action="/login" class="stack">
        <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
        <label>Gebruikersnaam<input name="username" autocomplete="username" required autofocus></label>
        <label>Wachtwoord<input type="password" name="password" autocomplete="current-password" required></label>
        <button class="button" type="submit">Inloggen</button>
    </form>
</section>

