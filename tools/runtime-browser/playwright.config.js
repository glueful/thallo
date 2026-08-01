// Chromium-only real-browser smoke gate for the theme-runtime custom elements
// (FF-B). See README.md in this directory for scope and rationale.
'use strict';

const { defineConfig, devices } = require('@playwright/test');

const PORT = process.env.RUNTIME_BROWSER_PORT || 4789;
const BASE_URL = `http://127.0.0.1:${PORT}`;

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list']] : [['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure'
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ],
  // Serves the whole repo root (see server.js) so fixtures load the REAL
  // theme CSS and the REAL packages/thallo-render/runtime/runtime.js by path
  // instead of copies.
  webServer: {
    command: `node server.js`,
    // The server has no index.html at the repo root it serves (a bare `/`
    // 404s by design), so the readiness probe targets a real fixture path
    // instead of BASE_URL itself.
    url: `${BASE_URL}/tools/runtime-browser/fixtures/elements.html`,
    reuseExistingServer: !process.env.CI,
    env: { RUNTIME_BROWSER_PORT: String(PORT) }
  }
});
