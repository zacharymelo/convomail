-- Copyright (C) 2026 Nick Fragoulis
-- 1.3.1: IMAP UIDs are unsigned 32-bit numbers, above the INTEGER range. Ignored if the column is already BIGINT.

ALTER TABLE llx_convomail_message MODIFY COLUMN uid BIGINT;
