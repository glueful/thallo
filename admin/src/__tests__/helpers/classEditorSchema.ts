// The style schema as the class editor receives it, built from the admin's own mirror of the
// contract (`styleProperties()`), so a property added to the contract reaches these tests without
// an edit here — which is what lets them prove "every Layout path has a control".
import { styleProperties } from '@/style/schema'
import type { StylePropertyRow, StyleSchemaResult } from '@/queries/styleSchema'

export const VOCABULARY = {
  version: 1,
  domains: {
    spacing: ['none', 'xs', 'sm', 'md', 'lg', 'xl', '2xl', '3xl'],
    width: ['narrow', 'content', 'container', 'full'],
    radius: ['none', 'sm', 'md', 'lg', 'full'],
    color: ['background', 'surface', 'text', 'accent'],
    shadow: ['none', 'sm', 'md', 'lg'],
  },
  values: { 'spacing.lg': 'var(--space-4)', 'radius.md': '6px' },
}

export function classEditorSchema(extra: StyleSchemaResult['properties'] = []): StyleSchemaResult {
  return {
    version: 3,
    breakpoints: { base: 0, md: 768, lg: 1024 },
    properties: [
      ...styleProperties().map(
        (def): StylePropertyRow => ({
          path: def.path,
          group: def.group,
          kinds: [def.tokenDomain !== null ? 'token' : 'choice', 'reset'],
          responsive: def.responsive,
          token_domain: def.tokenDomain,
          choices: def.choices,
        }),
      ),
      ...extra,
    ],
    advanced: [],
    vocabulary: VOCABULARY,
  }
}
