import assert from "node:assert/strict";
import { test } from "node:test";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "./idempotency.ts";

class TestStorage {
  values = new Map();

  getItem(key) {
    return this.values.get(key) ?? null;
  }

  setItem(key, value) {
    this.values.set(key, String(value));
  }

  removeItem(key) {
    this.values.delete(key);
  }
}

test("reuses the same key for the same pending payload", async () => {
  const storage = new TestStorage();
  const payload = { customer_id: "customer-1", items: [{ product_id: "product-1", quantity: 2 }] };

  const first = await getStableIdempotencyKey("sales", payload, storage);
  const retry = await getStableIdempotencyKey("sales", payload, storage);

  assert.equal(retry, first);
  assert.doesNotMatch(Array.from(storage.values.values()).join("\n"), /customer-1|product-1/);
});

test("keeps distinct identities for changed payloads and can recover the first after edits", async () => {
  const storage = new TestStorage();
  const firstPayload = { amount: 20, party_id: "party-1" };
  const first = await getStableIdempotencyKey("payments", firstPayload, storage);
  const changed = await getStableIdempotencyKey("payments", { amount: 25, party_id: "party-1" }, storage);
  const restored = await getStableIdempotencyKey("payments", firstPayload, storage);

  assert.notEqual(changed, first);
  assert.equal(restored, first);
});

test("clears a confirmed request so an intentional next posting gets a fresh key", async () => {
  const storage = new TestStorage();
  const payload = { supplier_id: "supplier-1", total: 100 };
  const first = await getStableIdempotencyKey("purchases", payload, storage);

  clearStableIdempotencyKey("purchases", first, storage);
  const next = await getStableIdempotencyKey("purchases", payload, storage);

  assert.notEqual(next, first);
});

test("isolates identity registries by operation scope", async () => {
  const storage = new TestStorage();
  const payload = { id: "same-payload" };
  const saleKey = await getStableIdempotencyKey("sales", payload, storage);
  const returnKey = await getStableIdempotencyKey("sales-return", payload, storage);

  assert.notEqual(returnKey, saleKey);
});
