---
title: "The scheduler and the queue"
slug: scheduler-and-queues
section: operations
order: 3
summary: "The one cron line every site needs, what runs on it, and three ways to run background jobs."
---

A Thallo site has two kinds of work that happen outside a request: recurring jobs, driven by one
cron line, and queued jobs, dispatched by the application and picked up by a worker. This page
sets both up, and shows how to tell they are running.

## Add the scheduler cron line

One entry runs everything in `config/schedule.php`:

```text
* * * * * php /path/to/site/glueful queue:scheduler run >> /path/to/site/storage/logs/scheduler.log 2>&1
```

The tick evaluates every job's cron expression and runs the due ones **inside the tick**. Nothing
in `config/schedule.php` needs a queue worker. Without this entry, none of it runs: scheduled
publishing never fires, the sweeps never sweep, and nothing says so.

## What the scheduler runs

Every job below is declared in `config/schedule.php`. Each row's switch is an `.env` variable;
set it to `false` and the job is never registered.

| Job | Schedule | What it does | Switch |
|---|---|---|---|
| `schedules_run` | `* * * * *` | Fires due scheduled publish and unpublish actions | always registered |
| `notification_retry_processor` | `*/10 * * * *` | Processes queued notification retries | `NOTIFICATION_RETRIES_ENABLED` |
| `domain_reverification_sweep` | `0 * * * *` | Re-verifies due custom-domain ownership proofs | `TENANCY_REVERIFICATION_ENABLED` |
| `session_cleaner` | `0 0 * * *` | Cleans up expired user sessions | `SESSION_CLEANER_ENABLED` |
| `log_cleanup` | `0 1 * * *` | Deletes log files older than `LOG_RETENTION_DAYS` (30) | `LOG_CLEANUP_ENABLED` |
| `database_backup` | `0 2 * * *` | Takes a full database backup, keeping `BACKUP_RETENTION_DAYS` (7) | `DB_BACKUP_ENABLED`, on by default only when `APP_ENV=production` |
| `signup_intent_sweep` | `15 2 * * *` | Removes expired and sanitised public-signup intents | `SIGNUP_SWEEP_ENABLED` |
| `cache_maintenance` | `0 3 * * *` | Runs cache maintenance | `CACHE_MAINTENANCE_ENABLED` |
| `update_check` | `0 4 * * *` | Asks Packagist whether a newer `glueful/thallo-core` is published | `UPDATE_CHECK_ENABLED` |

`DB_BACKUP_SCHEDULE` changes the backup's cron expression; the other schedules are fixed in the
file. See [back up and restore](04-backups.md) for what the backup job writes.

One switch is not in `.env`. **Settings › General › Publish scheduler** turns scheduled publish
and unpublish off. The tick still runs — it just fires no actions.

## Confirm the scheduler is ticking

`schedules_run` records the time it ran, every minute. Open **Utilities › Health** and look for the
**scheduler** check:

- **ok** — the last tick is less than five minutes old, and the message gives its time.
- **warning** — the last tick is older than five minutes, or there has never been one. The
  recommendation under the message is the cron line to add.

**Utilities › Scheduled Tasks** lists the same jobs with their cron expression, their next run,
their queue, and whether they are enabled. **Run now** does not run the job in the request: it
puts the job on the queue named in its row, so it runs when a worker takes that queue.

From a shell, `php glueful queue:scheduler list` prints the enabled jobs and their schedules. A
job switched off in `.env` is absent from that list.

## Filling in a control panel's cron form

A hosting control panel asks for the five schedule fields and the command separately. For the
scheduler:

| Field | Value |
|---|---|
| Minute (0-59) | `*` |
| Hour (0-23) | `*` |
| Day of month (1-31) | `*` |
| Month (1-12) | `*` |
| Day of week (0-6, Sunday = 0) | `*` |
| Command | `php /path/to/site/glueful queue:scheduler run` |

Use the absolute path to the site. If the panel's PHP is not the one you install with, give the
absolute path to the PHP binary too.

## What goes on the queue

The queue is separate from the scheduler. The application dispatches a job, the job waits in the
`queue_jobs` table, and a worker runs it. The connection is `QUEUE_CONNECTION` in `.env`;
`database` is the default and its tables are created by `thallo:provision`. `redis` is the other
connection the code ships, and is worth it only under real load.

A worker runs only the queues you name, so a job on a queue no worker takes waits indefinitely.
These are the queues Thallo dispatches to:

| Queue | What arrives on it |
|---|---|
| `default` | Style class detach-everywhere and remove-everywhere jobs, content type and block type backfills, filter-index jobs |
| `import-export` | Imports and exports started from **Settings › Import / Export** |
| `webhooks` | Content webhook deliveries (`WEBHOOKS_QUEUE` renames it) |
| `maintenance`, `critical`, `notifications` | Only what **Utilities › Scheduled Tasks**'s **Run now** puts there, and only for a job whose row names that queue |
| `tenancy-purge`, `tenancy-maintenance` | Workspace purges and host-cooldown sweeps, once workspaces are on |

An extension you enable may add its own; its own documentation names it.

## Run the queue with a supervised worker

`php glueful queue:work` processes jobs until it hits one of its limits, then exits. That is
deliberate: a long-lived PHP process should be restarted, so keep it under a supervisor. A systemd
unit:

```ini
[Unit]
Description=Thallo queue worker
After=network.target postgresql.service

[Service]
User=deploy
WorkingDirectory=/path/to/site
ExecStart=/usr/bin/php glueful queue:work --queue=default,import-export --sleep=3 --tries=3 --max-runtime=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--queue` is a comma-separated list, taken in the order you give it. `--sleep` is the seconds to
wait when every named queue is empty, `--tries` the attempts a job gets before it is recorded as
failed, and `--max-runtime` the seconds after which the worker exits and systemd starts a fresh
one. `--memory` and `--max-jobs` are the other two limits.

## Run the queue from cron

If you cannot run a supervisor, a cron line can drain the queue instead:

```text
*/5 * * * * php /path/to/site/glueful queue:work --queue=default,import-export --stop-when-empty --max-runtime=240 >> /path/to/site/storage/logs/queue.log 2>&1
```

`--stop-when-empty` ends the run as soon as one pass over every named queue takes no job.
`--max-runtime` is checked between passes, so the run ends at the first check after that many
seconds — it never interrupts a job already running.

Two things follow from that, and they decide the numbers you choose:

- The worker takes no lock, and two runs that overlap are not prevented from taking the same job.
  Keep `--max-runtime` comfortably below the cron interval, and run one such line, not several.
- On the `database` connection, a job still reserved after `retry_after` seconds
  (`config/queue.php`, 90 by default) is released back to its queue and can be taken again. If
  your jobs run longer than that, raise `retry_after`.

Queued work waits up to the cron interval before it starts, so an import or a style class change
sits "queued" for that long. A supervised worker starts it within `--sleep` seconds.

## Run one job by hand

A style class's detach-everywhere or remove-everywhere job locks the class until it completes.
With no worker running, finish it from the shell:

```bash
$ php glueful thallo:style-classes:run-job <job-id>
```

The command also resumes a job that stopped part-way. See
[reuse styling with style classes](../guides/05-style-classes.md).

## When a job fails

A job that throws is released back to its queue with a delay, and tried again. The delay grows
exponentially from `queue.workers.performance.backoff_base` up to `max_backoff`, both in
`config/queue.php`. Once the job has used its attempts — `--tries` on the worker, or the job's own
limit, whichever is lower — it is recorded in the `queue_failed_jobs` table with its exception, and
the worker moves on.

Failures are logged too. The worker writes a `Queue job failed` entry with the queue, the job's
uuid, its attempt count and the error message.

## Check it worked

- **Utilities › Health** shows the **scheduler** check as ok.
- Publish an entry with a schedule and confirm it goes live within a minute or two; see
  [drafts, preview and publishing](../concepts/05-publishing.md).
- Start an import from **Settings › Import / Export** and confirm it leaves the queued state; see
  [import content from CSV, WordPress or Markdown](../guides/12-import-content.md).

If the scheduler check stays a warning, or an import never leaves "queued", work through
[health checks and troubleshooting](05-troubleshooting.md).
