-- Jefes de mapa: uno por mapa para todo el servidor. defeated_at NULL = nunca lo vencieron (está vivo).
CREATE TABLE world_bosses (
    monster VARCHAR(40) NOT NULL PRIMARY KEY,
    map VARCHAR(40) NOT NULL,
    defeated_at DATETIME NULL,
    defeated_by VARCHAR(12) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO world_bosses (monster, map) VALUES
    ('nightmare', 'kanturu_relics'),
    ('selupan', 'raklion'),
    ('medusa', 'swamp_of_peace'),
    ('lord_of_ferea', 'ferea'),
    ('nix', 'nixie');
