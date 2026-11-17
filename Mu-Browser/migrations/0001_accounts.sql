CREATE TABLE accounts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(12)  NOT NULL,
    email         VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    locale        CHAR(2)      NOT NULL DEFAULT 'es',
    register_ip   VARCHAR(45)  NOT NULL,
    created_at    DATETIME     NOT NULL,
    last_login_at DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_accounts_username (username),
    UNIQUE KEY uq_accounts_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE remember_tokens (
    selector   CHAR(24)     NOT NULL,
    token_hash CHAR(64)     NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    expires_at DATETIME     NOT NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (selector),
    KEY ix_remember_account (account_id),
    CONSTRAINT fk_remember_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(64) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME    NOT NULL,
    PRIMARY KEY (id),
    KEY ix_attempts_username (username, attempted_at),
    KEY ix_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
