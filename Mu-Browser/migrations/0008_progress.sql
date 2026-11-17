-- Reset (volver al nivel 1 al llegar al máximo) y misión de evolución de clase.
-- evo_step: 0 sin empezar, 1 buscando el Scroll of Emperor, 2 lo tiene, 3 buscando el tesoro de su clase,
-- 4 lo tiene, 5 ya evolucionó.
ALTER TABLE characters
    ADD COLUMN resets INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN evo_step TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD KEY ix_characters_resets (resets);

-- Monstruos dorados de los mapas agregados después del original.
INSERT INTO golden_spawns (monster, map) VALUES
    ('golden_tantalos', 'tarkan'),
    ('golden_crust', 'icarus'),
    ('golden_stone_golem', 'aida'),
    ('golden_satyros', 'kanturu'),
    ('golden_twin_tail', 'kanturu_relics'),
    ('golden_napin', 'swamp_of_peace'),
    ('great_golden_dragon', 'vulcanus'),
    ('golden_iron_knight', 'raklion');
