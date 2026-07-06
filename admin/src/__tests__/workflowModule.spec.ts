import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules } from '@/registry/adminModules'
import { registerWorkflowModule } from '@/registry/workflowModule'

describe('workflow admin module gating (thallo.workflow capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Review queue nav when thallo.workflow is disabled', () => {
    registerWorkflowModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('includes the Review queue nav linking to /workflow when enabled', () => {
    registerWorkflowModule()
    const [main] = visibleNav((id) => id === 'thallo.workflow')
    expect(main.map((i) => i.label)).toEqual(['Review queue'])
    expect(main[0].to).toBe('/workflow')
  })
})
