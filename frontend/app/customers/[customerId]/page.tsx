"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowRight, CalendarClock, CheckCircle2, Clock3, FileText, MapPin, MessageCircle, Phone, RotateCcw, WalletCards, Save, Tag, RefreshCw } from "lucide-react";
import { api } from "@/lib/api";

type Customer = { id: string; code: string; name: string; phone?: string; alternate_phone?: string; address_text?: string; credit_limit: number; credit_enabled: boolean; payment_terms_days: number; status: string; notes?: string; created_at: string };
type Summary = { invoice_count: number; sales_total: number; paid_on_invoices: number; open_balance: number; returns_total: number; collections_total: number; last_invoice_at?: string | null; last_collection_at?: string | null };
type Invoice = { id: string; document_number: string; invoice_date: string; total: string | number; paid_amount: string | number; balance_due: string | number; currency: string; trip_id?: string | null; created_at: string };
type ReturnRow = { id: string; document_number: string; return_date: string; total: string | number; currency: string; original_sales_invoice_id?: string | null; original_invoice_number?: string | null };
type Payment = { id: string; amount: string | number; currency: string; payment_date: string; reference?: string | null; trip_id?: string | null; created_at: string };
type Visit = { id: string; status: string; check_in_at?: string | null; check_out_at?: string | null; notes?: string | null; created_at: string; trip_number: string };
type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
type Customer360 = { customer: Customer; summary: Summary; invoices: Page<Invoice>; returns: Page<ReturnRow>; payments: Page<Payment>; visits: Page<Visit> };
type CustomerPrice = { id: string; product_id: string; packaging_id: string | null; unit_key: string; unit_price: string | number; sku: string; product_name: string; packaging_name?: string | null };
type PricingProduct = { id: string; sku: string; name_ar: string; base_unit_id: string; base_unit_name?: string; default_piece_price: string | number; packagings: { id: string; name_ar: string; unit_id: string; conversion_to_base: string | number; sale_price?: string | number | null }[] };
type PricingCatalog = { products: { data: PricingProduct[] } };
type Tab = "invoices" | "payments" | "returns" | "visits";
const money = (value: string | number | null | undefined) => `${(Number(value) || 0).toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ج.م`;
const date = (value?: string | null) => value ? new Date(value).toLocaleDateString("ar-EG", { year: "numeric", month: "short", day: "numeric" }) : "—";

function whatsappUrl(phone?: string) {
  let digits = (phone ?? "").replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("0")) digits = `20${digits.slice(1)}`;
  return digits ? `https://wa.me/${digits}` : "";
}

function CustomerPricingPanel({ customerId }: { customerId: string }) {
  const [prices, setPrices] = useState<CustomerPrice[]>([]);
  const [products, setProducts] = useState<PricingProduct[]>([]);
  const [query, setQuery] = useState("");
  const [productId, setProductId] = useState("");
  const [packagingId, setPackagingId] = useState("base");
  const [unitPrice, setUnitPrice] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const refreshPrices = async () => {
    const result = await api<CustomerPrice[]>("/customers/" + encodeURIComponent(customerId) + "/prices");
    setPrices(result);
  };

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api<CustomerPrice[]>("/customers/" + encodeURIComponent(customerId) + "/prices")
      .then((result) => { if (!cancelled) setPrices(result); })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر تحميل قائمة الأسعار الخاصة بالعميل."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [customerId]);

  useEffect(() => {
    const term = query.trim();
    if (term.length < 2) { setProducts([]); return; }
    let cancelled = false;
    const timer = window.setTimeout(() => {
      api<PricingCatalog>("/catalog/pos?q=" + encodeURIComponent(term) + "&per_page=60&page=1")
        .then((result) => { if (!cancelled) setProducts(result.products.data || []); })
        .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر البحث عن الصنف."); });
    }, 220);
    return () => { cancelled = true; window.clearTimeout(timer); };
  }, [query]);

  const selectedProduct = products.find((product) => product.id === productId) ?? null;
  const selectedPackaging = selectedProduct?.packagings.find((packaging) => packaging.id === packagingId) ?? null;
  const setPriceForUnit = (product: PricingProduct | null, nextPackagingId: string) => {
    if (!product) { setUnitPrice(""); return; }
    const packaging = product.packagings.find((item) => item.id === nextPackagingId);
    const suggested = packaging?.sale_price !== null && packaging?.sale_price !== undefined
      ? Number(packaging.sale_price)
      : Number(product.default_piece_price || 0) * (Number(packaging?.conversion_to_base) || 1);
    setUnitPrice(String(suggested));
  };
  const clearEditor = () => { setProductId(""); setPackagingId("base"); setUnitPrice(""); setQuery(""); setProducts([]); };
  const savePrice = async (event: React.FormEvent) => {
    event.preventDefault(); setSaving(true); setError(""); setMessage("");
    if (!selectedProduct || !unitPrice.trim() || !Number.isFinite(Number(unitPrice)) || Number(unitPrice) < 0) {
      setError("اختار صنفًا وسعر بيع صحيحًا أكبر من أو يساوي صفر.");
      setSaving(false); return;
    }
    try {
      await api<CustomerPrice>("/customers/" + encodeURIComponent(customerId) + "/prices", {
        method: "PUT",
        body: JSON.stringify({ product_id: selectedProduct.id, packaging_id: selectedPackaging?.id ?? null, unit_price: Number(unitPrice) }),
      });
      await refreshPrices();
      setMessage("تم حفظ سعر " + selectedProduct.name_ar + " للعميل. سيُستخدم كسعر افتراضي عند إنشاء فاتورة جديدة.");
      clearEditor();
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر حفظ سعر العميل.");
    } finally { setSaving(false); }
  };

  return <section className="card customer-profile-ledger" style={{ marginBottom: 14 }}>
    <div className="customer-section-heading"><div><h2><Tag size={16} style={{ verticalAlign: "middle", marginLeft: 5 }}/> قائمة الأسعار الخاصة بالعميل</h2><p>احفظ سعر الصنف حسب وحدة البيع. سعر الفاتورة يظل قابلًا للمراجعة قبل الإصدار.</p></div><span className="badge blue">{prices.length.toLocaleString("ar-EG")} سعر محفوظ</span></div>
    {error && <div className="pos-alert error" role="alert" style={{ marginBottom: 10 }}>{error}</div>}
    {message && <div className="pos-alert success" role="status" style={{ marginBottom: 10 }}>{message}</div>}
    <form onSubmit={savePrice} style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 9, alignItems: "end", marginBottom: 14 }}>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>ابحث عن الصنف<input value={query} onChange={(event) => { setQuery(event.target.value); setProductId(""); setPackagingId("base"); }} placeholder="اكتب اسم الصنف أو SKU..." aria-label="بحث صنف للسعر الخاص" autoComplete="off"/></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>الصنف<select aria-label="الصنف لتحديد سعر العميل" value={productId} onChange={(event) => { const product = products.find((item) => item.id === event.target.value) ?? null; setProductId(event.target.value); setPackagingId("base"); setPriceForUnit(product, "base"); }} required><option value="">اختار صنفًا من البحث</option>{products.map((product) => <option key={product.id} value={product.id}>{product.name_ar} · {product.sku}</option>)}</select></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>وحدة البيع<select aria-label="وحدة السعر الخاص" value={packagingId} onChange={(event) => { setPackagingId(event.target.value); setPriceForUnit(selectedProduct, event.target.value); }} disabled={!selectedProduct}><option value="base">{selectedProduct?.base_unit_name || "الوحدة الأساسية"}</option>{selectedProduct?.packagings.map((packaging) => <option key={packaging.id} value={packaging.id}>{packaging.name_ar} · ×{Number(packaging.conversion_to_base).toLocaleString("ar-EG")}</option>)}</select></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>سعر البيع بالجنيه<input aria-label="سعر البيع المحفوظ" type="number" min="0" step="0.01" value={unitPrice} onChange={(event) => setUnitPrice(event.target.value)} disabled={!selectedProduct} required/></label>
      <button className="primary" type="submit" disabled={saving || loading || !selectedProduct} style={{ width: "auto", minHeight: 40, padding: "9px 13px" }}><Save size={14}/>{saving ? "جاري الحفظ..." : "حفظ السعر"}</button>
    </form>
    {query.trim().length >= 2 && <div style={{ fontSize: 11, color: "#64748b", marginBottom: 10 }}>{products.length ? "تم العثور على " + products.length.toLocaleString("ar-EG") + " صنف. اختار الصنف والوحدة ثم احفظ السعر." : "جاري البحث أو لا توجد أصناف مطابقة."}</div>}
    <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>وحدة البيع</th><th>سعر العميل</th><th>الإجراء</th></tr></thead><tbody>
      {loading ? <tr><td colSpan={5}>جاري تحميل قائمة الأسعار...</td></tr> : prices.map((price) => <tr key={price.id}><td><strong>{price.product_name}</strong></td><td>{price.sku}</td><td>{price.packaging_name || "الوحدة الأساسية"}</td><td><strong className="ledger-paid">{money(price.unit_price)}</strong></td><td><button type="button" className="secondary" onClick={() => { setQuery(price.sku); setProductId(price.product_id); setPackagingId(price.packaging_id || "base"); setUnitPrice(String(price.unit_price)); setProducts([{ id: price.product_id, sku: price.sku, name_ar: price.product_name, base_unit_id: "", default_piece_price: price.unit_price, packagings: price.packaging_id ? [{ id: price.packaging_id, name_ar: price.packaging_name || "وحدة التعبئة", unit_id: "", conversion_to_base: 1, sale_price: price.unit_price }] : [] }]); }}><RefreshCw size={13}/> تعديل السعر</button></td></tr>)}
      {!loading && !prices.length && <tr><td colSpan={5}>لا توجد أسعار خاصة محفوظة لهذا العميل. أي سعر جديد سيظل مرتبطًا بالفاتورة فقط حتى تحفظ سعرًا هنا.</td></tr>}
    </tbody></table></div>
    <p style={{ color: "#64748b", fontSize: 11, marginTop: 9 }}>السعر المحفوظ يُقترح في نقطة البيع عند اختيار هذا العميل؛ لا يعدّل سعر الصنف العام أو تكلفة الشراء، ولا يعيد تسعير الفواتير السابقة.</p>
  </section>;
}

export default function CustomerProfilePage() {
  const params = useParams<{ customerId: string }>();
  const customerId = params.customerId;
  const [data, setData] = useState<Customer360 | null>(null);
  const [tab, setTab] = useState<Tab>("invoices");
  const [pages, setPages] = useState({ invoices: 1, payments: 1, returns: 1, visits: 1 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [creditSaving, setCreditSaving] = useState(false);
  const [creditMessage, setCreditMessage] = useState("");
  const [creditError, setCreditError] = useState("");
  const [creditEnabled, setCreditEnabled] = useState(false);
  const [creditLimit, setCreditLimit] = useState("0");
  const [paymentTerms, setPaymentTerms] = useState("0");

  useEffect(() => {
    if (!customerId) return;
    let cancelled = false;
    setLoading(true);
    const query = new URLSearchParams({ per_page: "25", invoices_page: String(pages.invoices), payments_page: String(pages.payments), returns_page: String(pages.returns), visits_page: String(pages.visits) });
    api<Customer360>(`/customers/${encodeURIComponent(customerId)}/360?${query.toString()}`)
      .then((result) => { if (!cancelled) { setData(result); setCreditEnabled(Boolean(result.customer.credit_enabled)); setCreditLimit(String(result.customer.credit_limit ?? 0)); setPaymentTerms(String(result.customer.payment_terms_days ?? 0)); } })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر تحميل ملف العميل."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [customerId, pages]);

  const activityCount = useMemo(() => (data?.summary.invoice_count ?? 0) + (data?.payments.total ?? 0) + (data?.returns.total ?? 0) + (data?.visits.total ?? 0), [data]);

  function pageControls(key: keyof typeof pages, current: Page<unknown>) {
    if (current.total <= current.per_page) return null;
    return <div className="pos-pagination customer-history-pagination"><span>عرض {(current.from ?? 0).toLocaleString("ar-EG")}–{(current.to ?? 0).toLocaleString("ar-EG")} من {current.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={current.current_page <= 1} onClick={() => setPages((state) => ({ ...state, [key]: Math.max(1, state[key] - 1) }))}><ArrowRight size={14}/> السابق</button><span className="customer-page-index">{current.current_page.toLocaleString("ar-EG")} / {current.last_page.toLocaleString("ar-EG")}</span><button type="button" disabled={current.current_page >= current.last_page} onClick={() => setPages((state) => ({ ...state, [key]: Math.min(current.last_page, state[key] + 1) }))}>التالي <ArrowRight size={14} className="customer-next-arrow"/></button></div></div>;
  }

  if (loading) return <main className="main"><div className="card customer-profile-empty">جاري جمع تاريخ العميل وحسابه وفواتيره...</div></main>;
  if (!data) return <main className="main"><div className="topbar"><div><h1 className="title">ملف العميل</h1><div className="subtitle">تعذر تحميل البيانات المطلوبة.</div></div><Link href="/customers" className="secondary"><ArrowRight size={14}/> العودة للعملاء</Link></div><div className="pos-alert error">{error || "العميل غير موجود أو ليس لديك صلاحية عرضه."}</div></main>;

  const { customer, summary } = data;
  const primaryPhone = customer.phone || customer.alternate_phone;
  const whatsApp = whatsappUrl(primaryPhone);

  async function saveCreditSettings() {
    setCreditSaving(true); setCreditError(""); setCreditMessage("");
    if (!Number.isFinite(Number(creditLimit)) || Number(creditLimit) < 0 || !Number.isInteger(Number(paymentTerms)) || Number(paymentTerms) < 0) {
      setCreditError("راجع حد الائتمان ومدة السداد."); setCreditSaving(false); return;
    }
    try {
      const updated = await api<Customer>(`/customers/${encodeURIComponent(customerId)}`, { method: "PATCH", body: JSON.stringify({ credit_enabled: creditEnabled, credit_limit: Number(creditLimit), payment_terms_days: Number(paymentTerms) }) });
      setData((current) => current ? { ...current, customer: { ...current.customer, ...updated } } : current);
      setCreditMessage(creditEnabled ? "تم تفعيل حساب الآجل وحفظ الحد ومدة السداد." : "تم إيقاف الآجل للعميل. الفواتير السابقة لم تتغير.");
    } catch (reason) { setCreditError(reason instanceof Error ? reason.message : "تعذر حفظ إعدادات الآجل."); }
    finally { setCreditSaving(false); }
  }

  return <main className="main customer-profile-page">
    <div className="topbar"><div><Link href="/customers" className="customer-back-link"><ArrowRight size={14}/> كل العملاء</Link><h1 className="title" style={{ marginTop: 8 }}>ملف العميل 360°</h1><div className="subtitle">كل الفواتير والتحصيلات والمرتجعات والزيارات في ملف واحد.</div></div><Link href="/sales" className="primary customer-profile-new-sale">بيع للعميل</Link></div>

    <section className="card customer-profile-hero">
      <div className="customer-profile-avatar">{customer.name.trim().slice(0, 1) || "ع"}</div>
      <div className="customer-profile-identity"><div className="customer-name-row"><h2>{customer.name}</h2><span className={`badge ${customer.status === "active" ? "green" : "amber"}`}>{customer.status === "active" ? "نشط" : "غير نشط"}</span></div><div className="customer-profile-meta"><span>{customer.code}</span><span><MapPin size={13}/>{customer.address_text || "العنوان غير مسجل"}</span><span><CalendarClock size={13}/>عميل منذ {date(customer.created_at)}</span></div><div className="customer-profile-contact">{primaryPhone ? <><a href={`tel:${primaryPhone}`}><Phone size={15}/> {primaryPhone}</a>{whatsApp && <a className="whatsapp-link" href={whatsApp} target="_blank" rel="noreferrer"><MessageCircle size={15}/> واتساب مباشر</a>}</> : <span>لا يوجد رقم تواصل مسجل</span>}</div></div>
      <div className="customer-credit-card"><span>حد الائتمان</span><strong>{money(customer.credit_limit)}</strong><small>مدة السداد: {Number(customer.payment_terms_days || 0).toLocaleString("ar-EG")} يوم</small></div>
    </section>

    <section className="card" style={{ marginBottom: 14 }}>
      <div className="customer-section-heading"><div><h2>حساب الآجل وحد الائتمان</h2><p>فعّل البيع الآجل لهذا العميل وحدد الحد الأقصى ومدة السداد.</p></div><span className={`badge ${creditEnabled ? "green" : "amber"}`}>{creditEnabled ? "الآجل مفعل" : "الآجل غير مفعل"}</span></div>
      {creditError && <div className="pos-alert error" role="alert">{creditError}</div>}{creditMessage && <div className="pos-alert success" role="status">{creditMessage}</div>}
      <div className="form-grid">
        <label style={{ display: "flex", alignItems: "center", gap: 9, flexDirection: "row" }}><input type="checkbox" checked={creditEnabled} onChange={(event) => setCreditEnabled(event.target.checked)} style={{ width: 18, height: 18 }} /> السماح بإنشاء فواتير آجلة</label>
        <label>حد الائتمان (ج.م)<input type="number" min="0" step="0.01" value={creditLimit} onChange={(event) => setCreditLimit(event.target.value)} /></label>
        <label>مدة السداد (يوم)<input type="number" min="0" step="1" value={paymentTerms} onChange={(event) => setPaymentTerms(event.target.value)} /></label>
      </div>
      <button type="button" className="primary" style={{ marginTop: 12 }} disabled={creditSaving} onClick={() => void saveCreditSettings()}>{creditSaving ? "جاري الحفظ..." : "حفظ إعدادات الآجل"}</button>
    </section>

    <div className="customer-profile-stats">
      <div className="card customer-stat"><span>إجمالي المبيعات</span><strong>{money(summary.sales_total)}</strong><small>{Number(summary.invoice_count || 0).toLocaleString("ar-EG")} فاتورة</small></div>
      <div className="card customer-stat customer-stat-due"><span>المتبقي في الحساب</span><strong>{money(summary.open_balance)}</strong><small>من أرصدة الفواتير الحالية</small></div>
      <div className="card customer-stat"><span>مدفوع على الفواتير</span><strong>{money(summary.paid_on_invoices)}</strong><small>كما هو مسجل على الفواتير</small></div>
      <div className="card customer-stat"><span>حركات التحصيل</span><strong>{money(summary.collections_total)}</strong><small>آخر تحصيل: {date(summary.last_collection_at)}</small></div>
      <div className="card customer-stat"><span>إجمالي المرتجعات</span><strong>{money(summary.returns_total)}</strong><small>آخر فاتورة: {date(summary.last_invoice_at)}</small></div>
    </div>

    <CustomerPricingPanel customerId={customerId}/>

    <section className="card customer-profile-ledger">
      <div className="customer-section-heading"><div><h2>سجل التعاملات</h2><p>{activityCount.toLocaleString("ar-EG")} عملية ظاهرة في هذا الملف</p></div><span className="badge blue">Customer 360</span></div>
      <div className="customer-profile-tabs" role="tablist" aria-label="سجل العميل">
        <button type="button" role="tab" aria-selected={tab === "invoices"} className={tab === "invoices" ? "selected" : ""} onClick={() => setTab("invoices")}><FileText size={15}/> الفواتير ({data.summary.invoice_count.toLocaleString("ar-EG")})</button>
        <button type="button" role="tab" aria-selected={tab === "payments"} className={tab === "payments" ? "selected" : ""} onClick={() => setTab("payments")}><WalletCards size={15}/> التحصيلات ({data.payments.total.toLocaleString("ar-EG")})</button>
        <button type="button" role="tab" aria-selected={tab === "returns"} className={tab === "returns" ? "selected" : ""} onClick={() => setTab("returns")}><RotateCcw size={15}/> المرتجعات ({data.returns.total.toLocaleString("ar-EG")})</button>
        <button type="button" role="tab" aria-selected={tab === "visits"} className={tab === "visits" ? "selected" : ""} onClick={() => setTab("visits")}><Clock3 size={15}/> الزيارات ({data.visits.total.toLocaleString("ar-EG")})</button>
      </div>

      {tab === "invoices" && <><div className="table-wrap"><table><thead><tr><th>رقم الفاتورة</th><th>التاريخ</th><th>الإجمالي</th><th>المدفوع</th><th>المتبقي</th><th>الحركة</th></tr></thead><tbody>{data.invoices.data.map((invoice) => <tr key={invoice.id}><td><Link href={`/invoices/${invoice.id}`} className="ledger-doc-link">{invoice.document_number}</Link></td><td>{date(invoice.invoice_date)}</td><td>{money(invoice.total)}</td><td>{money(invoice.paid_amount)}</td><td><strong className={Number(invoice.balance_due) > 0 ? "ledger-due" : "ledger-paid"}>{money(invoice.balance_due)}</strong></td><td><Link href={`/invoices/${invoice.id}`} className="ledger-open-link">عرض الفاتورة</Link></td></tr>)}{!data.invoices.data.length && <tr><td colSpan={6}>لا توجد فواتير مسجلة للعميل حتى الآن.</td></tr>}</tbody></table></div>{pageControls("invoices", data.invoices)}</>}

      {tab === "payments" && <><div className="table-wrap"><table><thead><tr><th>تاريخ التحصيل</th><th>المبلغ</th><th>المرجع</th><th>الحالة</th></tr></thead><tbody>{data.payments.data.map((payment) => <tr key={payment.id}><td>{date(payment.payment_date)}</td><td><strong className="ledger-paid">{money(payment.amount)}</strong></td><td>{payment.reference || "تحصيل مسجل"}</td><td><span className="badge green"><CheckCircle2 size={11}/> مرحّل</span></td></tr>)}{!data.payments.data.length && <tr><td colSpan={4}>لا توجد حركات تحصيل منفصلة مسجلة. المدفوعات الأولية على الفواتير تظهر في تبويب الفواتير.</td></tr>}</tbody></table></div>{pageControls("payments", data.payments)}</>}

      {tab === "returns" && <><div className="table-wrap"><table><thead><tr><th>رقم المرتجع</th><th>التاريخ</th><th>الفاتورة الأصلية</th><th>قيمة المرتجع</th></tr></thead><tbody>{data.returns.data.map((returnRow) => <tr key={returnRow.id}><td><strong>{returnRow.document_number}</strong></td><td>{date(returnRow.return_date)}</td><td>{returnRow.original_sales_invoice_id ? <Link href={`/invoices/${returnRow.original_sales_invoice_id}`} className="ledger-doc-link">{returnRow.original_invoice_number || "عرض الأصل"}</Link> : "—"}</td><td>{money(returnRow.total)}</td></tr>)}{!data.returns.data.length && <tr><td colSpan={4}>لا توجد مرتجعات مسجلة.</td></tr>}</tbody></table></div>{pageControls("returns", data.returns)}</>}

      {tab === "visits" && <><div className="table-wrap"><table><thead><tr><th>تاريخ الزيارة</th><th>الرحلة</th><th>الحالة</th><th>ملاحظات</th></tr></thead><tbody>{data.visits.data.map((visit) => <tr key={visit.id}><td>{date(visit.check_in_at || visit.created_at)}</td><td>{visit.trip_number}</td><td>{visit.status}</td><td>{visit.notes || "—"}</td></tr>)}{!data.visits.data.length && <tr><td colSpan={4}>لا توجد زيارات ميدانية مسجلة للعميل.</td></tr>}</tbody></table></div>{pageControls("visits", data.visits)}</>}
    </section>

    {customer.notes && <section className="card customer-notes"><h2>ملاحظات العميل</h2><p>{customer.notes}</p></section>}
  </main>;
}
