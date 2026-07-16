import { describe, it, expect } from 'vitest'
import { visibleNav } from '@/registry/adminModules'
import { workflowModule } from '@/registry/workflowModule'

describe('workflow admin module gating (thallo.workflow capability)', () => {
  it('omits the Review queue nav when thallo.workflow is not visible', () => {
    const [main] = visibleNav(() => false, [workflowModule])
    expect(main).toEqual([])
  })

  it('includes the Review queue nav linking to /workflow when visible', () => {
    const [main] = visibleNav((id) => id === 'thallo.workflow', [workflowModule])
    expect(main.map((i) => i.label)).toEqual(['Review queue'])
    expect(main[0].to).toBe('/workflow')
  })
})
