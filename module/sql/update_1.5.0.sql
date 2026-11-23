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
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
--
-- 1.5.0: access levels per mailbox for users and user groups, and Employee leaving. Every line can run again:
-- errors such as "column already exists" or "key already exists" are ignored by the module loader.

-- Access levels, for users and user groups: 1 read and file, 2 also organise, 3 also send, 4 also manage. Existing
-- grants keep what they allowed: "read and file" could organise messages (2), "read, file and send" (3).
ALTER TABLE llx_convomail_account_access ADD COLUMN fk_usergroup integer NULL;
ALTER TABLE llx_convomail_account_access ADD COLUMN level smallint DEFAULT 0 NOT NULL;
ALTER TABLE llx_convomail_account_access MODIFY COLUMN fk_user integer NULL;
-- VPGSQL8.2 ALTER TABLE llx_convomail_account_access ALTER COLUMN fk_user DROP NOT NULL;
UPDATE llx_convomail_account_access SET level = CASE WHEN can_send = 1 THEN 3 ELSE 2 END WHERE level = 0;
ALTER TABLE llx_convomail_account_access ADD UNIQUE INDEX uk_convomail_account_access_group (fk_account, fk_usergroup);
ALTER TABLE llx_convomail_account_access ADD INDEX idx_convomail_account_access_fk_usergroup (fk_usergroup);
DELETE FROM llx_convomail_account_access WHERE fk_usergroup IS NOT NULL AND fk_usergroup NOT IN (SELECT rowid FROM llx_usergroup);

-- Employee leaving: a personal mailbox blocked on departure is deleted on this date by a scheduled job
ALTER TABLE llx_convomail_account ADD COLUMN date_delete datetime NULL;
