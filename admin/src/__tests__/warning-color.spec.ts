import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { contrast } from '@/style/contrast'

// Warning text (an engine installed but not enabled, a notice to act on) must be read at a glance.
// Nuxt UI's default is yellow-500 on white, about 2:1. The admin uses amber, and in light mode its
// 700 shade, which reads as text on white (WCAG AA, 4.5:1). Dark mode keeps the 400 shade.
const root = join(__dirname, '..', '..')
const css = readFileSync(join(root, 'src/assets/css/main.css'), 'utf8')
const vite = readFileSync(join(root, 'vite.config.ts'), 'utf8')

describe('the warning colour', () => {
  it('is amber', () => {
    expect(vite).toMatch(/warning:\s*'amber'/)
  })

  it('is the 700 shade in light mode, set where it wins over Nuxt UI’s runtime 500', () => {
    // Nuxt UI injects `:root, .light { --ui-warning: …-500 }` after this sheet: a plain `:root`
    // rule would lose, so the override is the more specific `:root:not(.dark)`.
    expect(css).toMatch(
      /:root:not\(\.dark\)\s*\{[^}]*--ui-warning:\s*var\(--ui-color-warning-700\)/,
    )
  })

  it('amber-700 reads as text on a white page', () => {
    expect(contrast('#b45309', '#ffffff')).toBeGreaterThanOrEqual(4.5)
  })
})
