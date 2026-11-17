-- Party: el id del party es el id del personaje líder. party_exp = experiencia que le dejaron sus compañeros
-- y que todavía no cobró (entra en su próxima ronda).
ALTER TABLE characters
    ADD COLUMN party_id INT UNSIGNED NULL,
    ADD COLUMN party_invite_id INT UNSIGNED NULL,
    ADD COLUMN party_exp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY ix_characters_party (party_id);
