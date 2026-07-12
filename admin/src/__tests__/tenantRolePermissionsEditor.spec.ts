import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TenantRolePermissionsEditor from '@/components/tenancy/TenantRolePermissionsEditor.vue'
import type { CapabilityDefinition, WorkspaceRole } from '@/queries/tenantRoles'

const catalog: Record<string, CapabilityDefinition> = {
  'content.view': { label: 'View content', group: 'Content', platform_only: false },
  'content.edit': { label: 'Edit content', group: 'Content', platform_only: false },
  'tenant.roles.manage': {
    label: 'Manage roles',
    group: 'Workspace',
    platform_only: false,
  },
  'tenant.members.manage': {
    label: 'Manage members',
    group: 'Workspace',
    platform_only: false,
  },
}

const stubs = {
  UButton: {
    props: ['disabled', 'loading', 'label'],
    emits: ['click'],
    template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot />{{ label }}</button>',
  },
  UBadge: { props: ['label'], template: '<span>{{ label }}</span>' },
  UInput: { template: '<input />' },
  UIcon: { template: '<span />' },
}

function role(overrides: Partial<WorkspaceRole>): WorkspaceRole {
  return {
    slug: 'member',
    name: 'Member',
    builtin: true,
    status: 'active',
    baseline: [],
    grants: [],
    revokes: [],
    effective: [],
    drift: [],
    ...overrides,
  }
}

function mountEditor(subject: WorkspaceRole) {
  return mount(TenantRolePermissionsEditor, {
    props: { role: subject, catalog, busy: false, preview: null },
    global: { stubs },
  })
}

describe('tenant role permissions editor', () => {
  it('uses the assigned set as explicit grants for a custom role', async () => {
    const wrapper = mountEditor(
      role({
        slug: 'reviewer',
        name: 'Reviewer',
        builtin: false,
        grants: ['content.view'],
        effective: ['content.view'],
      }),
    )

    await wrapper.get('[data-testid="available-content.edit"]').trigger('click')
    await wrapper.get('[data-testid="assign-selected"]').trigger('click')
    await wrapper.get('[data-testid="overrides-save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[['content.edit', 'content.view'], []]])
  })

  it('translates a built-in role assignment into baseline grant and revoke deltas', async () => {
    const wrapper = mountEditor(
      role({
        baseline: ['content.view', 'content.edit'],
        revokes: ['content.edit'],
        effective: ['content.view'],
      }),
    )

    await wrapper.get('[data-testid="assigned-content.view"]').trigger('click')
    await wrapper.get('[data-testid="remove-selected"]').trigger('click')
    await wrapper.get('[data-testid="overrides-save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[[], ['content.edit', 'content.view']]])
  })

  it('keeps the owner governance floor assigned when removing all permissions', async () => {
    const floor = ['tenant.roles.manage', 'tenant.members.manage']
    const wrapper = mountEditor(
      role({
        slug: 'owner',
        name: 'Owner',
        baseline: [...floor, 'content.view'],
        effective: [...floor, 'content.view'],
      }),
    )

    await wrapper.get('button[aria-label="Remove all"]').trigger('click')
    await wrapper.get('[data-testid="overrides-save"]').trigger('click')

    expect(wrapper.emitted('save')).toEqual([[[], ['content.view']]])
    expect(wrapper.get('[data-testid="assigned-tenant.roles.manage"]').attributes('disabled')).toBe(
      '',
    )
  })
})
