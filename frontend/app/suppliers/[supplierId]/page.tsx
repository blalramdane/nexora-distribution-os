"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowRight, CheckCircle2, FileText, MessageCircle, Package, Phone, RotateCcw, WalletCards, X, Save, Search, Percent, PlusCircle } from "lucide-react";
import { api } from "@/lib/api";
import InvoiceSharePanel from "@/app/components/invoice-share-panel";
import CompanyInvoiceHeader from "@/app/components/company-invoice-header";

type Supplier = { id: string; code: string; name: string; phone?: string | null; address?: string | null; tax_identifier?: string | null; credit_terms_days?: number; active: boolean; created_at: string };
type Summary = { invoice_count: number; purchases_total: number; paid_on_invoices: number; payments_total: number; returns_total: number; open_balance: number; last_purchase_at?: string | null; last_payment_at?: string | null; sell_through?: { sold_quantity_base:number; returned_quantity_base:number; net_quantity_base:number; sold_source_cost:number; returned_source_cost:number; settlement_amount:number; paid_amount:number; open_amount:number } };
type Invoice = { id: string; document_number: string; supplier_invoice_number?: string | null; invoice_date: string; total: string | number; paid_amount: string | number; currency: string; created_at: string; location_name?: string | null; returned_total: number; remaining_payable: number };
type ReturnRow = { id: string; document_number: string; return_date: string; total: string | number; currency: string; original_purchase_invoice_id?: string | null; original_invoice_number?: string | null };
type Payment = { id: string; amount: string | number; currency: string; payment_date: string; reference?: string | null; created_at: string };
type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
type Supplier360 = { supplier: Supplier; summary: Summary; invoices: Page<Invoice>; returns: Page<ReturnRow>; payments: Page<Payment> };
type InvoiceItem = { id: string; product_name_snapshot: string; sku_snapshot: string; entered_quantity: string | number; quantity_base: string | number; conversion_factor_snapshot: string | number; unit_cost_entered: string | number };
type SettlementProduct = { product_id:string; sku:string; name_ar:string; base_unit_id:string; supplier_product_id?:string|null; settlement_method:"purchase_debt"|"sold_goods"; settlement_unit_price:string|number|null };
type SupplierRebate = { id:string; document_number:string; period_from:string; period_to:string; currency:string; invoice_count:number; calculation_base:number|string; rate_percent:number|string; rebate_amount:number|string; status:"accrued"|"settled"|"voided"; notes?:string|null; created_at:string; created_by_name?:string|null; invoice_numbers?:string[] };
type Tab = "invoices" | "payments" | "returns";
const money = (value: string | number | null | undefined, currency = "EGP") => `${(Number(value) || 0).toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ${currency === "EGP" ? "ج.م" : currency}`;
const date = (value?: string | null) => value ? new Date(value).toLocaleDateString("ar-EG", { year: "numeric", month: "short", day: "numeric" }) : "—";

function whatsappUrl(phone?: string | null) {
  let digits = (phone ?? "").replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("0")) digits = `20${digits.slice(1)}`;
  return digits ? `https://wa.me/${digits}` : "";
}

export default function SupplierProfilePage() {
  const params = useParams<{ supplierId: string }>();
  const supplierId = params.supplierId;
  const [data, setData] = useState<Supplier360 | null>(null);
  const [tab, setTab] = useState<Tab>("invoices");
  const [pages, setPages] = useState({ invoices: 1, payments: 1, returns: 1 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedInvoice, setSelectedInvoice] = useState<Invoice | null>(null);
  const [invoiceItems, setInvoiceItems] = useState<InvoiceItem[]>([]);
  const [invoiceLoading, setInvoiceLoading] = useState(false);
  const [policyProducts, setPolicyProducts] = useState<SettlementProduct[]>([]);
  const [policySearch, setPolicySearch] = useState("");
  const [policyLoading, setPolicyLoading] = useState(false);
  const [policySavingId, setPolicySavingId] = useState<string | null>(null);
  const [policyMessage, setPolicyMessage] = useState("");
  const [policyError, setPolicyError] = useState("");
  const [rebates, setRebates] = useState<SupplierRebate[]>([]);
  const [rebatesLoading, setRebatesLoading] = useState(true);
  const [rebateSaving, setRebateSaving] = useState(false);
  const [rebateRate, setRebateRate] = useState("");
  const [rebateNotes, setRebateNotes] = useState("");
  const [rebateError, setRebateError] = useState("");
  const [rebateMessage, setRebateMessage] = useState("");
  const [rebatePeriodFrom, setRebatePeriodFrom] = useState(() => {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    return today.slice(0, 8) + "01";
  });
  const [rebatePeriodTo, setRebatePeriodTo] = useState(() => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10));

  useEffect(() => {
    if (!supplierId) return;
    let cancelled = false;
    setLoading(true);
    const query = new URLSearchParams({ per_page: "25", invoices_page: String(pages.invoices), payments_page: String(pages.payments), returns_page: String(pages.returns) });
    api<Supplier360>(`/suppliers/${encodeURIComponent(supplierId)}/360?${query.toString()}`)
      .then((result) => { if (!cancelled) setData(result); })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر تحميل ملف المورد."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [supplierId, pages]);

  useEffect(() => {
    if (!supplierId) return;
    let cancelled = false;
    setRebatesLoading(true);
    api<SupplierRebate[]>(`/suppliers/${encodeURIComponent(supplierId)}/rebates`)
      .then((rows) => { if (!cancelled) setRebates(rows); })
      .catch((reason: unknown) => { if (!cancelled) setRebateError(reason instanceof Error ? reason.message : "تعذر تحميل خصومات المورد."); })
      .finally(() => { if (!cancelled) setRebatesLoading(false); });
    return () => { cancelled = true; };
  }, [supplierId]);

  async function createSupplierRebate(event: React.FormEvent) {
    event.preventDefault();
    setRebateSaving(true); setRebateError(""); setRebateMessage("");
    const rate = Number(rebateRate);
    if (!rebatePeriodFrom || !rebatePeriodTo || rebatePeriodTo < rebatePeriodFrom || !Number.isFinite(rate) || rate <= 0 || rate > 100) {
      setRebateError("راجع الفترة، واكتب نسبة خصم أكبر من 0 وحتى 100%.");
      setRebateSaving(false); return;
    }
    try {
      const created = await api<SupplierRebate & { accounting_note?:string }>(`/suppliers/${encodeURIComponent(supplierId)}/rebates`, {
        method: "POST",
        body: JSON.stringify({ period_from: rebatePeriodFrom, period_to: rebatePeriodTo, rate_percent: rate, notes: rebateNotes.trim() || null }),
      });
      setRebates((current) => [created, ...current]);
      setRebateMessage(`تم تسجيل خصم ${Number(created.rate_percent).toLocaleString("ar-EG", { maximumFractionDigits: 4 })}% بقيمة ${money(created.rebate_amount)} على ${Number(created.invoice_count).toLocaleString("ar-EG")} فاتورة، من إجمالي ${money(created.calculation_base)}. الفواتير دي اتقفلت من إعادة استخدامها في خصم آخر.`);
      setRebateRate(""); setRebateNotes("");
    } catch (reason) {
      setRebateError(reason instanceof Error ? reason.message : "تعذر احتساب خصم المورد.");
    } finally { setRebateSaving(false); }
  }

  async function loadProductPolicies(query = policySearch) {
    if (!supplierId) return;
    setPolicyLoading(true);
    setPolicyError("");
    try {
      const params = new URLSearchParams({ limit: "300" });
      if (query.trim()) params.set("q", query.trim());
      const response = await api<{ products: SettlementProduct[] }>(`/suppliers/${encodeURIComponent(supplierId)}/products?${params.toString()}`);
      setPolicyProducts(response.products.map((item) => ({ ...item, settlement_unit_price: item.settlement_unit_price ?? "" })));
    } catch (reason) {
      setPolicyError(reason instanceof Error ? reason.message : "تعذر تحميل سياسات تسوية الأصناف.");
    } finally {
      setPolicyLoading(false);
    }
  }

  useEffect(() => {
    void loadProductPolicies("");
    // Load the initial product policies only when the selected supplier changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [supplierId]);

  async function saveProductPolicy(product: SettlementProduct) {
    setPolicySavingId(product.product_id);
    setPolicyMessage("");
    setPolicyError("");
    try {
      const rate = product.settlement_unit_price === "" || product.settlement_unit_price === null
        ? null
        : Number(product.settlement_unit_price);
      if (rate !== null && (!Number.isFinite(rate) || rate < 0)) {
        setPolicyError("سعر التسوية لازم يكون صفر أو أكثر، أو سيبه فارغًا لاستخدام تكلفة مصدر البضاعة.");
        return;
      }
      await api(`/suppliers/${encodeURIComponent(supplierId)}/products/${encodeURIComponent(product.product_id)}/settlement`, {
        method: "PUT",
        body: JSON.stringify({
          settlement_method: product.settlement_method,
          settlement_unit_price: product.settlement_method === "sold_goods" ? rate : null,
        }),
      });
      setPolicyMessage(`تم حفظ سياسة الصنف «${product.name_ar}». السياسة الجديدة تسري على فواتير الشراء القادمة فقط.`);
    } catch (reason) {
      setPolicyError(reason instanceof Error ? reason.message : "تعذر حفظ سياسة المورد للصنف.");
    } finally {
      setPolicySavingId(null);
    }
  }

  function pageControls(key: keyof typeof pages, current: Page<unknown>) {
    if (current.total <= current.per_page) return null;
    return <div className="pos-pagination customer-history-pagination"><span>عرض {(current.from ?? 0).toLocaleString("ar-EG")}–{(current.to ?? 0).toLocaleString("ar-EG")} من {current.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={current.current_page <= 1} onClick={() => setPages((state) => ({ ...state, [key]: Math.max(1, state[key] - 1) }))}><ArrowRight size={14}/> السابق</button><span className="customer-page-index">{current.current_page.toLocaleString("ar-EG")} / {current.last_page.toLocaleString("ar-EG")}</span><button type="button" disabled={current.current_page >= current.last_page} onClick={() => setPages((state) => ({ ...state, [key]: Math.min(current.last_page, state[key] + 1) }))}>التالي <ArrowRight size={14} className="customer-next-arrow"/></button></div></div>;
  }

  async function openInvoice(invoice: Invoice) {
    setSelectedInvoice(invoice);
    setInvoiceItems([]);
    setInvoiceLoading(true);
    try {
      const items = await api<InvoiceItem[]>(`/purchases/invoices/${encodeURIComponent(invoice.id)}/items`);
      setInvoiceItems(items);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر عرض بنود الفاتورة.");
    } finally {
      setInvoiceLoading(false);
    }
  }

  async function openOriginalInvoice(invoiceId: string) {
    const current = data?.invoices.data.find((invoice) => invoice.id === invoiceId);
    if (current) {
      await openInvoice(current);
      return;
    }
    setError("");
    try {
      const invoice = await api<Invoice>(`/purchases/invoices/${encodeURIComponent(invoiceId)}`);
      await openInvoice(invoice);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر فتح الفاتورة الأصلية للمرتجع.");
    }
  }

  if (loading) return <main className="main"><div className="card customer-profile-empty">جاري جمع فواتير المورد ومدفوعاته ومرتجعاته...</div></main>;
  if (!data) return <main className="main"><div className="topbar"><h1 className="title">ملف المورد</h1><Link href="/suppliers" className="secondary"><ArrowRight size={14}/> العودة للموردين</Link></div><div className="pos-alert error">{error || "المورد غير موجود أو ليس لديك صلاحية عرضه."}</div></main>;

  const { supplier, summary } = data;
  const wa = whatsappUrl(supplier.phone);

  return <main className="main customer-profile-page">
    <div className="topbar"><div><Link href="/suppliers" className="customer-back-link"><ArrowRight size={14}/> كل الموردين</Link><h1 className="title" style={{ marginTop: 8 }}>ملف المورد والحساب</h1><div className="subtitle">كل فواتير الشراء والمدفوعات والمرتجعات والرصيد المتبقي.</div></div><Link href="/purchases" className="primary customer-profile-new-sale">تسجيل فاتورة شراء</Link></div>

    <section className="card customer-profile-hero">
      <div className="customer-profile-avatar supplier-profile-avatar"><Package size={24}/></div>
      <div className="customer-profile-identity"><div className="customer-name-row"><h2>{supplier.name}</h2><span className={`badge ${supplier.active ? "green" : "amber"}`}>{supplier.active ? "نشط" : "غير نشط"}</span></div><div className="customer-profile-meta"><span>{supplier.code}</span><span>{supplier.tax_identifier ? `رقم ضريبي: ${supplier.tax_identifier}` : "رقم ضريبي غير مسجل"}</span><span>{supplier.address || "العنوان غير مسجل"}</span></div><div className="customer-profile-contact">{supplier.phone ? <><a href={`tel:${supplier.phone}`}><Phone size={15}/> {supplier.phone}</a>{wa && <a className="whatsapp-link" href={wa} target="_blank" rel="noreferrer"><MessageCircle size={15}/> واتساب مباشر</a>}</> : <span>لا يوجد رقم تواصل مسجل</span>}</div></div>
      <div className="customer-credit-card"><span>شروط السداد</span><strong>{Number(supplier.credit_terms_days || 0).toLocaleString("ar-EG")} يوم</strong><small>آخر شراء: {date(summary.last_purchase_at)}</small></div>
    </section>

    <div className="customer-profile-stats">
      <div className="card customer-stat"><span>إجمالي المشتريات</span><strong>{money(summary.purchases_total)}</strong><small>{Number(summary.invoice_count || 0).toLocaleString("ar-EG")} فاتورة</small></div>
      <div className="card customer-stat customer-stat-due"><span>المتبقي للمورد</span><strong>{money(summary.open_balance)}</strong><small>بعد المدفوع والمرتجعات</small></div>
      <div className="card customer-stat"><span>المدفوع على الفواتير</span><strong>{money(summary.paid_on_invoices)}</strong><small>كما هو مسجل على الفواتير</small></div>
      <div className="card customer-stat"><span>حركات السداد</span><strong>{money(summary.payments_total)}</strong><small>آخر سداد: {date(summary.last_payment_at)}</small></div>
      <div className="card customer-stat"><span>إجمالي المرتجعات</span><strong>{money(summary.returns_total)}</strong><small>مرتبطة بفواتير الشراء الأصلية</small></div>
      <div className="card customer-stat"><span>صافي المنتجات المباعة في الرحلات المقفولة</span><strong>{Number(summary.sell_through?.net_quantity_base || 0).toLocaleString("ar-EG")}</strong><small>المباع {Number(summary.sell_through?.sold_quantity_base || 0).toLocaleString("ar-EG")} · المرتجع {Number(summary.sell_through?.returned_quantity_base || 0).toLocaleString("ar-EG")}</small></div>
      <div className="card customer-stat"><span>تكلفة مصدر البضاعة المباعة</span><strong>{money(Number(summary.sell_through?.sold_source_cost || 0) - Number(summary.sell_through?.returned_source_cost || 0))}</strong><small>تكلفة المصدر الفعلية، وليست بالضرورة سعر التسوية المتفق عليه.</small></div>
      <div className="card customer-stat"><span>إجمالي مستحق البيع حسب الاتفاق</span><strong>{money(summary.sell_through?.settlement_amount || 0)}</strong><small>مستحقات الأصناف التي سياستها «الدفع حسب البيع» فقط.</small></div>
      <div className="card customer-stat customer-stat-due"><span>المتبقي من تسويات الرحلات</span><strong>{money(summary.sell_through?.open_amount || 0)}</strong><small>إجمالي تسويات الرحلات مطروحًا منه السداد المرتبط بها مباشرة.</small></div>
    </div>

    <section className="card customer-profile-ledger" style={{ marginBottom: 16 }}>
      <div className="customer-section-heading"><div><h2><Percent size={16} style={{ verticalAlign: "middle", marginLeft: 5 }}/> خصم المورد المستحق للمنشأة</h2><p>احتسب نسبة خصم على إجمالي فواتير الشراء المرحلة خلال فترة، مع حفظ الفواتير الداخلة في الحساب ومنع تكرار احتسابها.</p></div><span className="badge blue">{rebates.length.toLocaleString("ar-EG")} سجل خصم</span></div>
      <div className="trip-context" style={{ marginBottom: 12, whiteSpace: "normal" }}>الأساس هو إجمالي فواتير الشراء المرحلة بعد خصم الفاتورة والضريبة، مع استبعاد مرتجعات الشراء المرحلة المرتبطة بها وقت الاحتساب. يتم احتساب الفواتير غير المستخدمة في خصم سابق فقط، وبعملة واحدة لكل سجل. الرصيد هنا خصم مستحق للمراجعة؛ لا يسجل قبضًا نقديًا ولا يخصم تلقائيًا من مديونية المورد أو قيود الأستاذ.</div>
      {rebateError && <div className="pos-alert error" role="alert" style={{ marginBottom: 10 }}>{rebateError}</div>}
      {rebateMessage && <div className="pos-alert success" role="status" style={{ marginBottom: 10, whiteSpace: "normal" }}>{rebateMessage}</div>}
      <form onSubmit={createSupplierRebate} style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(150px, 1fr))", gap: 9, alignItems: "end", marginBottom: 14 }}>
        <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>من تاريخ<input aria-label="بداية فترة خصم المورد" type="date" value={rebatePeriodFrom} max={rebatePeriodTo || undefined} onChange={(event) => setRebatePeriodFrom(event.target.value)} required/></label>
        <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>إلى تاريخ<input aria-label="نهاية فترة خصم المورد" type="date" value={rebatePeriodTo} min={rebatePeriodFrom || undefined} onChange={(event) => setRebatePeriodTo(event.target.value)} required/></label>
        <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>نسبة الخصم %<input aria-label="نسبة خصم المورد" type="number" min="0.0001" max="100" step="0.0001" value={rebateRate} onChange={(event) => setRebateRate(event.target.value)} placeholder="مثال: 5" required/></label>
        <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>ملاحظات / مرجع<input aria-label="ملاحظات خصم المورد" value={rebateNotes} onChange={(event) => setRebateNotes(event.target.value)} maxLength={2000} placeholder="رقم اتفاق أو ملاحظة اختيارية"/></label>
        <button className="primary" type="submit" disabled={rebateSaving || rebatesLoading} style={{ width: "auto", minHeight: 40, padding: "9px 13px" }}><PlusCircle size={14}/>{rebateSaving ? "جاري حساب الخصم..." : "احتساب وحفظ الخصم"}</button>
      </form>
      <div className="table-wrap"><table><thead><tr><th>رقم الخصم</th><th>الفترة</th><th>الفواتير</th><th>إجمالي الأساس</th><th>النسبة</th><th>الخصم المستحق</th><th>الحالة</th></tr></thead><tbody>
        {rebatesLoading ? <tr><td colSpan={7}>جاري تحميل سجلات الخصومات...</td></tr> : rebates.map((rebate) => <tr key={rebate.id}><td><strong>{rebate.document_number}</strong><small style={{ display: "block", color: "#64748b" }}>{rebate.created_by_name || ""}</small></td><td>{date(rebate.period_from)} – {date(rebate.period_to)}</td><td>{Number(rebate.invoice_count).toLocaleString("ar-EG")}<small style={{ display: "block", color: "#64748b" }}>{rebate.invoice_numbers?.slice(0, 3).join("، ")}{(rebate.invoice_numbers?.length || 0) > 3 ? " …" : ""}</small></td><td>{money(rebate.calculation_base)}</td><td>{Number(rebate.rate_percent).toLocaleString("ar-EG", { maximumFractionDigits: 4 })}%</td><td><strong className="ledger-paid">{money(rebate.rebate_amount, rebate.currency)}</strong></td><td><span className={`badge ${rebate.status === "accrued" ? "amber" : rebate.status === "settled" ? "green" : "red"}`}>{rebate.status === "accrued" ? "مستحق للمراجعة" : rebate.status === "settled" ? "تمت التسوية خارج النظام" : "ملغي"}</span></td></tr>)}
        {!rebatesLoading && !rebates.length && <tr><td colSpan={7}>لا توجد خصومات محفوظة. حدد الفترة ونسبة الخصم لإظهار سجل مستحق مستقل.</td></tr>}
      </tbody></table></div>
    </section>

    <section className="card customer-profile-ledger" style={{ marginBottom: 16 }}>
      <div className="customer-section-heading"><div><h2>سياسة تسوية الأصناف مع المورد</h2><p>حدد هل مستحق المورد يظهر عند شراء الصنف، أم عند بيع كمياته وإقفال الرحلة.</p></div><span className="badge blue">إعدادات مستقبلية</span></div>
      <div className="trip-list-search" style={{ marginBottom: 12 }}><Search size={15}/><input aria-label="بحث أصناف المورد" value={policySearch} onChange={(event) => setPolicySearch(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") { event.preventDefault(); void loadProductPolicies(policySearch); } }} placeholder="ابحث باسم الصنف أو الكود..."/><button type="button" className="secondary" onClick={() => void loadProductPolicies(policySearch)} disabled={policyLoading}>{policyLoading ? "جاري البحث..." : "بحث"}</button></div>
      {policyMessage && <div role="status" className="badge green" style={{ display: "block", padding: 10, marginBottom: 10, whiteSpace: "normal" }}>{policyMessage}</div>}
      {policyError && <div role="alert" className="badge red" style={{ display: "block", padding: 10, marginBottom: 10, whiteSpace: "normal" }}>{policyError}</div>}
      <div className="trip-context" style={{ marginBottom: 12, whiteSpace: "normal" }}>«المستحق عند الشراء» يسجل قيمة الصنف في ذمم الفاتورة. «المستحق حسب البيع» يؤجل الاستحقاق حتى إقفال الرحلة التي باعت الصنف. سعر التسوية اختياري لكل وحدة أساسية؛ لو اتساب فارغًا يستخدم النظام تكلفة المصدر المسجلة للكمية المباعة. الإعداد الجديد لا يعيد حساب الفواتير المرحلة سابقًا.</div>
      {policyLoading && !policyProducts.length && <div className="customer-profile-empty">جاري تحميل أصناف المورد...</div>}
      <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>سياسة الاستحقاق</th><th>سعر التسوية لكل وحدة أساسية (ج.م)</th><th>حفظ</th></tr></thead><tbody>
        {policyProducts.map((product) => <tr key={product.product_id}>
          <td><strong>{product.name_ar}</strong><small style={{ display: "block", color: "#64748b" }}>{product.sku}</small></td>
          <td><select aria-label={`سياسة التسوية ${product.name_ar}`} value={product.settlement_method} onChange={(event) => setPolicyProducts((items) => items.map((item) => item.product_id === product.product_id ? { ...item, settlement_method: event.target.value as SettlementProduct["settlement_method"], settlement_unit_price: event.target.value === "purchase_debt" ? "" : item.settlement_unit_price } : item))}><option value="purchase_debt">المستحق عند الشراء</option><option value="sold_goods">المستحق حسب البيع وإقفال الرحلة</option></select></td>
          <td><input aria-label={`سعر التسوية ${product.name_ar}`} type="number" min="0" step="0.01" disabled={product.settlement_method !== "sold_goods"} value={product.settlement_unit_price ?? ""} onChange={(event) => setPolicyProducts((items) => items.map((item) => item.product_id === product.product_id ? { ...item, settlement_unit_price: event.target.value } : item))} placeholder="فارغ = تكلفة المصدر"/></td>
          <td><button type="button" className="secondary" disabled={policySavingId === product.product_id || policyLoading} onClick={() => void saveProductPolicy(product)}><Save size={13}/>{policySavingId === product.product_id ? "جاري الحفظ" : "حفظ السياسة"}</button></td>
        </tr>)}
        {!policyLoading && !policyProducts.length && <tr><td colSpan={4}>لا توجد أصناف مرتبطة بفواتير هذا المورد. سجّل فاتورة شراء أو ابحث عن صنف بعد ربطه بالمورد.</td></tr>}
      </tbody></table></div>
    </section>

    <section className="card customer-profile-ledger">
      <div className="customer-section-heading"><div><h2>سجل التعاملات مع المورد</h2><p>اختار أي فاتورة لعرض بنودها وتكلفتها وتاريخ استلامها.</p></div><span className="badge blue">Supplier 360</span></div>
      <div className="customer-profile-tabs" role="tablist" aria-label="سجل المورد">
        <button type="button" role="tab" aria-selected={tab === "invoices"} className={tab === "invoices" ? "selected" : ""} onClick={() => setTab("invoices")}><FileText size={15}/> فواتير الشراء ({data.invoices.total.toLocaleString("ar-EG")})</button>
        <button type="button" role="tab" aria-selected={tab === "payments"} className={tab === "payments" ? "selected" : ""} onClick={() => setTab("payments")}><WalletCards size={15}/> المدفوعات ({data.payments.total.toLocaleString("ar-EG")})</button>
        <button type="button" role="tab" aria-selected={tab === "returns"} className={tab === "returns" ? "selected" : ""} onClick={() => setTab("returns")}><RotateCcw size={15}/> مرتجعات المورد ({data.returns.total.toLocaleString("ar-EG")})</button>
      </div>

      {tab === "invoices" && <><div className="table-wrap"><table><thead><tr><th>فاتورة النظام</th><th>فاتورة المورد</th><th>التاريخ</th><th>الموقع</th><th>الإجمالي</th><th>مرتجع</th><th>المتبقي</th><th></th></tr></thead><tbody>{data.invoices.data.map((invoice) => <tr key={invoice.id}><td><button type="button" className="ledger-doc-button" onClick={() => void openInvoice(invoice)}>{invoice.document_number}</button></td><td>{invoice.supplier_invoice_number || "—"}</td><td>{date(invoice.invoice_date)}</td><td>{invoice.location_name || "—"}</td><td>{money(invoice.total)}</td><td>{money(invoice.returned_total)}</td><td><strong className={invoice.remaining_payable > 0 ? "ledger-due" : "ledger-paid"}>{money(invoice.remaining_payable)}</strong></td><td><button type="button" className="ledger-open-link" onClick={() => void openInvoice(invoice)}>عرض البنود</button></td></tr>)}{!data.invoices.data.length && <tr><td colSpan={8}>لا توجد فواتير شراء مسجلة للمورد حتى الآن.</td></tr>}</tbody></table></div>{pageControls("invoices", data.invoices)}</>}

      {tab === "payments" && <><div className="table-wrap"><table><thead><tr><th>تاريخ السداد</th><th>المبلغ</th><th>المرجع</th><th>الحالة</th></tr></thead><tbody>{data.payments.data.map((payment) => <tr key={payment.id}><td>{date(payment.payment_date)}</td><td><strong className="ledger-paid">{money(payment.amount)}</strong></td><td>{payment.reference || "سداد مسجل"}</td><td><span className="badge green"><CheckCircle2 size={11}/> مرحّل</span></td></tr>)}{!data.payments.data.length && <tr><td colSpan={4}>لا توجد حركات سداد منفصلة مسجلة. راجع مبالغ السداد المسجلة على الفواتير.</td></tr>}</tbody></table></div>{pageControls("payments", data.payments)}</>}

      {tab === "returns" && <><div className="table-wrap"><table><thead><tr><th>رقم المرتجع</th><th>التاريخ</th><th>الفاتورة الأصلية</th><th>قيمة المرتجع</th></tr></thead><tbody>{data.returns.data.map((returnRow) => <tr key={returnRow.id}><td><strong>{returnRow.document_number}</strong></td><td>{date(returnRow.return_date)}</td><td>{returnRow.original_purchase_invoice_id ? <button type="button" className="ledger-doc-button" onClick={() => void openOriginalInvoice(returnRow.original_purchase_invoice_id!)}>{returnRow.original_invoice_number || "عرض الأصل"}</button> : "—"}</td><td>{money(returnRow.total)}</td></tr>)}{!data.returns.data.length && <tr><td colSpan={4}>لا توجد مرتجعات مسجلة.</td></tr>}</tbody></table></div>{pageControls("returns", data.returns)}</>}
    </section>

    {selectedInvoice && <div className="customer-modal-backdrop no-print" role="presentation" onClick={(event) => { if (event.target === event.currentTarget) setSelectedInvoice(null); }}><section className="card customer-invoice-modal" role="dialog" aria-modal="true" aria-label={`بنود فاتورة ${selectedInvoice.document_number}`}><div className="customer-section-heading no-print"><div><h2>فاتورة {selectedInvoice.document_number}</h2><p>فاتورة المورد: {selectedInvoice.supplier_invoice_number || "بدون رقم خارجي"} · {date(selectedInvoice.invoice_date)}</p></div><button type="button" className="icon-btn" onClick={() => setSelectedInvoice(null)} aria-label="إغلاق"><X size={16}/></button></div>
      <article className="invoice-sheet invoice-sheet-premium company-invoice-sheet" dir="rtl">
        <CompanyInvoiceHeader documentKind="فاتورة شراء · PURCHASE INVOICE" number={selectedInvoice.document_number} date={selectedInvoice.invoice_date} location={selectedInvoice.location_name} extra={selectedInvoice.supplier_invoice_number ? `رقم فاتورة المورد: ${selectedInvoice.supplier_invoice_number}` : null}/>
        <section className="invoice-customer"><div className="invoice-customer-details"><span>بيانات المورد <small>SUPPLIER</small></span><strong>{supplier.name}</strong>{supplier.code && <small><b>كود المورد</b> {supplier.code}</small>}{supplier.address && <small><b>العنوان</b> {supplier.address}</small>}{supplier.phone && <small><b>الهاتف</b> <span dir="ltr">{supplier.phone}</span></small>}</div><div className="invoice-status-panel"><span className="invoice-status">فاتورة شراء مسجلة</span><small>حالة السداد</small><strong>{Number(selectedInvoice.remaining_payable) > 0 ? "متبقي للمورد" : "مسددة بالكامل"}</strong><b>{money(selectedInvoice.remaining_payable)}</b></div></section>
        <div className="invoice-lines-heading"><strong>تفاصيل الأصناف المستلمة</strong><span>PURCHASE DETAILS</span></div>
        {invoiceLoading ? <div className="customer-profile-empty">جاري تحميل البنود...</div> : <div className="invoice-table-wrap"><table className="invoice-table"><thead><tr><th>#</th><th>الصنف</th><th>كود الصنف</th><th>الكمية المدخلة</th><th>الكمية الأساسية</th><th>تكلفة الوحدة</th><th>الإجمالي</th></tr></thead><tbody>{invoiceItems.map((item, index) => <tr key={item.id}><td>{index + 1}</td><td>{item.product_name_snapshot}</td><td>{item.sku_snapshot}</td><td>{Number(item.entered_quantity).toLocaleString("ar-EG", { maximumFractionDigits: 3 })}</td><td>{Number(item.quantity_base).toLocaleString("ar-EG", { maximumFractionDigits: 3 })}</td><td>{money(item.unit_cost_entered)}</td><td>{money(Number(item.entered_quantity) * Number(item.unit_cost_entered))}</td></tr>)}{!invoiceItems.length && <tr><td colSpan={7}>لا توجد بنود لعرضها.</td></tr>}</tbody></table></div>}
        <section className="invoice-totals"><div className="invoice-grand-total"><span>إجمالي الفاتورة</span><strong>{money(selectedInvoice.total)}</strong></div><div><span>المدفوع</span><strong>{money(selectedInvoice.paid_amount)}</strong></div><div className="invoice-balance"><span>المتبقي للمورد</span><strong>{money(selectedInvoice.remaining_payable)}</strong></div><div><span>إجمالي المرتجعات المرتبطة</span><strong>{money(selectedInvoice.returned_total)}</strong></div></section>
        <footer className="invoice-footer company-invoice-footer"><strong>شركة الحياة للتوكيلات والتسويق</strong><span>أكبر مسوق للبراندات في مصر</span><span dir="ltr">01099965065 • 01270626723</span><small>مستند مشتريات داخلي للمراجعة والتسوية مع المورد.</small></footer>
      </article>
      <div className="no-print" style={{ display: "flex", flexWrap: "wrap", gap: 8, marginTop: 12 }}><button type="button" className="secondary" onClick={() => window.print()}>طباعة / حفظ PDF</button><InvoiceSharePanel invoiceType="purchase" invoiceId={selectedInvoice.id} recipientPhone={supplier.phone}/></div>
    </section></div>}
    {error && <div className="pos-alert error" role="status">{error}</div>}
  </main>;
}
