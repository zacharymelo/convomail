# Core proposal: open tracking for mail sent from Dolibarr's forms (25.0)

**Status:** design draft, not discussed with maintainers, no code yet.
**Target:** `develop` (`DOL_MAJOR_VERSION` 25), branch to create in `~/Desktop/dolibarr-upstream` from `origin/develop`.
**Waits on:** the `__CHECK_READ__` security finding ([security-check-read-key.md](security-check-read-key.md)).
Line numbers below are from `origin/develop` on 2026-10-23.

## Problem

A user sends a proposal, order or invoice from its card. They'd like to know whether the customer opened it, on
the document's timeline, the way mass mailing already records reads.

Core looks as if it supports this and doesn't:
- Email templates offer `__CHECK_READ__` in the form's substitutions. `core/actions_sendmails.inc.php:441`,
  `core/tpl/massactions_pre.tpl.php:345` and `core/actions_massactions.inc.php:602` build a pixel for
  `public/emailing/mailing-read.php?tag=undefined` with `md5(KEY-undefined)`. The endpoint checks
  `md5(KEY-tag-email-mtid)` (`public/emailing/mailing-read.php:114`), so it answers "Bad security key value",
  and it skips `tag=undefined` anyway.
- `core/tpl/card_presend.tpl.php:338-345` builds a different pixel with `$object->thirdparty->tag` (normally
  not set) and the raw key (subject of the security brief).
- `mailing-read.php` only knows mass-mailing targets (`llx_mailing_cibles`). Nothing links an open to the agenda
  event the send created.

Convomail (custom module) has done this for its own sends since 0.2.0: random key per message, public 1×1 GIF
endpoint, open counters, an "Email opened" event on the first open. This proposal brings the same design to
core's send form.

## Proposal

### 1. Setting (Setup > Emails)

`MAIN_MAIL_TRACK_OPENS`, off by default. The setup text says what it does, that opens are approximate, that the
public URL must be reachable from the internet, and that the site's consent/disclosure rules apply
(GDPR/ePrivacy). No tracking happens anywhere while it is off, including for keys already sent.

### 2. Form: a "Track opening" checkbox

In `FormMail`, next to the delivery-receipt option (`getHtmlForDeliveryreceipt()`, around
`html.formmail.class.php:930`): `withtrackopens`, shown only when the setting is on. Unchecked by default;
`MAIN_MAIL_TRACK_OPENS_DEFAULT` = 1 makes it checked. Posted as `trackopens`.
Modules can't add this field today (the `getFormMail` hook replaces the whole form or nothing), which is one
reason it belongs in core.

### 3. Send path (`core/actions_sendmails.inc.php`)

After substitutions, when the setting is on, `trackopens` is posted and the message is HTML (`dol_textishtml()`):
1. `$trackkey = bin2hex(random_bytes(16))`.
2. Pass `$message.$pixel` to `new CMailFile(...)` (line ~460), so only the delivered message carries the image.
3. Keep `$object->actionmsg = $message` without the pixel: the event note is displayed in Dolibarr, and the
   sender viewing their own event must not count as an open.
4. Set `$object->email_track_key = $trackkey` next to `$object->email_msgid` (line ~500), and copy it in
   `interface_50_modAgenda_ActionsAuto.class.php` next to `email_msgid` (line ~1631), the way every other
   `email_*` field reaches the event.

Plain-text messages are not tracked (no image possible); the checkbox says so when the HTML editor is off.

### 4. Storage: four columns on `llx_actioncomm`

| Column | Type | |
|--------|------|-|
| `email_track_key` | varchar(32) NULL | random key, indexed (not unique: see mass actions) |
| `email_nb_open` | integer NOT NULL DEFAULT 0 | |
| `email_date_first_open` | datetime NULL | |
| `email_date_last_open` | datetime NULL | |

Fits next to the existing `email_*` columns. Added to `install/mysql/tables/llx_actioncomm.sql`, the key file,
`migration/24.0.0-25.0.0.sql` and `ActionComm::$fields` / `create()` / `fetch()`.
No IP address, user agent or per-open rows: counters only, to keep personal data out.
Fallback only if maintainers refuse new `llx_actioncomm` columns: a small `llx_actioncomm_emailtrack` table
(`fk_actioncomm`, same four fields).

### 5. Public endpoint `public/agenda/emailopen.php?k=<key>&entity=<n>`

Same setup as `public/emailing/mailing-read.php` (`NOLOGIN`, `NOCSRFCHECK`, `NOSESSION`, entity from the URL).
- Accepts only 32 hex characters (`GETPOST('k', 'aZ09')` plus a length check).
- Always returns the same uncached 1×1 GIF, whether the key exists, tracking is off, or the key is unknown:
  no oracle.
- When the setting is on and the key exists: increment `email_nb_open`, set the dates, and on the first open
  create the "opened" event (below).
- Covered by the existing fail2ban recommendation for `/public/*`.

### 6. The "Email opened" event

On the first open only, an event with `type_code` `AC_OTH_AUTO`, `code` `AC_EMAIL_OPENED`, linked like the
sent event: same `fk_element` / `elementtype`, third party, contacts and project; owner = the sender.
Gated by a new `llx_c_action_trigger` row (`EMAIL_OPENED`), so admins switch it in Agenda > Automatic events like
the `*_SENTBYMAIL` ones. Later opens only update the counters, to keep timelines readable.

### 7. Display

Event card (`comm/action/card.php`, the mail block at ~2953): a "Opened" line, shown when `email_track_key` is
set: "Not opened yet" or "N times, first on …, last on …".

### 8. `__CHECK_READ__` outside mass mailing

Its only working meaning is in mass mailing (`comm/mailing/card.php:299`). In the send form and mass actions:
- with tracking on, a template containing `__CHECK_READ__` ticks the "Track opening" box and the token is
  replaced by nothing (the pixel is added at send time, step 3);
- with tracking off, it is replaced by nothing.

This removes the broken pixels in all four call sites. The minimal security fix comes first and separately; this
step is the feature-side follow-up.

### 9. Mass actions (follow-up PR)

`core/actions_massactions.inc.php` can send one email for several documents (`$oneemailperrecipient`) and then
creates one event per document. The same key goes on each of those events (hence the non-unique index); an open
updates all of them, and the first open creates one "opened" event per document. Left for a follow-up PR to
keep the first one small; until then mass-action sends aren't tracked, and the first PR's `__CHECK_READ__`
change (section 8) still replaces their broken pixel with nothing.

## Side effects, stated plainly

- Recipients' mail apps contact the Dolibarr server when they show the message (only when the sender ticked the
  box and the setting is on). That reveals to the server the time and, in web server logs, the IP of whoever
  loaded the image.
- Opens are approximate: apps that block images miss them; Apple Mail Privacy Protection and security scanners
  load images without anyone reading. The UI wording says "opened" as an approximation, not "read".
- Some spam filters score tracking pixels. Off by default for that reason too.
- Four nullable/defaulted columns on `llx_actioncomm`, one index; a new public page; a new trigger code.
- No change to mass mailing's own tracking.

## Out of scope

Tickets, notifications, Email Collector, mass mailing, plain-text messages, link click tracking.

## Testing plan

1. Fresh install and upgrade from 24.0 (columns, index, trigger row).
2. For each send mode (PHP mail, SMTPS, Swift Mailer): send a proposal from its card with the box ticked; load
   the delivered message; check the counters, the event card, and one "Email opened" event on the proposal's
   Events tab.
3. Box unticked, setting off, plain-text message, unknown key, malformed key, wrong entity: GIF returned, nothing
   recorded.
4. Event note and the sender's view of the event don't contain the image.
5. Multicompany: key from entity 2 recorded in entity 2.
6. PHPUnit for the key check and counters; phan/phpcs clean.

## Convomail afterwards

Convomail keeps `ConvomailTracker` for Dolibarr < 25. On versions with core's columns, it can write
`email_track_key` on the event it creates and point its pixel at core's endpoint, then stop using its own
counters. A row in UPSTREAM.md records the gap once the maintainers agree on the shape.

## Decisions (owner, 2026-10-23)

1. **Storage:** columns on `llx_actioncomm` (section 4). The index is non-unique from the start so the mass-action
   follow-up needs no second migration. Known cost: building the index on large `llx_actioncomm` tables during
   the 24→25 upgrade; say so in the PR.
2. **Checkbox:** unticked by default; hidden constant `MAIN_MAIL_TRACK_OPENS_DEFAULT` = 1 ticks it (section 2).
3. **Mass actions:** follow-up PR (section 9). The first PR covers the card's send form only.
4. **Endpoint:** new `public/agenda/emailopen.php` (section 5); `public/emailing/mailing-read.php` is untouched.

The maintainers can still change any of these; the discussion draft presents them as our proposal.
