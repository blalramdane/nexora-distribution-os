"use client";

import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import Link from "next/link";
import { ArrowRight, ClipboardList, FileSearch, RefreshCw, Search, ShoppingCart } from "lucide-react";
import { api } from "@/lib/api";

type InvoiceType = "sales" | "purchases";
type InvoiceRow = {
  id: string;
  document_type: InvoiceType;
  document_number: string;
  external_number?: string | null;
  invoice_date: string;
  party_id: string;
  party_type: "customer" | "supplier";
  party_name: string;
  party_code: string;
  total: number | string;
  paid_amount: number | string;
  returns_total: number | string;
  remaining_amount: number | string;
  currency: string;
};
type CurrencySummary = { currency: string; invoice_count: number; sales_total: number; purchases_total: number; customer_receivables_total: number; supplier_payables_total: number };
type RegisterResponse = {
  invoices: InvoiceRow[];
  pagination: { current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
  summary: { invoice_count: number; by_currency: CurrencySummary[] };
};
type Filters = { type: "all" | InvoiceType; q: string; date_from: string; date_to: string };

function localDate(date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}
function monthStart(date: string): string { return `${date.slice(0, 7)}-01`; }
function money(value: number | string, currency = "EGP"): string {
  return `${Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency === "EGP" ? "ج.م" : currency}`;
}
function dateLabel(value: string): string {
  const [year, month, day] = value.slice(0, 10).split("-").map(Number);
  if (!year || !month || !day) return value;
  return new Date(year, month - 1, day).toLocaleDateString("ar-EG", { year: "numeric", month: "short", day: "numeric" });
}

export default function InvoiceRegisterPage() {
  const today = localDate();
  const [draft, setDraft] = useState<Filters>({ type: "all", q: "", date_from: monthStart(today), date_to: today });
  const [filters, setFilters] = useState<Filters>(draft);
  const [page, setPage] = useState(1);
  const [refreshToken, setRefreshToken] = useState(0);
  const [result, setResult] = useState<RegisterResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError("");
    const params = new URLSearchParams({ type: filters.type, page: String(page), per_page: "25" });
    if (filters.q.trim()) params.set("q", filters.q.trim());
    if (filters.date_from) params.set("date_from", filters.date_from);
    if (filters.date_to) params.set("date_to", filters.date_to);
    api<RegisterResponse>(`/invoice-register?${params.toString()}`)
      .then((data) => { if (!cancelled) setResult(data); })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر تحميل سجل الفواتير."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [filters, page, refreshToken]);

  function submitFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (draft.date_from && draft.date_to && draft.date_to < draft.date_from) {
      setError("تاريخ النهاية لازم يكون نفس يوم البداية أو بعده.");
      return;
    }
    setPage(1); setFilters({ ...draft, q: draft.q.trim() });
  }

  const summaryCurrencies = result?.summary.by_currency ?? [];
  const pagination = result?.pagination;

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">سجل كل الفواتير</h1><div className="subtitle">سجل موحد لفواتير البيع والشراء المرحلة، مع الطرف المقابل والمدفوع والمرتجع والرصيد المتبقي.</div></div>
      <div style={{ display: "flex", gap: 7, flexWrap: "wrap" }}><Link href="/sales" className="primary" style={{ display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none" }}><ShoppingCart size={15}/> فاتورة بيع</Link><Link href="/purchases" className="secondary" style={{ display: "inline-flex", alignItems: "center", gap: 7, textDecoration: "none" }}><ClipboardList size={15}/> فاتورة شراء</Link><button type="button" className="secondary" disabled={loading} onClick={() => setRefreshToken((value) => value + 1)}><RefreshCw size={14}/> تحديث</button></div>
    </div>

    <form className="card" onSubmit={submitFilters} style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(150px, 1fr))", gap: 10, alignItems: "end", marginBottom: 14 }}>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>نوع المستند<select value={draft.type} onChange={(event) => setDraft((state) => ({ ...state, type: event.target.value as Filters["type"] }))}><option value="all">كل الفواتير</option><option value="sales">فواتير البيع</option><option value="purchases">فواتير الشراء</option></select></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>من تاريخ<input aria-label="بداية تاريخ الفاتورة" type="date" value={draft.date_from} onChange={(event) => setDraft((state) => ({ ...state, date_from: event.target.value }))}/></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>إلى تاريخ<input aria-label="نهاية تاريخ الفاتورة" type="date" min={draft.date_from || undefined} value={draft.date_to} onChange={(event) => setDraft((state) => ({ ...state, date_to: event.target.value }))}/></label>
      <label style={{ display: "grid", gap: 6, fontSize: 12, color: "#475569" }}>بحث برقم الفاتورة أو الطرف<input aria-label="بحث سجل الفواتير" value={draft.q} onChange={(event) => setDraft((state) => ({ ...state, q: event.target.value }))} maxLength={120} placeholder="رقم فاتورة، اسم، كود..."/></label>
      <button type="submit" className="primary" disabled={loading} style={{ minHeight: 40, padding: "9px 14px" }}><Search size={14}/> تطبيق البحث</button>
    </form>

    <section className="customer-profile-stats" style={{ marginBottom: 14 }}>
      <div className="card customer-stat"><span>عدد الفواتير في النتيجة</span><strong>{(result?.summary.invoice_count ?? 0).toLocaleString("ar-EG")}</strong><small>مطابقة للفلاتر الحالية</small></div>
      {summaryCurrencies.map((summary) => <div key={summary.currency} className="card customer-stat"><span>ملخص {summary.currency}</span><strong>{money(summary.sales_total, summary.currency)} بيع</strong><small>شراء {money(summary.purchases_total, summary.currency)} · مديونية العملاء {money(summary.customer_receivables_total, summary.currency)} · مستحقات الموردين {money(summary.supplier_payables_total, summary.currency)}</small></div>)}
    </section>

    {error && <div className="pos-alert error" role="alert" style={{ marginBottom: 12 }}>{error}</div>}
    <section className="card customer-profile-ledger">
      <div className="customer-section-heading"><div><h2><FileSearch size={17} style={{ verticalAlign: "middle", marginLeft: 5 }}/> جميع الفواتير المرحلة</h2><p>عرض موحد وقابل للبحث؛ التعديل على الفاتورة يظل من مسار المستند الأصلي حفاظًا على سجل الترحيل.</p></div><span className="badge blue">{pagination?.total.toLocaleString("ar-EG") ?? "—"} مستند</span></div>
      {loading && <div className="customer-profile-empty">جاري تحميل سجل الفواتير...</div>}
      {!loading && !error && <div className="table-wrap"><table><thead><tr><th>النوع</th><th>رقم النظام</th><th>رقم فاتورة المورد</th><th>التاريخ</th><th>العميل / المورد</th><th>الإجمالي</th><th>المدفوع</th><th>المرتجع</th><th>الرصيد المتبقي</th><th>فتح</th></tr></thead><tbody>
        {(result?.invoices ?? []).map((invoice) => <tr key={`${invoice.document_type}-${invoice.id}`}>
          <td><span className={`badge ${invoice.document_type === "sales" ? "green" : "blue"}`}>{invoice.document_type === "sales" ? "بيع" : "شراء"}</span></td>
          <td><strong>{invoice.document_number}</strong></td><td>{invoice.external_number || "—"}</td><td>{dateLabel(invoice.invoice_date)}</td>
          <td><strong>{invoice.party_name}</strong><small style={{ display: "block", color: "#64748b" }}>{invoice.party_code}</small></td>
          <td>{money(invoice.total, invoice.currency)}</td><td>{money(invoice.paid_amount, invoice.currency)}</td><td>{money(invoice.returns_total, invoice.currency)}</td>
          <td><strong className={Number(invoice.remaining_amount) > 0 ? "ledger-due" : "ledger-paid"}>{money(invoice.remaining_amount, invoice.currency)}</strong></td>
          <td>{invoice.document_type === "sales" ? <Link className="ledger-open-link" href={`/invoices/${encodeURIComponent(invoice.id)}`}>عرض الفاتورة</Link> : <Link className="ledger-open-link" href={`/suppliers/${encodeURIComponent(invoice.party_id)}`}>ملف المورد</Link>}</td>
        </tr>)}
        {!result?.invoices.length && <tr><td colSpan={10} style={{ textAlign: "center", padding: 24 }}>لا توجد فواتير مرحلة مطابقة للفلاتر المحددة.</td></tr>}
      </tbody></table></div>}
      {pagination && pagination.total > pagination.per_page && <div className="pos-pagination customer-history-pagination"><span>عرض {(pagination.from ?? 0).toLocaleString("ar-EG")}–{(pagination.to ?? 0).toLocaleString("ar-EG")} من {pagination.total.toLocaleString("ar-EG")}</span><div><button type="button" disabled={loading || page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}><ArrowRight size={14}/> السابق</button><span className="customer-page-index">{pagination.current_page.toLocaleString("ar-EG")} / {pagination.last_page.toLocaleString("ar-EG")}</span><button type="button" disabled={loading || page >= pagination.last_page} onClick={() => setPage((value) => Math.min(pagination.last_page, value + 1))}>التالي <ArrowRight size={14} className="customer-next-arrow"/></button></div></div>}
    </section>
  </main>;
}
