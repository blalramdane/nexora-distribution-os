import { defineConfig, devices } from "@playwright/test";

// Keep browser tests isolated from other local projects and the live dev server.
const e2ePort = Number(process.env.E2E_PORT ?? 3027);
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;

export default defineConfig({
  testDir: "./e2e",
  // The local Laravel server and SQLite demo database are shared by these tests;
  // serialize browser flows to avoid request contention and cross-test state races.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: "list",
  use: {
    baseURL: e2eBaseUrl,
    trace: "on-first-retry",
    ...devices["Desktop Chrome"],
  },
  webServer: {
    command: `npm run start -- --hostname 127.0.0.1 --port ${e2ePort}`,
    url: e2eBaseUrl,
    reuseExistingServer: false,
    timeout: 120_000,
  },
});
