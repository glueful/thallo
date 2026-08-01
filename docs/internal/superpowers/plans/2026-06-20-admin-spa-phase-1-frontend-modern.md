# Admin SPA — Phase 1 Frontend (modernized) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development — implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax. Recommended fallback: superpowers:executing-plans.

**Supersedes:** Task groups **1–8** of `docs/superpowers/plans/2026-06-17-admin-spa-phase-1.md` (the frontend half — already banner-flagged SUPERSEDED there). The backend (Task group 0: `GET /v1/admin/entries`, `GET /admin/config.json`, `POST /admin/setup`, `serveFrontend()` mount) is **built and committed** and is unaffected by this plan. This plan implements the **app-specific frontend layer on top of the existing scaffold** (Vite 8 / file-based `vue-router/vite` / `vite-plugin-vue-layouts-next` / Nuxt UI 4 / Pinia 3 / **Pinia Colada**).

**Goal:** Ship Lemma's first-party editor SPA so an editor can author and publish the *existing* content types end-to-end — list (draft-inclusive) → create → edit a schema-driven, rich-text-bodied draft → set a route/slug → preview → publish/schedule/rollback/redirects → upload-and-use an asset — without touching the API by hand. Phase 1 **consumes** schema; it never mutates it.

**Architecture:** A typed-from-OpenAPI `openapi-fetch` client (auth + refresh-on-401 middleware) is the single network seam. **Server state** (content types, entries, drafts, versions, redirects, media) is owned by **Pinia Colada** (`useQuery`/`useMutation` — caching, background refetch, invalidation, optimistic updates) — NOT hand-rolled fetching composables. **Client state** (the authenticated session) is a Pinia store persisted to **encrypted localStorage** via the existing `pinia-persist-plugin`. Routing is **file-based** (`src/pages/`) with a single `router.beforeEach` guard consulting runtime config (`installed` → `/setup`) and session auth (`requiresAuth` → `/login`). The schema-driven field editor is a `type → component` registry whose components wrap **Nuxt UI** inputs and use Vue 3.5 `defineModel()`; long-form fields use **`UEditor`**. A hard, **test-enforced** boundary: no Phase 1 query/mutation/page ever calls `PATCH /content-types/{slug}/schema` or `POST /content-types/{slug}/migrations`.

**Tech Stack (all already in `admin/package.json`):**
- Vue **3.5** (`<script setup lang="ts">`, `defineModel`, `useTemplateRef`, reactive props destructure)
- Vite **8**, file-based routing via **`vue-router/vite`** (`vue-router` ^5) + **`vite-plugin-vue-layouts-next`**
- **Nuxt UI 4** (`@nuxt/ui`, Tailwind 4) — `UTable`, `UPagination`, `UForm`, `UFormField`, `UEditor`, `UInput`/`USelect`/`USwitch`/`UInputNumber`/`UFileUpload`, `UDashboard*`, `UModal`, `UButton`, `UBadge`
- **Pinia 3** (`pinia`) + **Pinia Colada** (`@pinia/colada`) for server state
- **`openapi-typescript`** (codegen) + **`openapi-fetch`** (typed client)
- **`zod` ^4** for form schemas (Standard Schema → `UForm`)
- **`@vueuse/core`** utilities
- Tests: **Vitest 4** + **`@vue/test-utils`** + jsdom; lint `oxlint`, format `oxfmt`

**Spec:** `docs/superpowers/specs/2026-06-17-admin-spa-phase-1-design.md` (the *contracts* still hold; only the toolchain modernizes).

**Decisions locked for this plan (do not re-litigate):**
- **Server state → Pinia Colada.** Replace the old "domain composables" with thin `useQuery`/`useMutation` wrappers over the typed client. Query keys + invalidation live in `src/queries/`.
- **Token storage → encrypted localStorage.** The session store persists `accessToken` (+ `user`) via the `pinia-persist-plugin` `encrypt` strategy on `localStorage`. (The old "in-memory only / never localStorage" line and its boundary test are **removed**.) The encryption secret is a build-time `VITE_ADMIN_PERSIST_SECRET`; client-side encryption-at-rest is obfuscation, not secrecy — the real defenses are short-lived access tokens + refresh-on-401. This is the maintainer's accepted tradeoff.
- **Rich text → `UEditor`.** No `markdown-it`.
- **Field inputs → Nuxt UI** wrapped per type, `defineModel()`-based.

---

## Contracts the backend already assumes (must stay true)

1. Build `base: '/admin/'` → output `public/admin/` with an `index.html` deep-link fallback. *(Vite `base` is set in `vite.config.ts` — Task F7 verifies it.)*
2. Boot fetches `GET /admin/config.json` (`apiBase`, `sitePreviewUrl`, `defaultLocale`, `installed`) before the app is usable.
3. First-run `POST /admin/setup` is hit with **raw `fetch`** (it's outside `/v1/admin`, unauthenticated, no bearer).
4. All API calls (except config.json + setup) go through the typed client at `apiBase` (`/v1/admin`) with the bearer token.

---

## File map (all under `admin/`)

**Foundation (Task group F0)**
- Create: `src/runtime/config.ts` — fetch/parse `/admin/config.json`; expose typed `RuntimeConfig`; loaded once before `app.mount`.
- Create: `src/api/schema.d.ts` — **generated** by `openapi-typescript` (gitignored or committed; see Step). 
- Create: `src/api/client.ts` — `openapi-fetch` client + auth middleware (attach bearer) + refresh-on-401 middleware.
- Create: `src/stores/session.ts` — Pinia session store (accessToken/user, login/logout/refresh), **encrypted-localStorage persist**.
- Create: `src/queries/keys.ts` — query-key factory (the cache namespace).
- Create: `src/queries/contentTypes.ts`, `entries.ts`, `drafts.ts`, `routes.ts`, `publish.ts`, `schedules.ts`, `versions.ts`, `redirects.ts`, `media.ts`, `preview.ts` — Colada `useQuery`/`useMutation` wrappers.
- Modify: `src/main.ts` — register `PiniaColada`; load runtime config before mount.
- Modify: `src/router/index.ts` — add the `beforeEach` guard (installed + auth).
- Create: `src/env.d.ts` (or extend) — type `import.meta.env.VITE_ADMIN_PERSIST_SECRET`.
- Scripts: `package.json` — add `gen:api` (openapi-typescript).

**Screens (file-based pages) & components**
- Modify: `src/pages/setup.vue`, `src/pages/login.vue`, `src/pages/index.vue` (dashboard/empty-state).
- Modify: `src/pages/content/[type]/index.vue` (entries list — `UTable`+`UPagination`).
- Modify: `src/pages/content/[type]/[uuid]/index.vue` (entry editor).
- Modify: `src/pages/content/[type]/[uuid]/versions.vue` (versions + rollback).
- Create: `src/components/FieldEditor.vue` and `src/fields/registry.ts`, `src/fields/types.ts`, and one component per type under `src/fields/components/` (`StringField.vue`, `TextField.vue`, `NumberField.vue`, `BooleanField.vue`, `DatetimeField.vue`, `EnumField.vue`, `AssetField.vue`, `ReferenceField.vue`, `JsonField.vue`).
- Modify: `src/navigation/sidebar.ts` — populate **Content** children from the content-types query at runtime (Task F2).

**Tests**
- `src/__tests__/` (mirrors existing `App.spec.ts`): `session.spec.ts`, `client.spec.ts`, `fields/StringField.spec.ts` (+ siblings), `queries/entries.spec.ts`, `schemaBoundary.spec.ts` (**the boundary test**).
- Create: `src/test/mountWithUi.ts` — mount helper stubbing Nuxt UI components to native elements.

**Packaging / CI / docs (Task F7)**
- `.gitattributes` (export-ignore SPA source), `.github/workflows/admin.yml` (build+test), release bake of `public/admin/`, `docs/ADMIN_SPA.md`.

Conventions: TS strict, `<script setup lang="ts">`, `@/` → `src/`, file-based pages, `oxlint`/`oxfmt` clean, Vitest. No barrel re-exports of the client (avoid Pinia↔client import cycles — import `client` directly).

---

## Task group F0 — Foundation (runtime config, typed client, session, Colada, guard)

> Everything downstream depends on F0. Implement it first and in order. Each task is TDD where a unit boundary exists; the wiring steps (main.ts/router) are verified by a smoke test.

### Task F0.1 — Generate the typed API + create the client with auth/refresh middleware

**Files:** Create `src/api/schema.d.ts` (generated), `src/api/client.ts`; modify `package.json`; Test `src/__tests__/client.spec.ts`.

- [ ] **Step 0: Confirm the spec source + auth endpoints.**
  - Run: `ls /Users/michaeltawiahsowah/Sites/glueful/lemma/docs/openapi.json` — the committed spec the client types against.
  - Run: `grep -n "auth/login\|auth/refresh\|auth/logout\|/me" /Users/michaeltawiahsowah/Sites/glueful/lemma/docs/openapi.json | head` — confirm the auth path shapes. If they differ from the assumed `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`, use the real ones in `session.ts` (Task F0.3). Record the confirmed paths in the commit message.

- [ ] **Step 1: Add the codegen script.** In `package.json` `scripts`, add:
```json
    "gen:api": "openapi-typescript ../docs/openapi.json -o src/api/schema.d.ts",
```

- [ ] **Step 2: Generate the types.** Run: `pnpm gen:api`. Expected: `src/api/schema.d.ts` written with a `paths` interface. (Commit it — the SPA builds in CI without the PHP app present.)

- [ ] **Step 3: Write the failing client test.** Create `src/__tests__/client.spec.ts`:
```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

// The client reads apiBase + token lazily so tests can stub both.
const getToken = vi.fn<() => string | null>()
const onRefresh = vi.fn<() => Promise<boolean>>()

vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({
    accessToken: getToken(),
    refresh: onRefresh,
    clear: vi.fn(),
  }),
}))

describe('api client middleware', () => {
  beforeEach(() => {
    getToken.mockReset()
    onRefresh.mockReset()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('attaches the bearer token from the session store', async () => {
    getToken.mockReturnValue('tok-123')
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    await client.GET('/content-types' as any)
    const req = (globalThis.fetch as any).mock.calls[0][0] as Request
    expect(req.headers.get('authorization')).toBe('Bearer tok-123')
  })

  it('refreshes once on 401 then retries; clears on refresh failure', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      .mockResolvedValueOnce(new Response('{}', { status: 401 }))
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    const res = await client.GET('/content-types' as any)
    expect(onRefresh).toHaveBeenCalledTimes(1)
    expect(res.response.status).toBe(200)
  })
})
```

- [ ] **Step 4: Run it; verify it fails.** Run: `pnpm test -- client`. Expected: FAIL — `Cannot find module '@/api/client'`.

- [ ] **Step 5: Implement the client.** Create `src/api/client.ts`:
```ts
import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { runtimeConfig } from '@/runtime/config'

// One typed client for the whole app. baseUrl comes from runtime config (env-agnostic bundle).
export const client = createClient<paths>({ baseUrl: runtimeConfig.apiBase })

// Attach the bearer from the session store on every request. Imported lazily inside the
// hook to avoid a Pinia<->client module cycle at load time.
const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const { useSessionStore } = await import('@/stores/session')
    const token = useSessionStore().accessToken
    if (token) request.headers.set('authorization', `Bearer ${token}`)
    return request
  },
}

// Refresh-on-401: on a 401, attempt a single refresh; on success retry the original request
// once; on failure clear the session (the router guard then routes to /login).
let refreshing: Promise<boolean> | null = null
const refreshMiddleware: Middleware = {
  async onResponse({ request, response }) {
    if (response.status !== 401) return response
    const { useSessionStore } = await import('@/stores/session')
    const session = useSessionStore()
    refreshing ??= session.refresh().finally(() => { refreshing = null })
    const ok = await refreshing
    if (!ok) { session.clear(); return response }
    const retry = request.clone()
    retry.headers.set('authorization', `Bearer ${session.accessToken ?? ''}`)
    return fetch(retry)
  },
}

client.use(authMiddleware)
client.use(refreshMiddleware)
```

- [ ] **Step 6: Run it; verify it passes.** Run: `pnpm test -- client`. Expected: PASS — bearer attached; single refresh + retry; clear on failure.

- [ ] **Step 7: lint + commit.**
```bash
pnpm lint && pnpm fmt:check
git add admin/package.json admin/src/api/schema.d.ts admin/src/api/client.ts admin/src/__tests__/client.spec.ts
git commit -m "Add typed OpenAPI client with auth + refresh-on-401 middleware"
```

### Task F0.2 — Runtime config loader

**Files:** Create `src/runtime/config.ts`; modify `src/env.d.ts`; Test `src/__tests__/config.spec.ts`.

- [ ] **Step 1: Type the env var.** In `src/env.d.ts` (create if absent) add:
```ts
/// <reference types="vite/client" />
interface ImportMetaEnv {
  readonly VITE_ADMIN_PERSIST_SECRET?: string
}
interface ImportMeta { readonly env: ImportMetaEnv }
```

- [ ] **Step 2: Write the failing test.** Create `src/__tests__/config.spec.ts`:
```ts
import { describe, it, expect, vi } from 'vitest'

describe('runtime config loader', () => {
  it('fetches /admin/config.json and exposes typed fields', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
      new Response(JSON.stringify({
        apiBase: '/v1/admin', sitePreviewUrl: 'https://x/preview',
        defaultLocale: 'en', installed: true,
      }), { status: 200 }),
    ))
    const { loadRuntimeConfig } = await import('@/runtime/config')
    const cfg = await loadRuntimeConfig()
    expect(cfg.apiBase).toBe('/v1/admin')
    expect(cfg.installed).toBe(true)
  })
})
```

- [ ] **Step 3: Run it; verify it fails.** Run: `pnpm test -- config`. Expected: FAIL — module missing.

- [ ] **Step 4: Implement.** Create `src/runtime/config.ts`:
```ts
export interface RuntimeConfig {
  apiBase: string
  sitePreviewUrl: string
  defaultLocale: string
  installed: boolean
}

// Filled by loadRuntimeConfig() before app.mount (main.ts). Exported as a mutable singleton so
// the client/stores can read it synchronously after boot. Defaults are safe pre-load values.
export const runtimeConfig: RuntimeConfig = {
  apiBase: '/v1/admin', sitePreviewUrl: '', defaultLocale: 'en', installed: false,
}

export async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  const res = await fetch('/admin/config.json', { headers: { accept: 'application/json' } })
  if (!res.ok) throw new Error(`config.json ${res.status}`)
  const data = (await res.json()) as Partial<RuntimeConfig>
  Object.assign(runtimeConfig, {
    apiBase: data.apiBase ?? runtimeConfig.apiBase,
    sitePreviewUrl: data.sitePreviewUrl ?? '',
    defaultLocale: data.defaultLocale ?? 'en',
    installed: Boolean(data.installed),
  })
  return runtimeConfig
}
```

- [ ] **Step 5: Run it; verify it passes.** Run: `pnpm test -- config`. Expected: PASS.

- [ ] **Step 6: lint + commit.**
```bash
pnpm lint && git add admin/src/runtime/config.ts admin/src/env.d.ts admin/src/__tests__/config.spec.ts
git commit -m "Add runtime config loader (GET /admin/config.json)"
```

### Task F0.3 — Session store (encrypted-localStorage persist)

**Files:** Create `src/stores/session.ts`; Test `src/__tests__/session.spec.ts`.

> Uses the existing `pinia-persist-plugin` `strategies` shape: `{ key, storage: localStorage, encrypt: { secret }, mergeStrategy: 'shallow' }`. The plugin restores asynchronously, so treat persisted token as best-effort hydration; the router guard always re-checks `isAuthenticated`.

- [ ] **Step 1: Write the failing test.** Create `src/__tests__/session.spec.ts`:
```ts
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

describe('session store', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.stubGlobal('fetch', vi.fn()) })

  it('starts unauthenticated; setSession authenticates', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    expect(s.isAuthenticated).toBe(false)
    s.setSession('tok', { uuid: 'u1', email: 'a@b.c' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('tok')
  })

  it('login posts credentials and stores the returned token', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(new Response(
      JSON.stringify({ data: { token: 'jwt', user: { uuid: 'u1', email: 'a@b.c' } } }),
      { status: 200 }))
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    await s.login('a@b.c', 'pw')
    expect(s.isAuthenticated).toBe(true)
  })

  it('clear() wipes the session', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('tok', { uuid: 'u1', email: 'a@b.c' })
    s.clear()
    expect(s.isAuthenticated).toBe(false)
    expect(s.accessToken).toBeNull()
  })
})
```

- [ ] **Step 2: Run it; verify it fails.** Run: `pnpm test -- session`. Expected: FAIL — module missing.

- [ ] **Step 3: Implement.** Create `src/stores/session.ts` (confirm auth paths from Task F0.1 Step 0; adjust the three `fetch` URLs if they differ):
```ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { runtimeConfig } from '@/runtime/config'

export interface SessionUser { uuid: string; email: string }

// Auth is cookie/credential based and lives OUTSIDE the typed /v1/admin client surface, so it
// uses raw fetch against the framework auth endpoints (confirmed in F0.1 Step 0).
const authUrl = (path: string) => `${runtimeConfig.apiBase.replace(/\/v1\/admin$/, '')}/auth${path}`

export const useSessionStore = defineStore('session', () => {
  const accessToken = ref<string | null>(null)
  const user = ref<SessionUser | null>(null)
  const isAuthenticated = computed(() => accessToken.value !== null)

  function setSession(token: string, u: SessionUser) { accessToken.value = token; user.value = u }
  function clear() { accessToken.value = null; user.value = null }

  async function login(email: string, password: string): Promise<void> {
    const res = await fetch(authUrl('/login'), {
      method: 'POST', credentials: 'include',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ email, password }),
    })
    if (!res.ok) throw new Error('login failed')
    const { data } = await res.json()
    setSession(data.token, data.user)
  }

  // Refresh via the httpOnly refresh cookie. Returns true on success (token swapped), false otherwise.
  async function refresh(): Promise<boolean> {
    try {
      const res = await fetch(authUrl('/refresh'), { method: 'POST', credentials: 'include' })
      if (!res.ok) return false
      const { data } = await res.json()
      accessToken.value = data.token
      if (data.user) user.value = data.user
      return true
    } catch { return false }
  }

  async function logout(): Promise<void> {
    try { await fetch(authUrl('/logout'), { method: 'POST', credentials: 'include' }) } finally { clear() }
  }

  return { accessToken, user, isAuthenticated, setSession, clear, login, refresh, logout }
}, {
  // Encrypted-localStorage persistence (maintainer's accepted tradeoff). Only the token + user
  // are persisted; everything else is derived. The secret is build-time (VITE_*); client-side
  // encryption-at-rest is obfuscation, not secrecy — short-lived tokens + refresh are the real defense.
  persist: {
    enabled: true,
    strategies: [{
      key: 'lemma_session',
      storage: localStorage,
      encrypt: { secret: import.meta.env.VITE_ADMIN_PERSIST_SECRET ?? 'lemma-admin-dev' },
      mergeStrategy: 'shallow',
      debounce: 100,
    }],
  },
})
```

- [ ] **Step 4: Run it; verify it passes.** Run: `pnpm test -- session`. Expected: PASS.

- [ ] **Step 5: lint + commit.**
```bash
pnpm lint && git add admin/src/stores/session.ts admin/src/__tests__/session.spec.ts
git commit -m "Add session store with encrypted-localStorage persistence"
```

### Task F0.4 — Register Pinia Colada + load config before mount + query keys

**Files:** Modify `src/main.ts`; Create `src/queries/keys.ts`.

- [ ] **Step 1: Query-key factory.** Create `src/queries/keys.ts`:
```ts
// Central cache namespace. Every Colada query keys off these so invalidation is exhaustive
// and typo-proof. Keys are MaybeRefOrGetter-friendly (Pinia Colada): pass getters where a
// param is reactive (e.g. () => ['entries', typeSlug.value]).
export const qk = {
  contentTypes: () => ['content-types'] as const,
  entries: (type: string) => ['entries', type] as const,
  entry: (uuid: string) => ['entry', uuid] as const,
  draft: (uuid: string, locale: string) => ['draft', uuid, locale] as const,
  routes: (uuid: string) => ['routes', uuid] as const,
  versions: (uuid: string) => ['versions', uuid] as const,
  redirects: (type: string) => ['redirects', type] as const,
}
```

- [ ] **Step 2: Wire Colada + config-before-mount.** Edit `src/main.ts` to register `PiniaColada` and load runtime config before `app.mount`:
```ts
import './assets/css/main.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { PiniaColada } from '@pinia/colada'
import piniaPluginPersist from './plugins/pinia-persist-plugin'
import ui from '@nuxt/ui/vue-plugin'
import App from './App.vue'
import router from './router'
import { loadRuntimeConfig } from './runtime/config'

async function bootstrap() {
  // Runtime config MUST resolve before mount: the client baseUrl + the guard's `installed`
  // check read it synchronously. A failure here is fatal (the SPA can't find its API).
  await loadRuntimeConfig()

  const app = createApp(App)
  const pinia = createPinia()
  pinia.use(piniaPluginPersist)
  app.use(pinia)
  app.use(PiniaColada, {
    // Sensible server-state defaults; per-query overrides where needed.
    queryOptions: { staleTime: 30_000, gcTime: 5 * 60_000 },
  })
  app.use(router)
  app.use(ui)
  app.mount('#app')
}

void bootstrap()
```

- [ ] **Step 3: Smoke-check the build.** Run: `pnpm type-check`. Expected: no type errors. Run: `pnpm test` — existing `App.spec.ts` + F0 tests pass.

- [ ] **Step 4: lint + commit.**
```bash
pnpm lint && git add admin/src/main.ts admin/src/queries/keys.ts
git commit -m "Register Pinia Colada and load runtime config before mount"
```

### Task F0.5 — Router guard (installed → /setup, requiresAuth → /login)

**Files:** Modify `src/router/index.ts`; mark protected pages via `definePage`.

> File-based routes already resolve. The guard adds (a) the install gate and (b) the auth gate, in that order. Pages declare `requiresAuth`/`layout` with `definePage({ meta: {...} })` in their `<script setup>`.

- [ ] **Step 1: Add the guard.** Edit `src/router/index.ts`, after the `createRouter(...)` call and before `export default`:
```ts
import { runtimeConfig } from '@/runtime/config'
import { useSessionStore } from '@/stores/session'

// Order matters: install gate first (a fresh instance has no admin to authenticate), then auth.
router.beforeEach((to) => {
  // (1) Install gate. Until setup has run, force everything to /setup; once installed, /setup is inert.
  if (!runtimeConfig.installed && to.path !== '/setup') return { path: '/setup' }
  if (runtimeConfig.installed && to.path === '/setup') return { path: '/login' }

  // (2) Auth gate. Protected pages opt in via meta.requiresAuth (definePage). The login page
  // bounces to home when already authenticated.
  const session = useSessionStore()
  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && session.isAuthenticated) return { path: '/' }
  return true
})
```

- [ ] **Step 2: Declare page meta.** In each protected page's `<script setup>` (`index.vue`, `content/[type]/index.vue`, `content/[type]/[uuid]/index.vue`, `versions.vue`) add:
```ts
definePage({ meta: { requiresAuth: true } })
```
and in `login.vue` + `setup.vue`:
```ts
definePage({ meta: { layout: 'auth' } })
```
(`definePage` is a compiler macro from `vue-router/vite` — no import needed. The default layout (`default.vue`) applies to pages that don't set one.)

- [ ] **Step 3: Smoke test the guard.** Create `src/__tests__/guard.spec.ts` asserting: uninstalled → redirects to `/setup`; installed+unauthenticated+requiresAuth → redirects to `/login`. (Mock `@/runtime/config` and `@/stores/session`; call the exported guard fn — refactor the guard into a named `installAndAuthGuard(to)` export so it's unit-testable, and pass it to `router.beforeEach`.)

- [ ] **Step 4: lint + commit.**
```bash
pnpm lint && pnpm type-check
git add admin/src/router/index.ts admin/src/pages admin/src/__tests__/guard.spec.ts
git commit -m "Add install + auth router guard"
```

---

## Task group F1 — First-run setup + login screens

### Task F1.1 — Setup screen (`/setup`)

**Files:** Modify `src/pages/setup.vue`; Test `src/__tests__/setup-page.spec.ts`.

The setup form POSTs to the **unauthenticated** `/admin/setup` with **raw `fetch`** (not the typed client — it's outside `/v1/admin`, no bearer). On success → `/login`. Validate with a `zod` schema bound to `UForm`.

- [ ] **Step 1: Write the failing test** (asserts: valid submit calls `fetch('/admin/setup', {method:'POST', ...})` and pushes `/login`; a 409 surfaces an error toast/message). *(Full test code: mock `vue-router`'s `useRouter().push`, stub `fetch`, mount with `mountWithUi`.)*
- [ ] **Step 2: Implement `setup.vue`** using `UForm` + `zod` (fields: `site_name`, `admin_email`, `admin_password`, `locale` defaulting to `runtimeConfig.defaultLocale`), `UFormField` + `UInput`, submit handler doing the raw `fetch`. `definePage({ meta: { layout: 'auth' } })`.
- [ ] **Step 3–5:** run/verify/lint/commit (`"Implement first-run setup screen"`).

### Task F1.2 — Login screen (`/login`)

**Files:** Modify `src/pages/login.vue`; Test `src/__tests__/login-page.spec.ts`.

- [ ] **Step 1: Failing test** — valid submit calls `session.login(email, pw)` then pushes `redirect` query (or `/`); a rejected login shows an error.
- [ ] **Step 2: Implement** with `UForm`+`zod` (hand-built — **do NOT use `UAuthForm`**), `UFormField` + `UInput` for email/password and a `UButton` submit, calling `useSessionStore().login`. `definePage({ meta: { layout: 'auth' } })`. The `auth` layout already provides the card/branding chrome, so the page is just the form.
- [ ] **Step 3–5:** run/verify/lint/commit (`"Implement login screen"`).

---

## Task group F2 — Home + dynamic Content nav + entries list

### Task F2.1 — Content-types query + dynamic sidebar children

**Files:** Create `src/queries/contentTypes.ts`; modify `src/navigation/sidebar.ts` + `src/layouts/default.vue` (populate Content children).

- [ ] **Step 1: Failing test** (`src/__tests__/queries/contentTypes.spec.ts`) — `useContentTypes()` calls `client.GET('/content-types')` and returns the list. *(Mock `@/api/client`.)*
- [ ] **Step 2: Implement the query.** Create `src/queries/contentTypes.ts`:
```ts
import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { qk } from './keys'

export function useContentTypes() {
  return useQuery({
    key: qk.contentTypes(),
    query: async () => {
      const { data, error } = await client.GET('/content-types')
      if (error) throw error
      return data?.data?.content_types ?? []
    },
  })
}
```
> Confirm the response envelope shape (`data.content_types`) against `schema.d.ts` / the OpenAPI `ContentTypeListData` before finalizing the accessor.
- [ ] **Step 3: Populate the nav.** In `default.vue`, replace the static `items[0]` Content child list: compute the Content section's `children` from `useContentTypes()` — map each type to `{ label: type.name, to: \`/content/${type.slug}\` }`. Keep the rest of `sidebar.ts` static; only Content children come from the query. (Show a skeleton while `isLoading`.)
- [ ] **Step 4–6:** run/verify/lint/commit (`"Load content-type nav from Pinia Colada query"`).

### Task F2.2 — Home dashboard + first-run empty state

**Files:** Modify `src/pages/index.vue`.

- [ ] **Step 1: Failing test** — when `useContentTypes()` returns the seeded `page` type but no entries, the page renders the "create your first page" onboarding; when content exists, it renders recent entries / quick actions.
- [ ] **Step 2: Implement** using `UEmpty` for the onboarding state + `UPageCard`/`UButton` quick actions. (Recent entries can be a follow-on; Phase-1 minimum is the greeting + the empty-state CTA wired to `/content/page`.)
- [ ] **Step 3–5:** run/verify/lint/commit (`"Implement Home dashboard with first-run empty state"`).

### Task F2.3 — Entries list (`/content/[type]`) with `UTable` + `UPagination`

**Files:** Create `src/queries/entries.ts`; modify `src/pages/content/[type]/index.vue`; Test `src/__tests__/queries/entries.spec.ts`.

- [ ] **Step 1: Failing test for the query** — `useEntries(typeSlug, page, perPage, q)` calls `client.GET('/entries', { params: { query: { type, page, perPage, q } } })` and returns `{ entries, total, current_page, per_page }`; the key is `qk.entries(type)` so it refetches when `type` changes.
- [ ] **Step 2: Implement the query.** Create `src/queries/entries.ts`:
```ts
import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { qk } from './keys'

export function useEntries(
  type: MaybeRefOrGetter<string>,
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    // Key includes page/q so each page+filter is cached independently and refetched on change.
    key: () => [...qk.entries(toValue(type)), toValue(page), toValue(q) ?? ''],
    query: async () => {
      const { data, error } = await client.GET('/entries', {
        params: { query: { type: toValue(type), page: toValue(page), perPage: toValue(perPage), q: toValue(q) || undefined } },
      })
      if (error) throw error
      return data!.data
    },
  })
}
```
- [ ] **Step 3: Implement the page.** `content/[type]/index.vue`:
  - `const route = useRoute()`, `const type = computed(() => route.params.type as string)`.
  - `page`/`perPage`/`q` as `ref`s; `const { data, isLoading } = useEntries(type, page, perPage, q)`.
  - `UTable` with columns: select, **Title** (`display_title`, links to `/content/${type}/${row.uuid}`), **Status** (`UBadge`), **Locales** (badges), **Updated** (relative date), actions.
  - `UPagination` bound to `page` with `:total="data?.total"` and `:items-per-page="perPage"` — page change refetches (server-paged).
  - A `UInput` search bound to `q` (debounced via `@vueuse/core` `refDebounced`).
  - "New entry" button → POST create (Task F3 wires the mutation; here a button routing to a new editor or calling the create mutation).
- [ ] **Step 4–6:** run/verify/lint/commit (`"Implement entries list with UTable + UPagination"`).

---

## Task group F3 — Field registry + entry editor (draft save)

### Task F3.1 — Field types + registry + FieldEditor

**Files:** Create `src/fields/types.ts`, `src/fields/registry.ts`, `src/fields/components/*.vue`, `src/components/FieldEditor.vue`; Tests `src/__tests__/fields/*.spec.ts`.

> Each field component takes the field definition + a `v-model` value via **`defineModel()`** and renders the matching Nuxt UI input. The registry maps the backend field `type` → component. `FieldEditor` iterates a content-type schema and renders one field component per field.

- [ ] **Step 1: Field component contract.** Create `src/fields/types.ts`:
```ts
export interface FieldDef {
  name: string
  type: 'string' | 'text' | 'number' | 'boolean' | 'datetime' | 'enum' | 'reference' | 'asset' | 'json'
  required?: boolean
  enum?: string[]
}
```

- [ ] **Step 2: Write the failing test for `StringField`.** `src/__tests__/fields/StringField.spec.ts`:
```ts
import { describe, it, expect } from 'vitest'
import { mountWithUi } from '@/test/mountWithUi'
import StringField from '@/fields/components/StringField.vue'

describe('StringField', () => {
  it('renders the value and emits updates via v-model', async () => {
    const wrapper = mountWithUi(StringField, {
      props: { field: { name: 'title', type: 'string', required: true }, modelValue: 'Hello' },
    })
    const input = wrapper.get('input')
    expect((input.element as HTMLInputElement).value).toBe('Hello')
    await input.setValue('World')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['World'])
  })
})
```

- [ ] **Step 3: Implement `StringField.vue`** (the canonical pattern — all siblings follow it):
```vue
<script setup lang="ts">
import type { FieldDef } from '../types'
defineProps<{ field: FieldDef }>()
// Vue 3.5 defineModel: two-way binding without manual props/emit plumbing.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" />
  </UFormField>
</template>
```

- [ ] **Step 4: Implement the sibling components** — same structure, swapping the input. Implement each with its test (one `*.spec.ts` per field, mirroring Step 2):

| Component | Field type(s) | Nuxt UI input | `defineModel<T>` |
|---|---|---|---|
| `TextField.vue` | `text` | `UEditor` (rich text; bind its model; store HTML/Markdown per schema) | `string` |
| `NumberField.vue` | `number` | `UInputNumber` | `number` |
| `BooleanField.vue` | `boolean` | `USwitch` | `boolean` |
| `DatetimeField.vue` | `datetime` | `UInput type="datetime-local"` (or `UInputDate`) | `string` |
| `EnumField.vue` | `enum` | `USelect :items="field.enum"` | `string` |
| `AssetField.vue` | `asset` | (Task F6 — `UFileUpload` + media mutation) | `string` |
| `ReferenceField.vue` | `reference` | `USelectMenu` (searchable; options from a references query) | `string` |
| `JsonField.vue` | `json` | `UTextarea` (raw JSON; validate parse on blur) | `string` |

- [ ] **Step 5: Implement the registry.** `src/fields/registry.ts`:
```ts
import type { Component } from 'vue'
import type { FieldDef } from './types'
import StringField from './components/StringField.vue'
import TextField from './components/TextField.vue'
import NumberField from './components/NumberField.vue'
import BooleanField from './components/BooleanField.vue'
import DatetimeField from './components/DatetimeField.vue'
import EnumField from './components/EnumField.vue'
import AssetField from './components/AssetField.vue'
import ReferenceField from './components/ReferenceField.vue'
import JsonField from './components/JsonField.vue'

const registry: Record<FieldDef['type'], Component> = {
  string: StringField, text: TextField, number: NumberField, boolean: BooleanField,
  datetime: DatetimeField, enum: EnumField, asset: AssetField, reference: ReferenceField, json: JsonField,
}

export function fieldComponent(type: FieldDef['type']): Component {
  return registry[type] ?? StringField // unknown types degrade to a string input, never crash
}
```

- [ ] **Step 6: Implement `FieldEditor.vue`** — takes `schema: FieldDef[]` + a `v-model` record of field values; renders `<component :is="fieldComponent(f.type)" v-model="model[f.name]" :field="f" />` per field inside a `UForm`.

- [ ] **Step 7: Implement `src/test/mountWithUi.ts`** — a `mount` wrapper that registers global stubs mapping Nuxt UI components (`UInput`, `UFormField`, `USelect`, `USwitch`, `UEditor`, …) to native elements so field/component tests don't need the full UI runtime. *(Full helper code: a `ComponentMountingOptions` factory with a `global.stubs` map; `UInput`→`<input>`, `UFormField`→passthrough slot, etc.)*

- [ ] **Step 8: lint + commit** (`"Add schema-driven field registry + editor on Nuxt UI"`).

### Task F3.2 — Entry draft query + save mutation + editor page

**Files:** Create `src/queries/drafts.ts`; modify `src/pages/content/[type]/[uuid]/index.vue`.

- [ ] **Step 1: Failing test** — `useDraft(uuid, locale)` GETs the draft; `useSaveDraft()` is a `useMutation` that PATCHes/POSTs the draft and **invalidates** `qk.draft(uuid, locale)` + `qk.entries(type)` on success (so the list reflects the new title).
- [ ] **Step 2: Implement `drafts.ts`** with `useQuery` (load draft fields + `lock_version`) and `useMutation`:
```ts
import { useMutation, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { qk } from './keys'

export function useSaveDraft(uuid: string, locale: string, type: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (vars: { fields: Record<string, unknown>; lock_version: number }) =>
      client.PUT('/entries/{uuid}/draft/{locale}', {
        params: { path: { uuid, locale } }, body: vars,
      }).then(({ data, error }) => { if (error) throw error; return data }),
    onSettled: () => {
      cache.invalidateQueries({ key: qk.draft(uuid, locale) })
      cache.invalidateQueries({ key: qk.entries(type) })
    },
  })
}
```
> Confirm the real draft-save verb/path + body (`fields`/`lock_version`) against the existing `SaveDraftData` DTO + routes before finalizing.
- [ ] **Step 3: Implement the editor page** — load the type schema (from `useContentTypes()` find-by-slug) + draft, render `FieldEditor`, a Save button calling the mutation (optimistic-concurrency via `lock_version`), and (Task F4) the route/publish controls. Handle the `409` lock-version conflict with a `UModal`/toast prompting reload.
- [ ] **Step 4–6:** run/verify/lint/commit (`"Implement entry editor with draft save (optimistic concurrency)"`).

---

## Task group F4 — Route/slug editor + preview + publish/schedule

**Files:** Create `src/queries/routes.ts`, `publish.ts`, `schedules.ts`, `preview.ts`; extend the editor page.

- [ ] **F4.1 Route/slug editor** — `useRoutes(uuid)` query + `useSaveRoute()` mutation (set the per-locale slug). UI: a `UFormField` + `UInput` for the slug, validation against conflicts (surface the backend 409).
- [ ] **F4.2 Preview** — `usePreview()` mints a preview token (mutation) then opens `runtimeConfig.sitePreviewUrl` with the token (e.g. `window.open`). No caching (it's an action).
- [ ] **F4.3 Publish / unpublish** — `usePublish(uuid)` mutations; on success invalidate `qk.entry`, `qk.entries(type)` (status badge updates).
- [ ] **F4.4 Schedule** — `useSchedules(uuid)` query + create/cancel schedule mutations (`UInputDate`/`UInput type=datetime-local` for the time).
- Each sub-task: failing test (mock `@/api/client`) → implement query/mutation → wire UI control on the editor page → run/verify → lint/commit.

---

## Task group F5 — Versions + rollback + redirects

**Files:** Create `src/queries/versions.ts`, `redirects.ts`; modify `src/pages/content/[type]/[uuid]/versions.vue`.

- [ ] **F5.1 Versions + rollback** — `useVersions(uuid)` query (list) + `useRollback()` mutation; `versions.vue` renders a `UTable`/`UTimeline` of versions with a "Restore" action; rollback invalidates draft + entry + versions keys.
- [ ] **F5.2 Redirects** — `useRedirects(type)` query + create/delete mutations (the backend endpoints already exist — see `RedirectController`); a redirects panel (list `UTable` + a create `UForm`). Surface `target_state` (live/broken) as a `UBadge`.
- Each: failing test → implement → wire → run/verify → lint/commit.

---

## Task group F6 — Upload-and-use asset field

**Files:** Create `src/queries/media.ts`; finish `src/fields/components/AssetField.vue`.

- [ ] **F6.1** `useUploadMedia()` mutation — POSTs a `FormData` blob to the blob-upload endpoint (raw `fetch` if the typed client can't express multipart, else the client), returns the asset URL/uuid.
- [ ] **F6.2** `AssetField.vue` — `UFileUpload` (drop area) → on select, call the upload mutation → on success set `defineModel` to the returned reference; show a thumbnail/filename + a "remove" action. No media-library browser in Phase 1 (upload-and-use only).
- [ ] Failing test (stub upload) → implement → run/verify → lint/commit.

---

## Task group F7 — Schema-boundary test, packaging, CI, docs

### Task F7.1 — THE SCHEMA-BOUNDARY TEST (named, runnable)

**Files:** Create `src/__tests__/schemaBoundary.spec.ts`.

The invariant: **no Phase-1 source calls the schema-mutation endpoints.** Implement as a source-scan test (greps the built source for the forbidden path literals) AND, belt-and-suspenders, asserts no query/mutation module references them.

- [ ] **Step 1: Write the test.**
```ts
import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

// Phase 1 CONSUMES schema; it must never mutate it. These endpoint shapes are the boundary.
const FORBIDDEN = [/\/content-types\/[^'"`]*\/schema/, /\/content-types\/[^'"`]*\/migrations/, /PATCH.*content-types/i]

function walk(dir: string): string[] {
  return readdirSync(dir).flatMap((e) => {
    const p = join(dir, e)
    if (e === '__tests__' || e === 'node_modules') return []
    return statSync(p).isDirectory() ? walk(p) : p.endsWith('.ts') || p.endsWith('.vue') ? [p] : []
  })
}

describe('schema-boundary (Phase 1 never mutates content-type schema)', () => {
  it('no source file references the schema-mutation endpoints', () => {
    const offenders: string[] = []
    for (const file of walk(join(__dirname, '..'))) {
      const src = readFileSync(file, 'utf8')
      if (FORBIDDEN.some((re) => re.test(src))) offenders.push(file)
    }
    expect(offenders, `forbidden schema-mutation references in: ${offenders.join(', ')}`).toEqual([])
  })
})
```
- [ ] **Step 2: Run it; verify it passes** (it must pass on the implemented code — if it fails, a Phase-1 file is reaching past the boundary; fix the file, not the test).
- [ ] **Step 3: lint + commit** (`"Add schema-boundary test (Phase 1 consumes, never mutates, schema)"`).

### Task F7.2 — Packaging, CI, docs

**Files:** `admin/.gitattributes`, `.github/workflows/admin.yml`, release bake step, `docs/ADMIN_SPA.md`; verify `vite.config.ts` `base`.

- [ ] **Step 1: Verify build contract.** Confirm `vite.config.ts` sets `base: '/admin/'` and `build.outDir` resolves to `../public/admin` (the path `serveFrontend()` mounts). If absent, add them. Run `pnpm build` and confirm `public/admin/index.html` is produced.
- [ ] **Step 2: export-ignore the source.** `admin/.gitattributes`: `*/admin/** export-ignore` pattern so the SPA *source* is excluded from the dist archive while the *compiled* `public/admin/` ships (baked by release CI).
- [ ] **Step 3: CI.** `.github/workflows/admin.yml` — on PR/push touching `admin/**`: `pnpm install`, `pnpm lint`, `pnpm type-check`, `pnpm test`, `pnpm build`.
- [ ] **Step 4: Release bake.** The release workflow builds `admin/` and commits/attaches `public/admin/` to the tag (WordPress-style — source in repo, compiled bundle in the release).
- [ ] **Step 5: Docs.** `docs/ADMIN_SPA.md` — develop/build/runtime-config/swap-or-disable notes (mirror the old plan's §, updated for the modern stack: Pinia Colada, encrypted-localStorage, `VITE_ADMIN_PERSIST_SECRET`).
- [ ] **Step 6: commit** (`"Add admin SPA packaging, CI, and docs"`).

---

## Final review (after all tasks)

Dispatch a final code reviewer over the whole `admin/` diff, then `superpowers:finishing-a-development-branch`.

## Self-review notes (plan ↔ spec coverage)

Every Phase-1 scope item is mapped: **1** Auth shell → F0.3 + F1.2. **2** Content-type nav → F2.1. **3** Draft-inclusive list → backend (done) + F2.3. **4** Create entry + schema editor → F3. **5** Schema-driven field editor → F3.1 (registry/`FieldEditor`, Nuxt UI, `defineModel`). **6** Rich-text body → F3.1 `TextField` (**`UEditor`**, replaces markdown-it). **7** Asset upload-and-use → F6. **8** Route/slug editor → F4.1. **9** Preview via configured URL → F4.2. **10** Publish/unpublish/schedule → F4.3/F4.4. **11** Versions + rollback → F5.1. **12** Redirects → F5.2. **First-run web setup** → backend (done) + F1.1. **Runtime config** → F0.2. **Typed client + server state** → F0.1 + Pinia Colada queries (F2–F6). **Static serving + index fallback** → backend (done) + F7.2 build contract. **Boundary** → F7.1. **Token storage** → encrypted-localStorage (F0.3), superseding the old in-memory design.

**Modernizations vs the 06-17 plan:** hand-rolled router → file-based (scaffold); domain fetch composables → **Pinia Colada** `useQuery`/`useMutation` (F0.4–F6); `markdown-it` → **`UEditor`** (F3.1); raw-HTML field inputs → **Nuxt UI** + `defineModel` (F3.1); in-memory token → **encrypted localStorage** (F0.3); `strip-admin-paths.mjs` → Vite `base` (F7.2).
```
