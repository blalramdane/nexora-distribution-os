/* NEXORA field PWA shell cache. Never cache API responses, authentication data, or mutations. */
const CACHE_NAME = "nexora-shell-v1";
const APP_SHELL = ["/field", "/login", "/manifest.webmanifest", "/icons/nexora.svg"];
const STATIC_PREFIX = "/_next/static/";

self.addEventListener("install", (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE_NAME);
    await Promise.all(APP_SHELL.map(async (path) => {
      try {
        const response = await fetch(path, { cache: "reload" });
        if (response.ok && response.type === "basic") {
          await cache.put(path, response);
        }
      } catch {
        // A failed optional shell fetch must not prevent the PWA from installing.
      }
    }));
    await self.skipWaiting();
  })());
});

self.addEventListener("activate", (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter((name) => name.startsWith("nexora-shell-") && name !== CACHE_NAME)
      .map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  // Authenticated business data must always use the network and is never cached.
  if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/auth/")) return;

  if (request.mode === "navigate" && ["/field", "/login"].includes(url.pathname)) {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        if (response.ok) {
          const cache = await caches.open(CACHE_NAME);
          await cache.put(url.pathname, response.clone());
        }
        return response;
      } catch {
        const cached = await caches.match(url.pathname);
        if (cached) return cached;
        return new Response("واجهة NEXORA غير محفوظة على هذا الجهاز بعد. اتصل بالإنترنت وافتح صفحة المندوب مرة واحدة.", {
          status: 503,
          headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "no-store" },
        });
      }
    })());
    return;
  }

  const shouldCacheStatic = url.pathname.startsWith(STATIC_PREFIX)
    || url.pathname === "/manifest.webmanifest"
    || url.pathname.startsWith("/icons/");
  if (!shouldCacheStatic) return;

  event.respondWith((async () => {
    const cached = await caches.match(request);
    if (cached) return cached;
    try {
      const response = await fetch(request);
      if (response.ok && response.type === "basic") {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
      }
      return response;
    } catch {
      return new Response("المورد غير متاح دون اتصال.", {
        status: 503,
        headers: { "Content-Type": "text/plain; charset=utf-8", "Cache-Control": "no-store" },
      });
    }
  })());
});
