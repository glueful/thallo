import { describe, it, expect } from 'vitest'
import { importerChoices } from '@/pages/settings/import-export/importerChoices'

const ADAPTERS = [
  { key: 'thallo.content', label: 'Thallo snapshot (NDJSON)' },
  { key: 'csv.content', label: 'CSV' },
  { key: 'csv.users', label: 'Users (CSV)' },
  { key: 'markdown.folder', label: 'Markdown folder' },
]

describe('importer choices on the Import page', () => {
  it('never offers the users import, which lives under Users with its own mapping', () => {
    expect(importerChoices(ADAPTERS, true).map((c) => c.value)).toEqual([
      'thallo.content',
      'csv.content',
      'markdown.folder',
    ])
  })

  it('keeps only the core snapshot import while the importers are off', () => {
    expect(importerChoices(ADAPTERS, false).map((c) => c.value)).toEqual(['thallo.content'])
  })
})
