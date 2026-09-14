// Three-engine computed-style proofs for the visual builder's layered delivery
// (visual builder spec §2.6). See README.md in this directory for scope.
'use strict';

const { defineConfig, devices } = require('@playwright/test');

const PORT = process.env.STYLE_PROOFS_PORT || 4791;
const BASE_URL = `http://127.0.0.1:${PORT}`;

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list']],
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure'
  },
  // The declared public-site floor is Chrome 111 / Firefox 113 / Safari 16.2; the tested
  // matrix is the current stable engine of each family Playwright ships.
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } }
  ],
  webServer: {
    command: 'node server.js',
    url: `${BASE_URL}/tools/style-proofs/fixtures/index.html`,
    reuseExistingServer: !process.env.CI,
    env: { STYLE_PROOFS_PORT: String(PORT) }
  }
});
