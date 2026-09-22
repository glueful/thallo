import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export interface ScheduleRow {
  uuid: string
  action: string
  run_at: string
  status?: string
  locale?: string
  [k: string]: unknown
}

/** An entry's schedules, and whether the scheduler that runs them is ticking. */
export interface EntrySchedules {
  schedules: ScheduleRow[]
  schedulerTicking: boolean
}

// The schedules-list response body isn't typed in the spec; cast to the known contract.
export async function fetchSchedules(uuid: string): Promise<EntrySchedules> {
  const { data, error, response } = await client.GET('/entries/{uuid}/schedules', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  const body = (
    data as unknown as
      | { data?: { schedules?: ScheduleRow[]; scheduler?: { ticking?: boolean } } }
      | undefined
  )?.data
  return {
    schedules: body?.schedules ?? [],
    // An older server sends no status: assume ticking rather than raise a false alarm.
    schedulerTicking: body?.scheduler?.ticking ?? true,
  }
}

export function useSchedules(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.schedules(toValue(uuid)),
    query: () => fetchSchedules(toValue(uuid)),
  })
}

export async function createSchedule(
  uuid: string,
  locale: string,
  body: { action: string; run_at: string },
) {
  const { data, error, response } = await client.POST('/entries/{uuid}/schedules/{locale}', {
    params: { path: { uuid, locale } },
    body,
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function cancelSchedule(uuid: string, scheduleUuid: string) {
  const { data, error, response } = await client.DELETE(
    '/entries/{uuid}/schedules/{scheduleUuid}',
    {
      params: { path: { uuid, scheduleUuid } },
    },
  )
  if (error) throw toApiError(error, response)
  return data
}

export function useScheduleMutations(uuid: string, locale: string) {
  const cache = useQueryCache()
  // Schedules feed the per-locale 'scheduled' status (PublishPanel badge, LocaleSwitcher).
  const invalidate = () => {
    cache.invalidateQueries({ key: qk.schedules(uuid) })
    cache.invalidateQueries({ key: qk.entryLocales(uuid) })
  }

  const create = useMutation({
    mutation: (body: { action: string; run_at: string }) => createSchedule(uuid, locale, body),
    onSettled: invalidate,
  })
  const cancel = useMutation({
    mutation: (scheduleUuid: string) => cancelSchedule(uuid, scheduleUuid),
    onSettled: invalidate,
  })
  return { create, cancel }
}
