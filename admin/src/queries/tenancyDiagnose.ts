import { useQuery } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

export interface DiagnoseSection {
  status: 'ok' | 'warn' | 'fail' | 'info' | string
  detail: unknown
}

export interface DiagnoseReport {
  sections: Record<string, DiagnoseSection>
  ok: boolean
}

export const qkDiagnose = () => ['tenancy', 'diagnose'] as const

export async function fetchTenancyDiagnose(): Promise<DiagnoseReport> {
  const json = await authFetch(`${runtimeConfig.apiBase}/tenancy/diagnose`)
  const data = (json.data ?? json) as { report?: DiagnoseReport }
  if (!data.report) throw new Error('Malformed tenancy diagnostics response.')
  return data.report
}

export function useTenancyDiagnose() {
  return useQuery({ key: qkDiagnose(), query: fetchTenancyDiagnose, enabled: false })
}
