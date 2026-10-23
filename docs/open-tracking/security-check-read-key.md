# Brief: does `__CHECK_READ__` leak `MAILING_EMAIL_UNSUBSCRIBE_KEY`?

**Status:** suspected from reading the code, not reproduced.
**Confidential:** don't post anything about this publicly (GitHub issue, PR, forum) until the owner decides.
Dolibarr's SECURITY.md lists public GitHub issues as one "private" channel; use **security@dolibarr.org** instead.

## Goal

1. Reproduce or rule out the leak on the dev stack (Dolibarr 23) and confirm the same code on `develop` (25.0).
2. Work out the real impact: what someone holding the key can do.
3. Decide, with the owner, whether it qualifies under Dolibarr's [SECURITY.md](https://github.com/Dolibarr/dolibarr/blob/develop/SECURITY.md)
   (private report) or is a plain bug (normal PR, which then also unblocks the core open-tracking work).
4. Write the report or the bug PR text. **Don't send or post it**: hand it to the owner.

## The code

`MAILING_EMAIL_UNSUBSCRIBE_KEY` is the secret behind the mass-mailing links (Setup > Modules > Emailing).
`public/emailing/mailing-read.php:114` and `public/emailing/mailing-unsubscribe.php:79` accept a request only if
`securitykey == md5(KEY."-".tag."-".email."-".mtid)`.

`__CHECK_READ__` is built in six places (line numbers from `origin/develop`):

| File | What it puts in the URL |
|------|-------------------------|
| `htdocs/core/tpl/card_presend.tpl.php:338-345` | `tag=<$object->thirdparty->tag>` and `securitykey=<the raw KEY, urlencoded>` ← **suspect** |
| `htdocs/core/actions_sendmails.inc.php:441` | `tag=undefined`, `securitykey=md5(KEY-undefined)` (never matches, records nothing) |
| `htdocs/core/tpl/massactions_pre.tpl.php:345` | same as above |
| `htdocs/core/actions_massactions.inc.php:602` | same as above |
| `htdocs/admin/mails.php:79`, `htdocs/admin/mails_emailing.php:67` | `tag=undefinedtag`, hashed (test sends) |
| `htdocs/comm/mailing/card.php:299, 515` | the real per-target tag, hashed (mass mailing, correct) |

`card_presend.tpl.php` fills the substitution array used to prefill the form's message from the chosen email
template. If the template contains `__CHECK_READ__`, the image (raw key included) should land in the editor
content and then be sent: `actions_sendmails.inc.php` substitutes again, but the token is already gone.

## How to reproduce (dev stack, see CLAUDE.md "Dev stack")

1. Set a value for `MAILING_EMAIL_UNSUBSCRIBE_KEY` (Setup > Modules > Emailing setup).
2. Enable the HTML editor for emails if needed, and create an email template for proposals whose body contains
   `__CHECK_READ__` (Setup > Emails > Email templates).
3. Open a proposal whose third party has an email, Send email, pick the template. Check the editor's HTML
   source for the `<img>`. Send.
4. Read the delivered message from GreenMail (IMAP `localhost:3143`, any login) and look for
   `securitykey=<raw key>`. Also try with the template body applied without opening the editor (plain text mode).
5. Repeat on a `develop` install if possible, since SECURITY.md only counts issues present on `develop`.
   At least confirm the code is unchanged there (`git show origin/develop:htdocs/core/tpl/card_presend.tpl.php`).

## Impact questions to answer

- With the raw key, the holder can compute `securitykey` for **any** tag. Tags are
  `md5(instance_unique_id;email;lastname;mailing_id;KEY)` (`core/modules/mailings/modules_mailings.php:263`),
  so they also need the instance's `instance_unique_id` to guess other recipients' tags. Is there any place where
  tags or the instance id are exposed? If not, the practical impact may be small: forging read/unsubscribe for
  tags the holder already received.
- Is `MAILING_EMAIL_UNSUBSCRIBE_KEY` used for anything else (search `git grep MAILING_EMAIL_UNSUBSCRIBE_KEY`)?
- Does it qualify? SECURITY.md lists qualified types (none is "secret disclosure" as such), requires the issue on
  `develop` with a production setup, and the template step means a user with template rights must have used
  `__CHECK_READ__`. Write down the honest severity.

## Deliverables

Put them next to this file:
- `security-check-read-key-findings.md`: what was reproduced, on which version, the impact, the severity, and
  the recommendation (private report or plain bug fix).
- If a report: `security-check-read-key-report.md` in the format SECURITY.md asks for (plain text/MD, steps to
  reproduce, one vulnerability only).
- The fix you'd propose (likely: hash the key as the other call sites do, or drop the substitution). The
  replacement design for `__CHECK_READ__` is in [core-open-tracking-v25.md](core-open-tracking-v25.md); keep the
  security fix minimal and separate from it.

Don't push, post or email anything. Don't change Convomail's code for this.
</content>
</invoke>
