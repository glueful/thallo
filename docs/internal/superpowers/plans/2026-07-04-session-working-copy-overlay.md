# Session-Wide Working-Copy Overlay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An existing working-copy stash wins over the draft wherever the preview session overlays the draft — canonical URLs and (new) the homepage — so the whole session matches the canvas stage.

**Architecture:** One private overlay helper in `EnginePublicRouteResolver`, keyed off the verified read result's own entry/locale (spec pin), called from all three read paths (token, canonical session branch, new homepage session branch). Additive `resolveEntry` signature (review P1). No storage/protocol/admin changes.

**Tech Stack:** PHP 8.3 (app + lemma-contracts + lemma-render packages), PHPUnit integration tests over the SQLite harness.

**Spec:** `docs/superpowers/specs/2026-07-04-session-working-copy-overlay-design.md`

## Global Constraints

- NO commits: stage at the end, STOP for "commit all".
- Overlay applies IFF `$read['version_uuid'] === null` (hard pin), keyed from `$read['entry_uuid']`/`$read['locale']` — never route params or copied session payload (spec pin).
- `resolveEntry` signature is ADDITIVE: `resolveEntry(string $entryUuid, ?string $locale = null, ?PreviewSession $previewSession = null)` (review P1); `home()` passes `null` for locale (today's behavior — the controller's `$locale` is only a no-entry template fallback).
- Listings/archives/terms and live renders untouched by construction.
- Test-state gotchas: `bootAppWithConfigOverride` for homepage config (mirror `RenderPipelineTest`); tearDown clears `render:*` (mirror `PreviewWorkingCopyTest`).

---

### Task 1: Overlay helper + canonical session path

**Files:**
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Test: `tests/Integration/Render/PreviewSessionTest.php`

**Interfaces:**
- Produces: `private function overlayWorkingCopy(array $read): array` (Task 2 reuses it).

- [ ] **Step 1: Write the failing tests**

Add to `PreviewSessionTest` (after `testSessionShowsPublishedContentInChromeElsewhere`), reusing `seedRoutedEntryWithDraft()` + `sessionRequest()`:

```php
    public function testSessionCanonicalUrlRendersTheWorkingCopyOverTheDraft(): void
    {
        [$entry, $token] = $this->seedRoutedEntryWithDraft();

        // No stash: the draft (existing behavior).
        $draft = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertStringContainsString('Draft override', (string) $draft->getContent());

        // Stash a working copy: it must WIN over the draft at the canonical URL.
        $this->container()->get(PreviewWorkingCopyStore::class)
            ->put($entry, 'en', ['title' => 'Working copy wins'], 300);
        $res = $this->handle($this->sessionRequest('/blog/hello', $token));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Working copy wins', $html);
        self::assertStringNotContainsString('Draft override', $html);
        // Session chrome + no-store posture unchanged.
        self::assertStringContainsString('preview-banner', $html);
        self::assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function testVersionPinnedSessionNeverRendersTheWorkingCopy(): void
    {
        [$entry] = $this->seedRoutedEntryWithDraft();
        // Pin the published version (seedBilingualPublishedEntry published v1).
        $versions = new VersionRepository($this->connection());
        $pinnedUuid = $versions->latestFor($entry, 'en')['uuid'];
        $pinned = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', (string) $pinnedUuid);

        $this->container()->get(PreviewWorkingCopyStore::class)
            ->put($entry, 'en', ['title' => 'Working copy wins'], 300);

        $res = $this->handle($this->sessionRequest('/blog/hello', $pinned));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringNotContainsString('Working copy wins', $html); // hard pin
        self::assertStringContainsString('Hello', $html);                 // the pinned version
    }

    public function testAnotherEntrysStashNeverLeaksIntoTheSession(): void
    {
        // Single-draft scope over an entry-keyed store: another entry's stash
        // must not affect its canonical URL inside THIS session.
        [, $token] = $this->seedRoutedEntryWithDraft();
        $other = $this->seedSecondPublishedEntry('other', 'Other page'); // helper below
        $this->container()->get(PreviewWorkingCopyStore::class)
            ->put($other, 'en', ['title' => 'Leaked stash'], 300);

        $res = $this->handle($this->sessionRequest('/blog/other', $token));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Other page', $html); // published
        self::assertStringNotContainsString('Leaked stash', $html);
    }

    public function testSessionRenderWithStashNeitherReadsNorWritesTheRenderCache(): void
    {
        // Sentinel shape (review P2, mirroring the valid-cookie bypass test):
        // an assertNull after the render only proves NO WRITE. Pre-seeding a
        // sentinel proves NO READ (the sentinel is not served) AND no write
        // (the sentinel survives unchanged).
        [$entry, $token] = $this->seedRoutedEntryWithDraft();
        $cache = $this->container()->get(CacheStore::class);
        $key = 'render:default:%2Fblog%2Fhello';

        // Prime the real cache entry, then plant the sentinel.
        $this->handle(Request::create('/blog/hello', 'GET'));
        $cached = $cache->get($key);
        self::assertIsArray($cached);
        $cached['body'] = 'SENTINEL-CACHED';
        $cache->set($key, $cached, 3600);

        $this->container()->get(PreviewWorkingCopyStore::class)
            ->put($entry, 'en', ['title' => 'Working copy wins'], 300);

        $res = $this->handle($this->sessionRequest('/blog/hello', $token));
        $html = (string) $res->getContent();
        self::assertStringContainsString('Working copy wins', $html); // overlaid, live
        self::assertStringNotContainsString('SENTINEL-CACHED', $html); // no READ
        self::assertSame('SENTINEL-CACHED', $cache->get($key)['body']); // no WRITE
    }
```

Add the seed helper next to `seedRoutedEntryWithDraft()` (shape mirrors `seedBlockPage` in `PreviewWorkingCopyTest`, but against the blog type this suite already seeds — check `seedBilingualPublishedEntry` for the exact repos and reuse them):

```php
    private function seedSecondPublishedEntry(string $slug, string $title): string
    {
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $typeUuid = $this->blogTypeUuid(); // resolve however seedBilingualPublishedEntry stores it
        $entry = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => $title], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $typeUuid, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator($this->connection(), $this->appContext(), new BlockTypeRepository($this->connection())),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        return $entry;
    }
```

(Adapt imports/type-uuid retrieval to the suite's existing helpers at execution time — `seedBilingualPublishedEntry` already builds this stack; prefer extracting/reusing over duplicating. If `VersionRepository::latestFor` doesn't exist, fetch the version uuid the way `PreviewWorkingCopyTest` does for its pinned-token test.)

- [ ] **Step 2: Run to verify the right failures**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php`
Expected: `testSessionCanonicalUrlRendersTheWorkingCopyOverTheDraft` AND the sentinel cache test FAIL on their "Working copy wins" assertions (the session branch has no overlay; the cache-bypass halves of the sentinel test assert existing behavior). Pinned/leak tests may pass already (they assert existing behavior); confirm they fail ONLY if seeded helpers are wrong. All pre-existing tests PASS.

- [ ] **Step 3: Implement the helper + canonical call site**

In `app/Content/Delivery/EnginePublicRouteResolver.php`:

3a. Add after `resolvePreview()`:

```php
    /**
     * Loop C overlay, session-wide (working-copy-overlay spec): an existing
     * working copy wins over the draft for DRAFT-MODE reads only. Keyed off
     * the read result's OWN entry/locale (spec pin) — the read is the thing
     * being shaped, so it decides which stash can apply; never route params
     * or separately-copied session payload.
     *
     * @param array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>} $read
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    private function overlayWorkingCopy(array $read): array
    {
        if ($read['version_uuid'] !== null || $this->workingCopies === null) {
            return $read;
        }
        $working = $this->workingCopies->get($read['entry_uuid'], $read['locale']);
        if ($working !== null) {
            $read['fields'] = $working;
        }
        return $read;
    }
```

3b. In `resolvePreview()`, replace the inline overlay block

```php
        // Loop C overlay (spec §3): DRAFT-MODE tokens only — a version-pinned
        // token renders its immutable version, never the working copy (hard pin).
        if ($read['version_uuid'] === null && $this->workingCopies !== null) {
            $working = $this->workingCopies->get($read['entry_uuid'], $read['locale']);
            if ($working !== null) {
                $read['fields'] = $working;
            }
        }
```

with

```php
        $read = $this->overlayWorkingCopy($read);
```

3c. In `resolvePath()`'s session branch, change

```php
                return $this->previewContent($this->preview->readVerified($previewSession));
```

to

```php
                return $this->previewContent(
                    $this->overlayWorkingCopy($this->preview->readVerified($previewSession))
                );
```

- [ ] **Step 4: Run to verify green**

Run: `vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php tests/Integration/Render/PreviewWorkingCopyTest.php`
Expected: PASS — including the untouched token-path suite (refactor-safe).

---

### Task 2: Homepage session overlay

**Files:**
- Modify: `packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Test: `tests/Integration/Render/PreviewSessionTest.php`

**Interfaces:**
- Consumes: `overlayWorkingCopy()` (Task 1).
- Produces: `resolveEntry(string $entryUuid, ?string $locale = null, ?PreviewSession $previewSession = null)` on the contract + implementation.

- [ ] **Step 1: Write the failing tests**

Everything in these tests runs through the OVERRIDE app's container (review
P1): `bootAppWithConfigOverride` boots a separate context, and
`RenderPipelineTest` calls that container's `RenderController` directly for
homepage overrides — writing the stash through the shared kernel's
`$this->container()` would let the override resolver miss it (a different
cache wiring = harness-reason failure).

```php
    public function testHomepageSessionForTheHomepageEntryRendersDraftThenWorkingCopy(): void
    {
        [$entry, $token] = $this->seedRoutedEntryWithDraft();
        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $entry]);
        $controller = $app->getContainer()->get(RenderController::class);

        // Session at '/': the DRAFT (closes the pre-existing gap).
        $draft = $controller->home($this->sessionRequest('/', $token));
        self::assertSame(200, $draft->getStatusCode());
        self::assertStringContainsString('Draft override', (string) $draft->getContent());

        // With a stash — written through the OVERRIDE container (review P1):
        // the WORKING COPY.
        $app->getContainer()->get(PreviewWorkingCopyStore::class)
            ->put($entry, 'en', ['title' => 'Working copy wins'], 300);
        $res = $controller->home($this->sessionRequest('/', $token));
        self::assertStringContainsString('Working copy wins', (string) $res->getContent());
    }

    public function testHomepageSessionForAnotherEntryStaysPublished(): void
    {
        $home = $this->seedSecondPublishedEntry('home', 'Published home');
        [, $token] = $this->seedRoutedEntryWithDraft(); // session for the BLOG entry
        $app = self::bootAppWithConfigOverride('lemma_render', ['homepage_entry' => $home]);
        $controller = $app->getContainer()->get(RenderController::class);

        $res = $controller->home($this->sessionRequest('/', $token));
        self::assertSame(200, $res->getStatusCode());
        $html = (string) $res->getContent();
        self::assertStringContainsString('Published home', $html); // single-draft scope
        self::assertStringNotContainsString('Draft override', $html);
    }
```

(Exact `getContainer()` accessor name and any session-verification wiring on
the direct-controller path: mirror `RenderPipelineTest`'s homepage tests at
execution time — the container-consistency rule and the assertions are the
contract.)

- [ ] **Step 2: Run to verify the right failures**

Expected: the own-entry test FAILS at the DRAFT assertion already (homepage never overlays today) — this proves the pre-existing gap. The other-entry test should PASS (published is today's behavior for everything).

- [ ] **Step 3: Implement**

3a. Contract (`packages/lemma-contracts/src/Delivery/PublicRouteResolver.php`) — replace

```php
    /** Same result shape, for a known entry (homepage; previews later). */
    public function resolveEntry(string $entryUuid, ?string $locale = null): array;
```

with

```php
    /**
     * Same result shape, for a known entry (homepage). `$previewSession` is an
     * ALREADY-VERIFIED session: when its {entry, locale} matches the resolved
     * entry, the draft — or the stashed working copy — is returned instead of
     * the published fields (single-draft scope; additive param, review P1).
     */
    public function resolveEntry(
        string $entryUuid,
        ?string $locale = null,
        ?PreviewSession $previewSession = null,
    ): array;
```

3b. Implementation — `EnginePublicRouteResolver::resolveEntry` gains the same
trailing param; after the routed/content checks succeed (where `$row` is
known), insert the session branch mirroring `resolvePath()`:

```php
        // Single-draft overlay at '/' (working-copy-overlay spec §2): the
        // session's OWN entry shows its draft/working copy as the homepage.
        if (
            $previewSession !== null
            && $previewSession->entry === (string) $row['entry_uuid']
            && $previewSession->locale === (string) $row['locale']
        ) {
            try {
                return $this->previewContent(
                    $this->overlayWorkingCopy($this->preview->readVerified($previewSession))
                );
            } catch (PreviewNotFoundException) {
                // Draft vanished mid-session: fall through to published.
            }
        }
```

3c. `RenderController::home()` — change

```php
            $result = $this->resolver->resolveEntry($homepageEntry);
```

to

```php
            // Locale stays null (P1 note): today's call passes none, and the
            // controller's $locale is only the no-entry template fallback.
            $result = $this->resolver->resolveEntry($homepageEntry, null, $session);
```

- [ ] **Step 4: Run to verify green**

Run: `vendor/bin/phpunit tests/Integration/Render/PreviewSessionTest.php tests/Integration/Render/PreviewWorkingCopyTest.php tests/Integration/Render/RenderPipelineTest.php`
Expected: PASS (pipeline suite guards the resolveEntry signature change).

---

### Task 3: Docs, full gates, STAGE

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README** — in the preview-sessions paragraph ("**Preview sessions (preview v2)**…"), after "the tokened draft overlaid at its canonical URL (single-draft scope: everything else stays published)", amend to mention the working copy and the homepage:

```
the tokened draft — or, when the canvas has applied unsaved work, its
working copy — overlaid at its canonical URL and at `/` when the entry is
the configured homepage (single-draft scope: everything else stays
published)
```

- [ ] **Step 2: CHANGELOG** — new `[Unreleased]` bullet after the canvas v9 entry:

```
- Session-wide working-copy overlay: the canvas's applied-but-unsaved
  working copy now wins over the draft EVERYWHERE the preview session
  overlays the draft — canonical URLs and (new) the homepage, which
  previously never overlaid even the draft. One resolver-side overlay
  helper keyed off the verified read's own entry+locale; version-pinned
  sessions keep ignoring working copies; listings/archives/terms keep
  their published-only posture; session renders keep bypassing the page
  cache (regression-asserted). `PublicRouteResolver::resolveEntry` gains
  an optional trailing `?PreviewSession` parameter (additive).
```

- [ ] **Step 3: Full gates**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
vendor/bin/phpunit                       # full PHP suite
composer run analyse 2>/dev/null || vendor/bin/phpstan analyse  # whichever script exists
cd admin && pnpm vitest run              # untouched, but cheap insurance
```

- [ ] **Step 4: STAGE (no commit)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add app/Content/Delivery/EnginePublicRouteResolver.php \
        packages/lemma-contracts/src/Delivery/PublicRouteResolver.php \
        packages/lemma-render/src/Http/Controllers/RenderController.php \
        packages/lemma-render/README.md \
        tests/Integration/Render/PreviewSessionTest.php \
        CHANGELOG.md \
        docs/superpowers/specs/2026-07-04-session-working-copy-overlay-design.md \
        docs/superpowers/plans/2026-07-04-session-working-copy-overlay.md
git status
```

STOP — the user commits.
