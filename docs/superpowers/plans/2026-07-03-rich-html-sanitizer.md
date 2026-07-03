# Rich HTML Sanitizer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A TipTap-scoped allowlist HTML sanitizer enforced at save (`FieldValidator`, incl. rich fields inside blocks) and at render (`safe_html` Twig filter, fail-closed), unblocking the `rich_text` starter block.

**Architecture:** `RichHtmlSanitizer` contract (lemma-contracts, new `Content` ns) + `TipTapHtmlSanitizer` over `symfony/html-sanitizer` v8.1.1 with an additively-built allowlist. Save-time sanitization rides `FieldValidator`'s cleaned-payload path (blocks recursion covers nested rich fields for free); `safe_html` is soft-bound into the render extension with pinned escaped-fallback behavior. `safe_html` joins `TemplatePolicy::FILTERS`, `CACHE_VERSION → 3`.

**Tech Stack:** unchanged; `symfony/html-sanitizer` already installed. **Spec:** `docs/superpowers/specs/2026-07-03-rich-html-sanitizer-design.md`.

## Global Constraints

- **Commit gate:** STAGE at the end; commit only on explicit authorization. No attribution trailers. phpcs via `-q; echo $?`; `composer boundaries`.
- **Spec pins (verbatim):** allowlist built ADDITIVELY with the real API — `allowElement()`, `allowAttribute()`, `allowLinkSchemes(['http', 'https', 'mailto'])`, `allowRelativeLinks(true)` — **never `allowSafeElements()`-and-subtract**; explicit `withMaxInputLength(1_000_000)`; stripped-never-422; `safe_html` fail-closed = **unbound OR throwing sanitizer → `htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`**, `is_safe => ['html']` justified only by every path being pre-safe; non-string → `''`; `CACHE_VERSION = 3`.
- **Coordination:** this plan CREATES `RenderContextExtension::getFilters()` (it doesn't exist yet). The starter-library plan's `safe_url` step APPENDS to it — that plan gets amended (with the `rich_text` swap + `CACHE_VERSION = 4`) before the library executes, per spec §5.

## File Map

| File | Responsibility |
|---|---|
| `packages/lemma-contracts/src/Content/RichHtmlSanitizer.php` | contract |
| `app/Content/Sanitization/TipTapHtmlSanitizer.php` | additive allowlist over symfony/html-sanitizer |
| `app/Content/Validation/FieldValidator.php` (modify) | sanitize `format:rich` in the cleaned payload |
| `packages/lemma-render/src/RenderContextExtension.php` (modify) | `safe_html` filter (new `getFilters()`) |
| `packages/lemma-render/src/Templates/TemplatePolicy.php` (modify) | FILTERS + CACHE_VERSION=3 |
| `packages/lemma-render/src/LemmaRenderServiceProvider.php` (modify) | soft-bind sanitizer into the extension |
| `app/Providers/LemmaServiceProvider.php` (modify) | contract → impl binding |
| Tests: `tests/Unit/Content/Sanitization/TipTapHtmlSanitizerTest.php`, `tests/Integration/Content/RichFieldSanitizationTest.php`, extend `tests/Integration/Render/BlocksRenderingTest.php` | |

---

### Task 1: Contract + `TipTapHtmlSanitizer` + binding

**Files:**
- Create: `packages/lemma-contracts/src/Content/RichHtmlSanitizer.php`, `app/Content/Sanitization/TipTapHtmlSanitizer.php`, `app/Content/Sanitization/BlockProtocolRelativeLinks.php`, `tests/Unit/Content/Sanitization/TipTapHtmlSanitizerTest.php`
- Modify: `app/Providers/LemmaServiceProvider.php`

**Interfaces:**
- Produces: `RichHtmlSanitizer::sanitize(string $html): string`; `TipTapHtmlSanitizer` (no ctor deps — constructible anywhere); container binding `RichHtmlSanitizer::class → TipTapHtmlSanitizer`.

- [ ] **Step 1: Failing tests** — `tests/Unit/Content/Sanitization/TipTapHtmlSanitizerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Sanitization;

use App\Content\Sanitization\TipTapHtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class TipTapHtmlSanitizerTest extends TestCase
{
    private TipTapHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new TipTapHtmlSanitizer();
    }

    /** The attack matrix (spec §6): each payload must strip/neutralize. */
    public function testAttackMatrixStripsEverything(): void
    {
        $cases = [
            // [input, must-not-contain fragments]
            ['<p>x</p><script>alert(1)</script>', ['<script', 'alert(1)']],
            ['<p onclick="alert(1)">x</p>', ['onclick']],
            ['<img src=x onerror=alert(1)>', ['<img', 'onerror']],
            ['<a href="javascript:alert(1)">x</a>', ['javascript:']],
            ['<a href="JAVASCRIPT:alert(1)">x</a>', ['avascript:']], // case-variant
            ['<a href="jav&#x09;ascript:alert(1)">x</a>', ['ascript:alert']], // entity-obfuscated
            // allowRelativeLinks(true) treats network-path URLs as relative — the
            // custom attribute sanitizer must drop the href (P1 review finding).
            ['<a href="//evil.com">x</a>', ['//evil.com']],
            ['<p style="background:url(javascript:x)">x</p>', ['style=']],
            ['<svg onload=alert(1)><circle/></svg>', ['<svg', 'onload']],
            ['<a href="data:text/html,<script>x</script>">x</a>', ['data:']],
            ['<iframe src="https://evil"></iframe>', ['<iframe']],
            ['<object data="x"></object><embed src="x"><form action="x"></form>', ['<object', '<embed', '<form']],
            ['<p>unclosed <strong>mis<em>nested</strong></em>', ['<script']], // malformed: must not throw
        ];
        foreach ($cases as [$input, $fragments]) {
            $out = $this->sanitizer->sanitize($input);
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsStringIgnoringCase($fragment, $out, $input);
            }
        }
    }

    /** TipTap fidelity (spec §6): the allowed vocabulary round-trips unmangled. */
    public function testTipTapVocabularyRoundTrips(): void
    {
        $doc = '<h2>Title</h2><p>Body with <strong>bold</strong>, <em>italic</em>, '
            . '<s>strike</s> and <u>underline</u>.</p>'
            . '<ul><li>one</li><li>two</li></ul><ol><li>1</li></ol>'
            . '<blockquote><p>quote</p></blockquote><pre><code>code()</code></pre>'
            . '<p><a href="https://example.com/x">abs</a> <a href="/rel">rel</a> '
            . '<a href="mailto:a@b.c">mail</a></p><hr><p>line<br>break</p>';
        $out = $this->sanitizer->sanitize($doc);
        foreach (
            ['<h2>Title</h2>', '<strong>bold</strong>', '<em>italic</em>', '<s>strike</s>',
             '<u>underline</u>', '<li>one</li>', '<blockquote>', '<pre>', '<code>',
             'href="https://example.com/x"', 'href="/rel"', 'href="mailto:a@b.c"', '<hr', '<br'] as $keep
        ) {
            self::assertStringContainsString($keep, $out);
        }
    }

    /** The protocol-relative pin in isolation: // drops, legitimate hrefs survive. */
    public function testProtocolRelativeHrefIsDroppedWhileRelativeAndAbsoluteSurvive(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="//evil.com">pr</a><a href="/local">rel</a>'
            . '<a href="https://ok.example">abs</a><a href="mailto:a@b.c">mail</a>',
        );
        self::assertStringNotContainsString('//evil.com', $out);
        self::assertStringContainsString('>pr</a>', $out); // link text survives, href dropped
        self::assertStringContainsString('href="/local"', $out);
        self::assertStringContainsString('href="https://ok.example"', $out);
        self::assertStringContainsString('href="mailto:a@b.c"', $out);
    }

    public function testTaskListShapeSurvivesWithInputsStripped(): void
    {
        $out = $this->sanitizer->sanitize(
            '<ul data-type="taskList"><li data-checked="true">'
            . '<input type="checkbox" checked>done</li></ul>',
        );
        self::assertStringContainsString('data-type="taskList"', $out);
        self::assertStringContainsString('data-checked="true"', $out);
        self::assertStringNotContainsString('<input', $out);
    }

    public function testIdempotentAndLongInputSafe(): void
    {
        $doc = '<p>' . str_repeat('long content ', 5000) . '<strong>end</strong></p>'; // ~65KB
        $once = $this->sanitizer->sanitize($doc);
        self::assertSame($once, $this->sanitizer->sanitize($once)); // idempotent
        self::assertStringContainsString('<strong>end</strong>', $once); // no silent truncation
    }
}
```

- [ ] **Step 2: Verify fail** — classes not found.

- [ ] **Step 3: Implement**

`packages/lemma-contracts/src/Content/RichHtmlSanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Content;

/**
 * Sanitizes rich-editor HTML to the allowlisted TipTap vocabulary (sanitizer spec
 * §1–§2). The output is safe to render raw. Idempotent — sanitizing already-clean
 * content is a no-op, so save-time + render-time double application costs nothing.
 */
interface RichHtmlSanitizer
{
    public function sanitize(string $html): string;
}
```

`app/Content/Sanitization/TipTapHtmlSanitizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Sanitization;

use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The TipTap-scoped allowlist (sanitizer spec §2): built ADDITIVELY — an empty
 * config plus explicit allowElement()/allowAttribute() calls, never
 * allowSafeElements()-and-subtract (auditable, immune to upstream "safe" set
 * changes). Everything outside the vocabulary is STRIPPED, never rejected.
 * Fixed in code — not app-configurable (the TemplatePolicy stance).
 */
final class TipTapHtmlSanitizer implements RichHtmlSanitizer
{
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            // The engine's DEFAULT max input length silently truncates long
            // documents (spec §2 pinned gotcha) — set it explicitly.
            ->withMaxInputLength(1_000_000)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks(true);

        foreach (
            ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
             'pre', 'code', 'strong', 'em', 's', 'u', 'a', 'br', 'hr'] as $element
        ) {
            $config = $config->allowElement($element);
        }
        $config = $config
            ->allowAttribute('href', ['a'])
            // TipTap task lists: <ul data-type="taskList"><li data-checked="…">.
            // Checkbox <input>s are NOT allowlisted — CSS renders state from data-checked.
            ->allowAttribute('data-type', ['ul'])
            ->allowAttribute('data-checked', ['li'])
            // allowRelativeLinks(true) treats network-path (//host) URLs as relative
            // and would preserve them — the safe_url posture forbids exactly that
            // (spec §2 pin). Runs AFTER the default URL sanitizer.
            ->withAttributeSanitizer(new BlockProtocolRelativeLinks());

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return $this->sanitizer->sanitize($html);
    }
}
```

`app/Content/Sanitization/BlockProtocolRelativeLinks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Sanitization;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Drops protocol-relative hrefs (sanitizer spec §2 pin): allowRelativeLinks(true)
 * treats network-path URLs (//evil.com) as relative and preserves them — the
 * safe_url posture forbids exactly that. Null drops the attribute; every other
 * value passes through unchanged. Runs after the default URL sanitizer.
 */
final class BlockProtocolRelativeLinks implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['a'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['href'];
    }

    public function sanitizeAttribute(
        string $element,
        string $attribute,
        string $value,
        HtmlSanitizerConfig $config,
    ): ?string {
        return str_starts_with(ltrim($value), '//') ? null : $value;
    }
}
```

VERIFY against the installed v8.1.1 signatures if any call errors: (a) `allowElement(string $element, array|string $allowedAttributes = 'default')` may accept attributes inline — the separate `allowAttribute()` calls are equivalent and clearer; keep them; (b) `AttributeSanitizerInterface`'s exact namespace/method signatures (`Visitor\AttributeSanitizer\…`) and that custom sanitizers registered via `withAttributeSanitizer()` run AFTER the built-in URL sanitizer (they should — defaults are registered first; if not, assert order empirically via the `//evil.com` test). If `sanitize()` output entity-encodes differently than the fidelity test expects (e.g. attribute quoting), adjust the ASSERTION fragments to the component's canonical output, keeping their meaning.

`app/Providers/LemmaServiceProvider.php` — contract binding (mirror the `PreviewSessionVerifier → EnginePreviewSessionVerifier` style; `use` imports):

```php
            RichHtmlSanitizer::class => [
                'class' => TipTapHtmlSanitizer::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 4: Verify pass** — the unit file. Gates: phpcs, boundaries.

---

### Task 2: Save-time enforcement in `FieldValidator`

**Files:**
- Modify: `app/Content/Validation/FieldValidator.php`
- Create: `tests/Integration/Content/RichFieldSanitizationTest.php`

**Interfaces:**
- Produces: `FieldValidator::__construct(?Connection, ?ApplicationContext, ?BlockTypeRepository, ?RichHtmlSanitizer $sanitizer = null)` — lazy default to `new TipTapHtmlSanitizer()` when null (same fallback pattern as `blockTypes()`).

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Blocks\BlockTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

final class RichFieldSanitizationTest extends LemmaTestCase
{
    public function testTopLevelRichFieldIsSanitizedInTheCleanedPayload(): void
    {
        $validator = new FieldValidator($this->connection(), $this->appContext());
        $schema = ContentTypeSchema::fromArray([
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ['name' => 'note', 'type' => 'text'], // plain — must stay UNTOUCHED
        ]);
        $clean = $validator->validate($schema, [
            'body' => '<p>ok</p><script>alert(1)</script><p onclick="x">two</p>',
            'note' => '<script>kept verbatim — escaping is the renderer\'s job</script>',
        ]);
        self::assertStringNotContainsString('<script', $clean['body']);
        self::assertStringNotContainsString('onclick', $clean['body']);
        self::assertStringContainsString('<p>ok</p>', $clean['body']);
        self::assertStringContainsString('<script>', $clean['note']); // plain text untouched
    }

    public function testRichFieldInsideABlockIsSanitizedThroughTheRecursion(): void
    {
        $blocks = new BlockTypeRepository($this->connection());
        $blocks->create(['slug' => 'prose', 'label' => 'Prose', 'schema' => [
            ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
        ]]);
        $validator = new FieldValidator($this->connection(), $this->appContext(), $blocks);
        $schema = ContentTypeSchema::fromArray([['name' => 'content', 'type' => 'blocks']]);

        $clean = $validator->validate($schema, ['content' => [
            ['type' => 'prose', 'data' => ['body' => '<p>fine</p><svg onload=alert(1)></svg>']],
        ]]);
        $body = $clean['content'][0]['data']['body'];
        self::assertStringContainsString('<p>fine</p>', $body);
        self::assertStringNotContainsString('<svg', $body);
        self::assertStringNotContainsString('onload', $body);
    }
}
```

- [ ] **Step 2: Verify fail** — script tags survive the cleaned payload.

- [ ] **Step 3: Implement**

`FieldValidator`:

1. Ctor gains a fourth optional param + lazy accessor (imports: `use App\Content\Sanitization\TipTapHtmlSanitizer;`, `use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;`):

```php
        private ?RichHtmlSanitizer $sanitizer = null,
```

```php
    private function sanitizer(): RichHtmlSanitizer
    {
        return $this->sanitizer ??= new TipTapHtmlSanitizer();
    }
```

2. In `validateAt()`'s per-field flow, immediately after the `checkType()` pass (before the datetime normalization block):

```php
            // Rich HTML sanitizes at SAVE into the cleaned payload (sanitizer spec
            // §3): stored data is clean by construction. Blocks recursion routes
            // nested rich fields through this same line — zero special-casing.
            // Plain text fields stay untouched (escaping is the renderer's job).
            if ($field->type === 'text' && $field->format === 'rich' && is_string($value)) {
                $value = $this->sanitizer()->sanitize($value);
            }
```

- [ ] **Step 4: Verify pass** — new test + `tests/Unit/Content/FieldValidatorTest.php` + `tests/Integration/Content/`. Gates.

---

### Task 3: `safe_html` render filter + policy

**Files:**
- Modify: `packages/lemma-render/src/RenderContextExtension.php`, `packages/lemma-render/src/Templates/TemplatePolicy.php`, `packages/lemma-render/src/LemmaRenderServiceProvider.php`
- Test: extend `tests/Integration/Render/BlocksRenderingTest.php`

**Interfaces:**
- Produces: Twig filter `safe_html` (`is_safe => ['html']`); `RenderContextExtension` ctor gains `?RichHtmlSanitizer $htmlSanitizer = null` (after `bool $debug`); **new** `getFilters()` method (the library plan's `safe_url` will APPEND here later).

- [ ] **Step 1: Failing test** (add to `BlocksRenderingTest`):

```php
    public function testSafeHtmlSanitizesAndFailsClosed(): void
    {
        // Bound path: sanitizes and the output may render raw.
        $ext = $this->container()->get(RenderContextExtension::class);
        $out = $ext->safeHtml('<p>ok</p><script>alert(1)</script>');
        self::assertStringContainsString('<p>ok</p>', $out);
        self::assertStringNotContainsString('<script', $out);

        // Rendering through Twig: markup survives (is_safe), attacks don't.
        $rendered = $this->env()->createTemplate("{{ body|safe_html }}")
            ->render(['body' => '<p>hi</p><script>x</script>']);
        self::assertStringContainsString('<p>hi</p>', $rendered);
        self::assertStringNotContainsString('<script', $rendered);

        // FAIL-CLOSED (spec §4, exact): unbound sanitizer → ESCAPED output.
        $unbound = new RenderContextExtension(
            null,
            $this->container()->get(\Glueful\Lemma\Contracts\Delivery\EntryTargetResolver::class),
        );
        $escaped = $unbound->safeHtml('<p>x</p>');
        self::assertSame(htmlspecialchars('<p>x</p>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $escaped);

        // FAIL-CLOSED: throwing sanitizer → same escaped fallback.
        $throwing = new RenderContextExtension(
            null,
            $this->container()->get(\Glueful\Lemma\Contracts\Delivery\EntryTargetResolver::class),
            htmlSanitizer: new class implements \Glueful\Lemma\Contracts\Content\RichHtmlSanitizer {
                public function sanitize(string $html): string
                {
                    throw new \RuntimeException('boom');
                }
            },
        );
        self::assertSame(
            htmlspecialchars('<b>y</b>', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $throwing->safeHtml('<b>y</b>'),
        );

        // Non-string → ''.
        self::assertSame('', $ext->safeHtml(null));
        self::assertSame('', $ext->safeHtml(42));

        // Policy: DB templates may use it; version bumped for the new surface.
        self::assertContains('safe_html', TemplatePolicy::FILTERS);
        self::assertSame(3, TemplatePolicy::CACHE_VERSION);
        self::assertSame(
            [],
            $this->container()->get(TemplateLinter::class)->lint('{{ data.body|safe_html }}'),
        );
    }
```

(Adjust the `unbound`/`throwing` constructions to the extension's real positional/named args — `htmlSanitizer:` lands after `debug`; the meaning of each assertion is the contract. If nesting Task 2's `CACHE_VERSION` assertion elsewhere already pins 2, update THAT assertion to 3 — one source of truth per suite.)

- [ ] **Step 2: Verify fail.**

- [ ] **Step 3: Implement**

`RenderContextExtension` — ctor gains (after `bool $debug = false`; `use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;` + `use Twig\TwigFilter;`):

```php
        /** Soft-bound (sanitizer spec §4): null → safe_html fails CLOSED (escapes). */
        private readonly ?RichHtmlSanitizer $htmlSanitizer = null,
```

New method + `getFilters()`:

```php
    /** @return list<TwigFilter> */
    public function getFilters(): array
    {
        return [
            // is_safe is justified ONLY because every path out of safeHtml() is
            // already safe: sanitized markup or pre-escaped text (spec §4).
            new TwigFilter('safe_html', $this->safeHtml(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Sanitized rich HTML for templates (sanitizer spec §4). Fail-closed, exactly:
     * no sanitizer bound OR the sanitizer throws → htmlspecialchars(ENT_QUOTES |
     * ENT_SUBSTITUTE, UTF-8). There is NO path returning unprocessed input.
     */
    public function safeHtml(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        if ($this->htmlSanitizer !== null) {
            try {
                return $this->htmlSanitizer->sanitize($value);
            } catch (\Throwable) {
                // fall through to the escaped fallback
            }
        }
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
```

`LemmaRenderServiceProvider::makeRenderContextExtension()` — append the soft binding:

```php
            $container->has(RichHtmlSanitizer::class)
                ? $container->get(RichHtmlSanitizer::class)
                : null,
```

(after the `MediaUrlResolver` slot ONCE THE LIBRARY SHIPS — today it appends after the `debug` argument; `use Glueful\Lemma\Contracts\Content\RichHtmlSanitizer;`).

`TemplatePolicy`: `'safe_html',` joins `FILTERS`;

```php
    public const CACHE_VERSION = 3; // bumped: 'safe_html' joined FILTERS (sanitizer spec §4)
```

(Any existing test asserting `CACHE_VERSION === 2` updates to 3 — the nesting-era assertion in `BlocksRenderingTest` if still separate.)

- [ ] **Step 4: Verify pass** — `BlocksRenderingTest` + full `tests/Integration/Render/`. Gates.

---

### Task 4: Docs + full verification + STAGE

- [ ] **Step 1: README** (render pack) — after the Blocks section:

```markdown
## Rich HTML in templates

`format: rich` text fields are sanitized SERVER-SIDE on save (TipTap-scoped
allowlist — no scripts, event handlers, unsafe schemes, images, or tables) and
templates render them with `{{ value|safe_html }}`, which re-sanitizes at output
(defense-in-depth) and falls back to escaped text if no sanitizer is bound. Never
use `|raw` on content fields.
```

- [ ] **Step 2: CHANGELOG `[Unreleased]` → `### Added`** (prepend):

```markdown
- **Rich HTML sanitization**: `RichHtmlSanitizer` contract + TipTap-scoped
  allowlist implementation over symfony/html-sanitizer (additive-only config,
  explicit 1MB input limit, task-list `data-*` preserved, checkbox inputs
  stripped). Enforced at SAVE in `FieldValidator` for `format: rich` fields —
  including rich fields inside blocks via the existing recursion — and at RENDER
  via the new `safe_html` Twig filter (fail-closed: unbound or throwing sanitizer
  escapes instead). `safe_html` joined the DB-template sandbox allowlist
  (CACHE_VERSION → 3). Unblocks the `rich_text` starter block.
```

- [ ] **Step 3: Full verification + STAGE** *(commit only when authorized)*

```bash
vendor/bin/phpcs -q; echo "PHPCS_EXIT=$?"
composer boundaries
vendor/bin/phpunit --testsuite Integration
vendor/bin/phpunit tests/Unit/Content/
git add packages/lemma-contracts packages/lemma-render app/Content \
        app/Providers/LemmaServiceProvider.php CHANGELOG.md \
        tests/Unit/Content tests/Integration/Content tests/Integration/Render
```

Expected: green (single pre-existing skip). STOP — when authorized:

```bash
git commit -m "feat(content): rich HTML sanitization — TipTap allowlist, save-time enforcement, safe_html filter

RichHtmlSanitizer contract + TipTapHtmlSanitizer over symfony/html-sanitizer:
additively-built allowlist (allowElement/allowAttribute/allowLinkSchemes
http+https+mailto/allowRelativeLinks; task-list data-* kept, inputs stripped;
explicit 1MB withMaxInputLength against the engine's silent-truncation
default). FieldValidator sanitizes format:rich into the cleaned payload at
save — nested rich fields inside blocks covered by the existing recursion.
New safe_html Twig filter, fail-closed exactly per spec (unbound OR throwing
sanitizer → htmlspecialchars ENT_QUOTES|ENT_SUBSTITUTE UTF-8; is_safe only
because every path is pre-safe). safe_html joins TemplatePolicy::FILTERS,
CACHE_VERSION=3. Attack matrix tested: script/event-attrs/javascript- and
entity-obfuscated links/style/SVG onload/data URLs/iframe-object-embed-form/
malformed HTML; TipTap vocabulary round-trips; idempotency; long-input guard."
```

---

## Self-Review Notes (already applied)

- **Spec coverage:** §1 contract (Task 1); §2 additive allowlist with the exact API pins + 1MB limit + task-list shape + stripped-never-422 (Task 1, attack matrix + fidelity tests); §3 save-time in the cleaned payload incl. blocks recursion + lazy fallback + plain-text untouched (Task 2); §4 `safe_html` exact fail-closed contract, non-string → '', policy membership + `CACHE_VERSION = 3` (Task 3); §5 coordination stated in Global Constraints (getFilters created HERE, library appends; library amendment happens pre-execution); §6 test list fully mapped; §7 error rows all covered.
- **Type consistency:** `safeHtml(mixed): string` matches the filter registration and every test call; the ctor param name `htmlSanitizer` (distinct from FieldValidator's `sanitizer`) used consistently in the test's named-arg construction.
- **Verify-don't-guess (flagged inline):** symfony v8.1.1 `allowElement` signature nuance; the component's canonical output encoding for fidelity assertions; the extension's positional-arg order in the fail-closed test constructions.
