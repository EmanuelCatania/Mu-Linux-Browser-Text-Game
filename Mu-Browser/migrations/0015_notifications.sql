-- Avisos para una cuenta (duelos, ventas, invitaciones, respuestas en el foro).
-- message es una clave de traducción y params sus valores en JSON.
CREATE TABLE notifications (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id INT UNSIGNED NOT NULL,
    message    VARCHAR(60)  NOT NULL,
    params     VARCHAR(400) NOT NULL DEFAULT '{}',
    link       VARCHAR(120) NULL,
    read_at    DATETIME     NULL,
    created_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY ix_notifications_account (account_id, read_at),
    CONSTRAINT fk_notifications_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
