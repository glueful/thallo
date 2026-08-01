# lemma-seo Admin UI — Design

**Status:** Approved (direction + refinements), pending spec review.
**Depends on:** the shipped `lemma-seo` backend pack (`docs/superpowers/specs/2026-07-01-lemma-seo-design.md`) — specifically the admin endpoints `GET`/`PUT /v1/admin/seo/meta/{entryUuid}?locale=` behind `auth` + `lemma_permission:seo.manage`, and the `seo_meta` columns.
**Scope:** the deferred admin-SPA UI for lemma-seo — a per-entry, per-locale SEO overrides editor in the content entry editor. No backend changes.

---

## 1. Scope

**In:** a `SeoPanel` in the entry editor (`content/{type}/{uuid}`) that reads and writes the `seo_meta` override row for the current entry + locale, exposing all writable fields (title, description, Open Graph, Twitter card, robots).

**Out (v1):** live preview of the *resolved* public value (fallback/default) — the panel edits the override row only; empty = "no override". Bulk cross-entry SEO management. JSON-LD. A standalone SEO page/nav entry.

## 2. Placement & gating

- **Placement.** `SeoPanel.vue` renders in the entry editor's **right column, directly below `PublishPanel`** (`src/pages/content/[type]/[uuid]/index.vue`, the `lg:w-96` column). It receives `{ uuid, locale, enabled }` — the same `uuid`/`locale` `PublishPanel` gets (reusing the page's existing locale context and `LocaleSwitcher`, no new locale UI), plus the parent-owned `enabled` capability gate (see §3.3/§3.4).
- **The whole panel is collapsible.** It renders as a collapsible section (collapsed/expanded) so the right column stays uncluttered; within it, the **Open Graph** group is a nested collapsible, **collapsed by default**. Title/description and the robots/Twitter controls stay compact.
- **Gating — two layers, fail-closed:**
  1. **Render gate:** `<SeoPanel v-if="seoEnabled" …>` where `seoEnabled = useCapabilitiesStore().isEnabled('lemma.seo')`. The capability store fails closed (empty set on error → hidden).
  2. **Query gate:** `useSeoMeta(uuid, locale, enabled)` takes an `enabled` gate (the analytics-Home lesson). With Pinia Colada the query object is still created, but when `enabled` is false it **never fetches / issues a request** — a disabled pack must not hit the 404'd route. The parent passes its `seoEnabled` through the panel's `enabled` prop (see §3.3/§3.4). (The render gate at layer 1 is what makes the query object "not created at all" when the panel is hidden.)

## 3. Components & data flow

### 3.1 Transport (`authFetch`, not the typed client)
`openapi.json` carries `/v1/admin/seo/meta/{entryUuid}` but under-types it (`query?: never`, `requestBody?: never`, `content?: never`), so the typed `client` would need casts on the `locale` query, PUT body, and response. The query layer therefore rides on **`authFetch`** — the codebase's helper for endpoints the spec under-types (the same pattern `queries/analytics.ts` and the i18n queries use) — and **no `pnpm gen:api` / `schema.ts` regen is needed**. The §3.2 `client.GET/PUT` snippets below describe the request contract; the actual calls go through `authFetch` (URL: `${apiBase}/seo/meta/${uuid}?locale=`). **If the SEO backend OpenAPI is corrected later, `queries/seo.ts` can be migrated to the typed client without changing `SeoPanel`.**

### 3.2 `src/queries/seo.ts` (mirrors `queries/routes.ts`)
- `SeoMeta` type — the override row shape (all fields optional/nullable): `title, description, og_title, og_description, og_image, twitter_card, robots`.
- `fetchSeoMeta(uuid, locale): Promise<SeoMeta>` — `client.GET('/seo/meta/{entryUuid}', { params: { path:{entryUuid:uuid}, query:{locale} } })`; unwraps `data.data`; returns `{}` when unset (backend returns an empty object).
- `useSeoMeta(uuid, locale, enabled?)` — `useQuery`, key `qk.seoMeta(uuid, locale)`, `enabled: () => (enabled === undefined ? true : toValue(enabled))`.
- `saveSeoMeta(uuid, locale, payload)` — `client.PUT('/seo/meta/{entryUuid}', { params:{ path:{entryUuid:uuid}, query:{locale} }, body: payload })`.
- `useSaveSeoMeta(uuid, locale)` — `useMutation`; `onSettled` invalidates `qk.seoMeta(uuid, locale)`.
- Add `qk.seoMeta(uuid, locale)` to `src/queries/keys.ts`.

### 3.3 `SeoPanel.vue`
Props `{ uuid: string; locale: string; enabled: boolean }`. The parent owns capability state and passes it down as `enabled`, so the panel needs no store access. The panel calls `useSeoMeta(() => props.uuid, () => props.locale, () => props.enabled)` and `useSaveSeoMeta(props.uuid, props.locale)`, plus `useNotify`.

Form model (local `reactive`): `title, description, og_title, og_description, og_image` (text), `twitter_card` (select), `robots` (select).

**Controls:**
- Title, Description — `UInput` / `UTextarea`.
- Open Graph (nested collapsible, collapsed by default) — `og_title` (input), `og_description` (textarea), `og_image` (input, URL).
- Social — `twitter_card` `USelect`: `summary`, `summary_large_image`, plus an empty "— none —" option.
- Robots — `USelect`, constrained to the exact backend-accepted strings: **`index`, `noindex`, `noindex,nofollow`** (default `index`). No other values are offered.
- **Save** button (explicit, matching PublishPanel's per-section saves) → `useSaveSeoMeta`, `success('SEO saved')` / `notifyError`.

### 3.4 `index.vue` wiring
Import `useCapabilitiesStore`, compute `const seoEnabled = computed(() => caps.isEnabled('lemma.seo'))`, and render after `PublishPanel`. The `v-if` is the render gate; `:enabled` feeds the query gate:
```vue
<SeoPanel
  v-if="seoEnabled"
  :key="`${uuid}-${locale}`"
  :uuid="uuid"
  :locale="locale"
  :enabled="seoEnabled"
/>
```

## 4. Payload normalization (deliberate empty → null)

Empty fields mean "no override," so the PUT body **normalizes `''` → `null`** for the nullable text columns (`title, description, og_title, og_description, og_image, twitter_card`). This prevents an empty string from overriding the fallback with blank meta. `robots` always sends one of the three valid enum strings (never null/empty).

The PUT sends **only the writable SEO fields** — `title, description, og_title, og_description, og_image, twitter_card, robots` — and never echoes back `entry_uuid`, `locale`, `id`, `created_at`, `updated_at`, or any server-returned row metadata.

## 5. No dirty-overwrite hydration

- **On key change** (`${uuid}-${locale}`), the panel **remounts** → a fresh form hydrated from the newly-loaded override. Locale switches are clean.
- **While the same key is open,** a background refetch (e.g. post-save invalidation, window refocus) **must not clobber unsaved edits.** The form copies query data **once per key, on first load** — a `hydrated` flag (reset by the remount) guards a `watch(data, …, { immediate: true })` so subsequent refetches don't overwrite the in-progress form. After a successful save the invalidated refetch returns the just-saved values, which already match the form.

## 6. Error handling

- Save failures surface via `useNotify(toApiError(err, response))`.
- Capability store failure → empty enabled set → parent's `seoEnabled` is false → panel not rendered (fail-closed); even if mounted, `enabled: false` stops the query from fetching.
- GET returning `{}` (no override yet) hydrates an empty form (robots defaults to `index`).

## 7. Testing (vitest; assert `data-test` hooks, per the Nuxt-UI test conventions)

- **`SeoPanel` renders + saves:** mounts with `{ uuid, locale, enabled: true }` (query mocked); loads an existing override into the form; typing + **Save** calls the PUT with the normalized, writable-only payload (empty text → `null`; robots as a valid enum). (`data-test="seo-save"`, per-field `data-test` hooks.)
- **Empty normalization:** clearing a previously-set field and saving sends `null` for it, not `''`.
- **Gating (query layer):** mounting `SeoPanel` with `enabled: false` never issues a request (mirrors `homeAnalyticsStrip.spec`). **Gating (render layer):** the parent hides `<SeoPanel>` when `seoEnabled` is false (mirrors `collectionsGating.spec`).
- **No dirty overwrite:** with the form edited, a simulated background refetch does not overwrite the local edits.
- **`queries/seo.ts` shape:** `fetchSeoMeta` unwraps `data.data` (and `{}` when unset); `saveSeoMeta` targets the right path/params — mirrors `collections.spec.ts` / `analyticsQueries.spec.ts`.

## 8. Deliverables summary

`src/api/schema.ts` (regen); `src/queries/keys.ts` (`seoMeta` key); `src/queries/seo.ts` (`useSeoMeta` w/ enabled gate + `useSaveSeoMeta`); `SeoPanel.vue` (collapsible, grouped, normalized save); `index.vue` wiring (capability-gated render); vitest specs (`SeoPanel`, gating, queries). No backend changes.
