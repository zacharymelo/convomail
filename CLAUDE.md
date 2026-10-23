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

**GreenMail differs from real servers.** It omits In-Reply-To from ENVELOPE, returns nothing for partial body fetches, and doesn't search Latin-1 bodies. The client works around the first two. Real-server behaviour (Gmail, Microsoft 365) is untested.

## Release

Only when asked:
1. `python3 bin/build.py X.Y.Z` sets the version in the descriptor and the trigger, runs composer and writes `bin/module_convomail-X.Y.Z.zip` with `convomail/` as the top folder. Zips are git-ignored.
2. Add an `update_X.Y.Z.sql` for any schema change. Fresh installs read the `llx_*.sql` files; upgrades read the `update_*.sql` files. Both must work: test enabling on a scratch database.
3. Update README (features, Upgrading, Roadmap), docs/ROADMAP.md and, if structure changed, docs/ARCHITECTURE.md.
4. Commit as `vX.Y.Z: <summary>`, push, then `gh release create vX.Y.Z bin/module_convomail-X.Y.Z.zip`.

## Dolibarr gotchas we have hit

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

**Other**
- Module CSS/JS passed to `llxHeader()` is linked without a version, so browsers keep the old file after an upgrade. Pages pass `convomail_assets(...)` as the `$head` argument instead (version = file time).
- A fixed-height CSS grid or flex column needs `minmax(0, 1fr)` rows / `min-height: 0` children, or panes grow with their content and can't scroll.
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
- Strings go in `langs/en_US/convomail.lang`. CSS uses Dolibarr theme variables, so dark themes work.
- Comments explain why, not what. Match the surrounding density.
