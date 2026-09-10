# Upgrading Thallo

## The sequence (every upgrade)

```bash
composer update \
  && php glueful thallo:provision \
  && php glueful migrate:verify
```

`thallo:provision` on an installed instance runs the pending migrations, grants the install
roles any permission a new pack declared, seeds any starter block type the instance lacks
(existing rows are never touched), rebuilds the extension cache, and warns (`asset-routing`)
when the web server serves `/theme-assets/*` or `/_thallo/*` from disk. Then reload PHP-FPM
so OPcache drops the previous release's classes:

```bash
sudo systemctl reload php8.4-fpm   # or the PHP-FPM restart for the site in your panel
```

Switching a pack's capability on (Settings › Capabilities, e.g. Commerce or Accounts) needs no
command: the first request afterwards seeds that pack's starter block types, skipping any slug
that already exists. Switching it off keeps the rows but drops them from Settings › Block types
and the block picker until it is on again. With workspaces on, seed each workspace with
`php glueful thallo:blocks:seed --all` instead.

**The `&&` chaining is part of the contract**: `migrate:run` applies what is
genuinely new, and `migrate:verify` confirms every declared migration source is
Ready afterwards — a non-zero exit anywhere stops the sequence.

**Pre-beta.3 installs are not upgradable in place.** Developer Preview builds up to
`1.0.0-beta.2` recorded pack migration receipts under pre-manifest ledger names
(`thallo-*`, render's bare `migrations`); beta.3's ledger is canonical from
provision and ships no migration path for those receipts. Re-provision, or rewrite
the ledger `source` values by hand before upgrading.

Then read the release's section in [CHANGELOG.md](../CHANGELOG.md) for anything marked
**Upgrade Notes**.

**Why the cache clear is required:** a compiled container from a previous release can
construct services with outdated constructor signatures. Thallo's security-relevant services
are built to fail **loud** in that state rather than silently downgrade — so a skipped cache
clear shows up as a clear error, not a quiet vulnerability. Clear the cache and it heals.

## Versioning expectations

- Semantic versioning. Developer Preview releases are `1.0.0-beta.N`.
- **Tags are immutable.** A correction ships as the next `beta.N+1` (later: patch releases);
  a tag you have already installed will never change underneath you.
- Behavioral defaults never change in a patch. Minor releases may add features, env keys, and
  operational obligations — each is listed in the release's Upgrade Notes and reflected in
  [production.md](production.md).

## Extensions

Extension engines (Commerce, Payvia, …) version independently and are pinned by this app's
`composer.json`. `composer update` moves them within the pinned constraints; their own
changelogs ship in `vendor/glueful/<name>/CHANGELOG.md`.
