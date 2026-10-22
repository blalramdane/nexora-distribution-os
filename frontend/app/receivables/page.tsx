"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { AlertTriangle, ArrowRight, CalendarDays, ChevronLeft, ChevronRight, FileDown, FileSpreadsheet, RefreshCw, Search, UserRound, WalletCards } from "lucide-react";
import { api } from "@/lib/api";
import { createXlsxWorkbook, type XlsxCell, type XlsxSheet } from "@/lib/xlsx-export.mjs";
import { buildReportPrintHtml, type PrintCell } from "@/lib/report-print.mjs";

type ReceivableInvoice = {
  id: string; document_number: string; invoice_date: string; total: number | string;
  paid_amount: number | string; returned_total: number | string; remaining_receivable: number | string;
  currency: string; customer_id: string; customer_name: string; customer_code: string; customer_phone?: string | null;
};
type Pagination = { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
type ReceivablesResponse = { invoices: ReceivableInvoice[]; pagination: Pagination; total_receivable: number; open_invoice_count: number; customer_count: number };

const numeric = (value: number | string | null | undefined) => Number(value || 0);
const money = (value: number | string | null | undefined, currency = "EGP") => numeric(value).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " " + currency;
const date = (value: string) => value ? new Date(value + "T00:00:00").toLocaleDateString("ar-EG", { year: "numeric", month: "short", day: "numeric" }) : "—";

export default function ReceivablesPage() {
  const [invoices, setInvoices] = useState<ReceivableInvoice[]>([]);
  const [pagination, setPagination] = useState<Pagination>({ current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
  const [totalReceivable, setTotalReceivable] = useState(0);
  const [openInvoiceCount, setOpenInvoiceCount] = useState(0);
  const [customerCount, setCustomerCount] = useState(0);
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [refreshVersion, setRefreshVersion] = useState(0);
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState<"xlsx" | "pdf" | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const fetchPage = useCallback((requestedPage: number, requestedPerPage: number, search: string) => {
    const params = new URLSearchParams({ page: String(requestedPage), per_page: String(requestedPerPage) });
    if (search.trim()) params.set("q", search.trim());
    return api<ReceivablesResponse>("/sales/open-balances?" + params.toString());
  }, []);

  const load = useCallback(async () => {
    setLoading(true); setError(""); setNotice("");
    try {
      const result = await fetchPage(page, perPage, query);
      setInvoices(result.invoices || []); setPagination(result.pagination);
      setTotalReceivable(numeric(result.total_receivable)); setOpenInvoiceCount(Number(result.open_invoice_count || 0));
      setCustomerCount(Number(result.customer_count || 0));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر تحميل مديونيات العملاء.");
    } finally { setLoading(false); }
  }, [fetchPage, page, perPage, query, refreshVersion]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), query.trim() ? 250 : 0);
    return () => window.clearTimeout(timer);
  }, [load, query]);

  const loadAllMatching = async (format: "xlsx" | "pdf") => {
    const pageSize = 100;
    const first = await fetchPage(1, pageSize, query);
    const rowLimit = format === "pdf" ? 3000 : 20000;
    if (first.pagination.total > rowLimit) {
      throw new Error("نتائج البحث " + first.pagination.total.toLocaleString("ar-EG") + " فاتورة. قلّل البحث إلى " + rowLimit.toLocaleString("ar-EG") + " فاتورة أو أقل قبل تصدير " + (format === "pdf" ? "PDF" : "Excel") + ".");
    }
    const all = [...first.invoices];
    for (let start = 2; start <= first.pagination.last_page; start += 5) {
      const pageNumbers = Array.from({ length: Math.min(5, first.pagination.last_page - start + 1) }, (_, index) => start + index);
      const responses = await Promise.all(pageNumbers.map((pageNumber) => fetchPage(pageNumber, pageSize, query)));
      responses.forEach((response) => all.push(...response.invoices));
    }
    return { rows: all, summary: first };
  };

  const exportReport = async (format: "xlsx" | "pdf") => {
    setExporting(format); setError(""); setNotice("");
    let wrapper: HTMLDivElement | null = null;
    try {
      const result = await loadAllMatching(format);
      const rows = result.rows;
      const summary = result.summary;
      const invoiceHeaders = ["تاريخ الفاتورة", "رقم الفاتورة", "كود العميل", "اسم العميل", "الهاتف", "الإجمالي", "المدفوع", "المرتجعات", "المتبقي المستحق", "العملة"];
      const details: XlsxCell[][] = rows.map((row) => [row.invoice_date, row.document_number, row.customer_code, row.customer_name, row.customer_phone || "", numeric(row.total), numeric(row.paid_amount), numeric(row.returned_total), numeric(row.remaining_receivable), row.currency]);
      const summaryRows: XlsxCell[][] = [
        ["البند", "القيمة"], ["إجمالي المديونية المفتوحة للمؤسسة", numeric(summary.total_receivable)],
        ["عدد الفواتير المفتوحة للمؤسسة", summary.open_invoice_count], ["عدد العملاء عليهم مديونية", summary.customer_count],
        ["عدد الفواتير المطابقة للبحث", summary.pagination.total], ["فلتر البحث", query.trim() || "كل العملاء"],
        ["ملاحظة", "الإجمالي العام لا يتغير مع البحث؛ كشف الفواتير يعرض السجلات المطابقة للفلتر فقط."],
      ];
      const filename = "NEXORA-receivables-" + new Date().toISOString().slice(0, 10);
      if (format === "xlsx") {
        const customers = new Map<string, { name: string; code: string; phone: string; due: number; count: number }>();
        rows.forEach((row) => {
          const current = customers.get(row.customer_id) || { name: row.customer_name, code: row.customer_code, phone: row.customer_phone || "", due: 0, count: 0 };
          current.due += numeric(row.remaining_receivable); current.count += 1; customers.set(row.customer_id, current);
        });
        const customerRows: XlsxCell[][] = [["كود العميل", "اسم العميل", "الهاتف", "عدد الفواتير المفتوحة", "إجمالي المستحق في الفواتير المطابقة"]];
        [...customers.values()].sort((a, b) => b.due - a.due).forEach((customer) => customerRows.push([customer.code, customer.name, customer.phone, customer.count, Number(customer.due.toFixed(4))]));
        const sheets: XlsxSheet[] = [
          { name: "ملخص المديونية", rows: summaryRows },
          { name: "الفواتير المفتوحة", rows: [[...invoiceHeaders], ...details] },
          { name: "حسب العميل", rows: customerRows },
        ];
        const bytes = createXlsxWorkbook(sheets);
        const blob = new Blob([bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
        const url = URL.createObjectURL(blob); const link = document.createElement("a");
        link.href = url; link.download = filename + ".xlsx"; document.body.appendChild(link); link.click(); link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 30000);
        setNotice("تم تصدير " + rows.length.toLocaleString("ar-EG") + " فاتورة مطابقة إلى Excel في 3 شيتات.");
      } else {
        const summaryCells: [string, PrintCell][] = [
          ["إجمالي المديونية المفتوحة للمؤسسة", numeric(summary.total_receivable)],
          ["عدد الفواتير المفتوحة", summary.open_invoice_count], ["عدد العملاء", summary.customer_count],
          ["الفواتير المطابقة للبحث", rows.length], ["فلتر البحث", query.trim() || "كل العملاء"],
        ];
        const html = buildReportPrintHtml({
          title: "كشف مديونيات العملاء — NEXORA Distribution OS",
          subtitle: "كشف تشغيلي للفواتير المرحلة التي ما زال عليها رصيد · تاريخ التصدير " + new Date().toLocaleDateString("ar-EG"),
          summaryRows: summaryCells,
          tables: [{ title: "الفواتير المفتوحة", headers: invoiceHeaders, rows: rows.map((row) => [row.invoice_date, row.document_number, row.customer_code, row.customer_name, row.customer_phone || "—", numeric(row.total), numeric(row.paid_amount), numeric(row.returned_total), numeric(row.remaining_receivable), row.currency]) }],
          notes: ["الرصيد محسوب على مستوى كل فاتورة بعد خصم المدفوع والمرتجعات المرحلة المرتبطة بها.", "الإجمالي العام للمؤسسة غير مفلتر؛ الجدول فقط يعرض النتائج المطابقة للبحث.", "هذا كشف فواتير مفتوحة وليس تقرير تقادم أعمار مديونية ولا كشف حساب تاريخيًا."],
        });
        const parsed = new DOMParser().parseFromString(html, "text/html");
        wrapper = document.createElement("div");
        wrapper.style.cssText = "position:absolute;left:-12000px;top:0;width:1120px;background:#fff;padding:18px;direction:rtl";
        wrapper.innerHTML = "<style>" + Array.from(parsed.querySelectorAll("style")).map((style) => style.textContent || "").join("\n") + "</style>" + parsed.body.innerHTML;
        document.body.appendChild(wrapper);
        const module = await import("html2pdf.js");
        await module.default().set({ margin: 7, filename: filename + ".pdf", image: { type: "jpeg", quality: 0.96 }, html2canvas: { scale: 1.5, useCORS: true, logging: false, scrollX: 0, scrollY: 0 }, jsPDF: { unit: "mm", format: "a4", orientation: "landscape" } }).from(wrapper).save();
        setNotice("تم إنشاء PDF لـ " + rows.length.toLocaleString("ar-EG") + " فاتورة مطابقة.");
      }
    } catch (reason) { setError(reason instanceof Error ? reason.message : "تعذر تصدير كشف المديونية."); }
    finally { wrapper?.remove(); setExporting(null); }
  };

  return <main className="main payables-page">
    <div className="topbar">
      <div><h1 className="title">مديونيات العملاء</h1><div className="subtitle">كشف الفواتير المفتوحة حسب العميل، والبحث برقم الفاتورة أو الاسم أو الهاتف، وتصدير Excel/PDF للمراجعة.</div></div>
      <div className="payables-header-actions"><Link href="/sales" className="primary payables-action-link"><ArrowRight size={14}/> فاتورة بيع</Link><button className="secondary" disabled={loading} onClick={() => setRefreshVersion((value) => value + 1)}><RefreshCw size={13}/> تحديث</button></div>
    </div>
    {error && <div role="alert" className="pos-alert error"><AlertTriangle size={15}/> {error}</div>}
    {notice && <div role="status" className="badge blue" style={{ display: "block", whiteSpace: "normal", padding: 10, marginBottom: 12 }}>{notice}</div>}
    <section className="payables-summary-grid">
      <div className="card payables-summary-card payable-total-card"><span><WalletCards size={16}/> إجمالي المديونية المفتوحة</span><strong>{money(totalReceivable)} <small>EGP</small></strong><p>إجمالي كل المؤسسة ولا يتغير بسبب البحث أو تقسيم الصفحات.</p></div>
      <div className="card payables-summary-card"><span><CalendarDays size={16}/> فواتير عليها رصيد</span><strong>{openInvoiceCount.toLocaleString("ar-EG")}</strong><p>بعد التحصيلات والمرتجعات المسجلة.</p></div>
      <div className="card payables-summary-card"><span><UserRound size={16}/> عملاء عليهم رصيد</span><strong>{customerCount.toLocaleString("ar-EG")}</strong><p>عدد العملاء أصحاب فواتير مفتوحة.</p></div>
    </section>
    <section className="card payables-ledger-card">
      <div className="payables-section-head"><div><h2>الفواتير المفتوحة</h2><p>الرصيد على مستوى الفاتورة؛ افتح ملف العميل لمراجعة تاريخه وإيصالاته.</p></div><span className="badge blue">{pagination.total.toLocaleString("ar-EG")} نتيجة</span></div>
      <div className="payables-toolbar">
        <label className="payables-search"><Search size={17}/><input aria-label="بحث مديونيات العملاء" value={query} onChange={(event) => { setPage(1); setQuery(event.target.value); }} placeholder="ابحث باسم العميل أو كوده أو الهاتف أو رقم الفاتورة..."/><kbd>بحث</kbd>{query && <button type="button" aria-label="مسح البحث" onClick={() => { setPage(1); setQuery(""); }}><span>×</span></button>}</label>
        <label className="payables-page-size">عدد الصفوف<select value={perPage} onChange={(event) => { setPage(1); setPerPage(Number(event.target.value)); }}><option value={25}>25</option><option value={50}>50</option><option value={100}>100</option></select></label>
      </div>
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 12 }}>
        <button className="secondary" type="button" disabled={loading || exporting !== null} onClick={() => void exportReport("xlsx")}><FileSpreadsheet size={14}/>{exporting === "xlsx" ? "جاري تجميع الفواتير..." : "تصدير Excel كامل"}</button>
        <button className="secondary" type="button" disabled={loading || exporting !== null} onClick={() => void exportReport("pdf")}><FileDown size={14}/>{exporting === "pdf" ? "جاري تجميع الفواتير..." : "تصدير PDF كامل"}</button>
      </div>
      {loading ? <div className="payables-loading"><span className="spinner"/> جاري تحميل مديونيات العملاء...</div> : !invoices.length ? <div className="payables-empty"><WalletCards size={34}/><strong>{query ? "مفيش فواتير مطابقة للبحث" : "مفيش فواتير عليها رصيد مفتوح"}</strong><span>{query ? "جرّب اسمًا أو رقم فاتورة أقصر." : "كل الفواتير المرحلة مسددة أو لا توجد فواتير مفتوحة."}</span></div> : <div className="table-wrap payables-table-wrap"><table><thead><tr><th>العميل</th><th>الفاتورة</th><th>التاريخ</th><th>الإجمالي</th><th>المدفوع</th><th>المرتجعات</th><th>المتبقي</th><th>الملف</th></tr></thead><tbody>
        {invoices.map((invoice) => <tr key={invoice.id}><td><Link href={"/customers/" + invoice.customer_id} className="payables-supplier-link"><strong>{invoice.customer_name}</strong><small>{invoice.customer_code} · {invoice.customer_phone || "لا يوجد هاتف"}</small></Link></td><td><strong>{invoice.document_number}</strong></td><td><span className="payables-date"><CalendarDays size={12}/>{date(invoice.invoice_date)}</span></td><td>{money(invoice.total, invoice.currency)}</td><td>{money(invoice.paid_amount, invoice.currency)}</td><td>{money(invoice.returned_total, invoice.currency)}</td><td><strong className="payables-due-value">{money(invoice.remaining_receivable, invoice.currency)}</strong></td><td><Link href={"/customers/" + invoice.customer_id} className="customer-open-profile"><span>كشف العميل</span><ArrowRight size={14}/></Link></td></tr>)}
      </tbody></table></div>}
      {!loading && pagination.total > 0 && <div className="payables-pagination"><span>عرض {(pagination.from || 0).toLocaleString("ar-EG")}–{(pagination.to || 0).toLocaleString("ar-EG")} من {pagination.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}><ChevronRight size={14}/> السابق</button><strong>{pagination.current_page.toLocaleString("ar-EG")} / {pagination.last_page.toLocaleString("ar-EG")}</strong><button type="button" disabled={page >= pagination.last_page} onClick={() => setPage((value) => Math.min(pagination.last_page, value + 1))}>التالي <ChevronLeft size={14}/></button></div></div>}
      <p className="payables-footnote">الكشف مخصص للفواتير المفتوحة فقط. لا يحسب أعمار المديونية، ولا يعالج أرصدة دائنة ناتجة عن مرتجع لفاتورة مسددة بالكامل؛ راجع سياسة المرتجعات قبل اعتماد كشف محاسبي نهائي.</p>
    </section>
  </main>;
}
