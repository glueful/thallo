# DB-Backed Homepage Setting — Design (compact)

**Date:** 2026-07-04
**Status:** Approved direction (user-specified); pins below.

## 0. Contract

- The homepage is a SITE SETTING storing an **entry uuid** (never a
  route/path): `homepage_entry` joins `GeneralSettings::DEFS` with the
  existing precedence — `lemma_settings` DB row → config
  `lemma_render.homepage_entry` (env `RENDER_HOMEPAGE_ENTRY`) → `''`.
  Clearing the DB setting falls back to env; both empty → the standalone
  index page.
- **Write-time validation, not runtime surprise:** `PUT /settings/general`
  rejects a `homepage_entry` that is not an existing entry of a
  `public_delivery` content type with a published version (422). The
  existing LOUD 500 stays for an invalid ENV fallback (deploy config
  error); a DB-set entry that later becomes unresolvable (unpublished/
  deleted) logs a warning and falls back env → standalone — a valid-at-
  write setting must never 500 the site.
- **A real clear path (review P1):** `GeneralSettings::save()` ignores
  nulls and `SettingsStore` has no delete — clearing must REMOVE the DB
  row, not write an empty string (an empty DB row would shadow env with
  '' and break the fallback). `SettingsStore` gains `forget(string $key)`;
  the PUT contract treats an EXPLICIT empty string for `homepage_entry`
  as "clear to fallback" (null keeps its existing "unchanged" meaning).
  Load-bearing test: set via DB → clear → the RENDERED homepage falls
  back to the env entry (and to standalone when env is empty too).
- **Boundary seam — SOURCE-AWARE provider (review P1):** the render pack
  cannot depend on app classes. A new contract
  `Glueful\Lemma\Contracts\Delivery\HomepageEntryProvider`
  (`homepageEntry(): string`) is bound app-side; `RenderController::home()`
  consumes it optionally (null provider → today's config read). The
  provider itself owns the fallback semantics, because a collapsed
  DB→env value would hide which source a broken uuid came from: the app
  implementation returns the DB override ONLY when it is currently
  resolvable (entry exists, type publicly delivered, published) —
  otherwise it logs a warning and returns the ENV value. The pack keeps
  its existing posture unchanged (whatever the provider returns behaves
  like config: unresolvable → loud 500) — which by construction can now
  only fire for an env-sourced value, exactly the deploy-config-error
  case the 500 is for. Per-request validation also means "valid at write,
  broken later" self-heals every request without a restart.
- Locale stays entry-level (one homepage entry, localized fields) — no
  per-locale homepages.

## 1. Admin surfaces (both, over the one setting)

- **Settings → General:** a "Homepage" field — entry picker (search
  published public entries; store uuid; clearable). The canonical
  audit/change/clear surface.
- **Entry flow:** a "Set as homepage" action in the entry editor's publish
  panel — the natural finish to design → preview → publish → set home.
  Disabled with a hint when the entry isn't published or its type isn't
  publicly delivered.
- **Badge:** a "Home" badge wherever the selected entry appears — the
  content list row and the entry editor header. The current value comes
  from the existing GET `/settings/general` payload.

## 2. Tests

- PHP: precedence (DB beats env beats empty); write-time 422 for missing/
  non-public/unpublished entries; runtime fallback (DB value unpublished
  after write → warning + env/standalone, no 500); env-invalid keeps the
  500; `home()` renders the DB-set entry through the provider contract.
- Admin: Settings picker saves/clears; "Set as homepage" calls the
  settings mutation and flips the badge; badge shows on the matching list
  row.
