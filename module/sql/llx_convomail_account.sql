-- Copyright (C) 2026 DPG Supply
-- Mail accounts (IMAP + SMTP) owned by a Dolibarr user

CREATE TABLE llx_convomail_account(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,
    fk_user         INTEGER       NOT NULL,
    label           VARCHAR(128),
    email           VARCHAR(255)  NOT NULL,
    imap_host       VARCHAR(255)  NOT NULL,
    imap_port       INTEGER       NOT NULL DEFAULT 993,
    imap_encryption VARCHAR(8)    NOT NULL DEFAULT 'ssl',
    smtp_host       VARCHAR(255)  NOT NULL,
    smtp_port       INTEGER       NOT NULL DEFAULT 587,
    smtp_encryption VARCHAR(8)    NOT NULL DEFAULT 'tls',
    login           VARCHAR(255)  NOT NULL,
    pass_crypted    VARCHAR(255),
    sent_folder     VARCHAR(255),
    is_default      SMALLINT      NOT NULL DEFAULT 0,
    status          SMALLINT      NOT NULL DEFAULT 1,
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER,
    fk_user_modif   INTEGER,
    import_key      VARCHAR(14)
) ENGINE=innodb;
