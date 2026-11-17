-- Misión en curso y entrada pendiente a un mapa de evento (Kalima).
ALTER TABLE characters
    ADD COLUMN quest_id INT UNSIGNED NULL,
    ADD COLUMN quest_score INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN event_map VARCHAR(20) NULL;

CREATE TABLE character_quests_done (
    character_id INT UNSIGNED NOT NULL,
    quest_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (character_id, quest_id),
    CONSTRAINT fk_quests_done_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
