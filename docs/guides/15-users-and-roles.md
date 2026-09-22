---
title: "Users, roles and permissions"
slug: users-and-roles
section: guides
order: 15
summary: "Create accounts, decide what each may do, and read the trail of who changed what."
---

At the end of this page other people have accounts on your site, each account holds a role that
decides what it may reach, and every change one of them makes is in the audit log.

You need an account holding the `administrator` role — the one
[the install](../getting-started/02-install.md) created — and `USERS_USER_LOOKUP_ENABLED=true`
and `USERS_USER_LIST_ENABLED=true` in `.env`. Both are `true` in the `.env.example` a new project
copies; without them the user list stays empty and says so.

## Create an account

1. Open **Users & Access › Users** and press the **+** button beside the **Users** heading.
2. Fill in **Username** and **Email**. Both must be unique across the install.
3. Set a **Password** of at least 8 characters. The circular-arrow button in the field generates
   one; the eye button reveals it.
4. Add **First name** and **Last name** if you want them; the admin shows the two together as the
   person's name.
5. Under **Roles**, pick the roles the account starts with. The list holds only roles you are
   allowed to hand out — see below.
6. Press **Create User**.

The account is created **active** with its email already marked verified, so the person can sign
in at `/admin` straight away. Thallo sends them nothing: pass on the address and password
yourself, and tell them to change it from **Forgot password?** on the sign-in screen.

To create many accounts at once, press the upload button beside **+** and map a CSV's columns to
`username`, `email`, `password`, `status`, `first_name`, `last_name` and `roles`. The job runs in
the background under **Settings › Import / Export**. The button appears only when the **Content
importers** capability is on; see [importing content](12-import-content.md).

## The roles a new install has

Five roles are seeded, each with a level. The level is what decides who may hand out what.

| Role | Level | What it holds |
|---|---|---|
| Superuser | 100 | Every permission in the catalogue. |
| Workspace Manager | 90 | `tenancy.manage` and `tenancy.access_any`, and nothing else: create, suspend, manage and enter every workspace. |
| Administrator | 80 | Every permission except `system.config`, `tenancy.manage` and `tenancy.access_any`. |
| Editor | 50 | `content.view`, `content.create`, `content.edit`, `content.publish`. |
| User | 10 | `content.view`. |

`php glueful thallo:provision` grants Superuser and Administrator every permission the install
declares, including those a newly installed pack brings, and [upgrading](../upgrading.md) runs it
again. It keeps no memory of what you revoked, so a permission you take off either of those two
roles is granted back the next time it runs. The three withheld from Administrator are withheld in
the code, and stay off.

These roles are install-wide. A workspace's own `owner`, `admin`, `member` and `viewer` are a
separate set, described in [workspaces](../concepts/08-workspaces.md). The full permission
catalogue is in the [permissions reference](../reference/06-permissions.md).

## Change someone's roles

Select the person in **Users & Access › Users**, stay on the **Details** tab, edit **Roles** and
press **Save changes**. The rules the server applies:

- You need the `users.roles.manage` permission.
- You may only add or remove a role whose level is below the highest level you hold. An
  administrator cannot make another account an administrator.
- To add a role you must already hold every permission that role grants. A superuser is exempt.
- You cannot change your own roles unless you are a superuser.
- `superuser` is never offered. On someone who already holds it, it appears as a padlocked badge
  you cannot remove.
- Only a superuser may add or remove `workspace_manager`.

The last active superuser cannot be deactivated, deleted or stripped of the role, and neither can
the last account holding `tenancy.access_any`. The request is refused and nothing changes.

Setting **Status** to `inactive` blocks sign-in. The trash button soft-deletes the account: it
drops out of the list and loses access, and the row is kept. You cannot delete your own account.

## Change what a role may do

1. Open **Users & Access › Roles & Permissions** and select the role.
2. The editor shows **Available** on the left and **Assigned** on the right. Select permissions in
   either column, or press **Select all**, and move them with the chevron buttons between the two.
3. Press **Save permissions**. The role ends up holding exactly what is in **Assigned**.

The **+** button makes a new role. **Name** and **Slug** are required; the slug is lower-case
letters, numbers, `-` and `_`, and cannot be changed once the role exists. A new role is created
at level 0, below every seeded role, so who may hand it out comes down to the permissions you then
give it.

Deleting a role is refused while any account still holds it, and the five seeded roles cannot be
deleted at all. To retire one, take it off everyone first.

To give one person a permission their roles do not carry, open them under **Users**, switch to the
**Permissions** tab and move it across there. Permissions that come from a role are listed under
**From roles** and cannot be removed on that tab.

## The superuser

Superuser is the install's root authority, and the admin cannot grant it. Two commands can, and
both need the account's UUID, shown as **User ID** at the top of the person's page.

```bash
$ php glueful thallo:superuser:grant <user-uuid>
$ php glueful thallo:superuser:transfer <from-user-uuid> <to-user-uuid>
```

`thallo:superuser:grant` gives the account the `superuser` and `administrator` roles.
`thallo:superuser:transfer` gives the destination both and takes `superuser` off the source, in
one transaction: either all of it happens or none of it does. Every account named must be active.
Both ask you to confirm; `--force` skips the question, and is required when the command is not
run from a terminal. Both write to the audit log.

## Sign-in

The admin's sign-in screen takes an email and a password. **Forgot password?** emails a one-time
code, which the next two screens exchange for a new password, so outgoing email has to work.

`config/auth.php` also offers email two-factor authentication, off by default
(`TWO_FACTOR_ENABLED`). Its tunables are `TWO_FACTOR_PIN_LENGTH` (6 digits), `TWO_FACTOR_PIN_TTL`
and `TWO_FACTOR_CHALLENGE_TTL` (300 seconds each), `TWO_FACTOR_DISABLE_FRESHNESS` (300) and
`TWO_FACTOR_TEMPLATE` (`two-factor-pin`). Two-factor is a property of an account, switched from
the terminal — `php glueful 2fa:status`, `2fa:enable` and `2fa:disable`, each taking a user UUID —
and the **Details** tab shows a **2FA on** or **2FA off** badge. Signing in to an account with
two-factor on emails a code, and the admin's sign-in screen asks for it before letting you in; to
get a new code, start the sign-in again.

`SESSION_COOKIE_ENABLED` is not about the admin: it carries the sign-in for the site's own
visitors. See [accounts](17-accounts.md).

## Read the audit log

**Users & Access › Audit Log** lists what happened, newest first, 25 to a page. Two dropdowns
filter it: by category (`auth`, `rbac`, `user`, `content`, `media`, `security`, `data`) and by
action (**Login**, **Role assigned**, **Published**, **Deleted** and the rest).

Select an entry to see who did it and to what, under **Actor** and **Activity details** — category,
action, target, timestamp, IP address, request ID and user agent. An edit also lists every field
that changed, old value to new, under **Changes**, and **Metadata** prints the whole context as
JSON. Passwords, secrets, API keys and anything ending in `_token` are redacted before the row is
written.

The log is append-only: nothing in the admin edits or deletes an entry. `php glueful audit:prune`
deletes rows older than `AUDIT_RETENTION_DAYS`, which defaults to 365.

## Check it worked

Sign in as the account you made, in a private window so your own session stays. Then open
**Users & Access › Audit Log** as yourself and filter the category to `auth`: the newest entry is
that account's **Login**. Switch the category to `rbac` and the **Role assigned** entry from when
you created it is there.

The sidebar is not narrowed by permission, so an account that lacks a permission still sees the
menu item; the page behind it refuses to load its data.
