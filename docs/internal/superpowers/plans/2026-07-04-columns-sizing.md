# Columns Block Sizing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The `columns` block gains `widths` (ratio presets) and `align` (vertical alignment) enum fields, rendered as allowlisted modifier-class tokens.

**Architecture:** Two additive enum fields on the `columns` schema. The template emits tokens by LOOKUP in a literal Twig map (`layout → widths → token`) — the single derivation site; mismatch/unknown/absent emits no width token (base CSS is equal columns). `align` emits a token only for non-default values. `blocks.css` pins one rule per token. Existing content renders byte-identically; the dev instance gets a schema-only additive update.

**Tech Stack:** PHP 8.3, Twig 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-07-04-columns-sizing-design.md`

## Global Constraints

- Exact tokens (contract §2) — the literal vocabulary, never derived by concatenation:
  `lemma-block-columns--w-50-50 | --w-33-67 | --w-67-33 | --w-25-75 | --w-75-25 | --w-33-33-33 | --w-25-50-25 | --w-50-25-25 | --w-25-25-50 | --align-top | --align-center | --align-bottom`.
- Mismatched layout/preset, unknown value, absent field → NO width token. `align: stretch`/absent → no align token.
- Schema change is additive; NO content rewrite anywhere (dev update is `updateSchema` only).
- No sandbox/policy change, no CACHE_VERSION bump.
- Session conventions: stage only; commit on "commit all"; CHANGELOG updated.

---

### Task 1: Schema + template + CSS + tests

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (columns schema, ~line 68)
- Modify: `packages/lemma-render/themes/default/templates/blocks/columns.twig`
- Modify: `packages/lemma-render/themes/default/assets/blocks.css` (columns section, ~line 107)
- Modify: `tests/Integration/Render/BlockLibraryRenderTest.php` (new cases), `tests/Integration/Content/SeedBlockTypesTest.php` (field pin), `tests/Integration/Render/StarterTemplatesTest.php` (fixture)

- [ ] **Step 1: Failing render tests** — append to `BlockLibraryRenderTest`:

```php
    public function testColumnsWidthPresetsEmitExactAllowlistedTokens(): void
    {
        $two = $this->render([[
            'id' => 'colw1', 'type' => 'columns',
            'data' => ['layout' => '2', 'widths' => '33-67',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringContainsString('lemma-block-columns--w-33-67', $two);

        // Mismatch (3-col preset on a 2-col layout): NO width token at all.
        $mismatch = $this->render([[
            'id' => 'colw2', 'type' => 'columns',
            'data' => ['layout' => '2', 'widths' => '33-33-33',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--w-', $mismatch);

        // Absent fields: byte-compatible with today's markup (no new tokens).
        $plain = $this->render([[
            'id' => 'colw3', 'type' => 'columns',
            'data' => ['layout' => '2', 'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--w-', $plain);
        self::assertStringNotContainsString('--align-', $plain);
    }

    public function testColumnsAlignEmitsTokensOnlyForNonDefaults(): void
    {
        $center = $this->render([[
            'id' => 'cola1', 'type' => 'columns',
            'data' => ['layout' => '2', 'align' => 'center',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringContainsString('lemma-block-columns--align-center', $center);

        $stretch = $this->render([[
            'id' => 'cola2', 'type' => 'columns',
            'data' => ['layout' => '2', 'align' => 'stretch',
                'col_1' => [], 'col_2' => [], 'col_3' => []],
        ]]);
        self::assertStringNotContainsString('--align-', $stretch);
    }
```

Run: `vendor/bin/phpunit --filter=testColumns tests/Integration/Render/BlockLibraryRenderTest.php` — Expected: FAIL.

- [ ] **Step 2: Schema** — the `columns` definition gains (after `layout`):

```php
                    ['name' => 'widths', 'type' => 'enum', 'enum' => [
                        '50-50', '33-67', '67-33', '25-75', '75-25',
                        '33-33-33', '25-50-25', '50-25-25', '25-25-50',
                    ]],
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['stretch', 'top', 'center', 'bottom']],
```

- [ ] **Step 3: Template** — `blocks/columns.twig` becomes:

```twig
{% set layout = data.layout|default('2') %}
{# Exact-token allowlist (columns-sizing spec §2): the ONE derivation site.
   A preset absent from the current layout's row — mismatch, unknown, absent —
   emits no token; base CSS is equal columns. align tokens only for
   non-defaults; stretch/absent keeps today's markup byte-identical. #}
{% set widthTokens = {
  '2': {'50-50': 'lemma-block-columns--w-50-50', '33-67': 'lemma-block-columns--w-33-67',
        '67-33': 'lemma-block-columns--w-67-33', '25-75': 'lemma-block-columns--w-25-75',
        '75-25': 'lemma-block-columns--w-75-25'},
  '3': {'33-33-33': 'lemma-block-columns--w-33-33-33', '25-50-25': 'lemma-block-columns--w-25-50-25',
        '50-25-25': 'lemma-block-columns--w-50-25-25', '25-25-50': 'lemma-block-columns--w-25-25-50'},
} %}
{% set alignTokens = {'top': 'lemma-block-columns--align-top', 'center': 'lemma-block-columns--align-center', 'bottom': 'lemma-block-columns--align-bottom'} %}
{% set widthToken = widthTokens[layout][data.widths|default('')]|default('') %}
{% set alignToken = alignTokens[data.align|default('')]|default('') %}
<div class="lemma-block lemma-block-columns lemma-block-columns--{{ layout }}{% if widthToken %} {{ widthToken }}{% endif %}{% if alignToken %} {{ alignToken }}{% endif %}">
  <div class="lemma-block-columns__col">{{ blocks(data.col_1) }}</div>
  <div class="lemma-block-columns__col">{{ blocks(data.col_2) }}</div>
  {% if layout == '3' %}<div class="lemma-block-columns__col">{{ blocks(data.col_3) }}</div>{% endif %}
</div>
```

(Verify Twig nested-hash access `widthTokens[layout][…]` under the sandbox — if the linter/node allowlist rejects chained subscripts on literals, hoist: `{% set row = widthTokens[layout]|default({}) %}` then `row[…]`.)

- [ ] **Step 4: CSS** — in the columns section of `blocks.css`, after the `--2`/`--3` rules:

```css
/* Width presets (columns-sizing spec §2): one rule per allowlisted token. */
.lemma-block-columns--w-50-50 { grid-template-columns: 1fr 1fr; }
.lemma-block-columns--w-33-67 { grid-template-columns: 1fr 2fr; }
.lemma-block-columns--w-67-33 { grid-template-columns: 2fr 1fr; }
.lemma-block-columns--w-25-75 { grid-template-columns: 1fr 3fr; }
.lemma-block-columns--w-75-25 { grid-template-columns: 3fr 1fr; }
.lemma-block-columns--w-33-33-33 { grid-template-columns: 1fr 1fr 1fr; }
.lemma-block-columns--w-25-50-25 { grid-template-columns: 1fr 2fr 1fr; }
.lemma-block-columns--w-50-25-25 { grid-template-columns: 2fr 1fr 1fr; }
.lemma-block-columns--w-25-25-50 { grid-template-columns: 1fr 1fr 2fr; }
/* Vertical alignment (default = stretch, the base grid behavior). */
.lemma-block-columns--align-top { align-items: start; }
.lemma-block-columns--align-center { align-items: center; }
.lemma-block-columns--align-bottom { align-items: end; }
```

…and extend the existing mobile collapse rule so presets stack too:

```css
@media (max-width: 40rem) {
  .lemma-block-columns--2, .lemma-block-columns--3,
  [class*="lemma-block-columns--w-"] { grid-template-columns: 1fr; }
}
```

(Replace the existing 40rem block with this — verify the attribute-selector
approach passes phpcs-adjacent CSS conventions in the file; if the file avoids
attribute selectors, enumerate the nine `--w-` classes instead.)

- [ ] **Step 5: Seeder pin + fixture**

`SeedBlockTypesTest` (in the container-constraints cluster):

```php
        $columns = array_column($repo->findBySlug('columns')['schema'], null, 'name');
        self::assertContains('33-67', $columns['widths']['enum']);
        self::assertContains('25-25-50', $columns['widths']['enum']);
        self::assertSame(['stretch', 'top', 'center', 'bottom'], $columns['align']['enum']);
```

`StarterTemplatesTest::fixture()` columns entry gains `'widths' => '33-67', 'align' => 'center'`.

- [ ] **Step 6: Run**

`vendor/bin/phpunit tests/Integration/Render/ tests/Integration/Content/SeedBlockTypesTest.php` — Expected: PASS.

---

### Task 2: Dev-instance additive schema update + gates + stage

- [ ] **Step 1: Additive update (schema ONLY — review pin: no content rewrite)**

One-off script via the app bootstrap (scratchpad, not committed):

```php
$context = Glueful\Framework::create('<lemma root>')->boot()->getContext();
$repo = app($context, App\Content\Blocks\BlockTypeRepository::class);
$row = $repo->findBySlug('columns');
$names = array_column($row['schema'], 'name');
if (!in_array('widths', $names, true)) {
    $schema = $row['schema'];
    // insert after 'layout' to match the seeder ordering
    array_splice($schema, 1, 0, [[
        'name' => 'widths', 'type' => 'enum',
        'enum' => ['50-50','33-67','67-33','25-75','75-25','33-33-33','25-50-25','50-25-25','25-25-50'],
    ], ['name' => 'align', 'type' => 'enum', 'enum' => ['stretch','top','center','bottom']]]);
    $repo->updateSchema((string) $row['uuid'], $schema, (string) $row['label'],
        $row['icon'] ?? null, $row['description'] ?? null, $row['category'] ?? null);
    echo "columns schema updated (additive)\n";
} else {
    echo "already present\n";
}
```

Expected: `columns schema updated (additive)`. Verify no entry/region rows changed (`SELECT count(*) FROM entries` etc. unchanged — the script touches `lemma_block_types` only by construction).

- [ ] **Step 2: Full gates** — `vendor/bin/phpunit && composer run phpcs` — green.
- [ ] **Step 3: CHANGELOG** — Added: columns block `widths` ratio presets + `align` vertical alignment (exact-token modifier classes from a single template allowlist; mismatch/absent = equal columns; additive schema, no content rewrite).
- [ ] **Step 4: Stage** the touched files. NO commit.
