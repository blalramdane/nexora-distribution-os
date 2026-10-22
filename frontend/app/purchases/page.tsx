"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { ArrowDownToLine, Check, ChevronLeft, ChevronRight, ClipboardList, FileText, Package, Plus, Search, Truck, Upload, Minus, Trash2, UserRound, X } from "lucide-react";
import { api } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";

type Supplier = { id: string; name: string; code: string; phone?: string | null; address?: string | null };
type Location = { id: string; name: string; code: string; type?: string };
type Category = { id: string; name_ar: string; code: string };
type Packaging = { id: string; product_id: string; unit_id: string; name_ar: string; unit_name: string; conversion_to_base: number | string; sale_price?: number | string | null; purchase_price?: number | string | null; is_default_sale_unit: boolean; is_default_purchase_unit: boolean };
type Product = { id: string; sku: string; name_ar: string; name_en?: string; brand?: string; base_unit_id: string; base_unit_name?: string; category_id?: string | null; category_name?: string | null; default_cost: number | string; default_piece_price: number | string; quantity_base: number | string; packagings: Packaging[] };
type Catalog = { categories: Category[]; products: { data: Product[]; current_page: number; last_page: number; total: number; per_page: number } };
type Line = { product: Product; packaging: Packaging | null; quantity: number; unit_cost: number };
const money = (value: number) => `${value.toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ج.م`;

function purchasePackaging(product: Product) {
  return product.packagings?.find((item) => item.is_default_purchase_unit)
    ?? product.packagings?.find((item) => Number(item.conversion_to_base) === 1)
    ?? null;
}

function purchaseCost(product: Product, packaging: Packaging | null) {
  const entered = packaging?.purchase_price;
  if (entered !== null && entered !== undefined && Number.isFinite(Number(entered))) return Number(entered);
  return (Number(product.default_cost) || 0) * (Number(packaging?.conversion_to_base) || 1);
}

export default function Purchases() {
  const [supplierQuery, setSupplierQuery] = useState("");
  const [supplierResults, setSupplierResults] = useState<Supplier[]>([]);
  const [supplier, setSupplier] = useState<Supplier | null>(null);
  const [locations, setLocations] = useState<Location[]>([]);
  const [locationId, setLocationId] = useState("");
  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryId, setCategoryId] = useState("");
  const [products, setProducts] = useState<Product[]>([]);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [catalogTotal, setCatalogTotal] = useState(0);
  const [lines, setLines] = useState<Line[]>([]);
  const [supplierInvoiceNo, setSupplierInvoiceNo] = useState("");
  const [discountPercent, setDiscountPercent] = useState("0");
  const [loading, setLoading] = useState(true);
  const [loadingError, setLoadingError] = useState("");
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [messageIsError, setMessageIsError] = useState(false);
  const [savedInvoice, setSavedInvoice] = useState<{ id: string; document_number: string } | null>(null);

  useEffect(() => {
    api<Location[]>("/locations")
      .then((nextLocations) => {
        setLocations(nextLocations);
        const warehouse = nextLocations.find((item) => item.type === "warehouse") ?? nextLocations[0];
        if (warehouse) setLocationId(warehouse.id);
      })
      .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر تحميل المواقع أو الموردين."));
  }, []);

  useEffect(() => {
    const term = supplierQuery.trim();
    if (term.length < 2) { setSupplierResults([]); return; }
    const timer = setTimeout(() => {
      api<Supplier[]>(`/suppliers?q=${encodeURIComponent(term)}`)
        .then(setSupplierResults)
        .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر البحث عن المورد."));
    }, 200);
    return () => clearTimeout(timer);
  }, [supplierQuery]);

  useEffect(() => {
    if (!locationId) return;
    const timer = setTimeout(() => {
      setLoading(true);
      const params = new URLSearchParams({ location_id: locationId, per_page: "60", page: String(page) });
      if (search.trim()) params.set("q", search.trim());
      if (categoryId) params.set("category_id", categoryId);
      api<Catalog>(`/catalog/pos?${params.toString()}`)
        .then((result) => { setCategories(result.categories); setProducts(result.products.data); setLastPage(result.products.last_page); setCatalogTotal(result.products.total); setLoadingError(""); })
        .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر تحميل كتالوج المنتجات."))
        .finally(() => setLoading(false));
    }, search.trim() ? 180 : 0);
    return () => clearTimeout(timer);
  }, [locationId, categoryId, search, page]);

  const subtotal = useMemo(() => lines.reduce((sum, line) => sum + line.quantity * line.unit_cost, 0), [lines]);
  const discountAmount = Math.min(subtotal, subtotal * Math.max(0, Math.min(100, Number(discountPercent) || 0)) / 100);
  const totalAfterDiscount = Math.max(0, subtotal - discountAmount);
  const totalQuantity = lines.reduce((sum, line) => sum + line.quantity, 0);

  function addProduct(product: Product) {
    setLines((current) => {
      const existing = current.find((line) => line.product.id === product.id);
      if (existing) return current.map((line) => line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line);
      const packaging = purchasePackaging(product);
      return [...current, { product, packaging, quantity: 1, unit_cost: purchaseCost(product, packaging) }];
    });
    setMessage("");
    setMessageIsError(false);
  }

  function changeQuantity(productId: string, quantity: number) {
    setLines((current) => quantity <= 0 ? current.filter((line) => line.product.id !== productId) : current.map((line) => line.product.id === productId ? { ...line, quantity } : line));
  }

  async function postPurchase() {
    setMessage(""); setMessageIsError(true); setSavedInvoice(null);
    if (loadingError) return setMessage("بيانات الشاشة لم تُحمّل. حدّث الصفحة وتأكد من الاتصال.");
    if (!supplier) return setMessage("ابحث عن المورد واختاره قبل تسجيل الفاتورة.");
    if (!locationId || !lines.length) return setMessage("اختار موقع الاستلام وأضف صنفًا واحدًا على الأقل.");
    if (lines.some((line) => !Number.isFinite(line.quantity) || line.quantity <= 0 || !Number.isFinite(line.unit_cost) || line.unit_cost < 0)) return setMessage("راجع كميات وتكاليف الأصناف.");
    if (totalQuantity <= 0 || subtotal <= 0) return setMessage("إجمالي الفاتورة لازم يكون أكبر من صفر.");
    setSaving(true);
    try {
      const payload = {
        supplier_id: supplier.id,
        location_id: locationId,
        supplier_invoice_number: supplierInvoiceNo.trim() || undefined,
        discount: discountAmount,
        discount_percent: Number(discountPercent) || 0,
        items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity, unit_cost: line.unit_cost, packaging_id: line.packaging?.id, unit_id: line.packaging?.unit_id ?? line.product.base_unit_id })),
      };
      const key = await getStableIdempotencyKey("purchases", payload);
      const result = await api<{ id: string; document_number: string; total: string }>("/purchases", { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      if (!result.id) { setMessage("لم يصل معرف الفاتورة من الخادم. افتح ملف المورد للتحقق قبل تكرار الاستلام."); setMessageIsError(false); return; }
      clearStableIdempotencyKey("purchases", key);
      setSavedInvoice({ id: result.id, document_number: result.document_number });
      setMessage(`تم استلام ${result.document_number} بنجاح. قيمة الفاتورة ${money(Number(result.total))} والمخزون تم تحديثه.`);
      setMessageIsError(false);
      setLines([]); setSupplierInvoiceNo(""); setDiscountPercent("0");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "تعذر تسجيل فاتورة الشراء. راجع الاتصال وحالة الفاتورة قبل إعادة المحاولة.");
    } finally { setSaving(false); }
  }

  return <main className="main pos-page purchase-page">
    <div className="topbar pos-header"><div><h1 className="title">استلام المشتريات</h1><div className="subtitle">اختار المورد والموقع، حدد وحدة الشراء (قطعة أو كرتونة)، ثم استلم الفاتورة مرة واحدة.</div></div><div className="pos-context"><label>موقع الاستلام<select aria-label="موقع الاستلام" value={locationId} onChange={(event) => setLocationId(event.target.value)}>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label><Link href="/purchase-imports" className="secondary purchase-ocr-link"><Upload size={14}/> استيراد صورة أو PDF</Link></div></div>

    {loadingError && <div className="pos-alert error" role="alert">{loadingError}</div>}
    <div className="pos-layout">
      <section className="card pos-catalog-panel"><div className="pos-catalog-head"><div><h2>كتالوج الاستلام</h2><span>{catalogTotal.toLocaleString("ar-EG")} صنف مطابق</span></div><label className="pos-search"><Search size={17}/><input aria-label="بحث أصناف المشتريات" value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="اسم الصنف / الكود / الباركود..."/><kbd>بحث</kbd></label></div>
        <div className="pos-category-strip" aria-label="تصنيفات المنتجات"><button type="button" className={!categoryId ? "selected" : ""} onClick={() => { setCategoryId(""); setPage(1); }}>كل المنتجات</button>{categories.map((category) => <button type="button" key={category.id} className={categoryId === category.id ? "selected" : ""} onClick={() => { setCategoryId(category.id); setPage(1); }}>{category.name_ar}</button>)}</div>
        {loading ? <div className="pos-empty">جاري تحميل الكتالوج...</div> : products.length ? <div className="pos-product-grid">{products.map((product) => { const packaging = purchasePackaging(product); return <button type="button" key={product.id} className="pos-product-card purchase-product-card" onClick={() => addProduct(product)} title={`إضافة ${product.name_ar}`}><span className="pos-product-symbol"><Package size={24}/>{lines.some((line) => line.product.id === product.id) && <b>{lines.find((line) => line.product.id === product.id)?.quantity.toLocaleString("ar-EG")}</b>}</span><span className="pos-product-category">{product.category_name || "بدون تصنيف"}</span><strong className="pos-product-name">{product.name_ar}</strong><span className="pos-product-sku">{product.sku}{product.brand ? ` · ${product.brand}` : ""}</span><span className="pos-product-bottom"><b>{money(purchaseCost(product, packaging))}</b><small>تكلفة {packaging?.name_ar || product.base_unit_name || "الوحدة الأساسية"}</small><small>الرصيد الحالي {Number(product.quantity_base).toLocaleString("ar-EG", { maximumFractionDigits: 3 })}</small></span><span className="pos-add"><Plus size={15}/> إضافة للاستلام</span></button>; })}</div> : <div className="pos-empty"><Package size={32}/><strong>لا توجد أصناف مطابقة</strong><span>جرّب بحثًا مختلفًا أو راجع صفحة الأصناف.</span><Link href="/products" className="ledger-open-link">إدارة الكتالوج</Link></div>}
        <div className="pos-pagination"><span>صفحة {page.toLocaleString("ar-EG")} من {Math.max(1, lastPage).toLocaleString("ar-EG")}</span><div><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))}><ChevronRight size={16}/> السابق</button><button type="button" disabled={page >= lastPage || loading} onClick={() => setPage((current) => Math.min(lastPage, current + 1))}>التالي <ChevronLeft size={16}/></button></div></div>
      </section>

      <aside className="card pos-checkout"><div className="pos-cart-heading"><span className="pos-cart-icon"><ClipboardList size={20}/></span><div><h2>فاتورة المورد</h2><span>{lines.length.toLocaleString("ar-EG")} أصناف · {totalQuantity.toLocaleString("ar-EG")} وحدة مشتراة</span></div><button type="button" className="icon-btn" disabled={!lines.length} onClick={() => setLines([])} title="تفريغ الفاتورة"><Trash2 size={15}/></button></div>

        <div className="pos-customer-block"><label className="pos-label" htmlFor="purchase-supplier-search"><Truck size={15}/> المورد</label>{supplier ? <div className="pos-selected-customer"><span className="pos-customer-avatar"><Truck size={18}/></span><div className="pos-customer-data"><strong>{supplier.name}</strong><small>{supplier.phone || "لا يوجد رقم هاتف"} · {supplier.code}</small><div className="pos-contact-links">{supplier.phone && <a href={`tel:${supplier.phone}`}>اتصال</a>}<Link href={`/suppliers/${supplier.id}`}>ملف المورد والفواتير</Link></div></div><button type="button" className="icon-btn" onClick={() => { setSupplier(null); setSupplierQuery(""); }} title="تغيير المورد"><X size={15}/></button></div> : <div className="pos-customer-search-wrap"><Search size={15}/><input id="purchase-supplier-search" value={supplierQuery} onChange={(event) => setSupplierQuery(event.target.value)} autoComplete="off" placeholder="ابحث عن المورد بالاسم أو الكود أو الهاتف..."/>{supplierQuery && <button type="button" aria-label="مسح البحث" onClick={() => setSupplierQuery("")}><X size={14}/></button>}{supplierQuery.trim().length >= 2 && <div className="pos-customer-results">{supplierResults.length ? supplierResults.slice(0, 8).map((item) => <button type="button" key={item.id} onClick={() => { setSupplier(item); setSupplierQuery(""); setSupplierResults([]); }}><span><strong>{item.name}</strong><small>{item.phone || "بدون هاتف"}</small></span><small>{item.code}</small></button>) : <p>لا توجد نتائج مطابقة.</p>}</div>}</div>}
          <label className="purchase-supplier-invoice-label">رقم فاتورة المورد (اختياري)<input value={supplierInvoiceNo} onChange={(event) => setSupplierInvoiceNo(event.target.value)} placeholder="اكتب الرقم كما هو على فاتورة المورد" maxLength={128}/></label>
          <label className="purchase-supplier-invoice-label">خصم المورد على إجمالي الفاتورة (%)<input type="number" min="0" max="100" step="0.01" value={discountPercent} onChange={(event) => setDiscountPercent(String(Math.max(0, Math.min(100, Number(event.target.value) || 0))))} placeholder="مثال: 5"/><small>الخصم: {money(discountAmount)} — يُحسب على إجمالي البضاعة كلها.</small></label>
        </div>

        <div className="pos-cart-lines">{lines.map((line) => <div className="pos-cart-line" key={line.product.id}><div className="pos-cart-line-main"><strong>{line.product.name_ar}</strong><small>{line.product.sku}</small>{line.product.packagings?.length > 0 && <select className="pos-line-packaging" aria-label={`وحدة شراء ${line.product.name_ar}`} value={line.packaging?.id || "base"} onChange={(event) => { const packaging = line.product.packagings.find((item) => item.id === event.target.value) ?? null; const cost = purchaseCost(line.product, packaging); setLines((current) => current.map((item) => item.product.id === line.product.id ? { ...item, packaging, unit_cost: cost } : item)); }}><option value="base">{line.product.base_unit_name || "الوحدة الأساسية"}</option>{line.product.packagings.map((item) => <option key={item.id} value={item.id}>{item.name_ar} · ×{Number(item.conversion_to_base).toLocaleString("ar-EG")}</option>)}</select>}<label className="purchase-unit-cost">تكلفة الوحدة<input type="number" min="0" step="0.01" value={line.unit_cost} onChange={(event) => setLines((current) => current.map((item) => item.product.id === line.product.id ? { ...item, unit_cost: Number(event.target.value) } : item))}/></label><b>{money(line.quantity * line.unit_cost)}</b></div><div className="pos-line-controls"><button type="button" aria-label={`تقليل ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, line.quantity - 1)}><Minus size={13}/></button><input aria-label={`كمية ${line.product.name_ar}`} type="number" min="0.001" step="any" value={line.quantity} onChange={(event) => changeQuantity(line.product.id, Number(event.target.value))}/><button type="button" aria-label={`زيادة ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, line.quantity + 1)}><Plus size={13}/></button><button type="button" className="pos-remove-line" aria-label={`حذف ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, 0)}><Trash2 size={14}/></button></div></div>)}{!lines.length && <div className="pos-cart-empty"><ClipboardList size={29}/><strong>فاتورة الشراء فارغة</strong><span>اضغط على الأصناف من الكتالوج لإضافتها، واختر وحدة الاستلام الصحيحة.</span></div>}</div>

        <div className="pos-total-card purchase-total-card"><div><span>عدد الوحدات المدخلة</span><strong>{totalQuantity.toLocaleString("ar-EG")}</strong></div><div><span>إجمالي البضاعة</span><strong>{money(subtotal)}</strong></div><div><span>خصم المورد ({Number(discountPercent || 0)}%)</span><strong className="stock-low">− {money(discountAmount)}</strong></div><div className="pos-grand-total"><span>صافي فاتورة المورد</span><strong>{money(totalAfterDiscount)}</strong></div></div>
        <div className="purchase-policy-note">الإجمالي محسوب من الكمية × تكلفة وحدة الشراء المحددة. راجع الكراتين ومعامل التحويل قبل الاستلام؛ المخزون والتكلفة يتحدّثان بعد ترحيل الفاتورة.</div>
        {message && <div className={`pos-alert ${messageIsError ? "error" : "success"}`} role="status" aria-live="polite">{message}</div>}
        {savedInvoice && <Link href={`/suppliers/${supplier?.id}`} className="pos-invoice-link"><Check size={16}/> {savedInvoice.document_number} · فتح ملف المورد والفاتورة</Link>}
        <button type="button" className="primary pos-submit" disabled={saving || !lines.length} onClick={postPurchase}><ArrowDownToLine size={17}/>{saving ? "جاري استلام الفاتورة..." : "تأكيد الاستلام وتحديث المخزون"}</button>
        <small className="pos-secure-note">تسجيل الاستلام يُنشئ حركة مخزون وتكلفة مسجلة. تأكد من الموقع والمورد والكميات قبل التأكيد.</small>
      </aside>
    </div>
  </main>;
}
