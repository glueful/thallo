---
title: "Turn on workspaces"
slug: multi-site
section: operations
order: 7
summary: "Enable multi-tenancy in stages, give each workspace its domain, and turn it off again."
---

This is the runbook for turning one Thallo site into many: the enablement flow stage by stage,
host routing, the commands that create workspaces, domains and members, and the way back. Read it
to the end before you run the first command: the middle of the flow rewrites the schema and blocks
every write to the site until it finishes.

[Workspaces](../concepts/08-workspaces.md) explains what a workspace owns and what the install
keeps in common. This page assumes you have read it.

## What you need before you start

- **PostgreSQL.** The retrofit refuses any other driver before it touches anything. See
  [known limitations](../limitations.md).
- **A cache driver that can purge by pattern.** Thallo probes the live driver on the first stage:
  a driver that cannot delete by pattern fails the step with `Tenancy requires a cache driver that
  supports pattern purge.` `CACHE_DRIVER=redis` passes; the Memcached driver does not implement
  pattern deletion at all.
- **No data collections.** Enabling is refused while any collection definition exists.
- **The UUID of the account that will own the first workspace.** The admin shows it as **User ID**
  at the top of the person's page under **Users & Access › Users**.
- **A window with no writes, and a fresh backup.** The retrofit raises a write barrier and keeps it
  up until the flow reaches `on`.

Every step below has an equivalent in the admin, on **Settings › Workspaces**: the
**Multi-workspace mode** card runs the enablement flow, the **Domain routing** card runs host
activation, and both show the same steps this page names.

## Read the current state

Three read-only commands.

```bash
$ php glueful thallo:tenancy:status
$ php glueful thallo:tenancy:resolution:status
$ php glueful thallo:tenancy:diagnose
```

`thallo:tenancy:status` prints the enablement machine as JSON: `step`, `enabled`, `schema_state`,
`progress`, `reloading`, `mode`, `pending_slug`, `pending_name` and `failure`. On an install that
has never been through the flow, `step` is `off`, `enabled` is `false` and `schema_state` is
`none`. The other steps are `migrating_extension`, `awaiting_confirm`, `retrofitting`,
`enabling_enforcement`, `reloading`, `finalizing`, `on`, `disabling`, `disabled_widened` and
`failed`.

`thallo:tenancy:diagnose` prints one line per check — `schema`, `state`, `enforcement`,
`provenance`, `collections`, `domain_reverification`, `static_write_audit`, `role_policy` and
`public_signup` — each with its status and its detail, and exits non-zero if any of them fails.

## Turn multi-workspace mode on

`thallo:tenancy:enable` advances the machine one stage per run and stops. Run it again to take the
next stage.

1. Start the flow. The step becomes `migrating_extension`; nothing is enforced yet.

   ```bash
   $ php glueful thallo:tenancy:enable
   ```

2. Run it again. It applies the tenancy package's migrations and stops at `awaiting_confirm`,
   telling you to re-run with `--slug`, `--name` and `--owner`. Passing those options earlier does
   nothing: they are read only on the run that confirms.

3. Confirm the first workspace. This is the stage that changes the database.

   ```bash
   $ php glueful thallo:tenancy:enable --slug=my-site --name="My Site" --owner=<user-uuid>
   ```

   In order, it raises the write barrier; creates the first workspace with that slug and name and
   gives the owner the `owner` role in it; proves that no business key would collide once every
   existing row belongs to that workspace, and stops before any schema change if one would; moves
   install-wide system keys out of the `settings` table; adds a workspace column to every table
   that holds content, the content model or settings and widens their unique constraints; and
   records the schema as `widened`. It ends at `reloading`, with the barrier still up.

4. Restart the app: PHP-FPM, and every `queue:work` worker.

5. Finish. It verifies enforcement, then lowers the barrier and sets the step to `on` in one
   transaction.

   ```bash
   $ php glueful thallo:tenancy:enable
   ```

You have finished when `thallo:tenancy:status` reports `step` `on`, `enabled` `true` and
`schema_state` `widened`, and `thallo:tenancy:diagnose` exits 0. The admin's sidebar now has a
**Workspaces** group.

If a stage fails, the step becomes `failed` and `failure` carries the reason. Running the command
again does nothing in that state: resume it from **Settings › Workspaces** with **Retry**, which
picks up at the stage that failed.

## Turn on domain routing

Until host routing is active the install serves exactly one workspace, and creating a second one
is refused. Activating it is a separate flow.

1. Set the hosts. In `.env`:

   ```ini
   TENANCY_BASE_DOMAIN=sites.example.com
   TENANCY_DEFAULT_HOSTS=sites.example.com,www.sites.example.com
   TENANCY_PUBLIC_SCHEME=https
   ```

   The same two values can be set in the **Public origin** card on **Settings › Workspaces**, as
   **Base domain** and **Default hosts**; saved there they override `.env`. A process that started
   before the change refuses to activate until the app is restarted.

2. Run the activation, once per stage:

   ```bash
   $ php glueful thallo:tenancy:resolution:activate
   ```

   The stages are `mapping_hosts` (each default host is attached to the first workspace, already
   verified — these hosts need no DNS proof), `verifying_wiring` (each one is probed and must
   resolve to that workspace), `rebuilding_routes` (the compiled route table is cleared) and then
   `awaiting_fresh_boot`, where the command tells you to re-run in a fresh process.

3. Restart the app, then run the command once more. It probes the hosts again and the step becomes
   `full`.

Check it with `thallo:tenancy:resolution:status`: `step` is `full` and `mode` is
`full_resolution`. A failed activation is retried or reset from the **Domain routing** card with
**Retry** or **Reset**; the command does nothing from `failed`.

## Create workspaces, domains and members

Each of these commands prints its result as JSON.

```bash
$ php glueful thallo:tenancy:tenant create --slug=acme --name="Acme" --owner=<user-uuid>
$ php glueful thallo:tenancy:tenant list --status=active
$ php glueful thallo:tenancy:tenant suspend --uuid=<workspace-uuid>
$ php glueful thallo:tenancy:tenant reactivate --uuid=<workspace-uuid>
```

`create` refuses unless domain routing is `full`. It registers the workspace, then seeds its
starter content types, block types and regions and activates it. `--status` accepts
`provisioning`, `active`, `suspended`, `deleted` and `purging`. With a base domain set, a slug that
would produce a reserved host — `www`, `api` or `admin` under that base domain — is refused; every
other workspace answers at `<slug>.<base domain>`.

A custom host is a proof of ownership. Add it, publish the TXT record, then verify:

```bash
$ php glueful thallo:tenancy:domain add --tenant=<workspace-uuid> --host=www.example.com
$ php glueful thallo:tenancy:domain verify --domain=<domain-uuid>
```

`add` answers with the domain's `uuid` and a `token`. Publish that token as the value of a TXT
record named `_thallo-verify.www.example.com`. `verify` answers `verified` when it finds the
record and `pending` when it does not, so you can run it again. The other actions are `list`
(`--tenant`) and `enable`, `disable` and `remove` (each `--domain`).

Membership is per workspace:

```bash
$ php glueful thallo:tenancy:member add --tenant=<workspace-uuid> --user=<user-uuid> --role=admin
$ php glueful thallo:tenancy:member set-role --tenant=<workspace-uuid> --user=<user-uuid> --role=viewer
$ php glueful thallo:tenancy:member remove --tenant=<workspace-uuid> --user=<user-uuid>
$ php glueful thallo:tenancy:member list --tenant=<workspace-uuid>
```

`--role` takes `owner`, `admin`, `member`, `viewer` or the slug of a custom role that workspace has
defined.

After an upgrade adds or changes a starter definition, bring every workspace up to date:

```bash
$ php glueful thallo:tenant:sync --all
```

It adds what is missing and leaves anything you have customised alone. `--kind=block_type`,
`--kind=content_type` or `--kind=region` narrows it; a workspace uuid in place of `--all` does one
workspace. `thallo:tenant:blocks:sync` is the block-type-only form of the same thing.
`thallo:tenant:seed <workspace-uuid>` repairs a workspace whose starter surface is incomplete.

## Keep it healthy

- `php glueful thallo:tenancy:diagnose` after every change to the flow. Its exit code is the check.
- `php glueful thallo:tenancy:purge:recover` redispatches workspace purges that were requested,
  failed or lost their lease. Run it after a queue outage, and whenever the admin says a purge is
  waiting for a worker.
- `php glueful thallo:tenancy:hosts:sweep` queues the expired host-cooldown sweep. Nothing
  schedules it for you; add a daily cron line:

  ```text
  0 5 * * * php /path/to/site/glueful thallo:tenancy:hosts:sweep
  ```

- Both of those jobs run on the `tenancy-purge` and `tenancy-maintenance` queues, so a worker has
  to be consuming them. See [the scheduler and the queue](03-scheduler-and-queues.md).

## Turn it off again

Disabling stops workspace scoping. It leaves the widened schema and every row in place, so nothing
is lost and turning it back on is quick. Three things must be true first: exactly one workspace
exists, domain routing is not active, and no starter definition is out of step.

1. Turn domain routing off. This also requires exactly one workspace.

   ```bash
   $ php glueful thallo:tenancy:resolution:deactivate
   ```

2. Disable. If it refuses because starter definitions are unsynchronised, run
   `php glueful thallo:tenant:sync --all` and try again.

   ```bash
   $ php glueful thallo:tenancy:disable
   ```

   It removes the enforcement provider, sets `enabled` to `false` and the step to
   `disabled_widened`, and tells you to re-run in a fresh process. The barrier stays up.

3. Restart the app, then run `php glueful thallo:tenancy:disable` once more. It verifies that the
   install serves correctly unscoped and lowers the barrier.

You are done when `thallo:tenancy:status` reports `step` `disabled_widened`, `enabled` `false` and
`reloading` `false`. To turn workspaces back on, run `thallo:tenancy:enable`, restart, and run it
again: there is no second retrofit.

## Commands that are not part of this flow

- **`php glueful extensions:enable` and `extensions:disable` on the tenancy enforcement
  provider.** `config/extensions.php` lists it under `protected`, and every enable and disable
  surface refuses it: workspace enforcement is owned by the enablement flow. Never add or remove
  that provider line by hand.
- **`tenant:create`, `tenant:activate` and `tenant:suspend`.** These are the framework's own
  commands. `tenant:create` inserts a row in the tenants table and stops — no owner membership, no
  starter content model, and none of the guards above. Use `thallo:tenancy:tenant`.
- **`thallo:tenancy:single-store:repair`.** This is the single-site repair, not part of enabling
  workspaces. It establishes the default workspace identity an install needs before workspaces are
  on, and it is the fix when a single-site install reports that no single-store workspace is
  established. It takes `--owner`, and optionally `--slug` (default `default`) and `--name`
  (default `Default`).
