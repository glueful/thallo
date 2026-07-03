# Canvas v3: Edit-in-Place Text — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Double-click a prose block in the canvas stage to type directly into its rendered rich field; text flows back to the canonical tree on a debounce and reaches the server only at Apply/Save.

**Architecture:** The renderer marks prose rich-field output at the `safe_html` seam (gated by a new soft-bound `BlockEditableFieldResolver` contract + a per-block frame stack in `blocks()`). The bridge runs a dumb edit session behind an edit-request/edit-grant protocol with a deterministic pre-Apply flush ack. The SPA patches the tree via `patchBlockData` routed through `FieldEditor`.

**Tech Stack:** PHP 8.3 (Glueful render pack + contracts package), vanilla-JS bridge asset, Vue 3 admin, vitest (incl. the jsdom direct-eval bridge suite).

**Spec:** `docs/superpowers/specs/2026-07-03-canvas-edit-in-place-design.md`

## Global Constraints

- **Prose-gated marking (review pin):** `safe_html` wraps ONLY when annotations are on AND the current block frame has a non-null `editable_field`. Non-prose blocks using `safe_html` produce no markers at all. Live renders carry nothing.
- **Prose convention (both sides, byte-mirrored):** schema of EXACTLY one field with `type === 'text' && format === 'rich'` → that field's name; anything else → null. Client source of truth: `proseRichFieldName` in `admin/src/fields/components/blocks/proseDetection.ts`.
- **Contract soft-binding:** the render pack depends only on `Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver` (null → no marking); the app implements it. `composer boundaries` guards the pack.
- **CSP pin:** no inline styles anywhere — editing ring/cursor styles are static `preview.css` classes.
- **Flush ack:** the bridge replies `lemma:edit-flushed` unconditionally to `lemma:edit-flush`; the parent awaits it with a 200ms timeout fallback before Apply reads `fields.value`.
- **Sanitization unchanged:** typed HTML enters the tree raw and is sanitized at Apply/Save (`FieldValidator`) and at render (`safe_html`).
- **Nonce discipline:** all new messages ride the v1 envelope.
- **Commit gate:** STAGE at the end of Task 4 only; commit ONLY on explicit authorization. No attribution trailers.
- **Verification:** PHP gates from the lemma repo root; admin `cd admin && pnpm type-check && pnpm test`. No OpenAPI changes (no new endpoints).

---

### Task 1: Contract + app resolver + renderer marking

**Files:**
- Create: `packages/lemma-contracts/src/Content/BlockEditableFieldResolver.php`
- Create: `app/Content/Blocks/EngineBlockEditableFieldResolver.php`
- Modify: `app/Providers/LemmaServiceProvider.php` (binding)
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (frame stack, ctor param, `safeHtml` wrap)
- Modify: `packages/lemma-render/src/LemmaRenderServiceProvider.php` (`makeRenderContextExtension` soft-binding)
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (reset-family call)
- Test: `tests/Integration/Render/EditInPlaceMarkingTest.php` (new)

**Interfaces:**
- Produces (Tasks 2–3 rely on the DOM shape): annotated preview renders of prose blocks contain
  `<div class="lemma-edit-region" data-lemma-edit-block="{id}" data-lemma-edit-field="{field}">…</div>`
  wrapping the sanitized rich HTML; nothing else is ever marked.
- Contract: `interface BlockEditableFieldResolver { public function editableRichField(string $typeSlug): ?string; }`

- [ ] **Step 1: Write the failing tests**

Create `tests/Integration/Render/EditInPlaceMarkingTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Blocks\EngineBlockEditableFieldResolver;
use App\Content\Preview\PreviewMinter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Edit-in-place spec §2: safe_html marks prose rich-field output in ANNOTATED
 * renders only — both data attributes present, never in live renders, never
 * for non-prose blocks. The resolver mirrors the client prose convention.
 */
final class EditInPlaceMarkingTest extends LemmaTestCase
{
    private string $type;

    protected function tearDown(): void
    {
        $this->container()->get(\Glueful\Cache\CacheStore::class)->deletePattern('render:*');
        $this->container()->get(\Glueful\Lemma\Seo\Cache\SitemapCache::class)->forgetAll();
        parent::tearDown();
    }

    public function testResolverMirrorsTheProseConvention(): void
    {
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
        ]);
        $repo->create([
            'slug' => 'promo', // two fields -> NOT prose
            'label' => 'Promo',
            'schema' => [
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
                ['name' => 'title', 'type' => 'string'],
            ],
        ]);
        $repo->create([
            'slug' => 'quote', // single field but plain text -> NOT prose
            'label' => 'Quote',
            'schema' => [['name' => 'text', 'type' => 'text']],
        ]);
        $resolver = new EngineBlockEditableFieldResolver($repo);
        self::assertSame('body', $resolver->editableRichField('rich_text'));
        self::assertNull($resolver->editableRichField('promo'));
        self::assertNull($resolver->editableRichField('quote'));
        self::assertNull($resolver->editableRichField('missing'));
    }

    public function testPreviewMarksProseRegionsAndLiveDoesNot(): void
    {
        // rich_text matches the starter theme's blocks/rich_text.twig, which
        // emits the field through safe_html — the marking seam under test.
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
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
            ['id' => 'proseblk0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Hello prose</p>']],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', 'eip-page');

        // ANNOTATED (direct token) render: region wrapper with BOTH attributes.
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $preview = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('Hello prose', $preview);
        self::assertStringContainsString('class="lemma-edit-region"', $preview);
        self::assertStringContainsString('data-lemma-edit-block="proseblk0001"', $preview);
        self::assertStringContainsString('data-lemma-edit-field="body"', $preview);
    }

    public function testNestedProseInsideAContainerGetsItsOwnFrame(): void
    {
        // The frame STACK under test: section.twig calls blocks(data.content),
        // so the nested rich_text renders inside the parent's frame scope —
        // its region must carry the NESTED id, and the section itself (not
        // prose) must never be marked.
        $repo = new BlockTypeRepository($this->connection());
        $repo->create([
            'slug' => 'rich_text',
            'label' => 'Text',
            'schema' => [['name' => 'body', 'type' => 'text', 'format' => 'rich']],
        ]);
        $repo->create([
            'slug' => 'section',
            'label' => 'Section',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'content', 'type' => 'blocks'],
            ],
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
            ['id' => 'sectionb0001', 'type' => 'section', 'data' => [
                'title' => 'Wrap',
                'content' => [
                    ['id' => 'nestedpr0001', 'type' => 'rich_text', 'data' => ['body' => '<p>Nested prose</p>']],
                ],
            ]],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', 'eip-nested');

        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('data-lemma-edit-block="nestedpr0001"', $html);
        self::assertStringNotContainsString('data-lemma-edit-block="sectionb0001"', $html);
    }
}
```

(A live-render absence assertion needs a published entry — extend the second test after the preview assertions:)

```php
        // LIVE render: publish, request the public path, assert NO marking.
        $version = (new \App\Content\Services\PublishService(
            $this->appContext(),
            $entries,
            new \App\Content\Repositories\VersionRepository($this->connection()),
            $types,
            new \App\Content\Validation\FieldValidator(
                $this->connection(),
                $this->appContext(),
                new BlockTypeRepository($this->connection()),
            ),
            new \App\Content\Repositories\ReferenceProjectionRepository($this->connection()),
        ))->publish($entry, 'en', 'user00000001');
        self::assertNotSame('', $version);
        $live = $this->handle(Request::create('/page/eip-page', 'GET'));
        $liveHtml = (string) $live->getContent();
        self::assertStringContainsString('Hello prose', $liveHtml);
        self::assertStringNotContainsString('lemma-edit-region', $liveHtml);
        self::assertStringNotContainsString('data-lemma-edit-block', $liveHtml);
```

- [ ] **Step 2: Run to verify failure**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/EditInPlaceMarkingTest.php`
Expected: ERROR — `EngineBlockEditableFieldResolver` not found.

- [ ] **Step 3: Create the contract**

Create `packages/lemma-contracts/src/Content/BlockEditableFieldResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Resolves the ONE in-place-editable rich field of a block type, or null when
 * the type is not prose-shaped (edit-in-place spec §1–§2). Consumed soft-bound
 * by the render pack's safe_html marking; implemented by the content engine
 * over block-type schemas. The convention (exactly one `text`/`format: rich`
 * field) is NOT a stable identity contract — when `editor_mode` metadata
 * lands, implementations consult it first.
 */
interface BlockEditableFieldResolver
{
    public function editableRichField(string $typeSlug): ?string;
}
```

- [ ] **Step 4: Create the app resolver**

Create `app/Content/Blocks/EngineBlockEditableFieldResolver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks;

use Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver;

/**
 * Server-side mirror of the client prose convention (edit-in-place spec §1;
 * admin proseDetection.ts is the byte-for-byte reference): a block type whose
 * schema is EXACTLY one `text` field with `format: rich` is prose, and that
 * field is in-place editable. Reads through the repository's per-request
 * schema memo, so per-block resolution during a render is cheap.
 */
final class EngineBlockEditableFieldResolver implements BlockEditableFieldResolver
{
    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    public function editableRichField(string $typeSlug): ?string
    {
        $schema = $this->blockTypes->schemasBySlug()[$typeSlug] ?? null;
        if ($schema === null) {
            return null;
        }
        $fields = $schema->fields();
        if (count($fields) !== 1) {
            return null;
        }
        $only = $fields[0];
        return $only->type() === 'text' && $only->format() === 'rich' ? $only->name() : null;
    }
}
```

(`ContentTypeSchema::fields()` returns `list<FieldDefinition>` — index `[0]` is safe after the count check.)

- [ ] **Step 5: Bind in the app provider**

In `app/Providers/LemmaServiceProvider.php`, add imports:

```php
use App\Content\Blocks\EngineBlockEditableFieldResolver;
use Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver;
```

In `services()`, next to the `RichHtmlSanitizer` binding:

```php
            BlockEditableFieldResolver::class => [
                'class' => EngineBlockEditableFieldResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 6: Renderer — frame stack + prose-gated marking**

In `packages/lemma-render/src/RenderContextExtension.php`:

Add the import:

```php
use Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver;
```

Add state next to `$annotateBlocks`:

```php
    /**
     * Per-block frame stack (edit-in-place spec §2): pushed around each block
     * template render so safe_html knows WHICH block instance it is emitting
     * for — and whether that block is prose (editable_field non-null). A stack,
     * not a scalar: nested blocks() calls run inside parent templates.
     * Reset-family (resetBlockFrames): cleared before every render.
     *
     * @var list<array{id: mixed, editable_field: ?string}>
     */
    private array $blockFrames = [];
```

Extend the constructor (after `$mediaUrls`):

```php
        /** Soft-bound (edit-in-place spec §2): null → safe_html never marks. */
        private readonly ?BlockEditableFieldResolver $editableFields = null,
```

In `blocks()`, wrap the render call (replace the `$rendered = $env->render(...)` statement):

```php
                $this->blockFrames[] = [
                    'id' => $item['id'] ?? null,
                    // Resolved ONLY when annotating: live renders never consult
                    // the resolver, and non-prose blocks get a null field.
                    'editable_field' => $this->annotateBlocks
                        ? $this->editableFields?->editableRichField($type)
                        : null,
                ];
                try {
                    $rendered = $env->render($template, [
                        'block' => ['id' => $item['id'] ?? null, 'type' => $type, 'data' => $data],
                        'data' => $data,
                        'entry' => $entry,
                        'index' => $index,
                    ]);
                } finally {
                    array_pop($this->blockFrames);
                }
```

Extend `safeHtml()` — replace its body's two return paths with a marked wrapper:

```php
    public function safeHtml(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if ($this->htmlSanitizer !== null) {
            try {
                return $this->markEditable($this->htmlSanitizer->sanitize($value));
            } catch (\Throwable) {
                // fall through to the escaped fallback
            }
        }
        return $this->markEditable(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * Edit-in-place marking (spec §2): wraps safe_html OUTPUT with the editable
     * region ONLY when annotations are on AND the current block frame is prose
     * (editable_field non-null) AND the instance has a string id. Non-prose
     * blocks using safe_html produce no markers at all (review pin).
     */
    private function markEditable(string $safe): string
    {
        if (!$this->annotateBlocks || $this->blockFrames === []) {
            return $safe;
        }
        $frame = $this->blockFrames[count($this->blockFrames) - 1];
        if (!is_string($frame['id']) || $frame['editable_field'] === null) {
            return $safe;
        }
        return '<div class="lemma-edit-region" data-lemma-edit-block="'
            . htmlspecialchars($frame['id'], ENT_QUOTES)
            . '" data-lemma-edit-field="'
            . htmlspecialchars($frame['editable_field'], ENT_QUOTES)
            . '">' . $safe . '</div>';
    }
```

Add the reset-family method next to `resetBlockDepth()`:

```php
    /** Reset-family: an escaped exception must not leak frames into the next render. */
    public function resetBlockFrames(): void
    {
        $this->blockFrames = [];
    }
```

- [ ] **Step 7: Wire the soft binding + reset call**

In `packages/lemma-render/src/LemmaRenderServiceProvider.php` `makeRenderContextExtension()`, add as the last constructor argument (import `Glueful\Lemma\Contracts\Content\BlockEditableFieldResolver`):

```php
            // Edit-in-place marking (spec §2): soft-bound; null = never marks.
            $container->has(BlockEditableFieldResolver::class)
                ? $container->get(BlockEditableFieldResolver::class)
                : null,
```

In `packages/lemma-render/src/Http/Controllers/RenderController.php`, in the render() reset block where `resetBlockDepth()` / `setBlockAnnotations(...)` are called, add:

```php
        $this->extension->resetBlockFrames();
```

- [ ] **Step 8: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Render/EditInPlaceMarkingTest.php && vendor/bin/phpunit tests/Integration/Render/ 2>&1 | tail -2 && composer boundaries`
Expected: PASS, full render suite green, boundaries OK (the pack imports only the contract).

---

### Task 2: Bridge editing session + direct jsdom tests

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/assets/preview/preview.css`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (extend)

**Interfaces:**
- Consumes: Task 1's DOM shape (`.lemma-edit-region` with both data attributes).
- Produces (Task 3's counterpart messages): outbound `lemma:edit-request {id}`, `lemma:text-changed {id, field, html}`, `lemma:edit-end {id}`, `lemma:edit-flushed`; inbound `lemma:edit-grant {id, field}`, `lemma:edit-flush`.

- [ ] **Step 1: Write the failing direct tests**

Append to `admin/src/__tests__/preview-bridge-dom.spec.ts` (reuse `wrapper()`/`sendToBridge()`/`lastPost()`; build prose wrappers with the marked region):

```ts
function proseWrapper(id: string, field = 'body', html = '<p>hello</p>'): HTMLElement {
  return wrapper(
    id,
    `<section><div class="lemma-edit-region" data-lemma-edit-block="${id}" ` +
      `data-lemma-edit-field="${field}">${html}</div></section>`,
  )
}

describe('edit-in-place session', () => {
  it('double-click posts edit-request; grant enables contenteditable on the ONE region', () => {
    const w = proseWrapper('eip-a-000001')
    document.body.appendChild(w)
    w.querySelector('p')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'eip-a-000001' })

    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-a-000001', field: 'body' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('contenteditable')).toBe('true')
    expect(region.classList.contains('lemma-canvas-editing')).toBe(true)
    // Toolbar detached for the duration (block may have been selected before).
    expect(w.querySelector('.lemma-canvas-toolbar')).toBeNull()
    // Escape commits and ends.
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
      id: 'eip-a-000001',
      field: 'body',
      html: '<p>hello</p>',
    })
    expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'eip-a-000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('grant field mismatch or multiple regions -> no editing (fail-safe)', () => {
    const w = proseWrapper('eip-b-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-b-000001', field: 'other' })
    expect(w.querySelector('[contenteditable]')).toBeNull()

    const two = wrapper(
      'eip-c-000001',
      '<section>' +
        '<div class="lemma-edit-region" data-lemma-edit-block="eip-c-000001" data-lemma-edit-field="body">a</div>' +
        '<div class="lemma-edit-region" data-lemma-edit-block="eip-c-000001" data-lemma-edit-field="body">b</div>' +
        '</section>',
    )
    document.body.appendChild(two)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-c-000001', field: 'body' })
    expect(two.querySelector('[contenteditable]')).toBeNull()
  })

  it('typing commits on the debounce; clicks INSIDE the region are not swallowed', () => {
    vi.useFakeTimers()
    try {
      const w = proseWrapper('eip-d-000001')
      document.body.appendChild(w)
      sendToBridge({ type: 'lemma:edit-grant', id: 'eip-d-000001', field: 'body' })
      const region = w.querySelector('.lemma-edit-region')!
      region.innerHTML = '<p>typed</p>'
      region.dispatchEvent(new Event('input', { bubbles: true }))
      vi.advanceTimersByTime(450)
      expect(lastPost('lemma:text-changed')).toMatchObject({ html: '<p>typed</p>' })

      // Caret-placement click inside the ACTIVE region passes through.
      const inside = new MouseEvent('click', { bubbles: true, cancelable: true })
      region.querySelector('p')!.dispatchEvent(inside)
      expect(inside.defaultPrevented).toBe(false)

      // A click OUTSIDE commits and exits, then behaves as v2 (select).
      posted.mockClear()
      const other = wrapper('eip-e-000001')
      document.body.appendChild(other)
      other.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))
      expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'eip-d-000001' })
      expect(lastPost('lemma:block-select')).toMatchObject({ id: 'eip-e-000001' })
      expect(region.getAttribute('contenteditable')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('edit-flush commits an active session and ALWAYS acks with edit-flushed', () => {
    // No active session: ack only.
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-flush' })
    expect(lastPost('lemma:edit-flushed')).toBeDefined()
    expect(lastPost('lemma:text-changed')).toBeUndefined()

    // Active session: final text-changed + edit-end BEFORE the ack.
    const w = proseWrapper('eip-f-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-f-000001', field: 'body' })
    const region = w.querySelector('.lemma-edit-region')!
    region.innerHTML = '<p>flush me</p>'
    posted.mockClear()
    sendToBridge({ type: 'lemma:edit-flush' })
    const types = posted.mock.calls.map((c) => (c[0] as { type: string }).type)
    expect(types).toEqual(['lemma:text-changed', 'lemma:edit-end', 'lemma:edit-flushed'])
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('mirror-duplicate clones never carry contenteditable or the editing class', () => {
    const w = proseWrapper('eip-g-000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-g-000001', field: 'body' })
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'eip-g-000001',
      idMap: { 'eip-g-000001': 'eip-h-000002' },
    })
    const copy = document.querySelector('[data-lemma-block="eip-h-000002"]')!
    expect(copy.querySelector('[contenteditable]')).toBeNull()
    expect(copy.querySelector('.lemma-canvas-editing')).toBeNull()
  })

  it('a DUPLICATED prose block is immediately editable under its NEW id (review P1)', () => {
    const w = proseWrapper('eip-i-000001')
    document.body.appendChild(w)
    sendToBridge({
      type: 'lemma:mirror-duplicate',
      sourceId: 'eip-i-000001',
      idMap: { 'eip-i-000001': 'eip-j-000002' },
    })
    const copy = document.querySelector('[data-lemma-block="eip-j-000002"]')!
    // The edit region's id was rewritten alongside the wrapper's — without
    // this, edit-grant for the new id can never find its region until the
    // next Apply re-renders truth.
    const region = copy.querySelector('.lemma-edit-region')!
    expect(region.getAttribute('data-lemma-edit-block')).toBe('eip-j-000002')
    sendToBridge({ type: 'lemma:edit-grant', id: 'eip-j-000002', field: 'body' })
    expect(region.getAttribute('contenteditable')).toBe('true')
    sendToBridge({ type: 'lemma:edit-flush' }) // clean up the session for later tests
  })
})
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: FAIL — no edit-request/grant handling exists.

- [ ] **Step 3: Implement the bridge session**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

Add state next to `selectedId`:

```js
  var editing = null // { id, field, region, debounce }
  var lastPointer = null // { x, y } of the granting double-click (caret placement)
```

Add the session functions after `clearSelection()`:

```js
  // ── Edit-in-place session (edit-in-place spec §3) ───────────────────────────
  function regionFor(id) {
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll('.lemma-edit-region[data-lemma-edit-block="' + cssEscape(id) + '"]')
    return regions.length === 1 ? regions[0] : null // one-region rule (fail-safe)
  }

  function commitEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    post('text-changed', { id: editing.id, field: editing.field, html: editing.region.innerHTML })
  }

  function endEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.region.removeAttribute('contenteditable')
    editing.region.classList.remove('lemma-canvas-editing')
    editing.region.removeEventListener('input', onEditInput)
    editing.region.removeEventListener('blur', onEditBlur)
    editing.region.removeEventListener('keydown', onEditKeydown)
    var id = editing.id
    editing = null
    post('edit-end', { id: id })
  }

  function onEditInput() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    editing.debounce = setTimeout(commitEditing, 400)
  }

  function onEditBlur() {
    commitEditing()
    endEditing()
  }

  function onEditKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault()
      commitEditing()
      endEditing()
    }
  }

  function startEditing(id, field) {
    if (editing) return
    var region = regionFor(id)
    // Field sanity check (spec §3): defense in depth against a stale grant.
    if (!region || region.getAttribute('data-lemma-edit-field') !== field) return
    detachToolbar()
    editing = { id: id, field: field, region: region, debounce: null }
    region.setAttribute('contenteditable', 'true')
    region.classList.add('lemma-canvas-editing')
    region.addEventListener('input', onEditInput)
    region.addEventListener('blur', onEditBlur)
    region.addEventListener('keydown', onEditKeydown)
    region.focus()
    // Caret at the double-click point (spec pin) — best-effort: jsdom and old
    // engines lack caretRangeFromPoint; focus() alone is the fallback.
    if (lastPointer && document.caretRangeFromPoint) {
      var range = document.caretRangeFromPoint(lastPointer.x, lastPointer.y)
      if (range && region.contains(range.startContainer)) {
        var sel = window.getSelection()
        if (sel) {
          sel.removeAllRanges()
          sel.addRange(range)
        }
      }
    }
  }
```

In `stripCanvasState()`, add after the class loop:

```js
    Array.prototype.forEach.call(root.querySelectorAll('[contenteditable]'), function (el) {
      el.removeAttribute('contenteditable')
    })
    Array.prototype.forEach.call(root.querySelectorAll('.lemma-canvas-editing'), function (el) {
      el.classList.remove('lemma-canvas-editing')
    })
```

In `mirrorDuplicate()`, after the existing `[data-lemma-block]` rewrite loop, add
the edit-region rewrite (review P1 — without it a duplicated prose block's
region keeps the SOURCE id and `edit-grant` for the new id can never find it):

```js
    Array.prototype.forEach.call(clone.querySelectorAll('[data-lemma-edit-block]'), function (el) {
      var mappedEdit = idMap[el.getAttribute('data-lemma-edit-block')]
      if (mappedEdit) el.setAttribute('data-lemma-edit-block', mappedEdit)
    })
```

In `activate()`, add a double-click listener before the click listener:

```js
    document.addEventListener('dblclick', function (e) {
      if (editing) return
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      lastPointer = { x: e.clientX, y: e.clientY }
      post('edit-request', { id: w.getAttribute('data-lemma-block') })
    }, true)
```

In the capture-phase click listener, add the carve-out as the FIRST branch:

```js
      if (editing) {
        // Caret placement inside the active region passes through untouched;
        // any click outside commits-and-exits, then v2 semantics resume.
        if (editing.region.contains(e.target)) return
        commitEditing()
        endEditing()
      }
```

In the message listener, add the inbound branches (before the mirror branches):

```js
    if (data.type === 'lemma:edit-grant' && typeof data.id === 'string' && typeof data.field === 'string') {
      startEditing(data.id, data.field)
    }
    if (data.type === 'lemma:edit-flush') {
      if (editing) {
        commitEditing()
        endEditing()
      }
      post('edit-flushed') // ALWAYS ack (spec §3) — the parent awaits this
    }
```

- [ ] **Step 4: Styles**

Append to `packages/lemma-render/assets/preview/preview.css`:

```css
/* Edit-in-place (edit-in-place spec §3): the active region swaps the selection
   ring for an editing style. Static rules only (CSP pin). */
.lemma-canvas-editing {
  outline: 2px solid rgba(16, 185, 129, 0.9);
  outline-offset: 2px;
  cursor: text;
}
.lemma-canvas-editing:focus { outline-color: rgba(16, 185, 129, 1); }
```

- [ ] **Step 5: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (12 tests: 7 existing + 5 new).

---

### Task 3: SPA — patch routing, composable messages, canvas wiring

**Files:**
- Modify: `admin/src/fields/components/BlocksField.vue` (`patchBlockData`, `blockTypeById`)
- Modify: `admin/src/components/FieldEditor.vue` (routing)
- Modify: `admin/src/composables/useCanvasBridge.ts` (edit messages + flush promise)
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue` (grant/patch wiring, flush before Apply)
- Test: `admin/src/__tests__/blocksField.spec.ts`, `admin/src/__tests__/canvas-bridge.spec.ts`, `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces:**
- Consumes: Task 2's message shapes; existing `ops.patchDataById`, `proseRichFieldName`.
- Produces:
  - `BlocksField` exposed: `patchBlockData(id: string, field: string, value: unknown): boolean`, `blockTypeById(id: string): string | null`.
  - `FieldEditor` exposed: `patchBlockDataById`, `blockTypeOfBlock` — same routing pattern.
  - `useCanvasBridge`: `onEditRequest(cb: (id: string) => void)`, `onTextChanged(cb: (id: string, field: string, html: string) => void)`, `editGrant(id: string, field: string): void`, `editFlush(): Promise<void>` (resolves on `lemma:edit-flushed` or after 200ms).

- [ ] **Step 1: Write the failing tests**

**(a)** `blocksField.spec.ts` — append inside the BlocksField describe:

```ts
  it('patchBlockData patches one field through the tree; blockTypeById resolves types', async () => {
    let model: { id: string; type: string; data: Record<string, unknown> }[] = [
      { id: 'aaa000000001', type: 'quote', data: { text: 'A' } },
    ]
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model,
        'onUpdate:modelValue': (v: typeof model) => (model = v),
      },
    })
    await flushPromises()
    const api = wrapper.vm as unknown as {
      patchBlockData: (id: string, f: string, v: unknown) => boolean
      blockTypeById: (id: string) => string | null
    }
    expect(api.blockTypeById('aaa000000001')).toBe('quote')
    expect(api.blockTypeById('missing')).toBeNull()
    expect(api.patchBlockData('aaa000000001', 'text', '<p>typed</p>')).toBe(true)
    expect(model[0]!.data.text).toBe('<p>typed</p>')
    expect(api.patchBlockData('missing', 'text', 'x')).toBe(false)
    wrapper.unmount()
  })
```

**(b)** `canvas-bridge.spec.ts` — append to the `useCanvasBridge` describe:

```ts
  it('edit messages: grant posts, request/text-changed dispatch, flush resolves on ack', async () => {
    const postSpy = vi.fn()
    const iframe = ref({
      src: 'https://site.test/_preview/tok123',
      contentWindow: { postMessage: postSpy },
    } as unknown as HTMLIFrameElement)
    const bridge = useCanvasBridge(iframe as Ref<HTMLIFrameElement | null>)
    const req = vi.fn()
    const text = vi.fn()
    bridge.onEditRequest(req)
    bridge.onTextChanged(text)

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:edit-request', id: 'b1', nonce: bridge.nonce },
      }),
    )
    expect(req).toHaveBeenCalledWith('b1')

    bridge.editGrant('b1', 'body')
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:edit-grant', id: 'b1', field: 'body', nonce: bridge.nonce },
      'https://site.test',
    )

    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:text-changed', id: 'b1', field: 'body', html: '<p>x</p>', nonce: bridge.nonce },
      }),
    )
    expect(text).toHaveBeenCalledWith('b1', 'body', '<p>x</p>')

    // Flush resolves on the ack (no timers needed).
    const flushed = bridge.editFlush()
    window.dispatchEvent(
      new MessageEvent('message', { data: { type: 'lemma:edit-flushed', nonce: bridge.nonce } }),
    )
    await expect(flushed).resolves.toBeUndefined()
    bridge.dispose()
  })

  it('editFlush falls back to the 200ms timeout when no bridge answers', async () => {
    vi.useFakeTimers()
    try {
      const bridge = useCanvasBridge(ref(null))
      const flushed = bridge.editFlush()
      vi.advanceTimersByTime(250)
      await expect(flushed).resolves.toBeUndefined()
      bridge.dispose()
    } finally {
      vi.useRealTimers()
    }
  })
```

Append to the FieldEditor describe:

```ts
  it('routes patchBlockDataById and blockTypeOfBlock to the owning field', async () => {
    await warmBlocksField()
    const wrapper = mountEditor()
    await flushPromises()
    const api = wrapper.vm as unknown as {
      patchBlockDataById: (id: string, f: string, v: unknown) => boolean
      blockTypeOfBlock: (id: string) => string | null
    }
    expect(api.patchBlockDataById('missing', 'x', 1)).toBe(false)
    expect(api.blockTypeOfBlock('missing')).toBeNull()
    expect(api.blockTypeOfBlock('inside000001')).toBe('card')
    expect(api.patchBlockDataById('inside000001', 'title', 'patched')).toBe(true)
    wrapper.unmount()
  })
```

**(c)** `canvas-page.spec.ts` — extend the bridge mock's callbacks/instance:

```ts
      editRequest?: (id: string) => void
      textChanged?: (id: string, field: string, html: string) => void
```

(in the callbacks type), and in `instance`:

```ts
      onEditRequest: (cb: (id: string) => void) => (callbacks.editRequest = cb),
      onTextChanged: (cb: (id: string, field: string, html: string) => void) =>
        (callbacks.textChanged = cb),
      editGrant: vi.fn(),
      editFlush: vi.fn().mockResolvedValue(undefined),
```

Add `bridge.instance.editGrant.mockClear()` in `beforeEach`. The `blockTypes` mock ref and draft fixture gain a prose type + block — in `beforeEach` set:

```ts
  blockTypes.value = [
    bt('card'),
    {
      ...bt('rich_text'),
      schema: [
        { name: 'body', type: 'text', format: 'rich', required: false, localized: false, filterable: false },
      ],
    } as BlockType,
  ]
  draft.value = {
    fields: {
      title: 'T',
      body: [
        { id: 'blockaaa0001', type: 'card', data: { title: 'A' } },
        { id: 'blockbbb0002', type: 'card', data: { title: 'B' } },
        { id: 'prose0000003', type: 'rich_text', data: { body: '<p>old</p>' } },
      ],
    },
    lock_version: 3,
  }
```

New tests:

```ts
  it('edit-request grants ONLY for prose blocks, with the rich field name', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.editRequest?.('prose0000003')
    await flushPromises()
    expect(bridge.instance.editGrant).toHaveBeenCalledWith('prose0000003', 'body')

    bridge.instance.editGrant.mockClear()
    bridge.callbacks.editRequest?.('blockaaa0001') // card: NOT prose
    bridge.callbacks.editRequest?.('missing')
    await flushPromises()
    expect(bridge.instance.editGrant).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('text-changed for a wrong field or a non-prose block is IGNORED (review P1)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    // Wrong field on a prose block; any field on a non-prose block: no patch.
    bridge.callbacks.textChanged?.('prose0000003', 'title', '<p>evil</p>')
    bridge.callbacks.textChanged?.('blockaaa0001', 'title', '<p>evil</p>')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls.at(-1)![0] as {
      fields: { body: { id: string; data: Record<string, unknown> }[] }
    }
    expect(saved.fields.body.find((b) => b.id === 'prose0000003')!.data.body).toBe('<p>old</p>')
    expect(saved.fields.body.find((b) => b.id === 'blockaaa0001')!.data.title).toBe('A')
    wrapper.unmount()
  })

  it('text-changed patches the tree (visible in the next save payload)', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.textChanged?.('prose0000003', 'body', '<p>typed in stage</p>')
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    expect(saveMock).toHaveBeenLastCalledWith(
      expect.objectContaining({
        fields: expect.objectContaining({
          body: expect.arrayContaining([
            expect.objectContaining({
              id: 'prose0000003',
              data: expect.objectContaining({ body: '<p>typed in stage</p>' }),
            }),
          ]),
        }),
      }),
    )
    wrapper.unmount()
  })

  it('Apply awaits the flush and the FINAL flushed text reaches the apply payload', async () => {
    // Review P2: order alone is not the risk — the last sub-debounce keystroke
    // is. The mocked flush delivers a final text-changed BEFORE resolving, the
    // way the real bridge commits during lemma:edit-flush; Apply must read the
    // tree AFTER that commit landed.
    mintMock.mockResolvedValue({ token: 'tok1', themeUrl: 'https://site.test/_preview/tok1' })
    applyMock.mockResolvedValue(undefined)
    bridge.instance.editFlush.mockImplementationOnce(async () => {
      bridge.callbacks.textChanged?.('prose0000003', 'body', '<p>final keystroke</p>')
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.find('[data-test="canvas-apply"]').trigger('click')
    await flushPromises()
    expect(bridge.instance.editFlush).toHaveBeenCalled()
    const applied = applyMock.mock.calls.at(-1)![3] as {
      body: { id: string; data: Record<string, unknown> }[]
    }
    expect(applied.body.find((b) => b.id === 'prose0000003')!.data.body).toBe(
      '<p>final keystroke</p>',
    )
    wrapper.unmount()
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts src/__tests__/canvas-bridge.spec.ts src/__tests__/canvas-page.spec.ts`
Expected: FAIL — missing methods/messages across all three.

- [ ] **Step 3: Implement**

**(a) `BlocksField.vue`** — after `pickerTypesFor`:

```ts
/** Edit-in-place (spec §4): patch ONE data field of a block, id-addressed. */
function patchBlockData(id: string, fieldName: string, value: unknown): boolean {
  if (!ops.findById(model.value ?? [], id)) return false
  apply((t) => ops.patchDataById(t, id, fieldName, value))
  return true
}

/** The type slug of `id`, for the parent's prose-convention grant check. */
function blockTypeById(id: string): string | null {
  return ops.findById(model.value ?? [], id)?.type ?? null
}
```

Add both to `defineExpose`.

**(b) `FieldEditor.vue`** — extend `BlocksFieldExposed`:

```ts
  patchBlockData: (id: string, field: string, value: unknown) => boolean
  blockTypeById: (id: string) => string | null
```

Add to `defineExpose`:

```ts
  patchBlockDataById(id: string, field: string, value: unknown) {
    return fieldOwning(id)?.patchBlockData(id, field, value) ?? false
  },
  blockTypeOfBlock(id: string): string | null {
    return fieldOwning(id)?.blockTypeById(id) ?? null
  },
```

**(c) `useCanvasBridge.ts`** — extend `BridgeMessage` with `field?: string; html?: string`. Add slots:

```ts
  let editRequestCb: ((id: string) => void) | null = null
  let textChangedCb: ((id: string, field: string, html: string) => void) | null = null
  let flushResolve: (() => void) | null = null
```

`onMessage` branches:

```ts
    if (data.type === 'lemma:edit-request' && typeof data.id === 'string') {
      editRequestCb?.(data.id)
    }
    if (
      data.type === 'lemma:text-changed' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string' &&
      typeof data.html === 'string'
    ) {
      textChangedCb?.(data.id, data.field, data.html)
    }
    if (data.type === 'lemma:edit-flushed') {
      flushResolve?.()
      flushResolve = null
    }
```

Returned API additions:

```ts
    onEditRequest(cb: (id: string) => void): void {
      editRequestCb = cb
    },
    onTextChanged(cb: (id: string, field: string, html: string) => void): void {
      textChangedCb = cb
    },
    editGrant(id: string, field: string): void {
      post({ type: 'lemma:edit-grant', id, field })
    },
    /**
     * Flush any in-stage editing session before Apply (spec §4): resolves on
     * the bridge's unconditional edit-flushed ack, or after 200ms when no
     * bridge answers (mid-reload stage must not wedge Apply).
     */
    editFlush(): Promise<void> {
      post({ type: 'lemma:edit-flush' })
      return new Promise((resolve) => {
        flushResolve = () => resolve()
        setTimeout(() => {
          flushResolve = null
          resolve()
        }, 200)
      })
    },
```

**(d) Canvas page** — add imports `proseRichFieldName` from `@/fields/components/blocks/proseDetection` and `useBlockTypes` from `@/queries/blockTypes`. Add after the add-after wiring:

```ts
// ── Edit-in-place (edit-in-place spec §4): grant prose blocks only; typed
// text patches the tree — no mirrors, the contenteditable IS the stage DOM.
const { data: allBlockTypes } = useBlockTypes()

/** The prose rich field of block `id`, or null — the ONE validation both paths use. */
function proseFieldOf(id: string): string | null {
  const slug = fieldEditorRef.value?.blockTypeOfBlock(id)
  const blockType = slug ? allBlockTypes.value?.find((t) => t.slug === slug) : undefined
  return blockType ? proseRichFieldName(blockType) : null
}

bridge.onEditRequest((id) => {
  const richField = proseFieldOf(id)
  if (richField !== null) bridge.editGrant(id, richField)
})

bridge.onTextChanged((id, field, html) => {
  // Re-validate (review P1): iframe scripts can see the nonce after hello, so
  // edit messages are REQUESTS, not authority. Patch only when the claimed
  // field IS the block's prose rich field — anything else is ignored.
  if (proseFieldOf(id) !== field) return
  fieldEditorRef.value?.patchBlockDataById(id, field, html)
})
```

Extend `FieldEditorExposed` with the two new members (same signatures as (b)). In `applyWorking()`, first line inside the outer `try`:

```ts
    await bridge.editFlush() // commit any in-stage typing before reading fields
```

- [ ] **Step 4: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/blocksField.spec.ts src/__tests__/canvas-bridge.spec.ts src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS, type-check clean.

---

### Task 4: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

Append to the canvas paragraph in `packages/lemma-render/README.md`:

```markdown
Prose blocks (the exactly-one-rich-text convention) are also editable
in-place: annotated renders wrap the sanitized rich-field output in a
`.lemma-edit-region` marker (emitted by `safe_html` itself, only for prose
blocks), and double-clicking one in the canvas turns it into a plain
contenteditable whose text flows back to the admin's block tree. Typed HTML
is sanitized at save and re-sanitized by `safe_html` at render.
```

- [ ] **Step 2: CHANGELOG**

Append to `[Unreleased]` after the loop C bullet:

```markdown
- Edit-in-place text (canvas v3): double-click a prose block in the Design
  view's stage to type directly into the rendered page — bare contenteditable
  with native shortcuts, debounced back into the block tree, server-touched
  only at Apply/Save (existing sanitizer chain). Renderer marks prose
  rich-field output via a new soft-bound `BlockEditableFieldResolver`
  contract; non-prose blocks are never marked.
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
  packages/lemma-contracts/src/Content/BlockEditableFieldResolver.php \
  app/Content/Blocks/EngineBlockEditableFieldResolver.php \
  app/Providers/LemmaServiceProvider.php \
  packages/lemma-render \
  admin/src/fields/components/BlocksField.vue \
  admin/src/components/FieldEditor.vue \
  admin/src/composables/useCanvasBridge.ts \
  "admin/src/pages/content/[type]/[uuid]/design/[locale].vue" \
  admin/src/__tests__ \
  tests/Integration/Render/EditInPlaceMarkingTest.php \
  CHANGELOG.md \
  docs/superpowers
git status --short
```

Then STOP and report, awaiting explicit commit authorization. Prepared message:

```
feat(admin): edit-in-place text — type directly into prose blocks on the canvas

- safe_html marks prose rich-field output in annotated renders (new
  soft-bound BlockEditableFieldResolver contract; prose-gated, never
  inert markers in non-prose blocks)
- Bridge edit session: double-click -> edit-request/edit-grant, bare
  contenteditable, 400ms debounce + blur/Escape commit, deterministic
  edit-flush ack before Apply
- Tree stays canonical: text-changed -> patchBlockData through the
  owning BlocksField; sanitization unchanged (FieldValidator at save,
  safe_html at render)
```

Recorded manual/browser acceptance (report as outstanding): caret placement on real themes, IME input, native mark shortcuts surviving the sanitizer round-trip, double-click-vs-select feel — plus the earlier v1/v2/loop C items.
