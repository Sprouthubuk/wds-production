// Started by e2e/run.mjs, which brings up the local API and site first (E2E_BASE, E2E_API, E2E_PASSWORD).
import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: '.',
  testMatch: /.*\.spec\.mjs/,
  outputDir: '../test-results',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 45_000,
  reporter: [['list']],
  use: {
    baseURL: process.env.E2E_BASE,
    channel: 'chrome',
    headless: true,
    viewport: { width: 390, height: 844 },
    trace: 'retain-on-failure',
  },
});
