-- Copyright (C) 2026 DPG Supply

ALTER TABLE llx_convomail_account_access ADD UNIQUE INDEX uk_convomail_account_access (fk_account, fk_user);
ALTER TABLE llx_convomail_account_access ADD INDEX idx_convomail_account_access_fk_user (fk_user);
-- The keys on fk_usergroup are in update_1.5.0.sql: key files run before update files, when an older table has no such column yet
