# Core changes this module works around

Convomail is built as a custom module first, with the aim of proposing webmail for Dolibarr core
(design: "Dolibarr Core Webmail: Design & Scaffolding"). Each item below is a gap the module had to
work around. Each one is a small, separate core pull request to make before proposing the module itself.

| # | Gap | Workaround in convomail | Core change to propose |
|---|-----|-------------------------|------------------------|
| 1 | The conversation pages (`societe/messaging.php`, `contact/messaging.php`, `projet/messaging.php`) only run the `doActions` hook | "Send email" buttons sit on the card pages; "Reply" is on the event card | Run `addMoreActionsButtons` (or a `messagingActions` hook) on the three messaging pages, plus a per-item hook in `show_actions_messaging()` |
| 2 | `CMailFile` does not expose the built message | Force swiftmailer mode for the `convomail` send context and read `$mailfile->message->toString()` | Store the raw MIME after `sendfile()` for every send mode, or add an option to IMAP APPEND sent mail to a folder |
| 3 | `CMailFile::$message` is a dynamic property (deprecated in PHP 8.2+) | Guarded with `isset()` / `method_exists()` | Declare the property |
| 4 | No per-user OAuth flow; tokens are created from an admin page (`admin/oauthlogintokens.php`) even though `llx_oauth_token` has `fk_user` | Phase 1 supports password / app-password login only | A user-facing "Connect with Google / Microsoft" flow storing tokens with `fk_user`, and `CMailFile` reading a user's token |
| 5 | No index on `llx_actioncomm.email_msgid` | Module adds `idx_actioncomm_email_msgid` in `sql/llx_convomail_zactioncomm.key.sql` | Add the index in core SQL and the migration file |
| 6 | Reply matching lives inside `EmailCollector::doCollectOneCollector()` | Phase 1 only sends; it sets the trackid in the Message-ID so Email Collector can link replies | Extract matching (sender to contact/third party, trackid, In-Reply-To) into a shared class both modules call |
| 7 | `References` with several ids fails with Swift Mailer's `addIdHeader()` when passed a space-separated string | Phase 1 sends the parent Message-ID only | Accept an array, or split the string, in `CMailFile` |
| 8 | `email_admin_prepare_head()` calls `complete_head_from_modules()` in `'remove'` mode only, so modules cannot add a tab to Home > Setup > Emails | Module declares an `email_admin` tab (shown once patched) and its setup page joins that tab bar when the tab exists; [patches/dolibarr-email-admin-module-tabs.patch](patches/dolibarr-email-admin-module-tabs.patch) | Call it in `'add'` mode too, as `company_admin_prepare_head()` does. Branch `new-email-admin-module-tabs` prepared, PR held for review ([patches/README.md](patches/README.md)) |
