import type { VueWrapper } from '@vue/test-utils'
import type { ComponentPublicInstance } from 'vue'

type SwitchVm = ComponentPublicInstance & { $emit: (e: string, v: boolean) => void }

export interface SwitchHandle {
  exists: () => boolean
  props: () => { modelValue?: boolean }
  attributes: (name: string) => string | undefined
  vm: SwitchVm
}

// @nuxt/ui ≥ 4.11 renders USwitch as `<Primitive data-slot="root"> … <SwitchRoot> <Primitive as
// button role="switch">`, and a fallthrough attribute such as `data-test` lands on BOTH the outer
// Primitive and the inner button. `findComponent('[data-test=…]')` therefore resolves a Primitive
// — which owns no `modelValue` and ignores `update:modelValue` — instead of the reka SwitchRoot the
// tests drive. Anchor on the `<button role="switch">`, then walk up to the first ancestor that
// owns `modelValue`: that is the SwitchRoot, whose `update:modelValue` USwitch forwards as the
// page's v-model.
export function switchRootByTestId(wrapper: VueWrapper<unknown>, testId: string): SwitchHandle {
  const button = wrapper.findComponent<ComponentPublicInstance>(
    `button[role="switch"][data-test="${testId}"]`,
  )
  if (!button.exists()) throw new Error(`no <button role="switch" data-test="${testId}"> rendered`)

  let vm: ComponentPublicInstance | null = button.vm
  while (vm && !('modelValue' in (vm.$props as Record<string, unknown>))) vm = vm.$parent
  if (!vm) throw new Error(`no SwitchRoot above <button data-test="${testId}">`)

  const el = button.element as HTMLElement
  return {
    exists: () => true,
    props: () => vm.$props as { modelValue?: boolean },
    attributes: (name: string) => el.getAttribute(name) ?? undefined,
    vm: vm as SwitchVm,
  }
}
