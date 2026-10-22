import { expect, test } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

function localCredential(label: string): string | undefined {
  const envKey = "NEXORA_E2E_" + label.toUpperCase().replaceAll(" ", "_");
  if (process.env[envKey]) return process.env[envKey];
  const file = resolve(process.cwd(), "..", ".local-alpha-credentials.txt");
  if (!existsSync(file)) return undefined;
  const line = readFileSync(file, "utf8").split(/\r?\n/).find((entry) => entry.startsWith(label + ":"));
  return line?.slice(label.length + 1).trim();
}

const organizationId = localCredential("Organization ID");
const login = localCredential("Login email");
const password = localCredential("Password");

async function signIn(page: import("@playwright/test").Page) {
  await page.goto("/login");
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(login!);
  await page.getByLabel("كلمة المرور").fill(password!);
  await page.getByLabel("اسم الجهاز").fill("NEXORA Report Export E2E");
  await page.getByRole("button", { name: "دخول" }).click();
  await expect(page).toHaveURL(/\/$/);
}

test("manager can download XLSX and PDF exports from the operational and receivables reports", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha browser credentials are not configured.");
  test.setTimeout(120_000);
  page.setDefaultNavigationTimeout(60_000);
  await signIn(page);

  await page.goto("/reports");
  await expect(page.getByRole("heading", { name: "التقارير" })).toBeVisible();
  const operationalXlsxPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "تصدير Excel" }).click();
  const operationalXlsx = await operationalXlsxPromise;
  expect(operationalXlsx.suggestedFilename()).toMatch(/NEXORA-operational-report-.*\.xlsx$/);

  const operationalPdfPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "تصدير PDF" }).click();
  const operationalPdf = await operationalPdfPromise;
  expect(operationalPdf.suggestedFilename()).toMatch(/NEXORA-operational-report-.*\.pdf$/);

  await page.goto("/receivables");
  await expect(page.getByRole("heading", { name: "مديونيات العملاء" })).toBeVisible();
  await expect(page.getByRole("button", { name: "تصدير Excel كامل" })).toBeEnabled();
  const receivablesXlsxPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "تصدير Excel كامل" }).click();
  const receivablesXlsx = await receivablesXlsxPromise;
  expect(receivablesXlsx.suggestedFilename()).toMatch(/NEXORA-receivables-.*\.xlsx$/);

  const receivablesPdfPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "تصدير PDF كامل" }).click();
  const receivablesPdf = await receivablesPdfPromise;
  expect(receivablesPdf.suggestedFilename()).toMatch(/NEXORA-receivables-.*\.pdf$/);
});
