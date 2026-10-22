"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { AlertTriangle, ArrowLeftRight, Boxes, Check, CheckCircle2, ChevronLeft, ChevronRight, ClipboardCheck, Download, History, Package, Plus, RefreshCw, Search, Truck, Warehouse, X } from "lucide-react";
import { api } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";

type Row = { id: string; product_id: string; location_id: string; sku: string; name_ar: string; name_en?: string; category_id?: string | null; category_name?: string | null; location_name: string; location_code: string; quantity_base: string | number; reserved_quantity_base: string | number; average_cost: string | number };
type Location = { id: string; name: string; code: string; type?: string };
type Category = { id: string; name_ar: string; code: string };
type PageResponse<T> = { data: T[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };
type StockStatus = "all" | "available" | "low" | "out";
type InventorySummary = { balance_rows:number; products_with_balance:number; active_products:number; products_without_positive_stock:number; quantity_units:number; stock_value:number; low_balance_rows:number; zero_balance_rows:number; threshold:number };
type MovementRow = { id:string; product_id:string; location_id:string; movement_type:string; quantity_base:string|number; unit_cost:string|number; source_document_type?:string|null; source_document_id?:string|null; occurred_at:string; posted_at?:string|null; reference?:string|null; sku:string; name_ar:string; location_name:string; location_code:string; created_by_name?:string|null };
type TransferProduct = {id:string;sku:string;name_ar:string;quantity_base:number;base_unit_name?:string|null};
type TransferLine = {product:TransferProduct;quantity:string};
type StocktakeItem = {product_id:string;sku:string;product_name:string;base_unit_name?:string|null;expected_quantity_base:number|string;counted_quantity_base:number|string;variance_quantity_base:number|string};
type Stocktake = {id:string;trip_id:string;status:"submitted"|"approved"|"rejected";item_count:number;variance_items_count:number;items:StocktakeItem[];notes?:string|null;review_notes?:string|null;submitted_at?:string|null;reviewed_at?:string|null;trip_number:string;trip_display_name?:string|null;trip_date:string;vehicle_name:string;vehicle_code:string;submitted_by_name:string;reviewed_by_name?:string|null};
const money = (value: number) => `${value.toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ج.م`;

export default function Inventory() {
  const [rows, setRows] = useState<Row[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [search, setSearch] = useState("");
  const [locationId, setLocationId] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [stockStatus, setStockStatus] = useState<StockStatus>("all");
  const [threshold, setThreshold] = useState("5");
  const [page, setPage] = useState(1);
  const [pageInfo, setPageInfo] = useState({ total: 0, lastPage: 1, from: 0, to: 0 });
  const [summary, setSummary] = useState<InventorySummary>({balance_rows:0,products_with_balance:0,active_products:0,products_without_positive_stock:0,quantity_units:0,stock_value:0,low_balance_rows:0,zero_balance_rows:0,threshold:5});
  const [view, setView] = useState<"balances"|"movements"|"transfer"|"stocktakes">("balances");
  const [movementRows, setMovementRows] = useState<MovementRow[]>([]);
  const [movementPage, setMovementPage] = useState({total:0,lastPage:1,from:0,to:0});
  const [movementSearch, setMovementSearch] = useState("");
  const [movementType, setMovementType] = useState("");
  const [fromDate, setFromDate] = useState("");
  const [toDate, setToDate] = useState("");
  const [movementCurrentPage, setMovementCurrentPage] = useState(1);
  const [transferFrom, setTransferFrom] = useState("");
  const [transferTo, setTransferTo] = useState("");
  const [transferSearch, setTransferSearch] = useState("");
  const [transferProducts, setTransferProducts] = useState<TransferProduct[]>([]);
  const [transferLines, setTransferLines] = useState<TransferLine[]>([]);
  const [transferReason, setTransferReason] = useState("");
  const [transferSaving, setTransferSaving] = useState(false);
  const [transferMessage, setTransferMessage] = useState("");
  const [stocktakes, setStocktakes] = useState<Stocktake[]>([]);
  const [stocktakeStatus, setStocktakeStatus] = useState<"submitted"|"approved"|"rejected"|"all">("submitted");
  const [stocktakeSearch, setStocktakeSearch] = useState("");
  const [stocktakePage, setStocktakePage] = useState(1);
  const [stocktakePageInfo, setStocktakePageInfo] = useState({total:0,lastPage:1,from:0,to:0});
  const [stocktakeBusyId, setStocktakeBusyId] = useState<string|null>(null);
  const [stocktakeReviewNotes, setStocktakeReviewNotes] = useState<Record<string,string>>({});
  const [stocktakeMessage, setStocktakeMessage] = useState("");
  const [stocktakeError, setStocktakeError] = useState("");
  const [refreshTick, setRefreshTick] = useState(0);
  const [selected, setSelected] = useState<Row | null>(null);
  const [delta, setDelta] = useState("");
  const [reason, setReason] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");

  useEffect(() => {
    Promise.all([api<Location[]>("/locations"), api<{ categories: Category[] }>("/catalog/references")])
      .then(([nextLocations, refs]) => { setLocations(nextLocations); setCategories(refs.categories ?? []); setTransferFrom(current=>current||nextLocations.find(item=>item.type==="warehouse")?.id||nextLocations[0]?.id||""); setTransferTo(current=>current||nextLocations.find(item=>item.type==="warehouse"&&item.id!==(nextLocations.find(row=>row.type==="warehouse")?.id))?.id||nextLocations.find(item=>item.id!==(nextLocations.find(row=>row.type==="warehouse")?.id))?.id||""); })
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : "تعذر تحميل قوائم المواقع والتصنيفات."));
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      setLoading(true);
      const params = new URLSearchParams({ per_page: "50", page: String(page), stock_status: stockStatus, low_stock_threshold: threshold || "5" });
      if (search.trim()) params.set("search", search.trim());
      if (locationId) params.set("location_id", locationId);
      if (categoryId) params.set("category_id", categoryId);
      const summaryParams=new URLSearchParams(params);summaryParams.delete("page");summaryParams.delete("per_page");summaryParams.delete("stock_status");
      Promise.all([api<PageResponse<Row>>(`/inventory?${params.toString()}`),api<InventorySummary>(`/inventory/summary?${summaryParams.toString()}`)])
        .then(([result,stockSummary]) => {
          setRows(result.data);
          setPageInfo({ total: result.total, lastPage: result.last_page, from: result.from ?? 0, to: result.to ?? 0 });
          setSummary(stockSummary);
          setError("");
          if (selected && !result.data.some((row) => row.id === selected.id)) setSelected(null);
        })
        .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : "تعذر تحميل أرصدة المخزون."))
        .finally(() => setLoading(false));
    }, search.trim() ? 180 : 0);
    return () => clearTimeout(timer);
  }, [search, locationId, categoryId, stockStatus, threshold, page, refreshTick]);

  useEffect(()=>{
    if(view!=="movements")return;
    const timer=setTimeout(()=>{
      const params=new URLSearchParams({per_page:"50",page:String(movementCurrentPage)});
      if(locationId)params.set("location_id",locationId);if(movementSearch.trim())params.set("search",movementSearch.trim());
      if(movementType)params.set("movement_type",movementType);if(fromDate)params.set("from_date",fromDate);if(toDate)params.set("to_date",toDate);
      api<PageResponse<MovementRow>>(`/inventory/movements?${params.toString()}`).then(result=>{setMovementRows(result.data);setMovementPage({total:result.total,lastPage:result.last_page,from:result.from??0,to:result.to??0});setError("")}).catch((reason:unknown)=>setError(reason instanceof Error?reason.message:"تعذر تحميل سجل حركات المخزون."));
    },movementSearch.trim()?180:0);return()=>clearTimeout(timer);
  },[view,locationId,movementSearch,movementType,fromDate,toDate,movementCurrentPage,refreshTick]);

  useEffect(()=>{
    if(view!=="transfer"||!transferFrom)return;
    const timer=setTimeout(()=>{const params=new URLSearchParams({location_id:transferFrom});if(transferSearch.trim())params.set("q",transferSearch.trim());api<TransferProduct[]>(`/products?${params.toString()}`).then(setTransferProducts).catch((reason:unknown)=>setError(reason instanceof Error?reason.message:"تعذر تحميل المنتجات المتاحة للتحويل."))},transferSearch.trim()?180:0);return()=>clearTimeout(timer);
  },[view,transferFrom,transferSearch]);

  useEffect(()=>{
    if(view!=="stocktakes")return;
    let active=true;
    const timer=setTimeout(()=>{
      const params=new URLSearchParams({status:stocktakeStatus,per_page:"20",page:String(stocktakePage)});
      if(stocktakeSearch.trim())params.set("search",stocktakeSearch.trim());
      api<PageResponse<Stocktake>>(`/inventory/stocktakes?${params.toString()}`).then(result=>{
        if(!active)return;
        setStocktakes(result.data);setStocktakePageInfo({total:result.total,lastPage:result.last_page,from:result.from??0,to:result.to??0});setStocktakeError("");
      }).catch(error=>{if(active)setStocktakeError(error instanceof Error?error.message:"تعذر تحميل الجرد المرسل للمراجعة.")});
    },stocktakeSearch.trim()?180:0);
    return()=>{active=false;clearTimeout(timer)};
  },[view,stocktakeStatus,stocktakeSearch,stocktakePage,refreshTick]);

  const lowCount = summary.low_balance_rows;
  const outCount = summary.products_without_positive_stock;

  async function adjustStock() {
    if (!selected) return setMessage("اختار الصنف والموقع من الجدول أولًا.");
    const amount = Number(delta);
    if (!Number.isFinite(amount) || amount === 0 || !reason.trim()) return setMessage("أدخل مقدار تغيير غير صفر وسبب واضح للتعديل.");
    if (Number(selected.quantity_base) + amount < 0) return setMessage("التعديل سيجعل المخزون بالسالب. راجع الكمية أو نفّذ تسوية جرد معتمدة.");
    setSaving(true);
    setMessage("");
    try {
      const payload = { product_id: selected.product_id, location_id: selected.location_id, quantity_delta: amount, reason: reason.trim() };
      const key = await getStableIdempotencyKey("inventory-adjustment", payload);
      const result = await api<{ document_number: string; balance_after: string | number }>("/inventory/adjust", { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      clearStableIdempotencyKey("inventory-adjustment", key);
      setMessage(`تم تسجيل ${result.document_number}. الرصيد بعد التعديل: ${Number(result.balance_after).toLocaleString("ar-EG")}.`);
      setDelta("");
      setReason("");
      setSelected(null);
      setPage(1);
      setRefreshTick(value=>value+1);
    } catch (reason) {
      setMessage(reason instanceof Error ? reason.message : "تعذر تعديل المخزون. راجع الرصيد والحركات.");
    } finally {
      setSaving(false);
    }
  }

  async function exportInventoryCsv(){
    setError("");
    try{
      const params=new URLSearchParams({per_page:"200",page:"1",stock_status:stockStatus,low_stock_threshold:threshold||"5"});
      if(search.trim())params.set("search",search.trim());if(locationId)params.set("location_id",locationId);if(categoryId)params.set("category_id",categoryId);
      const first=await api<PageResponse<Row>>(`/inventory?${params.toString()}`);const allRows=[...first.data];
      for(let current=2;current<=first.last_page;current++){params.set("page",String(current));const next=await api<PageResponse<Row>>(`/inventory?${params.toString()}`);allRows.push(...next.data)}
      const quote=(value:unknown)=>`"${String(value??"").replaceAll('"','""')}"`;
      const lines=[["الصنف","SKU","التصنيف","الموقع","الرصيد الفعلي","المحجوز","المتاح","متوسط التكلفة","قيمة المخزون"],...allRows.map(row=>{const onHand=Number(row.quantity_base||0);const reserved=Number(row.reserved_quantity_base||0);const available=onHand-reserved;return [row.name_ar,row.sku,row.category_name||"",row.location_name,onHand,reserved,available,Number(row.average_cost||0),onHand*Number(row.average_cost||0)]})];
      const csv="\uFEFF"+lines.map(line=>line.map(quote).join(",")).join("\r\n");const url=URL.createObjectURL(new Blob([csv],{type:"text/csv;charset=utf-8;"}));const link=document.createElement("a");link.href=url;link.download=`nexora-inventory-${new Date().toISOString().slice(0,10)}.csv`;document.body.appendChild(link);link.click();link.remove();URL.revokeObjectURL(url);
    }catch(reason){setError(reason instanceof Error?reason.message:"تعذر تصدير أرصدة المخزون.")}
  }

  function addTransferProduct(product:TransferProduct){
    if(transferLines.some(line=>line.product.id===product.id)){setTransferMessage("الصنف موجود بالفعل في قائمة التحويل.");return}
    if(Number(product.quantity_base)<=0){setTransferMessage("لا يوجد رصيد متاح لهذا الصنف في مخزن الصرف.");return}
    setTransferLines(current=>[...current,{product,quantity:"1"}]);setTransferMessage("");
  }

  async function postTransfer(){
    if(!transferFrom||!transferTo||transferFrom===transferTo)return setTransferMessage("اختار مخزن صرف ومخزن استلام مختلفين.");
    if(!transferReason.trim())return setTransferMessage("اكتب سبب التحويل عشان يظهر في سجل حركة المخزون.");
    if(!transferLines.length)return setTransferMessage("أضف صنفًا واحدًا على الأقل لقائمة التحويل.");
    const invalid=transferLines.find(line=>!Number.isFinite(Number(line.quantity))||Number(line.quantity)<=0||Number(line.quantity)>Number(line.product.quantity_base));
    if(invalid)return setTransferMessage(`راجع كمية ${invalid.product.name_ar}؛ المطلوب لازم يكون موجب وألا يتجاوز الرصيد المتاح (${Number(invalid.product.quantity_base).toLocaleString("ar-EG")}).`);
    setTransferSaving(true);setTransferMessage("");
    try{
      const payload={from_location_id:transferFrom,to_location_id:transferTo,reason:transferReason.trim(),items:transferLines.map(line=>({product_id:line.product.id,quantity_base:Number(line.quantity)}))};
      const key=await getStableIdempotencyKey("stock-transfer",payload);
      const result=await api<{document_number:string;total_quantity_base:number;from_location_name:string;to_location_name:string;replayed?:boolean}>("/inventory/transfers",{method:"POST",body:JSON.stringify({...payload,idempotency_key:key})});
      clearStableIdempotencyKey("stock-transfer",key);setTransferMessage(`تم تسجيل التحويل ${result.document_number}: ${result.from_location_name} ← ${result.to_location_name}. إجمالي الكمية ${Number(result.total_quantity_base).toLocaleString("ar-EG",{maximumFractionDigits:3})}.`);
      setTransferLines([]);setTransferReason("");setTransferSearch("");setPage(1);setRefreshTick(value=>value+1);
    }catch(reason){setTransferMessage(reason instanceof Error?reason.message:"تعذر ترحيل التحويل. راجع الرصيد المتاح وحاول مجددًا.")}
    finally{setTransferSaving(false)}
  }

  async function reviewStocktake(stocktake:Stocktake,action:"approve"|"reject"){
    const reviewNotes=(stocktakeReviewNotes[stocktake.id]||"").trim();
    if(action==="reject"&&!reviewNotes)return setStocktakeMessage("اكتب سبب الرفض قبل تسجيل القرار.");
    const varianceProducts=stocktake.items.filter(item=>Math.abs(Number(item.variance_quantity_base)||0)>0.000001).length;
    if(action==="approve"&&!window.confirm(varianceProducts
      ?`تأكيد اعتماد الجرد؟ سيتم ترحيل فروق ${varianceProducts} صنف إلى رصيد ${stocktake.vehicle_name} كحركات مخزنية مرتبطة بمستند الجرد. لو أي رصيد اتغير بعد تسجيل الجرد، العملية هتترفض بالكامل.`
      :"تأكيد اعتماد الجرد؟ لا توجد فروقات، ولن تتغير أرصدة المخزون."))return;
    setStocktakeBusyId(stocktake.id);setStocktakeMessage("");setStocktakeError("");
    try{
      const result=await api<{message:string;status:string;posted_adjustments?:number}>(`/inventory/stocktakes/${encodeURIComponent(stocktake.id)}/review`,{
        method:"POST",body:JSON.stringify({action,review_notes:reviewNotes||undefined}),
      });
      setStocktakeMessage(result.message);setStocktakeReviewNotes(current=>({...current,[stocktake.id]:""}));setRefreshTick(value=>value+1);
    }catch(error){setStocktakeError(error instanceof Error?error.message:"تعذر مراجعة الجرد. راجع الرصيد الحالي وحاول مجددًا.")}
    finally{setStocktakeBusyId(null)}
  }

  const movementTypeLabels:Record<string,string>={purchase_receipt:"استلام مشتريات",sale:"صرف مبيعات",sales_return:"مرتجع مبيعات",purchase_return:"مرتجع مشتريات",stock_adjustment:"تسوية جرد",transfer_out:"تحويل صادر",transfer_in:"تحويل وارد",vehicle_load:"تحميل عربية",opening_balance:"رصيد افتتاحي",stock_transfer:"تحويل مخزني",stocktake:"جرد فعلي",damage:"تالف / كسر"};

  return <main className="main inventory-page">
    <div className="topbar inventory-page-topbar"><div><span className="inventory-page-eyebrow">STOCK CONTROL / WAREHOUSE & VEHICLES</span><h1 className="title">مركز المخزون</h1><div className="subtitle">تابع أرصدة كل صنف حسب المخزن أو العربية، وراجع النواقص والتكلفة والتسويات من مكان واحد.</div></div><nav className="inventory-page-nav" aria-label="التنقل بين المخزون والمنتجات"><Link href="/inventory" className="active"><Warehouse size={14}/> أرصدة المخازن</Link><Link href="/products"><Boxes size={14}/> المنتجات المتاحة</Link></nav></div>

    {error && <div className="pos-alert error" role="alert">{error}</div>}

    <div className="inventory-filter-bar card">
      <label className="inventory-search"><Search size={17}/><input aria-label="بحث المخزون" value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="اسم الصنف / SKU / الباركود / اسم الموقع..."/><kbd>بحث</kbd></label>
      <label>الموقع<select value={locationId} onChange={(event) => { setPage(1); setSelected(null); setLocationId(event.target.value); }}><option value="">كل المواقع</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
      <label>التصنيف<select value={categoryId} onChange={(event) => { setPage(1); setSelected(null); setCategoryId(event.target.value); }}><option value="">كل التصنيفات</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name_ar}</option>)}</select></label>
      <label>حالة الرصيد<select value={stockStatus} onChange={(event) => { setPage(1); setSelected(null); setStockStatus(event.target.value as StockStatus); }}><option value="all">كل الأرصدة</option><option value="available">متوفر</option><option value="low">رصيد منخفض</option><option value="out">نفد المخزون</option></select></label>
      {(stockStatus === "low" || stockStatus === "available") && <label>حد الرصيد المنخفض<input type="number" min="0" step="any" value={threshold} onChange={(event) => { setPage(1); setThreshold(event.target.value); }}/></label>}
      <button type="button" className="inventory-reset-filter" onClick={() => { setSearch(""); setLocationId(""); setCategoryId(""); setStockStatus("all"); setThreshold("5"); setPage(1); setSelected(null); }}>مسح الفلاتر <X size={13}/></button>
    </div>

    <div className="inventory-metrics inventory-metrics-wide">
      <div className="card inventory-metric"><span><Boxes size={16}/> الأرصدة المطابقة</span><strong>{pageInfo.total.toLocaleString("ar-EG")}</strong><small>كل النتائج، مش الصفحة الحالية</small></div>
      <div className="card inventory-metric"><span><Package size={16}/> إجمالي الوحدات</span><strong>{Number(summary.quantity_units).toLocaleString("ar-EG", { maximumFractionDigits: 3 })}</strong><small>الرصيد الفعلي بعد تطبيق الفلاتر</small></div>
      <div className="card inventory-metric"><span><Warehouse size={16}/> قيمة المخزون</span><strong>{money(Number(summary.stock_value)||0)}</strong><small>على أساس متوسط التكلفة</small></div>
      <div className="card inventory-metric inventory-warning"><span><AlertTriangle size={16}/> رصيد متاح منخفض</span><strong>{lowCount.toLocaleString("ar-EG")}</strong><small>من كامل النتائج</small></div>
      <div className="card inventory-metric inventory-danger"><span><X size={16}/> أصناف بدون رصيد موجب</span><strong>{outCount.toLocaleString("ar-EG")}</strong><small>بالنطاق المحدد</small></div>
    </div>

    <div className="inventory-workspace-tabs" role="tablist" aria-label="أدوات إدارة المخزون">
      <button type="button" role="tab" aria-selected={view==="balances"} className={view==="balances"?"active":""} onClick={()=>setView("balances")}><Warehouse size={16}/> أرصدة المخازن</button>
      <button type="button" role="tab" aria-selected={view==="movements"} className={view==="movements"?"active":""} onClick={()=>{setView("movements");setMovementCurrentPage(1);setSelected(null)}}><History size={16}/> سجل حركة المخزون <span>{movementPage.total.toLocaleString("ar-EG")}</span></button>
      <button type="button" role="tab" aria-selected={view==="transfer"} className={view==="transfer"?"active":""} onClick={()=>{setView("transfer");setSelected(null);setTransferMessage("")}}><ArrowLeftRight size={16}/> تحويل بين المخازن</button>
      <button type="button" role="tab" aria-selected={view==="stocktakes"} className={view==="stocktakes"?"active":""} onClick={()=>{setView("stocktakes");setSelected(null);setStocktakePage(1);setStocktakeMessage("")}}><ClipboardCheck size={16}/> مراجعة جرد العربيات <span>{stocktakeStatus==="submitted"?stocktakePageInfo.total.toLocaleString("ar-EG"):"✓"}</span></button>
    </div>

    {view==="balances"&&<>
      {selected && <section className="card inventory-adjust-panel">
        <div className="customer-section-heading"><div><h2>تعديل رصيد صنف</h2><p>{selected.name_ar} · {selected.sku} · {selected.location_name}</p></div><button type="button" className="icon-btn" aria-label="إلغاء التعديل" onClick={() => { setSelected(null); setDelta(""); setReason(""); }}><X size={15}/></button></div>
        <div className="inventory-adjust-grid"><label>الرصيد الفعلي<input readOnly value={Number(selected.quantity_base).toLocaleString("ar-EG", { maximumFractionDigits: 3 })}/></label><label>فرق الكمية (+ / −)<input autoFocus type="number" step="any" value={delta} onChange={(event) => setDelta(event.target.value)} placeholder="مثال: 5 أو -2"/></label><label>سبب التعديل<input value={reason} onChange={(event) => setReason(event.target.value)} placeholder="جرد فعلي / كسر / تصحيح رصيد..." maxLength={255}/></label><button type="button" className="primary" disabled={saving} onClick={adjustStock}><RefreshCw size={14}/>{saving ? "جاري التسجيل..." : "تسجيل حركة التعديل"}</button></div>
        <small className="inventory-adjust-help">كل تعديل ينشئ حركة مخزون مسجلة؛ لا يتم تغيير الرصيد بصمت.</small>
      </section>}

      {message && <div className={`pos-alert ${message.startsWith("تم تسجيل") ? "success" : "error"}`} role="status" aria-live="polite">{message}</div>}
      <section className="card inventory-table-card">
        <div className="customer-section-heading"><div><h2>أرصدة الأصناف</h2><p>النتائج {pageInfo.from.toLocaleString("ar-EG")}–{pageInfo.to.toLocaleString("ar-EG")} من {pageInfo.total.toLocaleString("ar-EG")} · المتاح = الفعلي − المحجوز.</p></div><div className="inventory-table-actions"><button type="button" className="secondary" onClick={() => setRefreshTick(value=>value+1)}><RefreshCw size={13}/> تحديث</button><button type="button" className="secondary inventory-export-button" onClick={()=>void exportInventoryCsv()}><Download size={13}/> تصدير CSV</button></div></div>
        <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>الكود</th><th>التصنيف</th><th>الموقع</th><th>الرصيد الفعلي</th><th>المحجوز</th><th>المتاح</th><th>متوسط التكلفة</th><th>قيمة المخزون</th><th></th></tr></thead><tbody>
          {loading ? <tr><td colSpan={10}>جاري تحميل أرصدة المخزون...</td></tr> : rows.map((row) => {
            const quantity = Number(row.quantity_base) || 0; const reserved=Number(row.reserved_quantity_base)||0; const available=quantity-reserved; const value = quantity * (Number(row.average_cost) || 0);
            return <tr key={row.id} className={selected?.id === row.id ? "inventory-row-selected" : ""}>
              <td><strong>{row.name_ar}</strong>{row.name_en && <small className="inventory-secondary-name">{row.name_en}</small>}</td><td>{row.sku}</td><td>{row.category_name || "بدون تصنيف"}</td><td>{row.location_name}</td><td>{quantity.toLocaleString("ar-EG", { maximumFractionDigits: 3 })}</td><td>{reserved.toLocaleString("ar-EG",{maximumFractionDigits:3})}</td><td><strong className={available<=0?"ledger-due":available<=(Number(threshold)||5)?"inventory-quantity-low":"ledger-paid"}>{available.toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong>{available<=0?<small className="inventory-status">نفد المتاح</small>:available<=(Number(threshold)||5)?<small className="inventory-status">منخفض</small>:null}</td><td>{money(Number(row.average_cost) || 0)}</td><td>{money(value)}</td><td><button type="button" className={selected?.id === row.id ? "inventory-row-action selected" : "inventory-row-action"} onClick={() => { setSelected(row); setDelta(""); setReason(""); setMessage(""); }}>{selected?.id === row.id ? <Check size={13}/> : <RefreshCw size={13}/>} تسوية</button></td>
            </tr>;
          })}
          {!loading && !rows.length && <tr><td colSpan={10}><div className="inventory-no-results"><Package size={25}/><strong>لا توجد أرصدة مطابقة للفلاتر</strong><span>جرّب تغيير الموقع أو حالة الرصيد، أو امسح بعض الفلاتر.</span></div></td></tr>}
        </tbody></table></div>
        <div className="pos-pagination"><span>صفحة {page.toLocaleString("ar-EG")} من {Math.max(1, pageInfo.lastPage).toLocaleString("ar-EG")}</span><div><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((current) => Math.max(1, current - 1))}><ChevronRight size={15}/> السابق</button><button type="button" disabled={page >= pageInfo.lastPage || loading} onClick={() => setPage((current) => Math.min(pageInfo.lastPage, current + 1))}>التالي <ChevronLeft size={15}/></button></div></div>
      </section>
    </>}

    {view==="movements"&&<section className="card inventory-history-card">
      <div className="inventory-section-heading"><div><span className="inventory-page-eyebrow">STOCK MOVEMENT LEDGER</span><h2>دفتر حركات المخزون</h2><p>راجع الاستلام والصرف والمرتجعات والتسويات وتحويلات المخازن، مع مستند المصدر والسبب والمستخدم.</p></div><button type="button" className="secondary" onClick={()=>setRefreshTick(value=>value+1)}><RefreshCw size={13}/> تحديث</button></div>
      <div className="inventory-history-filters"><label className="inventory-history-search"><Search size={15}/><input value={movementSearch} onChange={event=>{setMovementSearch(event.target.value);setMovementCurrentPage(1)}} placeholder="الصنف / SKU / رقم المستند / الموقع..."/><button type="button" onClick={()=>setMovementSearch("")} disabled={!movementSearch}>×</button></label><label>نوع الحركة<select value={movementType} onChange={event=>{setMovementType(event.target.value);setMovementCurrentPage(1)}}><option value="">كل الحركات</option>{Object.entries(movementTypeLabels).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label><label>من تاريخ<input type="date" value={fromDate} onChange={event=>{setFromDate(event.target.value);setMovementCurrentPage(1)}}/></label><label>إلى تاريخ<input type="date" value={toDate} onChange={event=>{setToDate(event.target.value);setMovementCurrentPage(1)}}/></label></div>
      <div className="inventory-table-toolbar"><span>الحركات {movementPage.from.toLocaleString("ar-EG")}–{movementPage.to.toLocaleString("ar-EG")} من {movementPage.total.toLocaleString("ar-EG")}</span><button type="button" className="secondary" onClick={()=>{setMovementSearch("");setMovementType("");setFromDate("");setToDate("");setMovementCurrentPage(1)}}>مسح فلاتر الحركات</button></div>
      <div className="table-wrap"><table><thead><tr><th>التاريخ والوقت</th><th>الصنف</th><th>نوع الحركة</th><th>الموقع</th><th>تغير الكمية</th><th>تكلفة الوحدة</th><th>المستند / السبب</th><th>المستخدم</th></tr></thead><tbody>{movementRows.map(row=>{const qty=Number(row.quantity_base)||0;return <tr key={row.id}><td><span className="inventory-date-cell">{new Date(row.occurred_at).toLocaleDateString("ar-EG")}<small>{new Date(row.occurred_at).toLocaleTimeString("ar-EG",{hour:"2-digit",minute:"2-digit"})}</small></span></td><td><strong>{row.name_ar}</strong><small className="inventory-secondary-name">{row.sku}</small></td><td><span className={`inventory-movement-chip ${qty>0?"in":"out"}`}>{movementTypeLabels[row.movement_type]||row.movement_type}</span></td><td>{row.location_name}</td><td><strong className={qty>0?"ledger-paid":"ledger-due"}>{qty>0?"+":""}{qty.toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong></td><td>{money(Number(row.unit_cost)||0)}</td><td><strong>{row.reference||row.source_document_type||"—"}</strong>{row.source_document_id&&<small className="inventory-secondary-name">{row.source_document_id}</small>}</td><td>{row.created_by_name||"النظام"}</td></tr>})}{!movementRows.length&&<tr><td colSpan={8}><div className="inventory-no-results"><History size={25}/><strong>لا توجد حركات مطابقة</strong><span>غيّر فترة التاريخ أو فلاتر الحركة.</span></div></td></tr>}</tbody></table></div>
      <div className="pos-pagination"><span>صفحة {movementCurrentPage.toLocaleString("ar-EG")} من {Math.max(1,movementPage.lastPage).toLocaleString("ar-EG")}</span><div><button type="button" disabled={movementCurrentPage<=1} onClick={()=>setMovementCurrentPage(current=>Math.max(1,current-1))}><ChevronRight size={15}/> السابق</button><button type="button" disabled={movementCurrentPage>=movementPage.lastPage} onClick={()=>setMovementCurrentPage(current=>Math.min(movementPage.lastPage,current+1))}>التالي <ChevronLeft size={15}/></button></div></div>
    </section>}

    {view==="transfer"&&<section className="card inventory-transfer-card">
      <div className="inventory-section-heading"><div><span className="inventory-page-eyebrow">INTERNAL STOCK TRANSFER</span><h2>تحويل بضاعة بين المخازن والعربيات</h2><p>التحويل ينقص رصيد المصدر ويزيد رصيد الوجهة بنفس الكمية، ويحافظ على متوسط التكلفة وتتبع مصدر الشراء.</p></div><span className="inventory-transfer-icon"><ArrowLeftRight size={21}/></span></div>
      <div className="inventory-transfer-locations"><label>مخزن الصرف<select value={transferFrom} onChange={event=>{setTransferFrom(event.target.value);setTransferLines([]);if(event.target.value===transferTo)setTransferTo(locations.find(row=>row.id!==event.target.value)?.id||"");setTransferMessage("")}}><option value="">اختار مخزن الصرف</option>{locations.map(location=><option key={location.id} value={location.id}>{location.name} — {location.code}</option>)}</select><small>الكمية المتاحة من هذا الموقع فقط</small></label><span className="inventory-transfer-arrow"><ArrowLeftRight size={18}/></span><label>مخزن الاستلام<select value={transferTo} onChange={event=>{setTransferTo(event.target.value);setTransferMessage("")}}><option value="">اختار مخزن الاستلام</option>{locations.filter(location=>location.id!==transferFrom).map(location=><option key={location.id} value={location.id}>{location.name} — {location.code}</option>)}</select><small>سيتم زيادة الرصيد في هذا الموقع</small></label></div>
      <div className="inventory-transfer-builder"><div className="inventory-transfer-catalog"><div className="inventory-section-heading small"><div><h3>أضف الأصناف للتحويل</h3><p>{transferFrom?`الرصيد الظاهر خاص بـ ${locations.find(location=>location.id===transferFrom)?.name||"مخزن الصرف"}`:"اختار مخزن الصرف أولًا"}</p></div></div><label className="inventory-history-search"><Search size={15}/><input value={transferSearch} onChange={event=>setTransferSearch(event.target.value)} placeholder="ابحث باسم الصنف أو SKU..."/><button type="button" onClick={()=>setTransferSearch("")} disabled={!transferSearch}>×</button></label><div className="inventory-transfer-products">{transferProducts.filter(product=>Number(product.quantity_base)>0).slice(0,60).map(product=><div className="inventory-transfer-product" key={product.id}><span className="inventory-transfer-product-icon"><Package size={16}/></span><span className="inventory-transfer-product-copy"><strong>{product.name_ar}</strong><small>{product.sku}</small><small>متاح: {Number(product.quantity_base).toLocaleString("ar-EG",{maximumFractionDigits:3})} {product.base_unit_name||"وحدة"}</small></span><button type="button" disabled={!transferFrom||!transferTo||transferFrom===transferTo||transferLines.some(line=>line.product.id===product.id)} onClick={()=>addTransferProduct(product)}><Plus size={14}/> إضافة</button></div>)}{!transferProducts.filter(product=>Number(product.quantity_base)>0).length&&<div className="inventory-no-results"><Package size={22}/><strong>لا توجد أصناف متاحة</strong><span>اختار مخزن الصرف أو سجّل رصيدًا من فاتورة شراء.</span></div>}</div></div>
       <div className="inventory-transfer-cart"><div className="inventory-section-heading small"><div><h3>مستند التحويل</h3><p>{transferLines.length} صنف في المستند</p></div><span className="badge blue">{transferLines.reduce((sum,line)=>sum+Math.max(0,Number(line.quantity)||0),0).toLocaleString("ar-EG",{maximumFractionDigits:3})} وحدة</span></div>{!transferLines.length&&<div className="inventory-transfer-empty"><ArrowLeftRight size={25}/><strong>قائمة التحويل فارغة</strong><span>أضف الأصناف من القائمة المجاورة.</span></div>}{transferLines.map((line,index)=>{const max=Number(line.product.quantity_base)||0;const qty=Number(line.quantity)||0;return <div className="inventory-transfer-line" key={line.product.id}><div><strong>{line.product.name_ar}</strong><small>المتاح: {max.toLocaleString("ar-EG",{maximumFractionDigits:3})}</small></div><label>الكمية<input aria-label={`كمية تحويل ${line.product.name_ar}`} type="number" min="0.001" max={max} step="any" value={line.quantity} onChange={event=>setTransferLines(current=>current.map((item,i)=>i===index?{...item,quantity:event.target.value}:item))}/></label><button type="button" aria-label={`حذف ${line.product.name_ar} من التحويل`} onClick={()=>setTransferLines(current=>current.filter(item=>item.product.id!==line.product.id))}><X size={15}/></button>{qty>max&&<small className="inventory-transfer-line-error">الكمية أكبر من الرصيد المتاح</small>}</div>})}<label className="inventory-transfer-reason">سبب التحويل<input value={transferReason} onChange={event=>setTransferReason(event.target.value)} maxLength={255} placeholder="مثال: إعادة توزيع البضاعة بين المخازن"/></label>{transferMessage&&<div className={`inventory-transfer-message ${transferMessage.startsWith("تم تسجيل")?"success":"error"}`} role="status">{transferMessage}</div>}<button type="button" className="primary inventory-post-transfer" disabled={transferSaving||!transferLines.length||!transferFrom||!transferTo||transferFrom===transferTo||!transferReason.trim()||transferLines.some(line=>!Number.isFinite(Number(line.quantity))||Number(line.quantity)<=0||Number(line.quantity)>Number(line.product.quantity_base))} onClick={()=>void postTransfer()}><ArrowLeftRight size={16}/>{transferSaving?"جاري ترحيل التحويل...":"مراجعة وترحيل التحويل"}</button><small className="inventory-transfer-footnote">كل تحويل ينشئ مستندًا وحركتي مخزون مرتبطتين، ولا يغيّر قيمة مخزون المؤسسة الإجمالية.</small></div></div>
    </section>}

    {view==="stocktakes"&&<section className="card inventory-stocktakes-card">
      <div className="inventory-section-heading"><div><span className="inventory-page-eyebrow">VEHICLE STOCKTAKE REVIEW</span><h2>مراجعة جرد العربيات</h2><p>راجع العدد الفعلي اللي سجله المندوب، واعتمد الفروقات أو ارفضها بسبب واضح. الاعتماد لا ينجح لو رصيد الصنف اتغير بعد الجرد.</p></div><span className="inventory-transfer-icon"><ClipboardCheck size={21}/></span></div>
      <div className="inventory-stocktakes-toolbar"><label className="inventory-history-search"><Search size={15}/><input value={stocktakeSearch} onChange={event=>{setStocktakeSearch(event.target.value);setStocktakePage(1)}} placeholder="ابحث برقم الرحلة أو العربية أو اسم المندوب..."/><button type="button" onClick={()=>setStocktakeSearch("")} disabled={!stocktakeSearch}>×</button></label><label>حالة الجرد<select value={stocktakeStatus} onChange={event=>{setStocktakeStatus(event.target.value as typeof stocktakeStatus);setStocktakePage(1);setStocktakeMessage("")}}><option value="submitted">بانتظار المراجعة</option><option value="approved">معتمد</option><option value="rejected">مرفوض</option><option value="all">كل الحالات</option></select></label><button type="button" className="secondary" onClick={()=>setRefreshTick(value=>value+1)}><RefreshCw size={13}/> تحديث</button></div>
      {stocktakeMessage&&<div className="pos-alert success" role="status" aria-live="polite">{stocktakeMessage}</div>}
      {stocktakeError&&<div className="pos-alert error" role="alert">{stocktakeError}</div>}
      <div className="inventory-stocktakes-count">الجرد {stocktakePageInfo.from.toLocaleString("ar-EG")}–{stocktakePageInfo.to.toLocaleString("ar-EG")} من {stocktakePageInfo.total.toLocaleString("ar-EG")}</div>
      <div className="inventory-stocktake-list">{stocktakes.map(stocktake=>{
        const varianceItems=stocktake.items.filter(item=>Math.abs(Number(item.variance_quantity_base)||0)>0.000001);
        const statusLabel=stocktake.status==="submitted"?"بانتظار المراجعة":stocktake.status==="approved"?"معتمد":"مرفوض";
        const differenceTotal=varianceItems.reduce((sum,item)=>sum+Number(item.variance_quantity_base||0),0);
        return <article className="inventory-stocktake-card" key={stocktake.id}>
          <div className="inventory-stocktake-head"><div className="inventory-stocktake-avatar"><Truck size={20}/></div><div className="inventory-stocktake-title"><strong>{stocktake.trip_display_name||stocktake.trip_number}</strong><small>{stocktake.trip_display_name?`رقم الرحلة ${stocktake.trip_number} · `:""}{stocktake.vehicle_name} ({stocktake.vehicle_code})</small><small>بواسطة {stocktake.submitted_by_name} · {stocktake.submitted_at?new Date(stocktake.submitted_at).toLocaleString("ar-EG"):"وقت الإرسال غير مسجل"}</small></div><span className={`inventory-stocktake-status ${stocktake.status}`}>{statusLabel}</span></div>
          <div className="inventory-stocktake-metrics"><div><small>أصناف تمت مراجعتها</small><strong>{Number(stocktake.item_count).toLocaleString("ar-EG")}</strong></div><div><small>أصناف بها فرق</small><strong className={varianceItems.length?"inventory-stocktake-warning":""}>{varianceItems.length.toLocaleString("ar-EG")}</strong></div><div><small>صافي فرق الكمية</small><strong className={Math.abs(differenceTotal)>0.000001?"inventory-stocktake-warning":""}>{differenceTotal>0?"+":""}{differenceTotal.toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong></div></div>
          {stocktake.notes&&<div className="inventory-stocktake-rep-notes"><strong>ملاحظة المندوب:</strong> {stocktake.notes}</div>}
          <div className="inventory-stocktake-items-wrap"><table><thead><tr><th>الصنف</th><th>المتوقع وقت الجرد</th><th>الفعلي</th><th>الفرق</th></tr></thead><tbody>{stocktake.items.map(item=>{const variance=Number(item.variance_quantity_base)||0;return <tr key={item.product_id}><td><strong>{item.product_name}</strong><small>{item.sku}</small></td><td>{Number(item.expected_quantity_base).toLocaleString("ar-EG",{maximumFractionDigits:3})} {item.base_unit_name||"وحدة"}</td><td>{Number(item.counted_quantity_base).toLocaleString("ar-EG",{maximumFractionDigits:3})}</td><td><strong className={variance>0?"ledger-paid":variance<0?"ledger-due":""}>{variance>0?"+":""}{variance.toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong></td></tr>})}</tbody></table></div>
          {stocktake.status === "submitted" ? (
            <>
              <label className="inventory-stocktake-review-notes">
                ملاحظة المراجع / سبب الرفض
                <textarea
                  rows={2}
                  value={stocktakeReviewNotes[stocktake.id] || ""}
                  onChange={(event) => setStocktakeReviewNotes((current) => ({ ...current, [stocktake.id]: event.target.value }))}
                  maxLength={2000}
                  placeholder="اكتب سبب الرفض أو ملاحظة للمراجعة (مطلوبة عند الرفض)..."
                />
              </label>
              <div className="inventory-stocktake-actions">
                <button type="button" className="inventory-stocktake-reject" disabled={stocktakeBusyId === stocktake.id} onClick={() => void reviewStocktake(stocktake, "reject")}>
                  <X size={15}/>{stocktakeBusyId === stocktake.id ? "جاري الحفظ..." : "رفض الجرد"}
                </button>
                <button type="button" className="inventory-stocktake-approve" disabled={stocktakeBusyId === stocktake.id} onClick={() => void reviewStocktake(stocktake, "approve")}>
                  <CheckCircle2 size={15}/>{stocktakeBusyId === stocktake.id ? "جاري الاعتماد..." : "اعتماد الجرد وترحيل الفروقات"}
                </button>
              </div>
            </>
          ) : (
            <div className={`inventory-stocktake-final ${stocktake.status}`}>
              <div>
                <strong>{stocktake.status === "approved" ? "تم اعتماد الجرد" : "تم رفض الجرد"}</strong>
                <small>{stocktake.reviewed_by_name || "المراجع"}{stocktake.reviewed_at ? ` · ${new Date(stocktake.reviewed_at).toLocaleString("ar-EG")}` : ""}</small>
                {stocktake.review_notes && <span>{stocktake.review_notes}</span>}
              </div>
              <small>{stocktake.status === "approved" ? `تم ترحيل ${varianceItems.length.toLocaleString("ar-EG")} فرق مخزني (إن وُجد).` : "لم تتغير أرصدة المخزون."}</small>
            </div>
          )}
        </article>;
      })}{!stocktakes.length&&!stocktakeError&&<div className="inventory-no-results"><ClipboardCheck size={26}/><strong>{stocktakeStatus==="submitted"?"مفيش جرد في انتظار المراجعة":"مفيش سجلات مطابقة"}</strong><span>{stocktakeStatus==="submitted"?"لما المندوب يرسل جرد العربية، هتظهر تفاصيله هنا للمراجعة.":"جرّب حالة مختلفة أو امسح البحث."}</span></div>}</div>
      <div className="pos-pagination"><span>صفحة {stocktakePage.toLocaleString("ar-EG")} من {Math.max(1,stocktakePageInfo.lastPage).toLocaleString("ar-EG")}</span><div><button type="button" disabled={stocktakePage<=1} onClick={()=>setStocktakePage(current=>Math.max(1,current-1))}><ChevronRight size={15}/> السابق</button><button type="button" disabled={stocktakePage>=stocktakePageInfo.lastPage} onClick={()=>setStocktakePage(current=>Math.min(stocktakePageInfo.lastPage,current+1))}>التالي <ChevronLeft size={15}/></button></div></div>
    </section>}
  </main>;
}
