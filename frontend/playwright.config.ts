import { defineConfig, devices } from "@playwright/test";
import { existsSync } from "node:fs";
import { resolve } from "node:path";

// Keep browser tests isolated from other local projects and the live dev server.
const e2ePort = Number(process.env.E2E_PORT ?? 3027);
const e2eBaseUrl = `http://127.0.0.1:${e2ePort}`;
const e2eApiPort = Number(process.env.E2E_API_PORT ?? 8018);
const e2eApiUrl = `http://127.0.0.1:${e2eApiPort}/api/v1`;
const requestedE2eDatabase = process.env.NEXORA_E2E_DATABASE?.trim();
if (!requestedE2eDatabase && !process.env.CI) {
  throw new Error(
    "Refusing to start the E2E API without an isolated SQLite database. Set NEXORA_E2E_DATABASE to a dedicated .sqlite/.sqlite3 file; the local .env may point at MySQL.",
  );
}
if (requestedE2eDatabase && requestedE2eDatabase !== ":memory:") {
  if (!/\.sqlite3?$/i.test(requestedE2eDatabase) || !existsSync(resolve(requestedE2eDatabase))) {
    throw new Error("NEXORA_E2E_DATABASE must point to an existing isolated .sqlite or .sqlite3 file.");
  }
}
const e2eDatabase = requestedE2eDatabase || ":memory:";
const localPhp = "..\\.tools\\php83\\php.exe";
const localPhpIni = "..\\.tools\\php83\\php.ini";
const backendCommand = process.platform === "win32"
  ? `if exist "${localPhp}" ("${localPhp}" -c "${localPhpIni}" artisan serve --host=127.0.0.1 --port=${e2eApiPort}) else (php artisan serve --host=127.0.0.1 --port=${e2eApiPort})`
  : `php artisan serve --host=127.0.0.1 --port=${e2eApiPort}`;

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
  webServer: [
    {
      command: backendCommand,
      cwd: "../backend",
      url: `${e2eApiUrl}/health`,
      reuseExistingServer: false,
      timeout: 120_000,
      // Never inherit DB_CONNECTION=mysql from backend/.env. Browser tests
      // must use a caller-provided isolated SQLite copy (or an empty in-memory
      // database in CI, where authenticated database-backed flows are skipped).
      env: {
        APP_ENV: "local",
        DB_CONNECTION: "sqlite",
        DB_DATABASE: e2eDatabase,
        DB_FOREIGN_KEYS: "true",
        SESSION_DRIVER: "array",
        CACHE_STORE: "array",
        QUEUE_CONNECTION: "sync",
        PHPRC: "../.tools/php83/php.ini",
        PHP_INI_SCAN_DIR: "",
      },
    },
    {
      command: "node .next-e2e/standalone/server.js",
      url: e2eBaseUrl,
      reuseExistingServer: false,
      timeout: 120_000,
      env: { NEXORA_E2E: "1", NEXT_PUBLIC_API_URL: e2eApiUrl, HOSTNAME: "127.0.0.1", PORT: String(e2ePort) },
    },
  ],
});
