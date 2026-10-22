import assert from "node:assert/strict";
import { test } from "node:test";
import { isPurchaseLineReady, normalizePurchaseLine, safeArabicCatalogName } from "./purchase-line-validation.ts";

test("a new-product draft with a null catalog name is invalid without crashing", () => {
  assert.equal(isPurchaseLineReady({
    item_mode: "new",
    quantity: "2",
    unit_cost: "125.00",
    new_product: { name_ar: null, base_unit_id: "unit-1" },
  }), false);
});

test("missing or malformed OCR fields are treated as incomplete instead of throwing", () => {
  assert.equal(isPurchaseLineReady({ item_mode: "new", quantity: null, unit_cost: "10", new_product: null }), false);
  assert.equal(isPurchaseLineReady({ item_mode: "existing", product_id: "product-1", quantity: "2", unit_cost: null }), false);
  assert.equal(isPurchaseLineReady({ item_mode: "new", quantity: "not-a-number", unit_cost: "10", new_product: { name_ar: "مروحة", base_unit_id: "unit-1" } }), false);
});

test("complete new and existing product lines remain valid", () => {
  assert.equal(isPurchaseLineReady({
    item_mode: "new", quantity: "2", unit_cost: "0",
    new_product: { name_ar: "مروحة ستاند", base_unit_id: "unit-1" },
  }), true);
  assert.equal(isPurchaseLineReady({ item_mode: "existing", product_id: "product-1", quantity: "1.5", unit_cost: "20.25" }), true);
});

test("a multi-piece purchase package requires a package label and package unit", () => {
  const line = {
    item_mode: "new", quantity: "3", unit_cost: "10980",
    new_product: {
      name_ar: "كاتيل", base_unit_id: "piece-unit",
      purchase_conversion_to_base: "12", purchase_packaging_name: "كرتونة 12 قطعة",
      purchase_packaging_unit_id: "carton-unit",
    },
  };
  assert.equal(isPurchaseLineReady(line), true);
  assert.equal(isPurchaseLineReady({ ...line, new_product: { ...line.new_product, purchase_packaging_unit_id: "" } }), false);
  assert.equal(isPurchaseLineReady({ ...line, new_product: { ...line.new_product, purchase_conversion_to_base: "0" } }), false);
});

test("normalizes null quantity and unit cost to empty controlled-input values", () => {
  assert.deepEqual(normalizePurchaseLine({
    id: null,
    item_mode: "new",
    product_id: null,
    quantity: null,
    unit_cost: null,
    invoice_item_name: null,
    new_product: { name_ar: null, sku: null, supplier_sku: "SUP-1", base_unit_id: null },
  }, "fallback-line-id", "unit-1"), {
    id: "fallback-line-id",
    product_id: "",
    quantity: "",
    unit_cost: "",
    invoice_piece_cost: "",
    item_mode: "new",
    invoice_item_name: "",
    invoice_unit_name: "",
    invoice_unit_kind: "unknown",
    invoice_unit_confirmed: false,
    new_product: {
      sku: "",
      supplier_sku: "SUP-1",
      supplier_name: "",
      name_ar: "",
      name_en: "",
      brand: "",
      category_id: "",
      base_unit_id: "unit-1",
      default_piece_price: "",
      purchase_packaging_name: "",
      purchase_packaging_unit_id: "",
      purchase_conversion_to_base: "1",
    },
  });
});

test("only pre-fills the canonical Arabic name from clear Arabic-only text", () => {
  assert.equal(safeArabicCatalogName("مروحة ستاند 18 بوصة"), "مروحة ستاند 18 بوصة");
  assert.equal(safeArabicCatalogName("مروحة ستاند 18 dog)"), "");
  assert.equal(safeArabicCatalogName("Electric Kettle 1.7 Liter"), "");
  assert.equal(safeArabicCatalogName(null), "");
});
