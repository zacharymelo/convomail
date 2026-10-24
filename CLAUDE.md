# Working on Convomail

Convomail is a Dolibarr module: webmail inside Dolibarr through each user's own IMAP/SMTP account, with every message that matters logged as an agenda event linked to third parties, contacts and projects. The module is the first step toward proposing webmail to Dolibarr core.

Read first, in this order:
1. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md): components, tables, flows, endpoints, security model.
2. [docs/ROADMAP.md](docs/ROADMAP.md): what is done, what is next, open decisions.
3. [UPSTREAM.md](UPSTREAM.md): core gaps we work around. Add to it rather than working around core harder.
4. [README.md](README.md): user-facing behaviour. Keep it in step with any change users can see.

## Rules from the project owner

- **Commit, push, tag or release only when asked.**
- **Core changes:**
  - Never push a Dolibarr core branch or open a core PR without the owner's go-ahead after review.
  - Describe side effects honestly in any core PR draft.
  - The held core PR is branch `new-email-admin-module-tabs` in `~/Desktop/dolibarr-upstream`. Its patch copy is [patches/](patches/README.md).
- **Prefer the simple supported behaviour** over clever workarounds of core limits, and record the gap in UPSTREAM.md. Example: the Mailbox menu shows for everyone with the read right, even without an account.
- **Module ID** is 194282, from the owner's reserved range 194281–194309 (194281 is doli-variable-bom). New rights use `194282xx`.
- **Header** of every PHP file: `Copyright (C) 2026 DPG Supply`, GPLv3+.

## Dev stack

```bash
docker compose up -d
```

- Dolibarr 23 on http://localhost:8114 (admin / admin). Port 8080 belongs to another project.
- `module/` is mounted as `custom/convomail`: edits are live, no copy step.
- GreenMail: SMTP 3025, IMAP 3143, no TLS, any login. From inside Dolibarr the host is `greenmail`; from the Mac it is `localhost`.
- First time: `docker run --rm -v "$PWD/module":/app -w /app composer:2 install --no-dev` for `module/vendor`.
- Fixtures, seed scripts and the end-to-end check: [dev/README.md](dev/README.md).
- Database: `docker compose exec -T db mariadb -udolibarr -pdolibarr dolibarr -e "..."`.
- Lint: `docker compose exec -T dolibarr php -l /var/www/html/custom/convomail/<file>`.

**Testing a change:**
1. Lint the PHP.
2. Exercise it in the browser pane on port 8114, or with curl plus SQL, the way `dev/e2e.sh` does.
3. Check the result over IMAP where it applies (flags, folders, Sent copy).
4. For access changes, also try as `salesrep`.

**GreenMail differs from real servers.** It omits In-Reply-To from ENVELOPE, returns nothing for partial body fetches, doesn't search Latin-1 bodies, refuses grouped search criteria (and stalls, until the read timeout, on an unquoted `<id>` before `)`), and matches FROM / TO / CC against whole addresses only. Test search on `dev/bigmailbox/` (Dovecot, 20,000 messages) instead. Real-server behaviour (Gmail, Microsoft 365) is untested.

## Release

Only when asked:
1. `python3 bin/build.py X.Y.Z` sets the version in the descriptor and the trigger, runs composer and writes `bin/module_convomail-X.Y.Z.zip` with `convomail/` as the top folder. Zips are git-ignored.
2. Add an `update_X.Y.Z.sql` for any schema change. Fresh installs read the `llx_*.sql` files; upgrades read the `update_*.sql` files. Both must work: test enabling on a scratch database. The schema upgrades itself on the first request after files change (`ConvomailAccount::checkSchema()` compares `CONVOMAIL_DB_VERSION` with the module version), so every statement must be safe to run again.
3. Update README (features, Upgrading, Roadmap), docs/ROADMAP.md and, if structure changed, docs/ARCHITECTURE.md.
4. Commit as `vX.Y.Z: <summary>`, push, then `gh release create vX.Y.Z bin/module_convomail-X.Y.Z.zip`.

## Dolibarr gotchas we have hit

**Optional modules**
- Document Browser (`docbrowser`, repo `../doli-docbrowser`, mounted in the dev stack) is used only when `isModEnabled('docbrowser')` and its class loads. Never make Convomail depend on it. Its keys are re-checked on send with `DocBrowserService::resolve()`; never accept a path from the form.
- CKEditor's content filter (ACF) keeps only what the toolbar can make: compose passes extra allowed content to `DolEditor::Create()` (blockquote with its border, `white-space`/`word-wrap` on div, the `convomail-signature` / `convomail-quote` classes, after core's own extras). The send path still drops the classes, so code that looks for the signature or quote also matches the "-- " line and the quote intro text (`convomail_insert_before_signature()`, `splitBody()` in `js/convomail-compose.js`). CKEditor also writes a line break after each `<br>`: stored/quoted plain text uses `<br>` without `pre-wrap` (`cleanBody(…, true)`), or each break shows twice. `$intro` is plain text (`transnoentitiesnoconv`), escaped once where it goes into HTML.
- Core's AI endpoint answers HTTP 200 with "Error returned by API call: …" when the provider is unreachable. Test the AI tools with a fake OpenAI-compatible server and `AI_API_SERVICE=custom` (`AI_API_CUSTOM_URL`, `AI_API_CUSTOM_KEY`).

**Module descriptor**
- Tab label syntax: `Label,ClassName,/path/file.class.php,method`. The second item is the class, not an icon.
- Menu `enabled`/`perms` strings go through `dol_eval` with a restricted set of methods. Rights are loaded right before menus, with no hook in between: per-user menu conditions aren't possible (UPSTREAM item 9).
- `ajax_constantonoff` setup switches show "off" unless the constant exists. Declare default-on constants in `$this->const`.
- Disable the module before changing its ID. Admins get the module's rights automatically on activation.
- Enabling from the CLI (`activateModule`) is unreliable; use the UI.

**Input and output**
- `GETPOST(..., 'alphanohtml')` strips `Name <email>`. Use `alphawithlgt` for address fields.
- `GETPOST(..., 'restricthtml')` runs Tidy, which collapses newlines in plain text. For a plain textarea, read the raw value and `htmlspecialchars` it.
- `dol_escape_htmltag` strips tags, and turns `\n` into a literal unless `keepn`.
- AJAX endpoints define `NOTOKENRENEWAL` and still need `token` on POST.
- `$form->buttonsSaveCancel()` makes Cancel a submit button. Guard every POST handler against `cancel`.
- `restrictedProjectArea()` returns >0 for allowed and -1 for denied, not a boolean.

**Sending through CMailFile**
- Swift Mailer gives the message a new Message-ID right after sending. Restore it before building the Sent copy.
- The References argument takes a single id.
- CMailFile embeds a pasted image twice and attaches it again. `ConvomailTransport::dedupePastedImages()` fixes this (UPSTREAM item 10).

**OAuth (0.5.0)**
- Core OAuth callbacks keep one token per app; Convomail's `oauth.php` keeps one per account under the `DoliStorage` key `convomail<account id>`. `DoliStorage` never sets `fk_user`; `ConvomailOAuth` does.
- phpoauth only accepts scopes that are `SCOPE_*` constants of the service class. Pass the constant names (`gmail_full`, `imap_accessasuserall`, ...).
- The Google service stores its own consent `state` in the token row (no token, no owner). Disconnect deletes by service key, not by owner.
- Horde refuses a blank password before trying XOAUTH2: pass the `Horde_Imap_Client_Password_Xoauth2` object as `password` too.
- Horde's trace masks passwords but not the XOAUTH2 credential; the trace goes through `ConvomailTraceMaskFilter`. Never log a token.
- Test without Google or Microsoft with `dev/oauth/start.sh` (Dovecot with an oauth2 passdb; see dev/README.md).

**Performance**
- Don't send IMAP `TEXT` / `BODY` searches by default: without a full-text index the server reads every message. Header criteria are answered from the server's cache.
- Call `convomail_release_session()` before slow mail-server work in any new page: PHP serialises a user's requests on the session lock.

**Shared mailboxes and collectors (0.6.0)**
- Access goes through `ConvomailAccount::canRead()` / `canSend()` / `canManage()` and `fetchAllUsableBy()`; never compare `fk_user` with the user. `fk_user = 0` is a shared mailbox; a new, unsaved account has `fk_user` null.
- CommonObject saves an empty `integer:User` field as NULL unless the field has a `default`: that is why `fk_user` declares `'default' => '0'`.
- `upgradeSchema()` also inserts new cron jobs and rebuilds the menus when the version changes, so deploying files is enough.
- Core's collector pages are admin-only: Convomail runs collectors itself (`doCollectOneCollector(0)`) and shows their last result. Needs `core/lib/date.lib.php` + `functions2.lib.php` loaded first.
- `select_dolusers()`: `$morefilter` takes core's universal filter syntax, not SQL; use the `$notdisabled` argument to hide disabled users.
- `dolGetStatus(..., 3)` is a dot without text; use 2 for dot and label. `$langs->trans()` escapes its parameters: print HTML links next to the translated text, not through it.
- Test collectors and the OAuth path on the dev stack: `dev/oauth/start.sh`, then turn on PHP-IMAP from Shared mail accounts.

**Other**
- Dolibarr's searchable dropdowns (select2, via `$form->selectarray()`) render option text as HTML: `Name <address>` loses the address as an unknown tag. Use `ConvomailAccount::choiceLabel()` ("Name — address") for account choices.
- Core's WAF (`waf.inc.php`) refuses a whole POST when a value contains `<style`, `<script`, `<iframe`, `on…=` handlers or `=data:`. Anything Convomail puts into the compose editor (quotes, templates) must go through `ConvomailRenderer::purify()` first. The refusal page hides `paramkey` / `paramvalue` in an HTML comment: view the page source to see which field.
- Core's `ajax_autocompleter()` fills one hidden id field; for address lists use jQuery UI `autocomplete()` directly in multi-value mode (`js/convomail-compose.js`), and trim trailing separators server-side.
- Module CSS/JS passed to `llxHeader()` is linked without a version, so browsers keep the old file after an upgrade. Pages pass `convomail_assets(...)` as the `$head` argument instead (version = file time).
- A fixed-height CSS grid or flex column needs `minmax(0, 1fr)` rows / `min-height: 0` children, or panes grow with their content and can't scroll.
- To see what a user's mail server actually answers, turn on Debug mode, repeat the action, and read `ajax/debug.php?mode=imaptrace` (Horde masks the password).
- Don't use server-side IMAP SORT for list order. Servers differ, and the bundled Horde stores range results (`ESEARCH ALL 40:31`) in ascending order. The list sorts UIDs itself (`newestFirst()`); `ajax/debug.php?mode=imap` shows a server's order.
- Horde has no getter for a part's transfer encoding; base64 is detected heuristically.
- With curl, a `-F` value starting with `<` reads a file. Use `--form-string` for message bodies.
- Online module install fails when `htdocs/custom` isn't writable by the web server user. That's a server permission problem, not ours (README Installation).

## Conventions

- Business logic lives in `class/`, page wiring in the top-level PHP files.
  - Shared HTML for the reading pane, message page and project tab lives in `ConvomailView`.
  - Filing always goes through `ConvomailView::logMessage()`.
  - Project detection always goes through `ConvomailProjectResolver`.
- IMAP access goes only through `ConvomailMailboxClient`. Horde objects never leave `ConvomailHordeMailboxClient`.
- Every endpoint that touches a mailbox goes through `convomail_get_usable_account()`.
- Writes are POST with a CSRF token; JSON endpoints return `{ok, message, ...}`.
- Production runs `MAIN_SECURITY_CSRF_WITH_TOKEN=3`: every GET with an `action` (except create, edit, presend… see main.inc.php) needs `&token=`. Links we print carry `newToken()`; URLs core redirects to (`backtopage`) cannot, so they use a flag parameter instead of `action` (e.g. `newthirdparty=1`). Test such flows with level 3 on.
- Horde's `deleteMailbox()` retries a failed DELETE by flagging every message of the folder deleted and expunging it. `ajax/folder.php` only deletes after `folderStatus()` shows no messages and no subfolders: keep that check first.
- Drag and drop: Chrome cancels a drag when the page layout changes during `dragstart` (it fires `dragend` right away). Make visual changes in a `setTimeout`, and keep drop zones out of the flow. Test drags with real mouse drags (the browser tool's `left_click_drag`): synthetic `DragEvent`s don't go through that check.
- Horde's `getNamespaces()` returns arrays unless called with `array('ob_return' => true)`; the objects are what `folderNamespace()` reads.
- Folder subscriptions: Convomail lists subscribed folders when the server keeps subscriptions (Horde always reports INBOX, so INBOX alone means none). Subscribe new folders only when it does, or the first subscription hides every other folder.
- GreenMail can get a folder stuck so that DELETE stalls until the read timeout (seen after several sessions appended to and expunged it, and on a subfolder whose parent was renamed). GreenMail can't RENAME a folder into another parent either: it drops the connection and loses the folder. Test folder moves on `dev/bigmailbox/` (Dovecot), where they keep subfolders and messages. A fresh folder deletes fine; real servers aren't affected.
- `dol_escape_htmltag($s)` turns line breaks into a literal `\n`: pass `keepn` (`dol_escape_htmltag($s, 0, 1)`) for textareas and multi-line `data-*` attributes, or saving the form back stores the `\n` text.
- Strings go in `langs/en_US/convomail.lang`. CSS uses Dolibarr theme variables, so dark themes work.
- Comments explain why, not what. Match the surrounding density.
