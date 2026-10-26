-- Copyright (C) 2026 DPG Supply
-- 1.1.0: open tracking tells who loaded the image (a mail app, Gmail's image proxy, Apple Mail Privacy Protection,
-- a security scanner, or the team itself): counts by kind and the latest opens, as JSON. No address is stored.
-- Ignored if the column exists.

ALTER TABLE llx_convomail_message ADD COLUMN open_detail TEXT;
