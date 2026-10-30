-- Eenmalig uitvoeren op de bestaande Cloud86-database voordat deze applicatieversie wordt gepubliceerd.
-- Een gekoppelde levering blijft als afzonderlijke Exact-levering bestaan, maar wordt voor GLS
-- meegenomen in de hoofdlevering waar merged_into_exact_entry_id naar verwijst.
ALTER TABLE deliveries
    ADD COLUMN merged_into_exact_entry_id VARCHAR(64) NULL AFTER exact_entry_id,
    ADD INDEX deliveries_merged_into_idx (merged_into_exact_entry_id);
