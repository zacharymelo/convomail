# Core patches

Small Dolibarr core changes that convomail can use but does not require. Each one is also proposed upstream
(see [UPSTREAM.md](../UPSTREAM.md)). Remove a patch from your install once your Dolibarr version includes it.

## dolibarr-email-admin-module-tabs.patch

**What it does:** lets modules add tabs to **Home → Setup → Emails**. With it, convomail's setup page appears there as
the **Convomail (user mailboxes)** tab, next to *Incoming emails*. Without it, convomail works the same and its setup
page is reached from the module list (Home → Setup → Modules → Convomail).

**The change:** `email_admin_prepare_head()` in `htdocs/core/lib/admin.lib.php` also calls
`complete_head_from_modules(..., 'email_admin')` in add mode, the same way `company_admin_prepare_head()` does.
1 file, 5 added lines, no database change.

**Side effects:**
- Installs with no module declaring an `email_admin` tab see no difference.
- A module that already declared an `email_admin:+...` tab (ignored until now) starts showing it.
- A module tab is shown according to its own permission condition only. Convomail's uses `$user->admin`.
- When the Email templates page is opened from the user menu, core hides its admin-only tabs; module tabs do not follow that rule.

**Tested:** Dolibarr 23.0 (applies at a line offset). Applies cleanly to `develop` at `8017bd5`; not run on `develop`.

### Apply

The patch paths start with `htdocs/`.

From a Dolibarr git checkout (the folder that contains `htdocs/`):

```bash
git apply --check /path/to/convomail/patches/dolibarr-email-admin-module-tabs.patch
git apply /path/to/convomail/patches/dolibarr-email-admin-module-tabs.patch
```

On a server whose web root *is* the `htdocs` folder (for example the official Docker image, `/var/www/html`):

```bash
cd /var/www/html
patch -p2 --dry-run < /path/to/dolibarr-email-admin-module-tabs.patch
patch -p2 < /path/to/dolibarr-email-admin-module-tabs.patch
```

Then disable and re-enable Convomail (Home → Setup → Modules) so it registers its tab.

### Undo

```bash
cd /var/www/html
patch -p2 -R < /path/to/dolibarr-email-admin-module-tabs.patch
```

A Dolibarr upgrade also overwrites the file, which removes the patch. Re-apply it after upgrading until the change is
in core.

## Upstream pull request (draft, not opened)

Branch `new-email-admin-module-tabs` in `~/Desktop/dolibarr-upstream`, based on `develop`. Proposed title and body:

```markdown
# NEW Allow modules to add tabs on the email setup pages

`email_admin_prepare_head()` only calls `complete_head_from_modules()` in `'remove'` mode, so a module can hide a tab of
Home > Setup > Emails but cannot add one. This calls it in `'add'` mode as well, the same way
`company_admin_prepare_head()` already does, so a module that handles email (incoming mail, user mailboxes...) can put
its setup next to the core email tabs:

    $this->tabs[] = array('data' => 'email_admin:+mytab:MyTabTitle:mylangfile@mymodule:$user->admin:/mymodule/admin/mypage.php');

Side effects:
- No change for installs where no module declares an `email_admin` tab.
- A module that already declared one (ignored until now) will start showing it.
- Visibility of a module tab depends only on the permission condition the module declares.
- Module tabs are added after "Incoming emails"; the existing `'remove'` pass still runs after them.

Tested on 23.0 with a module declaring such a tab (screenshot below). Applies cleanly on develop; I have not run it on develop itself.
```

Attach the screenshot of the Emails setup tab bar with the module tab, and tick "Allow edits from maintainers".
