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
            <label>Naam<input name="name" maxlength="30" required></label>
            <label>Naam 2<input name="name_2" maxlength="30"></label>
            <label>Naam 3<input name="name_3" maxlength="30"></label>
            <label>Straat<input name="street" maxlength="40" required></label>
            <label>Huisnummer<input name="house_no" maxlength="5" required></label>
            <label>Toevoeging<input name="house_no_addition" maxlength="5"></label>
            <label>Postcode<input name="zip_code" maxlength="8" required></label>
            <label>Plaats<input name="city" maxlength="30" required></label>
            <label>Landcode<input name="country_code" maxlength="2" value="NL" required></label>
            <label>Contactpersoon<input name="contact" maxlength="30"></label>
            <label>Telefoon<input name="phone" maxlength="25"></label>
            <label>E-mailadres *<input type="email" name="email" maxlength="320" required></label>
            <label>Notitie 1<input name="note_1" maxlength="30"></label>
            <label>Notitie 2<input name="note_2" maxlength="30"></label>
        </div>
    </fieldset>
    <button class="button" type="submit">Levering opslaan</button>
</form>

