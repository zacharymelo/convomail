# Convomail roadmap

The plan for the module and for proposing webmail to Dolibarr core. Update it when a phase ships or a decision is made. Structure and data model are in [ARCHITECTURE.md](ARCHITECTURE.md).

## Why

Users leave Dolibarr to send email from another client, and the link between a conversation and its customer, contact and project is lost. Convomail keeps email in Dolibarr: each user's own mailbox, with every message that matters on the agenda.

## Where it stands (0.4.0)

| Phase | Scope | Version |
|---|---|---|
| 1. Send and log | Accounts per user, compose / reply, the user's SMTP, Sent copy over IMAP, `AC_EMAIL` events with third party, contacts, project, attachments | 0.1.0 |
| 1b. Receipts | Read-receipt request; open tracking (admin switch, off by default); Email setup tab via the optional core patch; module ID 194282 | 0.2.0 |
| 2. Read and file | Mailbox (folders, list, message page), safe HTML display, reply / reply all / forward, Log to…, "server saves sent mail itself" | 0.3.0 |
| 2c. Projects and webmail | Three-pane mailbox, bulk actions and shortcuts, project Emails tab, project detection (logged / thread / code / suggested), automatic filing of replies, rich-text compose with signature, templates, pasted images and project contact chips | 0.4.0 |

**Tested:** Dolibarr 23.0, PHP 8.2, GreenMail, Chromium, light theme. See [Test debt](#test-debt).

## Next

Phases in order of value as last agreed. Re-check the order with the owner before starting one.

### 2b. OAuth for Google and Microsoft
**Why:** Microsoft 365 tenants increasingly refuse password SMTP/IMAP, and Gmail needs app passwords. This is the main barrier for real users.
- A per-user "Connect with Google / Microsoft" flow. Core's OAuth (`llx_oauth_token`, `core/modules/oauth/*`) has `fk_user`, but tokens are created only from an admin page (UPSTREAM item 4).
- Horde supports XOAUTH2 (`xoauth2_token` param). CMailFile supports `MAIN_MAIL_SMTPS_AUTH_TYPE = XOAUTH2` with `MAIN_MAIL_SMTPS_OAUTH_SERVICE`; check how it reads the token per send context.
- Account: an `auth_type` column (`password` | `oauth_google` | `oauth_microsoft`) plus a reference to the token.
- **Decide first:** use core's OAuth service setup (admin registers the app once) or the module's own client config.

### 3. Automatic conversation
**Why:** today a reply is filed only when someone opens the mailbox page that lists it.
- A cron job (`$this->cronjobs`) per account: scan Inbox and Sent since the last UID (`uidnext` / `CONDSTORE`), run the resolver, file thread and code matches. Store the last seen UID per account and folder (new table `llx_convomail_folder_state`).
- Filing policy per user or account: off / replies only (today's behaviour) / anything from a known contact.
- Shared team inboxes (sales@, support@): an account owned by a user group instead of a user, with per-message assignment. This needs an ownership model change in `ConvomailAccount::canBeUsedBy()`.
- Overlap with core's Email Collector: same codes and trackid scheme, so both can run. Document which to use when, or make the sync job call the shared matcher (UPSTREAM item 6).

### 4. Sales features
- "Awaiting reply" / "Replied" state per thread, with columns on third party and project lists.
- Follow-up nudges: an agenda to-do when an outgoing email has no reply after N days.
- The last message on opportunity / project cards (hook on `projectcard`).
- Response-time report per user and per customer.

### Smaller items
- Save drafts to the IMAP Drafts folder.
- Several References ids when core accepts them (UPSTREAM item 7).
- Translations beyond en_US (fr_FR first).
- Search across folders; the server's search only covers one folder.
- A per-user "file this sender always to project X" rule.

## Toward core

Each [UPSTREAM.md](../UPSTREAM.md) item is a small separate core PR, to make before proposing the module itself.
- **Item 8** (Email setup tabs) is prepared on branch `new-email-admin-module-tabs` in `~/Desktop/dolibarr-upstream` and **held for the owner's review**. Don't push it without their go-ahead.
- Items 2, 3, 5, 7 and 10 are small and self-contained: good next candidates.
- Items 4 and 6 are design changes and need a discussion with core maintainers first.

## Open decisions

| Question | Options | Notes |
|---|---|---|
| OAuth app registration | Core OAuth setup vs module config | Core's is the path to core inclusion |
| Shared inboxes | Group-owned accounts vs delegated access to a user's account | Affects `canBeUsedBy()`, rights and the menu |
| Auto-filing scope | Replies only (now) vs known contacts too | Privacy: personal mail from a contact would land on the agenda |
| Email Collector overlap | Coexist vs replace for user mailboxes | Same event codes today |
| Distribution | DoliStore listing vs GitHub releases only | The repo is private |

## Test debt

Not yet verified. Do these before telling users it works there:
- **Dolibarr 24** (the owner's production) and `develop`.
- **Real servers:** Gmail and Microsoft 365 with app passwords (Sent folder detection, `server_saves_sent`, SPECIAL-USE folders, search); a self-hosted Dovecot / Postfix.
- **Dark theme** and the Eldy / Md themes in narrow windows.
- Large mailboxes (10k+ messages): list speed, folder counts.
- Multicompany (`entity` > 1).
- Upgrading straight from 0.1.0 to 0.4.0.
