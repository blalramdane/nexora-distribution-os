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
const invoiceFixture = resolve(process.cwd(), "e2e", "fixtures", "purchase-invoice-test.png");

test("OCR purchase import can be reviewed, posted once, and replayed without duplicating stock", async ({ page }) => {
  test.skip(!organizationId || !login || !password, "Local Alpha browser credentials are not configured.");
  test.skip(!existsSync(invoiceFixture), "The committed purchase invoice OCR fixture is missing.");
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(60_000);

  const stamp = Date.now().toString();
  const supplierName = `مورد اختبار OCR ${stamp}`;
  const pageErrors: string[] = [];
  const failedRequests: string[] = [];
  const extractionRequests: string[] = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  page.on("requestfailed", (request) => failedRequests.push(`${request.method()} ${request.url()}: ${request.failure()?.errorText || "failed"}`));
  page.on("request", (request) => { if (request.url().includes("/purchase-imports/") && request.url().includes("/extraction")) extractionRequests.push(`${request.method()} ${request.url()}`); });

  await page.goto("/login");
  await page.getByLabel("Organization ID").fill(organizationId!);
  await page.getByLabel("الإيميل أو الهاتف").fill(login!);
  await page.getByLabel("كلمة المرور").fill(password!);
  await page.getByLabel("اسم الجهاز").fill("NEXORA Purchase Import Posting E2E");
  await page.getByRole("button", { name: "دخول" }).click();
  await expect(page).toHaveURL(/\/$/);

  await page.goto("/purchase-imports");
  await page.locator("input[type=file]").setInputFiles({
    name: `purchase-invoice-test-${stamp}.png`,
    mimeType: "image/png",
    buffer: withUniquePngText(readFileSync(invoiceFixture), stamp),
  });
  const uploadButton = page.getByRole("button", { name: /ارفع واقرأ/ });
  await expect(uploadButton).toBeEnabled();
  const uploadResponsePromise = page.waitForResponse((response) => response.url().includes("/api/v1/purchase-imports") && response.request().method() === "POST");
  await uploadButton.click();
  const uploadResponse = await uploadResponsePromise;
  const uploadBody = await uploadResponse.json() as { documents?: Array<{ id: string; status: string; purchase_invoice_id?: string | null; extracted_text?: string | null; duplicate?: boolean }> };
  console.log("OCR upload response", JSON.stringify(uploadBody.documents?.map(({ id, status, purchase_invoice_id, extracted_text, duplicate }) => ({ id, status, hasPurchaseInvoice: Boolean(purchase_invoice_id), hasExtractedText: Boolean(extracted_text), duplicate }))));

  // Exercise the browser's actual Tesseract OCR worker and the real isolated
  // Laravel upload/extraction endpoints; no API mocks are used in this test.
  try {
    await expect(page.getByRole("status").filter({ hasText: "اكتملت القراءة" })).toBeVisible({ timeout: 30_000 });
  } catch (error) {
    console.log("OCR diagnostics", JSON.stringify({ pageErrors, failedRequests, extractionRequests, state: await page.locator("body").innerText().then((text) => text.slice(-2000)) }));
    throw error;
  }

  await expect(page.getByLabel("رقم فاتورة المورد")).toHaveValue(/TEST-P0O-2026-001/);
  await expect(page.locator("input[type=date]")).toHaveValue("2026-10-17");
  await expect(page.getByLabel("الإجمالي النهائي من أصل الفاتورة")).toHaveValue("4650.00");
  await expect(page.getByLabel("المخزن المستلم")).not.toHaveValue("");

  const lines = page.locator(".invoice-import-line-card");
  await expect(lines).toHaveCount(3);
  const expectedLines = [
    { sku: "ELE-FAN-18", nameAr: "مروحة ستاند 18 بوصة", nameEn: "Stand Fan 18 inch", quantity: "2", cost: "1250.00" },
    { sku: "ELK-KET-17", nameAr: "غلاية كهربائية 1.7 لتر", nameEn: "Electric Kettle 1.7 Liter", quantity: "3", cost: "420.00" },
    { sku: "EIR-DRY-22", nameAr: "مكواة بخار 2200 وات", nameEn: "Steam Iron 2200 Watt", quantity: "1", cost: "890.00" },
  ];
  for (let index = 0; index < expectedLines.length; index += 1) {
    const card = lines.nth(index);
    const line = expectedLines[index];
    // Keep this test repeatable even when the isolated E2E database already
    // contains catalog matches from a prior run. The scenario under test is
    // atomic creation plus idempotent receipt, so explicitly choose new items.
    const newProductMode = card.getByRole("group", { name: `نوع البند ${index + 1}` }).getByRole("button", { name: "صنف جديد" });
    if (await newProductMode.getAttribute("aria-pressed") !== "true") await newProductMode.click();
    const supplierSku = card.getByLabel("كود المورد / الباركود");
    await supplierSku.fill(line.sku);
    await expect(supplierSku).toHaveValue(line.sku);
    const arabicName = card.getByLabel("اسم الصنف في الكتالوج (عربي)");
    const suggestedArabicName = (await arabicName.inputValue()).trim();
    if (/[A-Za-z]/.test(suggestedArabicName)) expect(suggestedArabicName).toBe("");
    if (suggestedArabicName !== line.nameAr) {
      // Any uncertain/missing Arabic canonical name must be supplied by the
      // operator before the invoice can create a catalog item.
      await arabicName.fill(line.nameAr);
    }
    await expect(arabicName).toHaveValue(line.nameAr);
    await expect(card.getByLabel("الاسم بالإنجليزية (اختياري)")).toHaveValue(line.nameEn);
    await expect(card.getByLabel(`كمية بند ${index + 1}`)).toHaveValue(line.quantity);
    await expect(card.getByLabel(`تكلفة بند ${index + 1}`)).toHaveValue(line.cost);
  }
  await expect(page.locator("strong").filter({ hasText: /(?:٤٬٦٥٠٫٠٠|4,650\.00)\s*EGP/ })).toBeVisible();

  // The OCR supplier name is intentionally treated as untrusted text. Enter a
  // clear, synthetic test supplier before creating any ledger transaction.
  await page.getByLabel("اسم المورد المقروء").fill(supplierName);
  await page.getByRole("button", { name: "تسجيل المورد وربطه بالفاتورة" }).click();
  await expect(page.getByText(new RegExp(`تم تسجيل المورد «${supplierName}»`))).toBeVisible();

  await page.getByRole("button", { name: "حفظ المراجعة" }).click();
  await expect(page.getByText("تم حفظ بيانات المراجعة على مستند الفاتورة.")).toBeVisible();

  const purchaseRequestPromise = page.waitForRequest((request) =>
    request.url().includes("/api/v1/purchases") && request.method() === "POST",
  );
  const purchaseResponsePromise = page.waitForResponse((response) =>
    response.url().includes("/api/v1/purchases") && response.request().method() === "POST",
  );
  const postButton = page.getByRole("button", { name: "5. تأكيد الفاتورة وترحيل المخزون" });
  await expect(postButton).toBeEnabled();
  await postButton.click();
  const [purchaseRequest, purchaseResponse] = await Promise.all([purchaseRequestPromise, purchaseResponsePromise]);
  expect(purchaseResponse.status()).toBe(201);
  const purchasePayload = purchaseRequest.postDataJSON() as Record<string, unknown>;
  const purchase = await purchaseResponse.json() as {
    id: string;
    document_number: string;
    total: number | string;
    created_products: Array<{ id: string; sku: string; name_ar: string }>;
  };
  expect(purchase.id).toBeTruthy();
  expect(purchase.document_number).toMatch(/^PI/);
  expect(Number(purchase.total)).toBe(4650);
  expect(purchase.created_products).toHaveLength(3);
  await expect(page.getByText(new RegExp(`تم ترحيل ${purchase.document_number}`))).toBeVisible();
  await expect(postButton).toHaveCount(0);

  // Simulate a client retry after the server committed the purchase. Replaying
  // exactly the same idempotency key/payload must return the original invoice.
  const apiBase = `http://127.0.0.1:${process.env.E2E_API_PORT ?? "8018"}/api/v1`;
  const replay = await page.evaluate(async ({ payload, url }) => {
    const token = localStorage.getItem("nexora_token");
    const response = await fetch(`${url}/purchases`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify(payload),
    });
    return { status: response.status, body: await response.json() };
  }, { payload: purchasePayload, url: apiBase });
  expect(replay.status).toBe(201);
  expect(replay.body.id).toBe(purchase.id);
  expect(replay.body.document_number).toBe(purchase.document_number);

  // Verify every new product's persisted stock balance after the retry. Each
  // SKU must have exactly the quantity in the invoice, not twice that amount.
  const stock = await page.evaluate(async ({ url, skus }) => {
    const token = localStorage.getItem("nexora_token");
    const results = [] as Array<{ sku: string; rows: Array<{ sku: string; name_ar: string; quantity_base: string | number; location_name: string }> }>;
    for (const sku of skus) {
      const response = await fetch(`${url}/inventory?search=${encodeURIComponent(sku)}&per_page=50`, {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!response.ok) throw new Error(`Inventory lookup failed for ${sku}: HTTP ${response.status}`);
      const body = await response.json();
      results.push({ sku, rows: body.data ?? [] });
    }
    return results;
  }, { url: apiBase, skus: purchase.created_products.map((item) => item.sku) });

  expect(stock).toHaveLength(3);
  for (const entry of stock) {
    const lineIndex = purchase.created_products.findIndex((item) => item.sku === entry.sku);
    expect(entry.rows).toHaveLength(1);
    expect(entry.rows[0].sku).toBe(entry.sku);
    expect(Number(entry.rows[0].quantity_base)).toBe(Number(expectedLines[lineIndex].quantity));
  }
  expect(pageErrors).toEqual([]);
});
