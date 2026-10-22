import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test } from "@playwright/test";

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

test("purchase import review stays open for a new-product draft with null OCR fields", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha browser credentials are not configured.");

  const pageErrors: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));

  await page.goto("/login");
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(login!);
  await page.getByLabel("كلمة المرور").fill(password!);
  await page.getByLabel("اسم الجهاز").fill("NEXORA Purchase Import Null Regression");
  await page.getByRole("button", { name: "دخول" }).click();
  await expect(page).toHaveURL(/\/$/);

  await page.route(/\/api\/v1\/purchase-imports(?:\?.*)?$/, async (route) => {
    if (route.request().method() !== "GET") return route.continue();

    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify([{
        id: "01HZX0000000000000000000001",
        original_name: "supplier-invoice-null-draft.pdf",
        mime_type: "application/pdf",
        file_size: 2048,
        status: "needs_review",
        extracted_text: "فاتورة مشتريات\nرقم الفاتورة: NULL-001\nالإجمالي النهائي: 10.00",
        extraction_json: {
          invoice_number: "NULL-001",
          invoice_date: "2026-10-17",
          total: "10.00",
          lines: [{
            id: "line-null-1",
            product_id: "",
            quantity: null,
            unit_cost: null,
            item_mode: "new",
            invoice_item_name: "بند ناقص من OCR",
            new_product: { name_ar: null, base_unit_id: null, supplier_sku: "SUP-NULL-1" },
          }],
        },
        purchase_invoice_id: null,
        created_at: "2026-10-17T12:00:00Z",
      }]),
    });
  });

  await page.goto("/purchase-imports");
  await expect(page.getByRole("heading", { name: "استيراد فواتير المشتريات بالصور وPDF" })).toBeVisible();
  await expect(page.getByText("مراجعة المستند المختار")).toBeVisible();
  await expect(page.getByText("بند 1", { exact: true })).toBeVisible();
  await expect(page.getByLabel("اسم الصنف في الكتالوج (عربي)")).toHaveValue("");
  await expect(page.getByLabel("كمية بند 1")).toHaveValue("");
  await expect(page.getByLabel("تكلفة بند 1")).toHaveValue("");
  await expect(page.getByRole("button", { name: "5. تأكيد الفاتورة وترحيل المخزون" })).toBeDisabled();
  expect(pageErrors).toEqual([]);
});
