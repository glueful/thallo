export interface HomepageButtonState {
  enabled: boolean
  tooltip: string
}

/**
 * "Set as homepage" needs two things the server checks: the locale is published AND it has a
 * saved route (slug). The Pages list shows only the first; a page can be published and still be
 * routeless, and the server refuses that. Disable the button and say which one is missing.
 */
export function homepageButtonState(input: { published: boolean; hasRoute: boolean }): HomepageButtonState {
  if (!input.published) return { enabled: false, tooltip: 'Publish first to set as homepage' }
  if (!input.hasRoute) return { enabled: false, tooltip: 'Save a slug first to set as homepage' }
  return { enabled: true, tooltip: 'Set as homepage' }
}
