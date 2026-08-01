# Thallo Admin

The first-party admin SPA for a [Thallo](https://thallo.dev) instance — the editorial UI for
authoring, publishing, and managing content. It is a **replaceable client of the Thallo admin API**
(`/v1/admin`): the backend owns the data and the API; this app is one front end for it, and an
operator can disable it (`thallo.admin.enabled=false`) and bring their own.

> Source lives here; the **compiled bundle** is what the Thallo app serves at `/admin`.

## Stack

- **Vue 3.5** + `<script setup>` + TypeScript
- **Vite 8**
- **Vue Router 5** — **file-based routing** via `vue-router/vite` (routes derive from `src/pages/`)
- **Layouts** via `vite-plugin-vue-layouts-next` (`src/layouts/`)
- **Pinia 3** for state — with an AES-GCM-encrypted localStorage persistence plugin
  (`src/plugins/pinia-persist-plugin.ts`)
- **Nuxt UI 4** (Tailwind v4; `@internationalized/date` for date components). Color mode disabled;
  primary colour `valencia`.
- **Typed API client**: `openapi-typescript` (generates types from the backend's OpenAPI) +
  `openapi-fetch` (the runtime client for the authenticated `/v1/admin` surface)
- **Testing**: Vitest 4 + `@vue/test-utils` + jsdom (`src/__tests__/`)
- **Tooling**: pnpm, `oxlint` (lint), `oxfmt` (format), `vue-tsc` (type-check)

## Requirements

- Node `^22.18.0 || >=24.12.0`
- pnpm

## Scripts

```sh
pnpm install        # install deps
pnpm dev            # dev server (HMR)
pnpm build          # type-check + production build
pnpm preview        # preview the production build
pnpm test           # run unit/component tests (Vitest)
pnpm test:watch     # watch mode
pnpm lint           # oxlint   (pnpm lint:fix to autofix)
pnpm fmt            # oxfmt    (pnpm fmt:check to verify)
pnpm type-check     # vue-tsc
```

## Project layout

```
src/
  pages/        # file-based routes (each .vue → a route)         [to be added]
  layouts/      # shared layouts for vite-plugin-vue-layouts-next [to be added]
  router/       # router instance (routes injected by vue-router/vite)
  stores/       # Pinia stores
  plugins/      # app plugins (e.g. the encrypted Pinia persistence plugin)
  __tests__/    # Vitest specs
  App.vue, main.ts
```

## Integration with the Thallo backend

This SPA is mounted by the Thallo app via the framework's `serveFrontend()` seam and talks to the
admin API. A few contracts must hold for the build to drop into a Thallo instance:

- **Build output → the Thallo app's `public/admin/`** (what `serveFrontend('/admin', …)` serves).
  Set `build.outDir` accordingly (e.g. `../public/admin`). *(not wired yet — default is `dist/`)*
- **Base path `/admin/`** — the bundle is served under `/admin`, so assets must resolve there.
  Set Vite `base: '/admin/'` (the router already uses `import.meta.env.BASE_URL`).
  *(not wired yet — default is `/`)*
- **Runtime config**: the app fetches **`GET /admin/config.json`** at boot (unauthenticated) for
  `apiBase`, `defaultLocale`, `sitePreviewUrl`, and `installed` — so one compiled bundle works across
  installs without env-baking.
- **API base**: authenticated calls go to **`/v1/admin`** through the typed `openapi-fetch` client;
  the unauthenticated bootstrap/auth endpoints (`config.json`, login/refresh, `POST /admin/setup`)
  use plain `fetch`.
- **First-run**: when `config.json` reports `installed:false`, the app routes to a setup screen that
  posts to `POST /admin/setup`; once installed, that screen is inert.

## Status

Fresh scaffold. The app-specific layer — runtime config loader, typed API client + auth/session
(refresh-on-401), domain composables, the schema-driven field editor (on Nuxt UI), and the page
screens — is implemented per the Phase 1 plan
(`../docs/internal/superpowers/plans/2026-06-17-admin-spa-phase-1.md`, frontend task groups re-planned against
this scaffold).
