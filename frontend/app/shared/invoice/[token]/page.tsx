"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import { AlertTriangle, LoaderCircle, Printer, ShieldCheck } from "lucide-react";
import { api } from "@/lib/api";
import CompanyInvoiceHeader from "@/app/components/company-invoice-header";

type SharedInvoiceItem = { product_name_snapshot: string; sku_snapshot: string; quantity: number | string; unit_price: number | string; line_total: number | string };
type SharedInvoice = { document_number: string; invoice_date: string; party_name: string; subtotal: number | string; discount: number | string; tax: number | string; total: number | string; currency: string; items: SharedInvoiceItem[] };
type SharedPaymentReceipt = { document_number:string; payment_date:string; party_name:string; amount:number|string; currency:string; reference?:string|null; payment_method_name?:string|null; trip_number?:string|null };
const money = (value: number | string) => Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function SharedInvoicePage() {
  const params = useParams<{ token: string }>();
  const [invoice, setInvoice] = useState<SharedInvoice | null>(null);
  const [paymentReceipt, setPaymentReceipt] = useState<SharedPaymentReceipt | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const meta = document.createElement("meta");
    meta.name = "referrer";
    meta.content = "no-referrer";
    document.head.appendChild(meta);
    document.title = "مستند مشترك | NEXORA";
    let cancelled = false;
    api<{ invoice?: SharedInvoice; payment_receipt?: SharedPaymentReceipt; document_type?: string }>(`/shared/invoice/${encodeURIComponent(params.token)}`)
      .then((result) => {
        if (cancelled) return;
        if (result.payment_receipt) {
          setPaymentReceipt(result.payment_receipt);
          document.title = "سند صرف مشترك | NEXORA";
        } else if (result.invoice) {
          setInvoice(result.invoice);
          document.title = "فاتورة مشتركة | NEXORA";
        }
      })
      .catch(() => { if (!cancelled) setError("الرابط غير صالح أو انتهت صلاحيته أو تم إلغاؤه."); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; meta.remove(); };
  }, [params.token]);

  if (loading) return <main className="main"><div className="card" role="status"><LoaderCircle size={18} className="spin"/> جاري تحميل الفاتورة المشتركة...</div></main>;
  if (error || (!invoice && !paymentReceipt)) return <main className="main"><section className="card" style={{ maxWidth: 640, margin: "10vh auto", padding: 24 }}><AlertTriangle size={24}/><h1>تعذر فتح المستند</h1><p role="alert">{error || "المستند غير متاح عبر هذا الرابط."}</p><small>اطلب من المرسل إنشاء رابط مشاركة جديد إذا كان الرابط قد انتهى أو أُلغي.</small></section></main>;

  if (paymentReceipt) return <main className="main invoice-page" dir="rtl">
    <div className="topbar no-print"><div><h1 className="title">سند صرف مورد مشترك</h1><div className="subtitle"><ShieldCheck size={14}/> رابط عرض فقط · لا يتيح تعديل السند أو بيانات الحساب.</div></div><button type="button" className="primary" onClick={() => window.print()}><Printer size={15}/> طباعة / حفظ PDF</button></div>
    <article className="invoice-sheet invoice-sheet-premium company-invoice-sheet" style={{ margin: "0 auto" }} dir="rtl">
      <CompanyInvoiceHeader documentKind="سند صرف مورد · SUPPLIER PAYMENT" number={paymentReceipt.document_number} date={paymentReceipt.payment_date} extra="نسخة مشاركة للعرض فقط" />
      <section className="invoice-customer"><div><span>اسم المورد</span><strong>{paymentReceipt.party_name}</strong></div><div className="invoice-status"><ShieldCheck size={15}/> سند مرحّل · عرض فقط</div></section>
      <section className="invoice-totals" style={{ marginTop:24 }}>
        <div className="invoice-grand-total"><span>المبلغ المدفوع</span><strong>{money(paymentReceipt.amount)} {paymentReceipt.currency}</strong></div>
        <div><span>طريقة الدفع</span><strong>{paymentReceipt.payment_method_name || "—"}</strong></div>
        <div><span>رقم المرجع</span><strong>{paymentReceipt.reference || "—"}</strong></div>
        {paymentReceipt.trip_number && <div><span>تسوية الرحلة المرتبطة</span><strong>{paymentReceipt.trip_number}</strong></div>}
      </section>
      <footer className="invoice-footer company-invoice-footer"><strong>{"شركة الحياة للتوكيلات والتسويق"}</strong><span>أكبر مسوق للبراندات في مصر</span><span dir="ltr">01099965065  •  01270626723</span><small>هذا السند يثبت عملية السداد المسجلة؛ لا يتضمن أرصدة أو معلومات مالية داخلية أخرى.</small></footer>
    </article>
  </main>;

  if (!invoice) return <main className="main"><section className="card" role="alert">الفاتورة غير متاحة عبر هذا الرابط.</section></main>;

  return <main className="main invoice-page" dir="rtl">
    <div className="topbar no-print"><div><h1 className="title">فاتورة مشتركة</h1><div className="subtitle"><ShieldCheck size={14}/> رابط عرض فقط · لا يتيح تعديل الفاتورة أو بيانات الحساب.</div></div><button type="button" className="primary" onClick={() => window.print()}><Printer size={15}/> طباعة / حفظ PDF</button></div>
    <article className="invoice-sheet invoice-sheet-premium company-invoice-sheet" style={{ margin: "0 auto" }} dir="rtl">
      <CompanyInvoiceHeader documentKind="فاتورة مشتركة · INVOICE COPY" number={invoice.document_number} date={invoice.invoice_date} extra="نسخة مشاركة للعرض فقط" />
      <section className="invoice-customer"><div><span>الطرف</span><strong>{invoice.party_name}</strong></div><div className="invoice-status"><ShieldCheck size={15}/> عرض فقط</div></section>
      <div className="invoice-table-wrap"><table className="invoice-table"><thead><tr><th>#</th><th>الصنف</th><th>الكود</th><th>الكمية</th><th>سعر الوحدة</th><th>الإجمالي</th></tr></thead><tbody>{invoice.items.map((item, index) => <tr key={`${item.sku_snapshot}-${index}`}><td>{index + 1}</td><td>{item.product_name_snapshot}</td><td>{item.sku_snapshot}</td><td>{money(item.quantity)}</td><td>{money(item.unit_price)}</td><td>{money(item.line_total)}</td></tr>)}</tbody></table></div>
      <section className="invoice-totals"><div><span>الإجمالي الفرعي</span><strong>{money(invoice.subtotal)} {invoice.currency}</strong></div><div><span>الخصم</span><strong>{money(invoice.discount)} {invoice.currency}</strong></div><div><span>الضريبة</span><strong>{money(invoice.tax)} {invoice.currency}</strong></div><div className="invoice-grand-total"><span>الإجمالي النهائي</span><strong>{money(invoice.total)} {invoice.currency}</strong></div></section>
      <footer className="invoice-footer company-invoice-footer"><strong>{"شركة الحياة للتوكيلات والتسويق"}</strong><span>أكبر مسوق للبراندات في مصر</span><span dir="ltr">01099965065  •  01270626723</span><small>لا تتضمن هذه الصفحة تفاصيل الحساب الداخلية أو وسائل تعديل الفاتورة.</small></footer>
    </article>
  </main>;
}
