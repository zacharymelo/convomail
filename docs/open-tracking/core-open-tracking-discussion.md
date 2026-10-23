# Draft: feature request for the Dolibarr maintainers

**Not posted.** For a feature-request issue on Dolibarr/dolibarr, after the owner's review and only once the
security finding is settled. It deliberately doesn't mention `card_presend.tpl.php` or the unsubscribe key.
Full design: [core-open-tracking-v25.md](core-open-tracking-v25.md).

---

**Title:** Open tracking for emails sent from object cards (proposal, order, invoice…)

**Is your feature request related to a problem?**

When a proposal or invoice is sent from its card, there is no way to know whether the customer opened it. Mass
mailing records reads, but mail sent from the card's "Send email" form doesn't. The `__CHECK_READ__`
substitution is offered in that form's templates, but outside mass mailing it points to
`public/emailing/mailing-read.php` with `tag=undefined`, so nothing is ever recorded.

**Describe the solution you'd like**

An optional, off-by-default open tracking for the send form, recorded on the agenda event the send creates:

- A setting in Setup > Emails (off by default, with a consent/disclosure note) and a "Track opening" checkbox in
  the send form, next to the delivery receipt option.
- When ticked and the message is HTML, a random 32-hex key goes on the agenda event (new
  `llx_actioncomm.email_track_key`, with `email_nb_open`, `email_date_first_open`, `email_date_last_open`), and a
  1×1 image pointing to a new public page `public/agenda/emailopen.php?k=<key>&entity=<n>` is added to the
  delivered message only, not to the event note.
- The public page always returns the same GIF (no oracle). For a known key it updates the counters, and on the
  first open creates an "Email opened" event linked to the same document, third party, contacts and project
  (new automatic event `EMAIL_OPENED`, switchable in Agenda setup).
- The event card shows "Not opened yet" or the count and dates.
- `__CHECK_READ__` in the send form and mass actions then simply ticks the box instead of producing a pixel
  that can't work.

No IP address or user agent is stored. Opens stay approximate (image blocking, Apple Mail Privacy Protection,
security scanners), and the UI says so.

**Describe alternatives you've considered**

- A module doing it through the `sendMail` hook: it works, but it can't add the checkbox (the `getFormMail` hook
  replaces the whole form or nothing) and has to patch the body per send mode.
- A separate table instead of columns on `llx_actioncomm`: fine too if preferred.

**Additional context**

We run the same design in a custom module (webmail for Dolibarr) since early 2026 and would prepare the PR
against `develop`: setting, form checkbox, columns and migration, public page, event, card display, tests.
Mass actions (one email for several documents) would follow in a second PR.

Questions before we start: is open tracking acceptable in core at all, given the privacy side? And is the shape
above right? In particular, we chose new columns on `llx_actioncomm` (with an index, which takes time to build
on large tables during upgrade) over a separate table, a new page under `public/agenda/` over a mode of
`mailing-read.php`, and an unticked checkbox by default.
