-- Copyright (C) 2026 DPG Supply
-- Core has no index on llx_actioncomm.email_msgid; replies are matched to their event through it.
-- If the index already exists, _load_tables() ignores the error.

ALTER TABLE llx_actioncomm ADD INDEX idx_actioncomm_email_msgid (email_msgid);
