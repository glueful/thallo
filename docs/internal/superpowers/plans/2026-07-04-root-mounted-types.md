# Root-Mounted Content Types Implementation Plan

> Spec: `docs/superpowers/specs/2026-07-04-root-mounted-types-design.md`
> Execution: inline, task by task; STAGE at end; commit only on "commit all".

**Goal:** entries of a `mount_at_root` type resolve at `/{locale?}/{slug}`
(`/about`, `/fr/a-propos`) with every canonical surface following.

**Architecture:** one boolean on `content_types`; a `RootMountGuard` owning
the global root-namespace collision rules (routes + redirect sources); the
1-segment resolver case gains root entry + root redirect fallbacks with
type-precedence; a `CanonicalPathBuilder` becomes the single prefixed-vs-root
decision point adopted by all seven href surfaces.

## Global constraints

- Root grammar is FIXED `/{locale?}/{slug}` — never derived from
  `LEMMA_SEO_ROUTE_TEMPLATE` (spec §5). Root variants honor only
  `public_url_base` + default-locale collapse.
- Type precedence: an exact content-type slug always beats a root entry.
- Collisions fail loud at write time (409/422); resolve-time never shadows.
- Flag flips (either direction) purge `render:*`.
- phpcs 120-char lines; all events via BaseEvent; provider `use` imports.

---

### Task 1: Storage + metadata plumbing (no behavior change)

**Files:**
- Create: `database/migrations/019_AddMountAtRootToContentTypes.php`
  (boolean NOT NULL default false, mirror 001's `public_delivery` shape)
- Modify: `app/Content/Repositories/ContentTypeRepository.php`
  - `create()`: `'mount_at_root' => (bool)($data['mount_at_root'] ?? false)`
  - `hydrate()`: `$row['mount_at_root'] = (bool)($row['mount_at_root'] ?? false);`
  - `updateMeta()`: same nullable-partial handling as `public_delivery`
- Modify: `app/Content/Http/DTOs/CreateContentTypeData.php`
  (`public readonly bool $mount_at_root = false` + `#[Rule('boolean')]`)
- Modify: `app/Content/Http/DTOs/UpdateContentTypeData.php`
  (`?bool $mount_at_root = null`)
- Modify: `app/Content/Http/Controllers/ContentTypeController.php`
  - `store()`: pass the flag through (guard check added in Task 3)
  - `update()`: include in `updateMeta`; extend `$deliveryChanged` to
    `$routingChanged` (public_delivery OR mount_at_root changed → purge
    `render:*`); flip-ON guard call added in Task 3
- Test: `tests/Integration/Http/ContentTypeApiTest.php` — PATCH flips
  `mount_at_root`, hydrated GET/list rows carry the boolean, create accepts it.

**Gate:** ContentTypeApiTest green.

### Task 2: PathRenderer root variants + CanonicalPathBuilder

**Files:**
- Modify: `app/Content/Seo/PathRenderer.php`
  - `renderRoot(string $locale, string $slug): string` →
    `withBase('/' . locale . '/' . slug)` (rawurlencoded)
  - `renderRootDefaultLocale(string $slug): string` → `withBase('/' . slug)`
  - Both IGNORE `$routeTemplate` (spec §5 pin).
- Create: `app/Content/Seo/CanonicalPathBuilder.php`
  ```php
  final class CanonicalPathBuilder
  {
      public function __construct(
          private readonly PathRenderer $paths,
          private readonly LocaleManagerInterface $locales,
      ) {}
      /** THE prefixed-vs-root + default-locale-collapse decision. */
      public function pathFor(string $typeSlug, bool $mountAtRoot, string $locale, string $slug): string
  }
  ```
- Register in `app/Providers/LemmaServiceProvider.php` (autowire, shared;
  `use` import).
- Test: `tests/Unit/Content/Seo/PathRendererTest.php` — root variants with
  base + custom template (template ignored); new
  `tests/Unit/Content/Seo/CanonicalPathBuilderTest.php` — 4-way matrix
  (root/prefixed × default/other locale).

**Interfaces produced:** `CanonicalPathBuilder::pathFor(typeSlug, mountAtRoot, locale, slug)`.

**Gate:** unit tests green.

### Task 3: RootMountGuard + write-time enforcement

**Files:**
- Create: `app/Content/Routing/RootMountGuard.php`
  ```php
  /** Owns the global root URL namespace (spec §3): routes + redirect sources. */
  final class RootMountGuard
  {
      // deps: Connection, ContentTypeRepository, LocaleManagerInterface, ApplicationContext (reserved_prefixes config)
      /** @return list<string> conflict descriptions; [] = clear.
       *  $selfEntryUuid enables the self-reclaim exception. */
      public function conflictsForSlug(string $locale, string $slug, ?string $selfEntryUuid = null): array
      /** Validate EVERY route + redirect source of a type before flip-ON.
       *  @return list<string> */
      public function conflictsForType(string $typeUuid): array
      /** A NEW type slug vs existing root routes (spec §3 rule 3). */
      public function typeSlugConflicts(string $typeSlug): array
  }
  ```
  Checks per spec §3 table: type slugs, reserved prefixes
  (`config('lemma_render.reserved_prefixes')` + `_preview`), **reserved
  exact paths** (`config('lemma_render.reserved_exact')` —
  `sitemap.xml`/`robots.txt`; a root slug matching one would be accepted
  but unreachable at runtime), active locale codes, `page`/`terms`, other
  root-mounted routes, other root redirect sources. Self-reclaim: skip the
  same-entry redirect source that `RouteRepository::assign()` deletes.
- Modify: `app/Content/Http/Controllers/EntryController.php::assignRoute` —
  when the entry's type is root-mounted, run `conflictsForSlug` → 409
  `ROOT_SLUG_TAKEN` with the conflict list.
- Modify: `ContentTypeController::update()` — flip-ON runs
  `conflictsForType` → 409 with conflicts, flag unchanged.
- Modify: `ContentTypeController::store()` — `typeSlugConflicts` → 422.
- Register guard in `LemmaServiceProvider::services()`.
- Test: `tests/Integration/Routing/RootMountGuardTest.php` — the §8
  collision matrix (each rule incl. `sitemap.xml`, different-locale OK,
  self-reclaim, flip-ON conflict list incl. redirect source,
  type-create 422).

**Interfaces produced:** the three guard methods above.

**Gate:** guard tests + existing route/type API tests green.

### Task 4: Resolver grammar — root entries, root redirects, canonical 301

**Files:**
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
  - Extract the type/slug entry tail (current lines ~138–190: type gate →
    `routes->resolve` → gone/redirect/content + preview & working-copy
    overlays + presentation + cache tags) into
    `resolveTypedEntry(array $typeRow, string $slug, string $locale, ?PreviewSession): array`.
  - 1-segment case: type match → listing (unchanged); else
    `findRootRoute(localeChain, slug)` (entry_routes ⋈ content_types WHERE
    mount_at_root AND public_delivery AND status='active') → shared tail;
    else root redirect fallback; else 404.
  - 2-segment case: when the PREFIXED path of a root-mounted type resolves
    to **`kind === content` ONLY**, 301 to
    `CanonicalPathBuilder::pathFor(...)` root canonical instead of
    rendering (same family as locale collapse). Redirect rows are NOT
    canonicalized to the stale requested slug: `/pages/old` resolving as a
    redirect follows the redirect descriptor straight to `/new` (root-
    collapsed by Task 5's builder adoption in RouteResolver) — one hop,
    never `/pages/old → /old → /new`. Gone stays gone.
- Modify: `app/Content/Seo/RedirectRepository.php` — add
  `findBySourceAcrossTypes(list<string> $typeUuids, string $locale, string $slug): ?array`.
- Modify: `app/Content/Seo/RouteResolver.php` — expose the redirect
  descriptor path for the root branch (public
  `resolveRedirectRow(array $redirect): ResolutionResult` wrapping the
  existing private logic).
- Test: `tests/Integration/Render/RenderPipelineTest.php` (or a sibling
  `RootMountResolutionTest`) — the §8 grammar list: root hit, fr prefix,
  default collapse, type precedence, prefixed→root 301, root rename 301,
  **prefixed OLD slug → root NEW slug in one hop**, non-public 404,
  flag-OFF 404 + prefixed restored, preview overlay at root.

**Gate:** render pipeline suite green.

### Task 5: CanonicalPathBuilder adoption (all seven surfaces)

**Files (each swaps direct `PathRenderer` calls for the builder; callers
already hold the type row/flag):**
- `app/Content/Delivery/EnginePublicRouteResolver.php` (`href()`)
- `app/Content/Delivery/EngineEntryTargetResolver.php` (replaces the
  locale-collapse branch added 2026-07-04 — builder subsumes it)
- `app/Content/Seo/CanonicalProjector.php` (canonical + hreflang) — the
  flag is **per-pin**, not per-caller: alternates come from
  `publishedPinsForEntry()` and non-primary pin types resolve internally
  (`typeSlug($pinTypeUuid)`), so that lookup becomes a type-ROW lookup and
  each alternate/x-default href uses its own pin type's `mount_at_root`.
  A single caller-supplied flag would mislabel mixed-type alternates.
  Add a mixed-type alternate test if fixtures can represent that state
  (pins for an entry normally share its type — verify; if unrepresentable,
  assert the per-pin lookup path with same-type pins and note why).
- `app/Content/Delivery/EngineIndexableContentReader.php` (search hrefs)
- `app/Content/Delivery/EngineContentDeliveryReader.php` (delivery href +
  sitemap enumeration — shared mapper)
- `app/Content/Context/EngineLemmaContext.php`
- `app/Content/Seo/RouteResolver.php::resolveRedirect` — NOTE: currently
  uses `paths->render()` with NO default-locale collapse; builder adoption
  FIXES that latent off-canonical bug in redirect targets. Existing
  assertions expecting `/en/...` in redirect `to` values must be updated.
- Test: per-surface assertions (spec §8): menu target, sitemap href, search
  index href, SEO canonical + hreflang, delivery href, redirect target —
  root-collapsed for a flagged type AND unchanged for a prefixed type in
  the same test run.

**Gate:** full phpunit green.

### Task 6: Admin UI + API types

**Files:**
- Regen: `composer run docs:openapi` + `cd admin && pnpm gen:api`
- Modify: `admin/src/queries/contentTypes.ts` — `mount_at_root` on the
  `ContentType` interface + `normalizeContentType` + the
  `updateContentTypeMeta` meta param type.
- Modify: `admin/src/pages/settings/content-types/new.vue` — "Mount at
  root" USwitch under Public delivery (state default false), help text
  "Entries serve at /slug instead of /type/slug".
- Modify: `admin/src/pages/settings/content-types/[slug].vue` — second
  toggle row (`data-test="mount-at-root-toggle"`), same
  PATCH/notify/revert pattern as public delivery; 409 conflict list
  surfaces via the error toast body.
- Test: extend the type-editor/vitest coverage — toggle PATCHes
  `mount_at_root`; 409 shows conflicts and reverts the switch.

**Gate:** vitest, vue-tsc, oxlint green.

### Task 7: CHANGELOG + full gates + stage

- CHANGELOG `[Unreleased]`: root-mounted types entry (flag, grammar,
  precedence, collision posture, canonical surfaces incl. the redirect-
  target collapse fix, admin toggles).
- Full gates: `vendor/bin/phpunit`, `composer run phpcs`, admin
  `pnpm vitest run` / `pnpm type-check` / `pnpm lint`.
- `git add -A` (stage only; hold for "commit all").

## Self-review notes

- Task 4's tail extraction must keep `resolveEntry()` (homepage path)
  untouched — it has its own routeless/redirect rules.
- Guard queries join live tables at write time only — no resolve-time cost.
- The Task 5 EngineEntryTargetResolver change REPLACES this morning's
  locale-collapse branch; its tests keep passing (builder produces the same
  strings for prefixed types).
