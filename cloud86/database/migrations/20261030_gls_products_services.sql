-- Eenmalig uitvoeren op de bestaande Cloud86-database voordat deze applicatieversie wordt gepubliceerd.
-- Slaat de per-zending gekozen GLS-product- en serviceopties op. Bestaande leveringen houden
-- DirectDeliveryPlus als standaard en hebben geen aanvullende services.
ALTER TABLE deliveries
    ADD COLUMN gls_product VARCHAR(32) NOT NULL DEFAULT 'direct_delivery_plus' AFTER shipment_reference,
    ADD COLUMN gls_options_json JSON NULL AFTER gls_product;
