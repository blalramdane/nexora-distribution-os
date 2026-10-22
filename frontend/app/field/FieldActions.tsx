"use client";

import { useEffect, useMemo, useState } from "react";
import { Barcode, CircleDollarSign, RotateCcw, Search, ShoppingCart } from "lucide-react";
import { api, apiWithOfflineQueue } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";

type Packaging = { id: string; unit_id: string; name_ar: string; unit_name: string; conversion_to_base: number | string; barcode_primary?: string | null; is_default_sale_unit: boolean };
type Product = { id: string; sku: string; barcode?: string | null; name_ar: string; name_en?: string | null; base_unit_id: string; base_unit_name?: string | null; quantity_base: number; packagings?: Packaging[] };
type Customer = { id: string; name: string; code: string };
type CustomerProductPrice = { product_id: string; packaging_id: string | null; unit_key: string; unit_price: string | number };
type Trip = { id: string; trip_number: string; vehicle_id: string; vehicle_name: string; vehicle_location_id: string };
type Account = { id: string; code?: string; name: string; currency?: string };
type PaymentMethod = { id: string; code?: string; name_ar: string; requires_reference?: boolean };
type SourceInvoice = { id: string; document_number: string; invoice_date: string; total: string | number; currency: string };
type SourceInvoiceLine = { id: string; product_id: string; entered_unit_name: string; product_name_snapshot: string; quantity_base: string | number; remaining_entered_quantity: number; unit_price_entered: string | number };
type Mode = "sale" | "collection" | "return";

function wholeDigits(value: string): string {
  return value
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

export default function FieldActions({ trip, customer, onDone }: { trip: Trip; customer: Customer; onDone: () => void }) {
  const [mode, setMode] = useState<Mode>("sale");
  const [query, setQuery] = useState("");
  const [catalogMatches, setCatalogMatches] = useState<Product[]>([]);
  const [product, setProduct] = useState<Product | null>(null);
  const [packagingId, setPackagingId] = useState("");
  const [quantity, setQuantity] = useState("1");
  const [price, setPrice] = useState("");
  const [priceManuallyEdited, setPriceManuallyEdited] = useState(false);
  const [customerPrices, setCustomerPrices] = useState<CustomerProductPrice[]>([]);
  const [customerPricesLoaded, setCustomerPricesLoaded] = useState(false);
  const [settlementMode, setSettlementMode] = useState<"cash" | "credit" | "partial">("credit");
  const [paidAmount, setPaidAmount] = useState("");
  const [saleReference, setSaleReference] = useState("");
  const [amount, setAmount] = useState("");
  const [accountId, setAccountId] = useState("");
  const [paymentMethodId, setPaymentMethodId] = useState("");
  const [reference, setReference] = useState("");
  const [refs, setRefs] = useState<{ accounts: Account[]; payment_methods: PaymentMethod[] }>({ accounts: [], payment_methods: [] });
  const [sourceInvoices, setSourceInvoices] = useState<SourceInvoice[]>([]);
  const [sourceInvoiceId, setSourceInvoiceId] = useState("");
  const [sourceLines, setSourceLines] = useState<SourceInvoiceLine[]>([]);
  const [sourceLineId, setSourceLineId] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");

  const selectedPackaging = product?.packagings?.find((item) => item.id === packagingId) ?? null;
  const conversion = Math.max(0.000001, Number(selectedPackaging?.conversion_to_base ?? 1));
  const maxQuantity = product ? Number(product.quantity_base) / conversion : 0;
  const sourceLine = sourceLines.find((line) => line.id === sourceLineId) ?? null;
  const saleTotal = Number(quantity || 0) * Number(price || 0);
  const salePaidAmount = settlementMode === "cash" ? saleTotal : settlementMode === "partial" ? Number(paidAmount || 0) : 0;
  const selectedPaymentMethod = refs.payment_methods.find((item) => item.id === paymentMethodId) ?? null;
  const visibleProducts = useMemo(() => {
    const term = query.trim().toLocaleLowerCase();
    if (term.length < 1) return [];
    return catalogMatches;
  }, [catalogMatches, query]);

  useEffect(() => {
    api<typeof refs>("/field/references")
      .then((result) => {
        setRefs(result);
        setAccountId(result.accounts.find((account) => account.code === "CASH")?.id ?? result.accounts[0]?.id ?? "");
        setPaymentMethodId(result.payment_methods.find((method) => method.code === "cash")?.id ?? result.payment_methods[0]?.id ?? "");
      })
      .catch((error) => setMessage((error as Error).message));
  }, []);

  useEffect(() => {
    let active = true;
    setCustomerPricesLoaded(false);
    setPriceManuallyEdited(false);
    api<CustomerProductPrice[]>(`/field/customer-prices?trip_id=${encodeURIComponent(trip.id)}&customer_id=${encodeURIComponent(customer.id)}`)
      .then((rows) => { if (active) setCustomerPrices(rows); })
      .catch(() => { if (active) setCustomerPrices([]); })
      .finally(() => { if (active) setCustomerPricesLoaded(true); });
    return () => { active = false; };
  }, [customer.id, trip.id]);

  useEffect(() => {
    if (!customerPricesLoaded || !product || priceManuallyEdited) return;
    const unitKey = packagingId || "base";
    const savedPrice = customerPrices.find((row) => row.product_id === product.id && row.unit_key === unitKey);
    if (savedPrice) setPrice(String(Math.round(Number(savedPrice.unit_price))));
  }, [customerPricesLoaded, customerPrices, product?.id, packagingId, priceManuallyEdited]);

  useEffect(() => {
    const term = query.trim();
    if (term.length < 1) { setCatalogMatches([]); return; }
    let active = true;
    const timeout = window.setTimeout(() => {
      api<{ data: Product[] }>(`/field/catalog?trip_id=${encodeURIComponent(trip.id)}&q=${encodeURIComponent(term)}&per_page=50`)
        .then((response) => { if (active) setCatalogMatches(response.data); })
        .catch((error) => { if (active) setMessage((error as Error).message); });
    }, 180);
    return () => { active = false; window.clearTimeout(timeout); };
  }, [query, trip.id]);

  useEffect(() => {
    if (mode === "return") {
      setSourceInvoices([]); setSourceInvoiceId(""); setSourceLines([]); setSourceLineId("");
      api<SourceInvoice[]>(`/field/sales?trip_id=${encodeURIComponent(trip.id)}&customer_id=${encodeURIComponent(customer.id)}`)
        .then((rows) => { setSourceInvoices(rows); setSourceInvoiceId(rows[0]?.id ?? ""); })
        .catch((error) => setMessage((error as Error).message));
    }
  }, [mode, trip.id, customer.id]);

  useEffect(() => {
    if (mode !== "return" || !sourceInvoiceId) { setSourceLines([]); setSourceLineId(""); return; }
    api<SourceInvoiceLine[]>(`/field/sales/${encodeURIComponent(sourceInvoiceId)}/items?trip_id=${encodeURIComponent(trip.id)}&customer_id=${encodeURIComponent(customer.id)}`)
      .then((rows) => { setSourceLines(rows); setSourceLineId(rows[0]?.id ?? ""); setQuantity("1"); })
      .catch((error) => setMessage((error as Error).message));
  }, [mode, sourceInvoiceId, trip.id, customer.id]);

  const chooseProduct = (item: Product) => {
    const defaultPackaging = item.packagings?.find((pack) => pack.is_default_sale_unit) ?? null;
    setProduct(item); setPackagingId(defaultPackaging?.id ?? ""); setQuantity("1");
    setPriceManuallyEdited(false);
    const unitKey = defaultPackaging?.id ?? "base";
    const savedPrice = customerPrices.find((row) => row.product_id === item.id && row.unit_key === unitKey);
    setPrice(savedPrice ? String(Math.round(Number(savedPrice.unit_price))) : ""); setSettlementMode("credit"); setPaidAmount(""); setSaleReference(""); setQuery(""); setCatalogMatches([]); setMessage("");
  };

  const changePackaging = (nextPackagingId: string) => {
    setPackagingId(nextPackagingId);
    setPriceManuallyEdited(false);
    if (!product) { setPrice(""); return; }
    const unitKey = nextPackagingId || "base";
    const savedPrice = customerPrices.find((row) => row.product_id === product.id && row.unit_key === unitKey);
    setPrice(savedPrice ? String(Math.round(Number(savedPrice.unit_price))) : "");
  };

  const submit = async () => {
    setBusy(true); setMessage("");
    try {
      let path = "";
      let operation = "";
      let payload: Record<string, unknown>;
      if (mode === "collection") {
        if (!/^\d+$/.test(amount) || !Number.isSafeInteger(Number(amount)) || Number(amount) <= 0 || !accountId || !paymentMethodId) throw new Error("اختار الحساب وطريقة الدفع واكتب مبلغ التحصيل بالجنيه الصحيح.");
        payload = { party_type: "customer", party_id: customer.id, financial_account_id: accountId, payment_method_id: paymentMethodId, direction: "inbound", amount: Number(amount), trip_id: trip.id, reference: reference.trim() || null };
        path = "/payments"; operation = "field-collection";
      } else if (mode === "return") {
        if (!sourceInvoiceId || !sourceLine) throw new Error("اختار فاتورة البيع الأصلية والصنف المرتجع.");
        if (Number(quantity) <= 0 || Number(quantity) > sourceLine.remaining_entered_quantity) throw new Error("كمية المرتجع أكبر من المتاح في الفاتورة.");
        payload = { customer_id: customer.id, location_id: trip.vehicle_location_id, trip_id: trip.id, original_sales_invoice_id: sourceInvoiceId, items: [{ product_id: sourceLine.product_id, quantity: Number(quantity), unit_price: Number(sourceLine.unit_price_entered), original_sales_invoice_item_id: sourceLine.id }] };
        path = "/returns/sales"; operation = "field-sales-return";
      } else {
        if (!product || !/^\d+$/.test(quantity) || !Number.isSafeInteger(Number(quantity)) || Number(quantity) <= 0) throw new Error("اختار الصنف واكتب كمية صحيحة من غير كسور.");
        if (!/^\d+$/.test(price) || !Number.isSafeInteger(Number(price)) || Number(price) <= 0) throw new Error("اكتب سعر البيع للعميل بالجنيه الصحيح، من غير كسور.");
        if (Number(quantity) * conversion > Number(product.quantity_base) + 0.000001) throw new Error("الكمية المطلوبة أكبر من رصيد العربية المتاح.");
        if (settlementMode === "partial" && (!/^\d+$/.test(paidAmount) || !Number.isSafeInteger(Number(paidAmount)) || Number(paidAmount) <= 0 || Number(paidAmount) >= saleTotal)) {
          throw new Error("في السداد الجزئي، اكتب مبلغًا أكبر من صفر وأقل من إجمالي الفاتورة. للبيع النقدي بالكامل اختار نقدي بالكامل.");
        }
        if (salePaidAmount > 0 && (!accountId || !paymentMethodId)) {
          throw new Error("اختار حساب التحصيل وطريقة الدفع قبل تسجيل المبلغ المدفوع مع الفاتورة.");
        }
        if (salePaidAmount > 0 && selectedPaymentMethod?.requires_reference && !saleReference.trim()) {
          throw new Error("طريقة الدفع المختارة تحتاج رقم مرجع أو إيصال.");
        }
        const enteredUnitId = selectedPackaging?.unit_id ?? product.base_unit_id;
        payload = { customer_id: customer.id, location_id: trip.vehicle_location_id, trip_id: trip.id, paid_amount: salePaidAmount, ...(salePaidAmount > 0 ? { financial_account_id: accountId, payment_method_id: paymentMethodId, payment_reference: saleReference.trim() || undefined } : {}), items: [{ product_id: product.id, quantity: Number(quantity), unit_price: Number(price), unit_id: enteredUnitId, ...(selectedPackaging ? { packaging_id: selectedPackaging.id } : {}) }] };
        path = "/sales"; operation = "field-sale";
      }

      const key = await getStableIdempotencyKey(operation, payload);
      const result = await apiWithOfflineQueue<{ queued?: boolean; id?: string; amount?: number | string; category?: string }>(path, { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      if (!result.queued) clearStableIdempotencyKey(operation, key);
      if (result.queued) {
        setMessage("العملية اتحفظت على الجهاز وهتتزامن عند عودة الإنترنت.");
      } else if (mode === "collection") {
        setMessage("تم تسجيل التحصيل على حساب العميل."); setAmount(""); setReference("");
      } else if (mode === "return") {
        setMessage("تم تسجيل المرتجع وربطه بفاتورة البيع.");
      } else {
        setMessage("تم تسجيل البيع وتحديث رصيد العربية والعميل."); setProduct(null); setPrice(""); setQuantity("1"); setPackagingId(""); setSettlementMode("credit"); setPaidAmount(""); setSaleReference("");
      }
      onDone();
    } catch (error) { setMessage((error as Error).message); }
    finally { setBusy(false); }
  };

  return <section className="field-card field-operation-card">
    <div className="field-card-title"><span>عملية العميل</span><span className="badge blue">{customer.name}</span></div>
    <div className="field-action-tabs field-action-tabs-wide">
      <button className={mode === "sale" ? "active" : ""} onClick={() => setMode("sale")}><ShoppingCart size={16}/> بيع</button>
      <button className={mode === "collection" ? "active" : ""} onClick={() => setMode("collection")}><CircleDollarSign size={16}/> تحصيل</button>
      <button className={mode === "return" ? "active" : ""} onClick={() => setMode("return")}><RotateCcw size={16}/> مرتجع</button>
    </div>

    {mode === "sale" && <div className="field-form-stack">
      <label className="action-field"><span>ابحث عن الصنف بالاسم أو الكود أو الباركود</span><div className="action-search"><Search size={17}/><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="مثال: غلاية، FAN-18، أو امسح الباركود" aria-label="بحث صنف البيع" autoComplete="off" onKeyDown={(event) => { if (event.key === "Enter" && visibleProducts[0]) { event.preventDefault(); chooseProduct(visibleProducts[0]); } }}/></div></label>
      {query.trim() && <div className="field-catalog-results">{visibleProducts.map((item) => <button type="button" key={item.id} className="field-catalog-result" onClick={() => chooseProduct(item)}><span className="field-product-mark"><Barcode size={18}/></span><span className="field-product-copy"><strong>{item.name_ar}</strong><small>{item.sku}{item.barcode ? ` · ${item.barcode}` : ""}</small><small>المتاح: {Number(item.quantity_base).toLocaleString("ar-EG")} {item.base_unit_name ?? "وحدة"}</small></span><strong className="field-product-price">سعر خاص بالعميل</strong></button>)}{!visibleProducts.length && <p className="field-empty-hint">مفيش صنف مطابق في مخزون العربية. راجع الكود أو الباركود.</p>}</div>}
      {product && <div className="field-selected-product"><div className="field-selected-product-heading"><div><strong>{product.name_ar}</strong><small>{product.sku} · المتاح {Number(product.quantity_base).toLocaleString("ar-EG")} {product.base_unit_name ?? "وحدة"}</small></div><button className="secondary" onClick={() => { setProduct(null); setPrice(""); setPackagingId(""); }}>تغيير الصنف</button></div>
        <div className="field-entry-grid">
          <label className="action-field">وحدة البيع<select aria-label="وحدة البيع" value={packagingId} onChange={(event) => changePackaging(event.target.value)}><option value="">{product.base_unit_name ?? "الوحدة الأساسية"}</option>{product.packagings?.map((pack) => <option key={pack.id} value={pack.id}>{pack.name_ar} ({pack.unit_name}) · {Number(pack.conversion_to_base).toLocaleString("ar-EG")} {product.base_unit_name ?? "وحدة"}</option>)}</select></label>
          <label className="action-field">الكمية<input className="whole-number-input" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" aria-label="كمية البيع" value={quantity} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) setQuantity(value); }} placeholder="مثال: 2"/><small>المتاح بهذه الوحدة: {Math.floor(maxQuantity).toLocaleString("ar-EG")}</small></label>
          <label className="action-field field-sale-price">سعر البيع للعميل (جنيه)<input className="whole-number-input" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" aria-label="سعر البيع للعميل" value={price} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) { setPrice(value); setPriceManuallyEdited(true); } }} placeholder={customerPricesLoaded ? "اكتب السعر المتفق عليه" : "جاري تحميل سعر العميل..."}/><small>{customerPricesLoaded && customerPrices.some((row) => row.product_id === product.id && row.unit_key === (packagingId || "base")) ? "تم ملء السعر المحفوظ لهذا العميل والوحدة؛ تقدر تراجعه وتعدله قبل الفاتورة." : "لا يوجد سعر محفوظ لهذه الوحدة عند العميل؛ أدخل السعر المتفق عليه. تكلفة الشراء غير معروضة للمندوب."}</small></label>
          <label className="action-field">طريقة السداد<select aria-label="طريقة السداد" value={settlementMode} onChange={(event) => { setSettlementMode(event.target.value as "cash" | "credit" | "partial"); setPaidAmount(""); }}><option value="credit">آجل</option><option value="cash">نقدي بالكامل</option><option value="partial">سداد جزئي</option></select></label>
          {settlementMode === "partial" && <label className="action-field">المبلغ المدفوع وقت البيع (جنيه)<input className="whole-number-input" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" aria-label="المبلغ المدفوع وقت البيع" value={paidAmount} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) setPaidAmount(value); }} placeholder="المبلغ المستلم الآن"/></label>}
        </div>
        {salePaidAmount > 0 && <div className="field-entry-grid field-payment-details">
          <label className="action-field">حساب استلام النقدية<select aria-label="حساب استلام النقدية" value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">اختار حساب التحصيل</option>{refs.accounts.map((item) => <option key={item.id} value={item.id}>{item.name} {item.currency ? `· ${item.currency}` : ""}</option>)}</select></label>
          <label className="action-field">طريقة الدفع<select aria-label="طريقة دفع الفاتورة" value={paymentMethodId} onChange={(event) => { setPaymentMethodId(event.target.value); setSaleReference(""); }}><option value="">اختار طريقة الدفع</option>{refs.payment_methods.map((item) => <option key={item.id} value={item.id}>{item.name_ar}</option>)}</select></label>
          {selectedPaymentMethod?.requires_reference && <label className="action-field">رقم مرجع التحصيل<input value={saleReference} onChange={(event) => setSaleReference(event.target.value)} maxLength={255} placeholder="رقم إيصال أو تحويل أو شيك" aria-label="مرجع تحصيل الفاتورة"/></label>}
          <small className="field-inline-note">المبلغ المدفوع سيتسجل في حساب التحصيل وسند مرتبط بفاتورة العميل، وليس كرقم مدفوع فقط.</small>
        </div>}
        <div className="field-line-total"><span>إجمالي الفاتورة · المتبقي بعد السداد</span><strong>{saleTotal.toLocaleString("ar-EG", { maximumFractionDigits: 0 })} · {Math.max(0, saleTotal - salePaidAmount).toLocaleString("ar-EG", { maximumFractionDigits: 0 })} ج.م</strong></div>
      </div>}
    </div>}

    {mode === "collection" && <div className="field-form-stack">
      <label className="action-field">الحساب المالي<select value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">اختار حساب التحصيل</option>{refs.accounts.map((item) => <option key={item.id} value={item.id}>{item.name} {item.currency ? `· ${item.currency}` : ""}</option>)}</select></label>
      <label className="action-field">طريقة الدفع<select value={paymentMethodId} onChange={(event) => setPaymentMethodId(event.target.value)}><option value="">اختار طريقة الدفع</option>{refs.payment_methods.map((item) => <option key={item.id} value={item.id}>{item.name_ar}</option>)}</select></label>
      <label className="action-field">المبلغ المحصّل بالجنيه<input className="whole-number-input" inputMode="numeric" pattern="[0-9]*" type="text" autoComplete="off" value={amount} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) setAmount(value); }} placeholder="مثال: 1500"/></label>
      <label className="action-field">رقم الإيصال / ملاحظة (اختياري)<input value={reference} onChange={(event) => setReference(event.target.value)} placeholder="رقم إيصال أو ملاحظة قصيرة"/></label>
    </div>}

    {mode === "return" && <div className="field-form-stack">
      <label className="action-field">فاتورة البيع الأصلية<select value={sourceInvoiceId} onChange={(event) => setSourceInvoiceId(event.target.value)}><option value="">اختار فاتورة...</option>{sourceInvoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.document_number} · {new Date(invoice.invoice_date).toLocaleDateString("ar-EG")} · {Number(invoice.total).toLocaleString("ar-EG")} {invoice.currency}</option>)}</select></label>
      {!sourceInvoices.length && <p className="field-empty-hint">مفيش فواتير بيع مسجلة للعميل ده في الرحلة الحالية.</p>}
      <label className="action-field">الصنف من الفاتورة<select value={sourceLineId} onChange={(event) => { setSourceLineId(event.target.value); setQuantity("1"); }}><option value="">اختار الصنف...</option>{sourceLines.map((line) => <option key={line.id} value={line.id}>{line.product_name_snapshot} · متبقي {Number(line.remaining_entered_quantity).toLocaleString("ar-EG")} {line.entered_unit_name}</option>)}</select></label>
      {sourceLine && <><p className="field-inline-note">المتاح للإرجاع: {Number(sourceLine.remaining_entered_quantity).toLocaleString("ar-EG")} {sourceLine.entered_unit_name}. بيانات الوحدة والسعر مأخوذة من الفاتورة الأصلية.</p><label className="action-field">كمية المرتجع<input className="whole-number-input" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" value={quantity} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) setQuantity(value); }}/></label></>}
    </div>}

    {message && <div role="status" aria-live="polite" className="field-toast">{message}</div>}
    <button className="primary field-submit-button" disabled={busy} onClick={submit}>{busy ? "جاري الحفظ..." : mode === "sale" ? "تسجيل البيع" : mode === "collection" ? "تسجيل التحصيل" : "تسجيل المرتجع"}</button>
  </section>;
}
