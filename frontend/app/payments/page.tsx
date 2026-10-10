"use client";

import { useEffect, useState } from "react";
import { CircleDollarSign } from "lucide-react";
import { api } from "@/lib/api";

type Party = { id: string; name: string; code: string };
type Ref = { accounts: { id: string; name: string }[]; payment_methods: { id: string; name_ar: string; requires_reference?: boolean }[] };
type Invoice = { id: string; document_number: string; invoice_date: string; total: number; paid_amount: number; balance_due: number; trip_id?: string | null };
type Trip = { id: string; trip_number: string; vehicle_name: string; status: string };

export default function Payments() {
  const [customers, setCustomers] = useState<Party[]>([]);
  const [suppliers, setSuppliers] = useState<Party[]>([]);
  const [refs, setRefs] = useState<Ref>({ accounts: [], payment_methods: [] });
  const [trips, setTrips] = useState<Trip[]>([]);
  const [partyType, setPartyType] = useState<"customer" | "supplier">("customer");
  const [party, setParty] = useState("");
  const [account, setAccount] = useState("");
  const [method, setMethod] = useState("");
  const [reference, setReference] = useState("");
  const [amount, setAmount] = useState("");
  const [tripId, setTripId] = useState("");
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [invoiceId, setInvoiceId] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    Promise.all([
      api<Party[]>("/customers"),
      api<Party[]>("/suppliers"),
      api<Ref>("/finance/references"),
      api<Trip[]>("/trips"),
    ]).then(([c, s, r, t]) => {
      setCustomers(c);
      setSuppliers(s);
      setRefs(r);
      setAccount(r.accounts[0]?.id || "");
      setMethod(r.payment_methods[0]?.id || "");
      setTrips(t.filter((x) => !["completed", "cancelled"].includes(x.status)));
      setLoadError("");
    }).catch((e) => setLoadError((e as Error).message)).finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    setInvoiceId("");
    setInvoices([]);
    if (partyType !== "customer" || !party) return;
    api<Invoice[]>(`/sales/invoices?customer_id=${encodeURIComponent(party)}`)
      .then(setInvoices)
      .catch((e) => setLoadError((e as Error).message));
  }, [party, partyType]);

  const selectedInvoice = invoices.find((invoice) => invoice.id === invoiceId);
  const selectedMethod = refs.payment_methods.find((item) => item.id === method);
  const list = partyType === "customer" ? customers : suppliers;

  const submit = async () => {
    const value = Number(amount);
    if (!party || !account || !method || !Number.isFinite(value) || value <= 0) {
      setMsg("أكمل بيانات الطرف والحساب وطريقة الدفع ومبلغ صحيح أكبر من صفر.");
      return;
    }
    if (invoiceId && (!selectedInvoice || value > Number(selectedInvoice.balance_due))) {
      setMsg("مبلغ التحصيل أكبر من الرصيد المستحق على الفاتورة المختارة.");
      return;
    }
    if (selectedMethod?.requires_reference && !reference.trim()) {
      setMsg("طريقة الدفع المختارة تتطلب إدخال الرقم المرجعي.");
      return;
    }
    if (tripId && (partyType !== "customer" || !tripId)) {
      setMsg("ربط الرحلة متاح لتحصيلات العملاء فقط.");
      return;
    }

    setSaving(true);
    setMsg("");
    try {
      await api("/payments", {
        method: "POST",
        body: JSON.stringify({
          party_type: partyType,
          party_id: party,
          financial_account_id: account,
          payment_method_id: method,
          direction: partyType === "customer" ? "inbound" : "outbound",
          amount: value,
          reference: reference.trim() || undefined,
          trip_id: tripId || undefined,
          idempotency_key: crypto.randomUUID(),
          allocations: invoiceId ? [{ document_type: "sales_invoice", document_id: invoiceId, amount: value }] : undefined,
        }),
      });
      setMsg(invoiceId
        ? "تم تسجيل التحصيل وتخصيصه للفاتورة وتحديث رصيد العميل."
        : "تم تسجيل الحركة المالية.");
      setAmount("");
      setReference("");
      setInvoiceId("");
      setTripId("");
      if (partyType === "customer") {
        setInvoices(await api<Invoice[]>(`/sales/invoices?customer_id=${encodeURIComponent(party)}`));
      }
    } catch (e) {
      setMsg((e as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">التحصيلات والمدفوعات</h1><div className="subtitle">Customer Collection / Supplier Payment</div></div>
      <CircleDollarSign size={20} />
    </div>
    <section className="card" style={{ maxWidth: 720 }}>
      {loading && <div role="status" className="badge blue" style={{ display: "block", padding: 10 }}>جاري تحميل الحسابات والتحصيلات...</div>}
      {loadError && <div role="alert" className="badge red" style={{ display: "block", padding: 10, whiteSpace: "normal" }}>{loadError}</div>}
      <div className="form-grid">
        <label>النوع<select aria-label="نوع العملية" value={partyType} onChange={(e) => { setPartyType(e.target.value as "customer" | "supplier"); setParty(""); setTripId(""); }}><option value="customer">تحصيل من عميل</option><option value="supplier">دفع لمورد</option></select></label>
        <label>الطرف<select aria-label="الطرف" value={party} onChange={(e) => setParty(e.target.value)}><option value="">اختار...</option>{list.map((x) => <option key={x.id} value={x.id}>{x.name} — {x.code}</option>)}</select></label>
        {partyType === "customer" && <label style={{ gridColumn: "1 / -1" }}>الفاتورة المستحقة (اختياري)<select aria-label="الفاتورة المستحقة" value={invoiceId} onChange={(e) => { const id = e.target.value; setInvoiceId(id); const row = invoices.find((x) => x.id === id); if (row) setAmount(Number(row.balance_due).toFixed(2)); }}><option value="">تحصيل غير مخصص لفاتورة</option>{invoices.map((x) => <option key={x.id} value={x.id}>{x.document_number} — متبقي {Number(x.balance_due).toLocaleString("ar-EG")} EGP</option>)}</select></label>}
        <label>الحساب المالي<select aria-label="الحساب المالي" value={account} onChange={(e) => setAccount(e.target.value)}>{refs.accounts.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
        <label>طريقة الدفع<select aria-label="طريقة الدفع" value={method} onChange={(e) => setMethod(e.target.value)}>{refs.payment_methods.map((x) => <option key={x.id} value={x.id}>{x.name_ar}</option>)}</select></label>
        {selectedMethod?.requires_reference && <label>الرقم المرجعي المطلوب<input aria-label="الرقم المرجعي" value={reference} onChange={(e) => setReference(e.target.value)} required maxLength={255} placeholder="رقم الإيصال أو التحويل" /></label>}
        {partyType === "customer" && <label style={{ gridColumn: "1 / -1" }}>رحلة التوزيع (اختياري)<select aria-label="رحلة التحصيل" value={tripId} onChange={(e) => setTripId(e.target.value)}><option value="">تحصيل خارج رحلة</option>{trips.map((x) => <option key={x.id} value={x.id}>{x.trip_number} — {x.vehicle_name}</option>)}</select></label>}
        <label>المبلغ<input aria-label="المبلغ" type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>
      </div>
      {selectedInvoice && <div className="badge blue" style={{ display: "block", padding: 10, marginTop: 12 }}>رصيد الفاتورة قبل التحصيل: {Number(selectedInvoice.balance_due).toLocaleString("ar-EG")} EGP</div>}
      {msg && <div role="status" className="badge blue" style={{ display: "block", padding: 10, marginTop: 12, whiteSpace: "normal" }}>{msg}</div>}
      <button className="primary" disabled={saving || loading || Boolean(loadError)} onClick={submit} style={{ marginTop: 12, width: "100%" }}>{saving ? "جاري التسجيل..." : "تسجيل العملية"}</button>
    </section>
  </main>;
}
