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
