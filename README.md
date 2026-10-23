# Convomail — Dolibarr Module

Convomail lets each Dolibarr user send email from inside Dolibarr through their own mailbox. Every message is logged on the agenda of the customer, contact and project it belongs to. Users stop leaving Dolibarr to write email in another client, and the conversation history stays where the sales work happens.

**Status:** phase 1 (send and log), version 0.1.0. Tested on Dolibarr 23.0 with PHP 8.2 against a GreenMail test server. Not yet tested against real Gmail or Microsoft 365 servers.

**Download:** [latest release](https://github.com/zacharymelo/convomail/releases/latest) (`module_convomail-<version>.zip`, includes the IMAP library).

## Contents

- [What it does](#what-it-does)
- [How it works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Using it](#using-it)
- [Replies and the Email Collector](#replies-and-the-email-collector)
- [Privacy and security notes](#privacy-and-security-notes)
- [What the module stores](#what-the-module-stores)
- [Roadmap](#roadmap)
- [Development](#development)
- [Repository layout](#repository-layout)
- [Licence](#licence)

## What it does

- **Mail accounts per user.** A **Mail accounts** tab on each user card holds that user's IMAP and SMTP settings. It has presets for Gmail and Microsoft 365, a connection test and Sent folder detection.
- **Compose from where you work.** An **Email from my mailbox** button on third party, contact and project cards opens a compose page with the recipient, third party and project filled in. **Agenda → Compose email** opens a blank one.
- **Reply from the agenda.** Any email event with a Message-ID gets a **Reply** button. The reply keeps the thread (In-Reply-To and References) and inherits the third party and project.
- **Sent through the user's own server.** Mail goes out through the user's SMTP account, so it passes SPF/DKIM for their domain.
- **Copy in the Sent folder.** A copy is stored in the user's IMAP Sent folder, so it also shows in their usual mail client. The Sent folder is detected automatically, and created if the server has none. If the copy fails, the email is still sent and logged, and the user sees a warning.
- **Logged to the agenda.** Each sent message becomes a "Send Email" (`AC_EMAIL`) event with subject, body, recipients and attachments.
  - It is linked to the third party, every recipient who is a known contact, the project and the originating document.
  - It appears on the existing conversation views of third parties, contacts and projects.

## How it works

```
Compose page ──> ConvomailTransport ──> user's SMTP server ──> recipients
                        │
                        ├──> user's IMAP server: copy to Sent (same Message-ID)
                        │
                        └──> ConvomailMatcher (recipients → contacts, third party)
                                   │
                                   └──> ConvomailLogger ──> AC_EMAIL agenda event
                                                          + llx_convomail_message link
```

- **Sending** uses Dolibarr's own `CMailFile` with a dedicated `convomail` send context. The user's SMTP settings are put in memory for that one send and never written to the global configuration.
- **IMAP** goes through [bytestream/horde-imap-client](https://packagist.org/packages/bytestream/horde-imap-client), the IMAP stack Nextcloud Mail uses. It sits behind an interface so a JMAP client can be added later.

## Requirements

| Item | Version |
|---|---|
| Dolibarr | 20.0 or later (tested on 23.0) |
| PHP | 8.1 or later |
| Core modules | Third parties, Agenda (Projects optional) |
| Mail account | IMAP and SMTP access with a password or app password. OAuth is planned. |

## Installation

1. Download `module_convomail-<version>.zip` from the [releases](https://github.com/zacharymelo/convomail/releases).
2. In Dolibarr, go to **Home → Setup → Modules → Deploy external module** and upload the zip.
3. Enable **Convomail** in the module list.
4. Give users the rights they need under **Users → Permissions → Convomail**:
   - *Use and manage own mail accounts*
   - *Send email from Dolibarr*
   - *Manage the mail accounts of all users* (for admins who set accounts up for others)

Enabling the module activates the core "Send Email" event type and adds an index on `llx_actioncomm.email_msgid`.

## Configuration

Go to **Home → Setup → Modules → Convomail → Setup**:

| Setting | Purpose |
|---|---|
| Allowed mail servers | One host per line, `*` as wildcard. When empty, any public server is allowed and private, loopback and reserved addresses are refused, so the server cannot be pointed at internal hosts. Add your internal mail server here if you use one. |
| Accept self-signed certificates | For internal SMTP servers you trust. |
| Debug Mode | Enables `/custom/convomail/ajax/debug.php` for admins. |

Each user then adds an account: **user card → Mail accounts → Add a mail account**. With Gmail, use an app password.

## Using it

1. Open a third party, contact or project and click **Email from my mailbox**.
2. Check the sender account, recipients, third party and project, write the message, attach files, and click **Send**.
3. You land on the conversation view, where the message now appears.
4. To answer, open the event and click **Reply**.

## Replies and the Email Collector

Phase 1 sends and logs; it does not read the inbox. Dolibarr's core **Email Collector** can already log incoming replies:

- Convomail puts a Dolibarr tracking code in each Message-ID, using the same format as core: `proj<id>`, `pro`/`ord`/`inv<id>`, `thi<id>` or `ctc<id>`.
- Point an Email Collector at the user's mailbox with a "record event" operation. It recognises replies through their In-Reply-To and References headers and files them on the same project or third party.

## Privacy and security notes

- Passwords are encrypted with Dolibarr's `dolEncrypt()` and never sent back to the browser.
- Users only see and use their own accounts unless they have the "manage all accounts" right.
- Logged emails are ordinary agenda events and follow Dolibarr's agenda permissions. Anyone allowed to read the third party's events can read the email body.
- Plain-text messages are escaped before sending. HTML from the rich-text editor goes through Dolibarr's HTML sanitizer.

## What the module stores

| Table | Content |
|---|---|
| `llx_convomail_account` | One row per mail account: user, servers, ports, encryption, login, encrypted password, Sent folder |
| `llx_convomail_message` | One row per sent message: account, Message-ID, In-Reply-To, Sent folder UID, agenda event id |
| `llx_actioncomm` (core) | The agenda event for each message: subject, body, recipients, links |
| Agenda documents directory | Copies of attachments, under the event id |

Disabling the module removes menus and rights but keeps all data.

## Roadmap

Convomail is built as a module first, with the aim of proposing webmail for Dolibarr core.

| Phase | Scope | Status |
|---|---|---|
| 1. Send and log | Accounts, compose, reply, user SMTP, Sent copy, agenda events | **Done (0.1.0)** |
| 2. Read and file | Inbox and reading pane, HTML sanitizer, "Log to..." action, per-user OAuth for Google and Microsoft | Planned |
| 3. Automatic conversation | Sync job, auto-logging by policy, replies filed to their project, shared team inboxes | Planned |
| 4. Sales features | Awaiting-reply columns, follow-up nudges, last message on opportunity cards, response-time reports | Planned |

[UPSTREAM.md](UPSTREAM.md) lists the small core changes the module works around, which come before proposing it upstream.

## Development

```bash
docker compose up -d
docker run --rm -v "$PWD/module":/app -w /app composer:2 install --no-dev
```

- **Dolibarr:** http://localhost:8114 (admin / admin). The module is mounted at `custom/convomail`.
- **GreenMail test mail server:**
  - SMTP `greenmail:3025`, IMAP `greenmail:3143`, no encryption, any login and password.
  - Add `greenmail` to *Allowed mail servers* in the setup.
- **Release zip:** `python3 bin/build.py <version>`. It sets the version, installs the libraries and builds `bin/module_convomail-<version>.zip`.

## Repository layout

```
docker-compose.yml        Dev stack: Dolibarr, MariaDB, GreenMail
bin/build.py              Release build
UPSTREAM.md               Core changes to propose
module/                   Module source (installed as custom/convomail)
  core/modules/           Module descriptor
  class/                  Account, message, IMAP client, transport, matcher, logger, hooks
  sql/                    Tables and indexes
  admin/setup.php         Setup page
  user_accounts.php       Mail accounts tab on the user card
  compose.php             Compose, reply, send
  ajax/debug.php          Diagnostics (admin + debug mode)
  langs/en_US/            Translations
```

## Licence

GPL-3.0-or-later, see [LICENSE](LICENSE). Bundles [bytestream/horde-imap-client](https://packagist.org/packages/bytestream/horde-imap-client) and its Horde dependencies (LGPL-2.1).
