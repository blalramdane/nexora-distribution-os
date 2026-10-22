"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { Banknote, Check, ChevronLeft, ChevronRight, CircleDollarSign, CreditCard, Minus, Package, Plus, Search, Smartphone, ShoppingCart, Trash2, UserRound, Wallet, X } from "lucide-react";
import { api } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";

type Customer = { id: string; code: string; name: string; phone?: string; alternate_phone?: string; credit_limit?: number; credit_enabled?: boolean; payment_terms_days?: number };
type Location = { id: string; name: string; code: string; type?: string };
type Trip = { id: string; trip_number: string; vehicle_name: string; status: string; vehicle_location_id: string };
type Category = { id: string; name_ar: string; code: string };
type Packaging = { id: string; product_id: string; unit_id: string; name_ar: string; unit_name: string; conversion_to_base: number | string; sale_price?: number | string | null; purchase_price?: number | string | null; is_default_sale_unit: boolean; is_default_purchase_unit: boolean };
type Product = { id: string; sku: string; name_ar: string; name_en?: string; brand?: string; color?: string | null; base_unit_id: string; base_unit_name?: string; category_id?: string | null; category_name?: string | null; default_piece_price: number | string; quantity_base: number | string; packagings: Packaging[] };
type PosCatalog = { categories: Category[]; products: { data: Product[]; current_page: number; last_page: number; total: number; per_page: number } };
type FinancialAccount = { id: string; code: string; name: string; type?: string; currency?: string; active?: boolean };
type PaymentMethod = { id: string; code: string; name_ar: string; requires_reference: boolean; active?: boolean };
type CustomerPrice = { id: string; product_id: string; packaging_id: string | null; unit_key: string; unit_price: number | string };
type CustomerPriceState = { customerId: string; rows: CustomerPrice[] };
const EMPTY_CUSTOMER_PRICES: CustomerPrice[] = [];
type Line = { product: Product; packaging: Packaging | null; quantity: number; unit_price: number };
const money = (value: number) => `${value.toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ج.م`;

function whatsappUrl(phone?: string) {
  let digits = (phone ?? "").replace(/\D/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  if (digits.startsWith("0")) digits = `20${digits.slice(1)}`;
  return digits ? `https://wa.me/${digits}` : "";
}

function salePackaging(product: Product) {
  return product.packagings?.find((item) => item.is_default_sale_unit)
    ?? product.packagings?.find((item) => Number(item.conversion_to_base) === 1)
    ?? null;
}

function salePrice(product: Product, packaging: Packaging | null) {
  const entered = packaging?.sale_price;
  if (entered !== null && entered !== undefined && Number.isFinite(Number(entered))) return Number(entered);
  return (Number(product.default_piece_price) || 0) * (Number(packaging?.conversion_to_base) || 1);
}

function savedPriceForCustomer(prices: CustomerPrice[], product: Product, packaging: Packaging | null): number | null {
  const exact = prices.find((row) => row.product_id === product.id && row.packaging_id === (packaging?.id ?? null));
  if (exact) return Number(exact.unit_price);
  if (packaging) {
    const base = prices.find((row) => row.product_id === product.id && row.packaging_id === null);
    if (base) return Number(base.unit_price) * (Number(packaging.conversion_to_base) || 1);
  }
  return null;
}

function customerSalePrice(prices: CustomerPrice[], product: Product, packaging: Packaging | null) {
  return savedPriceForCustomer(prices, product, packaging) ?? salePrice(product, packaging);
}

function baseQuantity(line: Line) {
  return line.quantity * (Number(line.packaging?.conversion_to_base) || 1);
}

export default function Sales() {
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [customerQuery, setCustomerQuery] = useState("");
  const [customerResults, setCustomerResults] = useState<Customer[]>([]);
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [customerPriceState, setCustomerPriceState] = useState<CustomerPriceState | null>(null);
  const [customerPriceNotice, setCustomerPriceNotice] = useState("");
  const [locations, setLocations] = useState<Location[]>([]);
  const [locationId, setLocationId] = useState("");
  const [trips, setTrips] = useState<Trip[]>([]);
  const [tripId, setTripId] = useState("");
  const [categories, setCategories] = useState<Category[]>([]);
  const [categoryId, setCategoryId] = useState("");
  const [products, setProducts] = useState<Product[]>([]);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [catalogTotal, setCatalogTotal] = useState(0);
  const [lines, setLines] = useState<Line[]>([]);
  const [discount, setDiscount] = useState("0");
  const [paid, setPaid] = useState("0");
  const [financialAccounts, setFinancialAccounts] = useState<FinancialAccount[]>([]);
  const [paymentMethods, setPaymentMethods] = useState<PaymentMethod[]>([]);
  const [financialAccountId, setFinancialAccountId] = useState("");
  const [paymentMethodId, setPaymentMethodId] = useState("");
  const [paymentReference, setPaymentReference] = useState("");
  const [paymentRefsError, setPaymentRefsError] = useState("");
  const [loading, setLoading] = useState(true);
  const [loadingError, setLoadingError] = useState("");
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");
  const [messageError, setMessageError] = useState(false);
  const [savedInvoiceId, setSavedInvoiceId] = useState("");

  useEffect(() => {
    Promise.all([api<Location[]>("/locations"), api<Trip[]>("/trips")])
      .then(([nextLocations, nextTrips]) => {
        setLocations(nextLocations);
        setTrips(nextTrips);
        const initial = nextLocations.find((item) => item.type === "warehouse") ?? nextLocations[0];
        if (initial) setLocationId(initial.id);
      })
      .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر تحميل مواقع المخزون."));
  }, []);

  useEffect(() => {
    api<{ accounts: FinancialAccount[]; payment_methods: PaymentMethod[] }>("/sales/payment-references")
      .then((result) => {
        setFinancialAccounts(result.accounts);
        setPaymentMethods(result.payment_methods);
        // Never silently assign a receipt to a cashbox or wallet. The field rep
        // must explicitly choose both the payment method and destination account.
        setFinancialAccountId("");
        setPaymentMethodId("");
        setPaymentRefsError("");
      })
      .catch((error: unknown) => setPaymentRefsError(error instanceof Error ? error.message : "تعذر تحميل حسابات التحصيل وطرق الدفع."));
  }, []);

  useEffect(() => {
    const term = customerQuery.trim();
    if (term.length < 2) {
      setCustomerResults([]);
      return;
    }
    const timer = setTimeout(() => {
      api<Customer[]>(`/customers?q=${encodeURIComponent(term)}`)
        .then(setCustomerResults)
        .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر البحث عن العميل."));
    }, 200);
    return () => clearTimeout(timer);
  }, [customerQuery]);

  useEffect(() => {
    if (!locationId) return;
    const timer = setTimeout(() => {
      setLoading(true);
      const params = new URLSearchParams({ location_id: locationId, per_page: "60", page: String(page) });
      if (search.trim()) params.set("q", search.trim());
      if (categoryId) params.set("category_id", categoryId);
      api<PosCatalog>(`/catalog/pos?${params.toString()}`)
        .then((result) => {
          setCategories(result.categories);
          setProducts(result.products.data);
          setLastPage(result.products.last_page);
          setCatalogTotal(result.products.total);
          setLoadingError("");
        })
        .catch((error: unknown) => setLoadingError(error instanceof Error ? error.message : "تعذر تحميل كتالوج المنتجات."))
        .finally(() => setLoading(false));
    }, search.trim() ? 180 : 0);
    return () => clearTimeout(timer);
  }, [locationId, categoryId, search, page]);

  const subtotal = useMemo(() => lines.reduce((sum, line) => sum + line.quantity * line.unit_price, 0), [lines]);
  const discountValue = Math.max(0, Math.min(subtotal, Number(discount) || 0));
  const total = Math.max(0, subtotal - discountValue);
  const paidAmount = Math.max(0, Math.min(total, Number(paid) || 0));
  const balanceDue = Math.max(0, total - paidAmount);
  const selectedLocation = locations.find((item) => item.id === locationId);
  const selectedPaymentMethod = paymentMethods.find((method) => method.id === paymentMethodId) ?? null;
  const selectedFinancialAccount = financialAccounts.find((account) => account.id === financialAccountId) ?? null;
  const customerPrices = customer && customerPriceState?.customerId === customer.id ? customerPriceState.rows : EMPTY_CUSTOMER_PRICES;
  const filteredProducts = products;

  useEffect(() => {
    if (!customer) {
      setCustomerPriceState(null);
      setCustomerPriceNotice("");
      setLines((current) => {
        let changed = false;
        const next = current.map((line) => {
          const nextPrice = salePrice(line.product, line.packaging);
          if (nextPrice === line.unit_price) return line;
          changed = true;
          return { ...line, unit_price: nextPrice };
        });
        return changed ? next : current;
      });
      return;
    }
    const selectedCustomerId = customer.id;
    let cancelled = false;
    setCustomerPriceState(null);
    setCustomerPriceNotice("جاري تحميل قائمة الأسعار الخاصة بالعميل...");
    api<CustomerPrice[]>("/customers/" + encodeURIComponent(selectedCustomerId) + "/prices")
      .then((rows) => {
        if (cancelled) return;
        setCustomerPriceState({ customerId: selectedCustomerId, rows });
        setCustomerPriceNotice(rows.length ? "تم تحميل " + rows.length.toLocaleString("ar-EG") + " سعر محفوظ للعميل؛ الأسعار المحفوظة تقترح تلقائيًا في الفاتورة." : "لا توجد أسعار محفوظة لهذا العميل؛ سيُستخدم السعر الافتراضي ويمكن مراجعته في الفاتورة.");
        setLines((current) => current.map((line) => ({ ...line, unit_price: customerSalePrice(rows, line.product, line.packaging) })));
      })
      .catch(() => {
        if (cancelled) return;
        setCustomerPriceState({ customerId: selectedCustomerId, rows: [] });
        setCustomerPriceNotice("تعذر تحميل السعر الخاص؛ تم الرجوع للسعر الافتراضي، راجع السعر قبل الإصدار.");
        setLines((current) => current.map((line) => ({ ...line, unit_price: salePrice(line.product, line.packaging) })));
      });
    return () => { cancelled = true; };
  }, [customer?.id]);

  useEffect(() => {
    if (!customer || customerPriceState?.customerId !== customer.id) return;
    setLines((current) => {
      let changed = false;
      const next = current.map((line) => {
        const nextPrice = customerSalePrice(customerPrices, line.product, line.packaging);
        if (nextPrice === line.unit_price) return line;
        changed = true;
        return { ...line, unit_price: nextPrice };
      });
      return changed ? next : current;
    });
  }, [customer?.id, customerPriceState?.customerId, customerPrices, lines]);

  function addProduct(product: Product) {
    const stock = Number(product.quantity_base) || 0;
    setLines((current) => {
      const existing = current.find((line) => line.product.id === product.id);
      if (existing) {
        if (baseQuantity(existing) + (Number(existing.packaging?.conversion_to_base) || 1) > stock) {
          setMessage(`الكمية المتاحة من «${product.name_ar}» في ${selectedLocation?.name ?? "الموقع المحدد"} لا تكفي.`);
          setMessageError(true);
          return current;
        }
        return current.map((line) => line.product.id === product.id ? { ...line, quantity: line.quantity + 1 } : line);
      }
      if (stock <= 0) {
        setMessage(`الصنف «${product.name_ar}» رصيده صفر في الموقع المحدد. راجع المخزون أو اختر موقعًا آخر.`);
        setMessageError(true);
        return current;
      }
      setMessage("");
      setMessageError(false);
      const packaging = salePackaging(product);
      const conversion = Number(packaging?.conversion_to_base) || 1;
      if (stock < conversion) {
        setMessage(`رصيد «${product.name_ar}» لا يكفي لوحدة البيع ${packaging?.name_ar || product.base_unit_name || "الأساسية"}.`);
        setMessageError(true);
        return current;
      }
      return [...current, { product, packaging, quantity: 1, unit_price: customerSalePrice(customerPrices, product, packaging) }];
    });
  }

  function changeQuantity(productId: string, quantity: number) {
    const line = lines.find((item) => item.product.id === productId);
    if (!line) return;
    const stock = Number(line.product.quantity_base) || 0;
    const conversion = Number(line.packaging?.conversion_to_base) || 1;
    if (quantity * conversion > stock) {
      setMessage(`الكمية المطلوبة أكبر من الرصيد المتاح (${stock.toLocaleString("ar-EG")} ${line.product.base_unit_name || "وحدة أساسية"}).`);
      setMessageError(true);
      return;
    }
    setMessage("");
    setLines((current) => quantity <= 0 ? current.filter((item) => item.product.id !== productId) : current.map((item) => item.product.id === productId ? { ...item, quantity } : item));
  }

  function selectTrip(value: string) {
    setTripId(value);
    const trip = trips.find((item) => item.id === value);
    if (trip) setLocationId(trip.vehicle_location_id);
    else {
      const warehouse = locations.find((item) => item.type === "warehouse");
      if (warehouse) setLocationId(warehouse.id);
    }
  }

  async function postSale() {
    setMessage("");
    setMessageError(true);
    setSavedInvoiceId("");
    if (loadingError) return setMessage("تعذر تحميل بيانات الشاشة. حدّث الصفحة أولًا.");
    if (!customer) return setMessage("اختار العميل أو ابحث عنه برقم الهاتف أو الاسم.");
    if (!locationId || !lines.length) return setMessage("أضف صنفًا واحدًا على الأقل إلى الفاتورة.");
    if (lines.some((line) => !Number.isFinite(line.quantity) || line.quantity <= 0 || !Number.isFinite(line.unit_price) || line.unit_price < 0)) return setMessage("راجع الكميات والأسعار قبل التسجيل.");
    if (lines.some((line) => line.quantity > Number(line.product.quantity_base))) return setMessage("يوجد صنف كميته المطلوبة أكبر من رصيد الموقع.");
    if (total <= 0) return setMessage("إجمالي الفاتورة يجب أن يكون أكبر من صفر.");
    if (paidAmount > 0 && (!financialAccountId || !paymentMethodId)) return setMessage(paymentRefsError || "اختار حساب التحصيل وطريقة الدفع للمبلغ المدفوع.");
    if (paidAmount > 0 && selectedPaymentMethod?.requires_reference && !paymentReference.trim()) return setMessage("طريقة الدفع المختارة تحتاج رقم مرجع أو إيصال.");
    setSaving(true);
    try {
      const payload = {
        customer_id: customer.id,
        location_id: locationId,
        trip_id: tripId || undefined,
        discount: discountValue,
        paid_amount: paidAmount,
        ...(paidAmount > 0 ? { financial_account_id: financialAccountId, payment_method_id: paymentMethodId, payment_reference: paymentReference.trim() || undefined } : {}),
        items: lines.map((line) => ({ product_id: line.product.id, quantity: line.quantity, unit_price: line.unit_price, packaging_id: line.packaging?.id, unit_id: line.packaging?.unit_id ?? line.product.base_unit_id })),
      };
      const key = await getStableIdempotencyKey("sales", payload);
      const result = await api<{ id: string; document_number: string; total: string; balance_due: string }>("/sales", { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      if (!result.id) {
        setMessage("لم يصل رقم الفاتورة من الخادم. افتح سجل الفواتير للتحقق قبل تكرار البيع.");
        setMessageError(false);
        return;
      }
      clearStableIdempotencyKey("sales", key);
      setSavedInvoiceId(result.id);
      setMessage(`تم تسجيل الفاتورة ${result.document_number} بنجاح — الإجمالي ${money(Number(result.total))} — المتبقي ${money(Number(result.balance_due))}.`);
      setMessageError(false);
      setLines([]);
      setDiscount("0");
      setPaid("0");
      setPaymentReference("");
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "تعذر تسجيل البيع. راجع حالة الاتصال والفاتورة قبل إعادة المحاولة.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="main pos-page">
      <div className="topbar pos-header">
        <div>
          <h1 className="title">نقطة البيع</h1>
          <div className="subtitle">اختار العميل، اضغط على المنتج لإضافته، وبعدها سجّل البيع في خطوة واحدة.</div>
        </div>
        <div className="pos-context">
          <label>مصدر المخزون
            <select aria-label="مصدر المخزون" value={locationId} onChange={(event) => { setTripId(""); setLocationId(event.target.value); }}>
              {locations.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
            </select>
          </label>
          <label>رحلة توزيع (اختياري)
            <select aria-label="رحلة توزيع" value={tripId} onChange={(event) => selectTrip(event.target.value)}>
              <option value="">بيع من المخزن</option>
              {trips.filter((trip) => !["completed", "cancelled"].includes(trip.status)).map((trip) => <option key={trip.id} value={trip.id}>{trip.trip_number} — {trip.vehicle_name}</option>)}
            </select>
          </label>
        </div>
      </div>

      {loadingError && <div className="pos-alert error" role="alert">{loadingError}</div>}
      <div className="pos-layout">
        <section className="card pos-catalog-panel">
          <div className="pos-catalog-head">
            <div>
              <h2>المنتجات</h2>
              <span>{catalogTotal.toLocaleString("ar-EG")} صنف مطابق</span>
            </div>
            <label className="pos-search"><Search size={17}/><input value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="اكتب اسم الصنف أو الكود أو الباركود..." aria-label="بحث المنتجات"/><kbd>بحث</kbd></label>
          </div>
          <div className="pos-category-strip" aria-label="تصنيفات المنتجات">
            <button type="button" className={!categoryId ? "selected" : ""} onClick={() => { setCategoryId(""); setPage(1); }}>كل المنتجات</button>
            {categories.map((category) => <button type="button" key={category.id} className={categoryId === category.id ? "selected" : ""} onClick={() => { setCategoryId(category.id); setPage(1); }}>{category.name_ar}</button>)}
          </div>

          {loading ? <div className="pos-empty"><span className="spinner"/> جاري تحميل المنتجات...</div> : filteredProducts.length ? (
            <div className="pos-product-grid">
              {filteredProducts.map((product) => {
                const stock = Number(product.quantity_base) || 0;
                const inCart = lines.find((line) => line.product.id === product.id)?.quantity ?? 0;
                const displayPackaging = salePackaging(product);
                const savedCustomerPrice = savedPriceForCustomer(customerPrices, product, displayPackaging);
                return <button type="button" key={product.id} className={`pos-product-card ${stock <= 0 ? "out-of-stock" : ""}`} disabled={stock <= 0} onClick={() => addProduct(product)} title={stock <= 0 ? "لا يوجد رصيد في الموقع المحدد" : `إضافة ${product.name_ar}`}>
                  <span className="pos-product-symbol"><Package size={24}/>{inCart > 0 && <b>{inCart.toLocaleString("ar-EG")}</b>}</span>
                  <span className="pos-product-category">{product.category_name || "بدون تصنيف"}</span>
                  <strong className="pos-product-name">{product.name_ar}</strong>
                  <span className="pos-product-sku">{product.sku}{product.brand ? ` · ${product.brand}` : ""}{product.color ? ` · ${product.color}` : ""}</span>
                  <span className="pos-product-bottom"><b>{money(customerSalePrice(customerPrices, product, displayPackaging))}</b>{savedCustomerPrice !== null && <small className="badge green">سعر محفوظ للعميل</small>}<small>سعر {displayPackaging?.name_ar || product.base_unit_name || "القطعة"}</small><small className={stock <= 3 ? "stock-low" : ""}>المتاح {stock.toLocaleString("ar-EG", { maximumFractionDigits: 3 })} {product.base_unit_name || "وحدة"}</small>{product.packagings?.length > 0 && <small className="pos-unit-hint">متاح بالقطعة أو {product.packagings.map((item) => item.name_ar).join(" / ")}</small>}</span>
                  <span className="pos-add"><Plus size={15}/> اختار الوحدة من الفاتورة</span>
                </button>;
              })}
            </div>
          ) : <div className="pos-empty"><Package size={32}/><strong>مفيش منتجات مطابقة</strong><span>جرّب تصنيفًا آخر أو غيّر كلمة البحث أو راجع رصيد الموقع.</span></div>}

          <div className="pos-pagination">
            <span>صفحة {page.toLocaleString("ar-EG")} من {Math.max(1, lastPage).toLocaleString("ar-EG")}</span>
            <div><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))}><ChevronRight size={16}/> السابق</button><button type="button" disabled={page >= lastPage || loading} onClick={() => setPage((current) => Math.min(lastPage, current + 1))}>التالي <ChevronLeft size={16}/></button></div>
          </div>
        </section>

        <aside className="card pos-checkout">
          <div className="pos-cart-heading"><span className="pos-cart-icon"><ShoppingCart size={20}/></span><div><h2>فاتورة البيع</h2><span>{lines.length.toLocaleString("ar-EG")} أصناف · {lines.reduce((sum, line) => sum + line.quantity, 0).toLocaleString("ar-EG")} وحدة</span></div><button type="button" className="icon-btn" disabled={!lines.length} onClick={() => setLines([])} title="تفريغ الفاتورة"><Trash2 size={15}/></button></div>

          <div className="pos-customer-block">
            <label className="pos-label" htmlFor="pos-customer-search"><UserRound size={15}/> العميل</label>
            {customer ? (
              <div className="pos-selected-customer">
                <span className="pos-customer-avatar"><UserRound size={18}/></span>
                <div className="pos-customer-data">
                  <strong>{customer.name}</strong>
                  <small>{customer.phone || "لا يوجد رقم هاتف"} · {customer.code}</small>
                  <div className="pos-contact-links">
                    {customer.phone && <><a href={`tel:${customer.phone}`}>اتصال فوري</a>{whatsappUrl(customer.phone) && <a href={whatsappUrl(customer.phone)} target="_blank" rel="noreferrer">واتساب</a>}</>}
                    <Link href={`/customers/${customer.id}`}>ملف العميل</Link>
                  </div>
                  <small>طريقة السداد حسب الفاتورة: نقدي بالكامل، دفع جزئي، أو آجل بالكامل.</small>
                </div>
                <button type="button" className="icon-btn" onClick={() => { setCustomer(null); setCustomerQuery(""); }} title="تغيير العميل"><X size={15}/></button>
              </div>
            ) : (
              <div className="pos-customer-search-wrap">
                <Search size={15}/>
                <input id="pos-customer-search" value={customerQuery} onChange={(event) => setCustomerQuery(event.target.value)} placeholder="اسم العميل أو رقمه..." autoComplete="off"/>
                {customerQuery && <button type="button" onClick={() => setCustomerQuery("")} aria-label="مسح البحث"><X size={14}/></button>}
                {customerQuery.trim().length >= 2 && <div className="pos-customer-results">{customerResults.length ? customerResults.slice(0, 8).map((item) => <button type="button" key={item.id} onClick={() => { setCustomer(item); setCustomerQuery(""); setCustomerResults([]); }}><span><strong>{item.name}</strong><small>{item.phone || "بدون هاتف"}</small></span><small>{item.code}</small></button>) : <p>لا توجد نتائج مطابقة.</p>}</div>}
              </div>
            )}
            {!customer && <small className="pos-hint">ابحث برقم الهاتف أو اسم العميل، من غير قائمة طويلة مشتتة.</small>}
            {customer && customerPriceNotice && <small className="pos-hint" role="status">{customerPriceNotice}</small>}
          </div>

          <div className="pos-cart-lines">
            {lines.map((line) => <div className="pos-cart-line" key={line.product.id}>
              <div className="pos-cart-line-main"><strong>{line.product.name_ar}{line.product.color ? ` — ${line.product.color}` : ""}</strong><small>{line.product.sku} · الرصيد {Number(line.product.quantity_base).toLocaleString("ar-EG")} {line.product.base_unit_name || "قطعة"}</small><div className="pos-unit-picker" role="group" aria-label={`اختيار وحدة ${line.product.name_ar}`}><button type="button" className={!line.packaging ? "active" : ""} onClick={() => { const nextLine = { ...line, packaging: null, unit_price: customerSalePrice(customerPrices, line.product, null) }; if (baseQuantity(nextLine) > Number(line.product.quantity_base)) { setMessage("الرصيد لا يكفي للوحدة المختارة."); setMessageError(true); return; } setLines((current) => current.map((item) => item.product.id === line.product.id ? nextLine : item)); }}>قطعة ({line.product.base_unit_name || "وحدة"})</button>{line.product.packagings.map((item) => <button type="button" key={item.id} className={line.packaging?.id === item.id ? "active" : ""} onClick={() => { const nextLine = { ...line, packaging: item, unit_price: customerSalePrice(customerPrices, line.product, item) }; if (baseQuantity(nextLine) > Number(line.product.quantity_base)) { setMessage(`الرصيد لا يكفي لوحدة ${item.name_ar}.`); setMessageError(true); return; } setLines((current) => current.map((row) => row.product.id === line.product.id ? nextLine : row)); }}>{item.name_ar} (×{Number(item.conversion_to_base).toLocaleString("ar-EG")})</button>)}</div><label className="pos-unit-price">سعر الوحدة <input type="number" min="0" step="0.01" value={line.unit_price} onChange={(event) => { const value = Number(event.target.value); setLines((current) => current.map((row) => row.product.id === line.product.id ? { ...row, unit_price: value } : row)); }} /></label><b>{money(line.quantity * line.unit_price)}</b></div>
              <div className="pos-line-controls"><button type="button" aria-label={`تقليل ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, line.quantity - 1)}><Minus size={13}/></button><input type="number" min="1" max={Math.floor((Number(line.product.quantity_base) || 0) / (Number(line.packaging?.conversion_to_base) || 1))} step="any" aria-label={`كمية ${line.product.name_ar}`} value={line.quantity} onChange={(event) => changeQuantity(line.product.id, Number(event.target.value))}/><button type="button" aria-label={`زيادة ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, line.quantity + 1)}><Plus size={13}/></button><button type="button" className="pos-remove-line" aria-label={`حذف ${line.product.name_ar}`} onClick={() => changeQuantity(line.product.id, 0)}><Trash2 size={14}/></button></div>
            </div>)}
            {!lines.length && <div className="pos-cart-empty"><ShoppingCart size={29}/><strong>الفاتورة لسه فاضية</strong><span>اضغط على أي منتج من شبكة الكاشير لإضافته هنا.</span></div>}
          </div>

          <div className="pos-discount-row"><label htmlFor="pos-discount">الخصم (ج.م)</label><input id="pos-discount" type="number" min="0" max={subtotal} step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} disabled={!lines.length}/><button type="button" onClick={() => setDiscount("0")} disabled={!Number(discount)}>إلغاء الخصم</button></div>
          <div className="pos-payment-label"><span><CreditCard size={16}/> المدفوع الآن</span><span>الباقي على العميل يظهر آليًا</span></div>
          <div className="pos-payment-quick"><button type="button" onClick={() => setPaid(String(total))} disabled={!lines.length}>نقدي بالكامل</button><button type="button" onClick={() => setPaid("0")} disabled={!lines.length} title="تسجيل الفاتورة على حساب العميل">آجل بالكامل</button><button type="button" onClick={() => setPaid(String(Math.round(total / 2 * 100) / 100))} disabled={!lines.length}>دفع جزئي</button></div>
          {lines.length > 0 && <small className="pos-hint" role="status">{paidAmount <= 0 ? "اختيارك الحالي: آجل بالكامل — لن يُسجل أي تحصيل في خزنة أو محفظة." : balanceDue <= 0 ? "اختيارك الحالي: مدفوع بالكامل — لازم تختار طريقة الدفع وحساب الاستلام بنفسك." : `اختيارك الحالي: دفع ${money(paidAmount)} الآن والباقي ${money(balanceDue)} على حساب العميل — حدد طريقة الدفع وحساب الاستلام.`}</small>}
          <input className="pos-paid-input" aria-label="المبلغ المدفوع الآن بالجنيه" type="number" min="0" max={total} step="0.01" value={paid} onChange={(event) => setPaid(event.target.value)} disabled={!lines.length}/>
          {paidAmount > 0 && <div className="pos-collection-panel">
            <div className="pos-collection-heading"><span className="pos-collection-heading-icon"><Wallet size={17}/></span><span><strong>الكاش هيتسجل فين؟</strong><small>اختار الخزنة أو المحفظة اللي استلمت المبلغ عليها</small></span><b>{money(paidAmount)}</b></div>
            {financialAccounts.length ? <div className="pos-account-choice-grid" role="radiogroup" aria-label="اختيار حساب استلام الكاش">
              {financialAccounts.map((account) => {
                const isSelected = financialAccountId === account.id;
                const accountLabel = `${account.name} · ${account.currency || "EGP"}`;
                const accountKind = `${account.type || ""} ${account.code || ""} ${account.name}`.toLowerCase();
                const isWallet = /wallet|mobile|vodafone|orange|etisalat|instapay|محفظ|فودافون|اورنج|اتصالات|انستا|كاش/.test(accountKind);
                return <button type="button" role="radio" aria-checked={isSelected} key={account.id} className={`pos-account-choice ${isSelected ? "selected" : ""}`} onClick={() => setFinancialAccountId(account.id)}>
                  <span className="pos-account-choice-icon">{isWallet ? <Smartphone size={19}/> : account.type === "cash" || account.code === "CASH" ? <Banknote size={19}/> : <Wallet size={19}/>}</span>
                  <span className="pos-account-choice-copy"><strong>{account.name}</strong><small>{account.type === "cash" || account.code === "CASH" ? "خزنة نقدية" : isWallet ? "محفظة / حساب تحصيل" : "حساب مالي"}</small></span>
                  <span className="pos-account-choice-check">{isSelected && <Check size={15}/>}</span>
                </button>;
              })}
            </div> : <div className="pos-collection-empty">مفيش خزنة أو محافظ نشطة للاختيار. راجع إعدادات الحسابات المالية قبل تسجيل التحصيل.</div>}
            <label className="pos-collection-method">طريقة التحصيل<select aria-label="طريقة تحصيل الفاتورة" value={paymentMethodId} onChange={(event) => { setPaymentMethodId(event.target.value); setPaymentReference(""); }}><option value="">اختار طريقة التحصيل</option>{paymentMethods.map((method) => <option key={method.id} value={method.id}>{method.name_ar}</option>)}</select></label>
            {selectedPaymentMethod?.requires_reference && <label className="pos-collection-method">رقم مرجع التحصيل<input value={paymentReference} onChange={(event) => setPaymentReference(event.target.value)} maxLength={255} placeholder="رقم إيصال أو تحويل" aria-label="مرجع تحصيل الفاتورة"/></label>}
            {selectedFinancialAccount && selectedPaymentMethod && <div className="pos-collection-confirm"><Check size={14}/> هيتم تسجيل التحصيل بطريقة <strong>{selectedPaymentMethod.name_ar}</strong> على <strong>{selectedFinancialAccount.name}</strong> وربطه بالفاتورة.</div>}
          </div>}
          <div className="pos-total-card"><div><span>إجمالي الأصناف</span><strong>{money(subtotal)}</strong></div><div><span>الخصم</span><strong>{money(discountValue)}</strong></div><div className="pos-grand-total"><span>الإجمالي النهائي</span><strong>{money(total)}</strong></div><div><span>المدفوع</span><strong>{money(paidAmount)}</strong></div><div className={balanceDue > 0 ? "pos-due" : "pos-settled"}><span>{balanceDue > 0 ? "المتبقي على العميل" : "حالة الفاتورة"}</span><strong>{balanceDue > 0 ? money(balanceDue) : "مدفوعة بالكامل"}</strong></div></div>

          {message && <div className={`pos-alert ${messageError ? "error" : "success"}`} role="status" aria-live="polite">{message}</div>}
          {savedInvoiceId && <Link className="pos-invoice-link" href={`/invoices/${savedInvoiceId}`}><Check size={16}/> فتح وطباعة الفاتورة</Link>}
          <button type="button" className="primary pos-submit" disabled={saving || !lines.length} onClick={postSale}><CircleDollarSign size={18}/>{saving ? "جاري تسجيل البيع..." : "تأكيد البيع وإصدار الفاتورة"}</button>
          <small className="pos-secure-note">التسجيل يخصم من رصيد الموقع المحدد. راجع العميل والكميات والإجمالي قبل التأكيد.</small>
        </aside>
      </div>
    </main>
  );
}
