"use client";

import { useEffect, useState } from "react";
import { PackageCheck, RotateCcw, Search } from "lucide-react";
import { api } from "@/lib/api";

type Party = { id: string; name: string; code: string };
type Location = { id: string; name: string; code: string };
type Invoice = { id: string; document_number: string; invoice_date: string; total: number; paid_amount: number; balance_due: number; trip_id?: string | null };
type InvoiceItem = {
  id: string;
  product_id: string;
  product_name_snapshot: string;
  sku_snapshot: string;
  entered_quantity: number;
  quantity_base: number;
  conversion_factor_snapshot: number;
  unit_price_entered: number;
  returned_quantity_base: number;
  remaining_quantity_base: number;
};
type ReturnLine = { item: InvoiceItem; quantity: string };

export default function Returns() {
  const [customers, setCustomers] = useState<Party[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [customerId, setCustomerId] = useState("");
  const [locationId, setLocationId] = useState("");
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [invoiceId, setInvoiceId] = useState("");
  const [lines, setLines] = useState<ReturnLine[]>([]);
  const [loadingMaster, setLoadingMaster] = useState(true);
  const [loadingInvoices, setLoadingInvoices] = useState(false);
  const [loadingItems, setLoadingItems] = useState(false);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [loadError, setLoadError] = useState("");

  useEffect(() => {
    Promise.all([api<Party[]>("/customers"), api<Location[]>("/locations")])
      .then(([customerRows, locationRows]) => {
        setCustomers(customerRows);
        setLocations(locationRows);
        setLocationId(locationRows.find((item) => item.code === "WH-MAIN")?.id || locationRows[0]?.id || "");
      })
      .catch((error) => setLoadError((error as Error).message))
      .finally(() => setLoadingMaster(false));
  }, []);

  useEffect(() => {
    setInvoiceId("");
    setInvoices([]);
    setLines([]);
    if (!customerId) return;

    setLoadingInvoices(true);
    setLoadError("");
    api<Invoice[]>(`/sales/history?customer_id=${encodeURIComponent(customerId)}`)
      .then(setInvoices)
      .catch((error) => setLoadError((error as Error).message))
      .finally(() => setLoadingInvoices(false));
  }, [customerId]);

  useEffect(() => {
    setLines([]);
    if (!invoiceId) return;

    setLoadingItems(true);
    setLoadError("");
    api<InvoiceItem[]>(`/sales/invoices/${encodeURIComponent(invoiceId)}/items`)
      .then((items) => setLines(items.map((item) => ({ item, quantity: "0" }))))
      .catch((error) => setLoadError((error as Error).message))
      .finally(() => setLoadingItems(false));
  }, [invoiceId]);

  const selectedInvoice = invoices.find((invoice) => invoice.id === invoiceId);
  const returnLines = lines.filter((line) => Number(line.quantity) > 0);
  const total = returnLines.reduce((sum, line) => sum + Number(line.quantity) * Number(line.item.unit_price_entered), 0);

  const setQuantity = (itemId: string, value: string) => {
    setLines((current) => current.map((line) => line.item.id === itemId ? { ...line, quantity: value } : line));
    setMessage("");
  };

  const refreshInvoiceItems = async (id: string) => {
    const items = await api<InvoiceItem[]>(`/sales/invoices/${encodeURIComponent(id)}/items`);
    setLines(items.map((item) => ({ item, quantity: "0" })));
  };

  const post = async () => {
    if (!customerId || !locationId || !invoiceId || !selectedInvoice) {
      setMessage("اختار العميل والفاتورة الأصلية وموقع استلام المرتجع.");
      return;
    }
    if (!returnLines.length) {
      setMessage("أدخل كمية مرتجعة أكبر من صفر لصنف واحد على الأقل.");
      return;
    }

    const exceedsSoldQuantity = returnLines.some((line) => {
      const conversion = Number(line.item.conversion_factor_snapshot || 1);
      const remainingEntered = Number(line.item.remaining_quantity_base) / conversion;
      return !Number.isFinite(Number(line.quantity)) || Number(line.quantity) <= 0 || Number(line.quantity) > remainingEntered + 0.000001;
    });
    if (exceedsSoldQuantity) {
      setMessage("إحدى الكميات تتجاوز الكمية المتبقية من الفاتورة الأصلية.");
      return;
    }

    setSaving(true);
    setMessage("");
    setLoadError("");
    try {
      const result = await api<{ document_number: string; total: string }>("/returns/sales", {
        method: "POST",
        body: JSON.stringify({
          customer_id: customerId,
          location_id: locationId,
          original_sales_invoice_id: invoiceId,
          idempotency_key: crypto.randomUUID(),
          items: returnLines.map((line) => ({
            product_id: line.item.product_id,
            quantity: Number(line.quantity),
            conversion_factor: Number(line.item.conversion_factor_snapshot || 1),
            unit_price: Number(line.item.unit_price_entered),
            original_sales_invoice_item_id: line.item.id,
          })),
        }),
      });
      setMessage(`تم تسجيل المرتجع ${result.document_number} بقيمة ${Number(result.total).toLocaleString("ar-EG")} EGP وتحديث المخزون وحساب العميل.`);
      try {
        await Promise.all([
          refreshInvoiceItems(invoiceId),
          api<Invoice[]>(`/sales/history?customer_id=${encodeURIComponent(customerId)}`).then(setInvoices),
        ]);
      } catch {
        // The return is already posted; do not tell the user it failed and invite a duplicate.
        setLoadError("تم تسجيل المرتجع بنجاح، لكن تعذر تحديث بيانات الفاتورة. حدّث الصفحة للتحقق من الرصيد.");
      }
    } catch (error) {
      setMessage((error as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">المرتجعات</h1><div className="subtitle">ربط كل مرتجع بفاتورته الأصلية لمنع إرجاع كمية غير مباعة.</div></div>
      <span className="badge amber"><RotateCcw size={11} /> Sales Return</span>
    </div>

    <section className="card">
      {loadingMaster && <div role="status" className="badge blue" style={{ display: "block", padding: 10 }}>جاري تحميل العملاء والمواقع...</div>}
      {loadError && <div role="alert" className="badge red" style={{ display: "block", padding: 10, whiteSpace: "normal" }}>{loadError}</div>}

      <div className="form-grid">
        <label>العميل<select aria-label="العميل" value={customerId} onChange={(event) => setCustomerId(event.target.value)} disabled={loadingMaster || saving}>
          <option value="">اختار العميل...</option>
          {customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} — {customer.code}</option>)}
        </select></label>
        <label>موقع استلام المرتجع<select aria-label="موقع استلام المرتجع" value={locationId} onChange={(event) => setLocationId(event.target.value)} disabled={loadingMaster || saving}>
          <option value="">اختار الموقع...</option>
          {locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}
        </select></label>
        <label style={{ gridColumn: "1 / -1" }}>فاتورة البيع الأصلية<select aria-label="فاتورة البيع الأصلية" value={invoiceId} onChange={(event) => setInvoiceId(event.target.value)} disabled={!customerId || loadingInvoices || saving}>
          <option value="">اختار الفاتورة التي تم بيع الصنف من خلالها...</option>
          {invoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.document_number} — {invoice.invoice_date} — {Number(invoice.total).toLocaleString("ar-EG")} EGP</option>)}
        </select></label>
      </div>

      {loadingInvoices && <div role="status" style={{ marginTop: 10, color: "#64748b" }}>جاري تحميل فواتير العميل...</div>}
      {customerId && !loadingInvoices && !invoices.length && <div className="badge amber" style={{ display: "block", marginTop: 10, padding: 10 }}>لا توجد فواتير بيع مسجلة لهذا العميل.</div>}

      {selectedInvoice && <div className="card" style={{ background: "#f8fafc", marginTop: 12, padding: 12 }}>
        <div className="panel-title"><span>بنود {selectedInvoice.document_number}</span><span className="badge blue">القيمة الأصلية: {Number(selectedInvoice.total).toLocaleString("ar-EG")} EGP</span></div>
        {loadingItems && <div role="status">جاري تحميل بنود الفاتورة...</div>}
        {!loadingItems && !lines.length && <div style={{ padding: 12, color: "#64748b" }}>تم إرجاع كل البنود القابلة للإرجاع من هذه الفاتورة.</div>}
        {lines.map((line) => {
          const factor = Number(line.item.conversion_factor_snapshot || 1);
          const remainingEntered = Number(line.item.remaining_quantity_base) / factor;
          return <div key={line.item.id} style={{ display: "grid", gridTemplateColumns: "minmax(0,1fr) 100px 100px", gap: 10, alignItems: "center", padding: "10px 0", borderBottom: "1px solid #e2e8f0" }}>
            <span><strong>{line.item.product_name_snapshot}</strong><small style={{ display: "block", color: "#64748b" }}>{line.item.sku_snapshot} · متبقي للإرجاع {remainingEntered.toLocaleString("ar-EG")}</small></span>
            <label>الكمية<input aria-label={`كمية مرتجع ${line.item.product_name_snapshot}`} type="number" min="0" max={remainingEntered} step="any" value={line.quantity} onChange={(event) => setQuantity(line.item.id, event.target.value)} disabled={saving || loadingItems} /></label>
            <span style={{ textAlign: "left" }}>{(Number(line.quantity || 0) * Number(line.item.unit_price_entered)).toLocaleString("ar-EG")} EGP</span>
          </div>;
        })}
      </div>}

      {selectedInvoice && <div style={{ display: "flex", justifyContent: "space-between", marginTop: 15 }}><span>قيمة المرتجع</span><strong>{total.toLocaleString("ar-EG")} EGP</strong></div>}
      {message && <div role="status" className="badge blue" style={{ display: "block", marginTop: 10, padding: 10, whiteSpace: "normal" }}>{message}</div>}
      <button className="primary" onClick={post} disabled={saving || loadingMaster || loadingItems || !invoiceId || !returnLines.length} style={{ marginTop: 12, display: "flex", justifyContent: "center", gap: 8 }}><PackageCheck size={16} />{saving ? "جاري تسجيل المرتجع..." : "تسجيل المرتجع المرتبط بالفاتورة"}</button>
    </section>
  </main>;
}
