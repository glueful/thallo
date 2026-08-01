# Finish Localization UI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface the per-locale state the Lemma backend already returns and round out the editor's locale workflow — status indicators, locale-aware versions, copy/overwrite, translation progress, cross-locale routes, bulk operations, and a disable-locale guard.

**Architecture:** The localization backend is done. `EntryRepository::localeSummary()` already returns per-locale `has_draft` / `is_published` / `route_slug` / `draft_updated_at` / `published_at` / `scheduled`, and the controller returns it verbatim (`EntryController::locales()` → `Response::success(['locales' => …])`). The OpenAPI DTO (`EntryLocaleData`) is only used for schema generation and currently omits `scheduled`. This plan is mostly **frontend** (Vue 3 + Nuxt UI + Pinia Colada in `admin/`), with two small backend touches: documenting `scheduled` on the schema DTO, and adding a per-locale content-usage endpoint for the disable guard.

**Tech Stack:** PHP 8.3 (Glueful framework), Vue 3 + Vite + Nuxt UI v4, Pinia Colada queries, openapi-typescript client (`admin/src/api/client`), vitest, oxlint/oxfmt.

**Conventions to follow (read before starting):**
- Glueful is **not Laravel** — see `/Users/michaeltawiahsowah/Sites/glueful/framework/CLAUDE.md`. Use `$this->db->table(...)` query builder, `Response::success(...)`.
- Pinia Colada query files live in `admin/src/queries/`; each exports bare `fetch*`/mutation fns + `use*` hooks. The typed client is `client.GET('/path', { params })` (base `/v1/admin`); cast untyped response bodies as the existing files do.
- Frontend hand-pinned contracts (e.g. `EntryLocaleSummary` in `admin/src/queries/entries.ts`) are the source of truth for shapes the spec types `unknown`.
- **Verification gate per frontend task:** `pnpm run type-check` (= `vue-tsc --build`; capture the exit code, never pipe through `tail`), then `pnpm run lint` (oxlint) and `pnpm run format` (oxfmt). For backend tasks: `vendor/bin/phpunit --filter <Name>` and `vendor/bin/phpcs <file>`.
- **Do not commit until instructed.** When authorized, commit on `dev` directly (no feature branch); the per-task commit steps below define the commit boundaries to use *then*. No Claude/Anthropic attribution in commit messages. Do **not** stage `CLAUDE.md`.
- Update `CHANGELOG.md` `[Unreleased]` as part of the final task.

**Commands referenced below:**
- Regenerate OpenAPI spec: `composer run docs:openapi` (writes `docs/openapi.json`).
- Regenerate FE API types from the spec: `cd admin && pnpm run gen:api`.
- Run one vitest file: `cd admin && pnpm exec vitest run <path>`.

---

## Task 1: Backend — document `scheduled` on the locale-summary schema

The runtime response already includes `scheduled`; only the OpenAPI DTO omits it. Add nested DTOs so the generated spec documents the full shape, and lock the data contract with a repository test.

**Files:**
- Create: `app/Content/Http/DTOs/Responses/Entries/EntryLocaleScheduleData.php`
- Create: `app/Content/Http/DTOs/Responses/Entries/EntryLocaleScheduleFailureData.php`
- Modify: `app/Content/Http/DTOs/Responses/Entries/EntryLocaleData.php`
- Test: `tests/Integration/Content/EntryLocaleSummaryTest.php`
- Regenerate: `docs/openapi.json`

- [ ] **Step 1: Write the failing test** for the data contract the UI depends on.

Create `tests/Integration/Content/EntryLocaleSummaryTest.php`. Mirror the seeding style of `tests/Integration/ImportExport/WordpressContentImporterTest.php` (uses `App\Tests\Support\LemmaTestCase`, `$this->connection()`, `$this->container()`). Seed a content type, an entry, a draft row, and a **pending publish** schedule row, then assert `localeSummary` surfaces it:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\EntryRepository;
use App\Tests\Support\LemmaTestCase;

final class EntryLocaleSummaryTest extends LemmaTestCase
{
    public function testLocaleSummaryIncludesScheduledBlock(): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'entloc000001',
            'content_type_uuid' => 'typeloc00001',
            'status' => 'active',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        // entry_drafts keys on an auto-increment `id` — there is NO `uuid` column.
        $db->table('entry_drafts')->insert([
            'entry_uuid' => 'entloc000001',
            'locale' => 'en',
            'fields' => '{}',
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => '2026-06-27 01:00:00',
        ]);
        $db->table('entry_schedules')->insert([
            'uuid' => 'schedloc0001',
            'entry_uuid' => 'entloc000001',
            'locale' => 'en',
            'action' => 'publish',
            'status' => 'pending',
            'run_at' => '2026-07-01 09:00:00',
            'created_at' => '2026-06-27 01:00:00',
            'updated_at' => '2026-06-27 01:00:00',
        ]);

        $repo = $this->container()->get(EntryRepository::class);
        $summary = $repo->localeSummary('entloc000001');

        self::assertCount(1, $summary);
        self::assertSame('en', $summary[0]['locale']);
        self::assertTrue($summary[0]['has_draft']);
        self::assertArrayHasKey('scheduled', $summary[0]);
        self::assertNotNull($summary[0]['scheduled']['publish']);
        self::assertNull($summary[0]['scheduled']['unpublish']);
        self::assertNull($summary[0]['scheduled']['last_failure']);
    }
}
```

> Column sets verified against `database/migrations/` as of this plan: `entries` (uuid, content_type_uuid, status, created_at, updated_at), `entry_drafts` (entry_uuid, locale, fields, schema_version, lock_version, updated_at — **no uuid**), `entry_schedules` (uuid, entry_uuid, locale, action, run_at, status, created_at, updated_at). Reconcile if a migration changes; do not invent columns.

- [ ] **Step 2: Run the test to verify it passes** (the data already exists; this is a characterization test that locks the contract).

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter EntryLocaleSummaryTest`
Expected: PASS. If it fails on a missing column, fix the seed (not the assertion).

- [ ] **Step 3: Create the nested failure DTO.**

`app/Content/Http/DTOs/Responses/Entries/EntryLocaleScheduleFailureData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryLocaleScheduleFailureData implements ResponseData
{
    public function __construct(
        public readonly string $action,
        public readonly ?string $run_at,
        public readonly string $reason,
    ) {
    }
}
```

- [ ] **Step 4: Create the schedule-summary DTO.**

`app/Content/Http/DTOs/Responses/Entries/EntryLocaleScheduleData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryLocaleScheduleData implements ResponseData
{
    public function __construct(
        public readonly ?string $publish,
        public readonly ?string $unpublish,
        public readonly ?EntryLocaleScheduleFailureData $last_failure,
    ) {
    }
}
```

- [ ] **Step 5: Add `scheduled` to `EntryLocaleData`.**

Edit `app/Content/Http/DTOs/Responses/Entries/EntryLocaleData.php` — add the property after `$published_at`:

```php
        public readonly ?\DateTimeInterface $published_at,
        public readonly ?EntryLocaleScheduleData $scheduled,
    ) {
    }
```

- [ ] **Step 6: Regenerate the OpenAPI spec and verify `scheduled` is documented.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && composer run docs:openapi && grep -c '"scheduled"' docs/openapi.json`
Expected: the command succeeds and grep prints a non-zero count.

- [ ] **Step 7: phpcs the new/edited PHP files.**

Run: `vendor/bin/phpcs app/Content/Http/DTOs/Responses/Entries/ tests/Integration/Content/EntryLocaleSummaryTest.php`
Expected: no errors.

- [ ] **Step 8: Commit.**

```bash
git add app/Content/Http/DTOs/Responses/Entries/ tests/Integration/Content/EntryLocaleSummaryTest.php docs/openapi.json
git commit -m "Document per-locale scheduled state on the entry-locales schema"
```

---

## Task 2: Frontend — locale switcher with per-locale status

Replace the plain locale `USelect` in the entry editor with a status-aware switcher: each locale shows whether it is published, draft-only, or has a pending schedule, and the active locale gets a status badge. Extract the status logic to a pure, testable helper.

**Files:**
- Modify: `admin/src/queries/entries.ts` (extend `EntryLocaleSummary`)
- Create: `admin/src/pages/content/[type]/[uuid]/components/localeStatus.ts`
- Create: `admin/src/pages/content/[type]/[uuid]/components/localeStatus.spec.ts`
- Create: `admin/src/pages/content/[type]/[uuid]/components/LocaleSwitcher.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`

- [ ] **Step 1: Extend the `EntryLocaleSummary` contract** in `admin/src/queries/entries.ts`. Replace the interface (lines ~110-118) with:

```ts
/** A pending/failed schedule summary for one locale (mirrors EntryRepository::localeSummary). */
export interface EntryLocaleSchedule {
  publish: string | null
  unpublish: string | null
  last_failure: { action: string; run_at: string | null; reason: string } | null
}

/** One locale an entry exists in (EntryRepository::localeSummary). */
export interface EntryLocaleSummary {
  locale: string
  has_draft: boolean
  is_published: boolean
  route_slug: string | null
  draft_updated_at: string | null
  published_at: string | null
  scheduled: EntryLocaleSchedule | null
}
```

- [ ] **Step 2: Write the failing test for the status helper.**

`admin/src/pages/content/[type]/[uuid]/components/localeStatus.spec.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { localeStatus } from './localeStatus'
import type { EntryLocaleSummary } from '@/queries/entries'

function summary(over: Partial<EntryLocaleSummary>): EntryLocaleSummary {
  return {
    locale: 'en',
    has_draft: false,
    is_published: false,
    route_slug: null,
    draft_updated_at: null,
    published_at: null,
    scheduled: null,
    ...over,
  }
}

describe('localeStatus', () => {
  it('reports published', () => {
    expect(localeStatus(summary({ is_published: true })).key).toBe('published')
  })
  it('reports scheduled when a publish schedule is pending', () => {
    const s = summary({ has_draft: true, scheduled: { publish: '2026-07-01T09:00:00Z', unpublish: null, last_failure: null } })
    expect(localeStatus(s).key).toBe('scheduled')
  })
  it('reports draft when a draft exists but is unpublished', () => {
    expect(localeStatus(summary({ has_draft: true })).key).toBe('draft')
  })
  it('reports none when nothing exists', () => {
    expect(localeStatus(summary({})).key).toBe('none')
  })
  it('prefers published over a pending schedule', () => {
    const s = summary({ is_published: true, scheduled: { publish: null, unpublish: '2026-07-01T09:00:00Z', last_failure: null } })
    expect(localeStatus(s).key).toBe('published')
  })
})
```

- [ ] **Step 3: Run the test to verify it fails.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm exec vitest run "src/pages/content/[type]/[uuid]/components/localeStatus.spec.ts"`
Expected: FAIL with "Cannot find module './localeStatus'".

- [ ] **Step 4: Implement the helper.**

`admin/src/pages/content/[type]/[uuid]/components/localeStatus.ts`:

```ts
import type { EntryLocaleSummary } from '@/queries/entries'

export type LocaleStatusKey = 'published' | 'scheduled' | 'draft' | 'none'

export interface LocaleStatus {
  key: LocaleStatusKey
  label: string
  icon: string
  /** Nuxt UI semantic color for badges/icons. */
  color: 'success' | 'warning' | 'neutral'
}

const STATUSES: Record<LocaleStatusKey, Omit<LocaleStatus, 'key'>> = {
  published: { label: 'Published', icon: 'i-lucide-check-circle', color: 'success' },
  scheduled: { label: 'Scheduled', icon: 'i-lucide-clock', color: 'warning' },
  draft: { label: 'Draft', icon: 'i-lucide-pencil', color: 'neutral' },
  none: { label: 'Not started', icon: 'i-lucide-circle-dashed', color: 'neutral' },
}

/** Derive a single editorial status for a locale. Published wins; then a pending schedule; then a bare draft. */
export function localeStatus(summary: EntryLocaleSummary): LocaleStatus {
  let key: LocaleStatusKey = 'none'
  if (summary.is_published) key = 'published'
  else if (summary.scheduled && (summary.scheduled.publish || summary.scheduled.unpublish)) key = 'scheduled'
  else if (summary.has_draft) key = 'draft'
  return { key, ...STATUSES[key] }
}
```

- [ ] **Step 5: Run the test to verify it passes.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm exec vitest run "src/pages/content/[type]/[uuid]/components/localeStatus.spec.ts"`
Expected: PASS (5 tests).

- [ ] **Step 6: Build the `LocaleSwitcher` component.**

`admin/src/pages/content/[type]/[uuid]/components/LocaleSwitcher.vue`. It owns the switch dropdown, the active-locale status badge, and the "add locale" menu. Props in, events out — no queries inside.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { EntryLocaleSummary } from '@/queries/entries'
import type { Locale } from '@/queries/locales'
import { localeStatus } from './localeStatus'

const props = defineProps<{
  modelValue: string
  summaries: EntryLocaleSummary[]
  enabled: Locale[]
  addable: Locale[]
}>()
const emit = defineEmits<{ 'update:modelValue': [code: string]; create: [code: string] }>()

function label(code: string): string {
  const m = props.enabled.find((l) => l.code === code)
  return m ? `${m.name} (${code})` : code
}
function statusFor(code: string) {
  const s = props.summaries.find((x) => x.locale === code)
  return s ? localeStatus(s) : null
}

// USelectMenu items carry the status so the trigger + list can render an icon per locale.
const items = computed(() =>
  props.summaries.map((s) => {
    const st = localeStatus(s)
    return { label: label(s.locale), value: s.locale, icon: st.icon, status: st }
  }),
)
const current = computed(() => statusFor(props.modelValue))

const addItems = computed(() =>
  props.addable.map((l) => ({ label: `${l.name} (${l.code})`, onSelect: () => emit('create', l.code) })),
)

const selected = computed({
  get: () => props.modelValue,
  set: (v: string) => emit('update:modelValue', v),
})
</script>

<template>
  <div class="flex items-center gap-2">
    <USelectMenu
      v-model="selected"
      :items="items"
      value-key="value"
      :icon="current?.icon ?? 'i-lucide-languages'"
      :search-input="false"
      size="sm"
      class="w-52"
    >
      <template #item="{ item }">
        <span class="flex w-full items-center justify-between gap-2">
          <span class="truncate">{{ item.label }}</span>
          <UIcon :name="item.status.icon" :class="`text-${item.status.color}`" class="size-4 shrink-0" />
        </span>
      </template>
    </USelectMenu>

    <UBadge v-if="current" :color="current.color" variant="subtle" size="sm" :icon="current.icon">
      {{ current.label }}
    </UBadge>

    <UDropdownMenu v-if="addItems.length" :items="addItems" :content="{ align: 'end' }">
      <UButton icon="i-lucide-plus" color="neutral" variant="ghost" size="sm" aria-label="Add a locale version" />
    </UDropdownMenu>
  </div>
</template>
```

> Verify against the Nuxt UI MCP (`get_component_metadata` for `USelectMenu`) that `value-key`, the `#item` slot, and `search-input` are the correct prop/slot names for the installed v4; adjust if the API differs. The `text-${color}` class must resolve to a semantic color — if `text-success`/`text-warning` aren't generated, map to explicit classes in `localeStatus.ts` instead.

- [ ] **Step 7: Use the switcher in the editor.** In `admin/src/pages/content/[type]/[uuid]/index.vue`:

Add the import after the `PublishPanel` import (line ~13):

```ts
import LocaleSwitcher from './components/LocaleSwitcher.vue'
```

Replace the `<template v-if="multiLocale">` block in the navbar `#right` slot (lines ~168-194 — the `USelect` + add `UDropdownMenu`) with:

```vue
          <LocaleSwitcher
            v-if="multiLocale"
            v-model="locale"
            :summaries="entryLocales ?? []"
            :enabled="enabledLocales"
            :addable="addableLocales"
            @create="openCreate"
          />
```

- [ ] **Step 8: Type-check, lint, format.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"`
Expected: `EXIT=0`. Then `pnpm run lint && pnpm run format`.

- [ ] **Step 9: Commit.**

```bash
git add "admin/src/queries/entries.ts" "admin/src/pages/content/[type]/[uuid]/components/localeStatus.ts" "admin/src/pages/content/[type]/[uuid]/components/localeStatus.spec.ts" "admin/src/pages/content/[type]/[uuid]/components/LocaleSwitcher.vue" "admin/src/pages/content/[type]/[uuid]/index.vue"
git commit -m "Show per-locale publish/draft/scheduled status in the entry editor"
```

---

## Task 3: Frontend — versions page follows the editor locale

The versions page hardcodes the default locale, so you cannot view/roll back the locale you were just editing. Make it read `?locale=` and have the editor's "Versions" link carry the active locale.

**Files:**
- Modify: `admin/src/pages/content/[type]/[uuid]/versions.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`

- [ ] **Step 1: Carry the locale on the Versions link.** In `index.vue`, change the Versions button `:to` (line ~199) to include the active locale:

```vue
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-history"
            :to="`/content/${type}/${uuid}/versions?locale=${locale}`"
          >
            Versions
          </UButton>
```

- [ ] **Step 2: Read the locale from the query in `versions.vue`.** Replace the script setup (lines ~10-18) with:

```ts
const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()
const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))
const locale = computed(() => String(route.query.locale ?? runtimeConfig.defaultLocale))

const { data: versions, status } = useVersions(uuid, locale)
const rollback = useRollback(uuid.value, locale.value, type.value)

async function onRollback(versionUuid: string) {
  try {
    await rollback.mutateAsync(versionUuid)
    success('Rolled back')
    router.push(`/content/${type.value}/${uuid.value}?locale=${locale.value}`)
  } catch (e) {
    notifyError(e, 'Rollback failed')
  }
}
```

- [ ] **Step 3: Show the locale in the versions header and preserve it on the back link.** Replace the `UDashboardNavbar` (lines ~33-45) with:

```vue
      <UDashboardNavbar :title="`Versions · ${locale}`">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}/${uuid}?locale=${locale}`"
            aria-label="Back to editor"
          />
        </template>
      </UDashboardNavbar>
```

- [ ] **Step 4: Honor `?locale=` as the editor's initial locale.** So the round-trip back from versions lands on the right locale. In `index.vue`, change the locale init default. Replace line ~25:

```ts
const locale = ref(String(route.query.locale ?? runtimeConfig.defaultLocale))
```

(The existing `localeInitialized` watchEffect already corrects this to a locale the entry actually has, so an invalid `?locale=` is harmless.)

- [ ] **Step 5: Type-check, lint, format.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`, then `pnpm run lint && pnpm run format`.

- [ ] **Step 6: Commit.**

```bash
git add "admin/src/pages/content/[type]/[uuid]/versions.vue" "admin/src/pages/content/[type]/[uuid]/index.vue"
git commit -m "Make the versions page follow the editor's selected locale"
```

---

## Task 4: Frontend — copy/overwrite into an existing locale

The backend's `createLocaleDraft` accepts `overwrite` to re-seed an existing draft, but no UI exposes it. Add a "Copy content from…" action that overwrites the **current** locale's draft from another existing locale.

**Files:**
- Modify: `admin/src/queries/entries.ts`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`

- [ ] **Step 1: Thread `overwrite` through the query.** In `admin/src/queries/entries.ts`, replace `createLocaleDraft` + `useCreateLocaleDraft` (lines ~135-158) with:

```ts
/** Create a draft for `locale`, optionally seeding by copying `sourceLocale`. `overwrite` replaces an existing draft. */
export async function createLocaleDraft(
  uuid: string,
  locale: string,
  sourceLocale?: string,
  overwrite = false,
): Promise<void> {
  const { error, response } = await client.POST('/entries/{uuid}/locales/{locale}', {
    params: { path: { uuid, locale } },
    body: { source_locale: sourceLocale ?? null, overwrite },
  })
  if (error) throw toApiError(error, response)
}

export function useCreateLocaleDraft() {
  const cache = useQueryCache()
  return useMutation({
    mutation: (vars: { uuid: string; locale: string; sourceLocale?: string; overwrite?: boolean }) =>
      createLocaleDraft(vars.uuid, vars.locale, vars.sourceLocale, vars.overwrite ?? false),
    onSettled: (_data, _error, vars) => {
      cache.invalidateQueries({ key: ['entry-locales', vars.uuid] })
      cache.invalidateQueries({ key: qk.draft(vars.uuid, vars.locale) })
    },
  })
}
```

> If `pnpm run type-check` complains that `overwrite` is not in the request body type, the spec is stale — run `composer run docs:openapi && (cd admin && pnpm run gen:api)` first, since `CopyLocaleData` already has the field on the backend.

- [ ] **Step 2: Add a "Copy from…" action + confirm modal in the editor.** In `index.vue` script, after the create-locale block (after line ~112), add:

```ts
// ── Copy content into the current locale (overwrite) ───────────────────────────
const copySource = ref('')
const copySourceOptions = computed(() =>
  entryLocaleCodes.value.filter((c) => c !== locale.value).map((c) => ({ label: localeLabel(c), value: c })),
)
function openCopyInto(source: string) {
  copySource.value = source
}
async function confirmCopyInto() {
  const source = copySource.value
  if (!source) return
  try {
    await createLocale.mutateAsync({
      uuid: uuid.value,
      locale: locale.value,
      sourceLocale: source,
      overwrite: true,
    })
    success(`Copied ${localeLabel(source)} content into ${localeLabel(locale.value)}`)
    copySource.value = ''
  } catch (e) {
    notifyError(e, 'Couldn’t copy content')
  }
}
```

- [ ] **Step 3: Add the trigger to the navbar.** In the `#right` slot, after `<LocaleSwitcher … />`, add a dropdown shown when the current locale has other siblings to copy from:

```vue
          <UDropdownMenu
            v-if="multiLocale && copySourceOptions.length"
            :items="copySourceOptions.map((o) => ({ label: `From ${o.label}`, onSelect: () => openCopyInto(o.value) }))"
            :content="{ align: 'end' }"
          >
            <UButton
              icon="i-lucide-copy"
              color="neutral"
              variant="ghost"
              size="sm"
              aria-label="Copy content into this locale"
            />
          </UDropdownMenu>
```

- [ ] **Step 4: Add the confirm modal** at the end of the template, after the existing create-locale `UModal` (after line ~284):

```vue
  <UModal
    :open="copySource !== ''"
    title="Copy content into this locale"
    @update:open="(v: boolean) => { if (!v) copySource = '' }"
  >
    <template #body>
      <p class="text-sm text-muted">
        Replace the <span class="text-default">{{ localeLabel(locale) }}</span> draft with content
        from <span class="text-default">{{ localeLabel(copySource) }}</span>? Shared (non-localized)
        fields are copied from the source; the target’s localized fields are cleared for
        re-translation. This overwrites the current draft.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" label="Cancel" :disabled="createLocale.isLoading.value" @click="copySource = ''" />
        <UButton icon="i-lucide-copy" label="Copy content" :loading="createLocale.isLoading.value" @click="confirmCopyInto" />
      </div>
    </template>
  </UModal>
```

> After overwriting, the local `fields` editor copy is stale. The `useCreateLocaleDraft` `onSettled` invalidates the draft query, and the editor's `watch(draft, …)` re-seeds `fields` when the refetched draft arrives — confirm this re-seed happens (it does, because the draft object reference changes). If it doesn't visibly refresh, add `cache.invalidateQueries` is already in place; no extra code needed.

- [ ] **Step 5: Type-check, lint, format, then commit.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`; then `pnpm run lint && pnpm run format`.

```bash
git add "admin/src/queries/entries.ts" "admin/src/pages/content/[type]/[uuid]/index.vue"
git commit -m "Allow copying content into an existing locale (overwrite)"
```

---

## Task 5: Frontend — translation-progress in the entry list

Each entry row lists the locales it exists in, but not how many of the enabled locales that covers. Add a "N / M" progress badge.

**Files:**
- Modify: `admin/src/pages/content/[type]/index.vue`

- [ ] **Step 1: Load enabled locales.** In the script, add the import + computed (after the existing query imports, ~line 6):

```ts
import { useLocales } from '@/queries/locales'
```

After `const { data, status } = useEntries(...)` (line ~49):

```ts
const { data: allLocales } = useLocales()
// Coverage is measured against currently-enabled locales, and only counts an entry's locales that are
// still enabled — otherwise a disabled-but-still-populated locale could read e.g. "3 / 2".
const enabledCodes = computed(
  () => new Set((allLocales.value ?? []).filter((l) => l.enabled).map((l) => l.code)),
)
const enabledCount = computed(() => enabledCodes.value.size)
function translatedCount(locales: string[]): number {
  return locales.filter((c) => enabledCodes.value.has(c)).length
}
```

- [ ] **Step 2: Show progress in the locales cell.** Replace the `#locales-cell` template (lines ~104-116) with:

```vue
        <template #locales-cell="{ row }">
          <div class="flex items-center gap-2">
            <UBadge
              v-if="enabledCount > 1"
              :color="translatedCount(row.original.locales) >= enabledCount ? 'success' : 'neutral'"
              variant="subtle"
              size="sm"
            >
              {{ translatedCount(row.original.locales) }} / {{ enabledCount }}
            </UBadge>
            <div class="flex flex-wrap gap-1">
              <UBadge
                v-for="loc in row.original.locales"
                :key="loc"
                color="neutral"
                variant="outline"
                size="sm"
              >
                {{ loc }}
              </UBadge>
            </div>
          </div>
        </template>
```

- [ ] **Step 3: Type-check, lint, format, then commit.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`; then `pnpm run lint && pnpm run format`.

```bash
git add "admin/src/pages/content/[type]/index.vue"
git commit -m "Show locale-coverage progress in the entry list"
```

---

## Task 6: Frontend — cross-locale route management

`PublishPanel` only edits the current locale's slug. Add a modal that lists every enabled locale with its route slug, editable and removable in one place.

**Files:**
- Modify: `admin/src/queries/routes.ts` (add `deleteRoute`)
- Create: `admin/src/pages/content/[type]/[uuid]/components/LocaleRoutesModal.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`

- [ ] **Step 1: Add a route-delete query.** Append to `admin/src/queries/routes.ts`:

```ts
export async function deleteRoute(uuid: string, locale: string) {
  const { error, response } = await client.DELETE('/entries/{uuid}/routes/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
}
```

- [ ] **Step 2: Build the modal.** `admin/src/pages/content/[type]/[uuid]/components/LocaleRoutesModal.vue`. It loads all routes once, holds an editable slug per enabled locale, and saves/deletes per row, invalidating the routes cache itself.

```vue
<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useQueryCache } from '@pinia/colada'
import { useRoutes, saveRoute, deleteRoute } from '@/queries/routes'
import { qk } from '@/queries/keys'
import type { Locale } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string; enabled: Locale[] }>()
const open = defineModel<boolean>('open', { required: true })

const { success, error: notifyError } = useNotify()
const cache = useQueryCache()
const { data: routes } = useRoutes(() => props.uuid)

// Editable slug per locale, seeded from the loaded routes.
const slugs = reactive<Record<string, string>>({})
const busy = ref('') // locale currently saving/deleting
watch(
  routes,
  (r) => {
    for (const l of props.enabled) {
      slugs[l.code] = r?.find((x) => x.locale === l.code)?.slug ?? ''
    }
  },
  { immediate: true },
)

const invalidate = () => cache.invalidateQueries({ key: qk.routes(props.uuid) })

async function onSave(code: string) {
  busy.value = code
  try {
    await saveRoute(props.uuid, code, slugs[code] ?? '')
    await invalidate()
    success(`Route saved for ${code}`)
  } catch (e) {
    notifyError(e, 'Couldn’t save route')
  } finally {
    busy.value = ''
  }
}
async function onDelete(code: string) {
  busy.value = code
  try {
    await deleteRoute(props.uuid, code)
    slugs[code] = ''
    await invalidate()
    success(`Route removed for ${code}`)
  } catch (e) {
    notifyError(e, 'Couldn’t remove route')
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Routes by locale">
    <template #body>
      <div class="space-y-3">
        <div v-for="l in enabled" :key="l.code" class="flex items-end gap-2">
          <UFormField :label="`${l.name} (${l.code})`" class="flex-1">
            <UInput v-model="slugs[l.code]" placeholder="my-page" class="w-full" />
          </UFormField>
          <UButton variant="subtle" :loading="busy === l.code" @click="onSave(l.code)">Save</UButton>
          <UButton
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :loading="busy === l.code"
            :aria-label="`Remove ${l.code} route`"
            @click="onDelete(l.code)"
          />
        </div>
      </div>
    </template>
  </UModal>
</template>
```

> Confirm `qk.routes` exists in `admin/src/queries/keys.ts` (it's used by `useRoutes`/`useSaveRoute`). Confirm `useNotify` and `defineModel` usage match other components (e.g. `UserBulkImportModal.vue`).

- [ ] **Step 3: Wire it into the editor.** In `index.vue`:

Import (after `LocaleSwitcher` import):

```ts
import LocaleRoutesModal from './components/LocaleRoutesModal.vue'
```

State (near the other refs, after line ~25):

```ts
const showRoutes = ref(false)
```

Add a trigger button in the navbar `#right` slot (after the Versions button), shown only for multi-locale:

```vue
          <UButton
            v-if="multiLocale"
            variant="ghost"
            color="neutral"
            icon="i-lucide-signpost"
            aria-label="Manage routes by locale"
            @click="showRoutes = true"
          />
```

And mount the modal at the end of the template (after the copy modal):

```vue
  <LocaleRoutesModal v-model:open="showRoutes" :uuid="uuid" :enabled="enabledLocales" />
```

- [ ] **Step 4: Type-check, lint, format, then commit.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`; then `pnpm run lint && pnpm run format`.

```bash
git add "admin/src/queries/routes.ts" "admin/src/pages/content/[type]/[uuid]/components/LocaleRoutesModal.vue" "admin/src/pages/content/[type]/[uuid]/index.vue"
git commit -m "Add cross-locale route management to the entry editor"
```

---

## Task 7: Frontend — bulk locale operations

Orchestrate the existing per-locale endpoints to (a) create drafts for all missing locales by copying the current one, and (b) publish every locale that has a draft. Pure frontend over `createLocaleDraft` + `publishEntry`.

**Files:**
- Create: `admin/src/pages/content/[type]/[uuid]/components/BulkLocaleMenu.vue`
- Modify: `admin/src/pages/content/[type]/[uuid]/index.vue`

- [ ] **Step 1: Build the bulk menu.** `admin/src/pages/content/[type]/[uuid]/components/BulkLocaleMenu.vue`. It does its own mutations + cache invalidation + summary toasts.

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { useQueryCache } from '@pinia/colada'
import { createLocaleDraft, type EntryLocaleSummary } from '@/queries/entries'
import { publishEntry } from '@/queries/publish'
import { qk } from '@/queries/keys'
import type { Locale } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{
  uuid: string
  type: string
  currentLocale: string
  summaries: EntryLocaleSummary[]
  addable: Locale[]
}>()

const { success, warning, error: notifyError } = useNotify()
const cache = useQueryCache()
const running = ref(false)

const items = computed(() => [
  [
    {
      label: `Create drafts for all locales (copy ${props.currentLocale})`,
      icon: 'i-lucide-copy-plus',
      disabled: props.addable.length === 0,
      onSelect: () => runCreateAll(),
    },
    {
      label: 'Publish every locale with a draft',
      icon: 'i-lucide-globe',
      onSelect: () => runPublishAll(),
    },
  ],
])

/** Run a batch, returning how many succeeded; never throws (collects failures). */
async function batch<T>(targets: T[], fn: (t: T) => Promise<unknown>): Promise<{ ok: number; fail: number }> {
  const results = await Promise.allSettled(targets.map((t) => fn(t)))
  const ok = results.filter((r) => r.status === 'fulfilled').length
  return { ok, fail: results.length - ok }
}

function refresh() {
  cache.invalidateQueries({ key: ['entry-locales', props.uuid] })
  cache.invalidateQueries({ key: qk.entries(props.type) })
}

async function runCreateAll() {
  if (running.value || props.addable.length === 0) return
  running.value = true
  try {
    const { ok, fail } = await batch(props.addable, (l) =>
      createLocaleDraft(props.uuid, l.code, props.currentLocale, false),
    )
    refresh()
    if (fail === 0) success(`Created ${ok} locale draft(s)`)
    else warning(`Created ${ok}, failed ${fail}`, 'Some locales may already exist.')
  } catch (e) {
    notifyError(e, 'Bulk create failed')
  } finally {
    running.value = false
  }
}

async function runPublishAll() {
  if (running.value) return
  const targets = props.summaries.filter((s) => s.has_draft).map((s) => s.locale)
  if (targets.length === 0) {
    warning('Nothing to publish', 'No locale has a draft.')
    return
  }
  running.value = true
  try {
    const { ok, fail } = await batch(targets, (code) => publishEntry(props.uuid, code))
    refresh()
    if (fail === 0) success(`Published ${ok} locale(s)`)
    else warning(`Published ${ok}, failed ${fail}`)
  } catch (e) {
    notifyError(e, 'Bulk publish failed')
  } finally {
    running.value = false
  }
}
</script>

<template>
  <UDropdownMenu :items="items" :content="{ align: 'end' }">
    <UButton icon="i-lucide-layers" color="neutral" variant="ghost" size="sm" :loading="running" aria-label="Bulk locale actions" />
  </UDropdownMenu>
</template>
```

> Confirm `useNotify` exposes `warning` (it's used in `index.vue`/`PublishPanel.vue`). Confirm `UDropdownMenu` accepts a `disabled` field on items in v4 (check via MCP); if not, filter the create item out when `addable.length === 0` instead.

- [ ] **Step 2: Mount it in the editor.** In `index.vue`, import:

```ts
import BulkLocaleMenu from './components/BulkLocaleMenu.vue'
```

In the navbar `#right` slot, after the routes button:

```vue
          <BulkLocaleMenu
            v-if="multiLocale"
            :uuid="uuid"
            :type="type"
            :current-locale="locale"
            :summaries="entryLocales ?? []"
            :addable="addableLocales"
          />
```

- [ ] **Step 3: Type-check, lint, format, then commit.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`; then `pnpm run lint && pnpm run format`.

```bash
git add "admin/src/pages/content/[type]/[uuid]/components/BulkLocaleMenu.vue" "admin/src/pages/content/[type]/[uuid]/index.vue"
git commit -m "Add bulk create-all-locales and publish-all-locales actions"
```

---

## Task 8: Backend + Frontend — disable-locale guard

Disabling a locale that still has published content silently drops it from delivery. Add a per-locale content-usage endpoint and warn before disabling.

**Files:**
- Modify: `app/Content/Repositories/EntryRepository.php` (add `localeUsage`)
- Create: `app/Content/Http/Controllers/LocaleAdminController.php`
- Modify: `routes/lemma_admin.php`
- Modify: `app/Providers/LemmaServiceProvider.php`
- Test: `tests/Integration/Content/LocaleUsageTest.php`
- Regenerate: `docs/openapi.json` + FE types
- Create: `admin/src/queries/localeUsage.ts`
- Modify: `admin/src/pages/settings/languages/index.vue`

- [ ] **Step 1: Write the failing repository test.** `tests/Integration/Content/LocaleUsageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\EntryRepository;
use App\Tests\Support\LemmaTestCase;

final class LocaleUsageTest extends LemmaTestCase
{
    public function testLocaleUsageCountsDraftsAndPublications(): void
    {
        $db = $this->connection();
        $db->table('entries')->insert([
            'uuid' => 'entuse000001',
            'content_type_uuid' => 'typeuse00001',
            'status' => 'active',
            'created_at' => '2026-06-27 00:00:00',
            'updated_at' => '2026-06-27 00:00:00',
        ]);
        // entry_drafts keys on auto-increment `id` — no `uuid` column.
        $db->table('entry_drafts')->insert([
            'entry_uuid' => 'entuse000001',
            'locale' => 'fr',
            'fields' => '{}',
            'schema_version' => 1,
            'lock_version' => 0,
            'updated_at' => '2026-06-27 01:00:00',
        ]);
        // entry_publications keys on auto-increment `id` (no `uuid`) and requires `version_uuid`.
        $db->table('entry_publications')->insert([
            'entry_uuid' => 'entuse000001',
            'locale' => 'fr',
            'version_uuid' => 'veruse000001',
            'published_at' => '2026-06-27 02:00:00',
        ]);

        $repo = $this->container()->get(EntryRepository::class);

        self::assertSame(['published_entries' => 1, 'draft_entries' => 1], $repo->localeUsage('fr'));
        self::assertSame(['published_entries' => 0, 'draft_entries' => 0], $repo->localeUsage('de'));
    }
}
```

> Columns verified against `005_CreateEntryPublicationsTable.php` (entry_uuid, locale, version_uuid, published_at — `version_uuid` is NOT NULL; no `uuid` column). `localeUsage` only counts rows, so the `version_uuid` value need not reference a real `entry_versions` row.

- [ ] **Step 2: Run it to verify it fails.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && vendor/bin/phpunit --filter LocaleUsageTest`
Expected: FAIL with "Call to undefined method … localeUsage()".

- [ ] **Step 3: Implement `localeUsage`.** Add to `app/Content/Repositories/EntryRepository.php` (near `localeSummary`, before `emptyScheduleSummary`):

```php
    /**
     * Count content that exists in a locale — used to warn before disabling it.
     *
     * @return array{published_entries:int,draft_entries:int}
     */
    public function localeUsage(string $locale): array
    {
        return [
            'published_entries' => (int) $this->db->table('entry_publications')
                ->where('locale', '=', $locale)->count(),
            'draft_entries' => (int) $this->db->table('entry_drafts')
                ->where('locale', '=', $locale)->count(),
        ];
    }
```

> Verify the query builder's count signature against an existing `->count()` call in this repository (e.g. the list method) and match it.

- [ ] **Step 4: Run the test to verify it passes.**

Run: `vendor/bin/phpunit --filter LocaleUsageTest` → PASS.

- [ ] **Step 5: Add the controller.** `app/Content/Http/Controllers/LocaleAdminController.php`. Model it on an existing thin admin controller (e.g. how `EntryController::locales()` resolves `EntryRepository` and returns `Response::success`):

```php
<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Repositories\EntryRepository;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class LocaleAdminController
{
    public function __construct(private readonly EntryRepository $entries)
    {
    }

    public function usage(Request $request, string $locale): Response
    {
        return Response::success($this->entries->localeUsage($locale), 'Locale usage retrieved.');
    }
}
```

> Match the exact `Response`/`Request` imports and constructor-injection style used by `EntryController` (open it to confirm whether it injects the repo via constructor or resolves from the container).

- [ ] **Step 6: Register the route.** In `routes/lemma_admin.php`, after the entries-locales routes (around line ~87), add it gated by `content.manage` — the settings-management permission (used by the Email/General/Import-Export settings endpoints), **not** `content.view`. This endpoint backs a Settings-page guard, so it belongs with the management permission rather than the broad content-read scope:

```php
    $router->get('/locales/{locale}/usage', [LocaleAdminController::class, 'usage'])
        ->middleware('lemma_permission:content.manage');
```

The file references controllers by imported short class name at the top (e.g. `use App\Content\Http\Controllers\EntryController;`) — add `use App\Content\Http\Controllers\LocaleAdminController;` alongside them and match that style.

- [ ] **Step 7: Register the controller in the provider.** In `app/Providers/LemmaServiceProvider.php`, add the `use` import and a service entry in `services()` mirroring the other content controllers (per the project rule that every Lemma HTTP controller is registered there):

```php
            LocaleAdminController::class => [
                'class' => LocaleAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
```

- [ ] **Step 8: Regenerate spec + FE types, phpcs.**

Run:
```bash
cd /Users/michaeltawiahsowah/Sites/glueful/lemma
vendor/bin/phpcs app/Content/Http/Controllers/LocaleAdminController.php app/Content/Repositories/EntryRepository.php tests/Integration/Content/LocaleUsageTest.php
composer run docs:openapi
(cd admin && pnpm run gen:api)
grep -c "locales/{locale}/usage" docs/openapi.json
```
Expected: phpcs clean; spec regenerates; grep prints ≥1.

- [ ] **Step 9: Add the FE usage query.** `admin/src/queries/localeUsage.ts`:

```ts
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

export interface LocaleUsage {
  published_entries: number
  draft_entries: number
}

export async function fetchLocaleUsage(locale: string): Promise<LocaleUsage> {
  const { data, error, response } = await client.GET('/locales/{locale}/usage', {
    params: { path: { locale } },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data as Partial<LocaleUsage> | undefined
  return { published_entries: d?.published_entries ?? 0, draft_entries: d?.draft_entries ?? 0 }
}
```

> If `client.GET('/locales/{locale}/usage', …)` is not typed after `gen:api`, the spec didn't pick up the route — re-check Step 6/8. As a fallback, follow the `authFetch` pattern from `locales.ts` with the full `/v1/admin/locales/${encodeURIComponent(locale)}/usage` path.

- [ ] **Step 10: Guard the disable toggle.** In `admin/src/pages/settings/languages/index.vue`:

Add imports + state (script top, after existing imports):

```ts
import { fetchLocaleUsage, type LocaleUsage } from '@/queries/localeUsage'
```

Add near the other refs:

```ts
const pendingDisable = ref<{ locale: Locale; usage: LocaleUsage } | null>(null)
const checking = ref(false)
```

Replace `onToggleEnabled` (lines ~24-30) with:

```ts
async function onToggleEnabled(locale: Locale, value: boolean) {
  // Disabling a locale with published content hides it from delivery — confirm first.
  if (!value) {
    checking.value = true
    try {
      const usage = await fetchLocaleUsage(locale.code)
      if (usage.published_entries > 0 || usage.draft_entries > 0) {
        pendingDisable.value = { locale, usage }
        return
      }
    } catch (e) {
      notifyError(e, 'Couldn’t check language usage')
      return
    } finally {
      checking.value = false
    }
  }
  await applyEnabled(locale, value)
}

async function applyEnabled(locale: Locale, value: boolean) {
  try {
    await update.mutateAsync({ code: locale.code, input: { enabled: value } })
  } catch (e) {
    notifyError(e, 'Couldn’t update language')
  }
}

async function confirmDisable() {
  const p = pendingDisable.value
  if (!p) return
  await applyEnabled(p.locale, false)
  pendingDisable.value = null
}
```

> The `USwitch` is bound with `:model-value="row.original.enabled"` (not `v-model`), so when the guard cancels, the switch snaps back to the unchanged store value on the next render — no manual revert needed. Confirm this binding is one-way in the file.

Add the confirm modal at the end of the template (after the add-language `UModal`):

```vue
  <UModal
    :open="pendingDisable !== null"
    title="Disable this language?"
    @update:open="(v: boolean) => { if (!v) pendingDisable = null }"
  >
    <template #body>
      <p class="text-sm text-muted">
        <span class="text-default">{{ pendingDisable?.locale.name }}</span> still has
        <span class="text-default">{{ pendingDisable?.usage.published_entries }}</span> published and
        <span class="text-default">{{ pendingDisable?.usage.draft_entries }}</span> draft entr(y/ies).
        Disabling it hides published content in this language from delivery. Continue?
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" label="Cancel" :disabled="update.isLoading.value" @click="pendingDisable = null" />
        <UButton color="error" label="Disable language" :loading="update.isLoading.value" @click="confirmDisable" />
      </div>
    </template>
  </UModal>
```

- [ ] **Step 11: Type-check, lint, format.**

Run: `cd /Users/michaeltawiahsowah/Sites/glueful/lemma/admin && pnpm run type-check; echo "EXIT=$?"` → `EXIT=0`; then `pnpm run lint && pnpm run format`.

- [ ] **Step 12: Update the CHANGELOG.** In `CHANGELOG.md` under `## [Unreleased]` → `### Added`, in the Localization section, add:

```markdown
- Localization editor UX: per-locale publish/draft/scheduled status in the entry-editor locale
  switcher, locale-aware versions page, copy-into-existing-locale (overwrite), translation-coverage
  progress in the entry list, cross-locale route management, and bulk create/publish across locales.
  Disabling a language now warns when it still has published or draft content, backed by a new
  `GET /v1/admin/locales/{locale}/usage` endpoint.
```

- [ ] **Step 13: Commit.**

```bash
git add app/Content/Repositories/EntryRepository.php app/Content/Http/Controllers/LocaleAdminController.php routes/lemma_admin.php app/Providers/LemmaServiceProvider.php tests/Integration/Content/LocaleUsageTest.php docs/openapi.json "admin/src/queries/localeUsage.ts" "admin/src/pages/settings/languages/index.vue" CHANGELOG.md
git commit -m "Warn before disabling a language that still has content"
```

---

## Final verification

- [ ] **Backend suite:** `cd /Users/michaeltawiahsowah/Sites/glueful/lemma && composer test` → all green.
- [ ] **Frontend gate:** `cd admin && pnpm run type-check && pnpm run lint && pnpm exec vitest run && pnpm run build` → all pass, `EXIT=0`.
- [ ] **Manual smoke (dev server):** with ≥2 enabled locales and an entry that has a draft in one: the switcher shows status badges; "Versions" opens the active locale's history; copy-into-locale overwrites; the entry list shows "N / M"; the routes modal saves/removes per locale; bulk create/publish report a summary toast; disabling a locale with content prompts the confirm dialog.

## Notes / risks

- **Nuxt UI v4 API drift:** `USelectMenu` slot/prop names (`#item`, `value-key`, `search-input`), `UDropdownMenu` item `disabled`, and `text-{semantic-color}` utility availability should be confirmed via the nuxt-ui MCP before assuming. Each affected step flags this inline.
- **Spec/type coupling:** Tasks 4 and 8 depend on the regenerated OpenAPI types (`overwrite` on the copy body; the usage route). Always run `composer run docs:openapi && (cd admin && pnpm run gen:api)` before the FE type-check in those tasks.
- **Test seeding:** the two new PHP tests seed raw rows with column lists verified against `database/migrations/` (`entry_drafts`/`entry_publications` key on auto-increment `id`, not `uuid`; `entry_publications.version_uuid` is required). Re-verify if migrations change.
- **Permission scope:** the `/locales/{locale}/usage` guard is `content.manage` (settings-management), so the Languages settings page caller must hold it — which it already does to manage languages.
- **Bulk ops are client orchestration** over existing endpoints (no transaction across locales); partial failures are surfaced as a count, not rolled back — this is intentional for v1.
