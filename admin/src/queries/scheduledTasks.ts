import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

// ── Scheduled tasks (Thallo\Core\Http\Controllers\ScheduledTasksController, /v1/admin/scheduled-tasks) ─────

export interface ScheduledTask {
  name: string
  description: string
  schedule: string
  next_run: string | null
  enabled: boolean
  handler_class: string
  queue: string
}

const qk = () => ['utilities', 'scheduled-tasks'] as const

export async function fetchScheduledTasks(): Promise<ScheduledTask[]> {
  const { data, error, response } = await client.GET('/scheduled-tasks')
  if (error) throw toApiError(error, response)
  return (data?.data?.tasks ?? []) as ScheduledTask[]
}

export function useScheduledTasks() {
  return useQuery({ key: qk(), query: fetchScheduledTasks })
}

export async function runScheduledTask(name: string): Promise<{ name: string; job_id: string }> {
  const { data, error, response } = await client.POST('/scheduled-tasks/{name}/run', {
    params: { path: { name } },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data
  return { name: String(d?.name ?? name), job_id: String(d?.job_id ?? '') }
}

export function useScheduledTaskMutations() {
  const cache = useQueryCache()
  const run = useMutation({
    mutation: runScheduledTask,
    onSettled: () => cache.invalidateQueries({ key: qk() }),
  })
  return { run }
}
