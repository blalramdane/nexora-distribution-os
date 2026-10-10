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

test("local Alpha can create master data, post a purchase, and reconcile a sale in the UI", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha browser credentials are not configured.");
  const stamp = Date.now().toString();
  const sku = `E2E-${stamp}`;
  const productName = `صنف آلي ${stamp}`;
  const supplierName = `مورد آلي ${stamp}`;
  const customerName = `عميل آلي ${stamp}`;

  await page.goto("/login");
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(login!);
  await page.getByLabel("كلمة المرور").fill(password!);
  await page.getByLabel("اسم الجهاز").fill("NEXORA CRUD smoke");
  await page.getByRole("button", { name: "دخول" }).click();
  await expect(page).toHaveURL(/\/$/);

  await page.goto("/products");
  await page.getByRole("button", { name: "إضافة صنف" }).click();
  await page.getByLabel("SKU").fill(sku);
  await page.getByLabel("اسم الصنف").fill(productName);
  await page.getByLabel("التكلفة").fill("17.50");
  await page.getByLabel("سعر البيع").fill("25.00");
  await expect(page.getByRole("button", { name: "حفظ الصنف" })).toBeEnabled();
  await page.getByRole("button", { name: "حفظ الصنف" }).click();
  await expect(page.getByText(productName, { exact: true })).toBeVisible();

  await page.goto("/suppliers");
  await page.getByRole("button", { name: "مورد جديد" }).click();
  await page.getByLabel(/اسم المورد/).fill(supplierName);
  await page.getByRole("button", { name: "حفظ المورد" }).click();
  await expect(page.getByText("تمت إضافة المورد بنجاح.")).toBeVisible();
  await expect(page.getByText(supplierName, { exact: true })).toBeVisible();

  await page.goto("/customers");
  await page.getByRole("button", { name: "عميل جديد" }).click();
  await page.getByLabel("اسم العميل").fill(customerName);
  await page.getByRole("button", { name: "حفظ العميل" }).click();
  await expect(page.getByText("تمت إضافة العميل بنجاح.")).toBeVisible();
  await expect(page.getByText(customerName, { exact: true })).toBeVisible();

  // The location endpoint uses the status column from the actual locations schema.
  // Use the same warehouse for receiving and selling so the stock reconciliation is explicit.
  await page.goto("/purchases");
  const purchaseLocation = page.locator("select").nth(0);
  await expect(purchaseLocation.locator("option").first()).toBeAttached();
  const warehouseValue = await purchaseLocation.locator("option").evaluateAll((options) => {
    const preferred = options.find((option) => option.textContent?.includes("المخزن الرئيسي"));
    return ((preferred ?? options.find((option) => Boolean((option as HTMLOptionElement).value))) as HTMLOptionElement | undefined)?.value ?? "";
  });
  expect(warehouseValue).toBeTruthy();
  await purchaseLocation.selectOption(warehouseValue);

  const supplierSelect = page.locator("select").nth(1);
  const supplierValue = await supplierSelect.locator("option").evaluateAll((options, name) => {
    const match = options.find((option) => option.textContent?.includes(String(name)));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  }, supplierName);
  expect(supplierValue).toBeTruthy();
  await supplierSelect.selectOption(supplierValue);

  await page.getByPlaceholder("اسم الصنف / SKU / barcode...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  const purchaseLine = page.getByText(productName, { exact: true }).last().locator("xpath=../..");
  const purchaseInputs = purchaseLine.locator('input[type="number"]');
  await expect(purchaseInputs).toHaveCount(3);
  await purchaseInputs.nth(0).fill("1");
  await purchaseInputs.nth(1).fill("1");
  await purchaseInputs.nth(2).fill("17.50");
  await page.getByRole("button", { name: "استلام الفاتورة" }).click();
  await expect(page.getByText(/تم استلام/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("SKU / اسم الصنف / الموقع...").fill(sku);
  const receivedRow = page.getByRole("row").filter({ hasText: sku });
  await expect(receivedRow).toBeVisible();
  await expect(receivedRow.locator("td").nth(3)).toHaveText(/1|١/);

  await page.goto("/sales");
  await page.locator("select").nth(0).selectOption(warehouseValue);
  await page.getByPlaceholder("ابحث بالاسم أو الهاتف أو كود العميل...").fill(customerName);
  await page.getByRole("button").filter({ hasText: customerName }).first().click();
  await page.getByPlaceholder("اسم الصنف / SKU / barcode...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  const saleLine = page.getByText(productName, { exact: true }).last().locator("xpath=../..");
  await expect(saleLine.locator('input[type="number"]')).toHaveCount(1);
  await saleLine.locator('input[type="number"]').fill("1");
  await page.getByLabel("المدفوع الآن").fill("25.00");
  await page.getByRole("button", { name: "تسجيل البيع" }).click();
  await expect(page.getByText(/تم تسجيل/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("SKU / اسم الصنف / الموقع...").fill(sku);
  const soldRow = page.getByRole("row").filter({ hasText: sku });
  await expect(soldRow).toBeVisible();
  await expect(soldRow.locator("td").nth(3)).toHaveText(/0|٠/);
});
