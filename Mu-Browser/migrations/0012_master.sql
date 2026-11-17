-- Nivel master (después del nivel 400, para la tercera clase) y árbol de habilidades pasivas.
-- master_exp es lo acumulado dentro del nivel master actual.
ALTER TABLE characters
    ADD COLUMN master_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN master_exp BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN master_points SMALLINT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE character_master (
    character_id INT UNSIGNED NOT NULL,
    node VARCHAR(20) NOT NULL,
    `rank` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (character_id, node),
    CONSTRAINT fk_character_master FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
