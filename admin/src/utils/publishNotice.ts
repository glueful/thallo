import { ApiError, apiErrorDetails } from '@/api/errors'

export interface PublishNotice {
  title: string
  description: string
}

const STATE_LABEL: Record<string, string> = {
  draft: 'a draft',
  in_review: 'in review',
  changes_requested: 'waiting for changes',
  approved: 'approved',
}

/**
 * A refused publish is usually a workflow state or a permission, not a fault. Turn the two
 * known refusals into a notice that says what happened and what to do next; anything else
 * returns null and the caller falls back to its generic error toast.
 */
export function publishFailureNotice(e: unknown): PublishNotice | null {
  if (!(e instanceof ApiError)) return null
  if (e.status === 409) {
    const state = apiErrorDetails(e)?.workflow_state
    if (typeof state !== 'string') return null
    const label = STATE_LABEL[state] ?? state
    return {
      title: 'Needs a review before publishing',
      description:
        `This locale is ${label}. Submit it for review and have a reviewer approve it, ` +
        'or ask an operator who holds workflow.bypass to publish it.',
    }
  }
  if (e.status === 403) {
    return {
      title: 'You are not allowed to publish',
      description: 'Publishing needs the content.publish permission on one of your roles.',
    }
  }
  return null
}
