"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { Boxes, Check, CheckCircle2, ClipboardCheck, Fuel, LogOut, MapPin, Navigation, Package, Phone, Plus, RefreshCw, Search, Truck, UserPlus, Users, Wifi, WifiOff, X } from "lucide-react";
import { api, apiWithOfflineQueue } from "@/lib/api";
import FieldActions from "./FieldActions";
import FieldTripTools from "./FieldTripTools";

type Customer = { id: string; name: string; code: string; phone?: string; address_text?: string; latitude?: number | null; longitude?: number | null; sequence: number; visit_status: string };
type Packaging = { id: string; unit_id: string; name_ar: string; unit_name: string; conversion_to_base: number | string; barcode_primary?: string | null; is_default_sale_unit: boolean };
type Product = { id: string; sku: string; barcode?: string | null; name_ar: string; name_en?: string | null; base_unit_id?: string; base_unit_name?: string | null; quantity_base: number; packagings?: Packaging[] };
type Trip = { id: string; trip_number: string; status: string; vehicle_id: string; vehicle_name: string; vehicle_code: string; vehicle_location_id: string };
type Tab = "customers" | "products" | "expenses" | "stocktake";

const TABS: { key: Tab; label: string; icon: typeof Users }[] = [
  { key: "customers", label: "العملاء", icon: Users },
  { key: "products", label: "الأصناف", icon: Boxes },
  { key: "expenses", label: "المصروفات", icon: Fuel },
  { key: "stocktake", label: "جرد العربية", icon: ClipboardCheck },
];

export default function Field() {
  const [trip, setTrip] = useState<Trip | null>(null);
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [stock, setStock] = useState<Product[]>([]);
  const [online, setOnline] = useState(true);
  const [message, setMessage] = useState("");
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<Tab>("customers");
  const [activeCustomerId, setActiveCustomerId] = useState("");
  const [customerSearch, setCustomerSearch] = useState("");
  const [catalogQuery, setCatalogQuery] = useState("");
  const [catalog, setCatalog] = useState<Product[]>([]);
  const [catalogPage, setCatalogPage] = useState(1);
  const [catalogLastPage, setCatalogLastPage] = useState(1);
  const [catalogLoading, setCatalogLoading] = useState(false);
  const [showNewCustomer, setShowNewCustomer] = useState(false);
  const [newCustomerName, setNewCustomerName] = useState("");
  const [newCustomerPhone, setNewCustomerPhone] = useState("");
  const [newCustomerAddress, setNewCustomerAddress] = useState("");
  const [savingCustomer, setSavingCustomer] = useState(false);

  const load = useCallback(async ({ silent = false }: { silent?: boolean } = {}) => {
    if (!silent) { setLoading(true); setMessage(""); }
    try {
      const data = await api<{ trip: Trip | null; customers: Customer[]; stock: Product[] }>("/field/today");
      setTrip(data.trip); setCustomers(data.customers); setStock(data.stock);
      setActiveCustomerId((current) => current && data.customers.some((customer) => customer.id === current && customer.visit_status === "checked_in")
        ? current
        : data.customers.find((customer) => customer.visit_status === "checked_in")?.id ?? "");
    } catch (error) { setMessage((error as Error).message); }
    finally { if (!silent) setLoading(false); }
  }, []);

  const logout = async () => {
    try { await api("/auth/logout", { method: "POST" }); }
    catch { /* local sign-out remains available if the network is down */ }
    finally {
      localStorage.removeItem("nexora_token");
      const org = localStorage.getItem("nexora_organization_id");
      window.location.assign(org ? `/login?organization_id=${encodeURIComponent(org)}` : "/login");
    }
  };

  useEffect(() => {
    setOnline(navigator.onLine);
    const on = () => setOnline(true); const off = () => setOnline(false);
    window.addEventListener("online", on); window.addEventListener("offline", off);
    void load();
    return () => { window.removeEventListener("online", on); window.removeEventListener("offline", off); };
  }, [load]);

  const visit = async (customer: Customer, status: "checked_in" | "visited" | "skipped") => {
    try {
      await apiWithOfflineQueue("/field/visits", { method: "POST", body: JSON.stringify({ trip_id: trip?.id, customer_id: customer.id, status }) });
      setCustomers((rows) => rows.map((row) => row.id === customer.id ? { ...row, visit_status: status } : row));
      setActiveCustomerId(status === "checked_in" ? customer.id : "");
      setMessage(online ? status === "checked_in" ? `تم بدء زيارة ${customer.name}.` : `تم تحديث حالة زيارة ${customer.name}.` : "الزيارة اتحفظت على الجهاز وهتتزامن عند عودة الإنترنت.");
    } catch (error) { setMessage((error as Error).message); }
  };

  const createCustomer = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!trip || !newCustomerName.trim() || savingCustomer) return;
    setSavingCustomer(true); setMessage("");
    try {
      const result = await api<{ customer: { id: string; name: string; code: string; phone?: string | null; address_text?: string | null }; sequence: number; visit_status: string }>("/field/customers", {
        method: "POST",
        body: JSON.stringify({ trip_id: trip.id, name: newCustomerName.trim(), phone: newCustomerPhone.trim() || null, address_text: newCustomerAddress.trim() || null }),
      });
      const customer: Customer = { ...result.customer, phone: result.customer.phone ?? undefined, address_text: result.customer.address_text ?? undefined, latitude: null, longitude: null, sequence: result.sequence, visit_status: result.visit_status };
      setCustomers((current) => [...current, customer]);
      setActiveCustomerId(customer.id);
      setCustomerSearch(""); setNewCustomerName(""); setNewCustomerPhone(""); setNewCustomerAddress(""); setShowNewCustomer(false);
      setMessage(`تم إضافة ${customer.name} للرحلة. تقدر تسجل له بيع أو تحصيل أو مرتجع دلوقتي.`);
    } catch (error) { setMessage((error as Error).message); }
    finally { setSavingCustomer(false); }
  };

  useEffect(() => {
    if (tab !== "products" || !trip) return;
    let active = true;
    const timeout = window.setTimeout(async () => {
      setCatalogLoading(true);
      try {
        const response = await api<{ data: Product[]; current_page: number; last_page: number }>(`/field/catalog?trip_id=${encodeURIComponent(trip.id)}&q=${encodeURIComponent(catalogQuery.trim())}&per_page=50&page=1`);
        if (active) { setCatalog(response.data); setCatalogPage(response.current_page); setCatalogLastPage(response.last_page); }
      } catch (error) { if (active) setMessage((error as Error).message); }
      finally { if (active) setCatalogLoading(false); }
    }, 160);
    return () => { active = false; window.clearTimeout(timeout); };
  }, [tab, trip, catalogQuery]);

  const loadMoreProducts = async () => {
    if (!trip || catalogLoading || catalogPage >= catalogLastPage) return;
    setCatalogLoading(true);
    try {
      const nextPage = catalogPage + 1;
      const response = await api<{ data: Product[]; current_page: number; last_page: number }>(`/field/catalog?trip_id=${encodeURIComponent(trip.id)}&q=${encodeURIComponent(catalogQuery.trim())}&per_page=50&page=${nextPage}`);
      setCatalog((current) => [...current, ...response.data]); setCatalogPage(response.current_page); setCatalogLastPage(response.last_page);
    } catch (error) { setMessage((error as Error).message); }
    finally { setCatalogLoading(false); }
  };

  const completedCount = customers.filter((customer) => ["visited", "skipped"].includes(customer.visit_status)).length;
  const progress = customers.length ? Math.round((completedCount / customers.length) * 100) : 0;
  const activeCustomer = customers.find((customer) => customer.id === activeCustomerId && customer.visit_status === "checked_in") ?? null;
  const visibleCustomers = useMemo(() => customers.filter((customer) => `${customer.name} ${customer.code} ${customer.phone ?? ""}`.toLocaleLowerCase().includes(customerSearch.trim().toLocaleLowerCase())), [customers, customerSearch]);

  return <main className="field-main">
    <header className="field-header">
      <div className="field-brand-block"><div className="brand-mini">NEXORA DISTRIBUTION</div><h1>مساحة عمل المندوب</h1><span>{trip ? `${trip.trip_number} · ${trip.vehicle_name}` : "لا توجد رحلة مسندة اليوم"}</span></div>
      <div className="field-header-actions"><span className={`connection ${online ? "" : "offline"}`}>{online ? <Wifi size={14}/> : <WifiOff size={14}/>} {online ? "متصل" : "بدون إنترنت"}</span><button className="icon-btn" onClick={() => void load()} aria-label="تحديث الرحلة"><RefreshCw size={16}/></button><button className="icon-btn" onClick={logout} aria-label="تسجيل الخروج" title="تسجيل الخروج"><LogOut size={16}/></button></div>
    </header>

    {trip && <>
      <section className="field-trip-overview">
        <div className="field-trip-overview-title"><span className="field-trip-overview-icon"><Truck size={20}/></span><div><strong>رحلة اليوم جاهزة للتشغيل</strong><small>{trip.trip_number} · {trip.vehicle_name} · {trip.vehicle_code}</small></div><span className="field-trip-status">{trip.status === "planned" ? "مخططة" : "قيد التنفيذ"}</span></div>
        <div className="field-stats"><div><strong>{customers.length.toLocaleString("ar-EG")}</strong><span>عملاء في خط السير</span></div><div><strong>{completedCount.toLocaleString("ar-EG")}</strong><span>زيارات مكتملة</span></div><div><strong>{stock.length.toLocaleString("ar-EG")}</strong><span>أصناف في العربية</span></div></div>
        <div className="field-progress-copy"><span>إنجاز خط السير</span><strong>{progress.toLocaleString("ar-EG")}٪</strong></div>
        <div role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={progress} aria-label="نسبة إنجاز زيارات الرحلة" className="field-progress-track"><div className="field-progress-value" style={{ width: `${progress}%` }}/></div>
        <small className="field-progress-note">النسبة محسوبة من الزيارات المنجزة أو المتخطاة، وليست عمولة مبيعات.</small>
      </section>

      <nav className="field-workspace-tabs" aria-label="أدوات المندوب">
        {TABS.map(({ key, label, icon: Icon }) => <button key={key} type="button" className={`field-workspace-tab ${tab === key ? "active" : ""}`} aria-pressed={tab === key} onClick={() => setTab(key)}><Icon size={18}/><span>{label}</span>{key === "customers" ? <small>{customers.length}</small> : key === "products" ? <small>{stock.length}</small> : null}</button>)}
      </nav>

      {tab === "customers" && <>
        <section className="field-card field-route-card">
          <div className="field-section-heading"><div><h2>العملاء اللي في خط سيرك</h2><p>اختار العميل اللي وصلت له، أو أضف عميل جديد من مكانك وسجّل له البيع فورًا.</p></div><button type="button" className="primary" onClick={() => setShowNewCustomer((value) => !value)}><UserPlus size={16}/><span>إضافة عميل جديد</span>{showNewCustomer ? <X size={15}/> : <Plus size={15}/>}</button><span className="field-neutral-counter">{visibleCustomers.length.toLocaleString("ar-EG")} عميل</span></div>
          {showNewCustomer && <form className="field-card field-form-stack" onSubmit={createCustomer} style={{ marginBottom: 16 }}>
            <div className="field-section-heading"><div><h2>عميل جديد للزيارة الحالية</h2><p>هيتحفظ في قاعدة بيانات الشركة ويتضاف تلقائيًا لرحلتك الحالية.</p></div></div>
            <div className="field-entry-grid">
              <label className="action-field">اسم العميل *<input required maxLength={255} value={newCustomerName} onChange={(event) => setNewCustomerName(event.target.value)} placeholder="اسم التاجر أو المحل" autoFocus /></label>
              <label className="action-field">رقم الهاتف<input type="tel" maxLength={32} value={newCustomerPhone} onChange={(event) => setNewCustomerPhone(event.target.value)} placeholder="01xxxxxxxxx" /></label>
              <label className="action-field">العنوان<input maxLength={2000} value={newCustomerAddress} onChange={(event) => setNewCustomerAddress(event.target.value)} placeholder="المنطقة والشارع (اختياري)" /></label>
            </div>
            <div className="field-header-actions"><button type="button" className="secondary" onClick={() => setShowNewCustomer(false)} disabled={savingCustomer}>إلغاء</button><button type="submit" className="primary" disabled={savingCustomer || !newCustomerName.trim()}>{savingCustomer ? "جاري حفظ العميل..." : "حفظ العميل وبدء البيع"}</button></div>
          </form>}
          <label className="action-field field-customer-search"><span className="sr-only">بحث العملاء</span><div className="action-search"><Search size={18}/><input aria-label="بحث العملاء" value={customerSearch} onChange={(event) => setCustomerSearch(event.target.value)} placeholder="ابحث باسم العميل أو رقم الهاتف أو الكود..."/></div></label>
          {loading ? <div className="field-tools-loading">جاري تحميل خط السير...</div> : visibleCustomers.map((customer) => {
            const active = activeCustomerId === customer.id && customer.visit_status === "checked_in";
            const done = ["visited", "skipped"].includes(customer.visit_status);
            return <article className={`customer-stop field-route-stop ${active ? "active" : ""} ${done ? "completed" : ""}`} key={customer.id}>
              <div className="stop-main"><span className="stop-number">{customer.sequence.toLocaleString("ar-EG")}</span><div className="field-customer-copy"><strong>{customer.name}</strong><small>{customer.code}{customer.phone ? ` · ${customer.phone}` : ""}</small><small>{customer.address_text || "العنوان غير مسجل"}</small></div><span className={`field-customer-status ${done ? "done" : active ? "active" : "pending"}`}>{done ? "تمت الزيارة" : active ? "زيارة جارية" : "في الانتظار"}</span></div>
              <div className="stop-actions field-route-actions">
                {!done && !active && <button className="visit-check" onClick={() => void visit(customer, "checked_in")}>بدء الزيارة</button>}
                {active && <span className="field-active-visit"><CheckCircle2 size={15}/> أنت عند العميل ده</span>}
                {!done && <button className="visit-done" onClick={() => void visit(customer, "visited")}>تمت الزيارة</button>}
                {!done && <button className="field-skip-visit" onClick={() => void visit(customer, "skipped")}>تخطي</button>}
                {customer.phone && <a href={`tel:${customer.phone}`} className="icon-btn" aria-label={`اتصال بـ ${customer.name}`}><Phone size={15}/></a>}
                {customer.latitude != null && customer.longitude != null && <a target="_blank" rel="noreferrer" href={`https://www.google.com/maps/dir/?api=1&destination=${customer.latitude},${customer.longitude}`} className="icon-btn" aria-label={`الاتجاهات إلى ${customer.name}`}><Navigation size={15}/></a>}
              </div>
              {active && <div className="field-current-customer-tools"><FieldActions trip={trip} customer={customer} onDone={() => { setMessage("تم تنفيذ العملية."); void load({ silent: true }); }}/></div>}
            </article>;
          })}
          {!loading && !visibleCustomers.length && <div className="field-empty-state"><Users size={24}/><strong>{customers.length ? "مفيش عميل مطابق للبحث" : "لسه مفيش عملاء في خط السير"}</strong><span>{customers.length ? "جرّب اسمًا أو رقم هاتف تاني." : "اطلب من مسؤول التوزيع إسناد العملاء للرحلة."}</span></div>}
        </section>
        {message && <div className="field-toast" role="status" aria-live="polite">{message}</div>}
      </>}

      {tab === "products" && <section className="field-card field-products-browser">
        <div className="field-section-heading"><div><h2>أصناف العربية</h2><p>بحث مباشر في أسماء الأصناف والأكواد والباركود والوحدات.</p></div><span className="field-neutral-counter">{stock.length.toLocaleString("ar-EG")} رصيد ظاهر</span></div>
        <label className="action-field"><span>بحث الصنف</span><div className="action-search"><Search size={18}/><input aria-label="بحث كتالوج العربية" value={catalogQuery} onChange={(event) => setCatalogQuery(event.target.value)} placeholder="اسم عربي/إنجليزي، SKU أو باركود..." autoComplete="off"/></div></label>
        {catalogLoading && <div className="field-tools-loading">جاري البحث في الكتالوج...</div>}
        <div className="field-catalog-results field-catalog-full-list">{catalog.map((product) => <article className="field-catalog-result field-catalog-readonly" key={product.id}><span className="field-product-mark"><Package size={18}/></span><span className="field-product-copy"><strong>{product.name_ar}</strong><small>كود: {product.sku}{product.barcode ? ` · باركود: ${product.barcode}` : ""}</small><small>الوحدة: {product.base_unit_name ?? "وحدة أساسية"}{product.packagings?.length ? ` · ${product.packagings.map((pack) => pack.name_ar).join("، ")}` : ""}</small></span><span className="field-product-stock"><strong>{Math.floor(Number(product.quantity_base)).toLocaleString("ar-EG")}</strong><small>{product.base_unit_name ?? "وحدة"}</small><small className="field-product-price">السعر يحدد عند البيع</small></span></article>)}</div>
        {!catalogLoading && !catalog.length && <div className="field-empty-state"><Search size={24}/><strong>مفيش أصناف مطابقة</strong><span>جرّب جزءًا من الاسم أو الكود أو امسح باركود الصنف في شاشة البيع.</span></div>}
        {catalogPage < catalogLastPage && <button className="secondary field-load-more" onClick={() => void loadMoreProducts()} disabled={catalogLoading}>{catalogLoading ? "جاري التحميل..." : "عرض أصناف إضافية"}</button>}
      </section>}

      {tab === "expenses" && <FieldTripTools trip={trip} mode="expenses" onChanged={() => void load()}/>}
      {tab === "stocktake" && <FieldTripTools trip={trip} mode="stocktake" onChanged={() => void load()}/>}
    </>}

    {!trip && !loading && <section className="field-empty-state field-no-trip"><Truck size={30}/><strong>مفيش رحلة شغالة لحسابك النهارده</strong><span>لما مسؤول التوزيع يسند لك رحلة فيها العملاء والعربية، هتظهر هنا تلقائيًا.</span><button className="secondary" onClick={() => void load()}><RefreshCw size={15}/> تحديث الرحلة</button></section>}
    {loading && !trip && <div className="field-tools-loading">جاري تحميل رحلة اليوم...</div>}
    {message && tab !== "customers" && <div className="field-toast" role="status" aria-live="polite">{message}</div>}
  </main>;
}
