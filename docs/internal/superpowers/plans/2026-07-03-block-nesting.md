# Container Blocks / Nesting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Block-type schemas may contain `blocks` fields (containers: sections, columns) with a centralized 3-level depth cap enforced by validation, rendering, and the editor.

**Architecture:** Amendment to the shipped block builder (spec: `docs/superpowers/specs/2026-07-03-page-block-builder-design.md`, "Amendment: Container blocks / nesting"). One prohibition lifted (`blocks` inside block schemas), one invariant added (`MAX = 3`, named per surface, cross-asserted by tests), recursion made explicit (validator depth parameter; async-component registry entry breaking the SPA import cycle; render-scoped depth counter in the reset family).

**Tech Stack:** unchanged (PHP 8.4/Glueful, Twig 3.27, Vue 3 SPA).

## Global Constraints

- **Commit gate:** STAGE at the end; commit only on explicit authorization. No attribution trailers.
- **phpcs** via `vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"`; `composer boundaries` after backend work.
- **Amendment pins (verbatim):** `BlockDepth::MAX = 3` backend-authoritative; render pack keeps its OWN `RenderContextExtension::MAX_BLOCK_DEPTH = 3` (no `App\` import) with an app-side equality test; SPA `MAX_BLOCK_DEPTH = 3` asserted in specs. Validation recurses through an internal `validateAt(..., int $depth)` — never blindly through public `validate()`. Registry maps `blocks` via `defineAsyncComponent` (kills the static import cycle). Depth counter resets in the `resetTags()`/`setAssetBase()` family; over-deep data → prod `''` / debug placeholder / log-once. **No `TemplatePolicy` change, no `CACHE_VERSION` bump.** `localized`/`filterable` rejections in block schemas STAY.

---

### Task 1: Backend — depth-aware validation + schema-rule lift

**Files:**
- Create: `app/Content/Blocks/BlockDepth.php`
- Modify: `app/Content/Validation/FieldValidator.php`, `app/Content/Blocks/BlockTypeRepository.php` (assertBlockSchema)
- Test: extend `tests/Integration/Content/BlocksValidationTest.php`, `tests/Integration/Content/BlockTypeRepositoryTest.php`

**Interfaces:**
- Produces: `App\Content\Blocks\BlockDepth::MAX = 3` (int const, the authoritative value); `FieldValidator::validate()` signature UNCHANGED (delegates to private `validateAt($schema, $payload, $strict, $depth = 0)`).

- [ ] **Step 1: Failing tests**

`BlocksValidationTest` additions (setUp gains a container type):

```php
        $this->blocks->create(['slug' => 'section', 'label' => 'Section', 'category' => 'Layout',
            'schema' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'content', 'type' => 'blocks', 'block_types' => ['hero']],
            ]]);
```

```php
    public function testNestedBlocksValidateWithComposedDotPaths(): void
    {
        // Depth 1 (body) → 2 (section.content) — valid nesting.
        $clean = $this->clean(['body' => [
            ['type' => 'section', 'data' => ['title' => 'S', 'content' => [
                ['type' => 'hero', 'data' => ['heading' => 'Nested']],
            ]]],
        ]]);
        self::assertSame('Nested', $clean['body'][0]['data']['content'][0]['data']['heading']);
        self::assertSame(12, strlen($clean['body'][0]['data']['content'][0]['id'])); // ids generated at depth

        // Nested field error carries the COMPOSED path.
        try {
            $this->clean(['body' => [
                ['type' => 'section', 'data' => ['content' => [
                    ['type' => 'hero', 'data' => ['heading' => 123]],
                ]]],
            ]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('body.0.content.0.heading', $e->errors());
        }
    }

    public function testDepthFourErrorsAtTheExactPath(): void
    {
        // section > section > section holds depth 3; its nested content field would
        // put items at depth 4 → the FIELD errors, nothing deeper validates.
        $deep = ['type' => 'section', 'data' => ['content' => [
            ['type' => 'section', 'data' => ['content' => [
                ['type' => 'section', 'data' => ['content' => [
                    ['type' => 'hero', 'data' => ['heading' => 'too deep']],
                ]]],
            ]]],
        ]]];
        try {
            $this->clean(['body' => [$deep]]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            self::assertArrayHasKey('body.0.content.0.content.0.content', $errors);
            self::assertStringContainsString('nesting depth', $errors['body.0.content.0.content.0.content']);
        }
        // Exactly at MAX (3) is fine.
        $ok = ['type' => 'section', 'data' => ['content' => [
            ['type' => 'section', 'data' => ['content' => [
                ['type' => 'hero', 'data' => ['heading' => 'depth three']],
            ]]],
        ]]];
        $this->clean(['body' => [$ok]]);
        $this->addToAssertionCount(1);
    }
```

NOTE: the section type's `content` allowlist lists only `hero`, but validation is
picker-only — nesting `section` inside `section` (outside the allowlist) is the
allowlist-acceptance test at depth, reused deliberately.

`BlockTypeRepositoryTest`: REPLACE the `['name' => 'sections', 'type' => 'blocks']`
rejection case (rule lifted) with an acceptance + the two rules that stay:

```php
    public function testBlockSchemasMayNestBlocksButStillRejectLocalizedAndFilterable(): void
    {
        $r = $this->repo();
        // Lifted (nesting amendment): blocks fields inside block schemas are allowed.
        $r->create(['slug' => 'section', 'label' => 'Section',
            'schema' => [['name' => 'content', 'type' => 'blocks']]]);
        self::assertNotNull($r->findBySlug('section'));

        foreach (
            [
                [['name' => 'title', 'type' => 'string', 'localized' => true]],
                [['name' => 'flag', 'type' => 'boolean', 'filterable' => true, 'filter_type' => 'boolean']],
            ] as $i => $schema
        ) {
            try {
                $r->create(['slug' => "bad{$i}", 'label' => 'Bad', 'schema' => $schema]);
                self::fail("expected SchemaParseException for case {$i}");
            } catch (SchemaParseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
```

(Delete the old `testBlockSchemaRulesRejectNestingLocalizationAndFilterable`.)

- [ ] **Step 2: Verify fail** — `vendor/bin/phpunit tests/Integration/Content/BlocksValidationTest.php tests/Integration/Content/BlockTypeRepositoryTest.php` → new tests FAIL (nesting rejected at save).

- [ ] **Step 3: Implement**

`app/Content/Blocks/BlockDepth.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Blocks;

/**
 * The ONE authoritative block-nesting depth cap (nesting amendment §A2): the entry's
 * blocks field is depth 1, children 2, grandchildren 3 (section → columns →
 * elements). The render pack and the SPA each carry their OWN named constant (the
 * pack cannot import App\) — tests assert the three surfaces agree, because the cap
 * is one rule expressed three times.
 */
final class BlockDepth
{
    public const MAX = 3;
}
```

`FieldValidator`: rename the public entry's body into a private depth-carrying method —
public signature unchanged:

```php
    /** @param array<string,mixed> $payload ... (existing docblock unchanged) */
    public function validate(ContentTypeSchema $schema, array $payload, bool $strict = false): array
    {
        return $this->validateAt($schema, $payload, $strict, 0);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function validateAt(ContentTypeSchema $schema, array $payload, bool $strict, int $depth): array
    {
        // …the ENTIRE former validate() body, unchanged except the blocks branch:
        //     [$cleanBlocks, $blockErrors] = $this->validateBlocks($field->name, $value, $strict, $depth);
    }
```

`validateBlocks(string $fieldName, mixed $value, bool $strict, int $depth)`:
items sit at `$depth + 1`; first check:

```php
        if ($depth + 1 > BlockDepth::MAX) {
            return [[], [$fieldName => sprintf(
                'exceeds maximum block nesting depth (%d)',
                BlockDepth::MAX,
            )]];
        }
```

…and the per-block recursion becomes `$this->validateAt($schemas[$type], $data, $strict, $depth + 1)`.
(The nested field's own dot-path composes because the CALLER prefixes `{$path}.` onto
the inner errors — already the v1 mechanism; nested blocks errors compose transitively.)

`BlockTypeRepository::assertBlockSchema()`: delete the `'blocks'` rejection branch
and update the docblock (two rules remain: localized, filterable).

- [ ] **Step 4: Verify pass** — both test files + `vendor/bin/phpunit tests/Unit/Content/ tests/Integration/Content/`. Gates: phpcs, boundaries.

---

### Task 2: Render — depth counter in the reset family

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Http/Controllers/RenderController.php` (reset call), `packages/lemma-render/README.md` (one line)
- Test: extend `tests/Integration/Render/BlocksRenderingTest.php`

**Interfaces:**
- Produces: `RenderContextExtension::MAX_BLOCK_DEPTH = 3` (public const); `resetBlockDepth(): void`.

- [ ] **Step 1: Failing tests** (`BlocksRenderingTest` additions):

```php
    public function testNestedBlocksComposeThroughContainerTemplates(): void
    {
        $this->saveBlockTemplate('section', 'SECTION[{{ data.title }}|{{ blocks(data.content) }}]');
        $this->saveBlockTemplate('hero', 'HERO[{{ data.heading }}]');
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'a', 'type' => 'section', 'data' => ['title' => 'S', 'content' => [
                ['id' => 'b', 'type' => 'hero', 'data' => ['heading' => 'Inner']],
            ]]],
        ]]);
        self::assertStringContainsString('SECTION[S|HERO[Inner]]', $out);
    }

    public function testOverDeepDataRendersNothingAndTheCounterRecovers(): void
    {
        self::assertSame(
            \App\Content\Blocks\BlockDepth::MAX,
            \Glueful\Lemma\Render\RenderContextExtension::MAX_BLOCK_DEPTH,
        ); // §A2: the surfaces agree

        $this->saveBlockTemplate('nest', 'N({{ blocks(data.inner) }})');
        $this->saveBlockTemplate('leaf', 'LEAF');
        $wrap = fn (array $inner): array => ['id' => 'x', 'type' => 'nest', 'data' => ['inner' => $inner]];
        // depth 4: nest > nest > nest > leaf — the innermost list renders EMPTY.
        $deep = [$wrap([$wrap([$wrap([['id' => 'l', 'type' => 'leaf', 'data' => []]])])])];
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => $deep]);
        self::assertStringNotContainsString('LEAF', $out);
        self::assertStringContainsString('N(N(N()))', preg_replace('/<!--.*?-->/s', '', $out) ?? $out);

        // The counter is render-scoped: a fresh render at depth 1 works immediately.
        $this->container()->get(RenderContextExtension::class)->resetBlockDepth();
        $ok = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'l2', 'type' => 'leaf', 'data' => []],
        ]]);
        self::assertStringContainsString('LEAF', $ok);
    }

    public function testDepthCounterUnwindsAfterAMidRenderException(): void
    {
        // The failure mode §A5 names: a block template THROWS while nested blocks()
        // frames are on the stack. try/finally must unwind every frame — a leaked
        // count would make the NEXT render start above depth 1 and falsely hit the
        // cap. No resetBlockDepth() between renders here: the unwind alone must hold.
        $this->saveBlockTemplate('nest', 'N({{ blocks(data.inner) }})');
        $this->saveBlockTemplate('leaf', 'LEAF');
        $this->saveBlockTemplate('boom', '{{ undefined_function_boom() }}');

        try {
            $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
                ['id' => 'a', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'b', 'type' => 'boom', 'data' => []], // throws at depth 2
                ]]],
            ]]);
            self::fail('expected the boom template to throw');
        } catch (\Throwable) {
            $this->addToAssertionCount(1);
        }

        // A FULL-DEPTH (3) render right after must succeed — proves depth is 0 again.
        $out = $this->env()->createTemplate("{{ blocks(list) }}")->render(['list' => [
            ['id' => 'c', 'type' => 'nest', 'data' => ['inner' => [
                ['id' => 'd', 'type' => 'nest', 'data' => ['inner' => [
                    ['id' => 'e', 'type' => 'leaf', 'data' => []],
                ]]],
            ]]],
        ]]);
        self::assertStringContainsString('N(N(LEAF))', $out);
    }
```

- [ ] **Step 2: Verify fail.**

- [ ] **Step 3: Implement**

`RenderContextExtension`:

```php
    /** Nesting amendment §A2: mirrors App\Content\Blocks\BlockDepth::MAX (the pack
     *  cannot import App\); an app-side test asserts the two agree. */
    public const MAX_BLOCK_DEPTH = 3;

    /** Render-scoped nesting depth (see resetBlockDepth). */
    private int $blockDepth = 0;

    /** Reset-before-every-render family (with resetTags/setAssetBase): an exception
     *  mid-render must not leak depth into the next response. */
    public function resetBlockDepth(): void
    {
        $this->blockDepth = 0;
    }
```

`blocks()` — wrap the body:

```php
        if (!is_array($list) || !array_is_list($list)) {
            return '';
        }
        if ($this->blockDepth + 1 > self::MAX_BLOCK_DEPTH) {
            // Data written around the API (validation caps authored content at MAX).
            $this->logBlockMiss('(depth)', 'exceeds maximum block nesting depth');
            return $this->debug
                ? '<div style="border:1px dashed red;padding:.5rem">Blocks beyond maximum nesting depth</div>'
                : '';
        }
        $this->blockDepth++;
        try {
            // …existing loop unchanged…
            return implode('', $html);
        } finally {
            $this->blockDepth--;
        }
```

`RenderController::render()` reset block gains `$this->extension->resetBlockDepth();`
next to `resetTags()`.

README "Blocks in templates" section gains: `Containers nest via
{{ blocks(data.region) }} up to 3 levels; deeper data renders nothing.`

- [ ] **Step 4: Verify pass** — `vendor/bin/phpunit tests/Integration/Render/` (all, incl. every existing test). Gates: phpcs, boundaries.

---

### Task 3: SPA — cycle-free recursion + depth-aware editor + builder rule change

**Files:**
- Modify: `admin/src/fields/registry.ts`, `admin/src/fields/components/BlocksField.vue`, `admin/src/components/ContentTypeFields.vue`, `admin/src/queries/blockTypes.ts`
- Test: extend `admin/src/__tests__/blocksField.spec.ts`

- [ ] **Step 1: Failing tests** (`blocksField.spec.ts` — add a container type to `defaultTypes()`):

```ts
  {
    uuid: 'bt5',
    slug: 'section',
    label: 'Section',
    icon: null,
    category: 'Layout',
    description: null,
    active: true,
    schema: [{
      name: 'content', type: 'blocks', required: false, localized: false, filterable: false,
    }],
  },
```

```ts
  it('recurses: adds a child block inside a section', async () => {
    const model = ref<{ id: string; type: string; data: Record<string, unknown> }[]>([
      { id: 's1', type: 'section', data: { content: [] } },
    ])
    const wrapper = mount(BlocksField, {
      props: {
        field,
        modelValue: model.value,
        'onUpdate:modelValue': (v: typeof model.value) => (model.value = v),
      },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-s1"]').trigger('click')
    await flushPromises() // async component resolution
    // The nested BlocksField renders its own add-block button.
    const nested = wrapper.findAll('[data-test="add-block"]')
    expect(nested.length).toBeGreaterThanOrEqual(2)
    await nested[1]!.trigger('click')
    await wrapper.findAll('[data-test="picker-item-hero"]')[0]!.trigger('click')
    await flushPromises()
    const content = model.value[0]!.data.content as { type: string }[]
    expect(content).toHaveLength(1)
    expect(content[0]!.type).toBe('hero')
  })

  it('shows the max-depth notice instead of an editor at depth 3', async () => {
    expect(MAX_BLOCK_DEPTH).toBe(3) // §A2 mirror assertion
    const wrapper = mount(BlocksField, {
      props: { field, modelValue: [{ id: 's1', type: 'section', data: { content: [] } }], depth: 3 },
    })
    await flushPromises()
    await wrapper.find('[data-test="block-toggle-s1"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="max-depth-notice"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="add-block"]')).toHaveLength(1) // only the outer one
  })
```

(import `MAX_BLOCK_DEPTH` from `@/queries/blockTypes`. If async-component resolution
needs an extra tick in jsdom, `await flushPromises()` twice — the assertions' meaning
is the contract.)

- [ ] **Step 2: Verify fail.**

- [ ] **Step 3: Implement**

`blockTypes.ts`:

```ts
/** Mirrors the backend App\Content\Blocks\BlockDepth::MAX (nesting amendment §A2). */
export const MAX_BLOCK_DEPTH = 3
```

`registry.ts` — break the static cycle (§A4):

```ts
import { defineAsyncComponent, type Component } from 'vue'
// BlocksField recurses through fieldComponent(); loading it async removes the
// registry ↔ widget static import cycle (nesting amendment §A4).
const BlocksField = defineAsyncComponent(() => import('./components/BlocksField.vue'))
```

(drop the static `import BlocksField…` line; the map entry stays `blocks: BlocksField`.)

`BlocksField.vue`:

- Props gain depth: `const props = defineProps<{ field: FieldDef; depth?: number }>()` with
  `const depth = computed(() => props.depth ?? 1)`.
- Import `MAX_BLOCK_DEPTH` from `@/queries/blockTypes`.
- Nested rendering: where block-card fields render through `fieldComponent`, blocks-type
  fields either recurse with `:depth="depth + 1"` or show the notice:

```vue
          <template v-for="f in bySlug.get(block.type)?.schema ?? []" :key="f.name">
            <p
              v-if="toFieldDef(f).type === 'blocks' && depth >= MAX_BLOCK_DEPTH"
              class="rounded border border-dashed border-default px-2 py-1.5 text-xs text-muted"
              data-test="max-depth-notice"
            >
              “{{ f.name }}”: maximum nesting depth ({{ MAX_BLOCK_DEPTH }}) reached.
            </p>
            <component
              :is="fieldComponent(toFieldDef(f).type)"
              v-else
              :field="toFieldDef(f)"
              :depth="toFieldDef(f).type === 'blocks' ? depth + 1 : undefined"
              :model-value="block.data[f.name]"
              @update:model-value="(v: unknown) => patchData(block.id, f.name, v)"
            />
          </template>
```

(Non-blocks widgets ignore the undefined `depth` prop.)

`ContentTypeFields.vue` — the block-type context STOPS excluding `blocks` (§A4):

```ts
const typeItems = computed(() => [...FIELD_TYPES])
```

(with a comment: nesting amendment lifted the exclusion; `localized`/`filterable`
switches stay hidden in the block-type context — unchanged.)

- [ ] **Step 4: Verify** — `pnpm type-check`, `pnpm test`, `pnpm lint` (real exit codes).

---

### Task 4: Docs + full verification + STAGE

- [ ] **Step 1: CHANGELOG** — append to the block-builder `[Unreleased]` bullet:

```markdown
  Follow-up (same day): **container blocks** — block schemas may nest `blocks`
  fields (sections, columns) up to a centralized depth of 3 (`BlockDepth::MAX`,
  mirrored and test-asserted in the render pack and SPA); depth-aware validation
  via an explicit internal depth parameter; recursive block editor with a
  max-depth notice and a cycle-free async registry entry; render-scoped depth
  counter in the reset family. No sandbox-policy change.
```

- [ ] **Step 2: Full verification + STAGE** *(commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
cd admin && pnpm type-check && pnpm test && cd ..
git add app/Content packages/lemma-render admin/src CHANGELOG.md \
        docs/superpowers/specs/2026-07-03-page-block-builder-design.md \
        tests/Integration/Content tests/Integration/Render
```

Expected: green (single pre-existing skip). STOP — when authorized:

```bash
git commit -m "feat(content): container blocks — nested blocks fields with a centralized depth cap

Block schemas may nest blocks fields (nesting amendment): assertBlockSchema
lifts only the no-nesting rule (localized/filterable stay rejected).
BlockDepth::MAX=3 is authoritative; the render pack and SPA carry mirrored
constants cross-asserted by tests. FieldValidator recurses through an explicit
validateAt(...,\$depth) (public validate() unchanged, depth 0); depth-4 items
error at the exact composed dot path. blocks() guards with a render-scoped
depth counter (reset family, try/finally; over-deep data renders ''/debug
placeholder, log-once) — no TemplatePolicy change, no CACHE_VERSION bump.
Recursive BlocksField via a cycle-free async registry entry, depth prop, and a
max-depth notice; the block-type schema builder now offers blocks fields."
```

---

## Self-Review Notes (already applied)

- **Amendment coverage:** A1 child model → Task 1 (schema-rule lift + nested validation reusing `{id,type,data}`); A2 three named constants + cross-assertions → Tasks 1 (`BlockDepth`), 2 (render const + equality test), 3 (SPA const + spec assertion); A3 `validateAt` explicit depth, public signature unchanged, exact-path depth error → Task 1; A4 async-component cycle break + `depth` prop + max-depth notice + `ContentTypeFields` un-exclusion → Task 3; A5 reset-family counter, try/finally, prod ''/debug placeholder/log-once, no policy bump → Task 2; A6 test list fully mapped.
- **Type consistency:** `validateBlocks(field, value, strict, depth)` matches its one caller; `resetBlockDepth()` named identically in extension, controller reset block, and the render test; `MAX_BLOCK_DEPTH` spelled identically in the pack const and the SPA export.
- **Judgement calls:** the depth-4 validation test nests `section` (outside `content`'s allowlist) deliberately — it doubles as the picker-only-at-depth proof; the render over-deep test strips HTML comments before asserting shape (prod comment vs debug placeholder both pass the "no LEAF" core assertion).
