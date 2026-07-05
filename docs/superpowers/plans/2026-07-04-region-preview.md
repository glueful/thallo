# Region Preview (see chrome before saving) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Header & footer admin page shows a live server-rendered preview of the *unsaved* region blocks — real Twig, real theme CSS, real settings classes — so instant-live region saves stop being "save and pray."

**Architecture:** One new endpoint, `POST /admin/regions/preview`, takes the current (unsaved) header/footer payloads, runs `RegionValidator` per region (palette errors surface pre-save as 422), renders a full skeleton HTML page through the container's `TwigFactory` environment (a pack `region-preview.twig` template: theme CSS via `asset()`, an absolute `<base href>` built from the request, the exact layout.twig chrome markup, `blocks()` composition, placeholder body), and returns `{html}`. The SPA loads it into an iframe via a **Blob object URL** (P1 review pin — NOT srcdoc: srcdoc documents get an opaque origin and asset resolution/CSP behavior gets browser-dependent; a blob document inherits the SPA's origin and the `<base>` makes host-relative `/theme-assets/*` resolve exactly like the live page) with `sandbox="allow-same-origin"` (NO allow-scripts — nothing can execute; same-origin only makes subresource fetches behave like the live site). Auto-refreshed with a debounce plus a manual refresh button; a 422 keeps the last good preview AND flags it with an explicit "Preview not updated" stale chip (P2 review pin) so editors can't mistake valid old chrome for their invalid current edits.

**Tech Stack:** PHP 8.3, Twig 3, PHPUnit; Vue 3 + Nuxt UI, vitest.

**Spec basis:** extends `docs/superpowers/specs/2026-07-04-global-regions-design.md` (this closes the "no preview while building" gap without touching the deferred canvas integration).

## Global Constraints

- Preview NEVER writes: no `lemma_regions` mutation, no `RegionUpdated`, no cache purge.
- Preview renders POSTED payloads; a region absent from the payload renders its SAVED row if one exists, else that element is omitted (preview shows chrome, not the fallback-chrome decision — the layout's null/fallback rule stays a live-render concern).
- Rendering is annotation-free by construction (live-mode `blocks()`; nothing sets annotations) and mirrors `RenderController`'s reset-family discipline (`resetBlockDepth`, `resetBlockFrames`, `resetTags`, `setAssetBase(null)`, `setLocale(default)`) so a shared-singleton extension never leaks state between admin preview and page renders.
- Render-pack machinery is soft-resolved: `TwigFactory` absent from the container → 409 "preview unavailable" (no hard app→render coupling beyond what exists).
- Same gate as reads: `lemma_permission:content.view` (preview mutates nothing).
- **P1 pin:** iframe delivery is Blob object URL + `sandbox="allow-same-origin"` (scripts still blocked — allow-scripts absent); the document carries an absolute `<base href="{scheme+host}/">` so host-relative assets resolve identically to the live page. Object URLs are revoked on replace and on unmount.
- **P2 pin:** last-good-preview is kept on validation failure, but the panel shows an explicit stale indicator ("Preview not updated") + the error until a refresh succeeds.
- Session conventions: stage only, commit on "commit all"; no attribution; CHANGELOG updated.

---

### Task 1: Preview endpoint + pack template + tests

**Files:**
- Create: `app/Http/DTOs/PreviewRegionsData.php`
- Modify: `app/Http/Controllers/RegionAdminController.php` (add `preview()`)
- Create: `packages/lemma-render/themes/default/templates/region-preview.twig`
- Modify: `routes/lemma_admin.php`
- Test: `tests/Integration/Http/RegionAdminApiTest.php` (new cases)

**Interfaces:**
- Produces: `POST /v1/admin/regions/preview` — body `{regions: {header?: {blocks, settings}, footer?: {blocks, settings}}}` → `200 {data: {html}}` | `422` (dot-path, prefixed `regions.{slug}.`) | `409` (render pack unavailable).

- [ ] **Step 1: DTO**

`app/Http/DTOs/PreviewRegionsData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * POST /admin/regions/preview body: per-slug UNSAVED region payloads. Free-form
 * here — RegionValidator owns the real rules per region so preview surfaces the
 * same 422s a save would, before anything goes live.
 */
final class PreviewRegionsData implements RequestData
{
    public function __construct(
        /** @var array<string, array{blocks?: list<array<string,mixed>>, settings?: array<string,mixed>}> */
        #[Rule('array')]
        public readonly array $regions = [],
    ) {
    }
}
```

- [ ] **Step 2: Failing tests** — append to `tests/Integration/Http/RegionAdminApiTest.php`:

```php
    public function testPreviewRendersPostedChromeWithoutSaving(): void
    {
        $controller = $this->controller();
        $resp = $controller->preview($this->previewDto([
            'regions' => [
                'header' => [
                    'blocks' => [
                        ['id' => 'prevhdrnav01', 'type' => 'navigation', 'data' => ['menu' => 'main']],
                    ],
                    'settings' => ['sticky' => true, 'width' => 'full'],
                ],
            ],
        ]));
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getContent());
        $html = json_decode((string) $resp->getContent(), true)['data']['html'];
        self::assertStringContainsString('lemma-block-navigation', $html);
        self::assertStringContainsString('lemma-region-header--sticky', $html);
        self::assertStringContainsString('lemma-region-header--full', $html);
        self::assertStringContainsString('/theme-assets/site.css', $html);
        self::assertStringContainsString('/theme-assets/blocks.css', $html);
        self::assertMatchesRegularExpression('#<base href="https?://[^"]+/">#', $html); // blob-doc anchor (P1)
        self::assertStringNotContainsString('lemma-preview-block', $html); // never annotated
        self::assertStringNotContainsString('<footer', $html);            // no footer posted, none saved

        // NOTHING was written.
        self::assertNull((new RegionRepository($this->connection()))->find('header'));
    }

    public function testPreviewFallsBackToTheSavedRowForAnUnpostedRegion(): void
    {
        (new RegionRepository($this->connection()))->save('footer', [
            ['id' => 'prevftrrich1', 'type' => 'rich_text', 'data' => ['body' => '<p>Saved footer</p>']],
        ], [], null);

        $resp = $this->controller()->preview($this->previewDto(['regions' => [
            'header' => ['blocks' => [], 'settings' => []],
        ]]));
        $html = json_decode((string) $resp->getContent(), true)['data']['html'];
        self::assertStringContainsString('Saved footer', $html);
    }

    public function testPreviewSurfacesPaletteErrorsBeforeAnythingGoesLive(): void
    {
        try {
            $this->controller()->preview($this->previewDto(['regions' => [
                'header' => ['blocks' => [
                    ['id' => 'prevbadblk01', 'type' => 'gallery', 'data' => ['images' => []]],
                ], 'settings' => []],
            ]]));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('regions.header.blocks.0.type', $e->errors());
        }
    }
```

…plus the helper next to `dto()`:

```php
    private function previewDto(array $body): \App\Http\DTOs\PreviewRegionsData
    {
        /** @var \App\Http\DTOs\PreviewRegionsData */
        return (new RequestDataHydrator())->hydrate(\App\Http\DTOs\PreviewRegionsData::class, $body);
    }
```

Run: `vendor/bin/phpunit tests/Integration/Http/RegionAdminApiTest.php` — Expected: FAIL (no `preview()`).

- [ ] **Step 3: Pack template**

`packages/lemma-render/themes/default/templates/region-preview.twig` (theme-overridable by the normal per-template fallback; markup mirrors layout.twig's region branches verbatim — classes included — so the preview IS the live markup):

```twig
{# Admin-only chrome preview (region-preview plan): the exact region markup
   layout.twig emits, around a placeholder body. Loaded as a BLOB document in
   the admin (P1 pin) — the absolute base href makes host-relative
   /theme-assets/* resolve like the live page. Never indexed, never routed. #}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <base href="{{ base_href }}">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Chrome preview</title>
  <link rel="stylesheet" href="{{ asset('site.css') }}">
  <link rel="stylesheet" href="{{ asset('blocks.css') }}">
</head>
<body>
  {% if header %}
    <header class="site-header lemma-region lemma-region-header lemma-region-header--{{ header.settings.width|default('contained') }}{% if header.settings.sticky|default(false) %} lemma-region-header--sticky{% endif %}">
      <div class="site-header__inner">{{ blocks(header.blocks) }}</div>
    </header>
  {% endif %}
  <main style="max-width: 72rem; margin: 0 auto; padding: 2rem 1rem;">
    <div style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 5rem 2rem; text-align: center; color: #94a3b8; font: 0.95rem system-ui;">
      Page content
    </div>
  </main>
  {% if footer %}
    <footer class="site-footer lemma-region lemma-region-footer lemma-region-footer--{{ footer.settings.width|default('contained') }}">
      <div class="site-footer__inner">{{ blocks(footer.blocks) }}</div>
    </footer>
  {% endif %}
</body>
</html>
```

- [ ] **Step 4: Controller method**

`RegionAdminController` — add imports (`use Glueful\Lemma\Render\TwigFactory;` is a HARD class reference; keep it soft with a string-classname `has()` check instead, exactly like `AdminUrlProvider` fallbacks elsewhere — verify house style and use `\Glueful\Lemma\Render\TwigFactory::class` inline-guarded**with** a `use` import since the render pack is a composer dependency of the app; the `has()` guard covers the capability-disabled case) and the method:

```php
    /** POST /v1/admin/regions/preview */
    #[ApiOperation(
        summary: 'Preview chrome regions',
        description: 'Renders the POSTED (unsaved) header/footer block lists through the real theme '
            . 'pipeline and returns a self-contained HTML document for an iframe. Validates exactly '
            . 'like a save (palette, schemas, settings) so errors surface BEFORE anything goes live. '
            . 'Never writes. Requires `content.view`.',
        tags: ['Lemma Regions'],
    )]
    #[ApiResponse(200, description: 'Rendered preview document.')]
    #[ApiResponse(409, description: 'Render pack unavailable.')]
    #[ApiResponse(422, description: 'Same validation a save would fail.')]
    public function preview(PreviewRegionsData $input, Request $request): Response
    {
        $container = container($this->context);
        if (!$container->has(TwigFactory::class)) {
            return Response::error('Preview unavailable: the render pack is not active.', 409);
        }

        // Validate posted payloads with save-identical rules; prefix error paths per slug.
        $context = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $posted = $input->regions[$slug] ?? null;
            if (is_array($posted)) {
                try {
                    $clean = $this->validator->validate(
                        $slug,
                        is_array($posted['blocks'] ?? null) ? $posted['blocks'] : [],
                        is_array($posted['settings'] ?? null) ? $posted['settings'] : [],
                    );
                } catch (\App\Content\Validation\ValidationException $e) {
                    $prefixed = [];
                    foreach ($e->errors() as $path => $message) {
                        $prefixed["regions.{$slug}.{$path}"] = $message;
                    }
                    throw new \App\Content\Validation\ValidationException($prefixed);
                }
                // A posted-but-empty region previews as absent (the null rule's spirit).
                $context[$slug] = $clean['blocks'] === [] ? null
                    : ['blocks' => $clean['blocks'], 'settings' => $clean['settings']];
            } else {
                $saved = $this->regions->find($slug);
                $context[$slug] = ($saved === null || $saved['blocks'] === []) ? null
                    : ['blocks' => $saved['blocks'], 'settings' => $saved['settings']];
            }
        }

        // Mirror RenderController's reset-family discipline: the extension is a
        // shared singleton; admin preview must not leak state into page renders.
        $ext = $container->get(RenderContextExtension::class);
        $ext->setLocale((string) config($this->context, 'i18n.default_locale', 'en'));
        $ext->resetBlockDepth();
        $ext->resetBlockFrames();
        $ext->resetTags();
        $ext->setAssetBase(null);

        // Absolute base (P1 pin): the SPA loads this document from a blob: URL,
        // where host-relative asset paths don't resolve — the <base> anchors
        // them to the real origin, so CSS loads exactly like the live page.
        $context['base_href'] = $request->getSchemeAndHttpHost() . '/';

        $env = $container->get(TwigFactory::class)->environment();
        $html = $env->render('region-preview.twig', $context);

        return Response::success(['html' => $html], 'Preview rendered.');
    }
```

(Imports to add: `PreviewRegionsData`, `RegionContextExtension`… exact list: `use App\Http\DTOs\PreviewRegionsData;`, `use Glueful\Lemma\Render\RenderContextExtension;`, `use Glueful\Lemma\Render\TwigFactory;`, `use Symfony\Component\HttpFoundation\Request;`, and `use function container;`/`config` per house style — check how other app controllers call `container()`/`config()` and match. Verify `Response::error(message, status)`'s real signature against an existing 409 usage and adjust.)

- [ ] **Step 5: Route** — in `routes/lemma_admin.php`, ABOVE the `{slug}` route:

```php
    $router->post('/regions/preview', [RegionAdminController::class, 'preview'])
        ->middleware('lemma_permission:content.view');
```

- [ ] **Step 6: Run**

`vendor/bin/phpunit tests/Integration/Http/RegionAdminApiTest.php` — Expected: PASS (8 tests).
Then `composer run docs:openapi && cd admin && pnpm gen:api`.

---

### Task 2: SPA preview panel

**Files:**
- Modify: `admin/src/queries/regions.ts` (add `usePreviewRegions`)
- Modify: `admin/src/pages/regions/index.vue` (preview card + auto/manual refresh)
- Modify: `admin/src/__tests__/regionsPage.spec.ts` (new cases)

- [ ] **Step 1: Query**

Append to `admin/src/queries/regions.ts`:

```ts
export function usePreviewRegions() {
  return useMutation({
    mutation: async (vars: {
      regions: Partial<Record<string, { blocks: BlockInstance[]; settings: Record<string, unknown> }>>
    }) => {
      const { data, error, response } = await client.POST('/regions/preview', {
        body: { regions: vars.regions } as never,
      })
      if (error) throw toApiError(error, response)
      const payload = data as unknown as { data?: { html?: string } } | undefined
      return payload?.data?.html ?? ''
    },
  })
}
```

- [ ] **Step 2: Page**

`admin/src/pages/regions/index.vue` — add above the two region cards a sticky-top preview card:

Script additions:

```ts
import { usePreviewRegions } from '@/queries/regions'
import { refDebounced } from '@vueuse/core'

const preview = usePreviewRegions()
const previewUrl = ref('')      // blob: object URL (P1 pin — never srcdoc)
const previewError = ref('')
const previewStale = ref(false) // P2 pin: the iframe shows LAST GOOD, not current edits

// Fingerprint of the editable state; debounced so typing doesn't spam renders.
const stateFingerprint = computed(() => JSON.stringify(state))
const debouncedFingerprint = refDebounced(stateFingerprint, 700)

function setPreviewDocument(html: string): void {
  const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }))
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
  previewUrl.value = url
}

async function refreshPreview(): Promise<void> {
  const regions: Record<string, { blocks: BlockInstance[]; settings: Record<string, unknown> }> = {}
  for (const slug of ['header', 'footer']) {
    const s = state[slug]
    if (s) regions[slug] = { blocks: s.blocks, settings: s.settings }
  }
  try {
    setPreviewDocument(await preview.mutateAsync({ regions }))
    previewError.value = ''
    previewStale.value = false
  } catch (e) {
    // Keep the last good preview but say so LOUDLY (P2): the iframe no longer
    // reflects the current (invalid) edits until a refresh succeeds.
    previewError.value = e instanceof Error ? e.message : 'Preview failed'
    previewStale.value = true
  }
}

watch(debouncedFingerprint, () => {
  if (Object.keys(state).length > 0) void refreshPreview()
})

onBeforeUnmount(() => {
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
```

(`ref` and `onBeforeUnmount` return to the vue import list. `previewUrl` starts empty — the watch's first debounced fire after load renders the initial preview.)

Template — first child of the body wrapper:

```html
<UCard data-test="region-preview">
  <template #header>
    <div class="flex items-center justify-between gap-2">
      <div>
        <h2 class="font-semibold text-default">Preview</h2>
        <p class="text-sm text-muted">Your unsaved chrome, rendered through the real theme. Nothing is live until you save.</p>
      </div>
      <div class="flex items-center gap-2">
        <UBadge
          v-if="previewStale"
          color="warning"
          variant="subtle"
          data-test="region-preview-stale"
        >
          Preview not updated
        </UBadge>
        <UButton
          size="sm"
          variant="subtle"
          color="neutral"
          icon="i-lucide-refresh-cw"
          :loading="preview.isLoading.value"
          data-test="region-preview-refresh"
          @click="() => { void refreshPreview() }"
        >
          Refresh
        </UButton>
      </div>
    </div>
  </template>
  <p v-if="previewError" class="mb-2 text-sm text-error" data-test="region-preview-error">{{ previewError }}</p>
  <iframe
    v-if="previewUrl"
    :src="previewUrl"
    sandbox="allow-same-origin"
    title="Chrome preview"
    class="h-72 w-full rounded-md border border-default bg-white"
    data-test="region-preview-frame"
  />
  <USkeleton v-else class="h-72 w-full" />
</UCard>
```

(P1 pin: blob `src` + `sandbox="allow-same-origin"` — `allow-scripts` is absent
so NOTHING executes; same-origin only makes stylesheet fetches behave exactly
like the live page. `blocks.js` isn't referenced by the preview template, so
interactive blocks render in their no-JS base state — correct for a chrome
preview.)

- [ ] **Step 3: Spec cases** — append to `regionsPage.spec.ts` (mock `usePreviewRegions` in the `@/queries/regions` mock: `usePreviewRegions: () => ({ mutateAsync: previewMock, isLoading: ref(false) })`):

```ts
  it('manual refresh loads a blob document into the sandboxed iframe', async () => {
    previewMock.mockResolvedValue('<!doctype html><html><body>PREVIEW</body></html>')
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()

    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()

    const frame = wrapper.find('[data-test="region-preview-frame"]')
    expect(frame.exists()).toBe(true)
    expect(frame.attributes('src')).toMatch(/^blob:/)                       // P1: blob URL, not srcdoc
    expect(frame.attributes('sandbox')).toBe('allow-same-origin')           // scripts stay blocked
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(false)
    // The payload carried BOTH regions' current state.
    const call = previewMock.mock.calls.at(-1)![0] as { regions: Record<string, unknown> }
    expect(Object.keys(call.regions).sort()).toEqual(['footer', 'header'])
    wrapper.unmount()
  })

  it('a failed preview keeps the last good document but flags it stale', async () => {
    previewMock.mockResolvedValueOnce('<!doctype html><html><body>GOOD</body></html>')
    const wrapper = mount(RegionsPage, { attachTo: document.body })
    await flushPromises()
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()
    const goodUrl = wrapper.find('[data-test="region-preview-frame"]').attributes('src')

    previewMock.mockRejectedValueOnce(new Error("'gallery' is not allowed in the header region"))
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()

    // Last good document stays…
    expect(wrapper.find('[data-test="region-preview-frame"]').attributes('src')).toBe(goodUrl)
    // …but the staleness is EXPLICIT (P2): banner + error, until a refresh succeeds.
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="region-preview-error"]').text()).toContain('gallery')

    previewMock.mockResolvedValueOnce('<!doctype html><html><body>FIXED</body></html>')
    await wrapper.find('[data-test="region-preview-refresh"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="region-preview-stale"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="region-preview-error"]').exists()).toBe(false)
    wrapper.unmount()
  })
```

(jsdom implements `URL.createObjectURL` for Blobs — if the installed jsdom
lacks it, polyfill in `setup.ts` alongside the existing getBBox shims:
`URL.createObjectURL ??= () => 'blob:jsdom/' + Math.random()` with a matching
`revokeObjectURL` no-op. Debounced auto-refresh is intentionally untested —
timers in jsdom buy flake; the manual path exercises the same
`refreshPreview()`.)

- [ ] **Step 4: Gates**

`pnpm vitest run && pnpm type-check && pnpm lint` — Expected: green.

---

### Task 3: Full gates + CHANGELOG + stage

- [ ] **Step 1:** `vendor/bin/phpunit && composer run phpcs` (lemma root) — green.
- [ ] **Step 2: CHANGELOG** — extend the global-regions `[Unreleased]` entry with: `POST /admin/regions/preview` renders the UNSAVED chrome through the real theme pipeline (save-identical validation, never writes) into a sandboxed iframe on the Header & footer page — debounced auto-refresh + manual refresh, last-good-preview on validation errors.
- [ ] **Step 3: Stage** the touched paths (controller, DTO, route, template, queries, page, specs, schema.d.ts, openapi.json, plan, CHANGELOG). NO commit — wait for "commit all".
