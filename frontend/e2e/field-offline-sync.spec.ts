import { expect, test } from "@playwright/test";

test("field visit queues offline and is acknowledged after reconnect", async ({ page, context }) => {
  await page.route("**/api/v1/field/today", async (route) => {
    await route.fulfill({
      status: 200,
      contentType: "application/json",
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

  await page.goto("/field");
  await expect(page.getByText("عميل اختبار")).toBeVisible();

  await context.setOffline(true);
  await page.getByRole("button", { name: "تمت" }).click();
  await expect(page.getByText("تم حفظ الزيارة محليًا وسيتم إرسالها عند عودة الإنترنت.")).toBeVisible();

  const queued = await page.evaluate(async () => {
    const request = indexedDB.open("nexora-distribution");
    return await new Promise<{ status: string; operationUuid: string }>((resolve, reject) => {
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const db = request.result;
        const tx = db.transaction("syncOperations", "readonly");
        const get = tx.objectStore("syncOperations").getAll();
        get.onsuccess = () => resolve({
          status: get.result[0]?.status,
          operationUuid: get.result[0]?.operationUuid,
        });
        get.onerror = () => reject(get.error);
      };
    });
  });

  expect(queued.status).toBe("pending");
  expect(queued.operationUuid).toBeTruthy();

  await page.route("**/api/v1/sync/operations", async (route) => {
    const request = route.request();
    const body = request.postDataJSON();
    expect(request.headers()["x-device-uuid"]).toBeTruthy();
    expect(body.operation_uuid).toBe(queued.operationUuid);
    expect(body.operation_type).toBe("POST");
    expect(body.schema_version).toBe(1);
    expect(body.payload.path).toBe("/field/visits");
    await route.fulfill({
      status: 202,
      contentType: "application/json",
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

  await context.setOffline(false);
  await page.waitForTimeout(1500);

  const completed = await page.evaluate(async () => {
    const request = indexedDB.open("nexora-distribution");
    return await new Promise<string>((resolve, reject) => {
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        const db = request.result;
        const tx = db.transaction("syncOperations", "readonly");
        const get = tx.objectStore("syncOperations").getAll();
        get.onsuccess = () => resolve(get.result[0]?.status);
        get.onerror = () => reject(get.error);
      };
    });
  });

  expect(completed).toBe("completed");
});
