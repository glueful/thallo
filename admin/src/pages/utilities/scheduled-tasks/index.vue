<script setup lang="ts">
import { ref } from 'vue'
import {
  useScheduledTasks,
  useScheduledTaskMutations,
  type ScheduledTask,
} from '@/queries/scheduledTasks'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status, refresh, isLoading } = useScheduledTasks()
const { run } = useScheduledTaskMutations()

const running = ref('')

async function onRun(task: ScheduledTask) {
  running.value = task.name
  try {
    await run.mutateAsync(task.name)
    success(
      'Task queued',
      `“${task.name}” was queued to run on the ${task.queue || 'default'} queue.`,
    )
  } catch (e) {
    notifyError(e, 'Could not queue the task')
  } finally {
    running.value = ''
  }
}

function fmtTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <UDashboardPanel id="utilities-scheduled-tasks">
    <template #header>
      <UDashboardNavbar title="Scheduled tasks">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="isLoading"
            @click="
              () => {
                refresh()
              }
            "
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-3xl space-y-4">
        <p class="text-sm text-muted">
          Recurring jobs defined in <code>config/schedule.php</code>. “Run now” queues the job to
          run asynchronously on its queue. Enable/disable and run history are managed in code.
        </p>

        <div v-if="status === 'pending'" class="space-y-2">
          <USkeleton class="h-20" />
          <USkeleton class="h-20" />
          <USkeleton class="h-20" />
        </div>

        <UEmpty
          v-else-if="!(data ?? []).length"
          icon="i-lucide-clock"
          title="No scheduled tasks"
          description="None are defined in config/schedule.php."
        />

        <div v-else class="flex flex-col gap-2">
          <div
            v-for="t in data"
            :key="t.name"
            class="flex items-start justify-between gap-4 rounded-xl border border-default p-4"
          >
            <div class="min-w-0">
              <div class="flex items-center gap-2">
                <p class="font-medium text-default">{{ t.name }}</p>
                <UBadge
                  :label="t.enabled ? 'Enabled' : 'Disabled'"
                  :color="t.enabled ? 'success' : 'neutral'"
                  variant="subtle"
                  size="xs"
                />
              </div>
              <p v-if="t.description" class="mt-0.5 truncate text-sm text-muted">
                {{ t.description }}
              </p>
              <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                <span
                  ><span class="text-dimmed">Schedule</span> <code>{{ t.schedule }}</code></span
                >
                <span><span class="text-dimmed">Next run</span> {{ fmtTime(t.next_run) }}</span>
                <span v-if="t.queue"><span class="text-dimmed">Queue</span> {{ t.queue }}</span>
              </div>
            </div>
            <UButton
              label="Run now"
              icon="i-lucide-play"
              color="neutral"
              variant="subtle"
              size="sm"
              class="shrink-0"
              :loading="run.isLoading.value && running === t.name"
              :disabled="run.isLoading.value"
              @click="onRun(t)"
            />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
