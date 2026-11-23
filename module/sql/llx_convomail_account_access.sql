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
-- Users and user groups granted a shared mail account (llx_convomail_account.fk_user IS NULL), each with a level:
-- 1 read and file, 2 also organise (move, archive, delete, spam, flags), 3 also send as the shared address,
-- 4 also manage (folders, sign-in, automatic filing). One of fk_user and fk_usergroup is set.

CREATE TABLE llx_convomail_account_access(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,   -- entity of the mailbox
    fk_account      INTEGER       NOT NULL,
    fk_user         INTEGER,
    fk_usergroup    INTEGER,
    level           SMALLINT      NOT NULL DEFAULT 0,
    can_send        SMALLINT      NOT NULL DEFAULT 0,   -- kept for older code: 1 when level >= 3
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER
) ENGINE=innodb;
