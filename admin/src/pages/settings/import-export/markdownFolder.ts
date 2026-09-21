// A folder of Markdown, uploaded as a .zip (the `markdown.folder` adapter): the import the
// `thallo:import:markdown` command runs, from the admin. What is sent, which content types can
// take it, and how its report reads.
import type { IeJobError } from '@/queries/importExport'

export const MARKDOWN_FOLDER = 'markdown.folder'

export interface FolderChoices {
  type: string
  publish: boolean
  /** Where a reader can propose a change: the folder's edit URL on GitHub, GitLab… */
  editBase: string
  /** Folders to leave out, as typed: "internal, drafts". */
  exclude: string
  locale: string
}

export interface FolderOptions {
  content_type: string
  publish: boolean
  locale: string
  exclude: string[]
  edit_base?: string
}

export function folderOptions(choices: FolderChoices): FolderOptions {
  const options: FolderOptions = {
    content_type: choices.type,
    publish: choices.publish,
    locale: choices.locale,
    exclude: choices.exclude
      .split(',')
      .map((folder) => folder.trim().replace(/^\/+|\/+$/g, ''))
      .filter((folder) => folder !== ''),
  }
  const editBase = choices.editBase.trim().replace(/\/+$/, '')
  if (editBase !== '') options.edit_base = editBase
  return options
}

export interface TypeLike {
  slug?: string | null
  name?: string | null
  schema?: { name?: unknown }[] | null
}

/** The content types that can hold a Markdown page: the import needs a title and a body. */
export function pageTypes(types: TypeLike[]): { label: string; value: string }[] {
  return types
    .filter((type) => {
      if (!type.slug) return false
      const names = (type.schema ?? []).map((f) => String(f.name ?? ''))
      return names.includes('title') && names.includes('body')
    })
    .map((type) => ({ label: type.name ?? String(type.slug), value: String(type.slug) }))
}

const ORDER: Record<string, number> = { error: 0, warning: 1 }

/** The job's rows as a report: what needs attention first, then what each page became. */
export function reportLines(rows: IeJobError[]): IeJobError[] {
  return rows
    .map((row, index) => ({ row, index }))
    .sort(
      (a, b) => (ORDER[a.row.severity] ?? 2) - (ORDER[b.row.severity] ?? 2) || a.index - b.index,
    )
    .map(({ row }) => row)
}
