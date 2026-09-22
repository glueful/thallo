---
section: reference
order: 90
summary: "The current boundaries of the Developer Preview, stated plainly."
---
# Known limitations

Stated plainly so you can decide with open eyes. None of these are bugs; each is a deliberate
current boundary.

## One merchant account per installation

Payment gateway credentials are **platform-scoped**: every workspace on an installation
settles through the installation's single gateway account (Stripe and/or Paystack), including
workspace SaaS-subscription billing. There are no per-workspace gateway credentials. If your
model needs each workspace to settle to its own merchant account, Thallo does not do that
today.

## Paystack constraints

- **No session renewal.** Paystack provides no provider-confirmed way to prove an old
  checkout session dead, so Thallo never silently replaces one (two live payment URLs would
  risk double charges). A genuinely dead Paystack session surfaces a typed
  "renewal unavailable" state; recovery is the documented operator path (mark paid, or
  cancel with the risk acknowledgement and recreate).
- **Repricing is a hard stop.** If an order's total changes after a Paystack checkout session
  exists, initiation refuses rather than minting a second live URL. Stripe orders re-mint at
  the new total automatically.
- Your Paystack integration's `payment_session_timeout` must remain `0` (see
  [production](production.md)).

## Placed orders are not editable

Draft (walk-in) orders are fully editable until finalized; finalization locks them. A placed
order's totals are load-bearing (payment sessions, invoices, and stock claims derive from
them), so post-finalize remedies are: cancel (with the payment-session risk acknowledgement
where one was exposed) and recreate, mark paid, or refund — never in-place edits.

## No payment-link open/click analytics

The public payment-link landing page ships zero third-party assets and no tracking by design
(it carries a bearer credential in its URL). Platform analytics cover the rest of the site;
the link page itself is deliberately blind.

## PostgreSQL only (for now)

The tested and supported database is PostgreSQL. SQLite and MySQL are configurable at the
framework level but are not tested lanes — the fresh-install rehearsal surfaced at least one
migration that fails on SQLite — so treat them as unsupported in the Developer Preview.

## Delivery channels

Payment links are delivered by email or copy-to-clipboard. SMS/WhatsApp channels are not
built yet.

## Installing

- **Thallo does not create the database.** `thallo:provision` connects to a PostgreSQL database
  that already exists; create it (and its user) first. See [install](getting-started/02-install.md).
- **Turning workspaces on takes restarts.** Enablement runs in steps, and some steps continue only
  in a fresh process, so the app is restarted between them; how many times depends on how it is
  deployed. With more than one workspace, workspaces cannot be turned off. See
  [run several sites](operations/07-multi-site.md).
- **A pack's config is changed by adding the file.** `config/render.php`, `config/search.php`,
  `config/seo.php` and the other pack configs do not ship in a new site; to change one, create the
  file with just the keys you set. Nothing shows the merged, effective configuration.
- **Stored secrets are not re-keyed automatically.** `encryption:rotate` cannot re-encrypt the
  payment gateway secrets, whose encryption is bound to the settings key rather than a column.
  Keep the old key in `APP_PREVIOUS_KEYS` and re-save the secrets under **Settings › Payments**;
  see [rotating a key](operations/06-security.md#rotating-a-key).

## Content

- **Schema changes that rename or remove a field are not in the admin.** The field editor refuses
  them and names the migration route in the admin API. A field cannot be retyped at all: add a new
  field instead. See [the content model](concepts/01-content-model.md).
- **The default theme shows a custom type's `title` and `body` only.** Every other field needs a
  template of your own (`templates/entry/{type}.twig`); see
  [make your own theme](guides/13-make-a-theme.md).
- **Format imports only create.** CSV and the other format importers add new entries and never
  update existing ones; the bundle importer updates. A bundle carries the list of its media, not
  the files themselves.
- **One homepage.** The homepage is one entry for the whole site, not one per language.

## Design

- **A block type made in the admin has one style target**, its root. The starter block types can
  style their parts separately; an admin-made type cannot.
- **The section and page library is fixed.** There is no config, directory or event to add your
  own sections or pages to it, and a section built by hand cannot be saved back into it.
- **Regions are the header and the footer, one set for every language, without history.** A region
  has no draft, no versions and no undo, and its root block cannot carry a style class (its child
  blocks can).
- **Menus have no history and no preview.** A saved menu is live at once, and an earlier version
  cannot be restored.

## Site features

- **SEO fallbacks are site-wide.** There are no per-content-type SEO defaults and no robots rules
  by group in the admin.
- **Permissions cannot be limited to one language in the admin.** A grant can be narrowed to one
  resource, such as a language, but no screen sets that, so a permission granted in the admin
  applies to every language. See [permissions](reference/06-permissions.md).
- **The account dashboard's links come from code.** A pack adds them through a registry; there is
  no screen for adding or reordering them. See [accounts](guides/17-accounts.md).
