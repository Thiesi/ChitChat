import { defineConfig, devices } from '@playwright/test';

// Opt-in local dev server for the mocked presentation specs. With
// CHITCHAT_PHP_SERVER_PORT set, Playwright starts `php -S` on that port (or
// reuses one already answering there) and stops what it started when the run
// ends, so no server is left behind. Unset, nothing changes: CI and the
// real-server journeys use their own server at CHITCHAT_BASE_URL.
const phpServerPort = process.env.CHITCHAT_PHP_SERVER_PORT;
if (phpServerPort !== undefined && !/^[0-9]{4,5}$/.test(phpServerPort)) {
  throw new Error('CHITCHAT_PHP_SERVER_PORT must be a port number, for example 8091.');
}
const phpServerUrl = phpServerPort ? `http://127.0.0.1:${phpServerPort}` : null;

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  timeout: 90_000,
  expect: {
    timeout: 12_000,
  },
  reporter: process.env.CI
    ? [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }]]
    : [['list']],
  use: {
    baseURL: process.env.CHITCHAT_BASE_URL ?? phpServerUrl ?? 'http://127.0.0.1:8080',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
      },
    },
    {
      name: 'firefox',
      use: {
        ...devices['Desktop Firefox'],
      },
    },
    {
      name: 'webkit',
      use: {
        ...devices['Desktop Safari'],
      },
    },
  ],
  outputDir: 'test-results',
  webServer: phpServerUrl
    ? {
        command: `php -S 127.0.0.1:${phpServerPort} -t public`,
        url: `${phpServerUrl}/`,
        reuseExistingServer: true,
        timeout: 30_000,
        stdout: 'ignore',
        stderr: 'pipe',
      }
    : undefined,
});