import { expect, test } from "@playwright/test";

const organizationId = process.env.NEXORA_E2E_ORG;
const login = process.env.NEXORA_E2E_LOGIN;
const password = process.env.NEXORA_E2E_PASSWORD;

test("local Alpha user can log in and load the real dashboard", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Set NEXORA_E2E_ORG, NEXORA_E2E_LOGIN and NEXORA_E2E_PASSWORD for a local API smoke test.");

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
