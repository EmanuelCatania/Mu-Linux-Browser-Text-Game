-- El baúl es de la cuenta, no del personaje: un ítem pertenece a un personaje (inventory, equipment)
-- o a una cuenta (vault), nunca a los dos.
ALTER TABLE items
    MODIFY character_id INT UNSIGNED NULL,
    ADD COLUMN account_id INT UNSIGNED NULL AFTER character_id,
    ADD UNIQUE KEY uq_items_account_place (account_id, container, slot),
    ADD CONSTRAINT fk_items_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE;
