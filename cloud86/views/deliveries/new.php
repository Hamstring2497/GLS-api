<div class="page-heading">
    <div><p class="eyebrow">Testinvoer</p><h1>Nieuwe levering</h1></div>
    <a href="/">Annuleren</a>
</div>
<form method="post" action="/deliveries" class="panel form-grid">
    <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
    <fieldset>
        <legend>Exact-referenties</legend>
        <div class="grid-2">
            <label>Exact Entry ID<input name="exact_entry_id" value="<?= e(bin2hex(random_bytes(16))) ?>" required></label>
            <label>Verzenddatum<input type="date" name="shipping_date" value="<?= e(date('Y-m-d')) ?>"></label>
            <label>Leveringsnummer<input name="delivery_number" required></label>
            <label>Ordernummer<input name="order_number" required></label>
        </div>
    </fieldset>
    <fieldset>
        <legend>Afleveradres</legend>
        <div class="grid-2">
            <label class="span-2">Bedrijfsnaam / ontvanger<input name="name" required></label>
            <label>Straat<input name="street" required></label>
            <label>Huisnummer<input name="house_no" required></label>
            <label>Postcode<input name="zip_code" required></label>
            <label>Plaats<input name="city" required></label>
            <label>Landcode<input name="country_code" maxlength="2" value="NL" required></label>
            <label>Contactpersoon<input name="contact"></label>
            <label>Telefoon<input name="phone"></label>
            <label>E-mailadres *<input type="email" name="email" required></label>
        </div>
    </fieldset>
    <button class="button" type="submit">Levering opslaan</button>
</form>

