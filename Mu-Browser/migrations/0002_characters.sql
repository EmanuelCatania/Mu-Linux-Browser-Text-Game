CREATE TABLE characters (
    id              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    account_id      INT UNSIGNED      NOT NULL,
    name            VARCHAR(12)       NOT NULL,
    class           TINYINT UNSIGNED  NOT NULL,
    level           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    strength        INT UNSIGNED      NOT NULL,
    agility         INT UNSIGNED      NOT NULL,
    stamina         INT UNSIGNED      NOT NULL,
    energy          INT UNSIGNED      NOT NULL,
    points          INT UNSIGNED      NOT NULL DEFAULT 0,
    fruits          INT UNSIGNED      NOT NULL DEFAULT 0,
    experience      BIGINT UNSIGNED   NOT NULL DEFAULT 0,
    hp_cur          INT UNSIGNED      NOT NULL,
    mp_cur          INT UNSIGNED      NOT NULL,
    monsters_killed INT UNSIGNED      NOT NULL DEFAULT 0,
    created_at      DATETIME          NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_characters_name (name),
    KEY ix_characters_account (account_id),
    KEY ix_characters_ranking (experience),
    CONSTRAINT fk_characters_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE character_skills (
    character_id INT UNSIGNED NOT NULL,
    skill        VARCHAR(2)   NOT NULL,
    PRIMARY KEY (character_id, skill),
    CONSTRAINT fk_character_skills_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE accounts
    ADD COLUMN zen BIGINT UNSIGNED NOT NULL DEFAULT 5000 AFTER locale,
    ADD COLUMN active_character_id INT UNSIGNED NULL AFTER zen,
    ADD CONSTRAINT fk_accounts_active_character FOREIGN KEY (active_character_id) REFERENCES characters (id) ON DELETE SET NULL;
