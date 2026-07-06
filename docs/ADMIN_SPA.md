# Thallo Admin SPA

The first-party editor for Thallo lives in `admin/` — a Vue 3.5 + Vite 8 + Nuxt UI 4 SPA. Its
compiled output ships as `public/admin/` and is mounted by the PHP app at `/admin` via the
framework `serveFrontend()` seam.

## Architecture

- **Typed API client** — `openapi-typescript` generates `src/api/schema.d.ts` from
  `docs/openapi.json`; `openapi-fetch` calls it. Codegen strips the `/v1/admin` prefix so queries
  read `client.GET('/content-types')`, with `apiBase` (from runtime config) as the client baseUrl.
- **Server state → Pinia Colada** — `src/queries/*` wrap the client in `useQuery`/`useMutation`
  (caching + invalidation). No hand-rolled fetching.
- **Session** — `src/stores/session.ts`, a Pinia store persisted to **encrypted localStorage** (the
  existing `pinia-persist-plugin`). The encryption secret is the build-time
  `VITE_ADMIN_PERSIST_SECRET`. Auth endpoints are under `/api/v1/auth/*` (raw fetch, not the typed
  client). *Client-side encryption-at-rest is obfuscation, not secrecy — the real defenses are
  short-lived tokens + refresh-on-401.*
- **Routing** — file-based (`src/pages/`, `vue-router/vite` + `vite-plugin-vue-layouts-next`), with
  one `beforeEach` guard: install gate (→ `/setup`) then auth gate (→ `/login`).
- **Schema-driven editor** — a `type → component` registry (`src/fields/`) renders Nuxt UI inputs
  (`UEditor` for rich text, `UInputDate` for dates, `UFileUpload` for assets) via `defineModel`.

## Develop

```bash
cd admin
pnpm install
pnpm dev          # needs the PHP app (or a proxy) for /admin/config.json + the API
pnpm gen:api      # regenerate src/api/schema.d.ts after the OpenAPI spec changes
pnpm test         # Vitest
pnpm lint         # oxlint
pnpm type-check   # vue-tsc
```

## Build (what packaging/CI runs)

```bash
cd admin
pnpm build        # type-check + vite build → ../public/admin/
```

`vite.config.ts` pins `base: '/admin/'` and `build.outDir` to `../public/admin`. `public/admin/` is
gitignored in dev and baked into release tags; the SPA *source* (`admin/`) is `export-ignore`d from
the distribution archive (`.gitattributes`).

## Runtime config

The SPA fetches `GET /admin/config.json` at boot (before mount) for `apiBase`, `sitePreviewUrl`,
`defaultLocale`, and `installed`. This keeps one compiled bundle env-agnostic across installs (see
`config/thallo.php` → `thallo.admin.*`).

## Boundary

Phase 1 **consumes** content-type schema (to drive the editor); it never **mutates** it. This is
enforced by `src/__tests__/schemaBoundary.spec.ts`, which fails the build if any source file calls a
`/content-types/.../schema` or `/content-types/.../migrations` endpoint.

## Phase-1 limitations (deliberate)

- `text` fields use `UEditor` storing an HTML string; `reference` fields use a UUID input (a
  searchable picker needs a target content type the field schema doesn't yet expose).
- The UI is en-only; locale lives in the data model.
- Content-type *management* (the schema builder) is out of scope — Phase 1 only seeds and edits.
