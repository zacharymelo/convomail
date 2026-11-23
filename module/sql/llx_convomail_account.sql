-- ============================================================================
-- Copyright (C) 2026 DPG Supply
-- Copyright (C) 2026 Nick Fragoulis
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- Mail accounts (IMAP + SMTP) owned by a Dolibarr user

CREATE TABLE llx_convomail_account(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,
    fk_user         INTEGER,                            -- owner; NULL for a shared mailbox
    label           VARCHAR(128),
    email           VARCHAR(255)  NOT NULL,
    imap_host       VARCHAR(255)  NOT NULL,
    imap_port       INTEGER       NOT NULL DEFAULT 993,
    imap_encryption VARCHAR(8)    NOT NULL DEFAULT 'ssl',
    smtp_host       VARCHAR(255)  NOT NULL,
    smtp_port       INTEGER       NOT NULL DEFAULT 587,
    smtp_encryption VARCHAR(8)    NOT NULL DEFAULT 'tls',
    login           VARCHAR(255)  NOT NULL,
    auth_type       VARCHAR(16)   NOT NULL DEFAULT 'password',  -- password | oauth
    oauth_app       VARCHAR(64),                               -- core OAuth app used, e.g. GOOGLE-Mail
    pass_crypted    VARCHAR(255),
    sent_folder     VARCHAR(255),
    server_saves_sent SMALLINT    NOT NULL DEFAULT 0,
    is_default      SMALLINT      NOT NULL DEFAULT 0,
    status          SMALLINT      NOT NULL DEFAULT 1,
    date_delete     DATETIME,                                  -- set when its user leaves: deleted on this date (scheduled job)
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER,
    fk_user_modif   INTEGER,
    import_key      VARCHAR(14)
) ENGINE=innodb;
