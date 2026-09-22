import { MARKDOWN_FOLDER } from './markdownFolder'

// Format-adapter keys belong to the thallo.importers pack; the core snapshot importer
// (thallo.content) is always available regardless of the capability.
const FORMAT_ADAPTER_KEYS = [
  'csv.content',
  'markdown.content',
  'wordpress.content',
  MARKDOWN_FOLDER,
]
// Imported from Users › Import, which has the column mapping it needs; this page has none.
const ELSEWHERE = ['csv.users']

/** The importers this page offers, as select items. */
export function importerChoices(
  adapters: { key: string; label: string }[],
  importersEnabled: boolean,
): { label: string; value: string }[] {
  return adapters
    .filter((a) => !ELSEWHERE.includes(a.key))
    .filter((a) => importersEnabled || !FORMAT_ADAPTER_KEYS.includes(a.key))
    .map((a) => ({ label: a.label, value: a.key }))
}
