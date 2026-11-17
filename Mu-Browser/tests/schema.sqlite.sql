-- Equivalente SQLite de migrations/ para los tests. Mantener en sincronía.
CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    email TEXT NOT NULL COLLATE NOCASE UNIQUE,
    password_hash TEXT NOT NULL,
    locale TEXT NOT NULL DEFAULT 'es',
    zen INTEGER NOT NULL DEFAULT 5000,
    store_open INTEGER NOT NULL DEFAULT 0,
    active_character_id INTEGER NULL REFERENCES characters (id) ON DELETE SET NULL,
    register_ip TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_login_at TEXT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    banned_at TEXT NULL
);
CREATE TABLE remember_tokens (
    selector TEXT PRIMARY KEY,
    token_hash TEXT NOT NULL,
    account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL
);
CREATE TABLE characters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    class INTEGER NOT NULL,
    level INTEGER NOT NULL DEFAULT 1,
    strength INTEGER NOT NULL,
    agility INTEGER NOT NULL,
    stamina INTEGER NOT NULL,
    energy INTEGER NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    fruits INTEGER NOT NULL DEFAULT 0,
    experience INTEGER NOT NULL DEFAULT 0,
    hp_cur INTEGER NOT NULL,
    mp_cur INTEGER NOT NULL,
    monsters_killed INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    quest_id INTEGER NULL,
    quest_score INTEGER NOT NULL DEFAULT 0,
    event_map TEXT NULL,
    guild_id INTEGER NULL REFERENCES guilds (id) ON DELETE SET NULL,
    guild_invite_id INTEGER NULL REFERENCES guilds (id) ON DELETE SET NULL,
    last_seen_at TEXT NULL,
    pvp_wins INTEGER NOT NULL DEFAULT 0,
    pvp_losses INTEGER NOT NULL DEFAULT 0,
    resets INTEGER NOT NULL DEFAULT 0,
    evo_step INTEGER NOT NULL DEFAULT 0,
    command INTEGER NOT NULL DEFAULT 0,
    party_id INTEGER NULL,
    party_invite_id INTEGER NULL,
    party_exp INTEGER NOT NULL DEFAULT 0,
    master_level INTEGER NOT NULL DEFAULT 0,
    master_exp INTEGER NOT NULL DEFAULT 0,
    master_points INTEGER NOT NULL DEFAULT 0,
    evo_progress TEXT NOT NULL DEFAULT '{}'
);
CREATE TABLE guilds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    founder_id INTEGER NOT NULL UNIQUE REFERENCES characters (id) ON DELETE CASCADE,
    created_at TEXT NOT NULL
);
CREATE TABLE chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    poster TEXT NOT NULL,
    dest TEXT NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE character_quests_done (
    character_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    quest_id INTEGER NOT NULL,
    PRIMARY KEY (character_id, quest_id)
);
CREATE TABLE character_skills (
    character_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    skill TEXT NOT NULL,
    PRIMARY KEY (character_id, skill)
);
CREATE TABLE items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NULL REFERENCES characters (id) ON DELETE CASCADE,
    account_id INTEGER NULL REFERENCES accounts (id) ON DELETE CASCADE,
    container TEXT NOT NULL,
    slot INTEGER NOT NULL,
    code TEXT NOT NULL,
    created_at TEXT NOT NULL,
    price INTEGER NULL,
    UNIQUE (character_id, container, slot),
    UNIQUE (account_id, container, slot)
);
CREATE TABLE battles (
    character_id INTEGER PRIMARY KEY REFERENCES characters (id) ON DELETE CASCADE,
    version INTEGER NOT NULL DEFAULT 1,
    state TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE presence (
    character_id INTEGER PRIMARY KEY REFERENCES characters (id) ON DELETE CASCADE,
    location TEXT NOT NULL,
    exp_rate INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL
);
CREATE TABLE golden_spawns (
    monster TEXT PRIMARY KEY,
    map TEXT NOT NULL UNIQUE,
    encountered_at TEXT NULL,
    encountered_by TEXT NULL
);
INSERT INTO golden_spawns (monster, map) VALUES
    ('golden_budge_dragon', 'lorencia'), ('golden_goblin', 'noria'), ('golden_knight', 'dungeon'),
    ('golden_titan', 'devias'), ('golden_devil', 'lost_tower'), ('golden_rabbit', 'elbeland'),
    ('golden_lizard_king', 'atlans'),
    ('golden_tantalos', 'tarkan'), ('golden_crust', 'icarus'), ('golden_stone_golem', 'aida'),
    ('golden_satyros', 'kanturu'), ('golden_twin_tail', 'kanturu_relics'), ('golden_napin', 'swamp_of_peace'),
    ('great_golden_dragon', 'vulcanus'), ('golden_iron_knight', 'raklion');

CREATE TABLE duels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    challenger_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    target_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    challenger_account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    target_account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    zen INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    winner_id INTEGER NULL,
    result TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE world_bosses (
    monster TEXT NOT NULL PRIMARY KEY,
    map TEXT NOT NULL,
    defeated_at TEXT NULL,
    defeated_by TEXT NULL
);

INSERT INTO world_bosses (monster, map) VALUES
    ('nightmare', 'kanturu_relics'),
    ('selupan', 'raklion'),
    ('medusa', 'swamp_of_peace'),
    ('lord_of_ferea', 'ferea'),
    ('nix', 'nixie');

CREATE TABLE character_master (
    character_id INTEGER NOT NULL REFERENCES characters (id) ON DELETE CASCADE,
    node TEXT NOT NULL,
    `rank` INTEGER NOT NULL,
    PRIMARY KEY (character_id, node)
);

CREATE TABLE settings (
    name TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE news (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    body TEXT NOT NULL,
    image TEXT NULL,
    published INTEGER NOT NULL DEFAULT 1,
    account_id INTEGER NULL REFERENCES accounts (id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE forum_threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category TEXT NOT NULL,
    title TEXT NOT NULL,
    account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    replies INTEGER NOT NULL DEFAULT 0,
    locked INTEGER NOT NULL DEFAULT 0,
    pinned INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    last_post_at TEXT NOT NULL
);
CREATE TABLE forum_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    thread_id INTEGER NOT NULL REFERENCES forum_threads (id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
    message TEXT NOT NULL,
    params TEXT NOT NULL DEFAULT '{}',
    link TEXT NULL,
    read_at TEXT NULL,
    created_at TEXT NOT NULL
);
