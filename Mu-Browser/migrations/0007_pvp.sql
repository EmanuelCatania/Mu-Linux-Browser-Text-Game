-- PvP: duelos entre personajes, con apuesta opcional de zen e ítems.
-- Los ítems apostados están en el contenedor "duel" de cada cuenta y lo ganado llega al contenedor "prize".
ALTER TABLE characters
    ADD COLUMN pvp_wins INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN pvp_losses INT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY ix_characters_pvp (pvp_wins);

-- status: pending (espera al desafiado) → answered (espera la confirmación del retador) → finished;
-- o declined / cancelled. Un duelo abierto que nadie toca durante un rato se da por vencido.
CREATE TABLE duels (
    id                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    challenger_id         INT UNSIGNED    NOT NULL,
    target_id             INT UNSIGNED    NOT NULL,
    challenger_account_id INT UNSIGNED    NOT NULL,
    target_account_id     INT UNSIGNED    NOT NULL,
    zen                   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status                VARCHAR(10)     NOT NULL,
    winner_id             INT UNSIGNED    NULL,
    result                MEDIUMTEXT      NULL,
    created_at            DATETIME        NOT NULL,
    updated_at            DATETIME        NOT NULL,
    PRIMARY KEY (id),
    KEY ix_duels_challenger (challenger_account_id, status),
    KEY ix_duels_target (target_account_id, status),
    KEY ix_duels_challenger_char (challenger_id),
    KEY ix_duels_target_char (target_id),
    CONSTRAINT fk_duels_challenger FOREIGN KEY (challenger_id) REFERENCES characters (id) ON DELETE CASCADE,
    CONSTRAINT fk_duels_target FOREIGN KEY (target_id) REFERENCES characters (id) ON DELETE CASCADE,
    CONSTRAINT fk_duels_challenger_account FOREIGN KEY (challenger_account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_duels_target_account FOREIGN KEY (target_account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
