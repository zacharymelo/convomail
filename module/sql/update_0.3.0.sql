-- Copyright (C) 2026 DPG Supply
-- 0.3.0: per-account option to skip the IMAP copy to Sent when the server already keeps one
-- (Microsoft 365 and Gmail do for mail sent through their SMTP). Ignored if the column exists.

ALTER TABLE llx_convomail_account ADD COLUMN server_saves_sent SMALLINT NOT NULL DEFAULT 0;
