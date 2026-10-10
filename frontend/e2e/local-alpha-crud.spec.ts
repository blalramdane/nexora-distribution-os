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
  test.setTimeout(120_000);
  page.setDefaultNavigationTimeout(60_000);
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
  await purchaseInputs.nth(0).fill("5");
  await purchaseInputs.nth(1).fill("1");
  await purchaseInputs.nth(2).fill("17.50");
  await page.getByRole("button", { name: "استلام الفاتورة" }).click();
  await expect(page.getByText(/تم استلام/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("SKU / اسم الصنف / الموقع...").fill(sku);
  const receivedRow = page.getByRole("row").filter({ hasText: sku });
  await expect(receivedRow).toBeVisible();
  await expect(receivedRow.locator("td").nth(3)).toHaveText(/5|٥/);

  // Create a trip, assign the customer, and move three units into the vehicle's stock location.
  await page.goto("/trips");
  const originSelect = page.locator("select").nth(1);
  await expect(originSelect.locator("option").filter({ hasText: "المخزن الرئيسي" })).toHaveCount(1);
  const originWarehouse = await originSelect.locator("option").evaluateAll((options) => {
    const match = options.find((option) => option.textContent?.includes("المخزن الرئيسي"));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  });
  expect(originWarehouse).toBeTruthy();
  await originSelect.selectOption(originWarehouse);
  const tripResponsePromise = page.waitForResponse((response) =>
    response.url().includes("/api/v1/trips") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "إنشاء رحلة اليوم" }).click();
  const tripResponse = await tripResponsePromise;
  expect(tripResponse.ok()).toBeTruthy();
  const trip = await tripResponse.json() as { id: string; trip_number: string };
  expect(trip.id).toBeTruthy();
  await expect(page.getByText("تم إنشاء الرحلة.")).toBeVisible();

  const routeTrip = page.locator("select").nth(2);
  await expect(routeTrip).toHaveValue(trip.id);
  const routeCustomer = page.locator("select").nth(3);
  const customerOption = await routeCustomer.locator("option").evaluateAll((options, name) => {
    const match = options.find((option) => option.textContent?.includes(String(name)));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  }, customerName);
  expect(customerOption).toBeTruthy();
  await routeCustomer.selectOption(customerOption);
  await page.getByRole("button", { name: "إضافة للرحلة" }).click();
  await expect(page.getByText("تم إضافة العميل للرحلة.")).toBeVisible();

  // The assigned field representative must be able to check in and complete the visit.
  await page.goto("/field");
  const fieldStop = page.locator(".customer-stop").filter({ hasText: customerName });
  await expect(fieldStop).toBeVisible();
  const checkInResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/field/visits") && response.request().method() === "POST");
  await fieldStop.getByRole("button", { name: "وصول" }).click();
  expect((await checkInResponsePromise).ok()).toBeTruthy();
  await expect(page.getByText("تم تحديث الزيارة")).toBeVisible();
  const visitCompleteResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/field/visits") && response.request().method() === "POST");
  await fieldStop.getByRole("button", { name: "تمت" }).click();
  expect((await visitCompleteResponsePromise).ok()).toBeTruthy();
  await expect(fieldStop).toContainText("تمت");
  await expect(page.getByText("تم تحديث الزيارة")).toBeVisible();

  await page.goto("/trips");
  await page.locator("select").nth(2).selectOption(trip.id);
  await page.getByPlaceholder("ابحث بالصنف أو SKU للتحميل...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  await page.getByLabel(`كمية تحميل ${productName}`).fill("3");
  await page.getByRole("button", { name: "تحميل العربية" }).click();
  await expect(page.getByText("تم تحميل العربية وتحديث المخزون.")).toBeVisible();

  // Link the sale to this trip; the UI must force the stock location to this vehicle.
  await page.goto("/sales");
  await page.getByLabel("رحلة التوزيع").selectOption(trip.id);
  await page.getByPlaceholder("ابحث بالاسم أو الهاتف أو كود العميل...").fill(customerName);
  await page.getByRole("button").filter({ hasText: customerName }).first().click();
  await page.getByPlaceholder("اسم الصنف / SKU / barcode...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  const saleLine = page.getByText(productName, { exact: true }).last().locator("xpath=../..");
  await expect(saleLine.locator('input[type="number"]')).toHaveCount(1);
  await saleLine.locator('input[type="number"]').fill("1");
  await page.getByLabel("المدفوع الآن").fill("5.00");
  await page.getByRole("button", { name: "تسجيل البيع" }).click();
  await expect(page.getByText(/تم تسجيل/)).toBeVisible();

  // Allocate the outstanding 20 EGP to the invoice and include it in this trip's cash settlement.
  await page.goto("/payments");
  const partySelect = page.getByLabel("الطرف");
  await expect(partySelect.locator("option").filter({ hasText: customerName })).toHaveCount(1);
  const customerParty = await partySelect.locator("option").evaluateAll((options, name) => {
    const match = options.find((option) => option.textContent?.includes(String(name)));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  }, customerName);
  expect(customerParty).toBeTruthy();
  await partySelect.selectOption(customerParty);
  const invoiceSelect = page.getByLabel("الفاتورة المستحقة");
  await expect(invoiceSelect.locator("option")).toHaveCount(2);
  const invoiceId = await invoiceSelect.locator("option").nth(1).getAttribute("value");
  expect(invoiceId).toBeTruthy();
  await invoiceSelect.selectOption(invoiceId!);
  await page.getByLabel("طريقة الدفع").selectOption({ label: "نقدي" });
  await page.getByLabel("رحلة التحصيل").selectOption(trip.id);
  await expect(page.getByLabel("المبلغ")).toHaveValue("20.00");
  await page.getByRole("button", { name: "تسجيل العملية" }).click();
  await expect(page.getByText("تم تسجيل التحصيل وتخصيصه للفاتورة وتحديث رصيد العميل.")).toBeVisible();

  // Reload the trip, verify the physical closing balance, and settle the 25 EGP collected.
  await page.goto("/trips");
  await page.locator("select").nth(2).selectOption(trip.id);
  await expect(page.getByLabel(`رصيد التسوية ${productName}`)).toHaveValue("2");
  await page.getByLabel("النقدية الفعلية").fill("25.00");
  await page.getByRole("button", { name: "إغلاق وتسوية الرحلة" }).click();
  await expect(page.getByText(/تمت التسوية/)).toBeVisible();
  await expect(page.getByText(/فرق النقدية 0(?:\.0+)? EGP/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("SKU / اسم الصنف / الموقع...").fill(sku);
  const finalRows = page.getByRole("row").filter({ hasText: sku });
  await expect(finalRows).toHaveCount(2);
  const closingQuantities = await finalRows.locator("td:nth-child(4)").allTextContents();
  expect(closingQuantities.map((value) => value.trim().replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit))).trim()).sort()).toEqual(["2", "2"]);

  // Returns must select the original posted invoice and one of its own lines.
  // Receive the returned unit into the warehouse after the trip has been settled.
  await page.goto("/returns");
  const returnCustomer = page.getByLabel("العميل");
  await expect(returnCustomer.locator("option").filter({ hasText: customerName })).toHaveCount(1);
  const returnCustomerId = await returnCustomer.locator("option").evaluateAll((options, name) => {
    const match = options.find((option) => option.textContent?.includes(String(name)));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  }, customerName);
  expect(returnCustomerId).toBeTruthy();
  await returnCustomer.selectOption(returnCustomerId);
  await page.getByLabel("موقع استلام المرتجع").selectOption(warehouseValue);

  const returnInvoice = page.getByLabel("فاتورة البيع الأصلية");
  await expect(returnInvoice.locator("option")).toHaveCount(2);
  await returnInvoice.selectOption({ index: 1 });
  const returnQuantity = page.getByLabel(`كمية مرتجع ${productName}`);
  await expect(returnQuantity).toHaveValue("0");
  await returnQuantity.fill("1");
  await page.getByRole("button", { name: "تسجيل المرتجع المرتبط بالفاتورة" }).click();
  await expect(page.getByText(/تم تسجيل المرتجع/)).toBeVisible();
  await expect(page.getByText("تم إرجاع كل البنود القابلة للإرجاع من هذه الفاتورة.")).toBeVisible();

  // Return another unit to the supplier against the original purchase invoice.
  const purchaseReturnSupplier = page.getByLabel("مورد المرتجع");
  await expect(purchaseReturnSupplier.locator("option").filter({ hasText: supplierName })).toHaveCount(1);
  const purchaseReturnSupplierId = await purchaseReturnSupplier.locator("option").evaluateAll((options, name) => {
    const match = options.find((option) => option.textContent?.includes(String(name)));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  }, supplierName);
  expect(purchaseReturnSupplierId).toBeTruthy();
  await purchaseReturnSupplier.selectOption(purchaseReturnSupplierId);
  await page.getByLabel("مخزن مرتجع المشتريات").selectOption(warehouseValue);

  const purchaseReturnInvoice = page.getByLabel("فاتورة الشراء الأصلية");
  await expect(purchaseReturnInvoice.locator("option").filter({ hasText: supplierName })).toHaveCount(0);
  await expect(purchaseReturnInvoice.locator("option").filter({ hasText: sku })).toHaveCount(0);
  await expect(purchaseReturnInvoice.locator("option").nth(1)).toContainText(/PI\d+/);
  await purchaseReturnInvoice.selectOption({ index: 1 });
  const purchaseReturnQuantity = page.getByLabel(`كمية مرتجع مشتريات ${productName}`);
  await expect(purchaseReturnQuantity).toHaveValue("0");
  await purchaseReturnQuantity.fill("1");
  await page.getByRole("button", { name: "تسجيل مرتجع المشتريات" }).click();
  await expect(page.getByText(/تم تسجيل مرتجع المشتريات/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("SKU / اسم الصنف / الموقع...").fill(sku);
  const returnedRows = page.getByRole("row").filter({ hasText: sku });
  await expect(returnedRows).toHaveCount(2);
  const returnedQuantities = await returnedRows.locator("td:nth-child(4)").allTextContents();
  expect(returnedQuantities.map((value) => value.trim().replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit))).trim()).sort()).toEqual(["2", "2"]);
});
