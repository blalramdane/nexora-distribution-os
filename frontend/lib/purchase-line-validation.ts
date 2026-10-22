export type PurchaseLineValidationInput = {
  item_mode?: "existing" | "new" | string;
  product_id?: string | null;
  quantity?: string | null;
  unit_cost?: string | null;
  invoice_piece_cost?: string | number | null;
  new_product?: {
    name_ar?: string | null;
    base_unit_id?: string | null;
    purchase_packaging_name?: string | null;
    purchase_packaging_unit_id?: string | null;
    purchase_conversion_to_base?: string | number | null;
  } | null;
};

/** Only clear Arabic-script descriptions may prefill the canonical Arabic catalog name. */
export function safeArabicCatalogName(value: string | null | undefined): string {
  const candidate = typeof value === "string" ? value.trim() : "";
  const arabicLetters = candidate.match(/[\u0621-\u064A]/g) || [];
  if (arabicLetters.length < 4 || /[A-Za-z]/.test(candidate)) return "";
  return candidate;
}

export function normalizeNewProductDraft(value: Record<string, unknown> | null | undefined, fallbackBaseUnitId = "") {
  const text = (key: string) => typeof value?.[key] === "string" ? value[key] as string : "";
  return {
    sku: text("sku"),
    supplier_sku: text("supplier_sku"),
    supplier_name: text("supplier_name"),
    name_ar: text("name_ar"),
    name_en: text("name_en"),
    brand: text("brand"),
    category_id: text("category_id"),
    base_unit_id: text("base_unit_id") || fallbackBaseUnitId,
    default_piece_price: text("default_piece_price"),
    purchase_packaging_name: text("purchase_packaging_name"),
    purchase_packaging_unit_id: text("purchase_packaging_unit_id"),
    purchase_conversion_to_base: text("purchase_conversion_to_base") || "1",
  };
}

export function normalizePurchaseLine(value: Record<string, unknown>, fallbackId: string, fallbackBaseUnitId = "") {
  const text = (key: string) => {
    const field = value[key];
    return typeof field === "string" ? field : typeof field === "number" && Number.isFinite(field) ? String(field) : "";
  };
  const sourceDraft = value.new_product && typeof value.new_product === "object" && !Array.isArray(value.new_product)
    ? value.new_product as Record<string, unknown>
    : undefined;
  const newProduct = sourceDraft ? normalizeNewProductDraft(sourceDraft, fallbackBaseUnitId) : undefined;
  const requestedMode = value.item_mode;
  const itemMode = requestedMode === "new" || requestedMode === "existing"
    ? requestedMode
    : newProduct ? "new" : "existing";

  return {
    id: text("id") || fallbackId,
    product_id: text("product_id"),
    quantity: text("quantity"),
    unit_cost: text("unit_cost"),
    invoice_piece_cost: text("invoice_piece_cost"),
    item_mode: itemMode,
    invoice_item_name: text("invoice_item_name") || newProduct?.supplier_name || "",
    invoice_unit_name: text("invoice_unit_name"),
    invoice_unit_kind: value.invoice_unit_kind === "carton" || value.invoice_unit_kind === "piece" ? value.invoice_unit_kind : "unknown",
    invoice_unit_confirmed: value.invoice_unit_confirmed === true,
    new_product: newProduct,
  };
}

/** Validate untrusted OCR/API draft data without allowing a missing field to crash the page. */
export function isPurchaseLineReady(line: PurchaseLineValidationInput): boolean {
  const productName = typeof line.new_product?.name_ar === "string" ? line.new_product.name_ar : "";
  const quantity = typeof line.quantity === "string" ? line.quantity : "";
  const unitCost = typeof line.unit_cost === "string" ? line.unit_cost : "";

  const productReady = line.item_mode === "new"
    ? !!productName.trim() && !!line.new_product?.base_unit_id
    : !!line.product_id;
  const conversion = Number(line.new_product?.purchase_conversion_to_base ?? 1);
  const purchasePackReady = line.item_mode !== "new" || (Number.isFinite(conversion) && conversion > 0 && (conversion <= 1 || (!!line.new_product?.purchase_packaging_name?.trim() && !!line.new_product?.purchase_packaging_unit_id)));

  return productReady
    && purchasePackReady
    && !!quantity.trim()
    && !!unitCost.trim()
    && Number.isFinite(Number(quantity))
    && Number(quantity) > 0
    && Number.isFinite(Number(unitCost))
    && Number(unitCost) >= 0;
}
