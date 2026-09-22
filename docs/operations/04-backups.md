---
title: "Back up and restore"
slug: backups
section: operations
order: 4
summary: "What to back up, the backup job that ships, and how to restore a site."
---

A Thallo site lives in three places: a PostgreSQL database, the files under `storage/`, and
`.env`. Lose one and the other two cannot rebuild it. This page says what to copy, what the
scheduled backup job in `config/schedule.php` does and does not do, how to put a site back, and
how to carry content alone to another install.

## The three things a site is made of

| What | Where | Holds |
|---|---|---|
| The database | The PostgreSQL database named by `DB_PGSQL_DATABASE` in `.env` | Content types, entries and their drafts, versions, publications and routes; block types; style classes; the header and footer; navigation menus; site settings; redirects; form submissions; users and roles; the media library's records |
| Uploaded files | `storage/uploads` | Every file in the media library. The records are in the database; the bytes are here |
| Keys and configuration | `.env` | `APP_KEY`, `TOKEN_SALT`, `JWT_KEY`, `SETUP_TOKEN`, the database connection, and everything else the install is configured with |

Your own code — `app/`, `routes/`, `config/`, `themes/{name}/` and `database/migrations/` —
belongs in version control. An upgrade never rewrites it; see
[upgrading](../upgrading.md).

PostgreSQL is the tested database, so that is what this page uses. See
[known limitations](../limitations.md).

What is not in the list rebuilds itself: `storage/cache`, the rendered pages, and the search
index, which `php glueful thallo:resync` re-drives.

## Take a backup

Dump the database first, then the files. A file uploaded between the two lands in the file copy
with no record in the dump, which is a harmless orphan; the other order leaves a record pointing
at a file that is not there.

```bash
$ export PGPASSWORD=DB_PGSQL_PASSWORD
$ pg_dump --host=localhost --port=5432 --username=my_site --format=plain --file=/path/to/backups/my_site-2026-09-22.sql my_site
```

`DB_PGSQL_PASSWORD` stands for the password in `.env`; take the host, port, user name and
database name from `DB_PGSQL_HOST`, `DB_PGSQL_PORT`, `DB_PGSQL_USERNAME` and
`DB_PGSQL_DATABASE` there too. Do not write the password into a script other people can read.

Then the uploaded files and `.env`:

```bash
$ tar -czf /path/to/backups/my_site-uploads-2026-09-22.tar.gz -C /path/to/my-site storage/uploads
$ cp /path/to/my-site/.env /path/to/backups/my_site-env-2026-09-22
```

Keep the three together and treat them as one secret: the `.env` copy carries every key the site
has, and the dump carries every account and every draft.

If the media library is on the `s3` disk instead (`UPLOADS_DISK` in `.env`, the disks in
`config/storage.php`), the files are in the bucket and the bucket's own versioning or replication
is what protects them.

## The backup job that ships

`config/schedule.php` declares a `database_backup` job, run by the scheduler tick like everything
else in that file — not by a queue worker. See
[the scheduler and the queue](03-scheduler-and-queues.md).

| Setting | Key in `.env` | Default |
|---|---|---|
| Whether it runs | `DB_BACKUP_ENABLED` | off |
| When it runs | `DB_BACKUP_SCHEDULE` | `0 2 * * *` |
| How long it keeps | `BACKUP_RETENTION_DAYS` | 7 |

None of those three keys is in the shipped `.env.example`; add the one you want to change.

The job writes into `storage/backups` (the `backups` entry of `paths` in `config/app.php`), names
the file `backup_` and the time it started, deletes files matching `backup_*.sql` in
`storage/backups` older than the retention, and appends a summary to
`storage/logs/database-backup.log`.

It runs `pg_dump` with the site's own `DB_PGSQL_*` settings: the password travels in the
`PGPASSWORD` environment variable, never on the command line, and `DB_PGSQL_SSL_MODE` becomes
`PGSSLMODE`. `pg_dump` must be on the scheduler host's `PATH`, and no older than the server's
major version. A night that makes no dump fails the job: the summary reads
`Database backup failed` with the reason, and the queue log records a critical failure.

The job is off by default. Turn it on with `DB_BACKUP_ENABLED=true`, run
`php glueful queue:scheduler run` once, and check `storage/backups` holds a new file. The dump stays on the same machine as the
database, so it protects you from a bad migration or a mistaken delete, not from losing the host:
copy `storage/backups` somewhere else as well.

## Restore a site

1. **Put the code back.** If the install is still on the machine, use it. Otherwise install
   Thallo at the release the backup came from — a database written by a newer release does not
   run on older code — and restore your own files from version control. See
   [upgrading](../upgrading.md).

2. **Restore `.env`** exactly as it was. The keys in it have to be the keys that wrote the
   database; the next section says what changes if they are not.

3. **Load the dump into an empty database.**

   ```bash
   $ createdb my_site
   $ psql --host=localhost --port=5432 --username=my_site --dbname=my_site --file=/path/to/backups/my_site-2026-09-22.sql
   ```

4. **Restore the uploaded files.**

   ```bash
   $ tar -xzf /path/to/backups/my_site-uploads-2026-09-22.tar.gz -C /path/to/my-site
   ```

5. **Finish the install.**

   ```bash
   $ php glueful thallo:provision
   ```

   Provision shows the connection it found in `.env` and asks you to confirm it, then recognises
   the database as already migrated, rebuilds the extension cache, publishes the admin bundle
   into `public/admin`, and clears the route and rendered-page caches. It keeps the keys `.env`
   already holds. **Never pass `--force` on a restore:** that regenerates `APP_KEY`, `TOKEN_SALT`
   and `JWT_KEY`, which is the one mistake this page exists to prevent.

6. **Re-drive the read side** if pages are missing or stale afterwards:

   ```bash
   $ php glueful thallo:resync
   ```

   It walks every published entry, rebuilds the published-reference projection, drops the cache
   tags and re-indexes search. Webhooks are not re-fired unless you add `--webhooks`.

## The keys that have to come back with the database

`APP_KEY` is the encryption key (`config/encryption.php` reads it) and the signing key for
preview tokens and for persisted queue and scheduler payloads. Restore a database beside a
different `APP_KEY` and:

- Encrypted rows stop decrypting. Payment gateway credentials held in settings — the `secret_key`
  and `webhook_secret` values — read back as nothing rather than raising, so the admin looks
  configured and the gateway rejects every call.
- Queued jobs and scheduled-job rows written before the change fail their HMAC check and are
  refused, while `QUEUE_PAYLOAD_SIGNING` and `QUEUE_REQUIRE_SIGNED_PAYLOADS` are on.
- Signed URLs already handed out for private files stop verifying, unless
  `UPLOADS_SIGNED_SECRET` is set — it falls back to `APP_KEY` when it is not.
- Preview links stop working. New ones are minted per preview, so that rights itself; with no
  `APP_KEY` at all, minting fails outright and no preview can be opened.

`JWT_KEY` signs access tokens: change it and everyone signs in again. `TOKEN_SALT` is generated
alongside the other two; keep all three. `SETUP_TOKEN` guards the first-run setup screen.

Rotating a key on purpose is a different job from restoring one; see
[security](06-security.md).

## A content-only copy

**Settings › Import / Export** exports a **Thallo Content Bundle**: one NDJSON file holding the
content types, the entries, their drafts, versions, publications and routes, the references
between them, and one manifest record for each file an entry's asset field points at. The steps
are in [import content from CSV, WordPress or Markdown](../guides/12-import-content.md).

It is a copy of content, not a backup of a site. It leaves out block types, style classes, the
header and footer, navigation menus, site settings, redirects, form submissions, users and roles,
and the uploaded files themselves — the manifest records where a file was, never its bytes. Use
it to move content to another install, and the database dump above to protect this one.

From a shell, `php glueful export:run --adapter=thallo.content` queues the same export. A worker
on the `import-export` queue runs it and writes
`storage/import-export/exports/{job uuid}/thallo-content-0001.ndjson`, one file per batch;
**Download** on the job's row in the admin streams them back as one file.

Those result files stay on disk. `php glueful import-export:cleanup` deletes a finished job's
temporary files and its rows — including the row that **Download** needs — and leaves the NDJSON
where it is. Each one holds every entry on the site, so delete them once you have them somewhere
safe.

After importing a bundle, run `php glueful thallo:resync`. The importer writes rows straight to
the tables, so nothing that normally follows a publish has run.

## Check it worked

Restore into a spare database and a spare directory before you need to, not during an outage. A
backup nobody has restored is not yet a backup.

- The dump loads with no error, and `php glueful migrate:verify` classifies every migration
  source as ready.
- `php glueful thallo:doctor` reports its `database`, `storage` and `keys` checks as ok.
- You sign in to the admin with an account from before the restore — which means `JWT_KEY` and
  the users table came back together.
- **Media** shows its thumbnails, and a page on the public site renders with its images.

If something is wrong after all of that, work through
[health checks and troubleshooting](05-troubleshooting.md).
