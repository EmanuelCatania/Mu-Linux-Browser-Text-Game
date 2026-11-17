-- Ítems de los jugadores. `container` dice dónde está (inventory, equipment y, más adelante, vault/store)
-- y `slot` la celda superior izquierda que ocupa (inventario de 8x8) o el número de slot de equipo.
CREATE TABLE items (
    id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    character_id INT UNSIGNED      NOT NULL,
    container    VARCHAR(12)       NOT NULL,
    slot         SMALLINT UNSIGNED NOT NULL,
    code         VARCHAR(120)      NOT NULL,
    created_at   DATETIME          NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_items_place (character_id, container, slot),
    CONSTRAINT fk_items_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batalla en curso de cada personaje. `version` evita que dos pedidos simultáneos pisen el mismo turno.
CREATE TABLE battles (
    character_id INT UNSIGNED NOT NULL,
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    state        MEDIUMTEXT   NOT NULL,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (character_id),
    CONSTRAINT fk_battles_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién está en cada spot y cuánta experiencia por turno viene sacando (para el cupo y el reparto de exp).
CREATE TABLE presence (
    character_id INT UNSIGNED NOT NULL,
    location     VARCHAR(40)  NOT NULL,
    exp_rate     INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at   DATETIME     NOT NULL,
    PRIMARY KEY (character_id),
    KEY ix_presence_location (location, updated_at),
    CONSTRAINT fk_presence_character FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Golden Invasion: un monstruo dorado por mapa y cuándo apareció por última vez.
CREATE TABLE golden_spawns (
    monster        VARCHAR(40) NOT NULL,
    map            VARCHAR(40) NOT NULL,
    encountered_at DATETIME    NULL,
    encountered_by VARCHAR(12) NULL,
    PRIMARY KEY (monster),
    UNIQUE KEY uq_golden_map (map)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO golden_spawns (monster, map) VALUES
    ('golden_budge_dragon', 'lorencia'),
    ('golden_goblin', 'noria'),
    ('golden_knight', 'dungeon'),
    ('golden_titan', 'devias'),
    ('golden_devil', 'lost_tower'),
    ('golden_rabbit', 'elbeland'),
    ('golden_lizard_king', 'atlans');
