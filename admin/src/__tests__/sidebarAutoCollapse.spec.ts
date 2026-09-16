import { describe, it, expect } from 'vitest'
import { nextTick, ref } from 'vue'
import { autoCollapseSidebar } from '@/navigation/sidebarAutoCollapse'

// The Design page wants the whole width: the sidebar collapses on entering it and comes back
// exactly as it was on leaving — a user who keeps it collapsed everywhere never sees it expand.
describe('autoCollapseSidebar', () => {
  it('collapses on entering a wanting route and restores the previous state on leaving', async () => {
    const collapsed = ref(false)
    const wants = ref(false)
    autoCollapseSidebar(collapsed, wants)
    wants.value = true
    await nextTick()
    expect(collapsed.value).toBe(true)
    wants.value = false
    await nextTick()
    expect(collapsed.value).toBe(false)
  })

  it('leaves a sidebar the user keeps collapsed collapsed, before and after', async () => {
    const collapsed = ref(true)
    const wants = ref(false)
    autoCollapseSidebar(collapsed, wants)
    wants.value = true
    await nextTick()
    expect(collapsed.value).toBe(true)
    wants.value = false
    await nextTick()
    expect(collapsed.value).toBe(true)
  })

  it('landing directly on a wanting route collapses at once and restores what storage had', async () => {
    const collapsed = ref(false)
    const wants = ref(true)
    autoCollapseSidebar(collapsed, wants)
    await nextTick()
    expect(collapsed.value).toBe(true)
    wants.value = false
    await nextTick()
    expect(collapsed.value).toBe(false)
  })

  it('a user who expands the sidebar while on the page keeps it expanded after leaving', async () => {
    const collapsed = ref(false)
    const wants = ref(false)
    autoCollapseSidebar(collapsed, wants)
    wants.value = true
    await nextTick()
    collapsed.value = false // the user reopened it by hand
    wants.value = false
    await nextTick()
    expect(collapsed.value).toBe(false)
  })

  it('the stop handle ends the watching', async () => {
    const collapsed = ref(false)
    const wants = ref(false)
    const stop = autoCollapseSidebar(collapsed, wants)
    stop()
    wants.value = true
    await nextTick()
    expect(collapsed.value).toBe(false)
  })
})
