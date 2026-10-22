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

function pngCrc32(bytes: Buffer): number {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) crc = (crc & 1) ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1;
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function withUniquePngText(bytes: Buffer, value: string): Buffer {
  const signature = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  if (!bytes.subarray(0, 8).equals(signature)) throw new Error("The invoice OCR E2E fixture must be a PNG.");
  let offset = 8;
  let iendOffset = -1;
  while (offset + 12 <= bytes.length) {
    const length = bytes.readUInt32BE(offset);
    const type = bytes.toString("ascii", offset + 4, offset + 8);
    if (type === "IEND") { iendOffset = offset; break; }
    offset += length + 12;
  }
  if (iendOffset < 0) throw new Error("The invoice OCR E2E fixture has no PNG IEND chunk.");
  const chunkType = Buffer.from("tEXt", "ascii");
  const chunkData = Buffer.from(`NEXORA-E2E\0${value}`, "latin1");
  const crcInput = Buffer.concat([chunkType, chunkData]);
  const chunk = Buffer.alloc(12 + chunkData.length);
  chunk.writeUInt32BE(chunkData.length, 0);
  chunkType.copy(chunk, 4);
  chunkData.copy(chunk, 8);
  chunk.writeUInt32BE(pngCrc32(crcInput), 8 + chunkData.length);
  return Buffer.concat([bytes.subarray(0, iendOffset), chunk, bytes.subarray(iendOffset)]);
}

const organizationId = localCredential("Organization ID");
const login = localCredential("Login email");
const password = localCredential("Password");

async function signIn(page: import("@playwright/test").Page, loginValue: string, passwordValue: string) {
  await page.goto(`/login?organization_id=${encodeURIComponent(organizationId!)}`);
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(loginValue);
  await page.getByLabel("كلمة المرور").fill(passwordValue);
  await page.getByLabel("اسم الجهاز").fill("NEXORA field assignment E2E");
  await page.getByRole("button", { name: "دخول" }).click();
}

test("local Alpha can create master data, post a purchase, and reconcile a sale in the UI", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha browser credentials are not configured.");
  test.setTimeout(180_000);
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
  // The catalog form now captures purchase packaging explicitly: enter the
  // supplier carton cost and the per-piece selling price instead of the old
  // generic cost field that no longer exists in the UI.
  await page.getByLabel(/تكلفة الكرتونة من المورد/).fill("17.50");
  await page.getByLabel(/سعر بيع القطعة/).fill("25.00");
  await expect(page.getByRole("button", { name: "حفظ الصنف" })).toBeEnabled();
  await page.getByRole("button", { name: "حفظ الصنف" }).click();
  await expect(page.getByText("تم إنشاء الصنف ووحدة الكرتونة بنجاح.")).toBeVisible();
  await page.getByRole("textbox", { name: "بحث في كتالوج المنتجات" }).fill(sku);
  await expect(page.getByText(productName, { exact: true })).toBeVisible();

  await page.goto("/suppliers");
  await page.getByRole("button", { name: "إضافة مورد" }).click();
  await page.getByLabel(/اسم المورد/).fill(supplierName);
  await page.getByRole("button", { name: "حفظ المورد" }).click();
  await expect(page.getByText("تمت إضافة المورد بنجاح.")).toBeVisible();
  await expect(page.getByText(supplierName, { exact: true })).toBeVisible();

  await page.goto("/customers");
  await page.getByRole("button", { name: "عميل جديد" }).click();
  await page.getByLabel("اسم العميل").fill(customerName);
  // This scenario intentionally sells partly on credit and later settles/returns
  // against the receivable; create an explicitly credit-enabled test customer.
  await page.getByLabel("حد الائتمان").fill("1000");
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

  await page.getByPlaceholder("ابحث عن المورد بالاسم أو الكود أو الهاتف...").fill(supplierName);
  const supplierResult = page.locator(".pos-customer-results button").filter({ hasText: supplierName }).first();
  await expect(supplierResult).toBeVisible();
  await supplierResult.click();

  await page.getByPlaceholder("اسم الصنف / الكود / الباركود...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  const purchaseLine = page.getByText(productName, { exact: true }).last().locator("xpath=../..");
  const purchaseInputs = purchaseLine.locator('input[type="number"]');
  await expect(purchaseInputs).toHaveCount(2);
  await purchaseInputs.nth(0).fill("17.50");
  await purchaseInputs.nth(1).fill("6");
  const purchasePostResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/purchases") && response.request().method() === "POST");
  await page.getByRole("button", { name: "تأكيد الاستلام وتحديث المخزون" }).click();
  const purchasePostResponse = await purchasePostResponsePromise;
  expect(purchasePostResponse.ok()).toBeTruthy();
  const purchaseInvoice = await purchasePostResponse.json() as { id: string; document_number: string };
  expect(purchaseInvoice.id).toBeTruthy();
  expect(purchaseInvoice.document_number).toBeTruthy();
  await expect(page.getByText(/تم استلام/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("اسم الصنف / SKU / الباركود / اسم الموقع...").fill(sku);
  const receivedRow = page.getByRole("row").filter({ hasText: sku });
  await expect(receivedRow).toBeVisible();
  await expect(receivedRow.locator("td").nth(4)).toHaveText(/6|٦/);

  // Create a trip, assign the customer, and move four units into the vehicle's stock location.
  await page.goto("/trips");
  const fieldRepName = `مندوب آلي ${stamp}`;
  const fieldRepPhone = `010900${stamp.slice(-5)}`;
  const fieldRepEmail = `field-${stamp}@e2e.test`;
  const fieldRepPassword = `Field-E2E-${stamp}!`;
  await page.getByLabel("اسم المندوب").fill(fieldRepName);
  await page.getByLabel("رقم الهاتف").fill(fieldRepPhone);
  await page.getByLabel("البريد الإلكتروني (اختياري)").fill(fieldRepEmail);
  await page.getByLabel("كلمة مرور مؤقتة").fill(fieldRepPassword);
  const fieldRepResponsePromise = page.waitForResponse((response) =>
    response.url().includes("/api/v1/field/reps") && response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "إنشاء حساب مندوب" }).click();
  const fieldRepResponse = await fieldRepResponsePromise;
  expect(fieldRepResponse.status()).toBe(201);
  const fieldRep = await fieldRepResponse.json() as { id: string; name: string };
  expect(fieldRep.id).toBeTruthy();
  await expect(page.getByText(new RegExp(`تم إنشاء حساب ${fieldRepName}`))).toBeVisible();
  await expect(page.getByLabel("المندوب المسؤول")).toHaveValue(fieldRep.id);

  const originSelect = page.getByLabel("مخزن الانطلاق");
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
  await page.getByRole("button", { name: "إنشاء رحلة جديدة وإسناد المندوب" }).click();
  const tripResponse = await tripResponsePromise;
  expect(tripResponse.ok()).toBeTruthy();
  const trip = await tripResponse.json() as { id: string; trip_number: string };
  expect(trip.id).toBeTruthy();
  await expect(page.getByText("تم إنشاء الرحلة وإسنادها للمندوب بنجاح.")).toBeVisible();

  const routeTrip = page.getByLabel("الرحلة المختارة");
  await expect(routeTrip).toHaveValue(trip.id);
  await page.getByRole("button", { name: "الخطوة 2: العملاء والزيارات" }).click();
  await page.getByPlaceholder("ابحث باسم العميل أو الهاتف أو الكود...").fill(customerName);
  const routeCustomer = page.locator(".trip-customer-results button").filter({ hasText: customerName }).first();
  await expect(routeCustomer).toBeVisible();
  await routeCustomer.click();
  await page.getByRole("button", { name: "إضافة العميل للرحلة" }).click();
  await expect(page.getByText("تم إضافة العميل للرحلة بنجاح.")).toBeVisible();

  // The separately authenticated field account must see its assigned trip only.
  await page.evaluate(() => localStorage.removeItem("nexora_token"));
  await signIn(page, fieldRepPhone, fieldRepPassword);
  await expect(page).toHaveURL(/\/field$/);
  const fieldStop = page.locator(".customer-stop").filter({ hasText: customerName });
  await expect(fieldStop).toBeVisible();
  const checkInResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/field/visits") && response.request().method() === "POST");
  await fieldStop.getByRole("button", { name: "بدء الزيارة" }).click();
  expect((await checkInResponsePromise).ok()).toBeTruthy();
  await expect(page.getByText(new RegExp(`تم بدء زيارة ${customerName}`))).toBeVisible();
  const visitCompleteResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/field/visits") && response.request().method() === "POST");
  await fieldStop.getByRole("button", { name: "تمت الزيارة" }).click();
  expect((await visitCompleteResponsePromise).ok()).toBeTruthy();
  await expect(fieldStop).toContainText("تمت الزيارة");
  await expect(page.getByText(new RegExp(`تم تحديث حالة زيارة ${customerName}`))).toBeVisible();

  // Return to the manager account to load stock, post the sale, and close the trip.
  await page.getByRole("button", { name: "تسجيل الخروج" }).click();
  await expect(page).toHaveURL(/\/login/);
  await signIn(page, login!, password!);
  await expect(page).toHaveURL(/\/$/);
  await page.goto("/trips");
  await page.getByLabel("الرحلة المختارة").selectOption(trip.id);
  await page.getByRole("textbox", { name: "البحث عن صنف للتحميل" }).fill(sku);
  const loadProduct = page.locator(".trip-load-product-card").filter({ hasText: sku });
  await expect(loadProduct).toBeVisible();
  await loadProduct.getByRole("button", { name: "إضافة للحمولة" }).click();
  await page.getByLabel(`كمية تحميل ${productName}`).fill("4");
  await page.getByRole("button", { name: "مراجعة وتأكيد تحميل العربية" }).click();
  await expect(page.locator(".trip-workflow-message").filter({ hasText: "تم تحميل العربية وتحديث المخزون بنجاح." })).toBeVisible();

  // Link the sale to this trip; the UI must force the stock location to this vehicle.
  await page.goto("/sales");
  await page.getByLabel("رحلة توزيع").selectOption(trip.id);
  await page.getByPlaceholder("اسم العميل أو رقمه...").fill(customerName);
  const saleCustomerResult = page.locator(".pos-customer-results button").filter({ hasText: customerName }).first();
  await expect(saleCustomerResult).toBeVisible();
  await saleCustomerResult.click();
  await page.getByPlaceholder("اكتب اسم الصنف أو الكود أو الباركود...").fill(sku);
  await page.getByRole("button").filter({ hasText: sku }).last().click();
  const saleLine = page.getByText(productName, { exact: true }).last().locator("xpath=../..");
  await expect(saleLine.getByLabel(`كمية ${productName}`)).toBeVisible();
  // Sell two units: collect only part of the invoice so a one-unit return is
  // covered by remaining AR, without silently creating an unapproved customer credit.
  await saleLine.getByLabel(`كمية ${productName}`).fill("2");
  await page.getByLabel("المبلغ المدفوع الآن بالجنيه").fill("5.00");
  await page.getByRole("radio", { name: /الخزينة الرئيسية/ }).click();
  await page.getByLabel("طريقة تحصيل الفاتورة").selectOption({ label: "نقدي" });
  let dropFirstSaleResponse = true;
  let firstSaleKey = "";
  await page.route("**/api/v1/sales", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    const body = route.request().postDataJSON() as { idempotency_key?: string };
    if (dropFirstSaleResponse) {
      dropFirstSaleResponse = false;
      firstSaleKey = body.idempotency_key ?? "";
      expect(firstSaleKey).toBeTruthy();
      const posted = await route.fetch(); // Commit the sale, then lose the response.
      const postedBody = await posted.text();
      expect(posted.ok(), "Sale POST rejected: HTTP " + posted.status() + " " + postedBody).toBeTruthy();
      await route.abort();
      return;
    }
    expect(body.idempotency_key).toBe(firstSaleKey);
    await route.continue();
  });
  await page.getByRole("button", { name: "تأكيد البيع وإصدار الفاتورة" }).click();
  await expect(page.getByText(/Failed to fetch|Network|network|fetch/i)).toBeVisible();
  await page.getByRole("button", { name: "تأكيد البيع وإصدار الفاتورة" }).click();
  await expect(page.getByText(/تم تسجيل/)).toBeVisible();
  await page.unroute("**/api/v1/sales");
  await expect(page.getByRole("link", { name: "فتح وطباعة الفاتورة" })).toBeVisible();
  await page.getByRole("link", { name: "فتح وطباعة الفاتورة" }).click();
  await expect(page.getByRole("heading", { name: "فاتورة العميل" })).toBeVisible();
  await expect(page.getByText(productName, { exact: true })).toBeVisible();
  const pdfDownloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "تنزيل PDF" }).click();
  const invoicePdf = await pdfDownloadPromise;
  expect(invoicePdf.suggestedFilename()).toMatch(/^SI.*\.pdf$/);

  // Allocate 20 EGP of the 45 EGP receivable and leave 25 EGP outstanding for the later 25 EGP return.
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
  await expect(page.getByLabel("المبلغ")).toHaveValue("45.00");
  await page.getByLabel("المبلغ").fill("20.00");
  await page.getByRole("button", { name: "تسجيل العملية" }).click();
  await expect(page.getByText("تم تسجيل التحصيل وتخصيصه للفاتورة وتحديث رصيد العميل.")).toBeVisible();

  // Post a trip expense before settlement; it must reduce expected cash exactly once.
  await page.goto("/trips");
  await page.getByLabel("الرحلة المختارة").selectOption(trip.id);
  await page.getByRole("button", { name: "الخطوة 3: المصروفات" }).click();
  const expenseAccount = page.getByLabel("الحساب المالي للمصروف");
  await expect(expenseAccount.locator("option").filter({ hasText: "الخزينة الرئيسية" })).toHaveCount(1);
  const expenseAccountValue = await expenseAccount.locator("option").evaluateAll((options) => {
    const match = options.find((option) => option.textContent?.includes("الخزينة الرئيسية"));
    return (match as HTMLOptionElement | undefined)?.value ?? "";
  });
  expect(expenseAccountValue).toBeTruthy();
  await expenseAccount.selectOption(expenseAccountValue);
  await page.getByLabel("نوع المصروف").fill("وقود E2E");
  await page.getByLabel("قيمة المصروف").fill("5.00");
  // Simulate a lost HTTP response after the server committed. Retrying the
  // unchanged form must reuse the same idempotency key and replay one expense.
  let dropFirstExpenseResponse = true;
  let firstExpenseKey = "";
  await page.route("**/api/v1/expenses", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    const body = route.request().postDataJSON() as { idempotency_key?: string };
    if (dropFirstExpenseResponse) {
      dropFirstExpenseResponse = false;
      firstExpenseKey = body.idempotency_key ?? "";
      await route.fetch(); // commit the expense on the API
      await route.abort(); // but simulate the response being lost in transit
      return;
    }
    expect(body.idempotency_key).toBe(firstExpenseKey);
    await route.continue();
  });
  await page.getByRole("button", { name: "حفظ المصروف" }).click();
  await expect(page.getByText(/Failed to fetch|Network|network|fetch/i)).toBeVisible();
  await page.getByRole("button", { name: "حفظ المصروف" }).click();
  await expect(page.getByText(/تم تسجيل مصروف وقود E2E بقيمة .* EGP/)).toBeVisible();
  await expect(page.getByText("وقود E2E", { exact: true })).toBeVisible();

  // Cash received was 25 EGP (5 at sale + 20 collection); the 5 EGP expense leaves 20 EGP expected.
  await page.getByRole("button", { name: "الخطوة 4: التسوية والإقفال" }).click();
  await expect(page.getByLabel(`رصيد التسوية ${productName}`)).toHaveValue("2");
  await page.getByLabel("النقدية الفعلية").fill("20.00");
  await page.getByRole("button", { name: "مراجعة وإقفال الرحلة" }).click();
  await expect(page.getByText(/تمت التسوية/)).toBeVisible();
  await expect(page.getByText(/المتوقعة 20(?:\.0+)? EGP/)).toBeVisible();
  await expect(page.getByText(/فرق النقدية 0(?:\.0+)? EGP/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("اسم الصنف / SKU / الباركود / اسم الموقع...").fill(sku);
  const finalRows = page.getByRole("row").filter({ hasText: sku });
  await expect(finalRows).toHaveCount(2);
  const closingQuantities = await finalRows.locator("td:nth-child(5)").allTextContents();
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
  await expect(page.getByLabel(`كمية مرتجع ${productName}`)).toHaveValue("0");
  await expect(page.getByText(/متبقي للإرجاع/)).toBeVisible();

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
  await page.getByPlaceholder("اسم الصنف / SKU / الباركود / اسم الموقع...").fill(sku);
  const returnedRows = page.getByRole("row").filter({ hasText: sku });
  await expect(returnedRows).toHaveCount(2);
  const returnedQuantities = await returnedRows.locator("td:nth-child(5)").allTextContents();
  expect(returnedQuantities.map((value) => value.trim().replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit))).trim()).sort()).toEqual(["2", "2"]);

  // The operator's next destinations should expose a clear summary/search path too.
  await page.goto("/payables");
  await expect(page.getByRole("heading", { name: "مستحقات الموردين" })).toBeVisible();
  await expect(page.getByLabel("بحث مستحقات الموردين")).toBeVisible();

  await page.goto(`/purchase-trace?q=${encodeURIComponent(purchaseInvoice.document_number)}`);
  await expect(page.getByRole("heading", { name: "تتبّع مصدر البضاعة" })).toBeVisible();
  await expect(page.getByLabel("بحث في تتبع فواتير الشراء")).toHaveValue(purchaseInvoice.document_number);

  await page.goto("/purchase-imports");
  await expect(page.getByText("رفع الفاتورة", { exact: true })).toBeVisible();
  await expect(page.getByText("مطابقة الأصناف", { exact: true })).toBeVisible();

  await page.goto("/trips");
  await expect(page.getByRole("button", { name: "الخطوة 1: تحميل العربية" })).toBeVisible();
  await expect(page.getByRole("button", { name: "الخطوة 2: العملاء والزيارات" })).toBeVisible();

  // Exercise adding a brand-new catalog item from an imported invoice. Seed
  // deterministic OCR text in the isolated E2E database so this test checks
  // the review/posting workflow without depending on probabilistic OCR output
  // or writing any uploaded test files into the user's real storage directory.
  await page.goto("/purchase-imports");
  const token = await page.evaluate(() => localStorage.getItem("nexora_token"));
  expect(token).toBeTruthy();
  const internalSalesImport = page.getByRole("button").filter({ hasText: "SI000041.pdf" });
  if (await internalSalesImport.count()) {
    await internalSalesImport.first().click();
    await expect(page.locator(".invoice-import-document-type-warning")).toContainText("فاتورة مبيعات داخلية، وليس فاتورة مشتريات");
    const blockedContinue = page.getByRole("button", { name: /اختر فاتورة مشتريات من المورد/ });
    if (await blockedContinue.count()) await expect(blockedContinue).toBeDisabled();
    const blockedPosting = page.getByRole("button", { name: /تأكيد الفاتورة وترحيل المخزون/ });
    if (await blockedPosting.count()) await expect(blockedPosting).toBeDisabled();
  }
  const arabicSupplierInvoice = page.getByRole("button").filter({ hasText: "Professional Arabic-English Purchase Invoice.png" });
  if (await arabicSupplierInvoice.count()) {
    await arabicSupplierInvoice.first().click();
    await expect(page.locator(".invoice-import-supplier-setup")).toBeVisible();
    await expect(page.getByLabel("اسم المورد المقروء")).toHaveValue("شركة العربي للتجارة والتوزيع");
    await expect(page.locator(".invoice-import-external-code")).toContainText("508-0001");
    await expect(page.locator(".invoice-import-line-card")).toHaveCount(6);
    await page.getByRole("button", { name: "تسجيل المورد وربطه بالفاتورة" }).click();
    await expect(page.getByText(/تم تسجيل المورد/)).toBeVisible();
    await expect(page.locator(".invoice-import-supplier-setup")).toHaveCount(0);
    await expect(page.locator(".invoice-import-line-card")).toHaveCount(6);
  }
  const e2eApiBase = `http://127.0.0.1:${process.env.E2E_API_PORT ?? "8018"}/api/v1`;
  const importListResponse = await page.request.get(`${e2eApiBase}/purchase-imports`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
  expect(importListResponse.ok()).toBeTruthy();
  const importDocuments = await importListResponse.json() as Array<{ id: string; status: string; original_name: string }>;
  let pendingImport = importDocuments.find((document) => document.status !== "posted");
  // This flow must also pass on a clean isolated E2E database; don't rely on
  // an import left behind by a different browser test or an earlier run.
  if (!pendingImport) {
    const fixture = resolve(process.cwd(), "e2e", "fixtures", "purchase-invoice-test.png");
    expect(existsSync(fixture), "The purchase invoice E2E fixture is missing.").toBeTruthy();
    await page.locator("input[type=file]").setInputFiles({
      name: `manual-review-${stamp}.png`,
      mimeType: "image/png",
      buffer: withUniquePngText(readFileSync(fixture), `manual-review-${stamp}`),
    });
    const uploadResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/purchase-imports") && response.request().method() === "POST");
    await page.getByRole("button", { name: /ارفع واقرأ/ }).click();
    const uploadResponse = await uploadResponsePromise;
    expect(uploadResponse.ok()).toBeTruthy();
    const uploadBody = await uploadResponse.json() as { documents?: Array<{ id: string; status: string; original_name: string }> };
    pendingImport = uploadBody.documents?.find((document) => document.status !== "posted");
  }
  expect(pendingImport).toBeTruthy();
  const invoiceDate = new Date().toISOString().slice(0, 10);
  const extractionResponse = await page.request.put(`${e2eApiBase}/purchase-imports/${pendingImport!.id}/extraction`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
    data: {
      extracted_text: "INVOICE NO E2E-NEW-ITEM\nProduct: Brand new test appliance\nQuantity 2\nUnit cost 50.00\nTotal 100.00",
      extraction_json: { invoice_number: "E2E-NEW-ITEM-01", invoice_date: invoiceDate, total: "100.00", lines: [], manual_review_required: true },
      ocr_engine: "playwright-deterministic-fixture",
      ocr_confidence: 99,
    },
  });
  expect(extractionResponse.ok()).toBeTruthy();
  await page.reload();
  const importSupplier = page.locator(".invoice-import-review-card .form-grid select").first();
  await expect(importSupplier).toBeVisible();
  expect(await importSupplier.locator("option").count()).toBeGreaterThan(1);
  await importSupplier.selectOption({ index: 1 });
  await page.getByLabel("رقم فاتورة المورد").fill("E2E-NEW-ITEM-01");
  await page.getByLabel("تاريخ الفاتورة").fill(invoiceDate);
  await page.getByLabel("الإجمالي النهائي من أصل الفاتورة").fill("100.00");
  await page.locator(".invoice-import-line-add-actions").getByRole("button", { name: "صنف جديد" }).click();
  await page.getByLabel("اسم الصنف كما هو مكتوب في فاتورة المورد").fill("Brand new test appliance");
  await page.getByLabel("اسم الصنف في الكتالوج (عربي)").fill("جهاز جديد من فاتورة E2E");
  const newProductSku = `E2E-OCR-${Date.now()}`;
  await page.getByLabel("الكود الداخلي SKU (اختياري)").fill(newProductSku);
  await page.getByLabel("كود المورد / الباركود").fill("SUP-E2E-NEW-001");
  await page.getByLabel("الوحدة الأساسية").selectOption({ index: 1 });
  await page.getByLabel("كمية بند 1").fill("2");
  await page.getByLabel("تكلفة بند 1").fill("50.00");

  const newImportPostPromise = page.waitForResponse((response) => response.url().includes("/api/v1/purchases") && response.request().method() === "POST");
  await page.getByRole("button", { name: /تأكيد الفاتورة وترحيل المخزون/ }).click();
  const newImportPost = await newImportPostPromise;
  expect(newImportPost.ok()).toBeTruthy();
  const newImportResult = await newImportPost.json() as { id: string; document_number: string; created_products: Array<{ sku: string; name_ar: string }> };
  expect(newImportResult.created_products).toHaveLength(1);
  expect(newImportResult.created_products[0].sku).toBe(newProductSku);
  await expect(page.getByText(/تم إنشاء 1 صنف جديد في الكتالوج/)).toBeVisible();

  await page.goto("/inventory");
  await page.getByPlaceholder("اسم الصنف / SKU / الباركود / اسم الموقع...").fill(newProductSku);
  const newStockRow = page.getByRole("row").filter({ hasText: newProductSku });
  await expect(newStockRow).toBeVisible();
  const newStockQuantity = await newStockRow.locator("td:nth-child(5)").textContent();
  expect((newStockQuantity || "").trim().replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))).toBe("2");

  // The reports screen must use the period endpoint, not the daily dashboard
  // snapshot, and show the sale created earlier in the same browser workflow.
  const reportsResponsePromise = page.waitForResponse((response) =>
    response.url().includes("/api/v1/reports?") && response.request().method() === "GET",
  );
  await page.goto("/reports");
  const reportsResponse = await reportsResponsePromise;
  expect(reportsResponse.ok()).toBeTruthy();
  const reportsPayload = await reportsResponse.json() as {
    summary: { sales_total: number; purchases_total: number };
    daily: Array<{ date: string }>;
    top_products: Array<{ sku: string }>;
  };
  expect(reportsPayload.summary.sales_total).toBeGreaterThan(0);
  expect(reportsPayload.summary.purchases_total).toBeGreaterThan(0);
  expect(reportsPayload.daily.length).toBeGreaterThan(0);
  expect(reportsPayload.top_products.length).toBeGreaterThan(0);
  await expect(page.getByRole("heading", { name: "التقارير" })).toBeVisible();
  await expect(page.getByLabel("من تاريخ")).toHaveValue(/\d{4}-\d{2}-\d{2}/);
  await expect(page.getByLabel("إلى تاريخ")).toHaveValue(/\d{4}-\d{2}-\d{2}/);
  await expect(page.locator(".reports-metric").filter({ hasText: "صافي الربح التشغيلي" })).toBeVisible();
  await expect(page.locator(".reports-products-table tbody tr")).not.toHaveCount(0);
  await expect(page.getByText("ملاحظات مهمة على الأرقام")).toBeVisible();
});
