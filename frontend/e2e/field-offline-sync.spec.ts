import { expect, test } from "@playwright/test";

const corsHeaders = {
  "Access-Control-Allow-Origin": `http://127.0.0.1:${process.env.E2E_PORT ?? "3027"}`,
  "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
  "Access-Control-Allow-Headers": "Accept, Authorization, Content-Type, X-Device-UUID, X-Idempotency-Key",
  Vary: "Origin",
};

async function fulfillPreflight(route: import("@playwright/test").Route): Promise<boolean> {
  if (route.request().method() !== "OPTIONS") return false;
  await route.fulfill({ status: 204, headers: corsHeaders });
  return true;
}

async function syncOperationStatus(page: import("@playwright/test").Page) {
  return page.evaluate(async () => {
    const request = indexedDB.open("nexora-distribution");
    return await new Promise<string | undefined>((resolve, reject) => {
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        const transaction = database.transaction("syncOperations", "readonly");
        const get = transaction.objectStore("syncOperations").getAll();
        get.onsuccess = () => resolve(get.result[0]?.status);
        get.onerror = () => reject(get.error);
      };
    });
  });
}

test("field visit queues offline and is acknowledged after reconnect", async ({ page, context }) => {
  await page.route(/\/api\/v1\/field\/today(?:\?.*)?$/, async (route) => {
    if (await fulfillPreflight(route)) return;
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
      body: JSON.stringify({
        trip: {
          id: "trip-1",
          trip_number: "TRIP-001",
          status: "active",
          vehicle_name: "سيارة 1",
          vehicle_code: "V-01",
          vehicle_location_id: "vehicle-location-1",
        },
        customers: [{
          id: "customer-1",
          name: "عميل اختبار",
          code: "C-001",
          phone: "01000000000",
          address_text: "دمياط",
          sequence: 1,
          visit_status: "pending",
        }],
        stock: [],
      }),
    });
  });

  const apiResponse = page.waitForResponse((response) => response.url().includes("field/today"));
  await page.goto("/field");
  const fieldResponse = await apiResponse;
  expect(fieldResponse.status()).toBe(200);
  await expect(page.getByText("عميل اختبار")).toBeVisible();

  await context.setOffline(true);
  await expect(page.locator(".connection.offline")).toContainText("بدون إنترنت");

  await page.getByRole("button", { name: "تمت الزيارة" }).click();
  await expect(page.getByText("الزيارة اتحفظت على الجهاز وهتتزامن عند عودة الإنترنت.")).toBeVisible();

  await expect.poll(() => syncOperationStatus(page)).toBe("pending");

  const queued = await page.evaluate(async () => {
    const request = indexedDB.open("nexora-distribution");
    return await new Promise<{ operationUuid: string }>((resolve, reject) => {
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const database = request.result;
        const transaction = database.transaction("syncOperations", "readonly");
        const get = transaction.objectStore("syncOperations").getAll();
        get.onsuccess = () => resolve({ operationUuid: get.result[0]?.operationUuid });
        get.onerror = () => reject(get.error);
      };
    });
  });

  expect(queued.operationUuid).toBeTruthy();

  await page.route("**/sync/device", async (route) => {
    if (await fulfillPreflight(route)) return;
    const body = route.request().postDataJSON();
    expect(body.device_uuid).toBeTruthy();
    expect(body.platform).toBe("web");
    await route.fulfill({
      status: 200,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
      body: JSON.stringify({
        registered: true,
        device_uuid: body.device_uuid,
        status: "active",
      }),
    });
  });

  await page.route("**/sync/operations", async (route) => {
    if (await fulfillPreflight(route)) return;
    const request = route.request();
    const body = request.postDataJSON();
    expect(request.headers()["x-device-uuid"]).toBeTruthy();
    expect(body.operation_uuid).toBe(queued.operationUuid);
    expect(body.operation_type).toBe("field.visit");
    expect(body.schema_version).toBe(1);
    expect(body.payload.path).toBe("/field/visits");

    await route.fulfill({
      status: 202,
      headers: { ...corsHeaders, "Content-Type": "application/json" },
      body: JSON.stringify({
        status: "accepted",
        operation_uuid: body.operation_uuid,
        sync_operation_id: "sync-1",
        result_reference: null,
        result: { accepted: true },
        authoritative: true,
      }),
    });
  });

  const syncRequest = page.waitForRequest("**/sync/operations", { timeout: 10_000 });
  await context.setOffline(false);
  await page.evaluate(() => { Object.defineProperty(navigator, "onLine", { configurable: true, value: true }); window.dispatchEvent(new Event("online")); });
  await expect(page.locator(".connection")).toContainText("متصل");
  const request = await syncRequest;
  expect(request.postData()).toContain(queued.operationUuid);
  await expect.poll(() => syncOperationStatus(page), { timeout: 10_000 }).toBe("completed");
});
