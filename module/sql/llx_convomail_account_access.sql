-- Copyright (C) 2026 DPG Supply
-- Users granted a shared mail account (llx_convomail_account.fk_user = 0)

CREATE TABLE llx_convomail_account_access(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    fk_account      INTEGER       NOT NULL,
    fk_user         INTEGER       NOT NULL,
    can_send        SMALLINT      NOT NULL DEFAULT 0,   -- 0 read and file, 1 also send as the shared address
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER
) ENGINE=innodb;
