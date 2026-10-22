import { expect, test } from "@playwright/test";

test("publishes a valid Arabic standalone PWA manifest", async ({ request }) => {
  const response = await request.get("/manifest.webmanifest");
  expect(response.ok()).toBeTruthy();

  const manifest = await response.json();
  expect(manifest.name).toBe("NEXORA Distribution OS");
  expect(manifest.short_name).toBe("NEXORA");
  expect(manifest.start_url).toBe("/field");
  expect(manifest.display).toBe("standalone");
  expect(manifest.lang).toBe("ar");
  expect(manifest.theme_color).toBe("#0b1730");
  expect(manifest.icons).toEqual(expect.arrayContaining([
    expect.objectContaining({ src: "/icons/nexora.svg", purpose: "any" }),
    expect.objectContaining({ src: "/icons/nexora.svg", purpose: "maskable" }),
  ]));

  const icon = await request.get("/icons/nexora.svg");
  expect(icon.ok()).toBeTruthy();
  expect(await icon.text()).toContain("<svg");
});

test("registers an offline app shell without caching business API data", async ({ page, request }) => {
  await page.route(/\/api\/v1\/field\/today(?:\?.*)?$/, (route) => route.fulfill({
    status: 200,
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ trip: null, customers: [], stock: [] }),
  }));
  await page.goto("/field");
  const activeScript = await page.evaluate(async () => {
    const registration = await navigator.serviceWorker.ready;
    return registration.active?.scriptURL ?? "";
  });
  expect(activeScript).toContain("/sw.js");

  const serviceWorker = await request.get("/sw.js");
  expect(serviceWorker.ok()).toBeTruthy();
  const source = await serviceWorker.text();
  expect(source).toContain("/field");
  expect(source).toContain("/api/");
  expect(source).toContain("/auth/");

  await page.context().setOffline(true);
  await page.reload();
  await expect(page.locator("body")).toContainText(/NEXORA|المندوب|بدون إنترنت/);
  await page.context().setOffline(false);
});
