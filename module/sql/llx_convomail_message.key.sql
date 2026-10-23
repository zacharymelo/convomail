-- Copyright (C) 2026 DPG Supply

ALTER TABLE llx_convomail_message ADD UNIQUE INDEX uk_convomail_message_account_msgid (fk_account, msgid);
ALTER TABLE llx_convomail_message ADD INDEX idx_convomail_message_fk_actioncomm (fk_actioncomm);
