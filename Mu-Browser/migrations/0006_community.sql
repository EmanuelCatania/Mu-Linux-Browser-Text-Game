-- Tienda personal: los ítems del contenedor "store" llevan precio y se ven en el mercado mientras la tienda está abierta.
ALTER TABLE items ADD COLUMN price BIGINT UNSIGNED NULL;

ALTER TABLE accounts ADD COLUMN store_open TINYINT(1) NOT NULL DEFAULT 0;

-- Clanes. Si se borra el personaje fundador, el clan se disuelve.
CREATE TABLE guilds (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name       VARCHAR(12)  NOT NULL,
    founder_id INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_guilds_name (name),
    UNIQUE KEY uq_guilds_founder (founder_id),
    CONSTRAINT fk_guilds_founder FOREIGN KEY (founder_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE characters
    ADD COLUMN guild_id INT UNSIGNED NULL,
    ADD COLUMN guild_invite_id INT UNSIGNED NULL,
    ADD COLUMN last_seen_at DATETIME NULL,
    ADD KEY ix_characters_seen (last_seen_at),
    ADD KEY ix_characters_kills (monsters_killed),
    ADD CONSTRAINT fk_characters_guild FOREIGN KEY (guild_id) REFERENCES guilds (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_characters_guild_invite FOREIGN KEY (guild_invite_id) REFERENCES guilds (id) ON DELETE SET NULL;

CREATE TABLE chat_messages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    character_id INT UNSIGNED    NOT NULL,
    poster       VARCHAR(12)     NOT NULL,
    dest         VARCHAR(40)     NOT NULL,
    body         VARCHAR(120)    NOT NULL,
    created_at   DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_chat_dest (dest, id),
    KEY ix_chat_poster (character_id, id),
    CONSTRAINT fk_chat_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
