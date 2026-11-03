<section class="page-heading compact-heading">
    <div>
        <p class="eyebrow">ONDERHOUD</p>
        <h1>Bestaande GLS-zending koppelen</h1>
        <p>Koppel zendingen die al in GLS Print &amp; Ship zijn gemaakt. Hiermee voorkomt u een tweede GLS-zending.</p>
    </div>
</section>

<section class="detail-card tracking-import-card">
    <div class="section-bar">
        <h2>Koppelingen</h2>
        <span>Geen nieuw labelbestand · alleen tracking en status worden vastgelegd</span>
    </div>
    <div class="tracking-import-content">
        <p>Gebruik per regel: <strong>leverings-ID('s) = trackingnummer(s)</strong>. Scheid meerdere waarden met een komma.</p>
        <form method="post" action="/maintenance/tracking-import" onsubmit="return confirmTrackingImport(this)">
            <input type="hidden" name="_token" value="<?= e(\App\Security::csrfToken()) ?>">
            <input type="hidden" name="confirm_import" value="no">
            <label for="tracking-assignments">Bestaande GLS-koppelingen</label>
            <textarea id="tracking-assignments" name="assignments" rows="9" required placeholder="9080458, 9080462 = 73220023886849"></textarea>
            <div class="form-actions">
                <a class="button button-secondary" href="/">Annuleren</a>
                <button class="button" type="submit">Tracking koppelen</button>
            </div>
        </form>
    </div>
</section>

<?php if ($deliveries !== []): ?>
<section class="detail-card tracking-import-card">
    <div class="section-bar"><h2>Leveringen zonder tracking</h2></div>
    <div class="tracking-import-list">
        <?php foreach ($deliveries as $delivery): ?>
            <div><strong><?= e($delivery['delivery_number']) ?></strong> · order <?= e($delivery['order_number']) ?> · <?= e($delivery['address']['name']) ?></div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
