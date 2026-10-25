# Dev fixtures and checks

Scripts for the local Docker stack (`docker-compose.yml` at the repo root). None of them are part of the module or the release zip.

| File | What it does | Where it runs |
|---|---|---|
| `fixtures.php` | Enables Projects; creates the third party **Acme Hospital**, its contact **Dana Rivera** (`dana.rivera@acme.test`) and the open project **PJ-OXY-001** "Oxygen meters rollout". Safe to run again | In the Dolibarr container |
| `user2.php` | Creates the non-admin user **salesrep** with Convomail rights 19428201 (use own accounts) and 19428202 (send). Password from `CM_TEST_PASS` | In the Dolibarr container |
| `seed_inbox.py` | Sends 30+ messages to `sales@convomail.test`: hostile HTML with an inline image and a PDF, plain text, Latin-1, fillers for paging. Optional argument: a Message-ID to reply to | Host (GreenMail SMTP on 3025) |
| `seed_projects.py` | Sends the three project-detection cases: a reply to a logged email (**thread**), a message whose References carry `-proj1@` (**code**), a new message from Dana (**suggested**) | Host |
| `e2e.sh` | Checks with curl and SQL: send with attachment (event, attachment copied, delivered), host allowlist refused on save, SMTP failure gives an error and no event, salesrep cannot reach admin's account | Host |
| `invoices/` | Invoice reading: `run.sh` makes the fixtures (Factur-X PDFs with core TCPDF, UBL/CII XML, hostile files) and runs the CLI checks; `suppliers.php` and `seed_mail.py` set up the **Fill from the invoice** panel cases. See `invoices/README.md` | Container (`run.sh`, `suppliers.php`), host (`seed_mail.py`) |

## Setup, once

```bash
docker compose up -d
docker run --rm -v "$PWD/module":/app -w /app composer:2 install --no-dev
```

1. Open http://localhost:8114 (admin / admin). Enable **Convomail** in Home → Setup → Modules. Use the UI: enabling from the CLI is unreliable.
2. In Convomail setup, add `greenmail` to *Allowed mail servers*.
3. Load the fixtures:

```bash
docker cp dev/fixtures.php convomail-dolibarr-1:/tmp/fixtures.php
docker compose exec dolibarr php /tmp/fixtures.php
```

4. On admin's user card → **Mail accounts**, add the account the scripts expect (it gets id 1 on a fresh database):
   - email `sales@convomail.test`, login `sales@convomail.test`, any password;
   - IMAP `greenmail` port 3143, SMTP `greenmail` port 3025, no encryption.

5. Optionally, the second user (e2e.sh creates it itself):

```bash
docker cp dev/user2.php convomail-dolibarr-1:/tmp/user2.php
docker compose exec -e CM_TEST_PASS=<password> dolibarr php /tmp/user2.php
```

## Test OAuth sign-in without Google or Microsoft

`dev/oauth/` runs a Dovecot whose IMAP and submission accept XOAUTH2 with one test token, checked by `introspect.php` (served from the Dolibarr container on port 8000). Submission relays to GreenMail.

1. Add a fake Microsoft app in Home → Setup → OAuth, named `Mail` (any ID, secret and tenant). In Convomail setup, choose `MICROSOFT3-Mail` for Microsoft 365 and add `cm-dovecot` to *Allowed mail servers*.
2. Run the script below. It starts Dovecot and the token check, creates the account `oauthuser@x.test` for admin, and stores a token for it the way a real connect does.
3. Test the account, open its mailbox, and send from it.
4. For the expiry path, store an expired token with a refresh token the provider will reject (`docker compose exec -T dolibarr php /tmp/seedtoken.php convomail-test-access-token -120 bogus`). The mailbox and sending then show "Reconnect".

```bash
dev/oauth/start.sh
```

```bash
docker rm -f cm-dovecot
```

The consent redirect itself can be checked with fake apps: the Connect button sends you to `accounts.google.com` / `login.microsoftonline.com` with Convomail's redirect URI and the right scopes. Going further needs a real app.

## Measure on a large mailbox

`dev/bigmailbox/` makes 20,000 messages for `big@x.test` on a Dovecot without full-text index, like most hosted servers. Message 4321 has the word "zebracactus" in its body only.

```bash
dev/bigmailbox/start.sh /tmp/cm-vmail
```

Add `cm-dovecot` to *Allowed mail servers*, add an account (big@x.test / pass, IMAP and SMTP `cm-dovecot`, ports 31143 / 31587, no encryption), then time pages with `curl -w '%{time_total}'`. Stop it with `docker rm -f cm-dovecot`.

## Fill the mailbox

```bash
python3 -I dev/seed_inbox.py
```

For the project cases:
1. Send an email to Dana from Convomail with project PJ-OXY-001 set.
2. Copy its Message-ID (`SELECT email_msgid FROM llx_actioncomm ORDER BY id DESC LIMIT 1`).
3. Run:

```bash
python3 -I dev/seed_projects.py '<message id without angle brackets>'
```

Open the Mailbox. Expected result:
- the thread and code messages show a PJ-OXY-001 badge and are filed automatically on the first view;
- Dana's new message shows "Suggested" and is not filed.

## Check an older Dolibarr

The module declares Dolibarr 20+. `dev/compat/docker-compose.yml` runs another Dolibarr version next to the dev stack, with the module mounted and the dev GreenMail reached through the host:

```bash
DOLI_VERSION=20 docker compose -p convomail20 -f dev/compat/docker-compose.yml up -d
```

- http://localhost:8120 (admin / admin; `DOLI_PORT` to change the port). Enable **Convomail** in the UI.
- In the Dolibarr container: enable the record modules (`activateModule('modProjet')`, `modFournisseur`, `modCommande`, `modPropale`, `modFacture`, `modTicket`, `modECM`, `modSupplierProposal`), set the company country (VAT rates need it), allow `host.docker.internal` in Convomail setup, run `fixtures.php`.
- Add a mail account with IMAP `host.docker.internal` 3143 and SMTP `host.docker.internal` 3025, no encryption, a login of its own (GreenMail makes the mailbox), and copy test messages into it over IMAP.
- Remove it with `docker compose -p convomail20 -f dev/compat/docker-compose.yml down -v`.

## Run the checks

```bash
mkdir -p /tmp/cm-e2e
CM_TEST_PASS=<salesrep password> dev/e2e.sh /tmp/cm-e2e
```

The script prints what it found; read the output, it does not stop on the first failure.

## Useful commands

```bash
docker compose exec -T db mariadb -udolibarr -pdolibarr dolibarr -e "SELECT id, code, fk_soc, fk_project, label FROM llx_actioncomm ORDER BY id DESC LIMIT 10"
```

```bash
curl -s "imap://localhost:3143/INBOX" -X "SEARCH ALL" -u "sales@convomail.test:x"
```

```bash
docker compose logs -f dolibarr
```

GreenMail keeps mail in memory: `docker compose restart greenmail` empties every mailbox.
