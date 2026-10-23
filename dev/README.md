# Dev fixtures and checks

Scripts for the local Docker stack (`docker-compose.yml` at the repo root). None of them are part of the module or the release zip.

| File | What it does | Where it runs |
|---|---|---|
| `fixtures.php` | Enables Projects; creates the third party **Acme Hospital**, its contact **Dana Rivera** (`dana.rivera@acme.test`) and the open project **PJ-OXY-001** "Oxygen meters rollout". Safe to run again | In the Dolibarr container |
| `user2.php` | Creates the non-admin user **salesrep** with Convomail rights 19428201 (use own accounts) and 19428202 (send). Password from `CM_TEST_PASS` | In the Dolibarr container |
| `seed_inbox.py` | Sends 30+ messages to `sales@convomail.test`: hostile HTML with an inline image and a PDF, plain text, Latin-1, fillers for paging. Optional argument: a Message-ID to reply to | Host (GreenMail SMTP on 3025) |
| `seed_projects.py` | Sends the three project-detection cases: a reply to a logged email (**thread**), a message whose References carry `-proj1@` (**code**), a new message from Dana (**suggested**) | Host |
| `e2e.sh` | Checks with curl and SQL: send with attachment (event, attachment copied, delivered), host allowlist refused on save, SMTP failure gives an error and no event, salesrep cannot reach admin's account | Host |

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
