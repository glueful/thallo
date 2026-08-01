# SEO / Routing Module — Design

**Goal:** Add an in-app SEO/routing module to Lemma: **redirects** (auto-captured on slug
change + manual) with **301/302/308** status codes, chain-free resolution, and
**canonical / hreflang** metadata on delivery — so a headless frontend never 404s a renamed
URL and can emit correct SEO signals.

**Status:** ✅ Shipped (2026-06-17) — implemented and reviewed.

**Backlog item:** [POST_V1.md](../../POST_V1.md) §4. Resolves the deferral documented in
[V1_DESIGN.md](../../V1_DESIGN.md) ("Redirects").

> V1_DESIGN: "`entry_routes` carries current route rows only in V1. Redirect rows wait for
> the SEO/routing module so status codes, chains, canonical URLs, and admin UX land together
> instead of as a half-feature in core content routing." This spec is that module.

**In scope:** redirects (auto + manual), status codes, chain-free resolution, canonical +
hreflang. **Out of scope:** sitemaps, SEO meta-fields (title/description/OG), robots.txt —
separate concerns, not bundled here.

---

## Headless framing (the contract)

Lemma delivery is headless — `GET /v1/content/{type}/{slugOrUuid}` returns **JSON**, not an
HTML page (`DeliveryController::show`). So a redirect is **not** the API 301'ing a browser;
it is the API telling the consuming frontend "this path moved to that path (301)" so the
**frontend** emits the real browser redirect on the public URL and renders the right
`<link rel=canonical>` / `hreflang`. Returning a real `30x` from the API would risk
API-client auto-follow and point at the API URL layer, not the public site — so delivery
returns **200 with a redirect descriptor** instead.

## Scope decisions (settled during brainstorming)

1. **Resolve to entry-or-redirect.** Delivery resolution yields a discriminated result:
   content (200) or a redirect descriptor `{to, status, external}` (200) — never content
   served at a moved URL (no duplicate-content).
2. **Auto on slug change + manual.** A slug rename auto-captures a `301` from the old path;
   operators also create manual redirects (internal or external, chosen status).
3. **Entry-targeted, literal for external.** A redirect points at an entry (resolves to the
   entry's *current* live slug) or at a literal external URL (terminal). Renames therefore
   never build a chain; resolution is **single-hop by construction**.
4. **Self-canonical + hreflang alternates,** derived from `entry_publications` +
   `entry_routes` (no new storage); default locale = `x-default`.
5. **In-app Lemma module** (`app/Content/Seo/`) — a bounded subsystem hooking core delivery
   resolution + route assignment directly, not a separate package.
6. **Dedicated `lemma.routes.manage` permission** for manual redirect create/delete —
   redirects are routing/SEO infrastructure, not normal entry editing; a draft editor must
   not get site-wide (and external-URL) redirect power by default.
7. **URL contract: relative path from a template + structured identity.** Route rows store
   only slugs, and API URLs are explicitly *not* public-site URLs (the reason delivery
   returns a descriptor, not a `30x`). So every URL the module emits — the redirect
   descriptor `to`, and canonical/hreflang `href` — is a **relative public path** rendered
   from a per-install **route template** (`config('lemma.seo.route_template')`, default
   `/{locale}/{type}/{slug}`; a per-type override map is allowed), **plus** the structured
   `{content_type, locale, slug}` identity so a frontend with bespoke routing can ignore the
   rendered path. The module never emits API paths. An optional `config('lemma.seo.public_url_base')`,
   if set, is prefixed to produce absolute URLs; if unset, paths are relative and the
   frontend prefixes. The default-locale segment is omittable via the template
   (e.g. `x-default` uses no locale prefix).
8. **Internal redirect targets carry full identity (can cross type/locale).** A redirect's
   internal target is `{target_entry_uuid, target_content_type_uuid, target_locale}`, so it
   can point cross-type (`/blog/old → /docs/new`) and cross-locale (`en → fr`). The resolver
   returns the target's full identity + rendered path, not a bare slug. Auto-captured
   redirects always set the target type/locale = the source's (same entry), so they're a
   strict subset; cross-type/locale targets are a manual-redirect capability.

## Architecture (units in `app/Content/Seo/`)

### 1. `entry_redirects` table + `RedirectRepository`
Keyed like `entry_routes` so lookups never join `entries`:
```
entry_redirects
  id                       bigint pk autoincrement
  uuid                     char(12)        -- public id (nanoid)
  content_type_uuid        char(12)        -- SOURCE content type (lookup key)
  locale                   varchar(16)     -- SOURCE locale (lookup key)
  source_slug              varchar(200)    -- the moved/source path
  -- internal target identity (resolves to the target entry's CURRENT slug, possibly
  -- a different type/locale than the source):
  target_entry_uuid        char(12) null
  target_content_type_uuid char(12) null
  target_locale            varchar(16) null
  -- OR external/literal target (terminal):
  target_url               text null
  status                   int             -- CHECK (status IN (301,302,308))
  origin                   varchar(16)     -- CHECK (origin IN ('auto','manual'))
  created_by               char(12) null
  created_at               timestamptz
  updated_at               timestamptz null
  UNIQUE (content_type_uuid, locale, source_slug)            -- one redirect per source path
  INDEX (target_entry_uuid)
  -- exactly one target shape: a full internal triple, or a literal url (never both/neither)
  CHECK (
    (target_entry_uuid IS NOT NULL AND target_content_type_uuid IS NOT NULL
       AND target_locale IS NOT NULL AND target_url IS NULL)
    OR
    (target_entry_uuid IS NULL AND target_content_type_uuid IS NULL
       AND target_locale IS NULL AND target_url IS NOT NULL)
  )
```
Notes: CHECK constraints + the exclusive-target guard are Postgres DDL (the schema builder's
enum/check support, consistent with the other Lemma tables). An internal target stores the
**full `{entry, content_type, locale}` triple** (decision 8) so the resolver can render the
target's path even when it differs in type/locale from the source. `origin` distinguishes
auto-captured from operator-created (both deletable via `lemma.routes.manage`).

### 2. `RouteResolver` — the resolution unit (typed result)
`resolve(string $typeUuid, array $localeChain, string $requestedLocale, string $path):
ResolutionResult`, a discriminated value object with four cases:
- **`Content($entryRow)`** — a live `entry_routes` row matched → serve the entry.
- **`Redirect($to, $status, $external, $target)`** — a redirect matched and its target is
  resolvable. `$to` is the **rendered relative path** (decision 7). For an **internal**
  target, the resolver looks up the target entry's *current live slug* in its
  `{target_content_type_uuid, target_locale}` (single hop), renders the path from the route
  template, and sets `$target = {content_type, locale, slug}` + `$external=false`. For a
  **literal** target, `$to = target_url` verbatim, `$external=true`, `$target=null`.
- **`Gone`** — a redirect matched but its **internal target has no current live route**
  (unpublished/deleted/route removed). Distinct from not-found so admin can surface it.
- **`NotFound`** — nothing matched.

**Resolution precedence (explicit — P2):**
1. **Live route across the full locale fallback chain** — unchanged from today
   (`DeliveryController::show` walks `$localeChain`), so an `fr` request still falls back to
   `en` *content* as it does now.
2. **Redirect for the *requested* locale only** — redirects do **not** walk the fallback
   chain. A redirect is a per-locale URL-history fact; letting an `en` redirect catch an `fr`
   request would mix locales surprisingly. So an `fr` request misses all live routes →
   checks only `fr` redirects → if none, falls through (even if `en` has a redirect for that
   slug).
3. Caller's nanoid/uuid fallback → 4. not found.

**No server-side chain following:** internal targets are one hop to the current slug; literal
targets are returned verbatim. Chains and loops are structurally impossible — no depth cap.

### 3. Auto-capture hook on `RouteRepository::assign`
When an entry+locale's slug changes `old → new`:
1. update `entry_routes` to `new` (existing behavior);
2. if `old` is non-empty and `old !== new`, **upsert** a redirect
   `{source_slug: old, locale, target_entry_uuid: entry, target_content_type_uuid: <this
   type>, target_locale: <this locale>, status: 301, origin: 'auto'}` — the target triple is
   the same entry/type/locale (auto-capture is never cross-type/locale);
3. **delete any redirect whose `source_slug === new`** — a live route cannot also be a
   redirect source (live wins).
Repeated renames A→B→C leave **both** A and B as redirects pointing at the entry, each
resolving to the current slug C — never a chain. `old === new` is a no-op. This hook fires on
the existing route-assignment path, so it is reachable with `lemma.entries.write` (changing an
entry's route) without granting `lemma.routes.manage`.

### 4. `CanonicalProjector`
`for(string $entryUuid, string $locale): array` → `{canonical, alternates: [{locale, href}],
x_default}`, derived from `entry_publications` (which locales are published) +
`entry_routes` (their slugs). Every `canonical`/`href`/`x_default` is a **rendered relative
path** from the route template (decision 7), and each carries its `{content_type, locale,
slug}` identity alongside the path. `canonical` = this entry+locale's own path; `alternates`
= one per *other published* locale of the entry; `x_default` = the default locale's path
(`ContentLocaleService::default()`, rendered with the default-locale template variant).
Unpublished locales are excluded. No storage.

### 5. Delivery integration — `DeliveryController::show`
Drive resolution through `RouteResolver`:
- **`Content`** → **200** content (as today), now carrying an added `seo` block from
  `CanonicalProjector` (`canonical` + `alternates` + `x_default`, each a relative path +
  identity). ETag/Cache-Control/Cache-Tag are **unchanged** for content (keyed on the version
  UUID as today) — except the cache tag set also includes the entry's hreflang-alternate
  entry UUIDs so a sibling-locale publish/unpublish refreshes the `seo` block.
- **`Redirect`** → **200** with body `{ redirect: { to, status, external, target } }` and no
  content (clients don't auto-follow; the frontend emits the real browser 301/302/308). `to`
  is a relative path; `target` is `{content_type, locale, slug}` for internal, `null` for
  external.
- **`Gone`** → **404** (public delivery stays 404 — a redirect to a now-unpublished entry is
  not a live destination).
- **`NotFound`** → existing nanoid→uuid fallback, then **404**.

**Caching of redirect/`Gone` responses (P2).** A redirect descriptor depends on the **source
redirect row** + the **target's current route/publication state**, *not* a version UUID, so it
needs its own cache contract:
- **ETag** = a hash of `{redirect.uuid, status, resolved $to, resolved target_state}` — it
  changes when the target's slug changes, the target unpublishes (`Redirect`→`Gone`), or the
  redirect row is edited/deleted.
- **Cache-Tag (surrogate)** = the **source** content type **and** the **target entry UUID**
  (for internal). Because route assignment / publish / unpublish already bust the target
  entry's cache tag, a target slug change or unpublish **invalidates the cached redirect
  descriptor** automatically. External-target redirects tag only the source.
- **Cache-Control** = a **short** TTL (config `lemma.seo.redirect_ttl`, default 60s) — redirect
  state is operationally mutable, so it's cached briefly and leans on surrogate invalidation
  for correctness. `Gone` responses use the same short TTL so a republish recovers quickly.

### 6. Admin API + `RedirectController` (perm `lemma.routes.manage`)
- **`POST /v1/admin/content-types/{slug}/redirects`** — create a manual redirect:
  `{locale, source_slug, target: {entry_uuid, content_type?, locale?} | {url}, status}`. For
  an internal target, `content_type`/`locale` default to the **source's** type/locale (the
  common same-type/same-locale case) but may be set to point cross-type/cross-locale
  (decision 8); the server validates the target entry exists and is of that type/locale.
  Rejects a `source_slug` that collides with a **live route** (409), a status not in
  {301,302,308} (422), and a target that is neither a valid internal triple nor a `url` (422).
- **`GET /v1/admin/content-types/{slug}/redirects`** — list auto + manual (filter by locale).
  Each row carries a computed **`target_state`**: `live` (entry target resolves to a current
  slug, or an external target) or **`broken`** (entry target unpublished/deleted/no route).
  This makes a `Gone`-resolving redirect **visible in admin** even though public delivery
  returns 404 — broken routing is surfaced, not hidden.
- **`DELETE /v1/admin/redirects/{uuid}`** — delete a redirect (auto or manual).

`lemma.routes.manage` is a **new permission**, added to the seed list in the
roles/permissions dependent migration (`004_SeedLemmaRolesAndPermissions` — which today knows
only `lemma.models.manage` / `entries.write` / `entries.publish` / `entries.read`) and granted
to **`lemma_admin` only**; it is **not** granted to `lemma_editor`/`viewer` by default
(editors change routes — which auto-captures — but don't manage site-wide/external redirects).
The three admin routes register in the route manifest under the existing `/v1/admin` `auth`
group, each `->middleware('lemma_permission:lemma.routes.manage')`.

## Data model delta
- **New:** `entry_redirects` (with the internal-target triple + literal-url target).
- **No new storage** for canonical/hreflang (derived).
- **No change** to `entry_routes`; the auto-capture is an additive hook on `assign`.
- **New permission row** `lemma.routes.manage` (seed migration) + **three new admin routes**
  in the route manifest.
- **New config keys** `lemma.seo.route_template`, `lemma.seo.public_url_base` (optional),
  `lemma.seo.redirect_ttl`.

## Lifecycle / maintenance
- A redirect whose entry target has no current live route resolves `Gone` (public 404) and
  shows as `target_state: broken` in admin. **Auto-redirects are not auto-pruned** in the
  core flow — a broken target is useful evidence, and silent deletion would hide broken
  routing. A later maintenance command (`lemma:seo:check` / `lemma:redirects:prune`) can
  report/prune broken redirects on demand (out of scope here, noted as a follow-up).

## Testing (Postgres, `LemmaTestCase`; resolver/projector unit-testable)

- **`RouteResolver` precedence:** live route → `Content`; moved slug → `Redirect` with the
  target's current slug + 301; external redirect → `Redirect{external:true, to:url}`; redirect
  to an entry with no live route → `Gone`; no match → `NotFound`.
- **Cross-type / cross-locale internal target (P1):** a manual redirect whose target triple is
  a *different* `{content_type, locale}` resolves to that target's current slug, and `to` +
  `target` reflect the target's type/locale (e.g. `/blog/old → /docs/new`, `en → fr`).
- **Locale-fallback rule (P2):** an `fr` request that misses all live routes but where **only
  `en`** has a matching redirect → the `en` redirect is **not** used (redirects are
  requested-locale-only); live-route content fallback to `en` still works as today. Assert both.
- **URL contract (P1):** `to`/`canonical`/`href` are relative paths rendered from
  `lemma.seo.route_template` and each carries `{content_type, locale, slug}`; with
  `public_url_base` set they're absolute; the default-locale template variant omits the locale
  segment for `x_default`.
- **Single-hop guarantee:** entry target resolves in exactly one hop to the current slug;
  literal target returned verbatim — no chain following.
- **Auto-capture:** rename A→B creates a 301 A→entry; then B→C leaves **both** A and B
  resolving to C (no chain); creating a live route at a slug that had a redirect deletes that
  redirect (live wins); `old === new` is a no-op.
- **Canonical/hreflang:** an entry published in `en`+`fr` → `seo` block with self-canonical +
  two alternates + `x_default` (default locale); an unpublished locale is excluded.
- **Delivery mapping:** `Content` → 200 + `seo`; `Redirect` → 200 `{redirect:{…}}` no content;
  `Gone` → 404; `NotFound` → uuid fallback then 404.
- **Admin API:** create internal (same-type/locale default) + cross-type/locale + external
  redirect; list shows auto + manual with `target_state` (`live` vs `broken`); delete; source
  colliding with a live route → 409; bad status → 422; invalid target (neither valid triple nor
  url) → 422.
- **Routes + permission seed (P3):** the three redirect routes are **registered in the route
  manifest** (assert via the manifest/route list) under `/v1/admin`; the seed migration adds
  `lemma.routes.manage` and grants it to `lemma_admin`; **`lemma_admin` can** create/delete a
  redirect while **`lemma_editor` is denied (403)** on the redirect endpoints — yet an editor's
  route change still auto-captures a redirect.
- **Redirect caching (P2):** a `Redirect`/`Gone` response carries the derived ETag + the
  short TTL + a cache tag on the **target entry**; changing the target's slug (or unpublishing
  it) changes the ETag / busts the tag so the next request reflects the new target (or `Gone`).
- **Broken-target visibility:** a redirect whose target entry is unpublished lists as
  `broken` while public delivery returns 404 for it.

## Out of scope / follow-ups

- **Sitemaps, SEO meta-fields (title/description/OG), robots.txt** — separate modules.
- **`lemma:seo:check` / `lemma:redirects:prune`** maintenance command for broken/orphaned
  redirects — noted above; not built here.
- **Redirect import/export** (bulk launch migrations) — a later convenience.
- **Server-side multi-hop resolution of literal→internal targets** — intentionally omitted;
  single-hop is the invariant.

## Success criteria

- A renamed entry's old URL resolves to a `301` redirect descriptor whose `to` is the new
  relative path (+ `target` identity); the frontend can emit a real browser 301. Repeated
  renames never produce a chain.
- Operators manage manual redirects (301/302/308) — internal (same- or cross-type/locale, full
  target triple) and external — under `lemma.routes.manage`; a source colliding with a live
  route is rejected; `lemma_editor` is denied the redirect endpoints.
- Delivery exposes self-canonical + hreflang alternates (relative paths + identity) for
  published entries; redirect descriptors are requested-locale-only.
- A redirect to a now-unpublished entry returns public 404 but is visible as `broken` in admin;
  redirect/`Gone` responses are short-TTL cached and busted by target route/publication changes.
- Full suite green on Postgres CI; resolver (incl. cross-type/locale + locale-fallback),
  auto-capture, canonical, delivery-mapping, caching, routes/permission-seed, and admin tests
  pass.
