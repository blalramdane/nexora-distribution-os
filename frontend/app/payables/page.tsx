"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowDownLeft, ArrowRight, CalendarDays, CheckCircle2, ChevronLeft, ChevronRight, FileText, RefreshCw, Search, Truck, WalletCards, X } from "lucide-react";
import { api } from "@/lib/api";

type PayableInvoice = {
  id: string; document_number: string; supplier_invoice_number?: string | null; invoice_date: string;
  total: string | number; paid_amount: string | number; returned_total: string | number; remaining_payable: string | number;
  currency: string; supplier_id: string; supplier_name: string; supplier_code: string;
};
type Pagination = { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
type PayablesResponse = { invoices: PayableInvoice[]; pagination: Pagination; total_payable: number; open_invoice_count: number; supplier_count: number };
type InvoiceDetail = { id: string; document_number: string; supplier_id: string; supplier_invoice_number?: string | null; invoice_date: string; total: number | string; paid_amount: number | string; currency: string; created_at: string; location_name?: string | null; returned_total: number; remaining_payable: number };
type InvoiceItem = { id: string; product_name_snapshot: string; sku_snapshot: string; entered_quantity: number | string; quantity_base: number | string; conversion_factor_snapshot: number | string; unit_cost_entered: number | string; returned_quantity_base: number | string; remaining_quantity_base: number | string };
const money = (value: number | string) => Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const date = (value: string) => value ? new Date(value).toLocaleDateString("ar-EG", { year: "numeric", month: "short", day: "numeric" }) : "—";

export default function PayablesPage() {
  const [invoices, setInvoices] = useState<PayableInvoice[]>([]);
  const [pagination, setPagination] = useState<Pagination>({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
  const [totalPayable, setTotalPayable] = useState(0);
  const [openInvoiceCount, setOpenInvoiceCount] = useState(0);
  const [supplierCount, setSupplierCount] = useState(0);
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [refreshVersion, setRefreshVersion] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedInvoice, setSelectedInvoice] = useState<InvoiceDetail | null>(null);
  const [selectedItems, setSelectedItems] = useState<InvoiceItem[]>([]);
  const [invoiceLoading, setInvoiceLoading] = useState(false);
  const [invoiceError, setInvoiceError] = useState("");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
      if (query.trim()) params.set("q", query.trim());
      const result = await api<PayablesResponse>(`/purchases/open-balances?${params.toString()}`);
      setInvoices(result.invoices);
      setPagination(result.pagination);
      setTotalPayable(Number(result.total_payable || 0));
      setOpenInvoiceCount(Number(result.open_invoice_count || 0));
      setSupplierCount(Number(result.supplier_count || 0));
    } catch (reason) {
      setError((reason as Error).message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => { void load(); }, query.trim() ? 220 : 0);
    return () => clearTimeout(timer);
  }, [query, page, perPage, refreshVersion]);

  const openInvoice = async (invoiceId: string) => {
    setSelectedInvoice(null);
    setSelectedItems([]);
    setInvoiceError("");
    setInvoiceLoading(true);
    try {
      const [invoice, items] = await Promise.all([
        api<InvoiceDetail>(`/purchases/invoices/${encodeURIComponent(invoiceId)}`),
        api<InvoiceItem[]>(`/purchases/invoices/${encodeURIComponent(invoiceId)}/items`),
      ]);
      setSelectedInvoice(invoice);
      setSelectedItems(items);
    } catch (reason) {
      setInvoiceError((reason as Error).message);
    } finally {
      setInvoiceLoading(false);
    }
  };

  return <main className="main payables-page">
    <div className="topbar">
      <div><h1 className="title">مستحقات الموردين</h1><div className="subtitle">اعرف مين له فلوس، أنهي فاتورة عليها رصيد، وإيه اللي اتدفع أو اترد — من غير التنقل بين شاشات كتير.</div></div>
      <div className="payables-header-actions"><Link href="/purchases" className="primary payables-action-link"><ArrowRight size={14}/> تسجيل شراء</Link><button className="secondary" disabled={loading} onClick={() => setRefreshVersion((value) => value + 1)}><RefreshCw size={13}/> تحديث</button></div>
    </div>

    {error && <div role="alert" className="pos-alert error">{error} <button type="button" className="payables-inline-retry" onClick={() => setRefreshVersion((value) => value + 1)}>حاول تاني</button></div>}

    <section className="payables-summary-grid">
      <div className="card payables-summary-card payable-total-card"><span><WalletCards size={16}/> إجمالي المستحق للموردين</span><strong>{money(totalPayable)} <small>ج.م</small></strong><p>الرصيد المفتوح لكل الفواتير، حتى لو بتبحث عن مورد واحد.</p></div>
      <div className="card payables-summary-card"><span><FileText size={16}/> فواتير عليها رصيد</span><strong>{openInvoiceCount.toLocaleString("ar-EG")}</strong><p>بعد الدفعات ومرتجعات الموردين.</p></div>
      <div className="card payables-summary-card"><span><Truck size={16}/> موردون مستحق لهم</span><strong>{supplierCount.toLocaleString("ar-EG")}</strong><p>عدد الموردين اللي ليهم مستحقات حاليًا.</p></div>
    </section>

    <section className="card payables-ledger-card">
      <div className="payables-section-head"><div><h2>فواتير الشراء المفتوحة</h2><p>كل صف فاتورة واحدة. افتح الفاتورة للتفاصيل أو افتح ملف المورد لمتابعة كل التعاملات.</p></div><span className="badge blue">{pagination.total.toLocaleString("ar-EG")} نتيجة</span></div>
      <div className="payables-toolbar">
        <label className="payables-search"><Search size={17}/><input aria-label="بحث مستحقات الموردين" value={query} onChange={(event) => { setPage(1); setQuery(event.target.value); }} placeholder="ابحث باسم المورد أو كوده أو رقم الفاتورة..."/><kbd>بحث</kbd>{query && <button type="button" aria-label="مسح البحث" onClick={() => { setPage(1); setQuery(""); }}><X size={14}/></button>}</label>
        <label className="payables-page-size">عدد الصفوف<select value={perPage} onChange={(event) => { setPage(1); setPerPage(Number(event.target.value)); }}><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select></label>
      </div>
      {loading ? <div className="payables-loading"><span className="spinner"/> جاري تحميل مستحقات الموردين...</div> : !invoices.length ? <div className="payables-empty"><CheckCircle2 size={34}/><strong>{query ? "مفيش فواتير مطابقة للبحث" : "مفيش فواتير عليها رصيد مفتوح"}</strong><span>{query ? "جرّب اسمًا أو رقم فاتورة أقصر." : "كل فواتير الشراء الحالية مسددة بالكامل أو لا توجد مشتريات مرحّلة."}</span>{query && <button type="button" className="secondary" onClick={() => { setPage(1); setQuery(""); }}>عرض كل المستحقات</button>}</div> : <div className="table-wrap payables-table-wrap"><table><thead><tr><th>المورد</th><th>فاتورة النظام / المورد</th><th>تاريخ الفاتورة</th><th>إجمالي الفاتورة</th><th>المدفوع</th><th>المرتجعات</th><th>المتبقي</th><th>الإجراءات</th></tr></thead><tbody>
        {invoices.map((invoice) => <tr key={invoice.id}>
          <td><Link href={`/suppliers/${invoice.supplier_id}`} className="payables-supplier-link"><strong>{invoice.supplier_name}</strong><small>{invoice.supplier_code} · ملف المورد</small></Link></td>
          <td><button type="button" className="ledger-doc-button" onClick={() => void openInvoice(invoice.id)}>{invoice.document_number}</button><small className="payables-supplier-invoice">فاتورة المورد: {invoice.supplier_invoice_number || "غير مسجل"}</small></td>
          <td><span className="payables-date"><CalendarDays size={12}/>{date(invoice.invoice_date)}</span></td>
          <td>{money(invoice.total)} <small>ج.م</small></td><td>{money(invoice.paid_amount)} <small>ج.م</small></td><td>{money(invoice.returned_total)} <small>ج.م</small></td>
          <td><strong className="payables-due-value">{money(invoice.remaining_payable)} <small>ج.م</small></strong></td>
          <td><div className="payables-row-actions"><button type="button" onClick={() => void openInvoice(invoice.id)}><FileText size={13}/> الفاتورة</button><Link href={`/suppliers/${invoice.supplier_id}`}><Truck size={13}/> المورد</Link><Link href={`/purchase-trace?q=${encodeURIComponent(invoice.document_number)}`}><ArrowDownLeft size={13}/> تتبّع</Link></div></td>
        </tr>)}
      </tbody></table></div>}

      {!loading && pagination.total > 0 && <div className="payables-pagination"><span>عرض {(pagination.from || 0).toLocaleString("ar-EG")}–{(pagination.to || 0).toLocaleString("ar-EG")} من {pagination.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}><ChevronRight size={14}/> السابق</button><strong>{pagination.current_page.toLocaleString("ar-EG")} / {pagination.last_page.toLocaleString("ar-EG")}</strong><button type="button" disabled={page >= pagination.last_page} onClick={() => setPage((value) => Math.min(pagination.last_page, value + 1))}>التالي <ChevronLeft size={14}/></button></div></div>}
      <p className="payables-footnote">المستحق محسوب من فواتير الشراء المرحلة بعد خصم المدفوع والمرتجعات المسجلة. الدفعات المقدمة غير المخصصة وتسويات الفروق تحتاج سياسة محاسبية مستقلة.</p>
    </section>

    {(invoiceLoading || selectedInvoice || invoiceError) && <div className="customer-modal-backdrop" role="presentation" onClick={(event) => { if (event.target === event.currentTarget) { setSelectedInvoice(null); setInvoiceError(""); } }}><section className="card customer-invoice-modal payables-invoice-modal" role="dialog" aria-modal="true" aria-label="تفاصيل فاتورة مشتريات">
      <div className="customer-section-heading"><div><h2>{selectedInvoice ? `فاتورة الشراء ${selectedInvoice.document_number}` : "تفاصيل فاتورة الشراء"}</h2><p>تفاصيل بنود الفاتورة وكمياتها والتكلفة والرصيد المتبقي.</p></div><button type="button" className="icon-btn" aria-label="إغلاق" onClick={() => { setSelectedInvoice(null); setInvoiceError(""); }}><X size={16}/></button></div>
      {invoiceLoading && <div className="customer-profile-empty">جاري تحميل تفاصيل الفاتورة...</div>}
      {invoiceError && <div className="pos-alert error">{invoiceError}</div>}
      {selectedInvoice && <><div className="invoice-mini-summary payables-invoice-summary"><span>الإجمالي<strong>{money(selectedInvoice.total)} ج.م</strong></span><span>المدفوع<strong>{money(selectedInvoice.paid_amount)} ج.م</strong></span><span>المرتجعات<strong>{money(selectedInvoice.returned_total)} ج.م</strong></span><span>المتبقي<strong className="payables-due-value">{money(selectedInvoice.remaining_payable)} ج.م</strong></span></div><div className="payables-invoice-meta"><span>التاريخ: <strong>{date(selectedInvoice.invoice_date)}</strong></span><span>المخزن: <strong>{selectedInvoice.location_name || "—"}</strong></span><span>رقم المورد: <strong>{selectedInvoice.supplier_invoice_number || "غير مسجل"}</strong></span></div><div className="table-wrap"><table><thead><tr><th>الصنف</th><th>الكود</th><th>الكمية المدخلة</th><th>معامل التحويل</th><th>المتبقي للإرجاع</th><th>تكلفة الوحدة</th></tr></thead><tbody>{selectedItems.map((item) => <tr key={item.id}><td>{item.product_name_snapshot}</td><td>{item.sku_snapshot}</td><td>{Number(item.entered_quantity).toLocaleString("ar-EG")}</td><td>{Number(item.conversion_factor_snapshot).toLocaleString("ar-EG")}</td><td>{Number(item.remaining_quantity_base).toLocaleString("ar-EG")}</td><td>{money(item.unit_cost_entered)} ج.م</td></tr>)}{!selectedItems.length && <tr><td colSpan={6}>لا توجد بنود لعرضها.</td></tr>}</tbody></table></div><div className="payables-modal-actions"><Link href={`/suppliers/${selectedInvoice.supplier_id}`} className="secondary">فتح ملف المورد</Link><Link href={`/purchase-trace?q=${encodeURIComponent(selectedInvoice.document_number)}`} className="primary">تتبّع مصدر البضاعة</Link></div></>}
    </section></div>}
  </main>;
}
