# Convomail architecture

How the module is put together: what each part does, what it stores, and how information moves between Dolibarr, the user's mail server and the agenda. Written for whoever (person or agent) works on it next. State as of **0.6.3**.

- What the module does for users: [README.md](../README.md)
- What is planned: [ROADMAP.md](ROADMAP.md)
- Core gaps it works around: [UPSTREAM.md](../UPSTREAM.md)
- How to work on it: [CLAUDE.md](../CLAUDE.md)

## Contents

1. [Big picture](#1-big-picture)
2. [Repository layout](#2-repository-layout)
3. [Data model](#3-data-model)
4. [Identifiers and conventions](#4-identifiers-and-conventions)
5. [Classes](#5-classes)
6. [Flows](#6-flows)
7. [Pages and endpoints](#7-pages-and-endpoints)
8. [Registration: rights, menus, tabs, hooks, settings](#8-registration-rights-menus-tabs-hooks-settings)
9. [Security model](#9-security-model)
10. [External code](#10-external-code)
11. [Known limits](#11-known-limits)

---

## 1. Big picture

Convomail adds email to Dolibarr without copying the mailbox into it.

- **Mail stays on the mail server.** The mailbox pages talk to the user's IMAP server on every request. Dolibarr stores only accounts, plus one link row per message that was sent or filed.
- **Every message that matters is an agenda event.** Sent mail, mail filed with *Log to…* and replies filed automatically all become `llx_actioncomm` rows linked to a third party, contacts and a project. Core's conversation views and the module's project Emails tab read those events. Nothing else is needed to see a customer's email history.
- **Sending goes through core.** `CMailFile` sends through the user's own SMTP server, with a dedicated `convomail` send context whose settings are put in memory for one send.

```
            Dolibarr pages                          Convomail services                 Outside
 ┌──────────────────────────────┐      ┌──────────────────────────────────┐      ┌──────────────┐
 │ mailbox.php  (3 panes)       │─────▶│ ConvomailHordeMailboxClient      │─────▶│ IMAP server  │
 │ ajax/message.php (pane)      │      │   (ConvomailMailboxClient)       │      └──────────────┘
 │ ajax/action.php  (actions)   │      │ ConvomailRenderer (safe HTML)    │
 │ message.php      (full page) │      │ ConvomailProjectResolver         │
 │ compose.php                  │─────▶│ ConvomailTransport ── CMailFile ─┼─────▶ SMTP server
 │ project_emails.php (tab)     │      │ ConvomailMatcher                 │
 │ user_accounts.php  (tab)     │      │ ConvomailLogger ─────────────────┼──┐
 │ admin/setup.php              │      │ ConvomailView (shared views)     │  │
 │ public/open.php (no login)   │─────▶│ ConvomailTracker                 │  │
 └──────────────────────────────┘      └──────────────────────────────────┘  │
                                                                              ▼
                     llx_convomail_account · llx_convomail_message · llx_actioncomm (+ _resources)
                                        └── read by core conversation views and the project Emails tab
```

## 2. Repository layout

```
convomail/
  README.md               User documentation
  UPSTREAM.md             Core gaps the module works around (one table)
  CLAUDE.md / AGENTS.md   Guide for agents working on the repo
  docs/
    ARCHITECTURE.md       This file
    ROADMAP.md            Done, next, open decisions
    *.jpg                 Screenshots used by the README
  patches/                Optional core patch (Email setup tab) + held upstream PR draft
  dev/                    Fixtures and end-to-end scripts for the Docker dev stack
  bin/build.py            Release build: version bump, composer install, zip
  docker-compose.yml      Dev stack: Dolibarr (port 8114), MariaDB, GreenMail
  module/                 Installed as htdocs/custom/convomail
    core/modules/modConvomail.class.php     Descriptor: ID, rights, menus, tabs, hooks, constants
    core/triggers/interface_99_...Trigger   Placeholder trigger (debug log only)
    class/                Business classes (section 5)
    lib/convomail.lib.php Helpers (account access, folder cache, presets, templates, project contacts)
    mailbox.php           Three-pane mailbox + automatic filing
    message.php           One message on its own page + full "Log to..." form
    compose.php           New mail, reply (agenda event or mailbox message), forward
    project_emails.php    Emails tab of a project
    user_accounts.php     Mail accounts tab of a user
    oauth.php             Connect with Google / Microsoft 365: consent redirect, provider callback, disconnect
    attachment.php        Streams one MIME part
    ajax/message.php      Reading-pane HTML fragment
    ajax/action.php       Mailbox actions (JSON)
    ajax/template.php     Email template, substituted (JSON)
    ajax/projectcontacts.php  Project contacts for compose chips (JSON)
    ajax/addresses.php    Address suggestions for To / Cc / Bcc (JSON)
    ajax/debug.php        Diagnostics (admin + debug mode)
    public/open.php       Open-tracking image (no login)
    admin/setup.php       Module setup
    sql/                  Tables, keys, upgrades (section 3)
    js/convomail.js       Mailbox behaviour; full-page view toolbar; iframe sizing
    js/convomail-compose.js   Templates and project contact chips
    css/convomail.css     Mailbox, project tab and compose styles (theme variables)
    langs/en_US/convomail.lang
    composer.json/.lock   bytestream/horde-imap-client, ezyang/htmlpurifier
    vendor/               Composer output (not in git; built into the release zip)
```

## 3. Data model

### Module tables

**`llx_convomail_account`**: one row per mailbox a user can use (`ConvomailAccount`).

| Column | Meaning |
|---|---|
| `rowid`, `entity` | Id; multicompany entity |
| `fk_user` | Owner. Only the owner sends from it; a user with `account->admin` may manage and read it |
| `label`, `email` | Display name and address used as From |
| `imap_host`, `imap_port`, `imap_encryption` | IMAP server; encryption `ssl` (implicit TLS), `tls` (STARTTLS) or `none` |
| `smtp_host`, `smtp_port`, `smtp_encryption` | SMTP server; same encryption values |
| `login`, `pass_crypted` | Login; password encrypted with `dolEncrypt()`, never sent to the browser (empty for OAuth accounts) |
| `auth_type`, `oauth_app` | `password` or `oauth`; for OAuth, the core OAuth app used (`GOOGLE-<name>`, `MICROSOFT3-<name>`, `MICROSOFT-<name>`) |
| `sent_folder` | Folder for the Sent copy; auto-detected (SPECIAL-USE `\Sent`, then common names), created as `Sent` if missing |
| `server_saves_sent` | 1 = the server keeps its own copy of sent mail (Gmail, Microsoft 365); skip the IMAP copy |
| `is_default` | One per user; first account offered |
| `status` | 1 enabled, 0 disabled |
| `date_creation`, `tms`, `fk_user_creat`, `fk_user_modif`, `import_key` | Standard |

Keys: `(entity, fk_user)`, `status`.

**`llx_convomail_account_access`**: users granted a shared mailbox (`fk_account`, `fk_user`, `can_send`; unique per account and user). An account with `fk_user = 0` is shared.

**`llx_convomail_message`**: one row per message Convomail sent or filed (`ConvomailMessage`). It links a Message-ID to its agenda event.

| Column | Meaning |
|---|---|
| `fk_account` | Account it was sent from or read in |
| `folder`, `uid` | Where it was in the mailbox (Sent copy for sent mail; may be empty) |
| `msgid` | Message-ID without angle brackets (see section 4) |
| `in_reply_to` | Parent Message-ID. Used to build threads in the project Emails tab |
| `direction` | 1 out (sent), 2 in (received, filed) |
| `fk_actioncomm` | The agenda event |
| `date_message` | Message date |
| `read_receipt` | 1 = a read receipt was requested |
| `track_key` | Open-tracking key (32 hex) when tracking was on for that send |
| `nb_open`, `date_first_open`, `date_last_open` | Open counters |
| `date_creation`, `tms`, `fk_user_creat`, `import_key` | Standard (`fk_user_creat` = sender, used for the "opened" event owner) |

Keys: unique `(fk_account, msgid)`, unique `track_key`, `fk_actioncomm`.

**Index added to core:** `idx_actioncomm_email_msgid` on `llx_actioncomm.email_msgid`. Every project and thread lookup goes through it.

### Core tables used

| Table | Use |
|---|---|
| `llx_oauth_token` | OAuth accounts: one row per account, service `<Google\|Microsoft3\|Microsoft>-convomail<account id>`, `token` = serialized phpoauth token encrypted by `DoliStorage`, `fk_user` = owner. The Google service may also leave its consent `state` in the same row |
| `llx_actioncomm` | The agenda event of each message. Fields: `code`, `fk_action` (type), `label` (subject), `note` (cleaned HTML body), `datep`, `fk_soc`, `fk_project`, `fk_element`/`elementtype` (origin document), `email_msgid`, `email_from`, `email_sender`, `email_to`, `email_tocc`, `email_tobcc`, `email_subject`, `fk_user_action` (owner) |
| `llx_actioncomm_resources` | Assigned contacts (`element_type = socpeople`) and users of each event |
| `llx_c_actioncomm` | Event types. `AC_EMAIL` is activated by the module (inactive by default in core) |
| `llx_socpeople`, `llx_societe` | Matching addresses to contacts and third parties |
| `llx_projet`, `llx_element_contact` | Projects and their contacts (compose chips, suggestions) |
| `llx_c_email_templates` | Email templates offered in compose |
| `llx_user` (`signature`), `llx_user_param` | Signature; `CONVOMAIL_LAST_ACCOUNT` / `CONVOMAIL_LAST_FOLDER` (mailbox remembers where the user was) |
| `llx_const` | Module settings (section 8) |
| Agenda documents directory | Attachments copied to `<agenda dir_output>/<event id>/` |

### Agenda event codes written by Convomail

| What | `type_code` | `code` | Linked to |
|---|---|---|---|
| Sent from Convomail | `AC_EMAIL` | `AC_EMAIL` | third party, matched contacts, project, origin document |
| Received, filed (Log to…, one-click, automatic) | `AC_OTH_AUTO` | `AC_EMAIL_IN` | third party, contacts, project. Same codes as core's Email Collector |
| Filed message the user had sent from elsewhere (From is one of their accounts) | `AC_EMAIL` | `AC_EMAIL` | same |
| First open of a tracked message | `AC_OTH_AUTO` | `AC_CONVOMAIL_OPENED` | same third party, contacts, project as the sent event; owner = sender |

## 4. Identifiers and conventions

- **Message-IDs** are stored without `<>`.
  - Mail sent by Convomail gets core's form: `<time>.<n>.swiftmailer-dolibarr-<trackid>@<instance hash>`.
  - Swift Mailer replaces the Message-ID right after sending, so `ConvomailTransport` puts the delivered one back before building the Sent copy.
- **Tracking id in the Message-ID** (`ConvomailTransport::buildTrackId()`, the same scheme core and Email Collector use):
  - `proj<id>` when a project is set;
  - else `pro` / `ord` / `inv` / `tic` / `con` + id for the origin document;
  - else `thi<id>` (third party), else `ctc<id>` (contact).

  Replies quote it in In-Reply-To / References, which is how both the resolver (`-proj<id>@`) and core's Email Collector route them.
- **Messages without a Message-ID** get a stable stand-in, `convomail-<account id>-<md5(folder|uid|date)>@local` (`ConvomailView::msgid()`).
- **IMAP identity of a message** is account + folder + UID. UIDs are only stable within a folder; moving a message changes them.
- **List order** is UID descending, which is arrival order in that folder on every server. A message moved or copied into a folder (for example back from Archive) counts as newly arrived.
- **Effective sender:** `ConvomailMatcher::senders()` = the Reply-To people when they differ from From (`replyToOthers()`; servers copy From into an absent Reply-To), else From. Used for the list row ("X via Y"), `correspondent()`, the resolver's suggestion, and to decide that mail From the user but answering someone else is received (logging, Reply). `otherAddresses()` includes Reply-To, so filing links the visitor's contact and third party.
- **Folder roles:** `inbox`, `sent`, `drafts`, `junk`, `trash`, `archive`, or `''`. They come from SPECIAL-USE attributes, then common names (`ConvomailHordeMailboxClient::folderRole()`). Archive and Trash are created when missing.
- **Project match strengths** (`ConvomailProjectResolver`): `logged` > `thread` > `code` > `suggested`. Only `thread` and `code` are filed automatically.

## 5. Classes

| Class (file) | Responsibility | Key methods |
|---|---|---|
| `modConvomail` (core/modules) | Descriptor | `init()` loads `sql/`, activates `AC_EMAIL`, `delete_menus()`, `_init()`; `remove()` |
| `ConvomailAccount` | Account CRUD (CommonObject `*Common()`), password crypto, access, host allowlist | `fetchDefaultForUser()`, `fetchAllForUser()`, `canBeUsedBy()`, `checkHostAllowed()` / `isHostAllowed()`, `setPassword()`, `getPassword()`, `countForUser()` (tab badge), `clearSessionCache()` |
| `ConvomailCollector` | Automatic filing through core's Email Collector | `enable()` creates/updates `CONVOMAIL<id>_IN` (INBOX, `isanswer`) and `_OUT` (Sent folder, `isanswer` + `withouttrackingidinmsgid`, `code=SET:AC_EMAIL`), action `recordevent` with `userownerid/authorid=SET:<owner>`; `disable()`, `sync()` (called by `ConvomailAccount::update()` and on shared-mailbox access changes), `remove()` (by `delete()`), `status()`, `runNow()`, `refreshAll()` (on upgrade). Collector label "Convomail: Inbox/Sent replies of <address> (<owner or shared mailbox>)"; the description names the Dolibarr user (or the shared mailbox's users) and what the job does |
| `ConvomailOAuth` | OAuth sign-in (Google, Microsoft 365) on top of core's phpoauth services and `DoliStorage` | `providers()`, `availableApps()`, `appFor()`, `authorizationUrl()`, `handleCallback()`, `accessToken()` (refresh 5 minutes before expiry), `status()`, `disconnect()`, `mailSettings()` (in-memory settings for `CMailFile`) |
| `ConvomailMessage` | Link row CRUD | `fetchByMsgid()`, `fetchByTrackKey()` (no entity filter: public URL), `fetchByActioncomm()`, `countForProject()` (tab badge) |
| `ConvomailMailboxClient` (interface) | What the module needs from a mailbox protocol | `testConnection`, `listFolders`, `listMessages`, `fetchMessage`, `fetchPart`, `setSeen`, `setFlag`, `moveMessages`, `append`, `createFolder`, `findSentFolder`, `findFolderByRole` |
| `ConvomailHordeMailboxClient` | IMAP implementation (Horde_Imap_Client). Returns plain arrays, never Horde objects | Summaries carry `uid, msgid, subject, date, from/to/cc/reply_to, in_reply_to, references, seen, answered, flagged, size, has_attachments, snippet`. `fetchMessage()` adds `parts` (`id, type, charset, name, disposition, cid, size`). Fallbacks: In-Reply-To from headers when ENVELOPE omits it; whole small parts when partial fetch returns nothing |
| `ConvomailRenderer` | Message body to safe HTML | `cleanBody($allowImages, $forStorage)`, `iframeDocument()`, `attachments()`, static `purify()` (HTMLPurifier; `cid:` scheme via `ConvomailHTMLPurifierCidScheme`) |
| `ConvomailTransport` | Send through the account's SMTP via `CMailFile`, copy to Sent | `send()`, `buildTrackId()`. Private: `overrideSmtpSettings()` / `restoreSmtpSettings()` (in-memory `MAIN_MAIL_*_CONVOMAIL`), `dedupePastedImages()` (core bug), `copyToSent()` |
| `ConvomailMatcher` | Addresses to contacts and third parties | `parseAddresses()`, `mapEmails()` (per address), `matchRecipients()` (contacts + single third party), `loggedEvents()` (Message-ID → event) |
| `ConvomailProjectResolver` | Which project a message belongs to | `resolvePage()` (whole page in about 4 queries), `resolve()`, `parentIds()`; `$projects` cache (ref, title, socid) |
| `ConvomailLogger` | Write agenda events + link rows | `logSent()`, `logReceived()`, `fetchReplyTarget()` |
| `ConvomailTracker` | Open tracking | `isEnabled()`, `newKey()`, `pixelHtml()`, `recordOpen()` (first open creates `AC_CONVOMAIL_OPENED`) |
| `ConvomailView` | Shared server-side views and filing | `render()` (reading pane / full page), `logMessage()` (filing used by all paths), `defaultLinks()`, `projectThreads()`, `projectBadge()`, `myEmails()`, `msgid()` |
| `ActionsConvomail` | Hooks | `getElementProperties`, `addMoreActionsButtons` (buttons on third party, contact, project and event cards), `formObjectOptions` (receipt status on the event card) |

## 6. Flows

### 6.1 Send (compose → SMTP → Sent → agenda)
1. `compose.php` (GET) prefills from the context:
   - `socid` / `contactid` / `projectid` / `origin` + `originid`;
   - `replyto` (an agenda event);
   - `accountid` + `folder` + `uid` + `mode=reply|replyall|forward` (a mailbox message).

   Signature (`$user->signature`) when `CONVOMAIL_ADD_SIGNATURE`. Templates via `ajax/template.php`, project chips via `ajax/projectcontacts.php`, address suggestions in To / Cc / Bcc via `ajax/addresses.php` (jQuery UI autocomplete, multi-address).
2. POST `action=send`:
   - the account must be the user's own and enabled;
   - the body is read with `restricthtml` (rich editor) or raw and escaped (plain textarea);
   - attachments are uploaded to a per-send temp dir (forwards re-fetch the original parts).
3. `ConvomailMatcher::matchRecipients()` gives the contacts and, if unambiguous, the third party. `buildTrackId()` makes the tracking id.
4. `ConvomailTransport::send()`:
   1. puts the account's SMTP settings in memory under the `convomail` context (swiftmailer mode, `MAIN_MAIL_ADD_INLINE_IMAGES_IF_DATA`);
   2. builds `CMailFile` (In-Reply-To / References = parent, read receipt flag);
   3. dedupes pasted images, then `sendfile()`;
   4. restores the Message-ID and removes the tracking image from the Sent copy;
   5. appends the copy to Sent over IMAP, unless `server_saves_sent`. A failure here is only a warning.
5. `ConvomailLogger::logSent()` creates an `AC_EMAIL` event (body without pasted images) and a `llx_convomail_message` row (direction 1), and copies the attachments.
6. Redirect to the third party's or project's conversation view.

### 6.2 Read (mailbox → reading pane)
1. `mailbox.php`:
   1. `convomail_get_usable_account()` picks the account: requested, else last used, else default;
   2. `convomail_folders()` lists folders (session cache, 5 minutes);
   3. the folder is chosen: explicit, else role, else last used, else INBOX;
   4. **Search**, when there is one (`ConvomailHordeMailboxClient::searchUids()`):
      - every word must match, each in From, To, Cc or Subject (`OR` of `HEADER` criteria). With `searchbody=1` it uses `TEXT` (headers and body), with a 120 s timeout;
      - servers that refuse grouped criteria get one simple search per header and word, merged by the module;
      - the matching UIDs are cached for 10 minutes in `<documents>/convomail/temp/search/<user>-<hash>.json`, keyed on the query and the folder's UIDVALIDITY, UIDNEXT and message count, so pages 2+ and repeats don't search again, and new or removed mail refreshes it.
   5. `listMessages()` loads one page (`convomail_page_size()`: the user's `CONVOMAIL_PAGE_SIZE`, else the setup default, else 25; 10/25/50/100), newest arrival first (UIDs descending, sorted by the module, not by server SORT), with References and previews.
2. `mapEmails()` and `resolvePage()` give each row its known contact or third party and its project chip.
3. **Automatic filing** (6.4) runs, then the page renders the three panes. `data-*` attributes on `.cm-app` carry the account, folder, CSRF token and endpoint URLs for `js/convomail.js`.
4. A row click fetches `ajax/message.php`. `ConvomailView::render()` then:
   1. `fetchMessage()` marks the message `\Seen`;
   2. resolves its project;
   3. `ConvomailRenderer::iframeDocument()` purifies the body, blocks remote images unless `showimages`, and rewrites `cid:` to `attachment.php`;
   4. returns the pane: toolbar, people, project line, attachments, sandboxed `<iframe srcdoc>`.

   The JS swaps the pane, marks the row read and `history.replaceState`s the URL.
5. Under 768px wide, rows open `message.php` instead, the same view on a page.

### 6.3 File a message (Log to…)
- **One click** in the pane: POST `ajax/action.php action=log` with the resolved project, third party and contacts. The JS then reloads the pane and the list rows (`refreshRows()`: fetches the current list page and swaps its `.cm-rows`, keeping the selection, ticked rows and scroll), so project chips and replies filed with it show at once.
- **Full form** on `message.php?action=log`: choose third party, contacts and project, then POST `action=confirm_log`. A posted Cancel never logs.
- Both call `ConvomailView::logMessage()`:
  1. returns 0 if the Message-ID already has an event;
  2. copies the attachments to temp;
  3. `ConvomailLogger::logReceived()` writes `AC_EMAIL_IN`, or `AC_EMAIL` when the From is one of the user's own addresses, with the body from `cleanBody(false, true)` (inline images become `[name]`);
  4. writes the link row, direction 2.

### 6.4 Automatic filing of replies
- In `mailbox.php`, for the Inbox and Sent roles only, and when `CONVOMAIL_AUTOFILE_REPLIES` is set.
- Each listed message with strength `thread` or `code` that isn't logged yet is fetched and filed by `logMessage()`:
  - project from the resolver;
  - third party from the parent event or the project, else the matcher.
- At most 10 per page view; the user sees "N reply(ies) filed…".
- Only messages someone lists are filed. Background filing is on the roadmap.

### 6.5 Project Emails tab
`project_emails.php`:
1. Access checks: `restrictedArea()`, `restrictedProjectArea()`, and the agenda read right.
2. `ConvomailView::projectThreads()` loads every `AC_EMAIL` / `AC_EMAIL_IN` event of the project, joined to its link row.
3. A message joins its parent's thread via `in_reply_to`; otherwise it starts a thread keyed by its subject without Re:/Fwd:. A thread is titled by its first non-reply subject.
4. Threads are shown as `<details>`, each message with **Reply** (`compose.php?replyto=<event>`).

### 6.6 Open tracking (off by default)
1. At send time, when tracking is on and the box is ticked, a key goes into the link row and a 1×1 image pointing at `public/open.php?k=<key>&entity=<n>` goes into the delivered HTML only.
2. `public/open.php` (no login) always returns the GIF. When tracking is on and the key exists, `ConvomailTracker::recordOpen()` updates the counters, and the first open creates `AC_CONVOMAIL_OPENED`.
3. `ActionsConvomail::formObjectOptions()` shows the counts on the sent event's card.

### 6.7 Mailbox actions
POST `ajax/action.php` with `uids[]` and `action`:
- `read`, `unread`, `flag`, `unflag`: `setFlag()`.
- `move` (`dest` must be a listed folder), `archive`, `delete` (to Archive / Trash, created if missing; refused when already there): `moveMessages()`.
- `log`.

The response is JSON `{ok, removed, message, events}`; the JS updates rows and the pane.

### 6.8 OAuth connect and token use
**Setup (admin, once):** the app (client ID, secret, tenant) is registered in core's Home → Setup → OAuth (`OAUTH_<TYPE>-<name>_ID/_SECRET/_TENANT`). Convomail setup stores which app each provider uses (`CONVOMAIL_OAUTH_GOOGLE_APP`, `CONVOMAIL_OAUTH_MICROSOFT_APP`) and shows the redirect URI, `oauth.php`.

**Connect (owner):**
1. The account form saves `auth_type=oauth` and `oauth_app`, then sends the owner to `oauth.php?action=connect`.
2. `authorizationUrl()` puts `{account, nonce, time}` in the session and redirects to the provider:
   - state `<account id>-<nonce>`, `login_hint`;
   - Google: offline access with a forced consent prompt (for a refresh token), scope `https://mail.google.com/`;
   - Microsoft: `prompt=select_account`, scopes `offline_access`, IMAP.AccessAsUser.All, SMTP.Send.
3. The provider redirects back to `oauth.php?code&state`. `handleCallback()` checks:
   - the nonce (single use, 30 minutes);
   - that the account belongs to the signed-in user.

   It then exchanges the code (`requestAccessToken`) and sets `fk_user` on the token row.

**Use:**
- `ConvomailOAuth::accessToken()` returns the stored access token, refreshing it within 5 minutes of expiry. It keeps the old refresh token when none comes back.
- **IMAP:** the token goes to Horde as `xoauth2_token` (`AUTHENTICATE XOAUTH2`). The same object is also passed as the password, because Horde refuses blank passwords.
- **SMTP:**
  - `ConvomailTransport` refreshes first;
  - it then sets in memory `MAIN_MAIL_SMTPS_AUTH_TYPE_CONVOMAIL=XOAUTH2`, `MAIN_MAIL_SMTPS_OAUTH_SERVICE_CONVOMAIL=<TYPE>-convomail<id>`, and copies of the app's settings under that key;
  - `CMailFile` then reads the token from `llx_oauth_token` itself.
- **Failure:** with no token, or a failed refresh, the mailbox and sending show "Reconnect this account", and nothing is sent or logged.

**Disconnect / delete:** deletes the account's token row, by its service key.

### 6.9 Third party from an email
1. `ConvomailView::render()` takes the correspondent (`correspondent()`: the sender, or the first other recipient of mail the user sent). If that address isn't mapped to a contact or third party, it shows `.cm-unknownline` with:
   - **Add as contact of X**: core `contact/card.php?action=create` prefilled. Shown when `ConvomailMatcher::thirdPartyForDomain()` finds a visible third party using the domain (its email, website or a contact's address; not for free-mail domains). Needs `societe->contact->creer`;
   - **Create third party**: core `societe/card.php?action=create` prefilled by `newThirdPartyUrl()`. Company: `nameFromDomain()` and `https://<domain>`. Free mail: the person's name. Needs `societe->creer`.
2. Core creates the third party with all its own rules, then redirects to `backtopage` = `message.php?…&newthirdparty=1&addr&addrname`, adding `&socid=<new id>`. Cancel comes back without `socid`. It is a flag rather than an `action`, because core redirects without a token and CSRF level 3 (`MAIN_SECURITY_CSRF_WITH_TOKEN=3`) refuses any GET `action` without one.
3. `message.php` checks access to the third party (`restrictedArea`) and offers adding the person as a contact (`splitName()`, unless a contact has the address) and logging the email. POST `action=confirm_thirdparty` creates the contact (`Contact::create`) and calls `ConvomailView::logMessage()` with the third party and contact, then returns to the mailbox on that message.

### 6.10 Shared mailboxes and automatic filing
- **Access:** `ConvomailAccount::canRead()` (own, granted shared, or `account->admin`), `canSend()` (own, or shared granted with `can_send`; admins need a grant too), `canManage()` (owner of a personal account, or `account->admin`). `fetchAllUsableBy()` gives the mailbox switcher and compose's From list; `convomail_get_usable_account()` falls back to granted shared mailboxes.
- **Dashboard** `accounts.php` (`account->admin`): every account, shared mailbox create/edit (same form as the user tab, `convomail_account_form_fields()`), access grants, automatic filing cells.
- **Automatic filing:** `convomail_filing_cell()` / `convomail_filing_actions()` (`filing_on|filing_off|filing_run`, owner or admin) drive `ConvomailCollector`. Core's cron *Email collector* runs the collectors every 5 minutes; Convomail's cron *ConvomailCronRefreshTokens* (`ConvomailOAuth::refreshTokens()`, every 5 minutes) refreshes OAuth tokens expiring within 15 minutes, because the collector can't. OAuth collectors need `MAIN_IMAP_USE_PHPIMAP=1` (button on the dashboard).

## 7. Pages and endpoints

| Path | Method | Parameters | Access | Output |
|---|---|---|---|---|
| `mailbox.php` | GET | `accountid, folder, role, search, searchbody, page, limit, refresh, uid, showimages` | `account->read`; account usable by user | Page |
| `message.php` | GET / POST | `accountid, folder, uid, showimages, action=log\|confirm_log\|confirm_thirdparty, newthirdparty, socid, projectid, contactids[], copyfiles, addr, addrname, addcontact, firstname, lastname, logit` | same | Page |
| `compose.php` | GET / POST | `socid, contactid, projectid, origin, originid, replyto, accountid, folder, uid, mode`; POST `action=send, sendto, sendtocc, sendtobcc, subject, message, attachments[], fwdparts[], read_receipt, track_opens` | `message->send`; own account | Page |
| `attachment.php` | GET | `accountid, folder, uid, part, inline` | `account->read`; usable account | File (`nosniff`, sandbox CSP; inline only for png/jpeg/gif/webp) |
| `project_emails.php` | GET | `id` | project read + private-project check + agenda read | Page |
| `accounts.php` | GET / POST | `accountid, action=create\|add\|edit\|update\|delete\|confirm_delete\|test\|detectsent\|grant\|revoke\|filing_on\|filing_off\|filing_run\|phpimap_on, userid, cansend, backtocard` | `account->admin` (`phpimap_on`: `$user->admin`) | Page |
| `oauth.php` | GET / POST | `action=connect&accountid&token` (GET); provider callback `code, state` or `error, error_description`; `action=disconnect&accountid` (POST) | connect: account owner; disconnect: owner or `account->admin` | Redirect |
| `user_accounts.php` | GET / POST | `id` (user), `accountid, action=create\|add\|edit\|update\|delete\|confirm_delete\|test\|detectsent\|filing_on\|filing_off\|filing_run` | own (`account->read`) or any (`account->admin`) | Page |
| `admin/setup.php` | GET / POST | `action=update, CONVOMAIL_ALLOWED_HOSTS` | admin | Page |
| `ajax/message.php` | GET | `accountid, folder, uid, showimages` | `account->read`; usable account | HTML fragment |
| `ajax/action.php` | POST + token | `accountid, folder, uids[], action, dest, projectid, socid, contactids` | `account->read`; usable account | JSON |
| `ajax/template.php` | GET | `id, socid, contactid, projectid` | `message->send`; template public or own | JSON `{subject, body}` |
| `ajax/projectcontacts.php` | GET | `id` | `message->send` + project read + private-project check | JSON list |
| `ajax/addresses.php` | GET | `term` (2+ chars) | `message->send`; contacts need `societe->contact->lire`, third parties `societe->lire`, both limited like core lists (`societe_commerciaux` without `client->voir`, private contacts, external users to their company); users need `user->user->lire`; recent = the user's own email events, minus their own addresses | JSON list, up to 15 |
| `ajax/debug.php` | GET | `mode` | admin + `CONVOMAIL_DEBUG_MODE` | Text |
| `public/open.php` | GET | `k, entity` | none (public) | 1×1 GIF |

The AJAX files define `NOTOKENRENEWAL`, so the page's CSRF token stays valid for many calls. Core's `main.inc.php` refuses POSTs without a valid token.

## 8. Registration: rights, menus, tabs, hooks, settings

- **Schema version:** `CONVOMAIL_DB_VERSION` (entity 0) records the version the tables are at. `modConvomail::upgradeSchema()` runs `sql/` (create missing tables and keys, then `update_*.sql`; changes already applied are skipped) on activation. `ConvomailAccount::checkSchema()`, run once per request from the account constructor, calls it when the files are newer, so deploying files without disable/enable is safe.
- **Module:** ID `194282` (reserved range 194281–194309), family crm. Depends on `modSociete`, `modAgenda`, `modFckeditor`. PHP ≥ 8.1, Dolibarr ≥ 20 (tested on 23.0).
- **Rights:**

  | Id | Code | Meaning |
  |---|---|---|
  | 19428201 | `account->read` | Use and manage own mail accounts; see the Mailbox |
  | 19428202 | `message->send` | Send from Dolibarr |
  | 19428203 | `account->admin` | Manage (and read) every user's accounts |

- **Menus:** top `Mailbox` (`mainmenu=convomail`, position 87, after Agenda), with left entries Inbox, Sent, Compose email and My mail accounts (`user_accounts.php?id=__USER_ID__`). Also "Compose email" under Agenda. The tab shows to everyone with `account->read`: a per-user "has an account" condition is not possible in core (UPSTREAM item 9).
- **Tabs:**
  - `user:+convomail` (Mail accounts, badge `ConvomailAccount::countForUser`);
  - `project:+convomail_emails` (Emails, badge `ConvomailMessage::countForProject`);
  - `email_admin:+convomail`, shown only with the [core patch](../patches/README.md).
- **Hook contexts:** `elementproperties`, `thirdpartycard`, `contactcard`, `projectcard`, `actioncard`, `usercard`.
- **Settings** (`llx_const`):

  | Name | Default | Effect |
  |---|---|---|
  | `CONVOMAIL_ALLOWED_HOSTS` | empty | Host allowlist (lines, `*` wildcard). Empty = any public host, private/loopback/reserved refused |
  | `CONVOMAIL_ALLOW_SELF_SIGNED` | 0 | Accept self-signed SMTP certificates |
  | `CONVOMAIL_AUTOFILE_REPLIES` | 1 (created on activation) | Automatic filing (6.4) |
  | `CONVOMAIL_PAGE_SIZE` | 25 (unset) | Default messages per mailbox page (10, 25, 50, 100) |
  | `CONVOMAIL_ADD_SIGNATURE` | 1 (created on activation) | Insert signature in compose |
  | `CONVOMAIL_READ_RECEIPT_DEFAULT` | 0 | Read receipt box ticked by default |
  | `CONVOMAIL_OPEN_TRACKING` | 0 | Open tracking available |
  | `CONVOMAIL_OAUTH_GOOGLE_APP`, `CONVOMAIL_OAUTH_MICROSOFT_APP` | empty | Core OAuth app used for each provider; empty = provider not offered |
  | `CONVOMAIL_DEBUG_MODE` | 0 | Enables `ajax/debug.php`, and records an IMAP protocol trace per user in `<documents>/convomail/temp/imap-trace-<user id>.log` (`?mode=imaptrace`) |

- **In-memory only, during one send:** `MAIN_MAIL_SENDMODE_CONVOMAIL`, `MAIN_MAIL_SMTP_SERVER_CONVOMAIL`, `_PORT_`, `MAIN_MAIL_SMTPS_ID_`, `_PW_`, `_AUTH_TYPE_`, `MAIN_MAIL_EMAIL_TLS_`, `_STARTTLS_`, `MAIN_MAIL_EMAIL_SMTP_ALLOW_SELF_SIGNED_CONVOMAIL`, `MAIN_MAIL_ADD_INLINE_IMAGES_IF_DATA`. For OAuth accounts also `MAIN_MAIL_SMTPS_OAUTH_SERVICE_CONVOMAIL` and `OAUTH_<TYPE>-convomail<id>_ID/_SECRET/_TENANT/_SCOPE/_URLCALLBACK`. They are never written to `llx_const`.
- **User params:** `CONVOMAIL_LAST_ACCOUNT`, `CONVOMAIL_LAST_FOLDER`, `CONVOMAIL_PAGE_SIZE` (overrides the setup default).
- **Session:** `convomail_folders_<accountid>` (folder list, 5 minutes), cleared on account changes and when Archive / Trash are created.

## 9. Security model

- **Ownership:** every page and endpoint that touches a mailbox goes through `convomail_get_usable_account()`. It requires an enabled account owned by the user, or `account->admin`. Compose and reply additionally require the user's *own* account.
- **CSRF:** core's token check on every POST, including `ajax/action.php`. State-changing GET links carry `token=`.
- **Incoming HTML:**
  1. HTMLPurifier strips scripts, forms, frames, event handlers and `javascript:` URLs; external resources are removed unless "Show images". `CSS.AllowTricky` is on, so `display`, `visibility` and `overflow` survive (newsletters hide their preview text with them); positioning (`position`, `top`, `z-index`...) is still removed;
  2. the result is shown in `<iframe sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox">`, with no `allow-scripts`;
  3. a CSP inside the frame allows only inline styles and images from `data:` and this Dolibarr (plus https/http when images are allowed).
- **Attachments:** always `Content-Disposition: attachment`, except raster images requested inline; `X-Content-Type-Options: nosniff`; `Content-Security-Policy: default-src 'none'; sandbox`. Part ids are validated (`^[0-9]+(\.[0-9]+)*$`).
- **Outgoing HTML:** rich editor → `restricthtml`; plain textarea → `htmlspecialchars`. HTML prefilled into the editor (quoted mailbox messages, quoted agenda events, templates) is purified first; otherwise core's injection filter refuses the send.
- **SSRF:** `checkHostAllowed()` on save and before every send, so a user can't point the server at internal hosts.
- **Secrets:** passwords are encrypted with `dolEncrypt()`, OAuth tokens by `DoliStorage` (also `dolEncrypt()`). SMTP settings live in memory only for one send. `ajax/debug.php` refuses SQL that mentions `pass_crypted`, prints only a token's expiry, and the IMAP trace passes through `ConvomailTraceMaskFilter`, which replaces XOAUTH2 credentials with `[TOKEN]` (Horde masks only passwords).
- **OAuth:** the consent state is a random nonce in the session, single use, checked with `hash_equals()`, and tied to an account the signed-in user owns. Only the owner can connect an account.
- **Public endpoint:** `public/open.php` accepts only 32-hex keys, does nothing when tracking is off, and always returns the same GIF (no oracle).

## 10. External code

| Code | Where | Why |
|---|---|---|
| `bytestream/horde-imap-client` 2.34 (+ Horde Mime, Mail, Util…), LGPL-2.1 | `module/vendor` | IMAP, MIME structure, charset conversion |
| `ezyang/htmlpurifier` 4.19, LGPL-2.1-or-later | `module/vendor` | Cleaning incoming HTML |
| Core `CMailFile` + bundled Swift Mailer | Dolibarr | Sending, Message-ID, inline images, read receipt header |
| Core FCKeditor module (CKEditor 4) | Dolibarr | Rich-text compose |
| Core Email Collector | Dolibarr (optional) | Uses the same trackid scheme; can file replies automatically on the server side |

## 11. Known limits

- **IMAP only.** Sign-in is by password, or OAuth with Google or Microsoft 365. OAuth was tested against a local Dovecot with a token check, not yet against Google or Microsoft themselves. Other OAuth providers aren't offered.
- **No background sync.** Filing replies needs someone to open the mailbox page that lists them.
- **No local copy of the mailbox.** Every page talks to IMAP (about 2–4 round trips per list page), and search is the server's. The default header search took 0.1–0.3 s on 20,000 messages (Dovecot without full-text index); a search in the message text about 4 s there, and much more on a remote server with large messages.
- **Session lock:** mailbox, message, reading pane and attachment requests call `convomail_release_session()` before talking to the mail server, so a slow server never blocks the user's other Dolibarr pages. Session writes after that point are not saved; pending notices are moved out first so they show once.
- **Drafts aren't saved**, there are no shared or team mailboxes, and nothing is permanently deleted (emptying Trash is left to the mail client).
- **Tested only** on Dolibarr 23.0 with PHP 8.2 and GreenMail. Not yet tested on real Gmail / Microsoft 365, other Dolibarr versions (the user's production runs 24), or the dark theme.
- **Test-server quirks:** GreenMail omits In-Reply-To from ENVELOPE, returns nothing for partial fetches, doesn't search Latin-1 bodies, refuses grouped search criteria, and matches FROM / TO / CC against whole addresses only (so "dana" finds nothing there; real servers match any part). The client works around the first two and the grouping.
