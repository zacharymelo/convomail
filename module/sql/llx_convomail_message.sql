-- Copyright (C) 2026 DPG Supply
-- Links a mail message (by Message-ID) to the agenda event it was logged as

CREATE TABLE llx_convomail_message(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,
    fk_account      INTEGER       NOT NULL,
    folder          VARCHAR(255),
    uid             INTEGER,
    msgid           VARCHAR(255)  NOT NULL,
    in_reply_to     VARCHAR(255),
    direction       SMALLINT      NOT NULL DEFAULT 1,
    fk_actioncomm   INTEGER,
    date_message    DATETIME,
    read_receipt    SMALLINT      NOT NULL DEFAULT 0,
    track_key       VARCHAR(32),
    nb_open         INTEGER       NOT NULL DEFAULT 0,
    date_first_open DATETIME,
    date_last_open  DATETIME,
    open_detail     TEXT,
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER,
    import_key      VARCHAR(14)
) ENGINE=innodb;
