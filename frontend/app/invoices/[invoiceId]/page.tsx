"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowRight, CheckCircle2, Download, MessageCircle, Printer, RefreshCw, Share2 } from "lucide-react";
import { api } from "@/lib/api";
import InvoiceSharePanel from "@/app/components/invoice-share-panel";
import CompanyInvoiceHeader from "@/app/components/company-invoice-header";

type Invoice = {
  id: string;
  document_number: string;
  invoice_date: string;
  posted_at?: string;
  subtotal: number | string;
  discount: number | string;
  tax: number | string;
  total: number | string;
  paid_amount: number | string;
  balance_due: number | string;
  currency: string;
  notes?: string | null;
  trip_id?: string | null;
  trip_number?: string | null;
  customer_name: string;
  customer_code: string;
  customer_phone?: string | null;
  customer_address?: string | null;
};
type InvoiceLine = {
  id: string;
  product_id: string;
  product_name_snapshot: string;
  sku_snapshot: string;
  entered_quantity: number | string;
  quantity_base: number | string;
  unit_price_entered: number | string;
  unit_cost_snapshot: number | string;
  discount: number | string;
  tax: number | string;
  line_total: number | string;
};
type InvoiceResponse = { invoice: Invoice; items: InvoiceLine[] };

const money = (value: number | string) => Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function whatsappPhone(phone?: string | null): string {
  if (!phone) return "";
  let digits = phone.replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("0")) digits = `20${digits.slice(1)}`;
  if (!digits.startsWith("20") && digits.length === 10) digits = `20${digits}`;
  return digits.length >= 11 ? digits : "";
}

export default function CustomerInvoicePage() {
  const params = useParams<{ invoiceId: string }>();
  const invoiceId = params.invoiceId;
  const [data, setData] = useState<InvoiceResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [actionNotice, setActionNotice] = useState("");
  const [pdfBusy, setPdfBusy] = useState(false);
  const invoiceRef = useRef<HTMLElement | null>(null);

  const load = async () => {
    setLoading(true);
    setError("");
    try {
      setData(await api<InvoiceResponse>(`/sales/invoices/${encodeURIComponent(invoiceId)}`));
    } catch (err) {
      setError((err as Error).message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { if (invoiceId) void load(); }, [invoiceId]);

  const phone = useMemo(() => whatsappPhone(data?.invoice.customer_phone), [data?.invoice.customer_phone]);
  const whatsappUrl = useMemo(() => {
    if (!data || !phone) return "";
    const { invoice } = data;
    const text = `أهلًا ${invoice.customer_name}، مرفق تفاصيل فاتورة ${invoice.document_number} من NEXORA. إجمالي الفاتورة: ${money(invoice.total)} ${invoice.currency}. المدفوع: ${money(invoice.paid_amount)} ${invoice.currency}. المتبقي: ${money(invoice.balance_due)} ${invoice.currency}.`;
    return `https://wa.me/${phone}?text=${encodeURIComponent(text)}`;
  }, [data, phone]);

  const createPdfFile = async (): Promise<File> => {
    if (!invoiceRef.current || !data) throw new Error("الفاتورة لسه ما اتحمّلتش.");
    const { default: html2pdf } = await import("html2pdf.js");
    const blob = await html2pdf()
      .set({
        margin: [10, 10, 10, 10],
        filename: data.invoice.document_number + ".pdf",
        image: { type: "jpeg", quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true, backgroundColor: "#ffffff" },
        jsPDF: { unit: "mm", format: "a4", orientation: "portrait" },
      })
      .from(invoiceRef.current)
      .outputPdf("blob") as Blob;
    return new File([blob], data.invoice.document_number + ".pdf", { type: "application/pdf" });
  };

  const downloadPdf = async () => {
    setPdfBusy(true);
    setActionNotice("");
    try {
      const file = await createPdfFile();
      const url = URL.createObjectURL(file);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = file.name;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
    } catch (err) {
      setActionNotice((err as Error).message);
    } finally {
      setPdfBusy(false);
    }
  };

  const sharePdf = async () => {
    setPdfBusy(true);
    setActionNotice("");
    try {
      const invoiceData = data;
      if (!invoiceData) throw new Error("الفاتورة لسه ما اتحمّلتش.");
      const file = await createPdfFile();
      if (navigator.share && navigator.canShare?.({ files: [file] })) {
        await navigator.share({
          files: [file],
          title: "فاتورة " + invoiceData.invoice.document_number,
          text: "فاتورة العميل " + invoiceData.invoice.customer_name,
        });
      } else {
        const url = URL.createObjectURL(file);
        const anchor = document.createElement("a");
        anchor.href = url;
        anchor.download = file.name;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 30_000);
        setActionNotice("المتصفح لا يدعم مشاركة PDF مباشرة. تم تنزيل الفاتورة؛ أرفقها يدويًا في محادثة العميل.");
      }
    } catch (err) {
      const shareError = err as Error;
      if (shareError.name !== "AbortError") setActionNotice(shareError.message);
    } finally {
      setPdfBusy(false);
    }
  };

  if (loading) return <main className="main"><div role="status" className="card">جاري تجهيز الفاتورة...</div></main>;
  if (error || !data) return <main className="main"><div role="alert" className="card error">{error || "الفاتورة غير موجودة أو ليس لديك صلاحية الوصول إليها."}<button className="secondary" onClick={() => void load()}><RefreshCw size={13}/> إعادة المحاولة</button><Link href="/sales" style={{ marginRight: 12 }}>العودة للمبيعات</Link></div></main>;

  const invoice = data.invoice;
  return <main className="main invoice-page">
    <div className="topbar no-print">
      <div><h1 className="title">فاتورة العميل</h1><div className="subtitle">تنزيل ملف PDF أو مشاركته من جهازك، أو الطباعة.</div></div>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
        <Link href="/sales" className="secondary" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}><ArrowRight size={14}/> رجوع للمبيعات</Link>
        <button className="secondary" disabled={pdfBusy} onClick={() => void downloadPdf()}><Download size={14}/> {pdfBusy ? "جاري تجهيز PDF..." : "تنزيل PDF"}</button>
        <button className="secondary" disabled={pdfBusy} onClick={() => void sharePdf()}><Share2 size={14}/> مشاركة ملف PDF</button>
        <button className="secondary" onClick={() => window.print()}><Printer size={14}/> طباعة</button>
        {whatsappUrl && <a className="secondary" href={whatsappUrl} target="_blank" rel="noreferrer" style={{ display: "inline-flex", alignItems: "center", gap: 6 }}><MessageCircle size={14}/> مشاركة ملخص واتساب</a>}
      </div>
    </div>
    {actionNotice && <div role="status" className="badge blue no-print" style={{ display: "block", padding: 10, whiteSpace: "normal", marginBottom: 10 }}>{actionNotice}</div>}
    <InvoiceSharePanel invoiceType="sales" invoiceId={invoiceId} />

    <article className="invoice-sheet invoice-sheet-premium company-invoice-sheet" ref={invoiceRef} dir="rtl">
      <CompanyInvoiceHeader documentKind="فاتورة بيع · SALES INVOICE" number={invoice.document_number} date={invoice.invoice_date} location={invoice.trip_number ? `رحلة التوزيع ${invoice.trip_number}` : null} extra={`العملة: ${invoice.currency}`} />
      <section className="invoice-customer">
        <div className="invoice-customer-details"><span>بيانات العميل <small>BILL TO</small></span><strong>{invoice.customer_name}</strong><small><b>كود العميل</b> {invoice.customer_code}</small>{invoice.customer_address && <small><b>العنوان</b> {invoice.customer_address}</small>}{invoice.customer_phone && <small><b>الهاتف</b> <span dir="ltr">{invoice.customer_phone}</span></small>}</div>
        <div className="invoice-status-panel"><span className="invoice-status"><CheckCircle2 size={15}/> مسجلة بالنظام</span><small>حالة السداد</small><strong className={Number(invoice.balance_due) > 0 ? "invoice-unpaid" : "invoice-paid"}>{Number(invoice.balance_due) > 0 ? "متبقي على العميل" : "مدفوعة بالكامل"}</strong><b>{money(invoice.balance_due)} {invoice.currency}</b></div>
      </section>

      <div className="invoice-lines-heading"><strong>تفاصيل الأصناف</strong><span>ITEMIZED DETAILS</span></div>
      <div className="invoice-table-wrap"><table className="invoice-table"><thead><tr><th>#</th><th>الصنف</th><th>كود الصنف</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead><tbody>
        {data.items.map((line, index) => <tr key={line.id}><td>{index + 1}</td><td>{line.product_name_snapshot}</td><td>{line.sku_snapshot}</td><td>{money(line.entered_quantity)}</td><td>{money(line.unit_price_entered)}</td><td>{money(line.line_total)}</td></tr>)}
      </tbody></table></div>

      <section className="invoice-totals">
        <div><span>الإجمالي الفرعي</span><strong>{money(invoice.subtotal)} {invoice.currency}</strong></div>
        <div><span>الخصم</span><strong>{money(invoice.discount)} {invoice.currency}</strong></div>
        <div><span>الضريبة</span><strong>{money(invoice.tax)} {invoice.currency}</strong></div>
        <div className="invoice-grand-total"><span>الإجمالي النهائي</span><strong>{money(invoice.total)} {invoice.currency}</strong></div>
        <div><span>المدفوع</span><strong>{money(invoice.paid_amount)} {invoice.currency}</strong></div>
        <div className="invoice-balance"><span>المتبقي على العميل</span><strong>{money(invoice.balance_due)} {invoice.currency}</strong></div>
      </section>
      {invoice.notes && <section className="invoice-notes"><strong>ملاحظات</strong><p>{invoice.notes}</p></section>}
      <footer className="invoice-footer company-invoice-footer"><strong>شكرًا لتعاملكم مع {"شركة الحياة للتوكيلات والتسويق"}</strong><span>أكبر مسوق للبراندات في مصر</span><span dir="ltr">01099965065  •  01270626723</span><small>يرجى الاحتفاظ بالفاتورة للرجوع إليها عند الحاجة.</small><em>هذه نسخة تشغيلية وليست بديلًا عن الفاتورة الضريبية الإلكترونية الرسمية إذا كانت مطلوبة.</em></footer>
    </article>

    {phone && <p className="no-print" style={{ color: "#64748b", fontSize: 11, marginTop: 10 }}>زر واتساب يفتح رسالة الملخص. زر مشاركة PDF يتيح اختيار WhatsApp من قائمة المشاركة على الأجهزة المدعومة. الإرسال التلقائي من السيرفر يحتاج إعداد WhatsApp Business API الرسمي.</p>}
  </main>;
}
