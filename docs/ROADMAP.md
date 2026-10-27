# Convomail roadmap

The plan for the module and for proposing webmail to Dolibarr core. Update it when a phase ships or a decision is made. Structure and data model are in [ARCHITECTURE.md](ARCHITECTURE.md).

## Why

Users leave Dolibarr to send email from another client, and the link between a conversation and its customer, contact and project is lost. Convomail keeps email in Dolibarr: each user's own mailbox, with every message that matters on the agenda.

## Where it stands (1.2.0)

| Phase | Scope | Version |
|---|---|---|
| 1. Send and log | Accounts per user, compose / reply, the user's SMTP, Sent copy over IMAP, `AC_EMAIL` events with third party, contacts, project, attachments | 0.1.0 |
| 1b. Receipts | Read-receipt request; open tracking (admin switch, off by default); Email setup tab via the optional core patch; module ID 194282 | 0.2.0 |
| 2. Read and file | Mailbox (folders, list, message page), safe HTML display, reply / reply all / forward, Log to…, "server saves sent mail itself" | 0.3.0 |
| 2c. Projects and webmail | Three-pane mailbox, bulk actions and shortcuts, project Emails tab, project detection (logged / thread / code / suggested), automatic filing of replies, rich-text compose with signature, templates, pasted images and project contact chips | 0.4.0 |
| Fixes | Newest mail first on every IMAP server; list pane scrolls on small screens; messages per page per user (10–100); CSS/JS refreshed after upgrades | 0.4.1 |
| 2b. OAuth | Sign in with Google or Microsoft 365: the admin picks a core OAuth app per provider, each owner connects their account (`oauth.php`), tokens in `llx_oauth_token` per account, XOAUTH2 for IMAP (Horde) and SMTP (`CMailFile`). IMAP protocol trace in Debug mode, with tokens masked. Address suggestions in To / Cc / Bcc | 0.5.0 |
| Fixes | Fast search (sender, recipients, subject by default; "In text" optional; cached results); slow mail servers no longer block the user's other Dolibarr pages | 0.5.1 |
| Fixes | Tables upgrade themselves after new files are deployed (accounts looked lost when 0.5.x files ran on 0.4.x tables); a failure to load accounts is shown as an error | 0.5.2 |
| Third party from an email | Create third party (core form, prefilled from the address) or Add as contact of the third party using the domain; then add the contact and log the email in one step. Fix: replies quoting collected emails were refused by core's injection filter | 0.5.3 |
| Web form email | The Reply-To person is treated as the sender when it differs from From: list ("X via site"), reading pane, project suggestion, filing, Create third party, Reply; form mail sent as the user's own address is logged as received | 0.5.4 |
| Fixes | The message list updates as soon as an email is filed from the reading pane | 0.5.5 |
| Docs | Convomail and Email Collector on the same mailbox: one event per email, verified in both orders | 0.5.6 |
| Fixes | Newsletters' hidden preview text no longer shows as a column of letters; buttons keep display:inline-block | 0.5.7 |
| 3. Automatic filing and shared mailboxes | Automatic filing through core's Email Collector (two collectors per mailbox, managed by Convomail, token refresh job), shared mailboxes with Read / Read and send per user, Shared mail accounts dashboard | 0.6.0 |
| UI | "Log replies automatically" (was Automatic filing) with tooltips, status details and a How does it work? box; Shared mail accounts (was All mail accounts) | 0.6.1 |
| Fixes | Collectors named after their mailbox and Dolibarr user (existing ones renamed on upgrade); no more raw text keys as collector labels | 0.6.2 |
| Fixes | The From and mailbox dropdowns show the email address ("Name — address") | 0.6.3 |
| Attachments | Files stored in Dolibarr picked with the optional Document Browser module (separate repo), largest ones sent as share links over its size limit | 0.7.0 |
| Fixes | "New third party" from an email works with CSRF level 3 (`MAIN_SECURITY_CSRF_WITH_TOKEN=3`) | 0.6.4 |
| Conversations | Under each message, the rest of its conversation (earlier messages and replies, including those sent and archived) from Inbox, Sent and Archive; per-user **Show conversation** switch | 0.7.0 |
| AI writing tools | Compose: write from a prompt (replies use the message answered), rephrase, shorten or translate the typed text with core's AI module; signature and quote untouched, Undo | 0.8.0 |
| Fixes | Replies keep the quote's indent and grey bar, and quoted plain-text mail keeps its line breaks (the editor's content filter dropped them) | 0.8.1 |
| Fixes | Setup: Allowed mail servers shows one host per line again, and a list broken by an earlier save (literal `\n`) works and is repaired on the next save | 0.8.2 |
| Changes | No more **Email from my mailbox** button on third party, contact and project cards, which already have core's **Send email** | 0.9.0 |
| Email links | `mailto:` links in messages open Convomail compose with recipients, Cc, subject and text; Shift-click opens the system mail app | 0.9.0 |
| Layout | Resizable mailbox columns; the conversation stays at the bottom of the reading pane under the scrolling message, with a resizable split | 0.9.0 |
| Fixes | The project Emails tab showed its threads without their messages since 0.7.0 (a conversation style shared the class name; now `cm-conv-*`) | 0.9.0 |
| Conversations | Reply, Reply all and Forward on each opened message of the conversation panel | 0.9.1 |
| Fixes | The folder list shows subscribed folders, as mail apps do, so an unsubscribed leftover (an empty `INBOX.sent`) no longer appears as a second Sent; folders Convomail creates are subscribed when the server uses subscriptions | 0.10.0 |
| Folders | New folder (also from Move to), rename and delete empty folders, drag messages onto a folder; shared mailboxes' folders are managed by admins | 0.10.0 |
| Folders | Folder tree: subfolders indented and collapsible, New subfolder, renames carry subfolders and their subscriptions, full paths in the Move menus | 0.11.0 |
| Fixes | The personal namespace ("INBOX." servers) was never detected, so new folders went to the top level there | 0.11.0 |
| Folders | Move a folder by dragging it onto another folder or onto the top-level zone; subfolders, messages and subscriptions follow | 0.12.0 |
| Fixes | Dragging a folder was cancelled by the browser as soon as it started (the top-level zone appeared and moved the list); the zone now lays over the column | 0.12.1 |
| From an email | File attachments on any record or a Documents folder; a Create menu grouped by who the email is from (Customer: proposal, order, invoice; Supplier: invoice, quote; Other: ticket, project/lead) opens a small panel in the reading pane (finished on the record's card), with its files and the email logged on it; each kind can be turned off in setup | 0.13.0 |
| From an email | The customer invoice kind is on by default, like the others (it started off in 0.13.0) | 0.13.1 |
| Phones | Mailbox header (account and Compose on one line, folders in one sideways row with full paths, search on its own line), icon-only message toolbar, forms with labels above full-width fields | 0.13.1 |
| From an email | Link to record: log an email on an existing document, or link (or move) its agenda event there, optionally filing its attachments; suggestions from references in the subject and the sender's latest documents; the pane shows the event's record | 0.14.0 |
| From an email | Suggested and picked records open in a new tab, to check them before linking, filing or creating | 0.14.1 |
| Open tracking | Opened or not shown in the mailbox: an eye on sent rows (count and times in its tooltip), a line on the open message, the eye in the conversation; no need to open the agenda | 0.15.0 |
| Compatibility | Dolibarr 20 and 21: attachment checks use a copy of core's executable list where `getExecutableContent()` is missing (22+), and the AI writing tools only show where core has their lists (22+); mailbox notices are plain text (Dolibarr 20 showed their HTML). Checked on a Dolibarr 20 stack (`dev/compat/`) | 0.15.1 |
| From an email | **Fill from the invoice** on the supplier invoice panel (setting, off by default): reads the e-invoice data attached (UBL/CII XML) or embedded in the PDF (Factur-X, ZUGFeRD, XRechnung) on the server, only when the user clicks, and fills the number, dates and one line per VAT rate with a badge naming the source. The supplier is never taken from the document (warning when the VAT numbers differ); credit notes and other currencies are not filled. The panel takes up to 10 lines and a due date. The readers are a component of their own, reached through one class | 0.16.0 |
| Mailbox | New mail shows up by itself: an open mailbox checks every minute while its tab is visible (setting *Check for new mail*: 30 s to 5 min, or never; one IMAP STATUS per check). New messages slide into the first page, unread counts and the tab title follow; elsewhere a notice bar offers **Show**. Errors stop the checks after three tries with **Retry** in the bar; an ended session or a recreated folder offers **Reload** | 0.17.0 |
| Supplier invoices | **Read with AI** (setting, off by default; needs *Fill from the invoice* and core's AI module): a PDF without e-invoice data is read by the AI service set up in Dolibarr, after bank details, emails and phone numbers are removed; the answer is checked field by field and fills the panel marked **AI**. Follows Dolibarr 25's AI conventions (`docparsing`, error messages, request log, privacy redaction switch) | 0.17.0 |
| Compose | Reply and Reply all offer the original attachments (unticked). Forwards keep the images shown in the text, embedded, and list them with checkboxes; an image too large or SVG goes as an attachment instead of being dropped. An image a message attaches with a Content-ID but never shows is listed as an attachment | 0.17.0 |
| Compose | **Attach files…** shows each file as a chip (name, size, × to remove), adds to the list on each pick, and warns over the server's upload limit. Files dragged from the computer can be dropped anywhere on the page (an overlay says what the drop will do): images dropped on the text go in the text, other files are attached; a missed drop never leaves the page | 0.18.0 |
| From an email | The third party of every Create panel shows as Dolibarr's own link once chosen, with a pen to pick another. *Fill from the invoice* and *Read with AI* select the supplier whose VAT number the invoice gives (exactly one), marked *VAT number*, with a warning when the email does not come from one of its addresses. While they read, the panel stays on screen with a progress line | 0.18.0 |
| Security | Fixes from the audit of 2026-10-26: the SMTP settings of a send are restored whatever happens (`finally`); the mail-server host check reads IPv4 and IPv6 addresses, refuses more non-public ranges (CGNAT, IPv4 inside IPv6…) and runs again before every IMAP connection; entity filters on single-record reads; escaped page URLs; Dolibarr's file functions. The debug endpoint's free SQL mode is gone from releases (it read every secret of the database); a hardened copy lives on the `debug-tools` branch. Checks: `dev/checks/security.php` | 1.0.0 |
| Top bar | Unread emails in the top bar: from Dolibarr contacts and third parties by default (or all, or off), mailboxes chosen per user, a shortlist that opens each email, Mark all as read up to the newest counted; emails from contacts marked in the list (ARCHITECTURE 6.2c) | 1.1.0 |
| Open tracking | The team's own views are left out: Convomail's tracking image is removed from incoming mail and quotes, and loads from a signed-in Dolibarr browser, a recent sign-in address, the admin's networks or the server are not counted. Each load gets a kind (direct, Gmail, Yahoo, Apple Mail Privacy Protection, scanner, team; scanners not counted), shown as a breakdown with the latest loads; addresses compared, never stored (6.6) | 1.1.0 |
| Combined Inbox | The Inboxes of chosen mailboxes in one list (`mailbox.php?view=combined`): envelopes merged by arrival, a cursor per mailbox for older/newer pages, a one-minute per-user cache, every action in the row's own mailbox (moves stay within it), replies from the mailbox the email came to, new-mail checks and search across mailboxes, Mark all as read, a notice for an unreachable mailbox (6.2d) | 1.1.0 |
| Spam | **Spam** / **Not spam** buttons and the `!` key: to the mailbox's Junk folder or back to the Inbox, tagged `$Junk` / `$NotJunk` for servers that learn from them; in the Combined Inbox too (6.7). Lists in arrival order (INTERNALDATE, then UID; times cached per folder), so an email moved back keeps its place; Combined Inbox cursors are (arrival, UID) positions (4, 6.2d) | 1.2.0 |

**Tested:** Dolibarr 23.0 (everything), 24.0 (by the owner, on their test instance, 1.0.9x and 1.1.91 test builds) and 20.0 (install, mailbox, compose, File / Create panels: `dev/compat/`), PHP 8.2, GreenMail, Chromium, light theme. The 0.17.0 and 0.18.0 features were also checked by the owner on their test instance (test builds 0.16.91–0.16.93, 0.17.90–0.17.92). See [Test debt](#test-debt).

## Next

Phases in order of value as last agreed. Re-check the order with the owner before starting one.

### Mailbox follow-ups
- Combined Inbox: a combined Sent folder; a conversation that spans mailboxes (today it stays in the email's own mailbox).
- Opt-in per user: a browser notification for new mail.

### Reading supplier invoices: follow-ups
- **Done in 0.16.0: e-invoice data.** Setting *Fill from the invoice* (off by default); when on, a button on the supplier invoice panel reads the XML attached (CII/UBL) or embedded in the PDF (Factur-X, ZUGFeRD, XRechnung) on the server and fills the number, dates and one line per VAT rate, each with a badge naming its source. Nothing is read before the click. The supplier from the sender stays, with a warning when the VAT numbers differ; with none, the supplier whose VAT number the invoice gives is selected (exactly one), with a warning when the email does not come from one of its addresses. Credit notes and other currencies are not filled. See ARCHITECTURE 6.13.
- **Done in 0.17.0: Read with AI.** Second setting (off by default; needs the first and core's AI module). When a PDF has no e-invoice data, a **Read with AI** button sends its text to the AI service set up in Dolibarr, after removing bank details, emails and phone numbers, and fills the fields from the checked answer with **AI** badges. It follows Dolibarr 25's AI conventions: the `docparsing` feature code of core's own document import (its model setting, JSON mode and timeout from 25), core's error messages, the AI request log (`AI_LOG_REQUESTS`), and core's PrivacyGuard only under core's own redaction switch (`AI_PRIVACY_REDACTION`; 24.0's masks amounts too, 25's does not; both leave most of a spaced IBAN, UPSTREAM 18). Readings are kept a week. Tested against a mock provider (`dev/invoices/mock_ai.php`) on Dolibarr 23, with 24.0.0's and develop's PrivacyGuard; not yet with a real provider or on 24/25.
- **Dolibarr 25 overlap.** Core 25 adds its own *Direct import* of a supplier invoice from an uploaded file, read by the AI. Convomail's reading starts from the email, reads e-invoice data first without AI and checks every value; keep it aligned with core's conventions and revisit handing the AI step to core once 25 is released.
- **Later: mailbox tools for core's AI assistant** (Dolibarr 25 `addMcpTools` hook): search and read the user's mail from the assistant, with the same account checks as the mailbox.
- **Kept apart to be moved later.** The readers depend on Dolibarr core only and Convomail reaches them through `ConvomailInvoiceFill` alone, so they can become their own module, or a core contribution (core reads no e-invoices), without touching the panel.
- No OCR: a scanned invoice has no text, so it is typed by hand.
- To test: e-invoices made by other software (only TCPDF and hand-made PDFs so far), Dolibarr 20 and 24.

### 4. Sales features
- "Awaiting reply" / "Replied" state per thread, with columns on third party and project lists.
- Follow-up nudges: an agenda to-do when an outgoing email has no reply after N days.
- The last message on opportunity / project cards (hook on `projectcard`).
- Response-time report per user and per customer.

### Core's Send email through the user's mailbox
Proposed 2026-10-23, not built. Place in the order to be agreed with the owner.

**Goal:** one **Send email** button everywhere. Core's own form (on third parties, contacts, projects, proposals, orders, invoices…, with its document PDF and templates) sends through the user's own mailbox, and the copy lands in their Sent folder. That replaces Convomail's own button, removed from those cards for now. Today core's form sends through the company server: no Sent copy, and the user's domain checks (SPF/DKIM) may fail.

**How core sends** (Dolibarr 23):
- `core/actions_sendmails.inc.php` reads the form (`fromtype`: `user`, `company`, `robot`, aliases, `senderprofile_*`…), builds `CMailFile` with `sendcontext` `standard`, sends, then calls the card's trigger (`*_SENTBYMAIL`) with `email_msgid` = the CMailFile Message-ID.
- `CMailFile` picks its send mode **in the constructor** (`MAIN_MAIL_SENDMODE_<CONTEXT>`, else `MAIN_MAIL_SENDMODE`) and reads the SMTP settings in `sendfile()` (`MAIN_MAIL_SMTP_SERVER_<CONTEXT>`…).
- Hooks (context `mail`): `sendMail` before sending (returning 1 replaces the send) and `sendMailAfter` after it (`parameters['sent']`, the CMailFile as object).
- Card pages run the `doActions` hook (context `globalcard` on nearly every card) **before** including `actions_sendmails.inc.php`.

**Design:**
1. **Decide** in `doActions` (context `globalcard`) when `action=send` arrives: route only when the module setting is on (off by default at first), the sender is the user's own address (`fromtype=user`) or a shared mailbox address they may send as, and that address is one of their usable Convomail accounts (`canSend()`). Anything else (company, robot, aliases) keeps core's company server.
2. **Point core at the account**, in memory only and for that one email: set `MAIN_MAIL_SENDMODE_STANDARD=swiftmailer` and the `MAIN_MAIL_SMTP_*_STANDARD` keys from the account, password or XOAUTH2, by reusing `ConvomailTransport::overrideSmtpSettings()` with a context parameter. It has to happen in `doActions`, because the send mode is fixed when `CMailFile` is built.
3. **Copy to Sent and restore** in `sendMailAfter` (context `mail`): when `sent` is true and the account doesn't save sent mail itself, append `$mailfile->message->toString()` to the Sent folder (the transport's `copyToSent()`), then restore the settings right away. Other emails sent in the same request (notifications, triggers) then go out as before. A failed Sent copy is a warning, as in compose.
4. **Link it** in Convomail's trigger on `*_SENTBYMAIL`: add the `llx_convomail_message` row (account, direction 1, Message-ID). Let `ConvomailProjectResolver` and the conversation view accept core's `*_SENTBYMAIL` event codes beside `AC_EMAIL`, so these emails thread and file like Convomail's own.
5. **Shared mailboxes:** core's From list doesn't know them. Show a shared mailbox there only if a hook on the form allows it, or ask the admin to add a sender profile with the same address (step 1 then matches it).

**To check while building:**
- A trigger that sends mail before `sendMailAfter` runs (setting leak).
- Core's read receipt option.
- Ticket and mass-mailing contexts, which must never be routed.
- Message-ID consistency between the sent email, the Sent copy and the event.
- What the user sees when the mailbox refuses the send.

**Tests:**
- A proposal with its PDF sent from its card arrives through the user's SMTP, with the same Message-ID in Sent and on the event.
- Sending as the company address still uses the company server.
- A notification triggered by the send is unaffected.
- OAuth accounts; `server_saves_sent` accounts (no double copy).

### Smaller items
- Publishing Dolibarr contacts to other address books (LDAP via core's LDAP module, or Google / Microsoft 365 contacts): discussed and parked by the owner (2026-10-23).
- Search across folders, and Gmail's own search syntax (`X-GM-RAW`) for fast text search on Gmail.
- Save drafts to the IMAP Drafts folder.
- Several References ids when core accepts them (UPSTREAM item 7).
- Per-message assignment in shared mailboxes (who handles which email).
- Group-based access to shared mailboxes (today per user).
- Translations beyond en_US (fr_FR first).
- A per-user "file this sender always to project X" rule.

## Toward core

Each [UPSTREAM.md](../UPSTREAM.md) item is a small separate core PR, to make before proposing the module itself.
- **Item 8** (Email setup tabs) is prepared on branch `new-email-admin-module-tabs` in `~/Desktop/dolibarr-upstream` and **held for the owner's review**. Don't push it without their go-ahead.
- Items 2, 3, 5, 7 and 10 are small and self-contained: good next candidates.
- Items 4 and 6 are design changes and need a discussion with core maintainers first.

## Open decisions

| Question | Options | Notes |
|---|---|---|
| Auto-filing scope | Replies only (core's isanswer filter, now) vs anything from a known contact | Privacy: personal mail from a contact would land on the agenda; core has no "known contact" filter |
| Distribution | DoliStore listing vs GitHub releases only | The repo is public on GitHub, with releases there |

## Test debt

- **Audit of 2026-10-26, left for later:** `MAIN_DB_PREFIX` instead of `$db->prefix()` (6); PHPUnit tests for the pure functions (matcher, host check, parsers) (9). The DNS-rebinding window between the host check and the connection remains: the IMAP and SMTP libraries resolve the name themselves.

Not yet verified. Do these before telling users it works there:
- **Dolibarr 24** (the owner's production) and `develop`; **21 and 22** (20 and 23 are checked; `dev/compat/` runs any version with `DOLI_VERSION`).
- **Real servers:** Gmail and Microsoft 365, with app passwords and with OAuth (consent, refresh at their token endpoints, Google app verification, Exchange Authenticated SMTP), Sent folder detection, `server_saves_sent`, SPECIAL-USE folders, search. OAuth is verified only against a local Dovecot with a token check (`dev/oauth/`).
- **The owner's "other" IMAP server:** deleting an open email fails with "Mail server closed the connection unexpectedly". Not reproduced on GreenMail or Dovecot; capture `ajax/debug.php?mode=imaptrace` there.
- **Dark theme** and the Eldy / Md themes in narrow windows.
- Large mailboxes: listing and search measured on 20,000 messages on a local Dovecot (`dev/bigmailbox/`, 0.5.1). Still to check: a remote hosted server, 100k+ messages, folder counts on many folders.
- Multicompany (`entity` > 1).
- Upgrading straight from 0.1.0 or 0.2.0 to the current version.
