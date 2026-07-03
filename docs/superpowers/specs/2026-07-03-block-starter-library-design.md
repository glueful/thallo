# Block Starter Library — Design

**Date:** 2026-07-03
**Track:** block-builder v2 sub-project 2 (after container blocks)
**Depends on:** the shipped block builder + the **nesting amendment (commit c13fe62)** — `section`/`columns` require container blocks, so this library ships strictly AFTER nesting, never before. Also touches the DB-template policy layer (`TemplatePolicy`, one `CACHE_VERSION` bump for the two new Twig surfaces below).

## 0. Decisions

| Decision | Choice |
|---|---|
| Seeding | **CLI opt-in**: `php glueful blocks:seed` — idempotent by slug, existing slugs SKIPPED (never overwrites admin edits), deliberately no `--force`. Starter content is product opinion, not infrastructure; migrations stay out of it. |
| Source of truth | `App\Content\Blocks\StarterBlockTypes::definitions()` — data only; seeding flows through `BlockTypeRepository::create()` so slug/category/§2 schema validation stays centralized. |
| Media rendering | **A `media()` render helper ships as part of this work** (P1 resolution — media starters must actually render; no template silently assumes `/v1/blobs/{uuid}`). Public blobs only; see §3. |
| Link safety | **A `safe_url` Twig filter ships as part of this work** (P1 resolution): scheme-allowlisted URL emission; unsafe values render no link. See §4. |
| Style controls | By convention, not machinery: enum fields on the starters → modifier classes in templates (the deferred "per-block style controls" item lands here). |
| References | **No `reference` fields in starters** — `reference_type` targets site-specific content types a fresh install may not have; heroes/CTAs use URL strings. |

## 1. The starter set (10 types)

Created with `category` (Layout/Content/Media), a lucide `icon`, and a short `description` each. Field lists (all schemas pass the registry's §2 rules by construction — the seeder goes through `create()`):

| Category | Slug | Schema |
|---|---|---|
| Layout | `section` | `title` (string), `background` (enum `none/subtle/emphasis`), `content` (blocks) |
| Layout | `columns` | `layout` (enum `2/3`), `col_1`/`col_2`/`col_3` (blocks) — the nesting amendment's pinned fixed-regions-plus-enum pattern; the template renders regions per `layout` (col_3 ignored on `2`) |
| Layout | `divider` | `style` (enum `line/space`) |
| Layout | `spacer` | `size` (enum `small/medium/large`) |
| Content | `hero` | `heading` (string, required), `subheading` (string), `image` (asset), `alignment` (enum `left/center`), `cta_label` (string), `cta_url` (string) |
| Content | `copy` | `body` (text, plain) — rendered ESCAPED + `nl2br`. A rich-HTML starter is deliberately absent: rich fields store editor HTML strings, and rendering them requires `\|raw`, which no starter template may use without a server-side sanitizer contract (see Out of scope). |
| Content | `quote` | `text` (text, required), `attribution` (string) |
| Content | `cta` | `heading` (string, required), `body` (text), `button_label` (string), `button_url` (string), `variant` (enum `primary/secondary`) |
| Media | `image` | `image` (asset, required), `alt` (string), `caption` (string), `width` (enum `normal/wide/full`) |
| Media | `gallery` | `images` (asset, multiple), `columns` (enum `2/3/4`) |

## 2. Seeder + CLI

- `App\Content\Blocks\StarterBlockTypes::definitions(): list<array{slug, label, icon, category, description, schema}>` — pure data, the ONE place the starter set is defined (tests and the command both read it).
- `blocks:seed` console command (extends `Glueful\Console\BaseCommand`, house pattern): for each definition, `findBySlug()` → **skip if present** (any active state — a deactivated `hero` is still an admin decision), else `create()`. Output (pinned): one line per slug — `created hero` / `skipped hero (exists)` — plus a summary `Created N, skipped M.` so operators can SEE that reruns didn't overwrite edits. Exit 0 either way. **Registration (pinned for the plan):** app commands register explicitly in this repo — the command joins `LemmaServiceProvider`'s console-command services and its `commands([...])` list (mirror how the existing app commands are registered there).
- Docs: render-pack README + fresh-install note pointing at the command.

## 3. Media rendering — the `media()` helper (new, pinned)

The block-builder spec pins assets-in-blocks as *validated, never expanded*, and render's `asset()` is for THEME assets — so nothing today turns a blob uuid into an image URL. This library adds the missing seam, mirroring the `path()`/`EntryTargetResolver` architecture exactly:

- **Contract:** `Glueful\Lemma\Contracts\Delivery\MediaUrlResolver` — `url(string $uuid): ?string`.
- **Core impl** (`App\Content\Delivery\EngineMediaUrlResolver`): returns `api_prefix($context) . '/blobs/' . $uuid` **only when ALL of these hold** (mirroring what the framework blob route will actually serve anonymously — `UploadController::checkBlobAccess`):
  1. the blob exists, is active, and not deleted;
  2. `visibility === 'public'`;
  3. `uploads.enabled` is truthy (the blob routes exist at all);
  4. **anonymous retrieval is allowed by the FULL route stack** — not just the controller. `framework/routes/blobs.php` attaches `auth` middleware to `GET /blobs/{uuid}` when `uploads.access` is `true`, `'true'`, or `1`, BEFORE `UploadController::checkBlobAccess()`'s looser `!== 'private'` comparison ever runs. The resolver mirrors the composed behavior (pinned):

  ```php
  $access = config($context, 'uploads.access', 'private');
  $anonymousRetrieval = $access !== 'private'
      && $access !== true
      && $access !== 'true'
      && $access !== 1;
  ```

  Lemma defaults to `UPLOADS_ACCESS=private`, so on a default install `media()` returns null rather than emitting URLs that 401 as broken images; operators enable media starters by setting `upload_only` (auth'd uploads, public retrieval) or `public`/`false`.

  Anything failing any condition → **null**. (Earlier drafts mirrored only the controller-local check, under which truthy-`true` values would have emitted 401-broken URLs — the route middleware is the authoritative gate, and the §8 parity tests pin all three truthy forms.)
- **Public-and-retrievable only (pinned):** rendered pages are CACHED — a signed URL for a private blob expires inside the cached page and breaks the image, and a public-but-gated blob 401s. Templates skip the element on null. (The admin SPA's signed-URL display path is unaffected — different surface, uncached.)
- **Relative URLs (pinned):** host-relative (`/api/v1/blobs/{uuid}`-shaped via `api_prefix()`), correct on any host serving the rendered site.
- **Render pack:** `media(uuid)` Twig function on `RenderContextExtension`, soft-bound like `MenuReader`/`FacetCountsReader` (no resolver bound → null; templates never explode). One blob lookup per call in v1 — a gallery does N small primary-key queries; batching is a later optimization, noted not built.
- **Renders are not purged when a blob's visibility changes** — same accepted staleness class as reference edits inside blocks (out of scope, stated).

## 4. Link safety — the `safe_url` filter (new, pinned)

Twig autoescape does not make `href="javascript:…"` safe. The render pack gains a `safe_url` filter:

- `safe_url(string): ?string` — trims, then allows ONLY: site-relative paths starting `/` (**but not `//`** — protocol-relative URLs smuggle a host), `https://`, `http://`, and `mailto:`. Everything else (any other scheme, whitespace-obfuscated schemes, empty) → **null**.
- Link-emitting starter templates (`hero`, `cta`) use it: `{% set url = data.cta_url|safe_url %}{% if url %}<a href="{{ url }}">…{% else %}<span>…{% endif %}` — invalid URLs render the label as plain text, never a link.
- **Security tests (pinned):** `javascript:alert(1)`, `JAVASCRIPT:alert(1)`, `//evil.com`, and ` data:text/html,…` all render NO `<a href`; `/about`, `https://example.com`, `mailto:x@y.z` render links.

## 5. Sandbox policy

`media` joins `TemplatePolicy::FUNCTIONS` and `safe_url` joins `TemplatePolicy::FILTERS`; **one `CACHE_VERSION` bump to 3** covers both (new Twig surfaces DB templates may use — unlike the nesting change, these ARE policy changes).

## 6. Templates + style conventions

Ten templates at `packages/lemma-render/themes/default/templates/blocks/{slug}.twig`:

- Root element convention (pinned): `class="lemma-block lemma-block-{slug}"` plus one modifier class per style enum (`lemma-block-section--subtle`, `lemma-block-image--wide`, `lemma-block-cta--primary`, `lemma-block-hero--center`, …). Themes restyle by targeting these classes; the enums ARE the per-block style controls.
- `section` / `columns` compose children via `{{ blocks(data.content) }}` / `{{ blocks(data.col_1) }}` etc.
- Media templates render via `media()` and skip the element on null; `alt` falls back to `''` (decorative), `caption` renders a `<figcaption>` only when present.
- Minimal starter CSS appended to the default theme's existing stylesheet (grid for `columns`/`gallery`, spacing for `section`/`spacer`, variants) — enough to look intentional, not a design system.
- App themes override per template; DB-edited templates work on all of them (same loader chain). Sites that never seed the types simply never hit these templates.

## 7. Error handling

| Case | Behavior |
|---|---|
| Re-running `blocks:seed` | per-slug `skipped` lines; nothing overwritten; exit 0 |
| Starter slug exists but deactivated | skipped (deactivation is an admin decision) |
| `media()` on private/missing blob | null → template skips the element |
| `safe_url` on unsafe value | null → label renders as text, no link |
| `columns` layout `2` with col_3 data | col_3 not rendered (data preserved; validation still cleans it per schema) |

## 8. Testing

- **Seeder:** first run creates 10 (counts asserted from `StarterBlockTypes::definitions()`, not a literal); second run creates 0 / skips 10; an admin-edited `hero` schema survives re-seed byte-identical; every definition passes `BlockTypeRepository::create()` (§2 rules).
- **Helpers:** `media()` — public blob + anonymous access allowed (`upload_only`) → `/…/blobs/{uuid}` (via `api_prefix`); **`visibility=public` + each of `uploads.access` = `'private'`, `true`, `'true'`, `1` → null** (the route-middleware parity matrix; `'private'` is the default-install case); `uploads.enabled=false` → null; private → null; missing → null; unbound resolver → null; `safe_url` — the §4 allow/deny matrix; both lint clean in a DB template; `CACHE_VERSION === 3`.
- **Templates:** each starter renders its default-theme template with representative fixture data (no throw, non-empty, root classes + enum modifier present); `columns` 2 vs 3; `section`>`hero` composition; the §4 security matrix through the real `hero`/`cta` templates.
- **CLI:** output shape (per-slug lines + summary).

## Out of scope

- Batched media lookups; signed-URL support in rendered pages; purge-on-blob-visibility-change.
- Reference-based starter blocks; sample CONTENT seeding (types only, no entries).
- Setup-wizard integration (the CLI is the seam; a wizard checkbox can call it later).
- **Rich-HTML rendering (pinned rejection):** starter templates never use `|raw`. The safe pipeline — editor stores HTML → backend sanitizes on save/publish via allowlist → templates render the SANITIZED value — is a dedicated follow-up ("rich HTML sanitization/rendering") with its own spec/plan and security review, because sanitizer behavior affects EVERY rich text field in Lemma, not just starter blocks. Agreed shape for that cycle (recorded so it isn't rediscovered): `RichHtmlSanitizer::sanitize(string $html): string` contract; allowlist implementation scoped to TipTap output (paragraphs, headings, lists, blockquote, code, bold/italic/strike, safe-scheme links; images only if deliberately allowed); enforcement on save/publish in `FieldValidator` for `text format:rich` INCLUDING rich fields inside blocks; render surface = a `safe_html` Twig filter or an explicit "format:rich values are pre-sanitized and may be rendered raw by trusted filesystem templates" rule; security tests covering `<script>`, event attributes, `javascript:` links, unsafe `style`, hostile SVG/data URLs, and malformed HTML. A `rich_text` starter block joins the library immediately after that contract is real.
- **Notion-like block editor UX** (slash/add menu, inline insertion between blocks, drag handles, keyboard movement, collapsed chrome): a separate SPA `BlocksField` sub-project, not a template concern.
