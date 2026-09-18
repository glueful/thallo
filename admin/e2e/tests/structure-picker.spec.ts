import { test, expect } from '@playwright/test'
import {
  applyNow,
  hooks,
  openBlocksTab,
  openDesignPage,
  selectViaOutline,
  type Hooks,
} from '../helpers'

// Container-layout spec §6: the picker end to end, in a real browser — a container inserted from
// the Blocks tab is offered its presets, a choice commits one transaction, and undo and redo treat
// the whole preset as one thing.
//
// The proof drives the picker through the page's own test hooks rather than the stage, because
// this harness's stage is a captured page that never re-renders: a container inserted now has no
// tiles there to click. What the tiles do — rendering in the empty slot, disabling with a reason,
// posting choose and skip without selecting — is proven directly against the bridge asset in
// `admin/src/__tests__/preview-bridge-dom.spec.ts`.

interface PickerHooks {
  structureOffers: () => {
    id: string
    presets: { key: string; enabled: boolean; reason?: string }[]
  }[]
  chooseStructure: (id: string, preset: string) => Promise<void>
  skipStructure: (id: string) => void
}
type Page = Parameters<typeof openDesignPage>[0]

const offers = (page: Page) =>
  page.evaluate(() =>
    (window as unknown as { __thalloBuilder: PickerHooks }).__thalloBuilder.structureOffers(),
  )
const choose = (page: Page, id: string, preset: string) =>
  page.evaluate(
    ([blockId, key]) =>
      (window as unknown as { __thalloBuilder: PickerHooks }).__thalloBuilder.chooseStructure(
        blockId!,
        key!,
      ),
    [id, preset],
  )

/** Insert a container from the Blocks tab and return the id the editor gave it. */
async function insertContainer(page: Page): Promise<string> {
  await openBlocksTab(page)
  await page.locator('[data-test="palette-card-container"]').click()
  await page.waitForFunction(
    () =>
      (window as unknown as { __thalloBuilder: PickerHooks }).__thalloBuilder.structureOffers()
        .length > 0,
  )
  return (await offers(page))[0]!.id
}

/** Arm the Blocks tab into a block's content slot, the way the inspector's region + does. */
async function armInto(page: Page, parentId: string): Promise<void> {
  await selectViaOutline(page, parentId)
  await page.locator('[data-test="inspector-tabs"] button', { hasText: /^Block$/ }).click()
  await page.locator('[data-test="block-inspector"]').waitFor()
  await page.locator('[data-test="block-inspector"] [data-test="region-add-content"]').click()
  await page.locator('[data-test="blocks-tab"]').waitFor()
}

/** Insert a container INTO another block's content, and return the id it was given. */
async function insertContainerInto(page: Page, parentId: string): Promise<string> {
  await armInto(page, parentId)
  await page.locator('[data-test="palette-card-container"]').click()
  await page.waitForFunction(
    () =>
      (window as unknown as { __thalloBuilder: PickerHooks }).__thalloBuilder.structureOffers()
        .length > 0,
  )
  return (await offers(page))[0]!.id
}

const containerIn = (snapshot: Hooks, id: string) =>
  (snapshot.document.body as { id: string; data: Record<string, unknown> }[]).find(
    (b) => b.id === id,
  )!

test('a choice commits the whole preset, and undo and redo treat it as one', async ({ page }) => {
  const recorded = await openDesignPage(page)
  const id = await insertContainer(page)

  // Every preset is offered, with the ones that would not fit disabled and saying why.
  const offered = (await offers(page))[0]!.presets
  expect(offered.map((p) => p.key)).toContain('cols-33-67')
  expect(offered.find((p) => p.key === 'cols-33-67')!.enabled).toBe(true)

  await choose(page, id, 'cols-33-67')
  await applyNow(page, recorded)

  const after = await hooks(page)
  const entry = after.history[after.history.length - 1]!
  const types = entry.ops.map((op) => op.type)
  expect(types.filter((t) => t === 'InsertBlock')).toHaveLength(2)
  expect(types).toContain('SetSetting')
  // One transaction: undo takes the whole preset back, never half of it.
  expect(new Set(entry.ops.map((op) => op.transaction_id)).size).toBe(1)

  const columns = containerIn(after, id).data.content as { id: string; type: string }[]
  expect(columns.map((c) => c.type)).toEqual(['container', 'container'])
  const ids = columns.map((c) => c.id)
  // The offer is consumed.
  expect(await offers(page)).toEqual([])

  await page.keyboard.press('ControlOrMeta+z')
  await page.waitForFunction(() =>
    (
      (
        window as unknown as { __thalloBuilder: { snapshot: () => Hooks } }
      ).__thalloBuilder.snapshot().document.body as { data: { content?: unknown[] } }[]
    ).every((b) => (b.data.content ?? []).length !== 2),
  )
  const undone = await hooks(page)
  expect(containerIn(undone, id).data.content).toEqual([])
  // Undo does not reopen the picker (spec §6.1).
  expect(await offers(page)).toEqual([])

  await page.keyboard.press('ControlOrMeta+Shift+z')
  await page.waitForFunction(() =>
    (
      (
        window as unknown as { __thalloBuilder: { snapshot: () => Hooks } }
      ).__thalloBuilder.snapshot().document.body as { data: { content?: unknown[] } }[]
    ).some((b) => (b.data.content ?? []).length === 2),
  )
  const redone = await hooks(page)
  // Redo replays the same operations with the same ids.
  expect((containerIn(redone, id).data.content as { id: string }[]).map((c) => c.id)).toEqual(ids)
})

test('a preset that cannot fit the destination is offered disabled, with its reason', async ({
  page,
}) => {
  await openDesignPage(page)
  // Into the composition's deepest container: a Section is three high and will not fit, while a
  // column split still will. The tile says so before the click, not after.
  const id = await insertContainerInto(page, 'cont00000001')
  const presets = (await offers(page))[0]!.presets
  const section = presets.find((p) => p.key === 'section')!
  expect(section.enabled).toBe(false)
  expect(section.reason).toMatch(/levels/)
  expect(presets.find((p) => p.key === 'stack')!.enabled).toBe(true)
  expect(id).toBeTruthy()
})

test('content arriving consumes the offer, and a later choice commits nothing', async ({
  page,
}) => {
  const recorded = await openDesignPage(page)
  // A shallow destination, so a heading can still go inside the container that lands there.
  const id = await insertContainerInto(page, 'sect00000001')

  // A heading inserted into the offered container ends the offer (spec §6.1). The container is
  // already selected by its own insert, and it is not on this captured stage to select there.
  await page.locator('[data-test="inspector-tabs"] button', { hasText: /^Block$/ }).click()
  await page.locator('[data-test="block-inspector"]').waitFor()
  await page.locator('[data-test="block-inspector"] [data-test="region-add-content"]').click()
  await page.locator('[data-test="blocks-tab"]').waitFor()
  await page.locator('[data-test="palette-card-heading"]').click()
  await page.waitForFunction(
    () =>
      (window as unknown as { __thalloBuilder: PickerHooks }).__thalloBuilder.structureOffers()
        .length === 0,
  )

  const before = await hooks(page)
  const applies = recorded.applies.length
  // A choice made after the offer is over changes nothing at all.
  await choose(page, id, 'cols-33-67')
  const after = await hooks(page)
  expect(after.history.length).toBe(before.history.length)
  expect(recorded.applies.length).toBe(applies)
})
