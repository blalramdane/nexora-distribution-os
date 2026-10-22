import { expect, test } from "@playwright/test";

const corsHeaders = {
  "Access-Control-Allow-Origin": `http://127.0.0.1:${process.env.E2E_PORT ?? "3027"}`,
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Access-Control-Allow-Headers": "Accept, Authorization, Content-Type, X-Device-UUID, X-Idempotency-Key",
  Vary: "Origin",
};

async function fulfillApi(route: import("@playwright/test").Route, body: unknown, status = 200) {
  if (route.request().method() === "OPTIONS") {
    await route.fulfill({ status: 204, headers: corsHeaders });
    return;
  }
  await route.fulfill({
    status,
    headers: { ...corsHeaders, "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
}

test("field portal shows visit completion progress for the assigned trip", async ({ page }) => {
  await page.route(/\/api\/v1\/field\/today(?:\?.*)?$/, (route) => fulfillApi(route, {
    trip: {
      id: "trip-progress-1",
      trip_number: "TR-PORTAL-001",
      status: "loaded",
      vehicle_name: "سيارة المندوب",
      vehicle_code: "V-01",
      vehicle_location_id: "vehicle-location-1",
    },
    customers: [
      { id: "customer-done", name: "عميل تمت زيارته", code: "CUS-01", sequence: 1, visit_status: "visited" },
      { id: "customer-pending", name: "عميل في الانتظار", code: "CUS-02", sequence: 2, visit_status: "planned" },
    ],
    stock: [],
  }));

  await page.goto("/field");
  await expect(page.getByRole("heading", { name: "مساحة عمل المندوب" })).toBeVisible();
  await expect(page.getByText("TR-PORTAL-001 · سيارة المندوب", { exact: true })).toBeVisible();
  await expect(page.getByRole("progressbar", { name: "نسبة إنجاز زيارات الرحلة" })).toHaveAttribute("aria-valuenow", "50");
  await expect(page.getByText(/ليست عمولة مبيعات/)).toBeVisible();
});

test("field-rep login sends the account to the field portal instead of the admin dashboard", async ({ page }) => {
  await page.route(/\/api\/v1\/auth\/login(?:\?.*)?$/, (route) => fulfillApi(route, {
    token: "field-e2e-token",
    token_type: "Bearer",
    user: {
      id: "field-user-1",
      organization_id: "org-field-1",
      name: "مندوب اختبار",
      roles: [{ id: "role-field", key: "field_rep", name: "مندوب ميداني" }],
      permissions: [{ key: "field.visit" }, { key: "field.sync" }],
    },
  }));
  await page.route(/\/api\/v1\/field\/today(?:\?.*)?$/, (route) => fulfillApi(route, {
    trip: null,
    customers: [],
    stock: [],
  }));

  await page.goto("/login?organization_id=org-field-1");
  await expect(page.getByLabel("Organization ID")).toHaveValue("org-field-1");
  await page.getByLabel("الإيميل أو الهاتف").fill("01090001111");
  await page.getByLabel("كلمة المرور").fill("FieldRepTest!2026");
  await page.getByLabel("اسم الجهاز").fill("Field Portal Playwright");
  await page.getByRole("button", { name: "دخول" }).click();

  await expect(page).toHaveURL(/\/field$/);
  await expect(page.getByRole("heading", { name: "مساحة عمل المندوب" })).toBeVisible();
  await expect(page.getByRole("heading", { name: "لوحة التحكم" })).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem("nexora_organization_id"))).toBe("org-field-1");
});

test("field workspace makes product search, trip expenses, and vehicle stocktake easy to reach", async ({ page }) => {
  const tripId = "trip-tools-1";
  const customerPricesRelease: { current: () => void } = { current: () => {} };
  const customerPricesGate = new Promise<void>((resolve) => { customerPricesRelease.current = resolve; });
  let visitStatus: "planned" | "checked_in" | "visited" | "skipped" = "planned";
  await page.route(/\/api\/v1\/field\/today(?:\?.*)?$/, (route) => fulfillApi(route, {
    trip: { id: tripId, trip_number: "TR-TOOLS-001", status: "loaded", vehicle_id: "vehicle-1", vehicle_name: "عربية المندوب", vehicle_code: "V-01", vehicle_location_id: "vehicle-location-1" },
    customers: [{ id: "customer-1", name: "عميل اختبار", code: "CUS-01", sequence: 1, visit_status: visitStatus }],
    stock: [{ id: "product-1", sku: "SKU-01", name_ar: "مروحة اختبار", quantity_base: 5 }],
  }));
  await page.route(/\/api\/v1\/field\/catalog(?:\?.*)?$/, (route) => fulfillApi(route, {
    data: [{ id: "product-1", sku: "SKU-01", barcode: "622123", name_ar: "مروحة اختبار", name_en: "Test Fan", base_unit_id: "unit-1", base_unit_name: "قطعة", quantity_base: 5, packagings: [] }],
    current_page: 1,
    last_page: 1,
    total: 1,
  }));
  await page.route(/\/api\/v1\/field\/references(?:\?.*)?$/, (route) => fulfillApi(route, {
    accounts: [{ id: "account-1", name: "خزنة العربية", currency: "EGP" }],
    payment_methods: [{ id: "method-1", name_ar: "نقدي" }],
  }));
  await page.route(/\/api\/v1\/field\/customer-prices(?:\?.*)?$/, async (route) => {
    await customerPricesGate;
    await fulfillApi(route, [
      { id: "saved-price-1", customer_id: "customer-1", product_id: "product-1", packaging_id: null, unit_key: "base", unit_price: "95.0000" },
    ]);
  });
  await page.route(/\/api\/v1\/field\/expenses(?:\?.*)?$/, (route) => fulfillApi(route, []));
  await page.route(/\/api\/v1\/field\/stocktake(?:\?.*)?$/, async (route) => {
    if (route.request().method() === "POST") {
      await fulfillApi(route, { id: "stock-count-1", trip_id: tripId, status: "submitted", item_count: 1, variance_items_count: 1, items: [], submitted_at: "2026-10-18T03:00:00Z" }, 201);
      return;
    }
    await fulfillApi(route, { trip_id: tripId, items: [{ product_id: "product-1", sku: "SKU-01", name_ar: "مروحة اختبار", base_unit_name: "قطعة", expected_quantity_base: 5 }], history: [] });
  });
  await page.route(/\/api\/v1\/expenses(?:\?.*)?$/, (route) => fulfillApi(route, { id: "expense-1", category: "وقود", amount: 80 }, 201));
  await page.route(/\/api\/v1\/field\/visits(?:\?.*)?$/, async (route) => {
    if (route.request().method() === "POST") {
      const body = route.request().postDataJSON() as { status?: typeof visitStatus };
      if (body.status) visitStatus = body.status;
    }
    await fulfillApi(route, { status: "ok" });
  });
  let saleBody: Record<string, unknown> | undefined;
  await page.route("**/api/v1/sales**", async (route) => {
    if (route.request().method() !== "POST") return fulfillApi(route, []);
    saleBody = route.request().postDataJSON() as Record<string, unknown>;
    await fulfillApi(route, { id: "sale-customer-price-1", document_number: "SI-PRICE-001", total: "200.0000", paid_amount: "0.0000", balance_due: "200.0000", status: "posted" }, 201);
  });

  await page.goto("/field");
  await expect(page.getByRole("heading", { name: "مساحة عمل المندوب" })).toBeVisible();

  await page.locator(".field-workspace-tab").filter({ hasText: "الأصناف" }).click();
  await page.getByLabel("بحث كتالوج العربية").fill("SKU-01");
  await expect(page.getByText("مروحة اختبار", { exact: true })).toBeVisible();
  await expect(page.getByText(/622123/)).toBeVisible();

  await page.locator(".field-workspace-tab").filter({ hasText: "جرد العربية" }).click();
  const countInput = page.getByLabel("الكمية المعدودة مروحة اختبار");
  await countInput.fill("4");
  await expect(page.locator(".field-stocktake-count .has-variance")).toContainText("الفرق:");
  await page.getByRole("button", { name: /إرسال الجرد للمراجعة/ }).click();
  await expect(page.getByText(/تم إرسال الجرد للمراجعة/)).toBeVisible();
  await expect(page.getByText(/لن يتم تعديل المخزون/)).toBeVisible();

  await page.locator(".field-workspace-tab").filter({ hasText: "المصروفات" }).click();
  await page.getByLabel("قيمة المصروف").fill("80");
  await page.getByRole("button", { name: "تسجيل المصروف" }).click();
  await expect(page.getByText(/تم تسجيل وقود بقيمة/)).toBeVisible();

  await page.locator(".field-workspace-tab").filter({ hasText: "العملاء" }).click();
  await page.getByRole("button", { name: "بدء الزيارة" }).click();
  await page.getByLabel("بحث صنف البيع").fill("SKU-01");
  await page.locator(".field-catalog-result").filter({ hasText: "SKU-01" }).click();
  const customerSalePrice = page.getByLabel("سعر البيع للعميل");
  await expect(customerSalePrice).toHaveValue("");
  customerPricesRelease.current();
  await expect(customerSalePrice).toHaveValue("95");
  await expect(page.getByText(/تم ملء السعر المحفوظ لهذا العميل والوحدة/)).toBeVisible();
  await customerSalePrice.fill("١٠٠");
  await expect(customerSalePrice).toHaveValue("100");
  await page.getByLabel("كمية البيع").fill("٢");
  await page.getByLabel("طريقة السداد").selectOption("cash");
  await expect.poll(async () => (await page.locator(".field-line-total strong").innerText())
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))).toContain("200 · 0");
  await expect(page.getByLabel("كمية البيع")).toHaveValue("2");
  await expect.poll(async () => (await page.locator(".field-line-total strong").innerText())
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0))).toContain("200");
  const saleResponse = page.waitForResponse((response) => response.url().includes("/api/v1/sales") && response.request().method() === "POST");
  await page.getByRole("button", { name: "تسجيل البيع" }).click();
  const postedResponse = await saleResponse;
  const postedPayload = postedResponse.request().postDataJSON() as Record<string, unknown>;
  expect(postedResponse.status()).toBe(201);
  expect((await postedResponse.json()).document_number).toBe("SI-PRICE-001");
  expect(postedPayload.customer_id).toBe("customer-1");
  expect(postedPayload.paid_amount).toBe(200);
  expect(postedPayload.financial_account_id).toBeTruthy();
  expect(postedPayload.payment_method_id).toBeTruthy();
  const saleItems = postedPayload.items as Array<{ unit_price: number; quantity: number }>;
  expect(saleItems[0].unit_price).toBe(100);
  expect(saleItems[0].quantity).toBe(2);
});
