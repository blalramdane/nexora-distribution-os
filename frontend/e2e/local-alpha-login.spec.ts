import { expect, test } from "@playwright/test";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

function localCredential(label: string): string | undefined {
  const envKey = `NEXORA_E2E_${label.toUpperCase().replaceAll(" ", "_")}`;
  if (process.env[envKey]) return process.env[envKey];
  const file = resolve(process.cwd(), "..", ".local-alpha-credentials.txt");
  if (!existsSync(file)) return undefined;
  const line = readFileSync(file, "utf8").split(/\r?\n/).find((entry) => entry.startsWith(`${label}:`));
  return line?.slice(label.length + 1).trim();
}

const organizationId = localCredential("Organization ID");
const login = localCredential("Login email");
const password = localCredential("Password");

test("local Alpha user can log in and load the real dashboard", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha credentials are missing; set the NEXORA_E2E variables or run the local-alpha bootstrap.");

  await page.goto("/login");
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(login!);
  await page.getByLabel("كلمة المرور").fill(password!);
  await page.getByLabel("اسم الجهاز").fill("NEXORA Browser Smoke Test");
  await page.getByRole("button", { name: "دخول" }).click();

  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("heading", { name: "لوحة التحكم" })).toBeVisible();
  await expect(page.locator(".card.error")).toHaveCount(0);
  await expect(page.getByText("NEXORA Distribution OS").first()).toBeVisible();
});
