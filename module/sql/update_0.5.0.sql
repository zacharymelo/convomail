-- Copyright (C) 2026 DPG Supply
-- 0.5.0: sign in with Google or Microsoft 365 (OAuth). The token itself is stored in llx_oauth_token.
-- Ignored if the columns exist.

ALTER TABLE llx_convomail_account ADD COLUMN auth_type VARCHAR(16) NOT NULL DEFAULT 'password';
ALTER TABLE llx_convomail_account ADD COLUMN oauth_app VARCHAR(64);
