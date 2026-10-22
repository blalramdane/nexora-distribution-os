"use client";

import { useEffect, useState } from "react";
import { AlertTriangle, BarChart3, CalendarDays, Package, RefreshCw, TrendingDown, TrendingUp, FileDown, FileSpreadsheet } from "lucide-react";
import { api } from "@/lib/api";
import { createXlsxWorkbook, type XlsxCell, type XlsxSheet } from "@/lib/xlsx-export.mjs";
import { buildReportPrintHtml, type PrintCell, type PrintTable } from "@/lib/report-print.mjs";

type ReportSummary = {
  sales_total: number;
  sales_returns_total: number;
  net_sales: number;
  purchases_total: number;
  purchase_returns_total: number;
  net_purchases: number;
  collections: number;
  supplier_payments: number;
  expenses: number;
  cost_of_goods_sold: number;
  returned_cost: number;
  net_cost_of_goods_sold: number;
  gross_profit: number;
  net_profit: number;
  receivables_current: number;
  payables_current: number;
  inventory_units_current: number;
  inventory_value_current: number;
  active_trips_in_period: number;
};

type DailyReportRow = {
  date: string;
  sales: number;
  sales_returns: number;
  net_sales: number;
  purchases: number;
  purchase_returns: number;
  net_purchases: number;
  collections: number;
  supplier_payments: number;
  expenses: number;
  cost_of_goods_sold: number;
  returned_cost: number;
  net_cost_of_goods_sold: number;
  net_profit_before_variance: number;
};

type TopProduct = {
  product_id: string;
  product_name: string;
  sku: string;
  quantity_base: number;
  sales_total: number;
  cost_of_goods_sold: number;
  gross_profit: number;
};

type OperationalReport = {
  period: { date_from: string; date_to: string };
  currency: string;
  summary: ReportSummary;
  daily: DailyReportRow[];
  top_products: TopProduct[];
  notes: {
    balances_and_inventory_are_current_snapshots: boolean;
    trip_cash_and_stock_variances_included_in_profit: boolean;
    profit_is_operational_not_a_final_tax_statement: boolean;
  };
};

function localDate(date = new Date()): string {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function monthStart(date: string): string {
  return `${date.slice(0, 7)}-01`;
}

function formatMoney(value: number, currency = "EGP"): string {
  return `${Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

function formatQuantity(value: number): string {
  return Number(value || 0).toLocaleString("ar-EG", { maximumFractionDigits: 3 });
}

async function loadOperationalReport(from: string, to: string): Promise<OperationalReport> {
  const params = new URLSearchParams({ date_from: from, date_to: to });
  return api<OperationalReport>(`/reports?${params.toString()}`);
}

export default function Reports() {
  const initialTo = localDate();
  const [dateFrom, setDateFrom] = useState(() => monthStart(initialTo));
  const [dateTo, setDateTo] = useState(initialTo);
  const [report, setReport] = useState<OperationalReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [exporting, setExporting] = useState<"xlsx" | "pdf" | null>(null);

  useEffect(() => {
    void loadOperationalReport(monthStart(initialTo), initialTo)
      .then(setReport)
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : "تعذر تحميل التقرير."))
      .finally(() => setLoading(false));
  }, []);

  const runReport = async () => {
    if (!dateFrom || !dateTo) {
      setError("اختار تاريخ البداية والنهاية أولًا.");
      return;
    }
    if (dateTo < dateFrom) {
      setError("تاريخ النهاية لازم يكون نفس يوم البداية أو بعده.");
      return;
    }

    setLoading(true);
    setError("");
    try {
      setReport(await loadOperationalReport(dateFrom, dateTo));
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر تحميل التقرير. راجع الفترة وصلاحية التقارير.");
    } finally {
      setLoading(false);
    }
  };

  const summary = report?.summary;
  const currency = report?.currency || "EGP";
  const metrics: Array<{ label: string; value: number; kind?: "positive" | "negative" | "neutral"; hint?: string }> = summary ? [
    { label: "إجمالي المبيعات", value: summary.sales_total, hint: "الفواتير المرحلة خلال الفترة" },
    { label: "مرتجعات المبيعات", value: summary.sales_returns_total, kind: "negative", hint: "مرتجعات البيع المرحلة" },
    { label: "صافي المبيعات", value: summary.net_sales, kind: "positive", hint: "المبيعات بعد المرتجعات" },
    { label: "إجمالي المشتريات", value: summary.purchases_total, hint: "الفواتير المرحلة خلال الفترة" },
    { label: "مرتجعات المشتريات", value: summary.purchase_returns_total, kind: "negative", hint: "مرتجعات الموردين" },
    { label: "صافي المشتريات", value: summary.net_purchases, hint: "المشتريات بعد المرتجعات" },
    { label: "التحصيلات", value: summary.collections, kind: "positive", hint: "المدفوع من العملاء خلال الفترة" },
    { label: "مدفوعات الموردين", value: summary.supplier_payments, hint: "ما تم دفعه للموردين" },
    { label: "المصروفات", value: summary.expenses, kind: "negative", hint: "المصروفات المرحلة خلال الفترة" },
    { label: "تكلفة البضاعة بعد المرتجع", value: summary.net_cost_of_goods_sold, hint: "يشمل استرداد تكلفة مرتجعات الفترات السابقة" },
    { label: "الربح الإجمالي", value: summary.gross_profit, kind: summary.gross_profit >= 0 ? "positive" : "negative", hint: "صافي المبيعات ناقص تكلفة البضاعة" },
    { label: "صافي الربح التشغيلي", value: summary.net_profit, kind: summary.net_profit >= 0 ? "positive" : "negative", hint: "بعد المصروفات، قبل فروق التسوية" },
    { label: "مستحقات العملاء الحالية", value: summary.receivables_current, hint: "الرصيد الحالي وليس رصيدًا تاريخيًا" },
    { label: "مستحقات الموردين الحالية", value: summary.payables_current, hint: "الرصيد الحالي وليس رصيدًا تاريخيًا" },
    { label: "قيمة المخزون الحالية", value: summary.inventory_value_current, hint: `${formatQuantity(summary.inventory_units_current)} وحدة أساسية في المخزون` },
  ] : [];

  const exportReport = async (format: "xlsx" | "pdf") => {
    if (!report || !summary) return;
    setExporting(format);
    setError("");
    let wrapper: HTMLDivElement | null = null;
    try {
      const overview: [string, PrintCell][] = metrics.map((metric) => [metric.label, metric.value]);
      overview.push(["الفترة من", report.period.date_from], ["الفترة إلى", report.period.date_to], ["العملة", currency]);
      const dailyHeaders = ["التاريخ","المبيعات","مرتجعات البيع","صافي المبيعات","المشتريات","مرتجعات المشتريات","صافي المشتريات","التحصيلات","مدفوعات الموردين","المصروفات","تكلفة البضاعة","تكلفة المرتجع","صافي تكلفة البضاعة","الربح التشغيلي قبل الفروق"];
      const dailyRows = report.daily.map((row) => [row.date,row.sales,row.sales_returns,row.net_sales,row.purchases,row.purchase_returns,row.net_purchases,row.collections,row.supplier_payments,row.expenses,row.cost_of_goods_sold,row.returned_cost,row.net_cost_of_goods_sold,row.net_profit_before_variance]);
      const productsHeaders = ["الصنف","SKU","الكمية الأساسية","قيمة البيع","التكلفة","الربح الإجمالي"];
      const productsRows = report.top_products.map((row) => [row.product_name,row.sku,row.quantity_base,row.sales_total,row.cost_of_goods_sold,row.gross_profit]);
      const tables: PrintTable[] = [
        { title: "النشاط اليومي", headers: dailyHeaders, rows: dailyRows },
        { title: "أكثر الأصناف مبيعًا", headers: productsHeaders, rows: productsRows },
      ];
      const notes = [
        "أرصدة العملاء والموردين والمخزون لقطات حالية وقت التقرير وليست أرصدة تاريخية لنهاية الفترة.",
        "الربح المعروض تشغيلي وليس تقريرًا ضريبيًا نهائيًا، وفروق نقدية ومخزون الرحلات غير داخلة في الربح.",
      ];
      const filename = `NEXORA-operational-report-${report.period.date_from}-${report.period.date_to}`;
      if (format === "xlsx") {
        const sheets: XlsxSheet[] = [
          { name: "ملخص الفترة", rows: [["البند","القيمة"], ...overview.map(([label,value]) => [label,value] as XlsxCell[])] },
          { name: "النشاط اليومي", rows: [[...dailyHeaders], ...dailyRows.map((row) => row as XlsxCell[])] },
          { name: "أكثر الأصناف مبيعًا", rows: [[...productsHeaders], ...productsRows.map((row) => row as XlsxCell[])] },
        ];
        const bytes = createXlsxWorkbook(sheets);
        const blob = new Blob([bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength) as ArrayBuffer], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a"); link.href = url; link.download = `${filename}.xlsx`; document.body.appendChild(link); link.click(); link.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 30000);
      } else {
        const html = buildReportPrintHtml({ title: "التقرير التشغيلي — NEXORA Distribution OS", subtitle: `الفترة ${report.period.date_from} — ${report.period.date_to} · العملة ${currency}`, summaryRows: overview, tables, notes });
        const parsed = new DOMParser().parseFromString(html, "text/html");
        wrapper = document.createElement("div");
        wrapper.style.cssText = "position:absolute;left:-12000px;top:0;width:1120px;background:#fff;padding:18px;direction:rtl";
        wrapper.innerHTML = `<style>${Array.from(parsed.querySelectorAll("style")).map((style) => style.textContent || "").join("\n")}</style>${parsed.body.innerHTML}`;
        document.body.appendChild(wrapper);
        const module = await import("html2pdf.js");
        await module.default().set({ margin: 7, filename: `${filename}.pdf`, image: { type: "jpeg", quality: 0.96 }, html2canvas: { scale: 1.5, useCORS: true, logging: false }, jsPDF: { unit: "mm", format: "a4", orientation: "landscape" } }).from(wrapper).save();
      }
    } catch (reason) {
      setError(reason instanceof Error ? `فشل التصدير: ${reason.message}` : "تعذر تصدير التقرير.");
    } finally {
      wrapper?.remove();
      setExporting(null);
    }
  };

  return <main className="main reports-page">
    <div className="topbar">
      <div>
        <h1 className="title">التقارير</h1>
        <div className="subtitle">راجع حركة البيع والشراء والتحصيل والربح في فترة واحدة، من غير ما تغيّر أي قيود.</div>
      </div>
      <span className="reports-heading-icon"><BarChart3 size={21}/></span>
    </div>

    <section className="card reports-filter" aria-label="فلتر الفترة الزمنية">
      <div className="reports-filter-heading"><CalendarDays size={17}/><div><strong>الفترة المطلوبة</strong><small>الافتراضي من أول الشهر حتى اليوم الحالي.</small></div></div>
      <div className="reports-filter-fields">
        <label>من تاريخ<input type="date" value={dateFrom} max={dateTo || undefined} onChange={(event) => setDateFrom(event.target.value)}/></label>
        <label>إلى تاريخ<input type="date" value={dateTo} min={dateFrom || undefined} onChange={(event) => setDateTo(event.target.value)}/></label>
        <button className="primary reports-run" type="button" disabled={loading} onClick={() => void runReport()}>
          {loading ? <RefreshCw size={14} className="spin"/> : <BarChart3 size={14}/>} {loading ? "جاري إعداد التقرير..." : "عرض التقرير"}
        </button>
      </div>
      {report && <div className="reports-period-note" style={{display:"flex",gap:8,flexWrap:"wrap",alignItems:"center",justifyContent:"space-between"}}><span>التقرير المعروض: {report.period.date_from} — {report.period.date_to} · العملة: {report.currency}</span><span style={{display:"flex",gap:6}}><button type="button" className="secondary" disabled={loading||exporting!==null} onClick={() => void exportReport("xlsx")}><FileSpreadsheet size={14}/>{exporting==="xlsx"?"جاري تجهيز Excel...":"تصدير Excel"}</button><button type="button" className="secondary" disabled={loading||exporting!==null} onClick={() => void exportReport("pdf")}><FileDown size={14}/>{exporting==="pdf"?"جاري تجهيز PDF...":"تصدير PDF"}</button></span></div>}
    </section>

    {error && <div role="alert" className="reports-error"><AlertTriangle size={16}/><span>{error}</span></div>}
    {loading && !report && <div role="status" className="card reports-loading">بنحمّل الأرقام من فواتير وقيود المؤسسة...</div>}

    {summary && <>
      <div className="reports-section-heading"><div><h2>ملخص الفترة</h2><p>كل القيم المالية بالجنيه أو عملة المؤسسة المحددة.</p></div><span className="reports-result-count">{report?.daily.length || 0} أيام بها حركة</span></div>
      <section className="reports-metrics" aria-label="ملخص مالي">
        {metrics.map((metric) => <article className={`card reports-metric ${metric.kind || "neutral"}`} key={metric.label}>
          <span className="reports-metric-label">{metric.label}</span>
          <strong>{formatMoney(metric.value, currency)}</strong>
          <small>{metric.hint}</small>
        </article>)}
      </section>

      <section className="card reports-disclaimer">
        <div className="reports-disclaimer-icon"><TrendingDown size={17}/></div>
        <div><strong>ملاحظات مهمة على الأرقام</strong><p>مستحقات العملاء والموردين وقيمة المخزون هي أرصدة حالية وقت فتح التقرير، مش أرصدة تاريخية في نهاية الفترة. تكلفة البضاعة بعد المرتجعات ممكن تظهر سالبة لو مرتجعات اليوم من فواتير قديمة استردّت تكلفة أكبر من تكلفة مبيعات الفترة. الربح تشغيلي ومش تقرير ضريبي نهائي، وفروق النقدية والمخزون عند تسوية الرحلات غير مضافة للربح لحين اعتماد سياسة المحاسبة.</p></div>
      </section>

      <section className="card reports-table-card">
        <div className="reports-section-heading"><div><h2>تفصيل النشاط اليومي</h2><p>اضغط على الفترة من وإلى لتغيير الأيام المعروضة.</p></div><span className="reports-table-icon"><TrendingUp size={17}/></span></div>
        <div className="reports-table-wrap"><table className="reports-table"><thead><tr>
          <th>التاريخ</th><th>المبيعات</th><th>مرتجعات البيع</th><th>صافي المبيعات</th><th>المشتريات</th><th>التحصيلات</th><th>المصروفات</th><th>صافي الربح التشغيلي</th>
        </tr></thead><tbody>
          {report?.daily.map((row) => <tr key={row.date}>
            <td>{row.date}</td><td>{formatMoney(row.sales, currency)}</td><td>{formatMoney(row.sales_returns, currency)}</td><td className="reports-emphasis">{formatMoney(row.net_sales, currency)}</td><td>{formatMoney(row.purchases, currency)}</td><td>{formatMoney(row.collections, currency)}</td><td>{formatMoney(row.expenses, currency)}</td><td className={row.net_profit_before_variance >= 0 ? "reports-positive" : "reports-negative"}>{formatMoney(row.net_profit_before_variance, currency)}</td>
          </tr>)}
          {!report?.daily.length && <tr><td colSpan={8} className="reports-empty">مفيش حركات مرحلة في الفترة دي. جرّب فترة تانية أو راجع إن الفواتير مسجلة ومرحّلة.</td></tr>}
        </tbody></table></div>
      </section>

      <section className="card reports-table-card">
        <div className="reports-section-heading"><div><h2>أكثر الأصناف مبيعًا</h2><p>مرتب حسب قيمة البنود المباعة خلال الفترة، قبل خصم مرتجعات كل صنف.</p></div><span className="reports-table-icon"><Package size={17}/></span></div>
        <div className="reports-table-wrap"><table className="reports-table reports-products-table"><thead><tr><th>الصنف</th><th>SKU</th><th>الكمية الأساسية</th><th>قيمة البيع</th><th>التكلفة</th><th>الربح الإجمالي</th></tr></thead><tbody>
          {report?.top_products.map((product) => <tr key={`${product.product_id}-${product.sku}`}><td>{product.product_name}</td><td dir="ltr">{product.sku}</td><td>{formatQuantity(product.quantity_base)}</td><td>{formatMoney(product.sales_total, currency)}</td><td>{formatMoney(product.cost_of_goods_sold, currency)}</td><td className={product.gross_profit >= 0 ? "reports-positive" : "reports-negative"}>{formatMoney(product.gross_profit, currency)}</td></tr>)}
          {!report?.top_products.length && <tr><td colSpan={6} className="reports-empty">لا توجد أصناف مباعة في الفترة المحددة.</td></tr>}
        </tbody></table></div>
      </section>
      <div className="reports-footer-note">عدد الرحلات النشطة خلال الفترة: <strong>{summary.active_trips_in_period}</strong>. التقارير للقراءة فقط؛ أي تعديل أو ترحيل يتم من شاشة العملية نفسها.</div>
    </>}
  </main>;
}
