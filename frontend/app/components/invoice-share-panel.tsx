"use client";

import { useEffect, useState } from "react";
import { Check, Copy, Link2, LoaderCircle, Share2, ShieldCheck, X } from "lucide-react";
import { api } from "@/lib/api";

type InvoiceType = "sales" | "purchase" | "supplier_payment";
type ShareRecord = {
  id: string;
  invoice_type: InvoiceType;
  invoice_id: string;
  expires_at: string | null;
  revoked_at: string | null;
  access_count: number;
  last_accessed_at: string | null;
  created_at: string;
};
type CreatedShare = { id: string; path: string; expires_at: string };

const dateTime = (value?: string | null) => value
  ? new Date(value).toLocaleString("ar-EG", { dateStyle: "medium", timeStyle: "short" })
  : "—";

function phoneForWhatsApp(value?: string | null) {
  let digits = (value ?? "").replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("0")) digits = `20${digits.slice(1)}`;
  return digits;
}

export default function InvoiceSharePanel({ invoiceType, invoiceId, recipientPhone }: { invoiceType: InvoiceType; invoiceId: string; recipientPhone?: string | null }) {
  const [open, setOpen] = useState(false);
  const [links, setLinks] = useState<ShareRecord[]>([]);
  const [created, setCreated] = useState<CreatedShare | null>(null);
  const [days, setDays] = useState("7");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function loadLinks() {
    const query = new URLSearchParams({ invoice_type: invoiceType, invoice_id: invoiceId });
    const result = await api<ShareRecord[]>(`/invoice-shares?${query.toString()}`);
    setLinks(result);
  }

  useEffect(() => {
    if (!open || !invoiceId) return;
    let cancelled = false;
    api<ShareRecord[]>(`/invoice-shares?${new URLSearchParams({ invoice_type: invoiceType, invoice_id: invoiceId }).toString()}`)
      .then((result) => { if (!cancelled) setLinks(result); })
      .catch((reason: unknown) => { if (!cancelled) setError(reason instanceof Error ? reason.message : "تعذر تحميل روابط المشاركة."); });
    return () => { cancelled = true; };
  }, [open, invoiceId, invoiceType]);

  async function createLink() {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const result = await api<CreatedShare>("/invoice-shares", {
        method: "POST",
        body: JSON.stringify({ invoice_type: invoiceType, invoice_id: invoiceId, expires_in_days: Number(days) }),
      });
      setCreated(result);
      await loadLinks();
      setNotice(invoiceType === "supplier_payment" ? "تم إنشاء رابط سند الصرف للعرض فقط. انسخه أو أرسله للمورد." : "تم إنشاء رابط مشاركة للعرض فقط. انسخه أو أرسله للمستلم.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر إنشاء رابط المشاركة.");
    } finally {
      setBusy(false);
    }
  }

  async function copyLink() {
    if (!created) return;
    const url = new URL(created.path, window.location.origin).toString();
    try {
      await navigator.clipboard.writeText(url);
      setNotice("تم نسخ الرابط. تقدر تبعته على واتساب.");
    } catch {
      setError("المتصفح منع النسخ التلقائي. انسخ الرابط من الحقل الظاهر أسفل الزر.");
    }
  }

  async function revokeLink(link: ShareRecord) {
    if (link.revoked_at || !window.confirm("تأكيد إلغاء رابط المشاركة؟ لن يقدر المستلم يفتحه بعد الإلغاء.")) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await api(`/invoice-shares/${encodeURIComponent(link.id)}`, { method: "DELETE" });
      if (created?.id === link.id) setCreated(null);
      await loadLinks();
      setNotice("تم إلغاء الرابط بنجاح.");
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "تعذر إلغاء الرابط.");
    } finally {
      setBusy(false);
    }
  }

  const createdUrl = created ? new URL(created.path, typeof window !== "undefined" ? window.location.origin : "http://localhost").toString() : "";

  return <section className="invoice-share-panel no-print" style={{ margin: "12px 0", padding: 14, border: "1px solid var(--border, #dbe3ed)", borderRadius: 14 }}>
    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
      <div style={{ display: "flex", gap: 9, alignItems: "center" }}><ShieldCheck size={18}/><div><strong>رابط مشاركة آمن</strong><div style={{ fontSize: 12, color: "var(--muted, #64748b)" }}>عرض فقط، بصلاحية محددة، وقابل للإلغاء.</div></div></div>
      <button type="button" className="secondary" onClick={() => { setOpen((value) => !value); setError(""); setNotice(""); }}><Share2 size={14}/>{open ? "إخفاء المشاركة" : "إدارة روابط المشاركة"}</button>
    </div>

    {open && <div style={{ marginTop: 14 }}>
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "end" }}>
        <label style={{ display: "grid", gap: 6, fontSize: 13 }}>صلاحية الرابط
          <select value={days} onChange={(event) => setDays(event.target.value)} disabled={busy}>
            <option value="1">24 ساعة</option><option value="7">7 أيام</option><option value="30">30 يومًا</option><option value="90">90 يومًا</option>
          </select>
        </label>
        <button type="button" className="primary" disabled={busy} onClick={() => void createLink()}>{busy ? <LoaderCircle size={14} className="spin"/> : <Link2 size={14}/>} إنشاء رابط جديد</button>
      </div>

      {created && <div style={{ marginTop: 12, padding: 10, borderRadius: 10, background: "var(--surface-soft, #f8fafc)" }}>
        <label style={{ display: "grid", gap: 6, fontSize: 12 }}>الرابط — متاح للنسخ الآن
          <input readOnly value={createdUrl} onFocus={(event) => event.currentTarget.select()} aria-label="رابط الفاتورة القابل للمشاركة"/>
        </label>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, marginTop: 8 }}>
          <button type="button" className="secondary" onClick={() => void copyLink()}><Copy size={14}/> نسخ الرابط</button>
          <a className="secondary" href={`https://wa.me/${phoneForWhatsApp(recipientPhone)}?text=${encodeURIComponent(`${invoiceType === "supplier_payment" ? "سند صرف من NEXORA" : "فاتورة من NEXORA"}: ${createdUrl}`)}`} target="_blank" rel="noreferrer" style={{ display: "inline-flex", alignItems: "center", gap: 6, textDecoration: "none" }}><Share2 size={14}/> {invoiceType === "supplier_payment" ? "إرسال السند للمورد عبر واتساب" : "إرسال واتساب"}</a>
        </div>
        <small style={{ display: "block", marginTop: 8, color: "var(--muted, #64748b)" }}>ينتهي في: {dateTime(created.expires_at)}. الرمز الكامل لا يُخزّن لاسترجاعه لاحقًا؛ احتفظ بنسخة من الرابط بعد إرساله.</small>
      </div>}

      {links.length > 0 && <div style={{ display: "grid", gap: 8, marginTop: 14 }}><strong>سجل الروابط</strong>
        {links.map((link) => {
          const expired = link.expires_at !== null && new Date(link.expires_at).getTime() <= Date.now();
          const inactive = !!link.revoked_at || expired;
          return <div key={link.id} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, flexWrap: "wrap", padding: 10, border: "1px solid var(--border, #dbe3ed)", borderRadius: 10 }}>
            <div style={{ display: "grid", gap: 3, fontSize: 12 }}><strong>{link.revoked_at ? "ملغي" : expired ? "منتهي الصلاحية" : "نشط"} · {link.access_count.toLocaleString("ar-EG")} مرات فتح</strong><span style={{ color: "var(--muted, #64748b)" }}>أُنشئ {dateTime(link.created_at)} · ينتهي {dateTime(link.expires_at)}</span><span style={{ color: "var(--muted, #64748b)" }}>آخر فتح: {dateTime(link.last_accessed_at)}</span></div>
            <button type="button" className="secondary" disabled={busy || inactive} onClick={() => void revokeLink(link)}><X size={13}/> إلغاء</button>
          </div>;
        })}
      </div>}

      {error && <div className="pos-alert error" role="alert" style={{ marginTop: 10 }}>{error}</div>}
      {notice && <div className="pos-alert success" role="status" style={{ marginTop: 10 }}><Check size={14}/>{notice}</div>}
    </div>}
  </section>;
}
