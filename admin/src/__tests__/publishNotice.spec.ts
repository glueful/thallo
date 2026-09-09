import { describe, it, expect } from 'vitest'
import { ApiError } from '@/api/errors'
import { publishFailureNotice } from '@/utils/publishNotice'

// A blocked publish used to surface as "Couldn't publish" with the server sentence underneath,
// which reads as a fault. It is a workflow state: say so, and say what to do next.

function apiError(status: number, message: string, details: Record<string, unknown> = {}): ApiError {
  return new ApiError(message, status, {}, { success: false, message, error: { code: status, details } })
}

describe('publishFailureNotice', () => {
  it('explains a review-gated publish and points at the next step', () => {
    const notice = publishFailureNotice(
      apiError(409, 'Publishing requires an approved review (current state: in_review).', {
        workflow_state: 'in_review',
      }),
    )
    expect(notice?.title).toBe('Needs a review before publishing')
    expect(notice?.description).toContain('Submit it for review')
    expect(notice?.description).toContain('in review')
  })

  it('names the missing permission on a forbidden publish', () => {
    const notice = publishFailureNotice(apiError(403, 'Forbidden'))
    expect(notice?.title).toBe('You are not allowed to publish')
    expect(notice?.description).toContain('content.publish')
  })

  it('leaves every other failure to the generic handler', () => {
    expect(publishFailureNotice(apiError(500, 'boom'))).toBeNull()
    expect(publishFailureNotice(new Error('network'))).toBeNull()
  })
})
