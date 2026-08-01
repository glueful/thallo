# Ephemeral Preview Render (Loop C) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An Apply action on the visual canvas that server-validates the working block tree, stashes the cleaned fields in cache keyed by `{entry, locale}`, and reloads the stage's existing `/_preview/{token}` URL — instant preview of unsaved work, nothing persisted.

**Architecture:** App-side only (the render pack is untouched): a `PreviewWorkingCopyStore` (CacheStore-backed), an `applyPreview` action on `EntryController` running the exact `saveDraft` guard order (gate → validator), an overlay in `EnginePublicRouteResolver::resolvePreview()` gated on draft-mode tokens, clear-on-save in `saveDraft`. SPA: Apply button + token retention + same-URL stage reload; Save draft stops re-minting.

**Tech Stack:** PHP 8.3 (Glueful), PostgreSQL test DB, Vue 3 + Nuxt UI 4 admin, vitest, openapi-typescript (`pnpm gen:api`).

**Spec:** `docs/superpowers/specs/2026-07-03-ephemeral-preview-render-design.md`

## Global Constraints

- **Guard order parity (load-bearing):** applyPreview mirrors `saveDraft` — `BlockMigrationGate::assertWritable` BEFORE `FieldValidator::validate`, identical 409 `BLOCK_MIGRATION_IN_PROGRESS` and 422 shapes.
- **Version-pinned tokens:** apply → 409 `PREVIEW_VERSION_PINNED`, no stash; read overlay gated on `version_uuid === null` — both hard requirements.
- **Cleaned fields only:** the stash stores `FieldValidator::validate()`'s return value, never the raw payload.
- **Clear-on-save:** successful `saveDraft` clears the stash for its `{entry, locale}`; failure paths leave it.
- **Stash:** key `lemma:preview:working:{entryUuid}:{locale}`, TTL `min(remaining token TTL, 300s)`, payload cap 1 MB (1048576 bytes of JSON) → 413.
- **Token errors mirror `PreviewController::show()`:** expired → 410 "Preview link expired", invalid/malformed/wrong-entry → 403 "Invalid preview token" (generic, never leaks internals).
- **Route permission:** `lemma_permission:content.edit`.
- **Provider conventions:** `use` imports in `LemmaServiceProvider`, not inline FQCNs. New controllers/services registered in `services()`.
- **Commit gate:** STAGE at the end of Task 5 only; commit ONLY on explicit authorization. No attribution trailers. The uncommitted `cancelDelete`/`cancelAddAfter` fix in the design page rides with this staging.
- **Verification:** PHP from repo root (`vendor/bin/phpcs -q`, `composer boundaries`, `vendor/bin/phpunit --testsuite Unit|Integration`); admin `cd admin && pnpm type-check && pnpm test`. OpenAPI regen: `php glueful generate:openapi` then `cd admin && pnpm gen:api`.

---

### Task 1: `PreviewWorkingCopyStore` + DI registration

**Files:**
- Create: `app/Content/Preview/PreviewWorkingCopyStore.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (registration + factory)
- Test: `tests/Integration/Content/PreviewApplyTest.php` (new file; store round-trip test first)

**Interfaces:**
- Consumes: `Glueful\Cache\CacheStore` (container-resolved, same as `InvalidateCacheTagsListener`).
- Produces (Tasks 2–3 rely on): `PreviewWorkingCopyStore::put(string $entryUuid, string $locale, array $cleanFields, int $ttl): void`, `get(string $entryUuid, string $locale): ?array`, `clear(string $entryUuid, string $locale): void`.

- [ ] **Step 1: Write the failing store test**

Create `tests/Integration/Content/PreviewApplyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Preview\PreviewWorkingCopyStore;
use App\Tests\Support\LemmaTestCase;

final class PreviewApplyTest extends LemmaTestCase
{
    use ResolvesPreviewKey;

    private function store(): PreviewWorkingCopyStore
    {
        return $this->container()->get(PreviewWorkingCopyStore::class);
    }

    public function testWorkingCopyStoreRoundTripAndClear(): void
    {
        $store = $this->store();
        self::assertNull($store->get('entry0000001', 'en'));

        $store->put('entry0000001', 'en', ['title' => 'W'], 60);
        self::assertSame(['title' => 'W'], $store->get('entry0000001', 'en'));
        // Keyed per entry+locale: neighbours are isolated.
        self::assertNull($store->get('entry0000001', 'fr'));
        self::assertNull($store->get('entry0000002', 'en'));

        // Overwrite (last writer wins), then clear.
        $store->put('entry0000001', 'en', ['title' => 'W2'], 60);
        self::assertSame(['title' => 'W2'], $store->get('entry0000001', 'en'));
        $store->clear('entry0000001', 'en');
        self::assertNull($store->get('entry0000001', 'en'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Content/PreviewApplyTest.php`
Expected: FAIL/ERROR — class `PreviewWorkingCopyStore` not found.

- [ ] **Step 3: Implement the store**

Create `app/Content/Preview/PreviewWorkingCopyStore.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Preview;

use Glueful\Cache\CacheStore;

/**
 * The visual canvas's ephemeral working copy (loop C spec §3): the VALIDATED,
 * CLEANED fields of one entry+locale, stashed in cache so /_preview/{token}
 * can render unsaved work. Never persisted; TTL-bounded; overwritten per
 * apply; cleared by a successful saveDraft. Keyed by {entry, locale} — NOT by
 * token — so the save path (which never sees the token) can clear it.
 *
 * @phpstan-type Fields array<string,mixed>
 */
final class PreviewWorkingCopyStore
{
    /** @param CacheStore<mixed> $cache */
    public function __construct(private readonly CacheStore $cache)
    {
    }

    private function key(string $entryUuid, string $locale): string
    {
        return 'lemma:preview:working:' . $entryUuid . ':' . $locale;
    }

    /** @param array<string,mixed> $cleanFields validator OUTPUT only — never raw payload */
    public function put(string $entryUuid, string $locale, array $cleanFields, int $ttl): void
    {
        $this->cache->set($this->key($entryUuid, $locale), $cleanFields, $ttl);
    }

    /** @return array<string,mixed>|null */
    public function get(string $entryUuid, string $locale): ?array
    {
        $value = $this->cache->get($this->key($entryUuid, $locale));
        return is_array($value) ? $value : null;
    }

    public function clear(string $entryUuid, string $locale): void
    {
        $this->cache->delete($this->key($entryUuid, $locale));
    }
}
```

- [ ] **Step 4: Register in the provider**

In `app/Providers/LemmaServiceProvider.php`, add the `use` imports (provider convention — no inline FQCNs):

```php
use App\Content\Preview\PreviewWorkingCopyStore;
use Glueful\Cache\CacheStore;
```

In `services()`, next to the `PreviewController` entry:

```php
            PreviewWorkingCopyStore::class => [
                'shared' => true,
                'factory' => [self::class, 'makePreviewWorkingCopyStore'],
            ],
```

Next to `makePreviewController()`:

```php
    public static function makePreviewWorkingCopyStore(ContainerInterface $container): PreviewWorkingCopyStore
    {
        return new PreviewWorkingCopyStore($container->get(CacheStore::class));
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Content/PreviewApplyTest.php`
Expected: PASS (1 test).

---

### Task 2: Apply endpoint (`EntryController::applyPreview`)

**Files:**
- Create: `app/Content/Http/DTOs/ApplyPreviewData.php`
- Modify: `app/Content/Http/Controllers/EntryController.php` (ctor param, trait, new action)
- Modify: `routes/lemma_admin.php` (route next to mint)
- Test: `tests/Integration/Content/PreviewApplyTest.php` (extend)

**Interfaces:**
- Consumes: Task 1's store; `PreviewToken::verify(string $token, string $key, int $now): PreviewToken` (throws `PreviewTokenException`); `ResolvesPreviewKey::previewKey(ApplicationContext): string`; existing `$this->gate` / `$this->validator` / `$this->entries` / `$this->types` / `$this->locales` on `EntryController`.
- Produces (Task 4 relies on): `POST /v1/admin/entries/{uuid}/preview/{locale}/apply`, body `{token, fields}` → 200 `{applied_at}`; 403/409(`PREVIEW_VERSION_PINNED`|`BLOCK_MIGRATION_IN_PROGRESS`)/410/413/422 per spec §2.

- [ ] **Step 1: Write the failing endpoint tests**

Append to `tests/Integration/Content/PreviewApplyTest.php` (add these imports at the top of the file):

```php
use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\Migration\BlockMigrationService;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\ApplyPreviewData;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewToken;
use App\Content\Preview\ResolvesPreviewKey;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use Symfony\Component\HttpFoundation\Request;
```

And the test body (inside the class):

```php
    private string $type = '';

    /** @return array{entry:string} */
    private function seedEntry(): array
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'card',
            'label' => 'Card',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'D'], 1, 0, 'user00000001');
        return ['entry' => $entry];
    }

    private function controller(): EntryController
    {
        return $this->container()->get(EntryController::class);
    }

    private function req(): Request
    {
        return Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
    }

    private function mint(string $entry, ?string $version = null, string $locale = 'en'): string
    {
        return $this->container()->get(PreviewMinter::class)->mint($entry, $locale, $version);
    }

    public function testApplyTokenFailuresAreFailClosed(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // Invalid token -> 403.
        $bad = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token . 'x', fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(403, $bad->getStatusCode());

        // Wrong entry for a VALID token -> 403 (binding), stash untouched.
        $other = (new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        ))->createEntry($this->type, 'en', 1, 'user00000001');
        $rebound = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W']),
            $this->req(),
            $other,
            'en',
        );
        self::assertSame(403, $rebound->getStatusCode());
        self::assertNull($this->store()->get($other, 'en'));

        // Expired token -> 410 (the SPA's re-mint-and-retry path keys off this),
        // no stash. Minted directly with an in-the-past expiry via the same key
        // derivation both sides use (ResolvesPreviewKey on the test class).
        $expired = PreviewToken::mint($entry, 'en', null, time() - 1, $this->previewKey($this->appContext()));
        $gone = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $expired, fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(410, $gone->getStatusCode());
        self::assertNull($this->store()->get($entry, 'en'));
    }

    public function testApplyRejectsVersionPinnedTokensWith409(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        // Any version uuid works: the pin check runs BEFORE existence checks.
        $pinned = $this->mint($entry, 'version00001');
        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $pinned, fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('PREVIEW_VERSION_PINNED', (string) $resp->getContent());
        self::assertNull($this->store()->get($entry, 'en'));
    }

    public function testApplyValidates422AndCaps413(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // Duplicate block ids across the entry -> the saveDraft 422 mirror.
        $dup = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W', 'body' => [
                ['id' => 'sameid000001', 'type' => 'card', 'data' => ['title' => 'a']],
                ['id' => 'sameid000001', 'type' => 'card', 'data' => ['title' => 'b']],
            ]]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(422, $dup->getStatusCode());
        self::assertNull($this->store()->get($entry, 'en'));

        // 1 MB cap -> 413.
        $big = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => str_repeat('x', 1100000)]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(413, $big->getStatusCode());

        // Unknown locale -> 422 (a token minted FOR that locale passes binding
        // and dies at the locale check, mirroring mint/saveDraft).
        $xx = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $this->mint($entry, null, 'xx'), fields: ['title' => 'W']),
            $this->req(),
            $entry,
            'xx',
        );
        self::assertSame(422, $xx->getStatusCode());
    }

    public function testApplyIsGatedByBlockMigrations(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);
        $blockType = (string) (new BlockTypeRepository($this->connection()))->findBySlug('card')['uuid'];
        $this->container()->get(BlockMigrationService::class)
            ->migrate($blockType, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);

        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: ['title' => 'W', 'body' => [
                ['id' => 'blockone0001', 'type' => 'card', 'data' => ['title' => 'x']],
            ]]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
        self::assertStringContainsString('BLOCK_MIGRATION_IN_PROGRESS', (string) $resp->getContent());
        self::assertNull($this->store()->get($entry, 'en')); // no stash on 409
    }

    public function testApplyStashesTheCleanedFields(): void
    {
        ['entry' => $entry] = $this->seedEntry();
        $token = $this->mint($entry);

        // An unknown field rides along: the validator's CLEANED output drops it —
        // proving the stash never stores the raw payload.
        $resp = $this->controller()->applyPreview(
            new ApplyPreviewData(token: $token, fields: [
                'title' => 'Working',
                'not_in_schema' => 'dropped',
            ]),
            $this->req(),
            $entry,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
        $stashed = $this->store()->get($entry, 'en');
        self::assertNotNull($stashed);
        self::assertSame('Working', $stashed['title']);
        self::assertArrayNotHasKey('not_in_schema', $stashed);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Integration/Content/PreviewApplyTest.php`
Expected: ERROR — `ApplyPreviewData` / `applyPreview` not found. (If `createEntry`/`saveDraft`/`create` signatures differ from `PreviewAnnotationTest`'s usage, mirror that file exactly — it is the reference harness.)

- [ ] **Step 3: Create the DTO**

Create `app/Content/Http/DTOs/ApplyPreviewData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries/{uuid}/preview/{locale}/apply`
 * ({@see \App\Content\Http\Controllers\EntryController::applyPreview()}).
 *
 * `token` is the preview session's HMAC token — the controller verifies it and
 * binds it to the route's entry+locale before anything else. `fields` stays a
 * bare `array` for the same reason as {@see SaveDraftData}: the per-field
 * semantic validation is the controller's FieldValidator.
 */
final class ApplyPreviewData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        /** @var array<string,mixed> Working field values keyed by the content type's field names. */
        #[Rule('array')]
        public readonly array $fields = [],
    ) {
    }
}
```

- [ ] **Step 4: Implement `applyPreview`**

In `app/Content/Http/Controllers/EntryController.php`:

Add imports:

```php
use App\Content\Http\DTOs\ApplyPreviewData;
use App\Content\Preview\PreviewToken;
use App\Content\Preview\PreviewTokenException;
use App\Content\Preview\PreviewWorkingCopyStore;
use App\Content\Preview\ResolvesPreviewKey;
```

Add the trait inside the class (`final class EntryController` body, first line):

```php
    use ResolvesPreviewKey;
```

Extend the constructor (after the `$gate` param):

```php
        /** Loop C working-copy stash; null = apply unavailable (minimal wiring). */
        private readonly ?PreviewWorkingCopyStore $workingCopies = null,
```

Add the action after `saveDraft()`:

```php
    /**
     * Apply the CURRENT working fields as an ephemeral preview (loop C spec §2):
     * validate with the exact saveDraft guard set, stash the CLEANED fields in
     * cache keyed by {entry, locale}, persist nothing. /_preview/{token} then
     * overlays the stash over the DB draft until Save, the next Apply, or TTL.
     */
    #[ApiOperation(
        summary: 'Apply working fields as an ephemeral preview',
        description: 'Validates the submitted fields with the same guards as a draft save and stashes the '
            . 'cleaned result so the preview session renders unsaved work. Nothing is persisted; a successful '
            . 'draft save clears the stash.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Working copy applied to the preview session.')]
    #[ApiResponse(403, schema: ErrorResponse::class, envelope: false, description: 'Invalid or re-pointed token.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Version-pinned token (PREVIEW_VERSION_PINNED) or active block migration '
            . '(BLOCK_MIGRATION_IN_PROGRESS).',
    )]
    #[ApiResponse(410, schema: ErrorResponse::class, envelope: false, description: 'The preview token has expired.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Field validation failed.')]
    // 401/403(permission)/429/500 inferred from middleware + documentation.errors config.
    public function applyPreview(ApplyPreviewData $input, Request $request, string $uuid, string $locale): Response
    {
        if ($this->workingCopies === null) {
            return Response::error('Preview apply is unavailable.', 503);
        }
        // 1. Verify the token — same fail-closed mapping as PreviewController::show().
        try {
            $token = PreviewToken::verify($input->token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException $e) {
            return $e->isExpired()
                ? Response::error('Preview link expired', 410)
                : Response::forbidden('Invalid preview token');
        }
        // 2. Bind to the route: a token can never be pointed at another entry+locale.
        if ($token->entryUuid !== $uuid || $token->locale !== $locale) {
            return Response::forbidden('Invalid preview token');
        }
        // Version-pinned tokens conflict with immutable-version semantics (hard pin):
        // the token is VALID, the operation is not — 409, not 422. Never stash.
        if ($token->versionUuid !== null) {
            return Response::error(
                'A version-pinned preview cannot apply a working copy.',
                Response::HTTP_CONFLICT,
                ['code' => 'PREVIEW_VERSION_PINNED'],
            );
        }
        // 3. Locale + entry resolution (mirror saveDraft).
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        // 4. Payload cap: the stash is a cache row — never a cache-pressure primitive.
        $encoded = json_encode($input->fields);
        if ($encoded === false || strlen($encoded) > 1048576) {
            return Response::error('Preview payload too large.', 413);
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        // 5.–6. The exact saveDraft guard order (loop C spec §2, load-bearing):
        // migration gate FIRST, then the validator; identical response shapes.
        try {
            $this->gate?->assertWritable($input->fields, $schema);
        } catch (BlockMigrationInProgressException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, [
                'code' => 'BLOCK_MIGRATION_IN_PROGRESS',
                'block_type' => $e->slug,
            ]);
        }
        try {
            $clean = $this->validator->validate($schema, $input->fields);
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        // 7. Stash the CLEANED fields only, TTL capped to the token's remaining life.
        $ttl = min(max($token->expiresAt - time(), 1), 300);
        $this->workingCopies->put($uuid, $locale, $clean, $ttl);
        return Response::success(['applied_at' => date('c')], 'Preview applied.');
    }
```

- [ ] **Step 5: Register the route**

In `routes/lemma_admin.php`, directly after the mint route:

```php
    $router->post('/entries/{uuid}/preview/{locale}/apply', [EntryController::class, 'applyPreview'])
        ->middleware('lemma_permission:content.edit');
```

- [ ] **Step 6: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Content/PreviewApplyTest.php`
Expected: PASS (6 tests). Also run `vendor/bin/phpunit --testsuite Integration --filter EntryRepositoryTest` as a sanity spot-check on the touched controller.

---

### Task 3: Resolver overlay + clear-on-save (+ render test)

**Files:**
- Modify: `app/Content/Delivery/EnginePublicRouteResolver.php` (ctor param + overlay in `resolvePreview()`)
- Modify: `app/Content/Http/Controllers/EntryController.php` (`saveDraft` clear-on-save)
- Test: `tests/Integration/Render/PreviewWorkingCopyTest.php` (new)

**Interfaces:**
- Consumes: Task 1's store (`get`/`clear`); the existing `resolvePreview()`/`previewContent()` split; `PublishService::publish(): string` (returns the version uuid — the pinned-token test needs it).
- Produces: `/_preview/{token}` renders the working copy for draft-mode tokens; `saveDraft` success clears the stash.

- [ ] **Step 1: Write the failing render tests**

Create `tests/Integration/Render/PreviewWorkingCopyTest.php` (harness mirrors `PreviewAnnotationTest` — same seed shape, same `RenderController::preview()` drive):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewWorkingCopyStore;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Loop C spec §3: /_preview/{token} overlays the stashed working copy over the
 * DB draft for DRAFT-MODE tokens only; saveDraft clears the stash; pinned
 * versions are never overlaid.
 */
final class PreviewWorkingCopyTest extends LemmaTestCase
{
    private string $type;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Glueful\Lemma\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    /** @return array{entry:string,version:string} */
    private function seedBlockPage(string $slug): array
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'quote',
            'label' => 'Quote',
            'schema' => [['name' => 'text', 'type' => 'text']],
        ]);
        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'page',
            'name' => 'Page',
            'public_delivery' => true,
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ],
        ]);
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        $entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Draft only']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', $slug);
        $version = (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            $types,
            new FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        return ['entry' => $entry, 'version' => $version];
    }

    private function renderPreview(string $token): string
    {
        $response = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        );
        return (string) $response->getContent();
    }

    public function testWorkingCopyOverlaysDraftAndSaveClearsIt(): void
    {
        ['entry' => $entry] = $this->seedBlockPage('wc-page');
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');

        // No stash: the draft renders.
        self::assertStringContainsString('Draft only', $this->renderPreview($token));

        // Stash a working copy with a block that exists ONLY there.
        $store->put($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Draft only']],
            ['id' => 'workingb0001', 'type' => 'quote', 'data' => ['text' => 'Applied only']],
        ]], 60);
        $html = $this->renderPreview($token);
        self::assertStringContainsString('Applied only', $html);
        // The working-only block is ANNOTATED like any rendered instance.
        self::assertStringContainsString('data-lemma-block="workingb0001"', $html);

        // saveDraft SUCCESS clears the stash (clear-on-save pin): the next render
        // shows the (updated) draft, not a stale working copy.
        $save = $this->container()->get(EntryController::class)->saveDraft(
            new SaveDraftData(fields: ['title' => 'S', 'body' => [
                ['id' => 'draftblk0001', 'type' => 'quote', 'data' => ['text' => 'Saved now']],
            ]], lock_version: 2),
            Request::create('/x', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}'),
            $entry,
            'en',
        );
        self::assertSame(200, $save->getStatusCode());
        self::assertNull($store->get($entry, 'en'));
        $after = $this->renderPreview($token);
        self::assertStringContainsString('Saved now', $after);
        self::assertStringNotContainsString('Applied only', $after);
    }

    public function testVersionPinnedTokensAreNeverOverlaid(): void
    {
        ['entry' => $entry, 'version' => $version] = $this->seedBlockPage('wc-pinned');
        $store = $this->container()->get(PreviewWorkingCopyStore::class);
        $store->put($entry, 'en', ['title' => 'S', 'body' => [
            ['id' => 'workingb0002', 'type' => 'quote', 'data' => ['text' => 'Applied only']],
        ]], 60);

        $pinned = $this->container()->get(PreviewMinter::class)->mint($entry, 'en', $version);
        $html = $this->renderPreview($pinned);
        // Hard requirement (spec §3): the pinned version renders, the stash never shows.
        self::assertStringContainsString('Draft only', $html);
        self::assertStringNotContainsString('Applied only', $html);
    }
}
```

Note on `lock_version: 2`: `saveDraft` bumps the lock per write — seed writes once (`lock_version` 0 → 1), publish may bump again. If the first run fails with a 409 `STALE_DRAFT`, read the `current` lock from the response and fix the literal; the assertion that matters is the 200 + cleared stash.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Integration/Render/PreviewWorkingCopyTest.php`
Expected: FAIL — "Applied only" never renders (no overlay exists yet).

- [ ] **Step 3: Implement the overlay**

In `app/Content/Delivery/EnginePublicRouteResolver.php`:

Add the import:

```php
use App\Content\Preview\PreviewWorkingCopyStore;
```

Extend the constructor (after `$logger`):

```php
        /** Loop C working-copy stash; null = no ephemeral overlay (minimal wiring). */
        private readonly ?PreviewWorkingCopyStore $workingCopies = null,
```

In `resolvePreview()`, between the successful `$read = $this->preview->read($token);` and `return $this->previewContent($read);`:

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

(The resolver is registered with `'autowire' => true` and the store is a registered shared service, so the new nullable param is container-injected. If the overlay test still shows the draft after this step, the autowirer passed null for the optional param — switch the resolver registration to an explicit factory that constructs it with `$container->get(PreviewWorkingCopyStore::class)` as the last argument.)

- [ ] **Step 4: Implement clear-on-save**

In `EntryController::saveDraft()`, immediately before the final `return Response::success(...)`:

```php
        // Clear-on-save (loop C spec §3): the DB draft now matches the working
        // tree — a stale stash must not shadow later preview refreshes.
        $this->workingCopies?->clear($uuid, $locale);
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Render/PreviewWorkingCopyTest.php tests/Integration/Content/PreviewApplyTest.php`
Expected: PASS (8 tests). Then the neighboring suites:
`vendor/bin/phpunit tests/Integration/Render/PreviewAnnotationTest.php tests/Integration/Render/PreviewSessionTest.php`
Expected: PASS — the overlay never fires without a stash, so existing preview behavior is byte-identical.

---

### Task 4: OpenAPI regen + SPA (Apply button, token retention, save decoupling)

**Files:**
- Regenerate: `docs/openapi.json` (`php glueful generate:openapi`), `admin/src/api/schema.d.ts` (`cd admin && pnpm gen:api`)
- Modify: `admin/src/queries/preview.ts` (add `applyPreview`)
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Test: `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces:**
- Consumes: Task 2's endpoint (via the regenerated typed client).
- Produces: `applyPreview(uuid: string, locale: string, token: string, fields: Record<string, unknown>): Promise<void>`; canvas `data-test` hooks `canvas-apply` (new primary) and `canvas-save` (Save draft, no reload on success).

- [ ] **Step 1: Regenerate the API types**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma && php glueful generate:openapi
cd admin && pnpm gen:api
```

Expected: `schema.d.ts` regenerated; `git diff --stat docs/openapi.json` shows the new apply path. (The regenerated `docs/openapi.json` is staged with this work in Task 5.)

- [ ] **Step 2: Add the query function**

Append to `admin/src/queries/preview.ts`:

```ts
// Loop C: apply the CURRENT working fields as an ephemeral preview — nothing
// persisted; the stage's /_preview/{token} URL then renders the working copy.
export async function applyPreview(
  uuid: string,
  locale: string,
  token: string,
  fields: Record<string, unknown>,
): Promise<void> {
  const { error, response } = await client.POST('/entries/{uuid}/preview/{locale}/apply', {
    params: { path: { uuid, locale } },
    body: { token, fields },
  })
  if (error) throw toApiError(error, response)
}
```

- [ ] **Step 3: Write the failing canvas-page tests**

In `admin/src/__tests__/canvas-page.spec.ts`:

Extend the preview mock (replace the existing `vi.mock('@/queries/preview', …)` line):

```ts
const { mintMock, applyMock } = vi.hoisted(() => ({ mintMock: vi.fn(), applyMock: vi.fn() }))
vi.mock('@/queries/preview', () => ({ mintPreviewData: mintMock, applyPreview: applyMock }))
```

(Delete the old `const { mintMock } = vi.hoisted(…)` line.) Add `applyMock.mockReset()` in `beforeEach` next to `mintMock.mockReset()`.

Update the STALE v1 test `'Save & refresh saves with lock_version then RE-MINTS and reloads the stage'` — replace it entirely with:

```ts
  it('Save draft saves with lock_version and does NOT re-mint or reload the stage', async () => {
    mintMock.mockResolvedValue({ token: 't1', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenCalledWith(expect.objectContaining({ lock_version: 3 }))
    expect(mintMock).toHaveBeenCalledTimes(1) // mount only — save never re-mints
    expect(wrapper.find('[data-test="canvas-iframe"]').element).toBe(before) // no reload
    wrapper.unmount()
  })
```

Append the new Apply tests:

```ts
  it('Apply posts token+fields, reloads the SAME stage URL, no re-mint', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(applyMock).toHaveBeenCalledWith(
      'entry0000001',
      'en',
      'tok1',
      expect.objectContaining({ title: 'T' }),
    )
    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> reloaded
    expect(mintMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('Apply on a dead token re-mints ONCE and retries', async () => {
    mintMock
      .mockResolvedValueOnce({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
      .mockResolvedValueOnce({ token: 'tok2', themeUrl: 'https://site.test/_preview/tok2' })
    applyMock
      .mockRejectedValueOnce(new ApiError('expired', 410, {}, { success: false }))
      .mockResolvedValueOnce(undefined)
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()
    expect(mintMock).toHaveBeenCalledTimes(2)
    expect(applyMock).toHaveBeenCalledTimes(2)
    expect(applyMock).toHaveBeenLastCalledWith('entry0000001', 'en', 'tok2', expect.anything())
    wrapper.unmount()
  })

  it('Apply surfaces the migration 409 with the editor-mirror banner', async () => {
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(
      new ApiError("block type 'card' has a migration in progress", 409, {}, {
        success: false,
        error: { code: 409, details: { code: 'BLOCK_MIGRATION_IN_PROGRESS', block_type: 'card' } },
      }),
    )
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(notify.warning).toHaveBeenCalledWith(
      'Block type “card” is being migrated',
      expect.any(String),
    )
    wrapper.unmount()
  })

  it('Apply failure resets the stage (mirror DOM discarded) and keeps dirty fields', async () => {
    // Review P1: a rejected Apply wrote NO stash — optimistic mirrors from the
    // stage toolbar must not survive as if they were applied.
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockRejectedValueOnce(
      new ApiError('validation failed', 422, {}, { success: false }),
    )
    const wrapper = mountPage()
    await flushPromises()
    const before = wrapper.find('[data-test="canvas-iframe"]').element

    // Structural op first (the mirror-then-reject scenario).
    bridge.callbacks.move?.('blockaaa0001', 1)
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    await flushPromises()

    const iframe = wrapper.find('[data-test="canvas-iframe"]')
    expect(iframe.attributes('src')).toBe('https://site.test/_preview/tok1') // SAME URL
    expect(iframe.element).not.toBe(before) // remounted -> mirror DOM discarded
    expect(mintMock).toHaveBeenCalledTimes(1) // no re-mint on failure
    // Dirty local fields survive: a retry save still submits the MOVED order.
    saveMock.mockResolvedValue(undefined)
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        fields: expect.objectContaining({
          body: [
            expect.objectContaining({ id: 'blockbbb0002' }),
            expect.objectContaining({ id: 'blockaaa0001' }),
          ],
        }),
      }),
    )
    wrapper.unmount()
  })
```

- [ ] **Step 4: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts`
Expected: FAIL — no `canvas-apply` hook; the updated save test fails on the re-mint assertion.

- [ ] **Step 5: Wire the canvas page**

In `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`:

**(a)** Import the new query (extend the existing preview import):

```ts
import { applyPreview, mintPreviewData } from '@/queries/preview'
```

**(b)** Retain the token — in the preview-stage section, add next to `iframeSrc`:

```ts
const previewToken = ref('')
```

and in `mintAndLoad()` set it alongside the src:

```ts
    renderDisabled.value = false
    mintFailed.value = false
    previewToken.value = mint.token
    iframeSrc.value = mint.themeUrl
```

**(c)** Replace `saveAndRefresh` with the decoupled pair — delete the old
`saveAndRefresh` function AND its `const applying = ref(false)` line (both are
superseded below); keep `const save = useSaveDraft(...)`:

```ts
// ── Apply loop (loop C spec §4): ephemeral render, nothing persisted ──────────
const applying = ref(false)
const lastApplied = ref('')

async function applyWorking(): Promise<void> {
  applying.value = true
  try {
    try {
      await applyPreview(uuid.value, locale.value, previewToken.value, fields.value)
    } catch (e: unknown) {
      // Token died mid-session (expired/invalid): re-mint ONCE and retry (spec §4).
      if (e instanceof ApiError && (e.status === 410 || e.status === 403)) {
        await mintAndLoad()
        await applyPreview(uuid.value, locale.value, previewToken.value, fields.value)
      } else {
        throw e
      }
    }
    lastApplied.value = JSON.stringify(fields.value)
    reloadStage() // same-URL reload — the stash is behind the SAME token URL
  } catch (e: unknown) {
    // Apply-failure reset (review P1): the server rejected the working tree and
    // wrote NO stash — optimistic mirror DOM (move/delete/duplicate) must not
    // keep masquerading as applied. Reload the stage back to last-applied truth;
    // local dirty fields are kept.
    reloadStage()
    if (e instanceof ApiError && apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
      const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
      warning(
        `Block type “${blockType}” is being migrated`,
        'Apply is blocked until the migration completes — try again shortly.',
      )
    } else {
      notifyError(e, 'Couldn’t apply the preview')
    }
  } finally {
    applying.value = false
  }
}

// Save persists ONLY (loop C spec §4): the stage already shows the applied
// tree, and the server clears the stash — no re-mint, no reload on success.
const saving = ref(false)

async function saveDraftOnly(): Promise<void> {
  saving.value = true
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    success('Draft saved')
  } catch (e: unknown) {
    reloadStage() // discard optimistic mirrors — the stage falls back to last-applied truth
    // BYTE-MIRROR of the editor's onSave 409 branches.
    if (e instanceof ApiError && e.status === 409) {
      if (apiErrorCode(e) === 'BLOCK_MIGRATION_IN_PROGRESS') {
        const blockType = String(apiErrorDetails(e)?.block_type ?? 'a block type')
        warning(
          `Block type “${blockType}” is being migrated`,
          'Saving is blocked until the migration completes — try again shortly.',
        )
      } else {
        warning(
          'This draft changed elsewhere',
          'Reload to get the latest version before saving again.',
        )
      }
    } else {
      notifyError(e, 'Couldn’t save draft')
    }
  } finally {
    saving.value = false
  }
}

const stageStale = computed(() => JSON.stringify(fields.value) !== lastApplied.value)
```

Initialize `lastApplied` in the draft-hydration watcher (the stage's first render shows the draft):

```ts
    if (d && d.lock_version !== hydratedLock) {
      fields.value = { ...d.fields }
      lockVersion.value = d.lock_version
      hydratedLock = d.lock_version
      if (lastApplied.value === '') lastApplied.value = JSON.stringify(d.fields)
    }
```

**(d)** Template — replace the Save & refresh chip block with the pair:

```html
          <UChip :show="stageStale" color="info" inset>
            <UButton :loading="applying" data-test="canvas-apply" @click="applyWorking()">
              Apply
            </UButton>
          </UChip>
          <UChip :show="dirty" color="warning" inset>
            <UButton
              variant="outline"
              color="neutral"
              :loading="saving"
              data-test="canvas-save"
              @click="saveDraftOnly()"
            >
              Save draft
            </UButton>
          </UChip>
```

**(e)** Update the add-after picker heading copy in the same file from `Add block after` to `Add block after (visible on next Apply)`.

- [ ] **Step 6: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS (the v2 save-failure reset test keeps passing — `saveDraftOnly` kept the reset + banner branches), type-check clean.

---

### Task 5: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md` (one sentence in the canvas paragraph)
- Modify: `CHANGELOG.md` (`[Unreleased]` bullet)

- [ ] **Step 1: README**

In `packages/lemma-render/README.md`, append to the canvas toolbar paragraph (added in the stage-toolbar work):

```markdown
With the admin's Apply action, the preview session can also render the
editor's *unsaved* working tree: the app validates and stashes it (cache-only,
TTL-bounded) and the same `/_preview/{token}` URL overlays it over the draft —
version-pinned previews are never overlaid.
```

- [ ] **Step 2: CHANGELOG**

Append to `[Unreleased]` after the canvas stage toolbar bullet:

```markdown
- Ephemeral preview render (loop C): the Design view's primary action is now
  Apply — the working block tree is validated with the exact draft-save guard
  set (block-migration gate included) and stashed in cache, and the stage
  reloads its same preview URL to render unsaved work instantly. Save draft
  persists as before and clears the stash; version-pinned preview tokens can
  neither write nor read a working copy (409 `PREVIEW_VERSION_PINNED`).
```

- [ ] **Step 3: Full verification (all gates)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"          # expect PHPCS_EXIT=0
composer boundaries                                 # expect "Pack boundaries OK"
vendor/bin/phpunit --testsuite Unit                 # expect OK
vendor/bin/phpunit --testsuite Integration          # expect OK (1 pre-existing skip)
cd admin && pnpm type-check && pnpm test            # expect clean + all pass
```

- [ ] **Step 4: STAGE (commit only when authorized)**

```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
git add \
  app/Content/Preview/PreviewWorkingCopyStore.php \
  app/Content/Http/DTOs/ApplyPreviewData.php \
  app/Content/Http/Controllers/EntryController.php \
  app/Content/Delivery/EnginePublicRouteResolver.php \
  app/Providers/LemmaServiceProvider.php \
  routes/lemma_admin.php \
  tests/Integration/Content/PreviewApplyTest.php \
  tests/Integration/Render/PreviewWorkingCopyTest.php \
  admin/src/queries/preview.ts \
  admin/src/api/schema.d.ts \
  "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
  admin/src/__tests__/canvas-page.spec.ts \
  docs/openapi.json \
  packages/lemma-render/README.md \
  CHANGELOG.md \
  docs/superpowers
git status --short
```

Then STOP and report, awaiting explicit commit authorization. Prepared message:

```
feat(content): ephemeral preview render — Apply unsaved work to the canvas stage

- POST /entries/{uuid}/preview/{locale}/apply: exact saveDraft guard order
  (migration gate -> validator), stashes the CLEANED fields (cache-only,
  TTL <= 300s, 1MB cap); version-pinned tokens 409 PREVIEW_VERSION_PINNED
- /_preview/{token} overlays the working copy for draft-mode tokens only;
  saveDraft success clears the stash
- Canvas: Apply (instant ephemeral render) + Save draft (persist only, no
  re-mint/reload); dead-token applies re-mint once and retry
```

Recorded manual/browser acceptance (report as outstanding): perceived apply latency on a real theme, add-after visible pre-save, two-tab collision behavior (shared stash last-writer-wins), stash expiry mid-session — plus the earlier v1/v2 items.
