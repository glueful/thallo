// Browser proofs of the visual builder's structural editing (visual builder spec §5.6): the
// real Design page, served by the admin's Vite dev server, against API responses captured from
// the real controllers (scripts/build-builder-proof-fixtures). See README.md for scope.
import { defineConfig, devices } from '@playwright/test'

const PORT = Number(process.env.BUILDER_PROOFS_PORT || 4793)
export const BASE_URL = `http://127.0.0.1:${PORT}`

export default defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],
  timeout: 60_000,
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    // Tall enough for both ends of a stage drag to sit in the iframe's viewport at once, with
    // CI's wider fallback fonts making the composition taller than a local run's.
    viewport: { width: 1280, height: 1600 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'pnpm --dir .. dev',
    url: `${BASE_URL}/admin/`,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
    env: {
      VITE_E2E: '1',
      VITE_PORT: String(PORT),
      // The dev server binds plain http on the loopback for the proofs whatever the local .env says.
      VITE_HOST: '',
      VITE_SSL_KEY_PATH: '',
      VITE_SSL_CERT_PATH: '',
    },
  },
})
