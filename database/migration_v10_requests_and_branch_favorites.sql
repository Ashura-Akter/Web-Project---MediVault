-- MediVault v10 migration
-- Run this once against an existing MediVault database.
-- It makes My Shelf branch-specific and keeps restricted requests separate
-- from normal reservation handling in the pharmacist UI.

ALTER TABLE favorite_medicines
    ADD COLUMN pharmacy_id INT UNSIGNED NULL AFTER medicine_id;

-- Existing favorites are assigned to the first active pharmacy that currently
-- has stock for that medicine. New favorites always store the exact branch.
UPDATE favorite_medicines f
JOIN (
    SELECT medicine_id, MIN(pharmacy_id) AS pharmacy_id
    FROM inventory_batches
    WHERE (quantity_on_hand - reserved_quantity) > 0
      AND expiry_date > CURRENT_DATE()
    GROUP BY medicine_id
) x ON x.medicine_id = f.medicine_id
SET f.pharmacy_id = x.pharmacy_id
WHERE f.pharmacy_id IS NULL;

-- If an old favorite has no current stock, fall back to the first active pharmacy.
UPDATE favorite_medicines f
JOIN (
    SELECT MIN(pharmacy_id) AS pharmacy_id FROM pharmacies WHERE is_active = TRUE
) x
SET f.pharmacy_id = x.pharmacy_id
WHERE f.pharmacy_id IS NULL;

ALTER TABLE favorite_medicines
    MODIFY pharmacy_id INT UNSIGNED NOT NULL;

ALTER TABLE favorite_medicines
    DROP INDEX uq_favorite,
    ADD UNIQUE KEY uq_favorite (user_id, medicine_id, pharmacy_id),
    ADD KEY fk_favorite_pharmacy (pharmacy_id);

ALTER TABLE favorite_medicines
    ADD CONSTRAINT fk_favorite_pharmacy
    FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(pharmacy_id)
    ON DELETE CASCADE ON UPDATE CASCADE;
