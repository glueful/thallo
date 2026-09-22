---
title: "Permissions reference"
slug: permissions
section: reference
order: 6
summary: "Every permission, what it allows, and which roles hold it on a new install."
---

A permission is a string such as `content.publish`. Roles hold permissions, accounts hold roles,
and every route of the admin API names the permission it requires. This page lists all of them,
what each one opens, and which roles hold it on a new install. For creating accounts and handing
roles out, see [users, roles and permissions](../guides/15-users-and-roles.md).

## How a permission is checked

A route states one or more required permissions and the check runs after authentication. Holding
any one of the alternatives passes. A route can carry two gates in turn, and then you need both:
binding an API key to a workspace is the only one that does, and it needs `system.access` and
`tenancy.manage`. A failed check returns 403 with the code `FORBIDDEN`.

A grant is checked against a resource. A route whose path carries a language code — an entry's
draft, preview, publish, unpublish, rollback or route for one language — is checked against
`locale:<code>`; every other route against `thallo`. A grant can be narrowed to one resource, but
nothing in the admin sets that filter, so a permission granted there applies to every language.

A request made with an API key has to satisfy the permission twice: the account the key belongs to
must hold it, and one of the key's scopes must match it, both for the same required permission. A
key with no scopes at all is refused on every admin route. Scopes are described in
[the content API](../concepts/07-api.md#api-keys-and-their-scopes).

A few permissions are read in code rather than on a route. `workflow.bypass` is one: it is checked
as an entry is published, against the language being published.

## Thallo's permissions

These twenty-three are Thallo's own catalogue, in the groups the catalogue gives them. They are also
the capabilities a workspace role can hold.

### Content

| Permission | Opens |
|---|---|
| `content.view` | Reading entries, their drafts, versions, languages, routes and scheduled actions; content types and block types with their schema migrations and usage; the pattern library; style classes; regions and the region preview; the media library; the icon inventory. Rendering a preview. |
| `content.create` | Creating an entry, and starting an entry's draft in a further language. |
| `content.edit` | Saving and discarding an entry's draft for a language, setting and clearing its route, applying an edit made in the preview, inserting a block instance, and submitting an entry for review. |
| `content.publish` | Publishing, unpublishing and rolling back a language, and creating and cancelling a scheduled publish or unpublish. |
| `content.delete` | Deleting an entry. |
| `content.manage` | Creating, changing, activating, deactivating and deleting content types and block types, and running their schema migrations. Saving a region. **Settings › General**. Editing and deleting a file in the media library, and asking for it to be optimised. Form submissions. Uploading an import and downloading an export. Reading the themes on disk and the style vocabulary. The accounts settings. Creating the documentation content type. Reading a language's usage. |
| `content.routes` | Listing and creating a content type's redirects, and deleting one. |

### Experience

| Permission | Opens |
|---|---|
| `navigation.manage` | Menus: listing, creating, reading, renaming, reordering and deleting one, and setting its items. |
| `seo.manage` | Reading and writing an entry's SEO meta. |
| `templates.manage` | The theme editor: listing templates, reading, writing and deleting one, its version history and restoring a version, and creating a theme. |
| `styles.manage` | Creating, changing and deleting a style class, and starting a detach-everywhere or remove-everywhere job on one. |

### Operations

| Permission | Opens |
|---|---|
| `analytics.read` | The analytics summary, series and breakdown. |
| `workflow.review` | Approving a submission and requesting changes on it, and the **Review queue**. |
| `workflow.bypass` | Publishing a language whose review is not approved. A workspace's `owner` and `admin` hold it. |

### Workspace

| Permission | Opens |
|---|---|
| `tenant.members.manage` | A workspace's members: listing, adding and removing one and changing their role, the roles that may be handed out, and the workspace's member signup settings. |
| `tenant.domains.manage` | A workspace's domains: adding, verifying, re-verifying, enabling, disabling and removing one. |
| `tenant.roles.manage` | A workspace's roles: the role list, creating, renaming and deleting a custom role, and previewing and saving capability overrides. |
| `billing.manage` | The workspace's subscription: its plan metadata, starting and abandoning a checkout, and cancelling. |

### Collections

| Permission | Opens |
|---|---|
| `collections.manage` | Listing the data collections and reading one's definition. |
| `collections.schema.manage` | Creating a collection, adding and removing its fields and indexes, reordering its fields, changing its access, and deleting it. |
| `collections.data.manage` | A collection's rows: reading, creating, changing and deleting them. |

### Commerce

| Permission | Opens |
|---|---|
| `commerce.view` | Reading the commerce settings, the commerce emails, the marketplace, a product's link to an entry, order search and export, and an order's payments. |
| `commerce.manage` | Linking and unlinking a product and an entry, writing the commerce settings and emails, activating and deactivating the marketplace and setting its commission and master account, completing a sale, and sending a payment link. |

## Permissions outside the catalogue

These come from the framework, from the extensions, and from Thallo's own migrations. They are not
part of the catalogue above, so a workspace role cannot hold them: they are install-wide.

| Permission | Opens |
|---|---|
| `system.access` | Extensions — listing, the Packagist catalogue, a package's readme, enable, disable and install. API keys. Webhook subscriptions and deliveries. The capability switchboard. **Utilities**: health, the cache and clearing it, scheduled tasks and running one. |
| `system.config` | Defining permissions themselves: creating, changing and deleting a permission, and clearing expired ones. |
| `users.view` | The user list and one account's record, its roles, its direct permissions, its effective permissions, its access overview and its role history. |
| `users.create` | Creating an account. |
| `users.edit` | Changing an account — including its roles, which additionally needs `users.roles.manage` — and granting or revoking a permission directly on one. |
| `users.delete` | Deleting an account. |
| `users.roles.manage` | The roles an account may be given, and **Settings › Signup** with the role editor behind its **Manage roles** button. Also required, on top of `users.edit`, to change an account's roles. |
| `roles.view` | Reading roles, a role's permissions and its users, and the permission catalogue with its categories and resource types. |
| `roles.create` | Creating a role. |
| `roles.edit` | Renaming a role and replacing the set of permissions it grants. |
| `roles.delete` | Deleting a role. |
| `roles.assign` | Giving a role to an account and taking it away, one account or several at a time. |
| `tenancy.manage` | Workspaces: the list, creating, seeding, suspending, reactivating, trashing, restoring and purging one. The enablement flow and its diagnostics. Host resolution, the public origin and the workspace signup settings. **Settings › Payments**. Binding an API key to a workspace. |
| `tenancy.access_any` | Entering a workspace you are not a member of, as a platform operator. Every escalation granted is written to the audit log. |
| `audit.view` | **Users & Access › Audit Log**: the list and one entry. |
| `email.templates.manage` | **Settings › Email**: the templates, resetting one, the transport settings, and a test send. |
| `i18n.view` | Reading the configured languages, the translations and the missing ones. |
| `i18n.manage` | Adding and changing a language, and adding and changing a translation. |
| `i18n.import` | Importing a translation catalogue. |
| `i18n.export` | Exporting a translation catalogue. |
| `import_export.view` | The adapters, the job list, one job, its errors and its report. |
| `import_export.run_import` | Starting an import job. |
| `import_export.run_export` | Starting an export job. |
| `import_export.cancel` | Cancelling a job. |
| `import_export.retry` | Retrying a job's failed batches. |
| `import_export.export_failed_records` | Exporting a job's failed records. |
| `import_export.manage_all` | Reading and acting on jobs someone else started, rather than your own. |
| `meilisearch.search` | Querying an index through the search API. |

A permission only exists on an install where the pack or extension that declares it is installed.
`php glueful permissions:list` prints the catalogue as it stands on yours, grouped by category and
naming the package each one comes from.

```bash
$ php glueful permissions:list
```

## Permissions that imply others

One permission in the catalogue implies another: `commerce.manage` satisfies `commerce.view`. A
route that asks for `commerce.view` therefore passes for an account that holds only
`commerce.manage`. Nothing else implies anything, and the implication is expanded before the check,
so it holds for workspace roles and API-key scopes as well.

The other relations that look like implication are not. `content.manage` does not carry
`content.view`: a role that manages content types but holds no `content.view` cannot list them.

## The roles a new install has

Five install-wide roles are seeded, each with a level. The level decides who may hand a role out.

| Role | Slug | Level | Holds |
|---|---|---|---|
| Superuser | `superuser` | 100 | Every permission on the install. |
| Workspace Manager | `workspace_manager` | 90 | `tenancy.manage` and `tenancy.access_any`, and nothing else. |
| Administrator | `administrator` | 80 | Every permission except `system.config`, `tenancy.manage` and `tenancy.access_any`. |
| Editor | `editor` | 50 | `content.view`, `content.create`, `content.edit`, `content.publish`. |
| User | `user` | 10 | `content.view`. |

`php glueful thallo:provision` — and the setup that creates the first admin — persists the declared
catalogue and grants Superuser and Administrator each permission once. The first admin's setup
grants them everything; a later provision, which [upgrading](../upgrading.md) runs, grants only the
permissions that are new since, such as those a newly installed pack brings. A record of what each
role has been offered is kept, so a permission you revoke from either role by hand stays revoked.
The three withheld from Administrator are withheld in the code.

Workspace Manager, Editor and User are granted by migration and are not touched again, so a change
you make to one of them stands.

## Roles inside a workspace

On an install with [workspaces](../concepts/08-workspaces.md) on, membership of a workspace carries
a role in that workspace, and that role is checked against the same catalogue — Thallo's
twenty-three, never the framework's. Four roles are reserved: `owner`, `admin`, `member` and
`viewer`. What each holds by default is `role_matrix` in `config/tenancy.php`:

| Role | Holds |
|---|---|
| `owner` | Every permission in the catalogue. |
| `admin` | The same, without `tenant.members.manage`, `tenant.domains.manage`, `tenant.roles.manage` and `billing.manage`. |
| `member` | `content.view`, `content.create`, `content.edit`. |
| `viewer` | `content.view`. |

A workspace can override that baseline per role, granting or revoking one capability at a time, and
can define custom roles of its own. Four rules bound it:

- A custom role has no baseline, so it accepts grants only; a revoke on one is refused.
- `owner` always keeps `tenant.roles.manage` and `tenant.members.manage`. Revoking either is
  refused, and both are added back even if the stored override says otherwise.
- `owner` can never be disabled. Any of the other three can be, and a disabled role evaluates to no
  capabilities at all; its overrides are kept and apply again when it is re-enabled.
- A capability that is not in the catalogue cannot be granted or revoked.

A single-site install uses the same four roles and the same editor, reached from **Settings ›
Signup** with **Manage roles**, and gated by `users.roles.manage` rather than
`tenant.roles.manage`.

Membership decides access first: an account with no membership is not in the workspace whatever it
holds install-wide. The exception is `tenancy.access_any`, which lets a platform operator act in a
workspace it does not belong to.

## The workspace policy manifest

`thallo:policy:manifest` writes the workspace half of all this out as JSON: the catalogue with each
entry's label, group and implications, the reserved roles, the owner floor, the role matrix, and a
SHA-256 hash of the lot.

```bash
$ php glueful thallo:policy:manifest --export
```

`--validate <file>` checks a saved manifest against that hash and reports what is missing.
`--compare <old.json> --compare <new.json>` prints, for every role, the capabilities added and
removed between two of them. All three read only.
