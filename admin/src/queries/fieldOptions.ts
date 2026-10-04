import { useQuery } from '@pinia/colada'
import type { Ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// A block field's server-provided choices (search block spec §3.9): GET /v1/admin/field-options/
// {source}. Authors who can edit pages may load them; the source decides who else may.

export interface FieldOption {
  value: string
  label: string
  available: boolean
  reason: string | null
}

export async function fetchFieldOptions(source: string): Promise<FieldOption[]> {
  const json = await authFetch(
    `${runtimeConfig.apiBase}/field-options/${encodeURIComponent(source)}`,
  )
  const data = (json.data ?? json) as Record<string, unknown>
  return Array.isArray(data.options) ? (data.options as FieldOption[]) : []
}

export function useFieldOptions(source: Ref<string>) {
  return useQuery({
    key: () => ['field-options', source.value],
    query: () => fetchFieldOptions(source.value),
  })
}
