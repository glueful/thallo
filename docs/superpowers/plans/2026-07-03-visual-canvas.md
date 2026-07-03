# Visual Canvas (v1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Structured visual editing over the block model — theme-rendered preview iframe with preview-only block annotations, click-to-select bridging to a full-form inspector, explicit Save & refresh; the Lemma tree stays canonical.

**Architecture:** Render pack annotates `blocks()` output (`.lemma-preview-block` wrappers) in preview renders only and injects a token-free bridge script+stylesheet into preview HTML; the bridge speaks nonce-correlated postMessage. The admin gains a full-screen `/design/` sibling route: iframe stage + outline rail + inspector rehosting `FieldEditor`/`BlocksField`. One new stored-contract invariant: entry-wide block-id uniqueness (FieldValidator).

**Tech Stack:** PHP 8.4 (render pack + app validation), Twig, vanilla JS bridge, Vue 3/Nuxt UI SPA, vitest. Spec: `docs/superpowers/specs/2026-07-03-visual-canvas-design.md`.

## Global Constraints

- **Commit gate:** STAGE at the end (Task 6); commit ONLY on explicit authorization. No Claude/Anthropic attribution anywhere.
- phpcs via `vendor/bin/phpcs -q <files>; echo "PHPCS_EXIT=$?"`; **`composer boundaries` after every render-pack task** (the checker greps the literal `App\` string — comments included; the render pack must never mention it).
- **"Preview-session render" = BOTH entry points (spec §2 P1):** `RenderController::preview()` (direct `/_preview/{token}` — no `PreviewSessionMiddleware`) AND cookie-backed session renders of `/` and `/{path}`. Key off controller knowledge, never the middleware attribute alone.
- **Annotation (spec §2):** `.lemma-preview-block` CSS class wrapper (no inline style, no `<style>` element — CSP); `display:contents` from the static stylesheet; never in live renders; wrap only successfully rendered instances with a string id.
- **Bridge (spec §3):** silent until `{type:'lemma:canvas-hello', nonce}`; store `{origin, nonce}` from that event; echo the nonce on every message; post only to that origin. Links/buttons inert only while ACTIVE.
- **Stage scope (spec §4):** hover/select/highlight/scroll-to ONLY — no drag/add/delete/edit-in-place on the stage.
- **Apply loop (spec §6):** explicit `saveDraft(fields, lock_version)` → re-mint → reload iframe. 409 handling byte-mirrors the editor (`apiErrorCode` branch).
- **Entry-wide block-id uniqueness (spec §5 P2):** FieldValidator rejects duplicate block ids ACROSS all blocks fields (and nesting) of one validated entry.
- **OpenAPI posture:** the two static routes are tagged `Default` (the existing deny-list convention — see `RenderController::previewAsset`).
- SPA rules (recorded): `data-test` hooks only; void UButton handlers; no portal/Nuxt-UI-internal assertions; never pipe tsc through tail.
- New `data-test` hooks: `canvas-stage`, `canvas-iframe`, `canvas-save`, `canvas-refresh-preview`, `canvas-viewport-{desktop|tablet|mobile}`, `canvas-outline`, `canvas-outline-item-{id}`, `canvas-inspector`, `canvas-disabled`, `canvas-back`, `design-link` (editor page).

## File Structure

- Modify: `app/Content/Validation/FieldValidator.php` (entry-wide id set).
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (`setBlockAnnotations` + wrapper), `packages/lemma-render/src/Http/Controllers/RenderController.php` (annotate lifecycle, injection, two static asset actions), `packages/lemma-render/routes/public-routes.php`, `packages/lemma-render/README.md` (annotation shape limits — Task 6).
- Create: `packages/lemma-render/assets/preview/preview.css`, `packages/lemma-render/assets/preview/preview-bridge.js`.
- Create (SPA): `admin/src/composables/useCanvasBridge.ts`, `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`, `admin/src/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue`.
- Modify (SPA): `admin/src/fields/components/BlocksField.vue` (+`defineExpose(selectBlock)`), `admin/src/components/FieldEditor.vue` (ref-tracking + `selectBlockIn`), `admin/src/pages/content/[type]/[uuid]/index.vue` (Design action).
- Tests: `tests/Integration/Content/BlocksValidationTest.php` (extend), `tests/Integration/Render/PreviewAnnotationTest.php` (new), `admin/src/__tests__/canvas-bridge.spec.ts` (new), `admin/src/__tests__/canvas-page.spec.ts` (new).

---

### Task 1: Entry-wide block-id uniqueness (FieldValidator)

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php`
- Test: extend `tests/Integration/Content/BlocksValidationTest.php`

**Interfaces:**
- Produces: `validate()` rejects duplicate block ids across ALL blocks fields and nesting levels of one validated entry (error at the duplicate's dot path, message `"duplicate block id '{id}'"` — the existing copy). Public signatures unchanged.

- [ ] **Step 1: Failing test** (mirror the file's existing harness/type-seeding style):

```php
    public function testBlockIdsMustBeUniqueAcrossTheWholeEntry(): void
    {
        // Two blocks FIELDS carrying the same block id: the canvas bridge keys on
        // bare ids, so uniqueness is entry-wide (visual-canvas spec §5), not
        // per-list. Same error copy/path style as the within-list rejection.
        $schema = $this->schemaWithTwoBlocksFields(); // body + sidebar, both blocks
        try {
            $this->validator()->validate($schema, [
                'title' => 'X',
                'body' => [['id' => 'dupe00000001', 'type' => 'card', 'data' => ['title' => 'a']]],
                'sidebar' => [['id' => 'dupe00000001', 'type' => 'card', 'data' => ['title' => 'b']]],
            ]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('sidebar.0', $e->errors());
            self::assertStringContainsString("duplicate block id 'dupe00000001'", $e->errors()['sidebar.0']);
        }

        // Distinct ids across fields validate.
        $clean = $this->validator()->validate($schema, [
            'title' => 'X',
            'body' => [['id' => 'aaaaaaaaaaaa', 'type' => 'card', 'data' => ['title' => 'a']]],
            'sidebar' => [['id' => 'bbbbbbbbbbbb', 'type' => 'card', 'data' => ['title' => 'b']]],
        ]);
        self::assertSame('aaaaaaaaaaaa', $clean['body'][0]['id']);
    }
```

(Write `schemaWithTwoBlocksFields()`/`validator()` helpers if the file lacks equivalents — the validator needs the FULL construction with `BlockTypeRepository`, and a `card` block type seeded, following the file's existing setup.)

- [ ] **Step 2: Verify fail** (cross-field duplicate currently passes), then implement: `validate()` creates the shared set and threads it —

```php
    public function validate(ContentTypeSchema $schema, array $payload, bool $strict = false): array
    {
        $seenBlockIds = [];
        return $this->validateAt($schema, $payload, $strict, 0, $seenBlockIds);
    }
```

`validateAt(..., array &$seenBlockIds)` passes it to `validateBlocks(..., array &$seenBlockIds)`, which REPLACES its local `$seenIds = []` with the shared reference (the per-list check body is otherwise unchanged — nested recursion through `validateAt` now shares the same set, making uniqueness entry-wide including nesting). Internal callers of `validateAt` (the blocks recursion) pass the set through; no public signature changes.

- [ ] **Step 3: Verify pass** — this file + `vendor/bin/phpunit tests/Integration/Content/` (the suite seeds many blocks fixtures with distinct ids — they must stay green). phpcs.

---

### Task 2: Render-side annotation

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Http/Controllers/RenderController.php`
- Test: `tests/Integration/Render/PreviewAnnotationTest.php` (new; harness mirrors `RenderPipelineTest` — real kernel `handle()` for live/session paths, container controller for `preview()`)

**Interfaces:**
- Produces: `RenderContextExtension::setBlockAnnotations(bool $on): void` (reset-family; default false) — when on, `blocks()` wraps each successfully rendered instance:

```php
$rendered = $env->render($template, [...]);
$html[] = $this->annotateBlocks && is_string($item['id'] ?? null)
    ? '<div class="lemma-preview-block" data-lemma-block="'
        . htmlspecialchars((string) $item['id'], ENT_QUOTES) . '">' . $rendered . '</div>'
    : $rendered;
```

  (Missing-template comments/placeholders are NOT wrapped — nothing selectable there.)
- `RenderController` gains `private bool $annotateBlocks = false;` ASSIGNED (not just set) at the top of every entry point: `home()`/`page()` → `$this->annotateBlocks = $session !== null;`; `preview()` → `= true`; `previewAsset()` untouched (no render). `render()`'s reset block applies it: `$this->extension->setBlockAnnotations($this->annotateBlocks);` alongside `resetTags()` — controller-scoped assignment + per-render application means no leak between requests on the shared singleton.

- [ ] **Step 1: Failing tests:**

```php
    public function testPreviewRendersAnnotateBlocksAndLiveRendersDoNot(): void
    {
        // Seed a page with one block (reuse RenderPipelineTest's block-page seeding
        // shape: `related`-style block type + `page` type with a `sections` blocks
        // field, FULL validator).
        $this->seedBlockPage('source') // publishes; returns entry uuid

        // LIVE render: no wrapper, no data-lemma-block.
        $live = $this->handle(Request::create('/page/source', 'GET'));
        self::assertStringNotContainsString('data-lemma-block', (string) $live->getContent());

        // DIRECT token render (spec §2 P1: preview() does NOT pass the session
        // middleware — annotation must still fire).
        $token = $this->mintToken($entry, 'en');
        $direct = $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        );
        $html = (string) $direct->getContent();
        self::assertStringContainsString('class="lemma-preview-block"', $html);
        self::assertStringContainsString('data-lemma-block="', $html);

        // And the flag does not leak: the NEXT live render is clean again.
        $liveAgain = $this->handle(Request::create('/page/source', 'GET'));
        self::assertStringNotContainsString('data-lemma-block', (string) $liveAgain->getContent());
    }
```

(`mintToken` helper: call `PreviewMinter::mint` from the container — mirror how `PreviewSessionTest` mints; verify its exact helper shape at implementation.)

- [ ] **Step 2–3:** fail → implement → `vendor/bin/phpunit tests/Integration/Render/` green; phpcs + `composer boundaries`.

---

### Task 3: Bridge assets + preview HTML injection

**Files:**
- Create: `packages/lemma-render/assets/preview/preview.css`, `packages/lemma-render/assets/preview/preview-bridge.js`
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (two static actions + injection), `packages/lemma-render/routes/public-routes.php`
- Test: extend `tests/Integration/Render/PreviewAnnotationTest.php`

**Interfaces:**
- Routes (registered BEFORE the `/` + catch-all, after the other `_preview` literals): `GET /_preview.css` → `previewCss()`, `GET /_preview-bridge.js` → `previewBridgeJs()` — both `#[ApiOperation(summary: '… (not an API endpoint)', tags: ['Default'])]`, serving the packaged file with `Content-Type` (`text/css` / `application/javascript`) and `Cache-Control: public, max-age=86400` (token-free static content).
- Injection: a private helper applied to BOTH preview response paths — at the end of `preview()` (content 200s AND the 404 branch) and inside `sessionChrome()`:

```php
    /** Inject the canvas bridge into preview HTML (visual-canvas spec §3). */
    private function withPreviewBridge(Response $response): Response
    {
        $type = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($type, 'text/html')) {
            return $response; // never touch non-HTML (redirects, assets)
        }
        $inject = '<link rel="stylesheet" href="/_preview.css">'
            . '<script src="/_preview-bridge.js" defer></script>';
        $html = (string) $response->getContent();
        $response->setContent(
            str_contains($html, '</body>')
                ? preg_replace('#</body>#', $inject . '</body>', $html, 1)
                : $html . $inject // append at end-of-document; NEVER fail the render
        );
        return $response;
    }
```

**`preview.css`:**

```css
/* Preview/canvas support (visual-canvas spec §2–§3). The wrapper is layout-inert;
   rings are canvas-session visuals driven by the bridge. */
.lemma-preview-block { display: contents; }
[data-lemma-block].lemma-canvas-hover > *,
.lemma-canvas-hover-target { outline: 2px dashed rgba(37, 99, 235, 0.6); outline-offset: 2px; }
[data-lemma-block].lemma-canvas-selected > *,
.lemma-canvas-selected-target { outline: 2px solid rgba(37, 99, 235, 0.9); outline-offset: 2px; }
```

(`display:contents` elements paint no boxes, so rings go on the wrapper's CHILDREN via `> *` — verify visually in the manual pass; the fallback `-target` classes exist if the implementer needs to class the first child instead.)

**`preview-bridge.js`** (complete; vanilla, no build step):

```js
// Lemma canvas bridge (visual-canvas spec §3). SILENT until a canvas parent says
// hello; a plain preview tab never messages anyone. Nonce = correlation, not auth.
(function () {
  'use strict'
  var session = null // { origin, nonce }

  function post(type, payload) {
    if (!session) return
    var msg = Object.assign({ type: 'lemma:' + type, nonce: session.nonce }, payload || {})
    window.parent.postMessage(msg, session.origin)
  }

  function idsIndex() {
    return Array.prototype.map.call(
      document.querySelectorAll('[data-lemma-block]'),
      function (el) { return el.getAttribute('data-lemma-block') }
    )
  }

  function wrapperFor(target) {
    return target && target.closest ? target.closest('[data-lemma-block]') : null
  }

  function clearClass(cls) {
    Array.prototype.forEach.call(document.querySelectorAll('.' + cls), function (el) {
      el.classList.remove(cls)
    })
  }

  function activate() {
    document.addEventListener('mouseover', function (e) {
      var w = wrapperFor(e.target)
      clearClass('lemma-canvas-hover')
      if (w) {
        w.classList.add('lemma-canvas-hover')
        post('block-hover', { id: w.getAttribute('data-lemma-block') })
      }
    })
    // Capture phase: block-internal links/buttons are INERT while active
    // (spec §3) — editing must not navigate the stage.
    document.addEventListener('click', function (e) {
      var w = wrapperFor(e.target)
      if (!w) return
      e.preventDefault()
      e.stopPropagation()
      clearClass('lemma-canvas-selected')
      w.classList.add('lemma-canvas-selected')
      post('block-select', { id: w.getAttribute('data-lemma-block') })
    }, true)
    post('blocks-index', { ids: idsIndex() })
  }

  window.addEventListener('message', function (event) {
    var data = event.data || {}
    if (!session) {
      if (data.type === 'lemma:canvas-hello' && typeof data.nonce === 'string') {
        session = { origin: event.origin, nonce: data.nonce }
        activate()
      }
      return
    }
    if (event.origin !== session.origin || data.nonce !== session.nonce) return
    if (data.type === 'lemma:highlight') {
      clearClass('lemma-canvas-selected')
      var el = document.querySelector('[data-lemma-block="' + CSS.escape(String(data.id)) + '"]')
      if (el) el.classList.add('lemma-canvas-selected')
    }
    if (data.type === 'lemma:scroll-to') {
      var t = document.querySelector('[data-lemma-block="' + CSS.escape(String(data.id)) + '"]')
      if (t && t.firstElementChild) t.firstElementChild.scrollIntoView({ block: 'center', behavior: 'smooth' })
    }
  })
})()
```

- [ ] **Steps:** failing tests — (a) direct preview + session HTML contain exactly one `<link rel="stylesheet" href="/_preview.css">` and one bridge `<script>`; (b) live renders contain neither; (c) `GET /_preview.css` / `/_preview-bridge.js` return 200 with the right Content-Type + `max-age=86400`; (d) a preview response WITHOUT `</body>` (drive `withPreviewBridge` via reflection with a bare-HTML Response) still gets the injection appended. Implement → render suite green → phpcs + boundaries.

---

### Task 4: SPA bridge client + selection plumbing

**Files:**
- Create: `admin/src/composables/useCanvasBridge.ts`
- Modify: `admin/src/fields/components/BlocksField.vue` (`defineExpose({ selectBlock, onDragEnd })` — keep the existing exposure), `admin/src/components/FieldEditor.vue`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts`

**Interfaces:**
- `useCanvasBridge(iframeRef: Ref<HTMLIFrameElement | null>)` returns:

```ts
{
  nonce: string                              // crypto-random, per composable instance
  hello(): void                              // posts lemma:canvas-hello into the iframe
  onBlockSelect(cb: (id: string) => void): void
  onBlockHover(cb: (id: string) => void): void
  onBlocksIndex(cb: (ids: string[]) => void): void
  highlight(id: string): void                // parent -> bridge
  scrollTo(id: string): void
  dispose(): void                            // removes the window listener
}
```

  Implementation: one `window.addEventListener('message', …)` that DROPS messages whose `data.nonce !== nonce` (the iframe may be cross-origin — nonce is the correlation filter; also ignore messages from other sources).

  **targetOrigin pin (P2):** derive it from the IFRAME URL, not `sitePreviewUrl` — the canvas loads the server-decided `theme_url` (the `PublishPanel.vue:81` path), while `sitePreviewUrl` only feeds the older `buildPreviewUrl()` JSON-preview builder and may be unset or a different origin entirely:

```ts
function targetOrigin(): string {
  const src = iframeRef.value?.src ?? ''
  try {
    return new URL(src, window.location.href).origin
  } catch {
    return '*' // parsing impossible — the hello carries no secrets; nonce still correlates
  }
}
```

  Every post — `hello()`, `highlight()`, `scrollTo()` — uses `targetOrigin()` computed AFTER the iframe `src` is assigned (the composable reads it lazily per post, so re-mints that change the src are automatically respected).
- `FieldEditor.vue`: track refs of rendered `BlocksField` instances per field name (`:ref="(el) => trackBlocksField(field.name, el)"` on the blocks branch) and expose:

```ts
defineExpose({
  // Canvas selection: find the blocks field containing `id` and drive its
  // selectBlock. Returns true when found (entry-wide id uniqueness makes the
  // bare id unambiguous — visual-canvas spec §5).
  selectBlockById(id: string): boolean
})
```

  `selectBlockById` asks each tracked BlocksField (exposed `selectBlock` returns void — extend BlocksField's exposed API with `hasBlock(id: string): boolean` using the ops `findById` so FieldEditor can route without duplicating tree search).

  **Ref-cleanup pin (P2):** Vue calls a function ref with `null` on unmount and
  with a NEW instance on swap — `trackBlocksField(name, el)` must DELETE the map
  entry when `el === null` (and overwrite on non-null), and `selectBlockById()`
  iterates only the live map. Without the delete, a schema change or field
  removal leaves a stale component instance in the map and selection can hit a
  dead ref. Test pins it: mount with two blocks fields, remove one from the
  schema (rerender), assert `selectBlockById` for an id that lived in the removed
  field returns `false` and never throws.

- [ ] **Step 1: Failing tests** (`canvas-bridge.spec.ts` — jsdom `window.postMessage` is synchronous-ish via message events; use `new MessageEvent('message', {data, origin})` dispatch for full control):

```ts
it('drops messages with a foreign nonce and dispatches matching ones', async () => {
  const iframe = ref(null)
  const bridge = useCanvasBridge(iframe)
  const seen: string[] = []
  bridge.onBlockSelect((id) => seen.push(id))
  window.dispatchEvent(new MessageEvent('message', {
    data: { type: 'lemma:block-select', nonce: 'WRONG', id: 'b1' },
  }))
  window.dispatchEvent(new MessageEvent('message', {
    data: { type: 'lemma:block-select', nonce: bridge.nonce, id: 'b2' },
  }))
  expect(seen).toEqual(['b2'])
  bridge.dispose()
})

it('highlight/scrollTo post nonce-carrying messages into the iframe', () => {
  const postMessage = vi.fn()
  const iframe = ref({ contentWindow: { postMessage } } as unknown as HTMLIFrameElement)
  const bridge = useCanvasBridge(iframe)
  bridge.highlight('b1')
  expect(postMessage).toHaveBeenCalledWith(
    expect.objectContaining({ type: 'lemma:highlight', id: 'b1', nonce: bridge.nonce }),
    expect.any(String),
  )
})
```

  Plus a `FieldEditor.selectBlockById` component test: mount FieldEditor with a schema containing TWO blocks fields (mocked `useBlockTypes` as in `block-notion-ux.spec.ts`), seed distinct ids, call `wrapper.vm.selectBlockById('q2')`, assert the right field's block header gets focused (the canvas mapping test the spec §5 demands).

- [ ] **Step 2–3:** fail → implement → tests + `pnpm type-check` green.

---

### Task 5: Canvas page + editor Design action

**Files:**
- Create: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`, `admin/src/pages/content/[type]/[uuid]/design/components/CanvasOutline.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue` (Design button in the navbar right cluster: `data-test="design-link"`, `:to` the design route with the current locale)
- Test: `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces / behavior (spec §1, §5, §6):**
- The page loads independently: `useDraft(uuid, locale)` → local `fields` + `lockVersion` (same seeding watch as the editor page), content-type schema via the existing `contentTypes` query, block types via `useBlockTypes`.
- Mint on mount via `mintPreviewData(uuid, locale)`:
  - `theme_url === null` → render the `canvas-disabled` state (route LOADS — never SPA-404): explains rendered delivery is disabled + `canvas-back` link to the form editor. No stage.
  - else stage iframe (`canvas-iframe`) `src = theme_url`, then `bridge.hello()` on iframe `load`.
- Command bar: viewport preset buttons set the stage wrapper width (`100%`/`768px`/`390px`); dirty dot (local fields differ from loaded draft); `canvas-save` runs the apply loop —

```ts
async function saveAndRefresh() {
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    const mint = await mintPreviewData(uuid, locale.value)
    if (mint.themeUrl) iframeSrc.value = mint.themeUrl // fresh session per apply (spec §6)
  } catch (e) {
    // BYTE-MIRROR of the editor's onSave 409 branches (apiErrorCode).
  }
}
```

  `canvas-refresh-preview` re-mints WITHOUT saving (expired-token affordance).
- Selection wiring: `bridge.onBlockSelect(id => { fieldEditorRef.value?.selectBlockById(id); outlineSelected.value = id })`; outline click → `selectBlockById` + `bridge.highlight(id)` + `bridge.scrollTo(id)`.
- `CanvasOutline.vue` props `{ fields: Record<string, unknown>; schema: FieldDef[]; selected: string | null }`, emits `select(id)` — walks every blocks-typed field's tree (region map from `useBlockTypes`, same walk shape as `BlockOutlineRail`) rendering `canvas-outline-item-{id}` rows grouped under field-name headings.
- Inspector: `<FieldEditor ref="fieldEditorRef" v-model="fields" :schema="schema" data-test="canvas-inspector" />` — the editor page's exact component.

- [ ] **Step 1: Failing tests** (`canvas-page.spec.ts`; mock queries the way the editor-page-adjacent specs do; stub the iframe — no real load):
  - disabled state: mint resolves `themeUrl: null` → `canvas-disabled` rendered, no `canvas-iframe`, `canvas-back` present
  - happy path: mint resolves a URL → iframe src set; viewport buttons change the stage width style
  - apply: `canvas-save` click → `saveDraft` called with `lock_version`, then a SECOND mint, then iframe src updated; stale 409 → the stale banner; migration 409 payload → the migration banner
  - selection: simulated `block-select` message (dispatch `MessageEvent` with the bridge nonce — expose it) drives `selectBlockById` + outline selection; outline item click calls `highlight`/`scrollTo` (spy the bridge)
  - editor page renders `design-link` pointing at the design route
- [ ] **Step 2–3:** fail → implement → `pnpm vitest run` for the new files + full `pnpm test` + `pnpm type-check`.

---

### Task 6: Docs + full verification + STAGE

- [ ] **Step 1: render-pack README** — extend the preview section: annotation shape (`.lemma-preview-block`, `display:contents`), the documented HTML-shape limit ("block templates that must be literal children of semantic containers — `ul > li`, `table > tr` — are not compatible with canvas annotation; Lemma blocks are page/layout fragments"), and the two static support routes.
- [ ] **Step 2: CHANGELOG `[Unreleased]`** — append to the block-builder family:

```markdown
  Follow-up: **visual canvas (v1)** — a full-screen Design view per entry:
  theme-rendered preview iframe (real Twig through the preview session; every
  preview render now annotates blocks() output with layout-inert
  `.lemma-preview-block` wrappers and injects a token-free, nonce-correlated
  postMessage bridge), click-a-rendered-block-to-edit selection into a
  full-form inspector (the same FieldEditor/BlocksField the editor uses),
  entry-wide outline rail, responsive viewport presets, and an explicit
  Save & refresh loop (saveDraft + re-minted preview per apply; 409 handling
  mirrors the form editor). Select-only stage in v1 — structure edits stay in
  the inspector's Notion UX; no HTML/CSS editing surface. New stored-contract
  invariant: block ids are unique across the whole entry (validated).
```

- [ ] **Step 3: Full verification**

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Unit
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..
```

- [ ] **Step 4: STAGE** *(commit only when authorized)*

```bash
git add app/Content/Validation/FieldValidator.php packages/lemma-render \
        admin/src/composables/useCanvasBridge.ts admin/src/pages/content \
        admin/src/components/FieldEditor.vue admin/src/fields/components/BlocksField.vue \
        admin/src/__tests__ tests/Integration/Content/BlocksValidationTest.php \
        tests/Integration/Render/PreviewAnnotationTest.php CHANGELOG.md docs/superpowers
```

STOP — when authorized:

```bash
git commit -m "feat(admin): visual canvas — structured editing over the rendered page

Full-screen Design route per entry: the real theme-rendered page in a preview
iframe, click-to-select bridging rendered blocks to a full-form inspector
(FieldEditor/BlocksField rehosted), entry-wide outline, viewport presets, and
an explicit Save & refresh loop (saveDraft with lock_version, then a re-minted
preview session per apply; 409 branches byte-mirror the form editor).

Render side: blocks() wraps instances in layout-inert .lemma-preview-block
annotation wrappers in EVERY preview render (both the direct /_preview/{token}
entrypoint and cookie sessions) and never live; preview HTML gets a token-free
/_preview.css + /_preview-bridge.js injection (appended even without </body>);
the bridge is silent until a nonce-carrying canvas-hello, posts only to that
origin, echoes the nonce on every message, and makes block-internal links
inert while active. Select-only stage in v1 — no drag/add/edit-in-place, no
HTML/CSS surface; structure edits stay in the inspector's Notion UX.

New stored-contract invariant: block ids are unique across the WHOLE entry
(FieldValidator threads one seen-set through all blocks fields and nesting),
making the bridge's bare-id keying unambiguous across multiple blocks fields."
```

**Manual/browser acceptance (recorded, same class as the split routine):** real hover/click/scroll rings, link inertness, `display:contents` ring rendering on wrapper children, cross-origin posture with a configured `sitePreviewUrl`.

---

## Self-Review Notes (applied)

- **Spec coverage:** §1 route/layout/back-links → Task 5; §2 annotation incl. BOTH entry points + no-leak + shape limit docs → Tasks 2+6 (P1 test drives `preview()` directly, bypassing middleware); §3 static routes + Default-tag OpenAPI posture + injection incl. no-`</body>` append + full bridge JS/CSS + nonce protocol → Task 3; §4 select-only scope (nothing else built); §5 full-form inspector + `selectBlockById` chain + entry-wide uniqueness → Tasks 1+4+5 (two-blocks-fields mapping test); §6 apply loop/re-mint/409 mirror/disabled + expired states → Task 5; §7 matrix mapped; §8/§9 untouched.
- **Type consistency:** `useCanvasBridge` API used identically in Tasks 4–5; `selectBlockById`/`hasBlock`/`selectBlock` exposure chain defined once; `withPreviewBridge`/`setBlockAnnotations`/`$annotateBlocks` names consistent across Tasks 2–3.
- **Verify-don't-guess flags:** `PreviewMinter` mint-helper shape for the render test (Task 2); ring rendering on `display:contents` children (manual pass, fallback classes shipped); `FieldEditor`'s internal render loop shape for the ref-tracking hook (Task 4).
- **Review fixes (applied):** P2 — `targetOrigin` derived lazily from the iframe's actual `src` (`new URL(src, location.href).origin`, `'*'` only when parsing is impossible), never from `sitePreviewUrl` (which feeds only the JSON-preview builder); P2 — `trackBlocksField` deletes on `el === null`, `selectBlockById` iterates only live refs, with a removed-field stale-ref test.
- **Boundary discipline:** every render-pack file avoids the literal `App\` string; `composer boundaries` runs in Tasks 2, 3, and 6.
