-- Copyright (C) 2026 DPG Supply

ALTER TABLE llx_convomail_account ADD INDEX idx_convomail_account_entity_user (entity, fk_user);
ALTER TABLE llx_convomail_account ADD INDEX idx_convomail_account_status (status);
