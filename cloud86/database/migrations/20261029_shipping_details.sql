-- Eenmalig uitvoeren op bestaande databases, vóór de nieuwe PHP-code wordt gedeployed.
-- Bestaande leveringen blijven Parcel. Een lege referentie wordt in de app afgeleid
-- van de laatste vier cijfers van het ordernummer en bij opslaan vastgelegd.
ALTER TABLE deliveries
    ADD COLUMN ship_type CHAR(1) NOT NULL DEFAULT 'P' AFTER order_number,
    ADD COLUMN shipment_reference VARCHAR(64) NULL AFTER ship_type;
