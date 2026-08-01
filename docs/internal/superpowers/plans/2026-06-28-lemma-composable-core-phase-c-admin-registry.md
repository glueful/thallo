# Lemma Composable Core — Phase C: Admin Module Registry — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the admin SPA capability-aware — a `registerAdminModule({ id, requires, nav })` registry plus a capabilities store that loads `GET /v1/admin/capabilities`, so the sidebar shows (and routes admit) only modules whose required capability is enabled, with core screens registered as always-on modules and no hard-coded capability conditionals.

**Architecture:** Phase C of four (A contracts ✅ → B capability spine ✅ → **C admin registry** → D reference extraction). Frontend-only (Vue 3 + Vite + Nuxt UI + Pinia). A Pinia setup-store fetches the enabled capability ids (post-auth) from Phase B's endpoint. A pure-TS registry holds nav contributions tagged with `requires` (capability ids); core nav is registered as an always-on module (empty `requires`). The dashboard layout renders `visibleNav(isEnabled)` from the registry instead of a static array, and the router guard blocks routes tagged `meta.requiresCapability` when that capability is disabled. The match is **by capability id** — the Vue module carries its own nav; the backend only reports which ids are enabled.

**Tech Stack:** Vue 3.5, vue-router 5 (file-based auto-routes via `vue-router/vite`), Pinia 3 (setup-stores) + Pinia Colada, Nuxt UI 4, openapi-fetch / `authFetch`, Vitest 4 + @vue/test-utils + jsdom, oxlint + oxfmt, `vue-tsc` type-check. Work in `/Users/michaeltawiahsowah/Sites/glueful/lemma/admin`.

## Scope decisions (refine the spec's literal V1 wording — read first)

- **Frontend-only.** No backend change. Phase B's `GET /v1/admin/capabilities` already returns the enabled set (`{id,label,description,requires}`); Phase C consumes it.
- **The registry is nav-focused.** Because routes are file-based (`src/pages/**`), `registerAdminModule` carries `nav` + `requires` in V1. Its `routes`/`settingsPanels`/`fieldWidgets` fields (spec §6) are the **future runtime model** and are **not built here**.
- **Backend admin-contribution descriptors (spec §4.6) are deferred to the future runtime-loaded model** — the static Vue module already carries its nav, and matching is by capability id. (This refines the Phase B note "descriptors deferred to Phase C".)
- **Capabilities load post-auth**, triggered by the authenticated dashboard layout (the `/capabilities` endpoint needs auth, so it cannot load in the pre-mount bootstrap that fetches `/admin/config`). The nav is reactive, so it fills in when the load resolves; gated items are hidden until then (safe default). Core items (no `requires`) are always visible.

## Global Constraints

- **No backend change.** All work is under `admin/`.
- **TypeScript strict, no implicit `any`.** Match the codebase: typed openapi-fetch / `authFetch`, `ref()`/`computed()` setup-stores (NOT options stores), file-based routes with `definePage({ meta })`.
- **Match the enabled-set semantics:** a module is visible iff **every** id in its `requires` is enabled. An empty/absent `requires` means always-on (core). An unknown/disabled id hides the module.
- **No hard-coded capability conditionals in the sidebar** — gating flows through the registry + capabilities store, not `v-if="cap"` in `default.vue`.
- **No behavior change for the current (capability-free) nav:** with no pack modules registered and the capabilities store loaded, the sidebar must render exactly the items it renders today — including the live content-types injection into the Content section, the single populated nav group (with Utilities as an in-group accordion), and the bottom menu staying empty (`items[1]` does not exist today).
- **Lint/type/test gates per task:** `npm run lint` (oxlint) and `npm run fmt:check` (oxfmt) must pass on changed files; `npm run type-check` must pass. **Do NOT pipe `vue-tsc`/`npm run type-check` through `tail`/`head`** — it masks the exit code; run it directly and read the final status.
- **Commit gate:** Do **NOT** run `git commit` until the human explicitly authorizes it. Each task's final step keeps its `git add` (staging) but its `git commit` line runs **only after authorization** — until then, stage, stop, and report. This applies to every task below. Never push or open a PR.

---

### Task 1: Capabilities store

**Files:**
- Create: `admin/src/stores/capabilities.ts`
- Test: `admin/src/__tests__/capabilities.spec.ts`

**Interfaces:**
- Consumes: `authFetch(path)` from `admin/src/api/authFetch.ts` (returns `Promise<Record<string,unknown>>`, throws on non-2xx); `runtimeConfig.apiBase` from `admin/src/runtime/config.ts` (the `/v1/admin` base).
- Produces: `useCapabilitiesStore()` (Pinia setup-store) exposing:
  - `enabledIds: Ref<Set<string>>` — enabled capability ids.
  - `loaded: Ref<boolean>` — true once a load has completed.
  - `isEnabled(id: string): boolean` — membership in `enabledIds`.
  - `load(): Promise<void>` — fetch `${apiBase}/capabilities`, read `data.capabilities` (array of `{id,…}`), set `enabledIds` to their ids, set `loaded=true`. On error: leave `enabledIds` empty, set `loaded=true` (fail-closed: nothing pack-gated shows, core still does).
  - `ensureLoaded(): Promise<void>` — call `load()` at most once (dedupe concurrent calls; no-op once `loaded`).

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useCapabilitiesStore } from '@/stores/capabilities'

describe('capabilities store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    authFetch.mockReset()
  })

  it('loads enabled capability ids from the endpoint', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'lemma.forms' }, { id: 'lemma.render' }] } })
    const store = useCapabilitiesStore()
    expect(store.loaded).toBe(false)
    await store.load()
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/capabilities')
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('lemma.forms')).toBe(true)
    expect(store.isEnabled('lemma.render')).toBe(true)
    expect(store.isEnabled('lemma.nope')).toBe(false)
  })

  it('fails closed on error (empty enabled set, loaded=true)', async () => {
    authFetch.mockRejectedValue(new Error('403'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('lemma.forms')).toBe(false)
  })

  it('ensureLoaded loads at most once', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [] } })
    const store = useCapabilitiesStore()
    await Promise.all([store.ensureLoaded(), store.ensureLoaded()])
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(1)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/__tests__/capabilities.spec.ts`
Expected: FAIL — cannot resolve `@/stores/capabilities`.

- [ ] **Step 3: Implement the store**

`admin/src/stores/capabilities.ts`:
```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

interface CapabilityRow {
  id: string
}

/**
 * Enabled capability ids, loaded post-auth from GET /v1/admin/capabilities (Phase B).
 * Drives capability-gated nav (the admin module registry) and route gating. Fails closed:
 * on error the enabled set is empty, so nothing pack-gated shows while core stays visible.
 */
export const useCapabilitiesStore = defineStore('capabilities', () => {
  const enabledIds = ref<Set<string>>(new Set())
  const loaded = ref(false)
  let inflight: Promise<void> | null = null

  function isEnabled(id: string): boolean {
    return enabledIds.value.has(id)
  }

  async function load(): Promise<void> {
    try {
      const json = await authFetch(`${runtimeConfig.apiBase}/capabilities`)
      const data = (json.data ?? json) as Record<string, unknown>
      const rows = Array.isArray(data.capabilities) ? (data.capabilities as CapabilityRow[]) : []
      enabledIds.value = new Set(rows.map((r) => r.id))
    } catch {
      enabledIds.value = new Set()
    } finally {
      loaded.value = true
    }
  }

  function ensureLoaded(): Promise<void> {
    if (loaded.value) return Promise.resolve()
    inflight ??= load().finally(() => {
      inflight = null
    })
    return inflight
  }

  return { enabledIds, loaded, isEnabled, load, ensureLoaded }
})
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npx vitest run src/__tests__/capabilities.spec.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Lint, format, type-check**

Run: `cd admin && npx oxlint src/stores/capabilities.ts src/__tests__/capabilities.spec.ts && npx oxfmt --check src/stores/capabilities.ts src/__tests__/capabilities.spec.ts && npm run type-check`
Expected: all clean; type-check exits 0 (read the final line directly — do NOT pipe through tail).

- [ ] **Step 6: Stage (commit only when authorized — see Global Constraints)**

```bash
git add admin/src/stores/capabilities.ts admin/src/__tests__/capabilities.spec.ts
# When authorized:
git commit -m "Add capabilities store (loads enabled capability ids post-auth)"
```

---

### Task 2: `registerAdminModule` registry

**Files:**
- Create: `admin/src/registry/adminModules.ts`
- Test: `admin/src/__tests__/adminModules.spec.ts`

**Interfaces:**
- Consumes: `NavigationMenuItem` type from `@nuxt/ui`.
- Produces:
  - `interface AdminModuleNav { main?: NavigationMenuItem[]; utilities?: NavigationMenuItem[] }`
  - `interface AdminModule { id: string; requires?: string[]; nav?: AdminModuleNav }`
  - `registerAdminModule(module: AdminModule): void` — append to the module list (idempotent by `id`: a re-register with the same id replaces the prior, so HMR re-runs don't duplicate).
  - `registeredModules(): AdminModule[]` — the raw list (for tests).
  - `resetAdminModules(): void` — clear the list (for tests).
  - `visibleNav(isEnabled: (id: string) => boolean): [NavigationMenuItem[], NavigationMenuItem[]]` — returns the two-group structure (`[main, utilities]`). A module is included iff **every** id in its `requires` returns true from `isEnabled` (empty/absent `requires` ⇒ always included). Modules are concatenated in registration order; each contributes its `nav.main` to group 0 and `nav.utilities` to group 1.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, beforeEach } from 'vitest'
import {
  registerAdminModule,
  visibleNav,
  resetAdminModules,
  registeredModules,
} from '@/registry/adminModules'

describe('admin module registry', () => {
  beforeEach(() => resetAdminModules())

  it('always includes a module with no requires (core)', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Home', to: '/' }] } })
    const [main, utilities] = visibleNav(() => false)
    expect(main).toEqual([{ label: 'Home', to: '/' }])
    expect(utilities).toEqual([])
  })

  it('includes a gated module only when ALL its requires are enabled', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Home', to: '/' }] } })
    registerAdminModule({
      id: 'forms',
      requires: ['lemma.forms'],
      nav: { main: [{ label: 'Forms', to: '/forms' }] },
    })
    const enabled = new Set(['lemma.forms'])
    const [mainOn] = visibleNav((id) => enabled.has(id))
    expect(mainOn.map((i) => i.label)).toEqual(['Home', 'Forms'])
    const [mainOff] = visibleNav(() => false)
    expect(mainOff.map((i) => i.label)).toEqual(['Home'])
  })

  it('requires ALL ids (not any)', () => {
    registerAdminModule({
      id: 'multi',
      requires: ['a', 'b'],
      nav: { main: [{ label: 'Multi', to: '/multi' }] },
    })
    expect(visibleNav((id) => id === 'a')[0]).toEqual([]) // only one of two enabled
    expect(visibleNav(() => true)[0].map((i) => i.label)).toEqual(['Multi'])
  })

  it('routes utilities contributions into group 1', () => {
    registerAdminModule({ id: 'core', nav: { utilities: [{ label: 'Health', to: '/utilities/health' }] } })
    const [main, utilities] = visibleNav(() => true)
    expect(main).toEqual([])
    expect(utilities.map((i) => i.label)).toEqual(['Health'])
  })

  it('re-registering the same id replaces (no duplicate from HMR)', () => {
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'Old', to: '/' }] } })
    registerAdminModule({ id: 'core', nav: { main: [{ label: 'New', to: '/' }] } })
    expect(registeredModules()).toHaveLength(1)
    expect(visibleNav(() => true)[0].map((i) => i.label)).toEqual(['New'])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/__tests__/adminModules.spec.ts`
Expected: FAIL — cannot resolve `@/registry/adminModules`.

- [ ] **Step 3: Implement the registry**

`admin/src/registry/adminModules.ts`:
```ts
import type { NavigationMenuItem } from '@nuxt/ui'

export interface AdminModuleNav {
  main?: NavigationMenuItem[]
  utilities?: NavigationMenuItem[]
}

export interface AdminModule {
  id: string
  /** Capability ids that must ALL be enabled for this module to be visible. Empty/absent = always-on. */
  requires?: string[]
  nav?: AdminModuleNav
}

const modules: AdminModule[] = []

export function registerAdminModule(module: AdminModule): void {
  const i = modules.findIndex((m) => m.id === module.id)
  if (i >= 0) modules[i] = module
  else modules.push(module)
}

export function registeredModules(): AdminModule[] {
  return modules
}

export function resetAdminModules(): void {
  modules.length = 0
}

function moduleEnabled(module: AdminModule, isEnabled: (id: string) => boolean): boolean {
  return (module.requires ?? []).every((id) => isEnabled(id))
}

/** Assemble the two-group sidebar ([main, utilities]) from the enabled modules, in registration order. */
export function visibleNav(
  isEnabled: (id: string) => boolean,
): [NavigationMenuItem[], NavigationMenuItem[]] {
  const main: NavigationMenuItem[] = []
  const utilities: NavigationMenuItem[] = []
  for (const m of modules) {
    if (!moduleEnabled(m, isEnabled)) continue
    if (m.nav?.main) main.push(...m.nav.main)
    if (m.nav?.utilities) utilities.push(...m.nav.utilities)
  }
  return [main, utilities]
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npx vitest run src/__tests__/adminModules.spec.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Lint, format, type-check**

Run: `cd admin && npx oxlint src/registry/adminModules.ts src/__tests__/adminModules.spec.ts && npx oxfmt --check src/registry/adminModules.ts src/__tests__/adminModules.spec.ts && npm run type-check`
Expected: clean; type-check exits 0 (run directly, no pipe).

- [ ] **Step 6: Stage (commit only when authorized — see Global Constraints)**

```bash
git add admin/src/registry/adminModules.ts admin/src/__tests__/adminModules.spec.ts
# When authorized:
git commit -m "Add registerAdminModule registry with capability-gated visibleNav"
```

---

### Task 3: Make the sidebar registry-driven + capability-filtered

**Files:**
- Create: `admin/src/registry/coreModule.ts` (registers the current core nav as an always-on module)
- Modify: `admin/src/navigation/sidebar.ts` (keep `open`; replace the static `items` export with a registry-backed `useVisibleNav()` source)
- Modify: `admin/src/layouts/default.vue` (read the registry's visible nav, gated by the capabilities store; trigger `ensureLoaded`; keep the live content-types injection)
- Test: `admin/src/__tests__/coreModule.spec.ts`

**Interfaces:**
- Consumes: `registerAdminModule`, `visibleNav` (Task 2); `useCapabilitiesStore` (Task 1); the existing `NavigationMenuItem` nav data currently in `navigation/sidebar.ts`; `useContentTypes()` from `@/queries/contentTypes` (unchanged; still injected by the layout).
- Produces: `admin/src/registry/coreModule.ts` exporting `registerCoreModule(): void` which calls `registerAdminModule({ id: 'core', nav: { main: [...current items[0]...] } })` using the exact current nav array. The layout calls `registerCoreModule()` once, then renders `visibleNav((id) => caps.isEnabled(id))`, then injects content-types into the Content section (as today).

**Current-state fact (verified):** `navigation/sidebar.ts` exports `items = [[ … one group … ]]` — a SINGLE group (`items[0]`). The "Utilities" section is a node **inside** `items[0]` (the last accordion), and **`items[1]` does not exist** (the layout's second `<UNavigationMenu :items="items[1]">` currently binds `undefined` and renders nothing). The sidebar docblock *claims* a two-group split, but the data has never had a group 1 — that is a pre-existing inconsistency. Phase C **preserves current behavior**: core nav (incl. the Utilities node) goes entirely into `nav.main`; the second group stays empty. Do **not** split Utilities into group 1 here — that is a separate nav change, out of scope for the capability registry.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { resetAdminModules, visibleNav } from '@/registry/adminModules'

vi.mock('@/api/authFetch', () => ({ authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { registerCoreModule } from '@/registry/coreModule'

describe('core module registration', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    resetAdminModules()
  })

  it('registers the core nav as an always-on module (visible with no capabilities)', () => {
    registerCoreModule()
    const [main, utilities] = visibleNav(() => false) // no caps enabled
    // Core is always-on: its top-level sections are present even with zero enabled capabilities.
    const labels = main.map((i) => i.label)
    expect(labels).toContain('Home')
    expect(labels).toContain('Content')
    expect(labels).toContain('Media')
    // Utilities is a node INSIDE the single (main) group today — assert it stays there.
    expect(labels).toContain('Utilities')
    // The second group is empty (no items[1] exists today) — preserves the empty bottom menu.
    expect(utilities).toEqual([])
  })

  it('is idempotent (re-registering does not duplicate the core module)', () => {
    registerCoreModule()
    registerCoreModule()
    const [main] = visibleNav(() => true)
    expect(main.filter((i) => i.label === 'Home')).toHaveLength(1)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/__tests__/coreModule.spec.ts`
Expected: FAIL — cannot resolve `@/registry/coreModule`.

- [ ] **Step 3: Extract the current nav into the core module**

Create `admin/src/registry/coreModule.ts`. Move the current single nav group — `items[0]` — verbatim from `navigation/sidebar.ts` into this file as `main`, and register it as one always-on module. There is no `items[1]`, so the module contributes only `main`:
```ts
import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// The first-party (core) admin nav, registered as an always-on module (no `requires`).
// Pack modules register their own nav with a `requires` capability id elsewhere.
const main: NavigationMenuItem[] = [
  // ── paste the CURRENT items[0] array here, VERBATIM: the eight top-level sections
  //    Home, Content (with its empty `children: []` the layout fills from useContentTypes()),
  //    Media, Extensions, Users & Access, Developers, Settings, Utilities — including every
  //    icon, every nested `children`, `defaultOpen: true` on Content, and the two
  //    `target: '_blank'` external Developers links. ──
]

export function registerCoreModule(): void {
  registerAdminModule({ id: 'core', nav: { main } })
}
```
> Copy the array EXACTLY as it exists in `navigation/sidebar.ts:17-149` today (the contents of `items[0]`). Do not restructure it — this task only relocates it behind the registry. The "Utilities" node stays where it is, inside `main`. The registry's `visibleNav` will return `[main, []]` (empty second group), reproducing today's empty bottom menu.

- [ ] **Step 4: Slim `navigation/sidebar.ts` to the registry source**

In `admin/src/navigation/sidebar.ts`, keep the `open` ref. Replace the static `items` export with a composable that returns the capability-filtered two-group nav:
```ts
import { computed, type ComputedRef } from 'vue'
import { ref } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { visibleNav } from '@/registry/adminModules'
import { useCapabilitiesStore } from '@/stores/capabilities'

export const open = ref(false)

/** The two-group sidebar nav ([main, utilities]), filtered by enabled capabilities (reactive). */
export function useVisibleNav(): ComputedRef<[NavigationMenuItem[], NavigationMenuItem[]]> {
  const caps = useCapabilitiesStore()
  return computed(() => visibleNav((id) => caps.isEnabled(id)))
}
```
> Any other file that imported `items` from this module must switch to `useVisibleNav()` — grep `from '@/navigation/sidebar'` and `from '../navigation/sidebar'` and update each importer (the layout is updated in Step 5; fix any others the same way).

- [ ] **Step 5: Update the layout to use the registry + capabilities, preserving content-types injection**

In `admin/src/layouts/default.vue` `<script setup>`: register the core module once, trigger the capabilities load, read the filtered nav, and inject content-types into the Content section exactly as today.
```ts
import { computed } from 'vue'
import { open, useVisibleNav } from '../navigation/sidebar'
import { registerCoreModule } from '@/registry/coreModule'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useContentTypes } from '@/queries/contentTypes'

registerCoreModule()
useCapabilitiesStore().ensureLoaded() // post-auth: this layout only renders for authenticated users

const nav = useVisibleNav()
const { data: contentTypes } = useContentTypes()

// items[0] = main nav; inject live content types into the Content section's children (unchanged behavior).
const mainItems = computed(() =>
  nav.value[0].map((item) =>
    item.label === 'Content'
      ? {
          ...item,
          children: (contentTypes.value ?? []).map((ct) => ({
            label: ct.name ?? ct.slug ?? 'Untitled',
            icon: 'i-lucide-file-text',
            to: `/content/${ct.slug}`,
          })),
        }
      : item,
  ),
)
const utilityItems = computed(() => nav.value[1])
```
In the `<template>`, change the second `<UNavigationMenu>`'s `:items="items[1]"` to `:items="utilityItems"` and keep the first bound to `:items="mainItems"`. Remove the old `import { open, items } from '../navigation/sidebar'` (now `open, useVisibleNav`). `utilityItems` is `[]` today (no `items[1]` ever existed), so the bottom menu renders empty exactly as it does now — binding `[]` instead of `undefined` is equivalent for `UNavigationMenu`.

- [ ] **Step 6: Run the test + the full SPA suite (no nav regression)**

Run: `cd admin && npx vitest run src/__tests__/coreModule.spec.ts`
Expected: PASS (2 tests).
Run: `cd admin && npm run test`
Expected: PASS (existing suite green — the nav refactor changed structure, not behavior).

- [ ] **Step 7: Type-check + lint + format**

Run: `cd admin && npm run type-check && npx oxlint src/registry/coreModule.ts src/navigation/sidebar.ts src/layouts/default.vue src/__tests__/coreModule.spec.ts && npx oxfmt --check src/registry/coreModule.ts src/navigation/sidebar.ts src/layouts/default.vue src/__tests__/coreModule.spec.ts`
Expected: type-check exits 0 (run directly, no pipe); lint + format clean.

- [ ] **Step 8: Stage (commit only when authorized — see Global Constraints)**

```bash
git add admin/src/registry/coreModule.ts admin/src/navigation/sidebar.ts admin/src/layouts/default.vue admin/src/__tests__/coreModule.spec.ts
# When authorized:
git commit -m "Make sidebar registry-driven + capability-filtered (core as always-on module)"
```

---

### Task 4: Route-level capability gating

**Files:**
- Modify: `admin/src/router/guard.ts` (add `requiresCapability` meta + the gate)
- Test: `admin/src/__tests__/guard.spec.ts` (extend the existing test)

**Interfaces:**
- Consumes: `useCapabilitiesStore` (Task 1); the existing `installAndAuthGuard(to)` signature in `admin/src/router/guard.ts` and its `RouteMeta` augmentation.
- Produces: `RouteMeta.requiresCapability?: string`. The guard, after the auth check, blocks a route whose `meta.requiresCapability` is set and **not** enabled: it `ensureLoaded()`s the capabilities, then redirects to `/` if the capability is disabled (a disabled pack's page is unreachable by direct URL). When the capability is enabled (or the route has no `requiresCapability`), navigation proceeds. **The guard is NOT declared `async`** — the install/auth branches keep returning **synchronous** values (so the existing sync tests are untouched), and **only the capability branch** returns a `Promise` (via `.then`). The return type widens to `true | RouteLocationRaw | Promise<true | RouteLocationRaw>` (vue-router accepts a guard returning either a value or a Promise).

- [ ] **Step 1: Write the failing test (extend the existing guard spec)**

Add to `admin/src/__tests__/guard.spec.ts` — add a capabilities double to the hoisted block and mock it, then new cases. **Leave the existing six tests exactly as they are** — they call the guard synchronously (`expect(installAndAuthGuard(...)).toEqual(...)`) and still pass because those branches still return synchronous values.
```ts
// add to the existing vi.hoisted({...}) object:
//   caps: { ensureLoaded: vi.fn().mockResolvedValue(undefined), isEnabled: (_: string) => false }
// add the mock alongside the others:
//   vi.mock('@/stores/capabilities', () => ({ useCapabilitiesStore: () => caps }))

// The capability branch returns a Promise, so these two use .resolves:
it('redirects a capability-gated route to / when the capability is disabled', async () => {
  cfg.installed = true
  session.isAuthenticated = true
  caps.isEnabled = () => false
  await expect(
    installAndAuthGuard(to('/forms', { requiresAuth: true, requiresCapability: 'lemma.forms' })),
  ).resolves.toEqual({ path: '/' })
})

it('allows a capability-gated route when the capability is enabled', async () => {
  cfg.installed = true
  session.isAuthenticated = true
  caps.isEnabled = (id: string) => id === 'lemma.forms'
  await expect(
    installAndAuthGuard(to('/forms', { requiresAuth: true, requiresCapability: 'lemma.forms' })),
  ).resolves.toBe(true)
})

// A route with NO requiresCapability stays on the synchronous path — assert the value directly:
it('allows a route with no requiresCapability (synchronous, unchanged)', () => {
  cfg.installed = true
  session.isAuthenticated = true
  expect(installAndAuthGuard(to('/', { requiresAuth: true }))).toBe(true)
})
```
> Do NOT convert the existing sync expectations to `.resolves` — the guard stays sync except on the capability branch, so `.resolves` on a plain `true`/object would throw ("not a thenable").

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/__tests__/guard.spec.ts`
Expected: FAIL — the new gated route is not redirected/allowed (no capability branch yet).

- [ ] **Step 3: Add the capability gate to the guard**

In `admin/src/router/guard.ts`: extend the `RouteMeta` augmentation and the guard. **Do NOT make the function `async`** — keep the install + auth branches returning synchronous values (first), and return a `Promise` only from the capability branch (last), so the existing sync tests are untouched.
```ts
declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    /** Capability id (e.g. 'lemma.forms') that must be enabled for this route to be reachable. */
    requiresCapability?: string
  }
}

export function installAndAuthGuard(
  to: RouteLocationNormalized,
): true | RouteLocationRaw | Promise<true | RouteLocationRaw> {
  // (1) Install gate. (synchronous)
  if (!runtimeConfig.installed && to.path !== '/setup') return { path: '/setup' }
  if (runtimeConfig.installed && to.path === '/setup') return { path: '/login' }

  // (2) Auth gate. (synchronous)
  const session = useSessionStore()
  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && session.isAuthenticated) return { path: '/' }

  // (3) Capability gate: a disabled pack's route is unreachable by direct URL.
  //     Only THIS branch is async — it returns a Promise; the branches above stay synchronous.
  const cap = to.meta.requiresCapability
  if (cap !== undefined && session.isAuthenticated) {
    const caps = useCapabilitiesStore()
    return caps.ensureLoaded().then((): true | RouteLocationRaw => (caps.isEnabled(cap) ? true : { path: '/' }))
  }

  return true
}
```
Add the import: `import { useCapabilitiesStore } from '@/stores/capabilities'`.
> `router.beforeEach(installAndAuthGuard)` in `router/index.ts` accepts a guard returning a value OR a Promise — no change needed there.

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && npx vitest run src/__tests__/guard.spec.ts`
Expected: PASS (existing cases + 3 new).

- [ ] **Step 5: Type-check + lint + format + full suite**

Run: `cd admin && npm run type-check && npx oxlint src/router/guard.ts src/__tests__/guard.spec.ts && npx oxfmt --check src/router/guard.ts src/__tests__/guard.spec.ts && npm run test`
Expected: type-check exits 0 (run directly); lint + format clean; full suite green.

- [ ] **Step 6: Stage (commit only when authorized — see Global Constraints)**

```bash
git add admin/src/router/guard.ts admin/src/__tests__/guard.spec.ts
# When authorized:
git commit -m "Gate routes by capability via meta.requiresCapability"
```

---

## Phase C — Definition of Done

- A capabilities store loads the enabled capability ids from `GET /v1/admin/capabilities` (post-auth, fail-closed).
- A `registerAdminModule({ id, requires, nav })` registry assembles the two-group sidebar, including a module iff all its `requires` are enabled (empty = always-on).
- The sidebar is registry-driven: core nav is an always-on `core` module; the layout renders `visibleNav(caps.isEnabled)` and still injects live content types into the Content section. No hard-coded capability conditionals.
- Routes tagged `meta.requiresCapability` are unreachable by direct URL when the capability is disabled.
- With no pack modules registered, the nav renders exactly as before (no behavior change).
- `npm run test`, `npm run type-check`, `oxlint`, `oxfmt --check` all green.

**Deferred (not built here):** `registerAdminModule`'s `routes`/`settingsPanels`/`fieldWidgets` fields and runtime-loaded third-party admin bundles (the future runtime model); backend admin-contribution descriptors (spec §4.6); the first real pack module registration (arrives with the reference pack, Phase D — which will call `registerAdminModule` with a real `requires` and add a page under `src/pages/`).

---

## Self-Review

**Spec coverage (Phase C = spec §6 + §9.C, adapted to file-based routing):**
- §6 registry-based composition (`registerAdminModule`, V1 static) → Task 2 ✅
- §6 "mount only modules whose required capability the server reports as enabled" → Task 1 (caps store) + Task 2 (`visibleNav` gating) + Task 3 (sidebar) ✅
- §6 "no hard-coded sidebar conditionals" → Task 3 (registry-driven nav) ✅
- §9.C "refactor sidebar/router; core screens as always-on modules" → Task 3 (core module) + Task 4 (route guard) ✅
- §6 server-authoritative (match static modules to server-reported enabled ids) → caps store reads Phase B's `/capabilities`; modules gated by id ✅
- §6 `routes/settingsPanels/fieldWidgets` + runtime third-party + §4.6 backend descriptors → explicitly deferred (scope decisions) ✅

**Placeholder scan:** No TBD/TODO. The two "paste the current array verbatim" / "grep importers" notes in Task 3 are not placeholders — they instruct an exact, mechanical relocation of existing code (the current `items[0]` array — the single nav group) and a concrete grep to update importers; the surrounding code shows the before/after shape.

**Type consistency:**
- `useCapabilitiesStore()` exposes `isEnabled`/`ensureLoaded`/`loaded`/`enabledIds` in Task 1 and is consumed with those exact names in Tasks 3 (`caps.isEnabled`, `ensureLoaded`) and 4 (`caps.ensureLoaded`, `caps.isEnabled`).
- `registerAdminModule`/`visibleNav`/`resetAdminModules`/`registeredModules` defined in Task 2 are used with the same signatures in Tasks 2's test and Task 3.
- `visibleNav` returns `[main, utilities]` (a 2-tuple of `NavigationMenuItem[]`) in Task 2 and is destructured as such in Tasks 2 and 3.
- `AdminModule.requires` is `string[]` (ALL must be enabled) consistently in Task 2's logic, tests, and the Global Constraints.
- `RouteMeta.requiresCapability?: string` defined in Task 4 matches its test usage.
- No symbol referenced that isn't created in a prior task.
