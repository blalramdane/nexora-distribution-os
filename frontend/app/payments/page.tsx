"use client";

import { useEffect, useState } from "react";
import { CircleDollarSign } from "lucide-react";
import { api } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";
import InvoiceSharePanel from "@/app/components/invoice-share-panel";
import CompanyInvoiceHeader from "@/app/components/company-invoice-header";

type Party = { id: string; name: string; code: string };
type Ref = { accounts: { id: string; name: string }[]; payment_methods: { id: string; name_ar: string; requires_reference?: boolean }[] };
type Invoice = { id: string; document_number: string; invoice_date: string; total: number; paid_amount: number; balance_due: number; trip_id?: string | null };
type Trip = { id: string; trip_number: string; vehicle_name: string; status: string };
type SupplierTripSettlement = { id:string; trip_id:string; trip_number:string; trip_date:string; sold_quantity_base:number; net_quantity_base:number; settlement_amount:number; paid_amount:number; outstanding_amount:number; settlement_basis:string; is_payable:boolean };
type PaymentReceipt = { id:string; supplierName:string; supplierPhone?:string|null; amount:number; accountName:string; methodName:string; reference:string; date:string; tripNumber?:string };

function whatsappPaymentUrl(receipt: PaymentReceipt) {
  let phone = (receipt.supplierPhone || "").replace(/\D/g, "");
  if (phone.startsWith("00")) phone = phone.slice(2);
  if (phone.startsWith("0")) phone = `20${phone.slice(1)}`;
  const message = `سند صرف من NEXORA Distribution\nرقم السند: ${receipt.id}\nالمورد: ${receipt.supplierName}\nالمبلغ: ${receipt.amount.toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ج.م\nالتاريخ: ${receipt.date}\nطريقة الدفع: ${receipt.methodName}\nالحساب: ${receipt.accountName}${receipt.reference ? `\nالمرجع: ${receipt.reference}` : ""}\nهذا ملخص السند؛ يرجى الاحتفاظ به للمراجعة.`;
  return { url: `https://wa.me/${phone}?text=${encodeURIComponent(message)}`, message };
}

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
  const [supplierSettlements, setSupplierSettlements] = useState<SupplierTripSettlement[]>([]);
  const [tripSupplierSettlementId, setTripSupplierSettlementId] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");
  const [loadError, setLoadError] = useState("");
  const [receipt, setReceipt] = useState<PaymentReceipt|null>(null);

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
    setTripSupplierSettlementId("");
    setSupplierSettlements([]);
    if (!party) return;
    if (partyType === "customer") {
      api<Invoice[]>(`/sales/invoices?customer_id=${encodeURIComponent(party)}`)
        .then(setInvoices)
        .catch((e) => setLoadError((e as Error).message));
    } else {
      api<SupplierTripSettlement[]>(`/suppliers/${encodeURIComponent(party)}/trip-settlements`)
        .then(setSupplierSettlements)
        .catch((e) => setLoadError((e as Error).message));
    }
  }, [party, partyType]);

  const selectedInvoice = invoices.find((invoice) => invoice.id === invoiceId);
  const selectedSupplierSettlement = supplierSettlements.find((settlement) => settlement.id === tripSupplierSettlementId);
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
    if (tripSupplierSettlementId && (!selectedSupplierSettlement || value > Number(selectedSupplierSettlement.outstanding_amount))) {
      setMsg("مبلغ السداد أكبر من المتبقي في تسوية الرحلة المختارة.");
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
    setReceipt(null);
    try {
      const payload = {
        party_type: partyType,
        party_id: party,
        financial_account_id: account,
        payment_method_id: method,
        direction: partyType === "customer" ? "inbound" : "outbound",
        amount: value,
        reference: reference.trim() || undefined,
        trip_id: tripId || undefined,
        trip_supplier_settlement_id: tripSupplierSettlementId || undefined,
        allocations: invoiceId ? [{ document_type: "sales_invoice", document_id: invoiceId, amount: value }] : undefined,
      };
      const key = await getStableIdempotencyKey("payments", payload);
      const postedPayment = await api<{id:string;amount:string|number}>("/payments", {
        method: "POST",
        body: JSON.stringify({ ...payload, idempotency_key: key }),
      });
      clearStableIdempotencyKey("payments", key);
      if (partyType === "supplier") {
        const selectedSupplier = suppliers.find((item) => item.id === party);
        setReceipt({
          id: postedPayment.id,
          supplierName: selectedSupplier?.name || "المورد",
          supplierPhone: (selectedSupplier as (Party & {phone?:string|null}) | undefined)?.phone,
          amount: Number(postedPayment.amount ?? value),
          accountName: refs.accounts.find((item) => item.id === account)?.name || "—",
          methodName: selectedMethod?.name_ar || "—",
          reference: reference.trim(),
          date: new Date().toLocaleDateString("ar-EG", { year:"numeric", month:"long", day:"numeric" }),
          tripNumber: selectedSupplierSettlement?.trip_number,
        });
      }
      setMsg(invoiceId
        ? "تم تسجيل التحصيل وتخصيصه للفاتورة وتحديث رصيد العميل."
        : "تم تسجيل الحركة المالية.");
      setAmount("");
      setReference("");
      setInvoiceId("");
      setTripId("");
      setTripSupplierSettlementId("");
      if (partyType === "customer") {
        setInvoices(await api<Invoice[]>(`/sales/invoices?customer_id=${encodeURIComponent(party)}`));
      } else {
        setSupplierSettlements(await api<SupplierTripSettlement[]>(`/suppliers/${encodeURIComponent(party)}/trip-settlements`));
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
        {partyType === "supplier" && <label style={{ gridColumn: "1 / -1" }}>تسوية رحلة (اختياري)<select aria-label="تسوية رحلة المورد" value={tripSupplierSettlementId} onChange={(e) => { const id=e.target.value; setTripSupplierSettlementId(id); const row=supplierSettlements.find((item)=>item.id===id); if(row)setAmount(Number(row.outstanding_amount).toFixed(2)); }}><option value="">سداد عام للمورد — بدون ربط برحلة</option>{supplierSettlements.filter((row)=>row.is_payable&&row.outstanding_amount>0).map((row)=><option key={row.id} value={row.id}>{row.trip_number} · مستحق {Number(row.outstanding_amount).toLocaleString("ar-EG")} ج.م · صافي كمية {Number(row.net_quantity_base).toLocaleString("ar-EG")}</option>)}</select></label>}
        <label>الحساب المالي<select aria-label="الحساب المالي" value={account} onChange={(e) => setAccount(e.target.value)}>{refs.accounts.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></label>
        <label>طريقة الدفع<select aria-label="طريقة الدفع" value={method} onChange={(e) => setMethod(e.target.value)}>{refs.payment_methods.map((x) => <option key={x.id} value={x.id}>{x.name_ar}</option>)}</select></label>
        {selectedMethod?.requires_reference && <label>الرقم المرجعي المطلوب<input aria-label="الرقم المرجعي" value={reference} onChange={(e) => setReference(e.target.value)} required maxLength={255} placeholder="رقم الإيصال أو التحويل" /></label>}
        {partyType === "customer" && <label style={{ gridColumn: "1 / -1" }}>رحلة التوزيع (اختياري)<select aria-label="رحلة التحصيل" value={tripId} onChange={(e) => setTripId(e.target.value)}><option value="">تحصيل خارج رحلة</option>{trips.map((x) => <option key={x.id} value={x.id}>{x.trip_number} — {x.vehicle_name}</option>)}</select></label>}
        <label>المبلغ<input aria-label="المبلغ" type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} /></label>
      </div>
      {selectedInvoice && <div className="badge blue" style={{ display: "block", padding: 10, marginTop: 12 }}>رصيد الفاتورة قبل التحصيل: {Number(selectedInvoice.balance_due).toLocaleString("ar-EG")} EGP</div>}
      {partyType === "supplier" && supplierSettlements.some((row) => !row.is_payable) && <div className="trip-context" style={{ marginTop: 12, whiteSpace: "normal" }}>توجد تسويات تاريخية بتكلفة المصدر للعرض والمراجعة فقط؛ لن تظهر كسندات صرف جديدة حتى لا تتضاعف مديونية فاتورة الشراء.</div>}
      {selectedSupplierSettlement && <div className="badge blue" style={{ display: "block", padding: 10, marginTop: 12, whiteSpace:"normal" }}>تسوية الرحلة {selectedSupplierSettlement.trip_number}: إجمالي المستحق {Number(selectedSupplierSettlement.settlement_amount).toLocaleString("ar-EG")} ج.م · تم سداده {Number(selectedSupplierSettlement.paid_amount).toLocaleString("ar-EG")} ج.م · المتبقي {Number(selectedSupplierSettlement.outstanding_amount).toLocaleString("ar-EG")} ج.م</div>}
      {msg && <div role="status" className="badge blue" style={{ display: "block", padding: 10, marginTop: 12, whiteSpace: "normal" }}>{msg}</div>}
      <button className="primary" disabled={saving || loading || Boolean(loadError)} onClick={submit} style={{ marginTop: 12, width: "100%" }}>{saving ? "جاري التسجيل..." : "تسجيل العملية"}</button>
    </section>
    {receipt && <section className="card payment-receipt" style={{ maxWidth:980, marginTop:14, border:"1px solid #cbd5e1" }}>
      <div style={{ display:"flex", justifyContent:"space-between", gap:12, alignItems:"start", flexWrap:"wrap" }}>
        <div><span className="badge green no-print">تم تسجيل السداد</span><h2 style={{ margin:"10px 0 4px" }}>سند صرف مورد</h2></div>
        <strong style={{ fontSize:24 }}>{receipt.amount.toLocaleString("ar-EG", { minimumFractionDigits:2, maximumFractionDigits:2 })} ج.م</strong>
      </div>
      <article className="invoice-sheet invoice-sheet-premium company-invoice-sheet" dir="rtl" style={{ marginTop:16 }}>
        <CompanyInvoiceHeader documentKind="سند صرف مورد · SUPPLIER PAYMENT" number={receipt.id} date={receipt.date} location={receipt.accountName} extra={receipt.tripNumber ? `تسوية الرحلة: ${receipt.tripNumber}` : null} />
      <div className="table-wrap" style={{ marginTop:14 }}><table className="invoice-table"><tbody>
        <tr><th>اسم المورد</th><td>{receipt.supplierName}</td></tr>
        <tr><th>التاريخ</th><td>{receipt.date}</td></tr>
        <tr><th>طريقة الدفع</th><td>{receipt.methodName}</td></tr>
        <tr><th>الحساب المالي</th><td>{receipt.accountName}</td></tr>
        <tr><th>المرجع</th><td>{receipt.reference || "—"}</td></tr>
        <tr><th>حالة السند</th><td>مسجل بالنظام</td></tr>
        {receipt.tripNumber && <tr><th>تسوية الرحلة المرتبطة</th><td>{receipt.tripNumber}</td></tr>}
      </tbody></table></div>
      <footer className="invoice-footer company-invoice-footer"><strong>{"شركة الحياة للتوكيلات والتسويق"}</strong><span>أكبر مسوق للبراندات في مصر</span><span dir="ltr">01099965065  •  01270626723</span></footer>
      </article>
      <div className="no-print" style={{ display:"flex", flexWrap:"wrap", gap:8, marginTop:12 }}>
        <button type="button" className="primary" onClick={() => window.print()}>طباعة / حفظ PDF</button>
        {receipt.supplierPhone && <a className="secondary" href={whatsappPaymentUrl(receipt).url} target="_blank" rel="noreferrer" style={{ textDecoration:"none", display:"inline-flex", alignItems:"center", padding:"9px 12px", border:"1px solid #cbd5e1", borderRadius:8 }}>إرسال ملخص السند للمورد عبر واتساب</a>}
        <button type="button" className="secondary" onClick={() => void navigator.clipboard.writeText(whatsappPaymentUrl(receipt).message)}>نسخ تفاصيل السند</button>
      </div>
      <InvoiceSharePanel invoiceType="supplier_payment" invoiceId={receipt.id} recipientPhone={receipt.supplierPhone}/>
      <p className="no-print" style={{ color:"#64748b", fontSize:12, marginBottom:0 }}>واتساب يفتح رسالة جاهزة للمراجعة والإرسال؛ إرسال الرسالة لا يتم تلقائيًا.</p>
    </section>}
  </main>;
}
