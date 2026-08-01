# Canvas v4: Editable String Fields (`|editable_text`) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Themes opt string/plain-text fields into edit-in-place via `{{ data.heading|editable_text('heading') }}`; the parent's kind matrix (`rich | string | text`) is the sole grant authority.

**Architecture:** A self-escaping Twig filter emits `<span class="lemma-edit-region" …>` in annotated renders and the bare escaped value live. The protocol unifies: `edit-request {id, field}` (region under the double-click), `edit-grant {id, field, kind}` (parent-decided), per-(block, field) one-region rule, kind-driven commits (`innerHTML` for rich, `innerText` for plain). Starter theme adopts per the spec's table without restructuring conditionals.

**Tech Stack:** PHP 8.3 render pack (Twig filter), vanilla-JS bridge, Vue 3 admin, vitest jsdom.

**Spec:** `docs/superpowers/specs/2026-07-03-editable-string-fields-design.md`

## Global Constraints

- **Filter name:** `editable_text` exactly. Registered `is_safe: ['html']`, so it ALWAYS escapes the value itself (both modes) — Twig autoescape is never relied on.
- **Non-string values render as `''`** (empty span annotated / empty string live).
- **No kind in the DOM:** regions carry only `data-lemma-edit-block` + `data-lemma-edit-field`; the bridge behaves per the GRANT kind.
- **Kind matrix (parent authority):** `text+format rich` AND prose convention → `rich`; `string` → `string`; `text` non-rich → `text`; everything else → deny. Same helper validates `text-changed` before patching.
- **Kind semantics:** `string` = single-line (Enter commits-and-exits); `text` = multiline (`innerText` preserves `\n`); plain kinds use `contenteditable="plaintext-only"` best-effort with `'true'` fallback — commits read `innerText`, so plain-text safety never depends on it.
- **Conditional emissions stay conditional (hard rule):** wrap value expressions in place; NEVER unwrap `{% if %}` guards into empty targets.
- **CSP pin:** styling only via static `preview.css` (`.lemma-edit-region:empty` clickability rule included).
- **Protocol shape change ships both sides together** — safe because injected assets carry the `?v=` mtime cache-buster.
- **Commit gate:** STAGE at the end of Task 4 only; commit ONLY on explicit authorization. No attribution trailers.
- **Verification:** PHP gates from the lemma repo root; admin `cd admin && pnpm type-check && pnpm test`. No server endpoints change (no OpenAPI regen).

---

### Task 1: `editable_text` filter + starter adoption + CSS

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php` (filter)
- Modify: `packages/lemma-render/assets/preview/preview.css` (`:empty` rule)
- Modify: `packages/lemma-render/themes/default/templates/blocks/{hero,section,quote,image,cta}.twig`
- Test: `tests/Integration/Render/EditInPlaceMarkingTest.php` (extend)

**Interfaces:**
- Consumes: the v3 frame stack (`$this->blockFrames`, `$this->annotateBlocks`) in `RenderContextExtension`.
- Produces (Tasks 2–3 rely on the DOM shape): annotated emissions
  `<span class="lemma-edit-region" data-lemma-edit-block="{id}" data-lemma-edit-field="{name}">{escaped}</span>`;
  live emissions = `htmlspecialchars($value)` exactly.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Integration/Render/EditInPlaceMarkingTest.php` (the file's `seedBlockPage`-style plumbing and imports already exist; reuse `BlockTypeRepository`, `ContentTypeRepository`, `EntryRepository`, `RouteRepository`, `PreviewMinter`, `RenderController`):

```php
    /** Seed a page whose body holds one `hero` block with the given data. */
    private function seedHeroPage(string $slug, array $heroData): string
    {
        (new BlockTypeRepository($this->connection()))->create([
            'slug' => 'hero',
            'label' => 'Hero',
            'schema' => [
                ['name' => 'heading', 'type' => 'string'],
                ['name' => 'subheading', 'type' => 'string'],
                ['name' => 'cta_label', 'type' => 'string'],
                ['name' => 'cta_url', 'type' => 'string'],
                ['name' => 'image', 'type' => 'asset'], // Lemma schema type is asset, not media
                ['name' => 'alignment', 'type' => 'string'],
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
            ['id' => 'heroblok0001', 'type' => 'hero', 'data' => $heroData],
        ]], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($entry, $this->type, 'en', $slug);
        return $entry;
    }

    public function testEditableTextMarksAnnotatedRendersAndEscapesTheValue(): void
    {
        $entry = $this->seedHeroPage('et-page', [
            'heading' => 'Big <b>launch</b> "day"',
            'cta_label' => 'Go',
            'cta_url' => '/x',
        ]);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();

        // Marked span with BOTH attributes; the VALUE is filter-escaped.
        self::assertStringContainsString(
            '<span class="lemma-edit-region" data-lemma-edit-block="heroblok0001"'
                . ' data-lemma-edit-field="heading">Big &lt;b&gt;launch&lt;/b&gt; &quot;day&quot;</span>',
            $html,
        );
        // The CTA label inside the <a> is marked too (interactive-element pin).
        self::assertStringContainsString('data-lemma-edit-field="cta_label">Go</span>', $html);
    }

    public function testEditableTextLiveRendersAreByteIdenticalToPlainOutput(): void
    {
        $entry = $this->seedHeroPage('et-live', ['heading' => 'A & B', 'cta_url' => '']);
        // Publish so the live route serves it.
        $types = new ContentTypeRepository($this->connection());
        $entries = new EntryRepository($this->connection(), $this->appContext(), $types);
        (new \App\Content\Services\PublishService(
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

        $live = (string) $this->handle(Request::create('/page/et-live', 'GET'))->getContent();
        self::assertStringContainsString('A &amp; B', $live);
        self::assertStringNotContainsString('lemma-edit-region', $live);
        self::assertStringNotContainsString('data-lemma-edit-field', $live);
    }

    public function testEditableTextEmptyAndNonStringValues(): void
    {
        // heading '' -> EMPTY span in annotated renders (clickable blank, spec §0).
        $entry = $this->seedHeroPage('et-empty', ['heading' => '', 'cta_url' => '']);
        $token = $this->container()->get(PreviewMinter::class)->mint($entry, 'en');
        $html = (string) $this->container()->get(RenderController::class)->preview(
            Request::create("/_preview/{$token}", 'GET'),
            $token,
        )->getContent();
        self::assertStringContainsString('data-lemma-edit-field="heading"></span>', $html);

        // Direct filter calls: non-string -> '', and NO frame -> escaped value only
        // even with annotations on.
        $ext = $this->container()->get(\Glueful\Lemma\Render\RenderContextExtension::class);
        $ext->setBlockAnnotations(true);
        $ext->resetBlockFrames();
        self::assertSame('x &lt;y&gt;', $ext->editableText('x <y>', 'f'));
        self::assertSame('', $ext->editableText(['array'], 'f'));
        self::assertSame('', $ext->editableText(null, 'f'));
        $ext->setBlockAnnotations(false);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit tests/Integration/Render/EditInPlaceMarkingTest.php`
Expected: ERROR — `editableText` undefined / no span in output.

- [ ] **Step 3: Implement the filter**

In `packages/lemma-render/src/RenderContextExtension.php`:

Register (in `getFilters()`):

```php
            // Theme-declared editable text (editable-string-fields spec §1):
            // is_safe html because annotated mode emits a marker span — so the
            // filter ESCAPES the value itself in BOTH modes (never autoescape).
            new TwigFilter('editable_text', $this->editableText(...), ['is_safe' => ['html']]),
```

Implementation (next to `markEditable()`):

```php
    /**
     * Opt-in edit-in-place marking for plain string/text fields
     * (editable-string-fields spec §1): annotated renders wrap the ESCAPED
     * value in a span region; live renders emit exactly the escaped value.
     * The field name is the TEMPLATE's claim — the admin's grant matrix is
     * the validator, so a bogus name yields a region that is never granted.
     * Non-string values render as ''.
     */
    public function editableText(mixed $value, string $field): string
    {
        $escaped = is_string($value)
            ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : '';
        if (!$this->annotateBlocks || $this->blockFrames === []) {
            return $escaped;
        }
        $frame = $this->blockFrames[count($this->blockFrames) - 1];
        if (!is_string($frame['id'])) {
            return $escaped;
        }
        return '<span class="lemma-edit-region" data-lemma-edit-block="'
            . htmlspecialchars($frame['id'], ENT_QUOTES)
            . '" data-lemma-edit-field="'
            . htmlspecialchars($field, ENT_QUOTES)
            . '">' . $escaped . '</span>';
    }
```

- [ ] **Step 4: Adopt in the starter templates (value wraps ONLY — never restructure conditionals)**

`hero.twig` — three wraps:

```twig
  <h1 class="lemma-block-hero__heading">{{ data.heading|editable_text('heading') }}</h1>
  {% if data.subheading %}<p class="lemma-block-hero__subheading">{{ data.subheading|editable_text('subheading') }}</p>{% endif %}
  {% if data.cta_label %}
    {% if url %}<a class="lemma-block-hero__cta" href="{{ url }}">{{ data.cta_label|editable_text('cta_label') }}</a>
    {% else %}<span class="lemma-block-hero__cta">{{ data.cta_label|editable_text('cta_label') }}</span>{% endif %}
  {% endif %}
```

`section.twig`:

```twig
  {% if data.title %}<h2 class="lemma-block-section__title">{{ data.title|editable_text('title') }}</h2>{% endif %}
```

`quote.twig`:

```twig
  <p>{{ data.text|editable_text('text') }}</p>
  {% if data.attribution %}<cite>{{ data.attribution|editable_text('attribution') }}</cite>{% endif %}
```

`image.twig` — caption only; `alt` stays UNFILTERED (attribute — the documented counter-example):

```twig
  {% if data.caption %}<figcaption>{{ data.caption|editable_text('caption') }}</figcaption>{% endif %}
```

`cta.twig`:

```twig
  <h2 class="lemma-block-cta__heading">{{ data.heading|editable_text('heading') }}</h2>
  {% if data.body %}<p class="lemma-block-cta__body">{{ data.body|editable_text('body') }}</p>{% endif %}
  {% if data.button_label %}
    {% if url %}<a class="lemma-block-cta__button" href="{{ url }}">{{ data.button_label|editable_text('button_label') }}</a>
    {% else %}<span class="lemma-block-cta__button">{{ data.button_label|editable_text('button_label') }}</span>{% endif %}
  {% endif %}
```

- [ ] **Step 5: CSS**

Append to `packages/lemma-render/assets/preview/preview.css`:

```css
/* Empty editable regions (editable-string-fields spec §0): blank values the
   template still renders (e.g. hero.heading) need a click target. */
.lemma-edit-region:empty { display: inline-block; min-width: 3ch; min-height: 1em; }
```

- [ ] **Step 6: Run to verify pass**

Run: `vendor/bin/phpunit tests/Integration/Render/ 2>&1 | tail -2 && vendor/bin/phpcs -q packages/lemma-render/src/RenderContextExtension.php tests/Integration/Render/EditInPlaceMarkingTest.php; echo "PHPCS_EXIT=$?"`
Expected: full render suite PASS, PHPCS_EXIT=0.

---

### Task 2: Bridge protocol v4 (field-addressed requests, kind-driven sessions)

**Files:**
- Modify: `packages/lemma-render/assets/preview/preview-bridge.js`
- Test: `admin/src/__tests__/preview-bridge-dom.spec.ts` (update v3 cases + add v4 cases)

**Interfaces:**
- Consumes: Task 1's span regions.
- Produces (Task 3's counterpart messages): outbound `lemma:edit-request {id, field}`; inbound `lemma:edit-grant {id, field, kind}`; outbound `lemma:text-changed {id, field, html}` (rich) / `{id, field, text}` (string/text). Flush/end unchanged.

- [ ] **Step 1: Update/extend the direct tests**

In `admin/src/__tests__/preview-bridge-dom.spec.ts`:

**(a)** The v3 grant sends need `kind`; the v3 request assertion gains `field`. Apply these updates:
- Every existing `sendToBridge({ type: 'lemma:edit-grant', id: …, field: 'body' })` becomes `sendToBridge({ type: 'lemma:edit-grant', id: …, field: 'body', kind: 'rich' })` (all 5 occurrences).
- In the first edit test, the request assertion becomes:
  `expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'eip-a-000001', field: 'body' })`.
- The "grant field mismatch" test's mismatch send also gains `kind: 'rich'`.
- **Duplicate-region regression carries into v4 (review P2):** extend the v3
  test `'a DUPLICATED prose block is immediately editable under its NEW id'` —
  after the existing grant assertion (and its cleanup flush), double-click the
  CLONE's region and assert the field-addressed request emits the NEW id
  (mirrorDuplicate's `data-lemma-edit-block` idMap rewrite is what makes
  grant/patch routing work for duplicates):

```ts
    // v4: the field-addressed request from the CLONE emits the NEW id.
    posted.mockClear()
    const cloneRegion = copy.querySelector('.lemma-edit-region')!
    cloneRegion.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'eip-j-000002', field: 'body' })
```

**(b)** Append new cases inside `describe('edit-in-place session', …)`:

```ts
  function stringWrapper(id: string, field = 'heading', value = 'Hello'): HTMLElement {
    return wrapper(
      id,
      `<section><h1><span class="lemma-edit-region" data-lemma-edit-block="${id}" ` +
        `data-lemma-edit-field="${field}">${value}</span></h1></section>`,
    )
  }

  it('request field comes from the region under the double-click; two fields coexist', () => {
    const w = wrapper(
      'es-a-0000001',
      '<section>' +
        '<h1><span class="lemma-edit-region" data-lemma-edit-block="es-a-0000001" data-lemma-edit-field="heading">H</span></h1>' +
        '<p><span class="lemma-edit-region" data-lemma-edit-block="es-a-0000001" data-lemma-edit-field="body_text">B</span></p>' +
        '</section>',
    )
    document.body.appendChild(w)
    w.querySelector('p .lemma-edit-region')!.dispatchEvent(
      new MouseEvent('dblclick', { bubbles: true }),
    )
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'es-a-0000001', field: 'body_text' })

    // Grant for ONE of two same-block regions edits exactly that region.
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-a-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('[data-lemma-edit-field="body_text"]')!
    expect(region.getAttribute('contenteditable')).not.toBeNull()
    expect(
      w.querySelector('[data-lemma-edit-field="heading"]')!.getAttribute('contenteditable'),
    ).toBeNull()
    sendToBridge({ type: 'lemma:edit-flush' })
  })

  it('wrapper-level double-click falls back to the SINGLE region; none with two', () => {
    const single = stringWrapper('es-b-0000001')
    document.body.appendChild(single)
    posted.mockClear()
    single.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toMatchObject({ id: 'es-b-0000001', field: 'heading' })

    const two = wrapper(
      'es-c-0000001',
      '<section>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="es-c-0000001" data-lemma-edit-field="a">1</span>' +
        '<span class="lemma-edit-region" data-lemma-edit-block="es-c-0000001" data-lemma-edit-field="b">2</span>' +
        '</section>',
    )
    document.body.appendChild(two)
    posted.mockClear()
    two.querySelector('section')!.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
    expect(lastPost('lemma:edit-request')).toBeUndefined()
  })

  it('string kind: Enter commits-and-exits with the TEXT payload (markup never persists)', () => {
    const w = stringWrapper('es-d-0000001')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-d-0000001', field: 'heading', kind: 'string' })
    const region = w.querySelector('.lemma-edit-region')!
    expect(['plaintext-only', 'true']).toContain(region.getAttribute('contenteditable'))

    region.innerHTML = 'New <b>title</b>'
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(true) // single-line convention
    const commit = lastPost('lemma:text-changed')!
    expect(commit).toMatchObject({ id: 'es-d-0000001', field: 'heading', text: 'New title' })
    expect(commit.html).toBeUndefined()
    expect(lastPost('lemma:edit-end')).toMatchObject({ id: 'es-d-0000001' })
    expect(region.getAttribute('contenteditable')).toBeNull()
  })

  it('text kind: Enter does NOT exit; commit carries the text payload', () => {
    const w = stringWrapper('es-e-0000001', 'body_text', 'line')
    document.body.appendChild(w)
    sendToBridge({ type: 'lemma:edit-grant', id: 'es-e-0000001', field: 'body_text', kind: 'text' })
    const region = w.querySelector('.lemma-edit-region')!
    const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true })
    region.dispatchEvent(enter)
    expect(enter.defaultPrevented).toBe(false)
    expect(region.getAttribute('contenteditable')).not.toBeNull() // still editing
    region.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    expect(lastPost('lemma:text-changed')).toMatchObject({
      id: 'es-e-0000001',
      field: 'body_text',
      text: 'line',
    })
  })
```

- [ ] **Step 2: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: FAIL — requests carry no field; grants ignore kind; commits always post `html`.

- [ ] **Step 3: Implement the bridge changes**

In `packages/lemma-render/assets/preview/preview-bridge.js`:

**(a)** `editing` state gains kind (comment only — shape is dynamic):

```js
  var editing = null // { id, field, kind, region, debounce }
```

**(b)** Replace the dblclick listener body:

```js
    document.addEventListener('dblclick', function (e) {
      if (editing) return
      var w = wrapperFor(e.target)
      if (!w) return
      // The REGION under the double-click names the field (spec §2); a
      // wrapper-level double-click falls back to the block's single region.
      var region = e.target && e.target.closest ? e.target.closest('.lemma-edit-region') : null
      if (!region || !w.contains(region)) {
        var regions = w.querySelectorAll('.lemma-edit-region')
        region = regions.length === 1 ? regions[0] : null
      }
      if (!region) return
      e.preventDefault()
      lastPointer = { x: e.clientX, y: e.clientY }
      post('edit-request', {
        id: region.getAttribute('data-lemma-edit-block'),
        field: region.getAttribute('data-lemma-edit-field')
      })
    }, true)
```

**(c)** `startEditing` takes kind; the plain kinds get best-effort plaintext-only:

```js
  function startEditing(id, field, kind) {
    if (editing) return
    var region = regionFor(id, field)
    if (!region) return
    detachToolbar()
    editing = { id: id, field: field, kind: kind, region: region, debounce: null }
    region.setAttribute('contenteditable', kind === 'rich' ? 'true' : 'plaintext-only')
    // Best-effort (spec pin): engines without plaintext-only support reflect a
    // different IDL value — fall back to 'true'. Commits read innerText for
    // plain kinds, so markup can never persist either way.
    if (kind !== 'rich' && String(region.contentEditable).toLowerCase() !== 'plaintext-only') {
      region.setAttribute('contenteditable', 'true')
    }
    region.classList.add('lemma-canvas-editing')
    region.addEventListener('input', onEditInput)
    region.addEventListener('blur', onEditBlur)
    region.addEventListener('keydown', onEditKeydown)
    region.focus()
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

**(d)** `regionFor` becomes per-(block, field):

```js
  function regionFor(id, field) {
    var w = findBlock(id)
    if (!w) return null
    var regions = w.querySelectorAll(
      '.lemma-edit-region[data-lemma-edit-block="' + cssEscape(id) + '"]'
        + '[data-lemma-edit-field="' + cssEscape(field) + '"]'
    )
    return regions.length === 1 ? regions[0] : null // one region per (block, field)
  }
```

(The v3 field-sanity check inside `startEditing` is subsumed — the selector
matches the field directly.)

**(e)** `commitEditing` posts per kind:

```js
  function commitEditing() {
    if (!editing) return
    if (editing.debounce) clearTimeout(editing.debounce)
    if (editing.kind === 'rich') {
      post('text-changed', { id: editing.id, field: editing.field, html: editing.region.innerHTML })
    } else {
      var text = editing.region.innerText
      if (typeof text !== 'string') text = editing.region.textContent || ''
      post('text-changed', { id: editing.id, field: editing.field, text: text })
    }
  }
```

**(f)** `onEditKeydown` adds the single-line convention:

```js
  function onEditKeydown(e) {
    if (e.key === 'Escape') {
      e.preventDefault()
      commitEditing()
      endEditing()
    }
    if (e.key === 'Enter' && editing && editing.kind === 'string') {
      e.preventDefault() // single-line: Enter commits-and-exits
      commitEditing()
      endEditing()
    }
  }
```

**(g)** The grant message branch requires kind:

```js
    if (
      data.type === 'lemma:edit-grant' && typeof data.id === 'string'
      && typeof data.field === 'string' && typeof data.kind === 'string'
    ) {
      startEditing(data.id, data.field, data.kind)
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/preview-bridge-dom.spec.ts`
Expected: PASS (17 tests: 13 prior + 4 new).

---

### Task 3: SPA — kind matrix, composable, page wiring

**Files:**
- Modify: `admin/src/composables/useCanvasBridge.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`
- Test: `admin/src/__tests__/canvas-bridge.spec.ts`, `admin/src/__tests__/canvas-page.spec.ts`

**Interfaces:**
- Consumes: Task 2's message shapes; existing `blockTypeOfBlock`/`patchBlockDataById` FieldEditor routing (unchanged); `proseRichFieldName`.
- Produces: `useCanvasBridge` — `onEditRequest(cb: (id: string, field: string) => void)`, `editGrant(id: string, field: string, kind: EditKind): void`, `onTextChanged(cb: (id: string, field: string, payload: { html?: string; text?: string }) => void)`; exported `type EditKind = 'rich' | 'string' | 'text'`. Page — `editableKindOf(id, field): EditKind | null`.

- [ ] **Step 1: Update/extend the composable tests**

In `admin/src/__tests__/canvas-bridge.spec.ts`, the v3 edit-messages test changes shape — replace its body's edit parts:

```ts
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:edit-request', id: 'b1', field: 'heading', nonce: bridge.nonce },
      }),
    )
    expect(req).toHaveBeenCalledWith('b1', 'heading')

    bridge.editGrant('b1', 'heading', 'string')
    expect(postSpy).toHaveBeenCalledWith(
      { type: 'lemma:edit-grant', id: 'b1', field: 'heading', kind: 'string', nonce: bridge.nonce },
      'https://site.test',
    )

    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'lemma:text-changed',
          id: 'b1',
          field: 'heading',
          text: 'plain',
          nonce: bridge.nonce,
        },
      }),
    )
    expect(text).toHaveBeenCalledWith('b1', 'heading', { text: 'plain' })
    window.dispatchEvent(
      new MessageEvent('message', {
        data: {
          type: 'lemma:text-changed',
          id: 'b1',
          field: 'body',
          html: '<p>x</p>',
          nonce: bridge.nonce,
        },
      }),
    )
    expect(text).toHaveBeenCalledWith('b1', 'body', { html: '<p>x</p>' })
```

(A request without `field` must NOT dispatch — add after the first dispatch:)

```ts
    window.dispatchEvent(
      new MessageEvent('message', {
        data: { type: 'lemma:edit-request', id: 'b1', nonce: bridge.nonce },
      }),
    )
    expect(req).toHaveBeenCalledTimes(1)
```

- [ ] **Step 2: Update/extend the canvas-page tests**

In `admin/src/__tests__/canvas-page.spec.ts`:

**(a)** Mock shape updates:

```ts
    editRequest?: (id: string, field: string) => void
```

and in `instance`:

```ts
      onEditRequest: (cb: (id: string, field: string) => void) => (callbacks.editRequest = cb),
      onTextChanged: (
        cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
      ) => (callbacks.textChanged = cb),
```

and the callbacks type for textChanged:

```ts
    textChanged?: (id: string, field: string, payload: { html?: string; text?: string }) => void
```

**(b)** Replace the v3 grant test with the matrix test:

```ts
  it('edit-request grants per the kind matrix; everything else is denied', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    const wrapper = mountPage()
    await flushPromises()

    // Prose rich field -> rich.
    bridge.callbacks.editRequest?.('prose0000003', 'body')
    // Plain string field -> string.
    bridge.callbacks.editRequest?.('blockaaa0001', 'title')
    await flushPromises()
    expect(bridge.instance.editGrant).toHaveBeenCalledWith('prose0000003', 'body', 'rich')
    expect(bridge.instance.editGrant).toHaveBeenCalledWith('blockaaa0001', 'title', 'string')

    bridge.instance.editGrant.mockClear()
    bridge.callbacks.editRequest?.('blockaaa0001', 'nope') // unknown field
    bridge.callbacks.editRequest?.('missing', 'title') // unknown block
    bridge.callbacks.editRequest?.('prose0000003', 'title') // field not on prose type
    await flushPromises()
    expect(bridge.instance.editGrant).not.toHaveBeenCalled()
    wrapper.unmount()
  })
```

**(c)** Update the v3 text-changed tests to the payload shape:
- The "wrong field / non-prose IGNORED" test's calls become
  `bridge.callbacks.textChanged?.('prose0000003', 'title', { html: '<p>evil</p>' })` and
  `bridge.callbacks.textChanged?.('blockaaa0001', 'nope', { text: 'evil' })`, and its
  final assertions keep proving both blocks unchanged (title `'A'` now must be
  asserted for field `title` — keep the existing assertions and ADD:)

```ts
    // Kind mismatch is ALSO denied: rich payload for a string field.
    bridge.callbacks.textChanged?.('blockaaa0001', 'title', { html: '<b>evil</b>' })
```

- The "text-changed patches the tree" test's call becomes
  `bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>typed in stage</p>' })`.
- The "Apply awaits the flush" test's `mockImplementationOnce` becomes
  `bridge.callbacks.textChanged?.('prose0000003', 'body', { html: '<p>final keystroke</p>' })`.

**(d)** Add a string-patch test:

```ts
  it('a string text-changed patches the plain value into the tree', async () => {
    mintMock.mockResolvedValue({ token: 't', themeUrl: 'https://site.test/_preview/tok1' })
    saveMock.mockResolvedValue(undefined)
    const wrapper = mountPage()
    await flushPromises()

    bridge.callbacks.textChanged?.('blockaaa0001', 'title', { text: 'Retitled' })
    await flushPromises()
    await wrapper.find('[data-test="canvas-save"]').trigger('click')
    await flushPromises()
    const saved = saveMock.mock.calls[saveMock.mock.calls.length - 1]![0] as {
      fields: { body: { id: string; data: Record<string, unknown> }[] }
    }
    expect(saved.fields.body.find((b) => b.id === 'blockaaa0001')!.data.title).toBe('Retitled')
    wrapper.unmount()
  })
```

- [ ] **Step 3: Run to verify failure**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts src/__tests__/canvas-page.spec.ts`
Expected: FAIL — old signatures.

- [ ] **Step 4: Implement the composable**

In `admin/src/composables/useCanvasBridge.ts`:

```ts
export type EditKind = 'rich' | 'string' | 'text'
```

Slot/branch/API updates:

```ts
  let editRequestCb: ((id: string, field: string) => void) | null = null
  let textChangedCb:
    | ((id: string, field: string, payload: { html?: string; text?: string }) => void)
    | null = null
```

```ts
    if (
      data.type === 'lemma:edit-request' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string'
    ) {
      editRequestCb?.(data.id, data.field)
    }
    if (
      data.type === 'lemma:text-changed' &&
      typeof data.id === 'string' &&
      typeof data.field === 'string'
    ) {
      if (typeof data.html === 'string') textChangedCb?.(data.id, data.field, { html: data.html })
      else if (typeof data.text === 'string') {
        textChangedCb?.(data.id, data.field, { text: data.text })
      }
    }
```

(`BridgeMessage` gains `text?: string`.)

```ts
    onEditRequest(cb: (id: string, field: string) => void): void {
      editRequestCb = cb
    },
    onTextChanged(
      cb: (id: string, field: string, payload: { html?: string; text?: string }) => void,
    ): void {
      textChangedCb = cb
    },
    editGrant(id: string, field: string, kind: EditKind): void {
      post({ type: 'lemma:edit-grant', id, field, kind })
    },
```

- [ ] **Step 5: Implement the page wiring**

In `admin/src/pages/content/[type]/[uuid]/design/[locale].vue`, replace the v3
`proseFieldOf`/`onEditRequest`/`onTextChanged` block (import `EditKind` from
the composable):

```ts
/**
 * The grant/patch matrix (editable-string-fields spec §4) — the ONE authority
 * both paths use: prose rich field -> 'rich'; schema string -> 'string';
 * schema plain text -> 'text'; everything else -> null (deny).
 */
function editableKindOf(id: string, field: string): EditKind | null {
  const slug = fieldEditorRef.value?.blockTypeOfBlock(id)
  const blockType = slug ? allBlockTypes.value?.find((t) => t.slug === slug) : undefined
  if (!blockType) return null
  const schemaField = (blockType.schema ?? []).find((f) => f.name === field)
  if (!schemaField) return null
  const type = schemaField.type
  const format = (schemaField as { format?: string }).format
  if (type === 'text' && format === 'rich') {
    return proseRichFieldName(blockType) === field ? 'rich' : null
  }
  if (type === 'string') return 'string'
  if (type === 'text') return 'text'
  return null
}

bridge.onEditRequest((id, field) => {
  const kind = editableKindOf(id, field)
  if (kind !== null) bridge.editGrant(id, field, kind)
})

bridge.onTextChanged((id, field, payload) => {
  // Re-validate (v3 pin, matrix-shaped): edit messages are requests, not
  // authority — the payload key must match the re-derived kind.
  const kind = editableKindOf(id, field)
  if (kind === null) return
  if (kind === 'rich' && typeof payload.html === 'string') {
    fieldEditorRef.value?.patchBlockDataById(id, field, payload.html)
  } else if (kind !== 'rich' && typeof payload.text === 'string') {
    fieldEditorRef.value?.patchBlockDataById(id, field, payload.text)
  }
})
```

(`proseRichFieldName` import stays — the matrix uses it for the rich arm.)

- [ ] **Step 6: Run to verify pass**

Run: `cd admin && pnpm vitest run src/__tests__/canvas-bridge.spec.ts src/__tests__/canvas-page.spec.ts && pnpm type-check`
Expected: PASS, type-check clean.

---

### Task 4: Docs, full gates, STAGE (stop for commit authorization)

**Files:**
- Modify: `packages/lemma-render/README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: README**

Append to the canvas edit-in-place paragraph in `packages/lemma-render/README.md`:

```markdown
Plain string/text fields join in via the opt-in `|editable_text` filter:
`{{ data.heading|editable_text('heading') }}` marks the value's rendered
location (annotated renders only; live output is byte-identical to the plain
emission). Apply it ONLY to whole-element text emissions — never inside HTML
attributes (`alt`, `href`), where it would emit broken markup in preview —
and keep existing `{% if %}` guards: a conditionally omitted field stays
inspector-first. The admin validates every edit against the block schema, so
a mistyped field name simply never becomes editable.
```

- [ ] **Step 2: CHANGELOG**

Append to `[Unreleased]` after the edit-in-place bullet:

```markdown
- Editable string fields (canvas v4): themes opt plain string/text fields
  into edit-in-place with `{{ data.heading|editable_text('heading') }}` —
  single-line strings commit on Enter, multiline text keeps newlines, and
  the admin's schema-derived kind matrix is the grant/patch authority. The
  starter theme adopts it across hero/section/quote/image/cta (never
  unwrapping conditional emissions; attribute values stay unfiltered).
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
  packages/lemma-render \
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
feat(admin): editable string fields — |editable_text opt-in for the canvas

- New self-escaping Twig filter marks theme-declared string/text field
  emissions as edit regions in annotated renders (live output is
  byte-identical to plain emission); starter theme adopts it across
  hero/section/quote/image/cta without touching conditionals
- Protocol v4: edit-request carries {id, field} from the region under
  the double-click; edit-grant carries a schema-derived kind
  (rich|string|text); commits post innerHTML for rich, innerText for
  plain (markup can never persist); string Enter commits-and-exits
- The admin's editableKindOf matrix is the sole grant AND patch
  authority — unknown fields, non-prose rich, and kind-mismatched
  payloads are ignored
```

Recorded manual/browser acceptance (report as outstanding): editing the CTA label inside its `<a>`, multiline quote text, blank-heading click target, plaintext-only paste behavior — plus the earlier canvas items.
