-- Apply once after 20261029_shipping_details.sql.
ALTER TABLE deliveries
    ADD COLUMN freight_unit_type CHAR(2) NOT NULL DEFAULT 'CO' AFTER ship_type;
