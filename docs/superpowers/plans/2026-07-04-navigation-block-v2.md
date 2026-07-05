# Navigation Block v2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The navigation block becomes a real site-nav component: menu picker, alignment/size/active/hover styling, CSS-only submenus (hover or click reveal) with a configurable indicator, active-state via a canonical `current_path`, and optional per-item Lucide icons on menu items.

**Architecture — three cleanly separated workstreams:**
1. **Render context (`current_path`)**: one path normalizer shared with the page-cache key (`RenderPageCache::normalizePath()`, extracted public static), fed into the base render context by every RenderController handler.
2. **Navigation block**: six additive enums, a tree-rendering template (ul/li, one submenu level, grandchildren flattened, hover `:hover/:focus-within` vs click `<details name>` markup branch), modifier-class CSS, BlockCard menu-picker special case.
3. **Menu-item icons**: nullable `icon` column folded into the ORIGINAL `002_CreateNavigationItemsTable.php` + explicit dev/test DB sync, MenuTreeDTO validation, repository read/write, resolver tree, admin tree-editor input, template render via `icon()`.

**Tech Stack:** PHP 8.3, Twig 3, PHPUnit; Vue 3 + Nuxt UI, vitest.

**Spec:** `docs/superpowers/specs/2026-07-04-navigation-block-v2-design.md`

## Global Constraints

- `current_path` normalization = the page-cache normalizer, ONE implementation (P1 pin). Item URLs are canonical by construction (EntryTargetResolver); tests cover collapsed default locale, prefixed non-default locale, root-mounted path.
- `<details name>` exclusivity is progressive enhancement (P2 pin): unsupported browsers may keep multiple dropdowns open — acceptable v1; native open/close works everywhere.
- Click-mode parents with their own url repeat it as the FIRST child item (summary swallows navigation).
- Item icon grammar: `[a-z0-9]+(-[a-z0-9]+)*` (Lucide-only, no `brand:`), nullable, ≤ 64 chars; malformed → dot-path 422 at tree save; unknown-but-well-formed → label renders alone.
- Pre-launch migration rule: the icon column goes into `002_CreateNavigationItemsTable.php`; NO new migration file; dev (`lemma`) and test (`lemma_test`) DBs get explicit `ALTER TABLE` syncs (Task 3 Step 1) so existing local DBs don't fail tests or admin reads.
- **Deliberate spec correction:** the block's markup changes structurally for everyone (ul/li list, always-emitted default modifier classes — one grammar, no conditional class emission). "Byte-compatible" is relaxed to "visually identical at defaults": the default modifiers' CSS reproduces today's look. Menus with children start rendering them — the bug being fixed.
- Session conventions: stage only; commit on "commit all"; no attribution; CHANGELOG updated.

---

## Workstream 1 — render context `current_path`

### Task 1: shared normalizer + context value + grammar tests

**Files:**
- Modify: `packages/lemma-render/src/Http/Middleware/RenderPageCache.php` (extract `normalizePath()`)
- Modify: `packages/lemma-render/src/Http/Controllers/RenderController.php` (set + expose `current_path`)
- Test: `tests/Integration/Render/RenderPipelineTest.php` (+ `RegionRenderingTest` or a nav test in Task 2 for active-state grammar)

**Interfaces:**
- Produces: `RenderPageCache::normalizePath(string $path): string` (public static; duplicate slashes collapsed, trailing slash trimmed, root stays `/` — the EXACT body currently inside `key()`); Twig context `current_path: string` on every RenderController render (content, homepage, previews, themed error bodies).

- [ ] **Step 1: Failing context test — NO production template changes (P1 pin)**

The probe is a TEST-ONLY DB template override (the templates system IS the
fixture mechanism; `lemma_render_templates` truncates per test, production
layout.twig is never touched and public HTML/cache bodies never gain a debug
artifact). In `RenderPipelineTest`:

```php
    public function testCurrentPathIsInTemplateContextNormalized(): void
    {
        // Unit: the shared normalizer (HTTP-path-only — see Step 2 pin).
        self::assertSame('/pages/ctx', RenderPageCache::normalizePath('/pages//ctx/'));
        self::assertSame('/', RenderPageCache::normalizePath('/'));
        self::assertSame('/', RenderPageCache::normalizePath('//'));

        // End-to-end: a TEST-ONLY layout override prints the probe; the row
        // lives in the test DB and truncates with the test.
        $this->seedPresentationEntry(['title' => 'Ctx'], 'ctx');
        $base = (string) file_get_contents(
            $this->appContext()->getBasePath()
                . '/packages/lemma-render/themes/default/templates/layout.twig',
        );
        (new \Glueful\Lemma\Render\Templates\TemplateRepository($this->connection()))->save(
            'default',
            'layout.twig',
            str_replace('<body>', "<body>\n<!-- test-probe:{{ current_path }} -->", $base),
            null,
        );
        $res = $this->handle(Request::create('/pages//ctx/', 'GET'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('test-probe:/pages/ctx', (string) $res->getContent());
    }
```

(Imports: `RenderPageCache`. Verify the DB layout override is picked up by the
full handle() path — BlocksRenderingTest proves DB overrides work for block
templates; layout.twig is loaded through the same override-aware loader. If a
policy lint rejects the copied layout (filesystem templates aren't linted, DB
ones are — the copied layout calls allowlisted functions only, so it should
pass), fall back to asserting current_path through Task 2's nav active-state
full-stack tests and keep only the normalizer unit cases here.)

- [ ] **Step 2: Extract the normalizer**

In `RenderPageCache`, replace the body of `key()`'s normalization with a call:

```php
    /**
     * The ONE request-path normalizer (nav-v2 spec §3): cache keys and
     * current_path must never disagree on what "the path" is.
     *
     * SCOPE PIN (review P2): HTTP-path hygiene ONLY — strips any query string,
     * collapses duplicate slashes, trims the trailing slash (root stays '/').
     * It performs NO canonical routing decisions: no locale collapse, no
     * root-mount conversion, no redirect logic. Those happen BEFORE render
     * (301s + CanonicalPathBuilder). This is not a canonical URL builder —
     * do not grow it into one.
     */
    public static function normalizePath(string $path): string
    {
        $path = (string) strtok($path, '?');
        $collapsed = (string) preg_replace('#/{2,}#', '/', '/' . trim($path, " \t"));
        $trimmed = rtrim($collapsed, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    private function key(string $path): string
    {
        return "render:{$this->theme}:" . rawurlencode(self::normalizePath($path));
    }
```

- [ ] **Step 3: RenderController**

Add a member + set it at the top of every public entry point that has a Request (`home`, `page`, the preview/session handlers — grep `public function` and cover each; the themed 404/410 paths inherit whatever the failing request set):

```php
    private string $currentPath = '/';
    // …in each handler, first line with the request in hand:
    $this->currentPath = RenderPageCache::normalizePath($request->getPathInfo());
```

In `render()`'s base `$context`, alongside `site`:

```php
            'current_path' => $this->currentPath,
```

(+ `use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;` — verify no import cycle; it's a sibling namespace, fine.)

Layout debug hook (Step 1's probe), right after `<body>` in `layout.twig`:

```twig
  <!-- path:{{ current_path }} -->
```

- [ ] **Step 4: Run** — the new test passes; the whole render group stays green (`vendor/bin/phpunit tests/Integration/Render/`). NOTE: `current_path` is a plain context VALUE — no TemplatePolicy change, no CACHE_VERSION bump (the policy governs tags/filters/functions, not variables).

---

## Workstream 2 — navigation block rendering/styling/dropdowns

### Task 2: schema + template + CSS + menu picker + tests

**Files:**
- Modify: `app/Content/Blocks/StarterBlockTypes.php` (navigation schema)
- Rewrite: `packages/lemma-render/themes/default/templates/blocks/navigation.twig`
- Modify: `packages/lemma-render/themes/default/assets/blocks.css` (navigation section)
- Modify: `admin/src/fields/components/blocks/BlockCard.vue` (menu picker)
- Tests: `BlockLibraryRenderTest` (nav cases), `SeedBlockTypesTest` (enum pins), `StarterTemplatesTest` (fixture), `admin/src/__tests__/` (picker case — extend an existing blocks-editor spec or add `navigationBlockEditor.spec.ts`)

- [ ] **Step 1: Failing render tests** — append to `BlockLibraryRenderTest` (menus need seeding: use `MenuRepository` like `RenderPipelineTest` does — check its menu-seeding helper and mirror; the pack is installed, `Glueful\Lemma\Navigation\MenuRepository`):

```php
    /** Seed a menu with one plain item, one parent (own url) + child + grandchild. */
    private function seedNavMenu(): void
    {
        $menus = new \Glueful\Lemma\Navigation\MenuRepository($this->connection());
        $menu = $menus->createMenu('main', 'Main');
        $menus->replaceTree((string) $menu['uuid'], (int) $menu['lock_version'], [
            ['uuid' => 'navitem00001', 'parent_uuid' => null, 'position' => 0, 'kind' => 'url',
                'url' => '/about', 'labels' => ['en' => 'About'], 'entry_uuid' => null],
            ['uuid' => 'navitem00002', 'parent_uuid' => null, 'position' => 1, 'kind' => 'url',
                'url' => '/services', 'labels' => ['en' => 'Services'], 'entry_uuid' => null],
            ['uuid' => 'navitem00003', 'parent_uuid' => 'navitem00002', 'position' => 0, 'kind' => 'url',
                'url' => '/services/web', 'labels' => ['en' => 'Web'], 'entry_uuid' => null],
            ['uuid' => 'navitem00004', 'parent_uuid' => 'navitem00003', 'position' => 0, 'kind' => 'url',
                'url' => '/services/web/seo', 'labels' => ['en' => 'SEO'], 'entry_uuid' => null],
        ]);
    }
```

(VERIFY `replaceTree`'s exact flat-item shape against `MenuRepository` before
writing — field names/lock semantics must match; adjust the helper, keep the
tree shape: about, services▸web▸seo.)

```php
    public function testNavigationRendersTreeWithHoverSubmenus(): void
    {
        $this->seedNavMenu();
        $out = $this->render([[
            'id' => 'nav2a', 'type' => 'navigation',
            'data' => ['menu' => 'main', 'align' => 'center', 'size' => 'lg',
                'active_style' => 'pill', 'hover_style' => 'underline'],
        ]]);
        foreach ([
            'lemma-block-navigation--align-center', 'lemma-block-navigation--size-lg',
            'lemma-block-navigation--active-pill', 'lemma-block-navigation--hover-underline',
            'lemma-block-navigation--reveal-hover',
        ] as $token) {
            self::assertStringContainsString($token, $out);
        }
        self::assertStringContainsString('__item--parent', $out);   // services has children
        self::assertStringNotContainsString('<details', $out);      // hover mode
        self::assertStringContainsString('href="/services/web/seo"', $out); // grandchild FLATTENED in
        self::assertStringContainsString('<svg', $out);             // chevron-down indicator default
    }

    public function testNavigationClickModeUsesDetailsAndRepeatsParentUrl(): void
    {
        $this->seedNavMenu();
        $out = $this->render([[
            'id' => 'nav2b', 'type' => 'navigation',
            'data' => ['menu' => 'main', 'submenu_trigger' => 'click', 'submenu_icon' => 'none'],
        ]]);
        self::assertStringContainsString('<details name="nav-nav2b"', $out);
        // Parent url repeated as first child (summary swallows navigation).
        self::assertStringContainsString('href="/services"', $out);
        self::assertStringNotContainsString('<svg', $out);          // icon: none
    }

    public function testNavigationActiveStateMatchesCanonicalCurrentPath(): void
    {
        $this->seedNavMenu();
        // Render with current_path in context (the block test helper renders via
        // createTemplate — pass current_path explicitly, mirroring what
        // RenderController now injects).
        $out = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'nav2c', 'type' => 'navigation', 'data' => ['menu' => 'main']]],
            'current_path' => '/about',
        ]);
        self::assertStringContainsString('__item--active', $out);
        $inactive = $this->env()->createTemplate('{{ blocks(list) }}')->render([
            'list' => [['id' => 'nav2d', 'type' => 'navigation', 'data' => ['menu' => 'main']]],
            'current_path' => '/elsewhere',
        ]);
        self::assertStringNotContainsString('__item--active', $inactive);
    }
```

**Grammar cases (P1)** — in `RenderPipelineTest` or a new
`NavigationActiveStateTest`: seed an ENTRY menu item pointing at (a) a
default-locale entry (canonical `/pages/x` or root-mounted `/x`), (b) a
non-default-locale route (`/fr/...`); render the real pages through
`handle()` with a header region containing the navigation block; assert the
active class lands on exactly the matching item. (Reuses SeedsPublishedContent
+ RegionRepository; this is the full-stack proof that item urls and
current_path meet in canonical space.)

Run: expect FAIL.

- [ ] **Step 2: Schema** — navigation definition gains (after `orientation`):

```php
                    ['name' => 'align', 'type' => 'enum', 'enum' => ['start', 'center', 'end']],
                    ['name' => 'size', 'type' => 'enum', 'enum' => ['sm', 'md', 'lg']],
                    ['name' => 'active_style', 'type' => 'enum', 'enum' => ['underline', 'pill', 'none']],
                    ['name' => 'hover_style', 'type' => 'enum', 'enum' => ['color', 'underline', 'pill']],
                    ['name' => 'submenu_icon', 'type' => 'enum',
                        'enum' => ['chevron-down', 'chevron-right', 'plus', 'none']],
                    ['name' => 'submenu_trigger', 'type' => 'enum', 'enum' => ['hover', 'click']],
```

- [ ] **Step 3: Template** — `blocks/navigation.twig`, full rewrite:

```twig
{% set items = menu(data.menu|default('')) %}
{% set reveal = data.submenu_trigger|default('hover') %}
{% set subicon = data.submenu_icon|default('chevron-down') %}
<div class="lemma-block lemma-block-navigation lemma-block-navigation--{{ data.orientation|default('horizontal') }} lemma-block-navigation--align-{{ data.align|default('start') }} lemma-block-navigation--size-{{ data.size|default('md') }} lemma-block-navigation--active-{{ data.active_style|default('underline') }} lemma-block-navigation--hover-{{ data.hover_style|default('color') }} lemma-block-navigation--reveal-{{ reveal }}">
  {% if items %}
    <nav class="lemma-block-navigation__nav">
      <ul class="lemma-block-navigation__list">
        {% for item in items %}
          {# One submenu level (spec §4): grandchildren FLATTEN into the dropdown. #}
          {% set kids = [] %}
          {% for c in item.children|default([]) %}
            {% set kids = kids|merge([c]) %}
            {% for g in c.children|default([]) %}{% set kids = kids|merge([g]) %}{% endfor %}
          {% endfor %}
          {% set active = current_path is defined and item.url == current_path %}
          {% if kids is empty %}
            <li class="lemma-block-navigation__item{% if active %} lemma-block-navigation__item--active{% endif %}">
              <a href="{{ item.url }}">{% if item.icon|default('') %}{{ icon(item.icon) }}{% endif %}{{ item.label }}</a>
            </li>
          {% elseif reveal == 'click' %}
            <li class="lemma-block-navigation__item lemma-block-navigation__item--parent{% if active %} lemma-block-navigation__item--active{% endif %}">
              <details class="lemma-block-navigation__details" name="nav-{{ block.id }}">
                <summary>{% if item.icon|default('') %}{{ icon(item.icon) }}{% endif %}{{ item.label }}{% if subicon != 'none' %}{{ icon(subicon) }}{% endif %}</summary>
                <ul class="lemma-block-navigation__submenu">
                  {% if item.url|default('') %}<li><a href="{{ item.url }}">{{ item.label }}</a></li>{% endif %}
                  {% for kid in kids %}
                    <li{% if current_path is defined and kid.url == current_path %} class="lemma-block-navigation__item--active"{% endif %}>
                      <a href="{{ kid.url }}">{% if kid.icon|default('') %}{{ icon(kid.icon) }}{% endif %}{{ kid.label }}</a>
                    </li>
                  {% endfor %}
                </ul>
              </details>
            </li>
          {% else %}
            <li class="lemma-block-navigation__item lemma-block-navigation__item--parent{% if active %} lemma-block-navigation__item--active{% endif %}">
              {% if item.url|default('') %}<a href="{{ item.url }}">{% if item.icon|default('') %}{{ icon(item.icon) }}{% endif %}{{ item.label }}{% if subicon != 'none' %}{{ icon(subicon) }}{% endif %}</a>{% else %}<span tabindex="0">{% if item.icon|default('') %}{{ icon(item.icon) }}{% endif %}{{ item.label }}{% if subicon != 'none' %}{{ icon(subicon) }}{% endif %}</span>{% endif %}
              <ul class="lemma-block-navigation__submenu">
                {% for kid in kids %}
                  <li{% if current_path is defined and kid.url == current_path %} class="lemma-block-navigation__item--active"{% endif %}>
                    <a href="{{ kid.url }}">{% if kid.icon|default('') %}{{ icon(kid.icon) }}{% endif %}{{ kid.label }}</a>
                  {% endfor %}
              </ul>
            </li>
          {% endif %}
        {% endfor %}
      </ul>
    </nav>
  {% endif %}
</div>
```

(FIX the typo above at implementation — the hover-branch inner loop must close
`</li>` before `{% endfor %}`. `item.icon` is Workstream 3 — `|default('')`
makes the template safe before AND after that lands. `block.id` is available
in block templates — verify against faq.twig's usage.)

- [ ] **Step 4: CSS** — replace the navigation section in `blocks.css`:

```css
/* navigation — links from a menu (structured source); v2: tree + styling */
.lemma-block-navigation__nav { display: block; }
.lemma-block-navigation__list {
  display: flex; gap: var(--space-4); flex-wrap: wrap; align-items: center;
  list-style: none; margin: 0; padding: 0;
}
.lemma-block-navigation--vertical .lemma-block-navigation__list { flex-direction: column; gap: var(--space-2); align-items: stretch; }
.lemma-block-navigation--align-start .lemma-block-navigation__list { justify-content: flex-start; }
.lemma-block-navigation--align-center .lemma-block-navigation__list { justify-content: center; }
.lemma-block-navigation--align-end .lemma-block-navigation__list { justify-content: flex-end; }
.lemma-block-navigation--size-sm { font-size: 0.85rem; }
.lemma-block-navigation--size-md { font-size: 0.95rem; }
.lemma-block-navigation--size-lg { font-size: 1.1rem; }
.lemma-block-navigation__list a, .lemma-block-navigation__list summary, .lemma-block-navigation__list span[tabindex] {
  color: var(--muted); text-decoration: none; cursor: pointer;
  display: inline-flex; align-items: center; gap: 0.35em;
  padding: 0.25em 0.5em; border-radius: var(--radius);
}
/* hover styles */
.lemma-block-navigation--hover-color .lemma-block-navigation__list a:hover,
.lemma-block-navigation--hover-color .lemma-block-navigation__list summary:hover { color: var(--ink); }
.lemma-block-navigation--hover-underline .lemma-block-navigation__list a:hover,
.lemma-block-navigation--hover-underline .lemma-block-navigation__list summary:hover { color: var(--ink); text-decoration: underline; text-underline-offset: 0.35em; }
.lemma-block-navigation--hover-pill .lemma-block-navigation__list a:hover,
.lemma-block-navigation--hover-pill .lemma-block-navigation__list summary:hover { color: var(--accent-ink); background: var(--accent); }
/* active styles */
.lemma-block-navigation--active-underline .lemma-block-navigation__item--active > a,
.lemma-block-navigation--active-underline li.lemma-block-navigation__item--active > a { color: var(--ink); text-decoration: underline; text-underline-offset: 0.35em; }
.lemma-block-navigation--active-pill .lemma-block-navigation__item--active > a,
.lemma-block-navigation--active-pill li.lemma-block-navigation__item--active > a { color: var(--accent-ink); background: var(--accent); }
/* submenus */
.lemma-block-navigation__item--parent { position: relative; }
.lemma-block-navigation__submenu {
  list-style: none; margin: 0; padding: var(--space-2);
  min-width: 12rem; display: none;
  background: var(--bg); border: 1px solid var(--line);
  border-radius: var(--radius); box-shadow: var(--shadow);
}
.lemma-block-navigation--horizontal .lemma-block-navigation__item--parent > .lemma-block-navigation__submenu,
.lemma-block-navigation--horizontal .lemma-block-navigation__details > .lemma-block-navigation__submenu {
  position: absolute; top: 100%; left: 0; z-index: 30;
}
.lemma-block-navigation--reveal-hover .lemma-block-navigation__item--parent:hover > .lemma-block-navigation__submenu,
.lemma-block-navigation--reveal-hover .lemma-block-navigation__item--parent:focus-within > .lemma-block-navigation__submenu { display: block; }
.lemma-block-navigation__details > summary { list-style: none; }
.lemma-block-navigation__details > summary::-webkit-details-marker { display: none; }
.lemma-block-navigation__details[open] > .lemma-block-navigation__submenu { display: block; }
.lemma-block-navigation--vertical .lemma-block-navigation__submenu {
  position: static; display: none; box-shadow: none; border: 0; padding-left: var(--space-4);
}
.lemma-block-navigation--vertical.lemma-block-navigation--reveal-hover .lemma-block-navigation__item--parent:hover > .lemma-block-navigation__submenu,
.lemma-block-navigation--vertical.lemma-block-navigation--reveal-hover .lemma-block-navigation__item--parent:focus-within > .lemma-block-navigation__submenu,
.lemma-block-navigation--vertical .lemma-block-navigation__details[open] > .lemma-block-navigation__submenu { display: block; }
.lemma-block-navigation__submenu a { display: flex; padding: 0.35em 0.5em; }
```

(Delete the old `.lemma-block-navigation__nav a` rules — the new
list-scoped rules replace them; hover default `--hover-color` reproduces the
old muted→ink look, satisfying the visually-identical-at-defaults pin.)

- [ ] **Step 5: Menu picker (BlockCard)** — extend the cosmetic-rules area added for columns:

```ts
import { useMenus } from '@/queries/navigation'
// …
const menus = props.block.type === 'navigation' ? useMenus() : null
const menuOptions = computed(() =>
  (menus?.data.value ?? []).map((m) => ({ label: m.name ?? m.slug, value: m.slug })),
)
```

Template: before the generic `<component :is>` branch, a navigation-specific branch:

```html
<UFormField v-else-if="block.type === 'navigation' && f.name === 'menu'" label="menu" name="menu">
  <USelect
    :model-value="(block.data.menu as string) ?? ''"
    :items="menuOptions"
    class="w-full"
    data-test="nav-menu-select"
    @update:model-value="(v: unknown) => patchData('menu', v)"
  />
</UFormField>
```

(Verify `useMenus`'s return shape (`NavMenuSummary`: name/slug fields) and
whether calling a composable conditionally is safe here — it isn't (rules of
hooks): call `useMenus()` UNCONDITIONALLY and only USE it for navigation
blocks; the query is cached/shared so the cost is one fetch per session.)

Vitest case (extend the columns-ergonomics pattern or new spec file): a
`navigation` block renders `[data-test="nav-menu-select"]`; a `button` block
renders no such select.

- [ ] **Step 6: Fixture + seeder pin + run**

`StarterTemplatesTest` navigation fixture gains
`'align' => 'center', 'size' => 'md', 'submenu_trigger' => 'hover'`.
`SeedBlockTypesTest`: pin the six new enums (mirror the columns pin style).
Run PHP render group + SeedBlockTypes + admin vitest — Expected: PASS.

---

## Workstream 3 — menu-item icons (storage → API → admin → resolver → render)

### Task 3: icon column + validation + resolver + admin input

**Files:**
- Modify: `packages/lemma-navigation/migrations/002_CreateNavigationItemsTable.php` (fold-in)
- Modify: `packages/lemma-navigation/src/MenuRepository.php` (write + read `icon`)
- Modify: `packages/lemma-navigation/src/MenuResolver.php` (tree carries `icon`)
- Modify: `packages/lemma-navigation/src/Http/MenuTreeDTO.php` (validate)
- Modify: `admin/src/pages/navigation/components/MenuTreeEditor.vue` (+ queries/types as needed)
- Tests: navigation pack tests (find: `grep -rl MenuTreeDTO tests/`), render case (nav template already renders `item.icon` from Task 2)

- [ ] **Step 1: Fold-in + EXPLICIT dev/test DB sync (pre-launch rule)**

Migration: in `002_CreateNavigationItemsTable.php`, after the `url` column:

```php
            // Optional Lucide icon name rendered before the label (nav-v2 spec §5).
            $table->string('icon', 64)->nullable();
```

Then sync BOTH existing local DBs — the migration is already recorded as
applied on them, so the fold-in alone would leave them missing the column
(failing tests and admin reads):

```bash
PGPASSWORD=FxVPPXnsj3dcPujCdJ psql -h localhost -U lemma_app -d lemma \
  -c "ALTER TABLE navigation_items ADD COLUMN IF NOT EXISTS icon varchar(64)"
PGPASSWORD=FxVPPXnsj3dcPujCdJ psql -h localhost -U lemma_app -d lemma_test \
  -c "ALTER TABLE navigation_items ADD COLUMN IF NOT EXISTS icon varchar(64)"
```

Verify: `\d navigation_items` shows `icon` on both; `migrations` table
untouched (same filename, already recorded). Confirm the test harness
(`composer run test:migrate`) reports nothing pending.

- [ ] **Step 2: DTO validation (failing test first)** — find the MenuTreeDTO test file and add: an item with `'icon' => 'external-link'` round-trips; `'icon' => 'Bad Name'` and `'icon' => 'brand:github'` produce dot-path errors (`items.0.icon`); absent/null icon passes. Then implement in `MenuTreeDTO` (alongside the url/label rules):

```php
            $icon = $item['icon'] ?? null;
            if ($icon !== null && $icon !== '') {
                if (!is_string($icon) || strlen($icon) > 64
                    || preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $icon) !== 1) {
                    $errors["{$p}.icon"] = ['icon must be a lucide name (lowercase, hyphenated)'];
                }
            }
```

(Match the file's actual error-array conventions exactly; thread `icon` into
the flat rows the DTO produces for `replaceTree`.)

- [ ] **Step 3: Repository + resolver** — `replaceTree` writes `icon` (nullable) per row; the tree read includes it; `MenuResolver::children()` adds `'icon' => $row['icon'] ?? null` to each node and updates the docblock shape (`label, url, entry, icon, children`). Update the `MenuReader` contract docblock in lemma-contracts if it documents the shape. Pack tests: resolver test asserts icon carried (and null default).

- [ ] **Step 4: Admin input** — `MenuTreeEditor.vue`: each item row gains an optional icon input (`data-test="nav-item-icon"`, placeholder `e.g. external-link`) bound to the item's `icon`, plus a live preview `<UIcon :name="'i-lucide-' + item.icon" v-if="item.icon">`. Follow the file's existing per-item field pattern (labels/url). Extend its vitest spec if one exists; otherwise assert at the tree-DTO payload level.

- [ ] **Step 5: Render proof** — Task 2's template already renders `item.icon`; add one `BlockLibraryRenderTest` case: seed a menu item with `icon: 'external-link'` → nav output contains `<svg` inside that item's `<a>`; item with `icon: 'no-such-glyph'` renders label only (no svg, no raw name).

- [ ] **Step 6: OpenAPI + client** — `composer run docs:openapi && cd admin && pnpm gen:api` (the tree endpoints' schema changed).

---

### Task 4: dev updateSchema + full gates + CHANGELOG + stage

- [ ] **Step 1:** Dev-DB additive block-schema update for the six navigation enums (same scratchpad-script pattern as columns; schema only, no content rewrite).
- [ ] **Step 2:** Full gates: `vendor/bin/phpunit && composer run phpcs`; `cd admin && pnpm vitest run && pnpm type-check && pnpm lint`.
- [ ] **Step 3:** CHANGELOG `[Unreleased]`: navigation block v2 (menu picker, align/size/active/hover enums, CSS-only submenus hover/click with `<details name>` progressive enhancement, curated indicator icon, canonical `current_path` active state via the shared page-cache normalizer) + menu-item optional Lucide icons (column folded into the create-table migration; validated at tree save; rendered via `icon()`).
- [ ] **Step 4:** Stage everything. NO commit — wait for "commit all".
