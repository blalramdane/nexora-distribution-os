type StorageLike = Pick<Storage, "getItem" | "setItem" | "removeItem">;

type PendingRequest = {
  key: string;
  createdAt: number;
};

type PendingRegistry = Record<string, PendingRequest>;

const memoryRegistry = new Map<string, Map<string, string>>();
const STORAGE_PREFIX = "nexora:pending-idempotency:";

function resolveStorage(override?: StorageLike | null): StorageLike | null {
  if (override !== undefined) return override;

  try {
    return typeof window === "undefined" ? null : window.sessionStorage;
  } catch {
    return null;
  }
}

function assertScope(scope: string): void {
  if (!/^[a-z0-9._-]+$/i.test(scope)) {
    throw new Error("Invalid idempotency scope.");
  }
}

async function fingerprintPayload(payload: unknown): Promise<string> {
  const subtle = globalThis.crypto?.subtle;
  if (!subtle) {
    throw new Error("Secure request fingerprinting is unavailable in this browser context.");
  }

  const serialized = JSON.stringify(payload) ?? "null";
  const digest = await subtle.digest("SHA-256", new TextEncoder().encode(serialized));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

function readRegistry(storage: StorageLike, storageKey: string): PendingRegistry {
  try {
    const raw = storage.getItem(storageKey);
    if (!raw) return {};
    const parsed: unknown = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) return {};
    return parsed as PendingRegistry;
  } catch {
    return {};
  }
}

function memoryFor(scope: string): Map<string, string> {
  let registry = memoryRegistry.get(scope);
  if (!registry) {
    registry = new Map<string, string>();
    memoryRegistry.set(scope, registry);
  }
  return registry;
}

/**
 * Reuses a key for the same unconfirmed payload until its caller explicitly
 * clears it after a confirmed success. Only a SHA-256 fingerprint, never the
 * business payload itself, is stored in sessionStorage.
 */
export async function getStableIdempotencyKey(
  scope: string,
  payload: unknown,
  storageOverride?: StorageLike | null,
): Promise<string> {
  assertScope(scope);
  const fingerprint = await fingerprintPayload(payload);
  const storageKey = `${STORAGE_PREFIX}${scope}`;
  const storage = resolveStorage(storageOverride);

  if (storage) {
    try {
      const registry = readRegistry(storage, storageKey);
      const existing = registry[fingerprint];
      if (existing && typeof existing.key === "string" && existing.key) return existing.key;

      const key = globalThis.crypto.randomUUID();
      registry[fingerprint] = { key, createdAt: Date.now() };
      storage.setItem(storageKey, JSON.stringify(registry));
      return key;
    } catch {
      // A blocked or full sessionStorage falls back to in-memory identity.
    }
  }

  const registry = memoryFor(scope);
  const existing = registry.get(fingerprint);
  if (existing) return existing;

  const key = globalThis.crypto.randomUUID();
  registry.set(fingerprint, key);
  return key;
}

/** Clear only the matching request key so an unrelated pending request survives. */
export function clearStableIdempotencyKey(
  scope: string,
  key: string,
  storageOverride?: StorageLike | null,
): void {
  assertScope(scope);
  const storageKey = `${STORAGE_PREFIX}${scope}`;
  const storage = resolveStorage(storageOverride);

  if (storage) {
    try {
      const registry = readRegistry(storage, storageKey);
      let changed = false;
      for (const [fingerprint, entry] of Object.entries(registry)) {
        if (entry?.key === key) {
          delete registry[fingerprint];
          changed = true;
        }
      }
      if (changed) {
        if (Object.keys(registry).length) storage.setItem(storageKey, JSON.stringify(registry));
        else storage.removeItem(storageKey);
      }
    } catch {
      // The in-memory registry is still cleared below if sessionStorage fails.
    }
  }

  const registry = memoryRegistry.get(scope);
  if (registry) {
    for (const [fingerprint, storedKey] of registry.entries()) {
      if (storedKey === key) registry.delete(fingerprint);
    }
    if (registry.size === 0) memoryRegistry.delete(scope);
  }
}
