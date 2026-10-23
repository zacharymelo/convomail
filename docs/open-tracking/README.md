# Open tracking: goals and work split

Convomail tracks opens of the mail it sends (`ConvomailTracker`, `public/open.php`, 0.2.0). The question that
started this: can the same work for documents sent from Dolibarr's own "Send email" form (a proposal sent from
its card)? Reading core for that turned up `__CHECK_READ__`, a core substitution meant to do this, which records
nothing and may leak a secret.

## Goals

1. **Security:** find out whether `__CHECK_READ__` sends `MAILING_EMAIL_UNSUBSCRIBE_KEY` to recipients, how bad
   that is, and how to report or fix it. Brief: [security-check-read-key.md](security-check-read-key.md).
   Separate agent. Confidential until the owner decides.
2. **Core feature for 25.0:** propose working open tracking for mail sent from Dolibarr's forms, replacing the
   broken `__CHECK_READ__` pixel. Design: [core-open-tracking-v25.md](core-open-tracking-v25.md). Draft post for
   the maintainers: [core-open-tracking-discussion.md](core-open-tracking-discussion.md).
3. **Convomail afterwards:** once core ships it, Convomail uses core's tracking where the Dolibarr version has
   it and keeps `ConvomailTracker` for older versions, until the module's minimum version includes it.

Not a goal: a new core module, or a separate custom module. Open tracking belongs with `CMailFile`, the send form
and the agenda, which are already core.

## Order

1. Security finding settled first (report sent, or judged a plain bug). A public feature PR that rewrites
   `__CHECK_READ__` would point at the issue, so it waits.
2. Maintainer discussion on the feature (privacy is the likely objection).
3. Core branch from `develop` in `~/Desktop/dolibarr-upstream`, reviewed by the owner before any push.
4. UPSTREAM.md item and Convomail follow-up once the shape is agreed.

The optional interim step (tracking native-form sends from inside Convomail with a `sendMail` hook) is not
planned. The owner chose to put the effort into core.

## Rules (from CLAUDE.md)

- No push, PR, issue, email or forum post without the owner's go-ahead after review.
- Describe side effects honestly in any core PR draft.
- Work in `~/Desktop/dolibarr-upstream` on new branches from `origin/develop`; that checkout has other work in
  progress on other branches (currently `v24-hook-processbomlines`), so use a worktree instead of switching
  branches.
</content>
</invoke>
<invoke name="mcp__ccd_turn__update_plan">
<parameter name="advance">true