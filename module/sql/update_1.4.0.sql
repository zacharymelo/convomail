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
-- 1.4.0: a shared mailbox has no owner (fk_user NULL instead of 0), grants carry the entity of their mailbox, and a
-- grant can not point at a mailbox that does not exist. Every line can run again: errors such as "column already
-- exists" or "key already exists" are ignored by the module loader.
--
-- No foreign key points at core tables (llx_user, llx_actioncomm): core deletes those rows before it calls the
-- USER_DELETE trigger, and deletes agenda events in several places, so a key would block or break those deletions.
-- The Convomail trigger removes the rows that depend on them instead.

ALTER TABLE llx_convomail_account MODIFY COLUMN fk_user integer NULL;
-- VPGSQL8.2 ALTER TABLE llx_convomail_account ALTER COLUMN fk_user DROP NOT NULL;
UPDATE llx_convomail_account SET fk_user = NULL WHERE fk_user = 0;

-- Mailboxes whose owner was deleted before 1.4.0 (no trigger cleaned them then): disabled, an admin decides
UPDATE llx_convomail_account SET status = 0 WHERE fk_user IS NOT NULL AND fk_user NOT IN (SELECT rowid FROM llx_user);

ALTER TABLE llx_convomail_account_access ADD COLUMN entity integer DEFAULT 1 NOT NULL;
UPDATE llx_convomail_account_access SET entity = (SELECT a.entity FROM llx_convomail_account as a WHERE a.rowid = llx_convomail_account_access.fk_account) WHERE fk_account IN (SELECT rowid FROM llx_convomail_account);

-- Grants left behind by a deleted mailbox or a deleted user
DELETE FROM llx_convomail_account_access WHERE fk_account NOT IN (SELECT rowid FROM llx_convomail_account);
DELETE FROM llx_convomail_account_access WHERE fk_user NOT IN (SELECT rowid FROM llx_user);

ALTER TABLE llx_convomail_account_access ADD INDEX idx_convomail_account_access_entity (entity);
ALTER TABLE llx_convomail_account_access ADD CONSTRAINT fk_convomail_account_access_fk_account FOREIGN KEY (fk_account) REFERENCES llx_convomail_account (rowid);
