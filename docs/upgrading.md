# Upgrading Thallo

## The sequence (every upgrade)

```bash
composer update \
  && php glueful migrate:run \
  && php glueful migrate:verify
# clear compiled state — REQUIRED, not optional:
php glueful extensions:cache   # or your cache-clear entry point
rm -rf storage/cache/container_*.php storage/cache/routes_*.php
```

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
