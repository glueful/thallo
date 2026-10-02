import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

const cacheInvalidate = vi.hoisted(() => vi.fn())
vi.mock('@pinia/colada', () => ({
  useQueryCache: () => ({ invalidateQueries: cacheInvalidate }),
  useQuery: () => ({ data: { value: undefined }, status: { value: 'idle' } }),
  useMutation: () => ({ mutate: vi.fn() }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ refreshUntilChanged: vi.fn() }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

describe('schema state helpers', () => {
  const ext = (over: Record<string, unknown> = {}) => ({
    name: 'glueful/media',
    provider: 'P',
    requires_extensions: [],
    enabled: false,
    schema_state: 'ready' as const,
    schema_reasons: [],
    cli_command: 'php glueful extensions:enable glueful/media',
    ...over,
  })

  it('maps every closed schema state to a chip color', async () => {
    const { schemaChipColor } = await import('@/queries/extensions')
    expect(schemaChipColor('divergent')).toBe('error')
    expect(schemaChipColor('pending')).toBe('warning')
    expect(schemaChipColor('ready')).toBe('success')
    expect(schemaChipColor('none')).toBe('neutral')
    expect(schemaChipColor('undeclared')).toBe('neutral')
  })

  it('blocks the toggle only for a divergent schema, carrying reasons and the CLI command', async () => {
    const { toggleBlockedReason } = await import('@/queries/extensions')
    expect(toggleBlockedReason(ext())).toBeNull()
    expect(toggleBlockedReason(ext({ schema_state: 'pending' }))).toBeNull()

    const blocked = toggleBlockedReason(
      ext({
        schema_state: 'divergent',
        schema_reasons: ['glueful/media: checksum mismatch for 001_X.php'],
        cli_command: 'php glueful migrate:verify',
      }),
    )
    expect(blocked).toContain('checksum mismatch for 001_X.php')
    expect(blocked).toContain('php glueful migrate:verify')
  })

  it('extracts the failed migration from a 409 operation payload', async () => {
    const { failedMigrationOf } = await import('@/queries/extensions')
    const e = Object.assign(new Error('Extension operation did not complete'), {
      body: {
        error: {
          details: { operation: { failed_migration: '004_CreateThing.php', status: 'failed' } },
        },
      },
    })
    expect(failedMigrationOf(e)).toBe('004_CreateThing.php')
    expect(failedMigrationOf(new Error('plain'))).toBeNull()
    expect(failedMigrationOf(null)).toBeNull()
  })
})

describe('fetchInstalledExtensions', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('passes the schema columns through untouched', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        data: {
          extensions: [
            {
              name: 'glueful/media',
              provider: 'P',
              requires_extensions: [],
              enabled: false,
              schema_state: 'none',
              schema_reasons: [],
              cli_command: 'php glueful extensions:enable glueful/media',
            },
          ],
        },
      }),
    )
    const { fetchInstalledExtensions } = await import('@/queries/extensions')

    const rows = await fetchInstalledExtensions()

    expect(rows).toHaveLength(1)
    expect(rows[0]?.schema_state).toBe('none')
    expect(rows[0]?.cli_command).toContain('extensions:enable')
  })
})
