-- Copyright (C) 2026 DPG Supply
-- 0.2.0: read receipts and open tracking.
-- Runs on every activation after the table and key files. On a fresh install the columns and key
-- already exist and _load_tables() ignores the "already exists" errors.

ALTER TABLE llx_convomail_message ADD COLUMN read_receipt SMALLINT NOT NULL DEFAULT 0;
ALTER TABLE llx_convomail_message ADD COLUMN track_key VARCHAR(32);
ALTER TABLE llx_convomail_message ADD COLUMN nb_open INTEGER NOT NULL DEFAULT 0;
ALTER TABLE llx_convomail_message ADD COLUMN date_first_open DATETIME;
ALTER TABLE llx_convomail_message ADD COLUMN date_last_open DATETIME;

ALTER TABLE llx_convomail_message ADD UNIQUE INDEX uk_convomail_message_track_key (track_key);
