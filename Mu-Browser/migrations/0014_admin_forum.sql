-- Panel de administración, publicaciones de la portada y foro.
ALTER TABLE accounts
    ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN banned_at DATETIME NULL;

-- Valores de data/game.php cambiados desde el panel (ver GameSettings). name = ruta con puntos, value = JSON.
CREATE TABLE settings (
    name       VARCHAR(80)  NOT NULL,
    value      VARCHAR(400) NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title      VARCHAR(120) NOT NULL,
    body       TEXT         NOT NULL,
    image      VARCHAR(80)  NULL,
    published  TINYINT(1)   NOT NULL DEFAULT 1,
    account_id INT UNSIGNED NULL,
    created_at DATETIME     NOT NULL,
    updated_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_news_published (published, created_at),
    CONSTRAINT fk_news_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_threads (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category     VARCHAR(20)  NOT NULL,
    title        VARCHAR(80)  NOT NULL,
    account_id   INT UNSIGNED NOT NULL,
    replies      INT UNSIGNED NOT NULL DEFAULT 0,
    locked       TINYINT(1)   NOT NULL DEFAULT 0,
    pinned       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL,
    last_post_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_forum_threads_list (category, pinned, last_post_at),
    CONSTRAINT fk_forum_threads_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_posts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id  INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    body       TEXT         NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_forum_posts_thread (thread_id, id),
    KEY ix_forum_posts_account (account_id, created_at),
    CONSTRAINT fk_forum_posts_thread FOREIGN KEY (thread_id) REFERENCES forum_threads (id) ON DELETE CASCADE,
    CONSTRAINT fk_forum_posts_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
