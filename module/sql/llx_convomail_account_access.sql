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
-- Users granted a shared mail account (llx_convomail_account.fk_user IS NULL)

CREATE TABLE llx_convomail_account_access(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,   -- entity of the mailbox
    fk_account      INTEGER       NOT NULL,
    fk_user         INTEGER       NOT NULL,
    can_send        SMALLINT      NOT NULL DEFAULT 0,   -- 0 read and file, 1 also send as the shared address
    date_creation   DATETIME      NOT NULL,
    tms             TIMESTAMP,
    fk_user_creat   INTEGER
) ENGINE=innodb;
