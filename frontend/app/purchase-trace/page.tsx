"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ChevronLeft, ChevronRight, Package, RefreshCw, Search, Truck, WalletCards, FileText, X } from "lucide-react";
import { api } from "@/lib/api";

type SourceLine = {
  purchase_invoice_item_id: string;
  product_id: string;
  product_name: string;
  sku: string;
  received_quantity_base: number;
  supplier_returned_quantity_base: number;
  net_sold_quantity_base: number;
  remaining_stock_quantity_base: number;
  remaining_by_location: Array<{ location_id: string; location_name: string; location_type: string; quantity_base: number }>;
  sales: Array<{
    sales_invoice_id: string;
    document_number: string;
    invoice_date: string;
    customer_name: string;
    customer_code: string;
    trip_number?: string | null;
    sourced_quantity_base: number;
    returned_quantity_base: number;
    net_quantity_base: number;
    sourced_sales_value: number;
    net_sales_value: number;
    currency: string;
  }>;
  unit_cost_base: number;
  line_total: number;
};
type SourceInvoice = {
  purchase_invoice_id: string;
  document_number: string;
  supplier_invoice_number?: string | null;
  invoice_date: string;
  supplier_id: string;
  supplier_name: string;
  supplier_code: string;
  invoice_total: number;
  paid_amount: number;
  returned_total: number;
  remaining_payable: number;
  currency: string;
  items: SourceLine[];
};
type SourceTraceResponse = {
  invoices: SourceInvoice[];
  pagination: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
  source_attribution: string;
};

const money = (value: number) => Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const quantity = (value: number) => Number(value || 0).toLocaleString("ar-EG", { maximumFractionDigits: 3 });

export default function PurchaseTracePage() {
  const [invoices, setInvoices] = useState<SourceInvoice[]>([]);
  const [attributionNote, setAttributionNote] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [pagination, setPagination] = useState<SourceTraceResponse["pagination"]>({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
  const [ready, setReady] = useState(false);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ page: String(page), per_page: String(perPage) });
      if (query.trim()) params.set("q", query.trim());
      const result = await api<SourceTraceResponse>(`/purchases/source-trace?${params.toString()}`);
      setInvoices(result.invoices);
      setAttributionNote(result.source_attribution);
      setPagination(result.pagination);
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const initialQuery = new URLSearchParams(window.location.search).get("q") || "";
    setQuery(initialQuery);
    setReady(true);
  }, []);

  useEffect(() => {
    if (!ready) return;
    const timer = setTimeout(() => { void load(); }, query.trim() ? 220 : 0);
    return () => clearTimeout(timer);
  }, [ready, query, page, perPage]);

  const outstandingTotal = invoices.reduce((sum, invoice) => sum + invoice.remaining_payable, 0);
  const soldAgainstUnpaid = invoices.reduce((sum, invoice) => {
    if (invoice.remaining_payable <= 0) return sum;
    return sum + invoice.items.reduce((invoiceSum, item) => invoiceSum + item.net_sold_quantity_base * item.unit_cost_base, 0);
  }, 0);

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">تتبّع مصدر البضاعة</h1><div className="subtitle">ابدأ برقم فاتورة المورد أو اسم الصنف، واعرف الكمية اللي دخلت والموجودة في كل موقع والفواتير اللي باعت منها.</div></div>
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        <Link href="/payables" className="secondary" style={{ display: "inline-flex", gap: 6, alignItems: "center", textDecoration: "none" }}><WalletCards size={14}/> مستحقات الموردين</Link>
        <button className="secondary" onClick={() => void load()} disabled={loading}><RefreshCw size={13}/> تحديث</button>
      </div>
    </div>
    {error && <div role="alert" className="badge red" style={{ display: "block", padding: 12, whiteSpace: "normal", marginBottom: 12 }}>{error}</div>}

    <section className="grid" style={{ gridTemplateColumns: "repeat(auto-fit,minmax(190px,1fr))", marginBottom: 14 }}>
      <div className="card metric"><div className="metric-top"><span className="metric-label">مستحق الموردين ضمن النتائج</span><span className="metric-icon"><WalletCards size={15}/></span></div><div className="metric-value">{money(outstandingTotal)}<span className="metric-unit">EGP</span></div><div className="metric-note">لهذه الصفحة فقط · كل المستحقات في شاشة المستحقات</div></div>
      <div className="card metric"><div className="metric-top"><span className="metric-label">تكلفة المبيع من فواتير عليها رصيد</span><span className="metric-icon"><Truck size={15}/></span></div><div className="metric-value">{money(soldAgainstUnpaid)}<span className="metric-unit">EGP</span></div><div className="metric-note">ضمن النتائج المعروضة، وليس مبلغًا مستحقًا منفصلًا</div></div>
      <div className="card metric"><div className="metric-top"><span className="metric-label">فواتير مشتريات مطابقة</span><span className="metric-icon"><Package size={15}/></span></div><div className="metric-value">{pagination.total.toLocaleString("ar-EG")}</div><div className="metric-note">تقدر تتصفح كل الفواتير على صفحات</div></div>
    </section>

    <section className="card">
      <div className="purchase-trace-guide"><div className="purchase-trace-guide-step"><span>1</span><strong>فاتورة المورد</strong></div><ChevronLeft size={15}/><div className="purchase-trace-guide-step"><span>2</span><strong>الصنف والكمية</strong></div><ChevronLeft size={15}/><div className="purchase-trace-guide-step"><span>3</span><strong>المخزون والبيع</strong></div></div>
      <div className="panel-title"><span>سلسلة المصدر من الفاتورة إلى العميل</span><span className="badge blue">FIFO trace</span></div>
      <div className="purchase-trace-toolbar"><label className="payables-search"><Search size={15}/><input aria-label="بحث في تتبع فواتير الشراء" value={query} onChange={(event) => { setPage(1); setQuery(event.target.value); }} placeholder="رقم فاتورة المورد / النظام، المورد، الصنف أو SKU..."/>{query && <button aria-label="مسح البحث" type="button" onClick={() => { setPage(1); setQuery(""); }}><X size={14}/></button>}</label><label className="payables-page-size">عدد الفواتير<select value={perPage} onChange={(event) => { setPage(1); setPerPage(Number(event.target.value)); }}><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select></label></div>
      {loading && <div role="status" style={{ padding: 16, color: "#64748b" }}>جاري مطابقة فواتير الشراء بحركات البيع والمخزون...</div>}
      {!loading && !invoices.length && <div style={{ padding: 28, textAlign: "center", color: "#64748b" }}>{query ? "مفيش فواتير مطابقة؛ جرّب البحث بكود الصنف أو رقم الفاتورة." : "لا توجد فواتير مشتريات مرحّلة حتى الآن."}</div>}
      {invoices.map((invoice) => <section key={invoice.purchase_invoice_id} className="purchase-trace-invoice">
        <header className="purchase-trace-invoice-head">
          <div><strong>{invoice.document_number} · {invoice.supplier_name}</strong><small>فاتورة المورد: {invoice.supplier_invoice_number || "—"} · {invoice.invoice_date} · {invoice.supplier_code}</small><div className="purchase-trace-head-links"><Link href={`/suppliers/${invoice.supplier_id}`}><Truck size={12}/> ملف المورد</Link><Link href="/payables"><WalletCards size={12}/> المستحقات</Link></div></div>
          <div style={{ display: "flex", gap: 12, flexWrap: "wrap", alignItems: "center" }}><span style={{ fontSize: 11 }}>إجمالي: {money(invoice.invoice_total)} EGP</span><span style={{ fontSize: 11 }}>مدفوع: {money(invoice.paid_amount)} EGP</span><span className={invoice.remaining_payable > 0 ? "badge amber" : "badge green"}>{invoice.remaining_payable > 0 ? "باقي " + money(invoice.remaining_payable) + " EGP" : "مسددة"}</span></div>
        </header>
        <div className="table-wrap" style={{ border: 0, borderRadius: 0 }}><table style={{ minWidth: 770 }}><thead><tr><th>الصنف</th><th>الكمية المستلمة</th><th>اتباع صافيًا</th><th>اترد للمورد</th><th>متبقي في المخزون</th><th>تكلفة المصدر</th></tr></thead><tbody>
          {invoice.items.map((line) => <tr key={line.purchase_invoice_item_id}>
            <td><strong>{line.product_name}</strong><small style={{ display: "block", color: "#64748b" }}>{line.sku}</small></td>
            <td>{quantity(line.received_quantity_base)}</td>
            <td>
              <span className="badge blue">{quantity(line.net_sold_quantity_base)}</span>
              {line.sales.length > 0 && <details className="purchase-trace-sales-details"><summary><FileText size={13}/> فواتير البيع المرتبطة ({line.sales.length})</summary><div className="purchase-trace-sales-list">
                {line.sales.map((sale) => <div key={sale.sales_invoice_id + "-" + line.purchase_invoice_item_id} style={{ padding: 8, background: "#f8fafc", border: "1px solid #e2e8f0", borderRadius: 8 }}>
                  <Link href={"/invoices/" + sale.sales_invoice_id} style={{ fontWeight: 800, fontSize: 11 }}>{sale.document_number}</Link>
                  <small style={{ display: "block", color: "#64748b" }}>{sale.customer_name} · {sale.customer_code}</small>
                  <small style={{ display: "block", color: "#64748b" }}>{sale.invoice_date}{sale.trip_number ? " · رحلة " + sale.trip_number : ""}</small>
                  <small style={{ display: "block", marginTop: 4 }}>اتباع: {quantity(sale.net_quantity_base)} · قيمة البيع المرتبطة: {money(sale.net_sales_value)} {sale.currency}</small>
                  {sale.returned_quantity_base > 0 && <small style={{ display: "block", color: "#92400e" }}>مرتجع من المصدر: {quantity(sale.returned_quantity_base)}</small>}
                </div>)}
              </div></details>}
            </td>
            <td>{quantity(line.supplier_returned_quantity_base)}</td>
            <td><span className="badge green">{quantity(line.remaining_stock_quantity_base)}</span><small style={{ display: "block", color: "#64748b", marginTop: 4 }}>{line.remaining_by_location.map((location) => location.location_name + ": " + quantity(location.quantity_base)).join(" · ") || "لا يوجد مخزون متبقٍ من طبقة المصدر"}</small></td>
            <td>{money(line.unit_cost_base)} EGP / وحدة أساس</td>
          </tr>)}
        </tbody></table></div>
      </section>)}
      {!loading && pagination.total > 0 && <div className="payables-pagination"><span>عرض {(pagination.from || 0).toLocaleString("ar-EG")}–{(pagination.to || 0).toLocaleString("ar-EG")} من {pagination.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={page<=1} onClick={()=>setPage(value=>Math.max(1,value-1))}><ChevronRight size={14}/> السابق</button><strong>{pagination.current_page.toLocaleString("ar-EG")} / {pagination.last_page.toLocaleString("ar-EG")}</strong><button type="button" disabled={page>=pagination.last_page} onClick={()=>setPage(value=>Math.min(pagination.last_page,value+1))}>التالي <ChevronLeft size={14}/></button></div></div>}
      <div className="badge amber" style={{ display: "block", padding: 10, whiteSpace: "normal" }}>{attributionNote || "المخزون القديم قبل تشغيل تتبّع المصدر لا يتم ربطه تخمينيًا بفاتورة مورد."}</div>
    </section>
  </main>;
}
