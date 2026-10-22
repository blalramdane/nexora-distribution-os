"use client";

import { useEffect,useMemo,useState } from "react";
import { Plus, Route, Truck, Users, PlayCircle, MapPinned, ArrowUp, ArrowDown, WandSparkles, RefreshCw, CheckCircle2, Search, PackageCheck, Banknote, ClipboardList, Copy, UserPlus, Link2, Pencil, Save, Trash2, FileDown, FileSpreadsheet } from "lucide-react";
import { api } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";
import { createXlsxWorkbook, type XlsxCell, type XlsxSheet } from "@/lib/xlsx-export.mjs";
import { buildReportPrintHtml, type PrintCell, type PrintTable } from "@/lib/report-print.mjs";

type Vehicle={id:string;code:string;name:string;plate_number?:string};
type Location={id:string;name:string;code:string;type?:string};
type Customer={id:string;name:string;code:string;phone?:string};
type Product={id:string;sku:string;name_ar:string;default_cost:number;quantity_base?:number;base_unit_name?:string|null;stock_value?:number};
type LoadLine={product:Product;quantity:number};
type ClosingLine={product_id:string;name_ar:string;quantity:number};
type Trip={id:string;trip_number:string;display_name?:string|null;archived_at?:string|null;status:string;trip_date:string;vehicle_name:string;rep_name:string;origin_location_id?:string;vehicle_location_id?:string};
type Me={id:string;name:string;organization_id?:string};
type FieldRep={id:string;organization_id?:string;name:string;email?:string|null;phone?:string|null;status:string};
type RouteCustomer={customer_id:string;sequence:number;visit_status:string;code:string;name:string;phone?:string;address_text?:string;primary_address?:string;latitude?:number|null;longitude?:number|null};
type FinancialAccount={id:string;code:string;name:string;currency?:string};
type TripExpense={id:string;category:string;amount:string|number;expense_date:string;notes?:string|null;financial_account_name:string};
type TripProfit={trip_number:string;currency:string;sales_count:number;sales_total:number;returns_count:number;returns_total:number;net_sales:number;cost_of_goods_sold:number;returned_cost:number;net_cost_of_goods_sold:number;gross_profit:number;trip_expenses:number;net_trip_profit:number;variance_policy_note:string;costing_note:string};
type TripSupplierSettlement={supplier_id:string|null;supplier_name:string;sold_quantity_base:number;returned_quantity_base:number;net_quantity_base:number;sold_source_cost:number;returned_source_cost:number;settlement_amount:number;items:{product_id:string;product_name:string;sold_quantity_base:number;returned_quantity_base:number;net_quantity_base:number;sold_source_cost:number;returned_source_cost:number;settlement_amount:number}[]};
type TripDetailedReport={trip:{id:string;trip_number:string;status:string;trip_date:string;vehicle_name:string;plate_number?:string|null;rep_name:string;currency:string};summary:{loaded_value:number;sales_total:number;sales_cash:number;sales_non_cash:number;credit_at_sale:number;returns_total:number;net_sales:number;collections_after_sale:number;cash_collections_after_sale:number;non_cash_collections_after_sale:number;receivables_open_on_trip_invoices:number;trip_expenses:number;other_income:number;other_cash_outflow:number;opening_cash:number;expected_cash:number;actual_cash:number|null;cash_variance:number|null;stock_variance_value:number|null;customer_count:number;invoice_count:number};opening_items:{product_id:string;sku:string;name_ar:string;quantity_base:number|string;unit_cost:number|string}[];stock_reconciliation:{product_id:string;sku:string;name_ar:string;opening_quantity_base:number|string;loaded_quantity_base:number|string;sold_quantity_base:number|string;returned_quantity_base:number|string;transferred_quantity_base:number|string;adjustment_quantity_base:number|string;expected_closing_quantity_base:number|string;actual_closing_quantity_base:number|string;variance_quantity_base:number|string}[];loaded_items:{product_id:string;sku:string;name_ar:string;quantity_base:number|string;loaded_cost:number|string}[];sold_items:{product_id:string;sku:string;name_ar:string;sold_quantity_base:number|string;sales_value:number|string;cost_value:number|string}[];sales_invoices:{id:string;document_number:string;invoice_date:string;total:number|string;paid_amount:number|string;paid_at_sale:number|string;cash_at_sale:number|string;non_cash_at_sale:number|string;credit_at_sale:number|string;balance_due:number|string;currency:string;customer_id:string;customer_name:string;customer_code:string}[];sales_returns:{id:string;document_number:string;return_date:string;total:number|string;currency:string;customer_name:string}[];customer_collections:{id:string;date:string;customer_name:string;amount:number|string;allocated_to_trip:number|string;cash_allocated_to_trip:number|string;non_cash_allocated_to_trip:number|string;financial_account_name?:string|null;payment_method_name?:string|null;is_cash:boolean;reference?:string|null}[];expenses:{id:string;category:string;amount:number|string;expense_date:string;notes?:string|null;account_name?:string|null;vehicle_name?:string|null}[];other_cash_movements:{id:string;transaction_type:string;amount:number|string;transaction_date:string;category:string;beneficiary_name_snapshot?:string|null;reference?:string|null}[];supplier_settlements:unknown[];settlement: {opening_cash:number|string;expected_cash:number|string;actual_cash:number|string;cash_variance:number|string;stock_variance_value:number|string;opening_stock_basis?:"snapshot"|"legacy_zero"}|null};

function buildTripReportExport(report:TripDetailedReport,suppliers:TripSupplierSettlement[]){
 const n=(value:number|string|null|undefined):number=>Number(value??0);
 const money=(value:number|string|null|undefined):number=>n(value);
 const metaRows:[string,PrintCell][]=[
  ["رقم الرحلة",report.trip.trip_number],["حالة الرحلة",report.trip.status],["تاريخ الرحلة",report.trip.trip_date],
  ["العربية",`${report.trip.vehicle_name}${report.trip.plate_number?` (${report.trip.plate_number})`:""}`],["المندوب",report.trip.rep_name],["العملة",report.trip.currency],
  ["قيمة بضاعة التحميل بالتكلفة",money(report.summary.loaded_value)],["إجمالي المبيعات",money(report.summary.sales_total)],
  ["نقدي وقت البيع",money(report.summary.sales_cash)],["غير نقدي وقت البيع",money(report.summary.sales_non_cash)],
  ["الآجل وقت الإصدار",money(report.summary.credit_at_sale)],["مرتجعات المبيعات",money(report.summary.returns_total)],
  ["صافي المبيعات",money(report.summary.net_sales)],["تحصيلات نقدية بعد البيع",money(report.summary.cash_collections_after_sale)],
  ["تحصيلات غير نقدية بعد البيع",money(report.summary.non_cash_collections_after_sale)],["رصيد العملاء على فواتير الرحلة",money(report.summary.receivables_open_on_trip_invoices)],
  ["مصروفات الرحلة",money(report.summary.trip_expenses)],["مسحوبات/حركات نقدية أخرى",money(report.summary.other_cash_outflow)],
  ["النقدية الافتتاحية",money(report.summary.opening_cash)],["النقدية المتوقعة",money(report.summary.expected_cash)],
  ["النقدية الفعلية",report.summary.actual_cash===null?"لم يتم الإقفال":money(report.summary.actual_cash)],
  ["فرق النقدية",report.summary.cash_variance===null?"لم يتم الإقفال":money(report.summary.cash_variance)],
  ["فرق قيمة المخزون",report.summary.stock_variance_value===null?"لم يتم الإقفال":money(report.summary.stock_variance_value)],
  ["عدد الفواتير",report.summary.invoice_count],["عدد العملاء",report.summary.customer_count],
  ["أساس افتتاح المخزون",report.settlement?.opening_stock_basis==="snapshot"?"لقطة افتتاحية موثقة":report.settlement?"رحلة قديمة بدون لقطة افتتاحية موثقة":"لم يتم الإقفال"],
 ];
 const tables:PrintTable[]=[
  {title:"مطابقة مخزون الإقفال",headers:["الصنف","SKU","افتتاحي","تحميل","مباع","مرتجع","تحويل صافي","تسوية صافي","متوقع","فعلي","فرق"],rows:report.stock_reconciliation.map(r=>[r.name_ar,r.sku,n(r.opening_quantity_base),n(r.loaded_quantity_base),Math.abs(n(r.sold_quantity_base)),n(r.returned_quantity_base),n(r.transferred_quantity_base),n(r.adjustment_quantity_base),n(r.expected_closing_quantity_base),n(r.actual_closing_quantity_base),n(r.variance_quantity_base)])},
  {title:"مخزون افتتاح الرحلة",headers:["الصنف","SKU","الكمية","تكلفة الوحدة"],rows:report.opening_items.map(r=>[r.name_ar,r.sku,n(r.quantity_base),n(r.unit_cost)])},
  {title:"بضاعة التحميل",headers:["الصنف","SKU","الكمية المحملة","قيمة التكلفة"],rows:report.loaded_items.map(r=>[r.name_ar,r.sku,n(r.quantity_base),n(r.loaded_cost)])},
  {title:"فواتير البيع",headers:["التاريخ","رقم الفاتورة","كود العميل","اسم العميل","الإجمالي","نقدي وقت البيع","غير نقدي وقت البيع","آجل عند الإصدار","المتبقي الحالي"],rows:report.sales_invoices.map(r=>[r.invoice_date,r.document_number,r.customer_code,r.customer_name,n(r.total),n(r.cash_at_sale),n(r.non_cash_at_sale),n(r.credit_at_sale),n(r.balance_due)])},
  {title:"مديونية فواتير الرحلة",headers:["كود العميل","اسم العميل","رقم الفاتورة","التاريخ","الإجمالي","المدفوع وقت البيع","المتبقي الحالي"],rows:report.sales_invoices.filter(r=>n(r.balance_due)>0).map(r=>[r.customer_code,r.customer_name,r.document_number,r.invoice_date,n(r.total),n(r.paid_at_sale),n(r.balance_due)])},
  {title:"الأصناف المباعة",headers:["الصنف","SKU","الكمية المباعة","قيمة البيع","تكلفة البضاعة","مجمل الربح"],rows:report.sold_items.map(r=>[r.name_ar,r.sku,n(r.sold_quantity_base),n(r.sales_value),n(r.cost_value),n(r.sales_value)-n(r.cost_value)])},
  {title:"مرتجعات العملاء",headers:["التاريخ","رقم المرتجع","العميل","الإجمالي","العملة"],rows:report.sales_returns.map(r=>[r.return_date,r.document_number,r.customer_name,n(r.total),r.currency])},
  {title:"التحصيلات",headers:["التاريخ","العميل","طريقة الدفع","الحساب","قيمة الإيصال","المخصص لفواتير الرحلة","نقدي مخصص","غير نقدي مخصص","نوع العهدة","المرجع"],rows:report.customer_collections.map(r=>[r.date,r.customer_name,r.payment_method_name||"—",r.financial_account_name||"—",n(r.amount),n(r.allocated_to_trip),n(r.cash_allocated_to_trip),n(r.non_cash_allocated_to_trip),r.is_cash?"نقدي":"غير نقدي",r.reference||""])},
  {title:"المصروفات والحركات",headers:["النوع","التاريخ","البند","الحساب أو المستلم","المبلغ","ملاحظات أو مرجع"],rows:[...report.expenses.map(r=>["مصروف",r.expense_date,r.category,r.account_name||r.vehicle_name||"—",n(r.amount),r.notes||""] as XlsxCell[]),...report.other_cash_movements.map(r=>[r.transaction_type,r.transaction_date,r.category,r.beneficiary_name_snapshot||"الخزنة العامة",n(r.amount),r.reference||""] as XlsxCell[])]},
  {title:"تسوية الموردين",headers:["المورد","كمية مباعة","كمية مرتجعة","صافي الكمية","تكلفة المصدر المباعة","تكلفة المصدر المرتجعة","صافي تكلفة المصدر"],rows:suppliers.map(r=>[r.supplier_name,r.sold_quantity_base,r.returned_quantity_base,r.net_quantity_base,r.sold_source_cost,r.returned_source_cost,r.settlement_amount])},
  {title:"بنود الموردين",headers:["المورد","الصنف","المباع","المرتجع","الصافي","تكلفة المباع","تكلفة المرتجع","صافي تكلفة المصدر"],rows:suppliers.flatMap(r=>r.items.map(i=>[r.supplier_name,i.product_name,i.sold_quantity_base,i.returned_quantity_base,i.net_quantity_base,i.sold_source_cost,i.returned_source_cost,i.settlement_amount]))},
 ];
 const summaryRows:XlsxCell[][]=[["البند","القيمة"],...metaRows.map(([label,value])=>[label,value])];
 const sheets:XlsxSheet[]=[
  {name:"ملخص الرحلة",rows:summaryRows},
  ...tables.map(t=>({name:t.title,rows:[[...t.headers],...t.rows]})),
 ];
 const notes=[
  "الأرقام مبنية على المستندات المرحلة والبيانات الحالية وقت إنشاء التقرير.",
  "فرق المخزون والنقدية معروض للمراجعة؛ لا يعني هذا التقرير ترحيل تسوية محاسبية تلقائيًا.",
  "قيمة تسوية المورد تمثل تكلفة مصدر البضاعة حسب سياسة النظام، وليست سند دفع للمورد ولا تعدل مديونية فواتير الشراء تلقائيًا.",
  report.settlement?.opening_stock_basis==="legacy_zero"?"تنبيه: الرحلة لا تحتوي على لقطة افتتاحية موثقة؛ لا يتم تخمين رصيد البداية.":"",
 ].filter(Boolean);
 const printHtml=buildReportPrintHtml({title:"تقرير تسوية رحلة — NEXORA Distribution OS",subtitle:`${report.trip.trip_number} · ${report.trip.vehicle_name} · ${report.trip.rep_name} · ${report.trip.trip_date}`,summaryRows:metaRows,tables,notes});
 return {sheets,printHtml,notes};
}

export default function Trips(){
 const [tripName,setTripName]=useState(""),[renameTripName,setRenameTripName]=useState(""),[renamingTrip,setRenamingTrip]=useState(false),[savingOrigin,setSavingOrigin]=useState(false),[deletingTrip,setDeletingTrip]=useState(false);
 const [products,setProducts]=useState<Product[]>([]),[loadLines,setLoadLines]=useState<LoadLine[]>([]),[closingLines,setClosingLines]=useState<ClosingLine[]>([]),[actualCash,setActualCash]=useState(""),[openingCash,setOpeningCash]=useState("0"),[loadMsg,setLoadMsg]=useState(""),[loadQuery,setLoadQuery]=useState(""),[vehicles,setVehicles]=useState<Vehicle[]>([]),[locations,setLocations]=useState<Location[]>([]),[customers,setCustomers]=useState<Customer[]>([]),[trips,setTrips]=useState<Trip[]>([]),[vehicle,setVehicle]=useState(""),[location,setLocation]=useState(""),[customer,setCustomer]=useState(""),[selected,setSelected]=useState<Trip|null>(null),[me,setMe]=useState<Me|null>(null),[msg,setMsg]=useState(""),[routeCustomers,setRouteCustomers]=useState<RouteCustomer[]>([]),[routeMsg,setRouteMsg]=useState(""),[loadingError,setLoadingError]=useState(""),[creating,setCreating]=useState(false),[loadingStock,setLoadingStock]=useState(false),[settling,setSettling]=useState(false),[assigning,setAssigning]=useState(false);
 const [expenseAccounts,setExpenseAccounts]=useState<FinancialAccount[]>([]),[tripExpenses,setTripExpenses]=useState<TripExpense[]>([]),[expenseAccountId,setExpenseAccountId]=useState(""),[expenseCategory,setExpenseCategory]=useState("وقود"),[expenseAmount,setExpenseAmount]=useState(""),[expenseDate,setExpenseDate]=useState(()=>new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10)),[expenseNotes,setExpenseNotes]=useState(""),[expenseMsg,setExpenseMsg]=useState(""),[expenseSaving,setExpenseSaving]=useState(false);
 const [tripProfit,setTripProfit]=useState<TripProfit|null>(null),[profitMsg,setProfitMsg]=useState(""),[profitLoading,setProfitLoading]=useState(false);
 const [tripDetailedReport,setTripDetailedReport]=useState<TripDetailedReport|null>(null),[tripReportMsg,setTripReportMsg]=useState(""),[tripReportLoading,setTripReportLoading]=useState(false),[exportingReport,setExportingReport]=useState<"pdf"|"xlsx"|null>(null),[exportMsg,setExportMsg]=useState("");
 const [supplierSettlements,setSupplierSettlements]=useState<TripSupplierSettlement[]>([]),[supplierSettlementNote,setSupplierSettlementNote]=useState(""),[supplierSettlementLoading,setSupplierSettlementLoading]=useState(false);
 const [fieldReps,setFieldReps]=useState<FieldRep[]>([]),[selectedRepId,setSelectedRepId]=useState(""),[repName,setRepName]=useState(""),[repEmail,setRepEmail]=useState(""),[repPhone,setRepPhone]=useState(""),[repPassword,setRepPassword]=useState(""),[creatingRep,setCreatingRep]=useState(false),[repMsg,setRepMsg]=useState(""),[fieldLinkCopied,setFieldLinkCopied]=useState(false);
 const [createdRepCredentials,setCreatedRepCredentials]=useState<{name:string;organization_id:string;login:string;password:string}|null>(null);
 const [activeStep,setActiveStep]=useState<"load"|"visits"|"expenses"|"settlement">("load"),[tripSearch,setTripSearch]=useState(""),[tripStatusFilter,setTripStatusFilter]=useState("all"),[customerQuery,setCustomerQuery]=useState(""),[customerResults,setCustomerResults]=useState<Customer[]>([]),[productSearchError,setProductSearchError]=useState(""),[availableOnly,setAvailableOnly]=useState(false),[cleaningTrips,setCleaningTrips]=useState(false),[showArchivedTrips,setShowArchivedTrips]=useState(false),[restoringTripId,setRestoringTripId]=useState<string|null>(null);
 const load=()=>api<Trip[]>(showArchivedTrips?"/trips?archived_only=true":"/trips").then(setTrips).catch(e=>setLoadingError((e as Error).message));
 const loadRoute=(tripId:string)=>api<RouteCustomer[]>(`/trips/${tripId}/route`).then(setRouteCustomers).catch(e=>setRouteMsg((e as Error).message));

 useEffect(()=>{
  api<Me & {roles?:{key:string}[]}>("/auth/me").then(m=>{
   if(m.roles?.some(role=>role.key==="field_rep")){window.location.replace("/field");return;}
   setMe(m);
   return Promise.all([api<Vehicle[]>("/vehicles"),api<Location[]>("/locations"),api<Customer[]>("/customers"),api<Product[]>("/products"),api<Trip[]>("/trips"),api<FieldRep[]>("/field/reps")]).then(([v,l,c,p,t,r])=>{const warehouses=l.filter(row=>row.type==="warehouse");setVehicles(v);setVehicle(v[0]?.id||"");setLocations(l);setLocation(warehouses[0]?.id||"");setCustomers(c);setProducts(p);setTrips(t);setFieldReps(r);setSelectedRepId(current=>current||r[0]?.id||"");setLoadingError("")});
  }).catch(e=>setLoadingError((e as Error).message));
 },[]);

 useEffect(()=>{api<{accounts:FinancialAccount[];payment_methods:unknown[] }>("/finance/references").then(ref=>{setExpenseAccounts(ref.accounts);setExpenseAccountId(current=>current||ref.accounts[0]?.id||"")}).catch(e=>setExpenseMsg((e as Error).message))},[]);

 const loadTripExpenses=(tripId:string)=>api<TripExpense[]>(`/expenses?trip_id=${encodeURIComponent(tripId)}`).then(setTripExpenses).catch(e=>setExpenseMsg((e as Error).message));
 const loadTripProfit=(tripId:string)=>{setProfitLoading(true);setProfitMsg("");api<TripProfit>(`/trips/${encodeURIComponent(tripId)}/profit`).then(setTripProfit).catch(e=>setProfitMsg((e as Error).message)).finally(()=>setProfitLoading(false))};
 const loadTripDetailedReport=(tripId:string)=>{setTripReportLoading(true);setTripReportMsg("");api<TripDetailedReport>(`/trips/${encodeURIComponent(tripId)}/report`).then(result=>{setTripDetailedReport(result);setOpeningCash(String(result.summary.opening_cash??0))}).catch(e=>setTripReportMsg((e as Error).message)).finally(()=>setTripReportLoading(false))};
 const loadTripSupplierSettlements=(tripId:string)=>{setSupplierSettlementLoading(true);api<{suppliers:TripSupplierSettlement[];note:string}>(`/trips/${encodeURIComponent(tripId)}/supplier-settlement`).then(result=>{setSupplierSettlements(result.suppliers||[]);setSupplierSettlementNote(result.note||"")}).catch(e=>{setSupplierSettlements([]);setSupplierSettlementNote((e as Error).message)}).finally(()=>setSupplierSettlementLoading(false))};
 const exportTripWorkbook=()=>{
  if(!tripDetailedReport||!selected)return;
  setExportingReport("xlsx");setExportMsg("");
  try{
   const model=buildTripReportExport(tripDetailedReport,supplierSettlements);
   const bytes=createXlsxWorkbook(model.sheets);
   const blob=new Blob([bytes.buffer.slice(bytes.byteOffset,bytes.byteOffset+bytes.byteLength) as ArrayBuffer],{type:"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"});
   const url=URL.createObjectURL(blob),link=document.createElement("a");
   const slug=tripDetailedReport.trip.trip_number.replace(/[^a-zA-Z0-9_-]+/g,"-")||"trip";
   link.href=url;link.download=`NEXORA-${slug}-report.xlsx`;document.body.appendChild(link);link.click();link.remove();window.setTimeout(()=>URL.revokeObjectURL(url),30000);
   setExportMsg("تم تجهيز ملف Excel متعدد الشيتات. راجع المديونية وفروق المخزون قبل اعتماد التسوية.");
  }catch(error){setExportMsg(error instanceof Error?error.message:"تعذر إنشاء ملف Excel.")}
  finally{setExportingReport(null)}
 };
 const exportTripPdf=async()=>{
  if(!tripDetailedReport||!selected)return;
  setExportingReport("pdf");setExportMsg("");
  let wrapper:HTMLDivElement|null=null;
  try{
   const model=buildTripReportExport(tripDetailedReport,supplierSettlements);
   const parsed=new DOMParser().parseFromString(model.printHtml,"text/html");
   wrapper=document.createElement("div");
   wrapper.style.cssText="position:absolute;left:-12000px;top:0;width:1120px;background:#fff;padding:18px;color:#142238;font-family:Tahoma,Arial,sans-serif;direction:rtl";
   const styles=Array.from(parsed.querySelectorAll("style")).map(style=>style.textContent||"").join("\n");
   wrapper.innerHTML=`<style>${styles}</style>${parsed.body.innerHTML}`;
   document.body.appendChild(wrapper);
   const html2pdfModule=await import("html2pdf.js");
   const slug=tripDetailedReport.trip.trip_number.replace(/[^a-zA-Z0-9_-]+/g,"-")||"trip";
   await html2pdfModule.default().set({margin:7,filename:`NEXORA-${slug}-report.pdf`,image:{type:"jpeg",quality:0.96},html2canvas:{scale:1.5,useCORS:true,logging:false,scrollX:0,scrollY:0},jsPDF:{unit:"mm",format:"a4",orientation:"landscape"}}).from(wrapper).save();
   setExportMsg("تم إنشاء نسخة PDF من بيانات التقرير الحالية.");
  }catch(error){setExportMsg(error instanceof Error?`تعذر إنشاء PDF: ${error.message}`:"تعذر إنشاء ملف PDF.")}
  finally{wrapper?.remove();setExportingReport(null)}
 };
 const warehouseLocations=locations.filter(row=>row.type==="warehouse");
 const selectTrip=(t:Trip)=>{setSelected(t);setRenameTripName(t.display_name||"");setActiveStep(t.status==="planned"?"load":t.status==="completed"?"settlement":"visits");setLocation(warehouseLocations.some(row=>row.id===t.origin_location_id)?(t.origin_location_id||""):(warehouseLocations[0]?.id||""));setCustomer("");setCustomerQuery("");setCustomerResults([]);setActualCash("");setOpeningCash("0");setLoadLines([]);loadRoute(t.id);refreshClosing(t.id);setExpenseMsg("");loadTripExpenses(t.id);loadTripProfit(t.id);loadTripDetailedReport(t.id);loadTripSupplierSettlements(t.id)};

 useEffect(()=>{
  const term=customerQuery.trim();
  if(term.length<2){setCustomerResults([]);return;}
  const timer=setTimeout(()=>api<Customer[]>(`/customers?q=${encodeURIComponent(term)}`).then(setCustomerResults).catch(e=>setMsg((e as Error).message)),220);
  return()=>clearTimeout(timer);
 },[customerQuery]);

 useEffect(()=>{
  if(!location)return;
  const term=loadQuery.trim();
  const timer=setTimeout(()=>{const params=new URLSearchParams({location_id:location});if(term)params.set("q",term);api<Product[]>(`/products?${params.toString()}`).then(setProducts).catch(e=>setProductSearchError((e as Error).message))},term?200:0);
  return()=>clearTimeout(timer);
 },[loadQuery,location]);

 const visibleTrips=useMemo(()=>trips.filter(t=>{
  const text=`${t.display_name||""} ${t.trip_number} ${t.vehicle_name} ${t.rep_name} ${t.trip_date}`.toLocaleLowerCase();
  const queryMatches=!tripSearch.trim()||text.includes(tripSearch.trim().toLocaleLowerCase());
  const statusMatches=tripStatusFilter==="all"||(tripStatusFilter==="active"?!["planned","completed","cancelled"].includes(t.status):t.status===tripStatusFilter);
  return queryMatches&&statusMatches;
 }),[trips,tripSearch,tripStatusFilter]);
 const steps=[
  {id:"load" as const,title:"تحميل العربية",caption:"اختيار الأصناف والكميات",icon:PackageCheck},
  {id:"visits" as const,title:"العملاء والزيارات",caption:"رتّب خط السير",icon:Users},
  {id:"expenses" as const,title:"المصروفات",caption:"سجّل مصروفات الرحلة",icon:ClipboardList},
  {id:"settlement" as const,title:"التسوية والإقفال",caption:"راجع الرصيد والنقدية",icon:Banknote},
 ];

 const refreshClosing=(tripId:string)=>api<{product_id:string;name_ar:string;quantity_base:number}[]>(`/trips/${tripId}/stock`).then(rows=>setClosingLines(rows.map(r=>({product_id:r.product_id,name_ar:r.name_ar,quantity:Number(r.quantity_base)})))).catch(()=>setClosingLines([]));

 const create=async()=>{
  if(loadingError)return setMsg("بيانات الرحلات لم تُحمّل: "+loadingError);
  if(!vehicle||!location||!selectedRepId)return setMsg("اختار العربية والمخزن والمندوب قبل إنشاء الرحلة.");
  setCreating(true);setMsg("");try{const t=await api<Trip>("/trips",{method:"POST",body:JSON.stringify({vehicle_id:vehicle,rep_user_id:selectedRepId,origin_location_id:location,display_name:tripName.trim()||null})});setMsg("تم إنشاء الرحلة وإسنادها للمندوب بنجاح.");setTripName("");await load();selectTrip(t)}catch(e){setMsg((e as Error).message)}finally{setCreating(false)}
 };

 const saveTripName=async()=>{
  if(!selected)return;
  setRenamingTrip(true);setMsg("");
  try{
   const result=await api<{display_name:string|null;message:string}>(`/trips/${encodeURIComponent(selected.id)}`,{method:"PATCH",body:JSON.stringify({display_name:renameTripName.trim()||null})});
   const updated={...selected,display_name:result.display_name};
   setSelected(updated);setTrips(current=>current.map(t=>t.id===updated.id?{...t,display_name:updated.display_name}:t));setMsg(result.message);
  }catch(error){setMsg(error instanceof Error?error.message:"تعذر تغيير مسمى الرحلة.")}
  finally{setRenamingTrip(false)}
 };

 const saveTripOrigin=async()=>{
  if(!selected||!location)return setLoadMsg("اختار مخزن انطلاق أولًا.");
  if(!warehouseLocations.some(row=>row.id===location))return setLoadMsg("اختار موقعًا من نوع مخزن، وليس موقع عربية.");
  setSavingOrigin(true);setLoadMsg("");
  try{
   const result=await api<{origin_location_id:string;message:string}>(`/trips/${encodeURIComponent(selected.id)}`,{method:"PATCH",body:JSON.stringify({origin_location_id:location})});
   setSelected(current=>current?{...current,origin_location_id:result.origin_location_id}:current);
   setTrips(current=>current.map(row=>row.id===selected.id?{...row,origin_location_id:result.origin_location_id}:row));
   setLoadMsg(result.message);setProductSearchError("");
   const params=new URLSearchParams({location_id:location});if(loadQuery.trim())params.set("q",loadQuery.trim());
   setProducts(await api<Product[]>(`/products?${params.toString()}`));
  }catch(error){setLoadMsg(error instanceof Error?error.message:"تعذر حفظ مخزن الانطلاق.")}finally{setSavingOrigin(false)}
 };

 const deleteTrip=async()=>{
  if(!selected)return;
  if(!window.confirm(`تأكيد إخفاء الرحلة «${selected.display_name||selected.trip_number}» من قائمة التشغيل؟ الفواتير وحركات المخزون والمدفوعات ستظل محفوظة، ويمكنك استعادتها من الأرشيف.`))return;
  setDeletingTrip(true);setMsg("");
  try{
   const result=await api<{message:string}>(`/trips/${encodeURIComponent(selected.id)}`,{method:"DELETE"});
   setMsg(result.message);setTrips(current=>current.filter(t=>t.id!==selected.id));setSelected(null);setTripDetailedReport(null);setTripProfit(null);setSupplierSettlements([]);
  }catch(error){setMsg(error instanceof Error?error.message:"تعذر حذف الرحلة. قد تكون مرتبطة بحركات مخزون أو حسابات.")}
  finally{setDeletingTrip(false)}
 };

 const createFieldRep=async()=>{
  if(!repName.trim()||(!repPhone.trim()&&!repEmail.trim())||repPassword.length<10){setRepMsg("أدخل اسم المندوب ورقم هاتف أو بريدًا وكلمة مرور من 10 أحرف على الأقل.");return;}
  setCreatingRep(true);setRepMsg("");
  try{
   const created=await api<FieldRep>("/field/reps",{method:"POST",body:JSON.stringify({name:repName.trim(),email:repEmail.trim()||null,phone:repPhone.trim()||null,password:repPassword})});
   setFieldReps(current=>[...current.filter(r=>r.id!==created.id),created].sort((a,b)=>a.name.localeCompare(b.name,"ar")));
   setCreatedRepCredentials({name:created.name,organization_id:created.organization_id||"",login:created.phone||created.email||"",password:repPassword});
   setSelectedRepId(created.id);setRepName("");setRepEmail("");setRepPhone("");setRepPassword("");
   setRepMsg(`تم إنشاء حساب ${created.name} بصلاحيات مندوب ميداني فقط. اختاره لإنشاء الرحلة.`);
  }catch(e){setRepMsg((e as Error).message)}finally{setCreatingRep(false)}
 };

 const copyFieldLink=async()=>{
  const url=new URL("/login",window.location.origin);if(me?.organization_id)url.searchParams.set("organization_id",me.organization_id);
  try{await navigator.clipboard.writeText(url.toString());setFieldLinkCopied(true);window.setTimeout(()=>setFieldLinkCopied(false),2200)}catch{setRepMsg(`انسخ رابط بوابة المندوب يدويًا: ${url.toString()}`)}
 };

 const copyRepCredentials=async()=>{
  if(!createdRepCredentials)return;
  const url=new URL("/login",window.location.origin);url.searchParams.set("organization_id",createdRepCredentials.organization_id);
  const text=`NEXORA Distribution — بيانات دخول المندوب\nالاسم: ${createdRepCredentials.name}\nOrganization ID: ${createdRepCredentials.organization_id}\nتسجيل الدخول: ${createdRepCredentials.login}\nكلمة المرور المؤقتة: ${createdRepCredentials.password}\nرابط البوابة: ${url.toString()}`;
  try{await navigator.clipboard.writeText(text);setRepMsg("تم نسخ بيانات الدخول. شاركها مع المندوب بشكل خاص.")}catch{setRepMsg(text)}
 };

 const addLoad=(p:Product)=>{
  const available=Math.max(0,Number(p.quantity_base||0));
  if(available<=0){setLoadMsg(`الصنف ${p.name_ar} رصيده صفر في مخزن الانطلاق المختار.`);return;}
  setLoadLines(current=>{
   const existing=current.find(line=>line.product.id===p.id);
   if(existing){if(existing.quantity+1>available){setLoadMsg(`الكمية المتاحة من ${p.name_ar} هي ${available} فقط في المخزن المحدد.`);return current;}return current.map(line=>line.product.id===p.id?{...line,quantity:line.quantity+1}:line)}
   return [...current,{product:p,quantity:1}];
  });
  setLoadMsg("");
 };

 const cleanEmptyTrips=async()=>{
  const planned=trips.length;
  if(!planned){setMsg("لا توجد رحلات مخططة لتنظيفها.");return;}
  if(!window.confirm(`سيتم إخفاء كل الرحلات الحالية (${planned} رحلة) من التشغيل، مع الاحتفاظ بالفواتير والمخزون والمدفوعات والتسويات، ويمكن استعادتها من الأرشيف. هل تريد المتابعة؟`))return;
  setCleaningTrips(true);setMsg("");
  try{
   const result=await api<{message:string;archived_count:number}>("/trips/archive-all",{method:"DELETE",body:JSON.stringify({confirm:true})});
   setMsg(result.message);
   setSelected(null);setLoadLines([]);setClosingLines([]);setTripDetailedReport(null);setTripProfit(null);setSupplierSettlements([]);setShowArchivedTrips(false);
   await load();
  }catch(error){setMsg(error instanceof Error?error.message:"تعذر تنظيف الرحلات الفارغة.")}
  finally{setCleaningTrips(false)}
 };

 const restoreTrip=async(trip:Trip)=>{
  setRestoringTripId(trip.id);setMsg('');
  try{
   const result=await api<{message:string}>(`/trips/${encodeURIComponent(trip.id)}/restore`,{method:'POST'});
   setTrips(current=>current.filter(row=>row.id!==trip.id));setMsg(result.message);
  }catch(error){setMsg(error instanceof Error?error.message:'تعذر استعادة الرحلة.')}
  finally{setRestoringTripId(null)}
 };
 const postLoad=async()=>{
  if(loadingError)return setLoadMsg("بيانات الشاشة لم تُحمّل: "+loadingError);
  if(!selected||!location||!loadLines.length)return setLoadMsg("اختار الرحلة والمخزن وأضف الأصناف.");
  if(selected.origin_location_id!==location)return setLoadMsg("احفظ مخزن الانطلاق المختار أولًا قبل ترحيل التحميل.");
  if(loadLines.some(l=>!Number.isFinite(l.quantity)||l.quantity<=0))return setLoadMsg("كمية التحميل لازم تكون أكبر من صفر.");
  if(loadLines.some(l=>l.quantity>Number(l.product.quantity_base||0)))return setLoadMsg("فيه صنف كميته في التحميل أكبر من الرصيد المتاح بالمخزن. راجع الكميات الحمراء قبل التأكيد.");
  setLoadingStock(true);setLoadMsg("");
  try{const payload={trip_id:selected.id,from_location_id:location,items:loadLines.map(l=>({product_id:l.product.id,quantity_base:l.quantity}))};const key=await getStableIdempotencyKey("trip-load",payload);await api("/trip-loads",{method:"POST",body:JSON.stringify({...payload,idempotency_key:key})});clearStableIdempotencyKey("trip-load",key);setLoadMsg("تم تحميل العربية وتحديث المخزون بنجاح.");setLoadLines([]);setLoadQuery("");await Promise.all([load(),refreshClosing(selected.id)]);setActiveStep("visits")}catch(e){setLoadMsg((e as Error).message)}finally{setLoadingStock(false)}
 };

 const assign=async()=>{
  if(loadingError)return setMsg("بيانات الشاشة لم تُحمّل: "+loadingError);
  if(!selected||!customer)return setMsg("اختار الرحلة والعميل.");
  setAssigning(true);setMsg("");try{await api(`/trips/${selected.id}/customers`,{method:"POST",body:JSON.stringify({customer_id:customer})});setMsg("تم إضافة العميل للرحلة بنجاح.");setCustomer("");setCustomerQuery("");setCustomerResults([]);loadRoute(selected.id)}catch(e){setMsg((e as Error).message)}finally{setAssigning(false)}
 };

 const optimizeRoute=async()=>{
  if(!selected)return;
  try{const result=await api<{optimized_count:number;without_coordinates:number;customers:RouteCustomer[]}>(`/trips/${selected.id}/route/optimize`,{method:"POST",body:JSON.stringify({})});setRouteCustomers(result.customers);setRouteMsg(`اترتبت ${result.optimized_count} زيارات حسب الموقع، و${result.without_coordinates} عميل بدون GPS اتحطوا في الآخر.`)}catch(e){setRouteMsg((e as Error).message)}
 };

 const reorderRoute=async(next:RouteCustomer[])=>{
  if(!selected)return;
  setRouteCustomers(next);
  try{const result=await api<{customers:RouteCustomer[]}>(`/trips/${selected.id}/route/reorder`,{method:"POST",body:JSON.stringify({customer_ids:next.map(x=>x.customer_id)})});setRouteCustomers(result.customers);setRouteMsg("تم حفظ ترتيب الزيارات.")}catch(e){setRouteMsg((e as Error).message);loadRoute(selected.id)}
 };

 const moveRoute=(index:number,direction:-1|1)=>{
  const target=index+direction;
  if(target<0||target>=routeCustomers.length)return;
  const next=[...routeCustomers];[next[index],next[target]]=[next[target],next[index]];reorderRoute(next);
 };

 const settle=async()=>{
  if(!selected||actualCash===""||!Number.isFinite(Number(actualCash))||Number(actualCash)<0)return setLoadMsg("اختار الرحلة وأدخل نقدية فعلية صحيحة لا تقل عن صفر.");
  setSettling(true);setLoadMsg("");try{const payload={trip_id:selected.id,actual_cash:Number(actualCash),opening_cash:Number(openingCash||0),closing_items:closingLines.map(l=>({product_id:l.product_id,quantity_base:l.quantity}))};const key=await getStableIdempotencyKey("trip-settlement",payload);const result=await api<{expected_cash:number;cash_variance:number;stock_variance_value:number;supplier_settlements?:TripSupplierSettlement[]}>("/trip-settlements",{method:"POST",body:JSON.stringify({...payload,idempotency_key:key})});clearStableIdempotencyKey("trip-settlement",key);if(result.supplier_settlements)setSupplierSettlements(result.supplier_settlements);setLoadMsg(`تمت التسوية بنجاح — النقدية المتوقعة ${result.expected_cash} EGP، فرق النقدية ${result.cash_variance} EGP`);setActiveStep("settlement");const refreshed=await api<Trip[]>("/trips");setTrips(refreshed);const closed=refreshed.find(t=>t.id===selected.id);if(closed)setSelected(closed);loadTripSupplierSettlements(selected.id);loadTripDetailedReport(selected.id);loadTripProfit(selected.id)}catch(e){setLoadMsg((e as Error).message)}finally{setSettling(false)}
 };

 const postExpense=async()=>{
  if(!selected)return setExpenseMsg("اختار الرحلة الأول.");
  if(["completed","cancelled"].includes(selected.status))return setExpenseMsg("لا يمكن إضافة مصروف لرحلة مقفولة.");
  if(!expenseCategory.trim()||!expenseAccountId||expenseAmount===""||!Number.isFinite(Number(expenseAmount))||Number(expenseAmount)<=0||!expenseDate)return setExpenseMsg("أكمل نوع المصروف والحساب والمبلغ والتاريخ الصحيح.");
  setExpenseSaving(true);setExpenseMsg("");
  try{
   const payload={trip_id:selected.id,financial_account_id:expenseAccountId,category:expenseCategory.trim(),amount:Number(expenseAmount),expense_date:expenseDate,notes:expenseNotes.trim()||undefined};
   const key=await getStableIdempotencyKey("trip-expense",payload);
   const result=await api<{id:string;amount:string|number;category:string}>("/expenses",{method:"POST",body:JSON.stringify({...payload,idempotency_key:key})});
   clearStableIdempotencyKey("trip-expense",key);
   setExpenseAmount("");setExpenseNotes("");setExpenseMsg(`تم تسجيل مصروف ${result.category} بقيمة ${Number(result.amount).toLocaleString("ar-EG")} EGP.`);
   await loadTripExpenses(selected.id);loadTripProfit(selected.id);
  }catch(e){setExpenseMsg((e as Error).message)}finally{setExpenseSaving(false)}
 };

 const fieldLoginUrl=me?.organization_id?`/login?organization_id=${encodeURIComponent(me.organization_id)}`:"/login";
 return <main className="main">
  <div className="topbar"><div><h1 className="title">إدارة العربيات والرحلات</h1><div className="subtitle">إنشاء الرحلة ← تحميل المخزون ← ترتيب الزيارات ← البيع ← التسوية</div></div><span className="badge blue"><Route size={11}/> التوزيع الميداني</span></div>
  {loadingError&&<div className="badge red" role="alert" style={{display:"block",padding:12,marginBottom:14,whiteSpace:"normal"}}>تعذر تحميل بيانات التشغيل: {loadingError} <button className="secondary" onClick={()=>{setLoadingError("");Promise.all([api<Vehicle[]>("/vehicles"),api<Location[]>("/locations"),api<Customer[]>("/customers"),api<Product[]>("/products"),api<Me>("/auth/me"),api<Trip[]>("/trips"),api<FieldRep[]>("/field/reps")]).then(([v,l,c,p,m,t,r])=>{const warehouses=l.filter(row=>row.type==="warehouse");setVehicles(v);setVehicle(v[0]?.id||"");setLocations(l);setLocation(warehouses[0]?.id||"");setCustomers(c);setProducts(p);setMe(m);setTrips(t);setFieldReps(r);setSelectedRepId(current=>current||r[0]?.id||"")}).catch(e=>setLoadingError((e as Error).message))}}>إعادة المحاولة</button></div>}

  <section className="card trip-workflow-card">
   <div className="trip-workflow-summary">
    <div><span className="trip-workflow-eyebrow">مسار العمل</span><h2>{selected?(selected.display_name||`الرحلة ${selected.trip_number}`):"ابدأ برحلة جديدة أو اختار رحلة موجودة"}</h2><p>{selected?`${selected.trip_number} · ${selected.vehicle_name} · ${selected.rep_name} · ${selected.trip_date||"تاريخ غير محدد"}`:"هنمشي معاك خطوة بخطوة؛ مش محتاج تفتح كل العمليات مرة واحدة."}</p></div>
    {selected&&<span className={`badge ${selected.status==="completed"?"green":selected.status==="cancelled"?"red":selected.status==="planned"?"blue":"amber"}`}>{selected.status==="planned"?"مخططة":selected.status==="completed"?"مكتملة":selected.status==="cancelled"?"ملغاة":"قيد التنفيذ"}</span>}
   </div>
   {selected&&<div className="trip-name-editor"><label><span><Pencil size={14}/> مسمى الرحلة</span><input value={renameTripName} onChange={e=>setRenameTripName(e.target.value)} maxLength={160} placeholder="اكتب اسمًا خاصًا للرحلة"/><small>الرقم الرسمي {selected.trip_number} ثابت، والاسم هنا لتسهيل تمييز الرحلة فقط.</small></label><button type="button" className="secondary" disabled={renamingTrip||renameTripName.trim()===(selected.display_name||"")} onClick={()=>void saveTripName()}><Save size={14}/>{renamingTrip?"جاري الحفظ":"حفظ الاسم"}</button>{selected.status==="planned"&&<button type="button" className="trip-delete-button" disabled={deletingTrip} onClick={()=>void deleteTrip()}><Trash2 size={14}/>{deletingTrip?"جاري الأرشفة":"أرشفة الرحلة"}</button>}</div>}
   <div className="trip-stepper" aria-label="خطوات تنفيذ الرحلة">
    {steps.map((step,index)=>{const Icon=step.icon;return <button type="button" key={step.id} className={`trip-step ${activeStep===step.id?"active":""}`} aria-label={`الخطوة ${index+1}: ${step.title}`} aria-current={activeStep===step.id?"step":undefined} disabled={!selected} onClick={()=>setActiveStep(step.id)}><span className="trip-step-icon">{index+1}</span><span className="trip-step-copy"><strong>{step.title}</strong><small>{step.caption}</small></span>{activeStep===step.id&&<Icon size={17} className="trip-step-active-icon"/>}</button>;})}
   </div>
   {!selected&&<div className="trip-workflow-help"><PlayCircle size={16}/> أنشئ رحلة من النموذج أو اختار واحدة من قائمة الرحلات؛ بعدها هتظهر لك الخطوة المناسبة فقط.</div>}
   {selected&&<div className="trip-workflow-help" style={{marginTop:12,justifyContent:"space-between",flexWrap:"wrap"}}><span><Link2 size={15} style={{verticalAlign:"middle",marginInlineEnd:6}}/> رابط دخول بوابة المندوب (ببياناته الخاصة): <a href={fieldLoginUrl} style={{fontWeight:800,textDecoration:"underline"}}>فتح رابط الدخول</a></span><button type="button" className="secondary" onClick={copyFieldLink}><Copy size={13}/>{fieldLinkCopied?"تم نسخ الرابط":"نسخ رابط الدخول"}</button></div>}
   {loadMsg&&activeStep!=="load"&&<div role="status" aria-live="polite" className={`trip-workflow-message ${loadMsg.includes("بنجاح")||loadMsg.includes("تم تحميل")||loadMsg.includes("تمت التسوية")?"success":"error"}`}>{loadMsg}</div>}
  </section>

  {selected&&activeStep==="load"&&<section className="card trip-load-workspace">
   <div className="trip-load-workspace-head"><div><span className="trip-load-eyebrow"><PackageCheck size={14}/> VEHICLE LOADING</span><h2>تجهيز حمولة العربية</h2><p>اختار الأصناف من رصيد المخزن، حدّد الكميات، وراجع القائمة كاملة قبل ترحيل التحميل.</p></div><div className="trip-load-head-actions"><span className={`badge ${selected.status==="planned"?"blue":selected.status==="completed"?"green":"amber"}`}>{selected.status==="planned"?"رحلة جاهزة للتحميل":selected.status==="completed"?"رحلة مقفولة":"رحلة نشطة"}</span><button type="button" className="secondary" onClick={()=>{setLoadLines([]);setLoadMsg("")}} disabled={!loadLines.length||loadingStock}>تفريغ القائمة</button></div></div>
   <div className="trip-load-context-grid"><div><small>اسم الرحلة</small><strong>{selected.display_name||selected.trip_number}</strong><span>{selected.display_name?`الرقم الرسمي ${selected.trip_number}`:"رقم الرحلة الرسمي"}</span></div><div><small>العربية</small><strong>{selected.vehicle_name}</strong><span>{selected.rep_name}</span></div><div><small>مخزن الانطلاق</small><select aria-label="مخزن الانطلاق للتحميل" value={location} onChange={event=>setLocation(event.target.value)} disabled={savingOrigin||!warehouseLocations.length}>{warehouseLocations.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select><span>الرصيد سيُقرأ من المخزن المختار فقط</span><button type="button" className="secondary" style={{marginTop:7}} onClick={()=>void saveTripOrigin()} disabled={savingOrigin||!location||["completed","cancelled"].includes(selected.status)}>{savingOrigin?"جاري الحفظ…":"حفظ مخزن الانطلاق"}</button></div><label><small>تغيير الرحلة</small><select aria-label="اختيار رحلة أخرى للتحميل" value={selected.id} onChange={event=>{const trip=trips.find(row=>row.id===event.target.value);if(trip)selectTrip(trip)}}>{trips.map(trip=><option key={trip.id} value={trip.id}>{trip.display_name||trip.trip_number} — {trip.vehicle_name}</option>)}</select></label></div>
   <div className="trip-load-kpis"><div><small>أصناف بالقائمة</small><strong>{loadLines.length.toLocaleString("ar-EG")}</strong></div><div><small>إجمالي الكميات المطلوبة</small><strong>{loadLines.reduce((sum,line)=>sum+line.quantity,0).toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong></div><div><small>أصناف تجاوزت المتاح</small><strong className={loadLines.some(line=>line.quantity>Number(line.product.quantity_base||0))?"has-stock-error":""}>{loadLines.filter(line=>line.quantity>Number(line.product.quantity_base||0)).length.toLocaleString("ar-EG")}</strong></div></div>
   <div className="trip-load-columns">
    <section className="trip-load-catalog"><div className="trip-load-panel-head"><div><h3>المنتجات المتاحة</h3><p>الكمية المعروضة خاصة بمخزن الانطلاق.</p></div><span className="badge blue">{products.filter(product=>!availableOnly||Number(product.quantity_base||0)>0).length} صنف</span></div>
     <label className="trip-load-search"><Search size={16}/><input className="field" value={loadQuery} onChange={event=>setLoadQuery(event.target.value)} placeholder="ابحث باسم المنتج أو SKU أو الباركود..." aria-label="البحث عن صنف للتحميل"/><button type="button" disabled={!loadQuery} onClick={()=>setLoadQuery("")} aria-label="مسح البحث">×</button></label>
     <div className="trip-load-catalog-tools"><label><input type="checkbox" checked={availableOnly} onChange={event=>setAvailableOnly(event.target.checked)}/> إظهار الأصناف المتاحة فقط</label><button type="button" className="secondary" onClick={()=>{setProductSearchError("");api<Product[]>(`/products?${new URLSearchParams({location_id:location,...(loadQuery.trim()?{q:loadQuery.trim()}:{})}).toString()}`).then(setProducts).catch(error=>setProductSearchError((error as Error).message))}} disabled={!location}><RefreshCw size={13}/> تحديث الرصيد</button></div>
     {productSearchError&&<div className="trip-load-error" role="alert">{productSearchError}</div>}
     <div className="trip-load-product-grid">{products.filter(product=>!availableOnly||Number(product.quantity_base||0)>0).slice(0,48).map(product=>{const available=Math.max(0,Number(product.quantity_base||0));const picked=loadLines.find(line=>line.product.id===product.id);const remaining=available-(picked?.quantity||0);const outOfStock=available<=0;const overPicked=remaining<0;return <article className={`trip-load-product-card ${outOfStock?"out-of-stock":""}`} key={product.id}><div className="trip-load-product-card-top"><span className="trip-load-product-icon"><PackageCheck size={17}/></span><span className={`trip-load-stock-pill ${outOfStock?"out":available<=5?"low":"ok"}`}>{outOfStock?"نفد المخزون":available<=5?"رصيد قليل":"متاح"}</span></div><div className="trip-load-product-title"><strong>{product.name_ar}</strong><small>{product.sku}</small></div><div className="trip-load-product-stock"><span>المتاح في المخزن</span><strong>{available.toLocaleString("ar-EG",{maximumFractionDigits:3})} <small>{product.base_unit_name||"وحدة"}</small></strong></div>{picked&&<div className={`trip-load-picked-note ${overPicked?"error":""}`}>في القائمة: {picked.quantity.toLocaleString("ar-EG")} · المتبقي بعد الاختيار: {remaining.toLocaleString("ar-EG",{maximumFractionDigits:3})}</div>}<button type="button" className="trip-load-add-button" disabled={outOfStock||["completed","cancelled"].includes(selected.status)||remaining<1} onClick={()=>addLoad(product)}><Plus size={15}/>{picked?"إضافة وحدة أخرى":"إضافة للحمولة"}</button></article>})}{!products.filter(product=>!availableOnly||Number(product.quantity_base||0)>0).length&&<div className="trip-inline-empty">{availableOnly?"مفيش أصناف برصيد موجب في مخزن الانطلاق الحالي. ألغِ خيار «إظهار الأصناف المتاحة فقط» لمراجعة دليل المنتجات، أو سجّل فاتورة شراء/تحويل مخزون للمخزن الصحيح.":"دليل المنتجات فارغ في قاعدة البيانات الحالية. أضف الأصناف أولًا من صفحة الأصناف ثم حدّث القائمة."}</div>}</div>
     {products.filter(product=>!availableOnly||Number(product.quantity_base||0)>0).length>48&&<p className="trip-load-results-note">نعرض أول 48 نتيجة. استخدم البحث للوصول لباقي الأصناف.</p>}
    </section>
    <section className="trip-load-cart"><div className="trip-load-panel-head"><div><h3>قائمة تحميل العربية</h3><p>راجع الرصيد المتاح والكميات قبل الحفظ.</p></div><span className={`badge ${loadLines.length?"blue":"amber"}`}>{loadLines.length} أصناف</span></div>
     {!loadLines.length&&<div className="trip-load-cart-empty"><span><PackageCheck size={24}/></span><strong>الحمولة لسه فاضية</strong><p>اختار المنتجات من القائمة واضغط «إضافة للحمولة»، وهتظهر هنا فورًا.</p></div>}
     {loadLines.map((line,index)=>{const available=Math.max(0,Number(line.product.quantity_base||0));const over=Number(line.quantity)>available;return <article className={`trip-load-cart-line ${over?"over-stock":""}`} key={line.product.id}><span className="trip-load-line-index">{index+1}</span><div className="trip-load-cart-copy"><strong>{line.product.name_ar}</strong><small>{line.product.sku}</small><span>المتاح: {available.toLocaleString("ar-EG",{maximumFractionDigits:3})} {line.product.base_unit_name||"وحدة"}</span></div><div className="trip-load-quantity-control"><button type="button" aria-label={`تقليل كمية ${line.product.name_ar}`} disabled={line.quantity<=1} onClick={()=>setLoadLines(current=>current.map((row,i)=>i===index?{...row,quantity:Math.max(1,row.quantity-1)}:row))}>−</button><input aria-label={`كمية تحميل ${line.product.name_ar}`} type="number" min="0.001" step="any" value={line.quantity} onChange={event=>setLoadLines(current=>current.map((row,i)=>i===index?{...row,quantity:Number(event.target.value)}:row))} disabled={["completed","cancelled"].includes(selected.status)}/><button type="button" aria-label={`زيادة كمية ${line.product.name_ar}`} disabled={line.quantity>=available} onClick={()=>setLoadLines(current=>current.map((row,i)=>i===index?{...row,quantity:row.quantity+1}:row))}>+</button></div><button type="button" className="trip-load-remove-button" aria-label={`حذف ${line.product.name_ar} من قائمة التحميل`} onClick={()=>setLoadLines(current=>current.filter(row=>row.product.id!==line.product.id))} disabled={["completed","cancelled"].includes(selected.status)}><Trash2 size={15}/></button>{over&&<p className="trip-load-line-warning">الكمية المطلوبة أكبر من رصيد المخزن.</p>}</article>})}
     {loadLines.length>0&&<div className="trip-load-cart-summary"><div><span>إجمالي الوحدات</span><strong>{loadLines.reduce((sum,line)=>sum+line.quantity,0).toLocaleString("ar-EG",{maximumFractionDigits:3})}</strong></div><div><span>عدد الأصناف</span><strong>{loadLines.length}</strong></div><div><span>حالة القائمة</span><strong className={loadLines.some(line=>line.quantity>Number(line.product.quantity_base||0))?"has-stock-error":""}>{loadLines.some(line=>line.quantity>Number(line.product.quantity_base||0))?"راجع الكمية":"جاهزة للمراجعة"}</strong></div></div>}
     {loadMsg&&<div className={`trip-load-message ${loadMsg.includes("بنجاح")||loadMsg.includes("تم تحميل")?"success":"error"}`} role="status">{loadMsg}</div>}
     <div className="trip-load-footer-actions"><button type="button" className="secondary" disabled={!loadLines.length||loadingStock||["completed","cancelled"].includes(selected.status)} onClick={()=>{setLoadLines([]);setLoadMsg("")}}>إلغاء الاختيار</button><button type="button" className="primary" disabled={loadingStock||["completed","cancelled"].includes(selected.status)||!location||selected.origin_location_id!==location||!loadLines.length||loadLines.some(line=>!Number.isFinite(line.quantity)||line.quantity<=0||line.quantity>Number(line.product.quantity_base||0))} onClick={postLoad}><PackageCheck size={15}/>{loadingStock?"جاري ترحيل التحميل...":selected.origin_location_id!==location?"احفظ مخزن الانطلاق أولًا":"مراجعة وتأكيد تحميل العربية"}</button></div>
    </section>
   </div>
  </section>}

  <div className="split">
   <section className="card">
    <div className="panel-title"><span>إنشاء رحلة</span><span className="badge green">{trips.length} رحلات</span></div>
    <div className="form-grid">
     <label style={{gridColumn:"1 / -1"}}>مسمى الرحلة (اختياري)<input value={tripName} onChange={e=>setTripName(e.target.value)} maxLength={160} placeholder="مثال: رحلة الزرقا الصباحية — توزيع السبت"/><small>اكتب اسمًا واضحًا يظهر في قائمة الرحلات والتقارير بجانب رقمها الرسمي.</small></label>
     <label>العربية<select value={vehicle} onChange={e=>setVehicle(e.target.value)}>{vehicles.map(v=><option key={v.id} value={v.id}>{v.name} — {v.code}</option>)}</select></label>
     <label>مخزن الانطلاق<select value={location} onChange={e=>setLocation(e.target.value)}>{warehouseLocations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select><small>المصدر هنا مخزن فقط، وليس موقع العربية.</small></label>
     <label style={{gridColumn:"1 / -1"}}>المندوب المسؤول<select value={selectedRepId} onChange={e=>setSelectedRepId(e.target.value)}><option value="">اختار مندوبًا ميدانيًا...</option>{fieldReps.filter(r=>r.status==="active").map(r=><option key={r.id} value={r.id}>{r.name} · {r.phone||r.email||"بدون وسيلة دخول"}</option>)}</select><small>المندوب يدخل من رابط البوابة بحسابه، ويظهر له خط سيره المسند فقط.</small></label>
    </div>
    <button className="primary" disabled={creating||!vehicle||!location||!selectedRepId} onClick={create} style={{marginTop:14,display:"flex",justifyContent:"center",gap:8}}><Plus size={15}/> {creating?"جاري إنشاء الرحلة...":"إنشاء رحلة جديدة وإسناد المندوب"}</button>

    <section className="card" style={{marginTop:16,background:"#f8fafc"}}>
     <div className="panel-title"><span><UserPlus size={15}/> إنشاء حساب مندوب جديد</span><span className="badge blue">صلاحيات ميدانية</span></div>
     <p style={{fontSize:10,color:"#64748b",lineHeight:1.8,marginTop:0}}>اكتب بيانات الدخول التي ستسلمها للمندوب. كلمة المرور لا تظهر في أي رابط؛ رابط البوابة واحد ويتطلب تسجيل الدخول.</p>
     <div className="form-grid">
      <label>اسم المندوب<input value={repName} onChange={e=>setRepName(e.target.value)} placeholder="اسم المندوب" autoComplete="off"/></label>
      <label>رقم الهاتف<input value={repPhone} onChange={e=>setRepPhone(e.target.value)} placeholder="01xxxxxxxxx" inputMode="tel" autoComplete="off"/></label>
      <label>البريد الإلكتروني (اختياري)<input type="email" value={repEmail} onChange={e=>setRepEmail(e.target.value)} placeholder="rep@example.com" autoComplete="off"/></label>
      <label>كلمة مرور مؤقتة<input type="password" value={repPassword} onChange={e=>setRepPassword(e.target.value)} minLength={10} placeholder="10 أحرف على الأقل" autoComplete="new-password"/></label>
     </div>
     <button className="secondary" style={{marginTop:10,width:"100%",justifyContent:"center",display:"flex",gap:7}} disabled={creatingRep} onClick={createFieldRep}><UserPlus size={14}/>{creatingRep?"جاري إنشاء الحساب...":"إنشاء حساب مندوب"}</button>
     {repMsg&&<div role="status" aria-live="polite" className="badge blue" style={{display:"block",marginTop:9,padding:10,whiteSpace:"normal",lineHeight:1.8}}>{repMsg}</div>}
     {createdRepCredentials&&<div className="trip-workflow-help" style={{display:"block",marginTop:10,whiteSpace:"normal",lineHeight:2}}><strong>بيانات دخول لمرة واحدة — شاركها بشكل خاص</strong><div>المندوب: {createdRepCredentials.name}</div><div>Organization ID: <b dir="ltr">{createdRepCredentials.organization_id}</b></div><div>اسم الدخول: <b dir="ltr">{createdRepCredentials.login}</b></div><div>كلمة المرور المؤقتة: <b dir="ltr">{createdRepCredentials.password}</b></div><button className="secondary" style={{marginTop:8,width:"100%",justifyContent:"center",display:"flex",gap:7}} onClick={copyRepCredentials}><Copy size={13}/>نسخ بيانات الدخول والرابط</button><button className="secondary" style={{marginTop:6,width:"100%",justifyContent:"center",display:"flex",gap:7}} onClick={()=>setCreatedRepCredentials(null)}>إخفاء بيانات الدخول</button></div>}
    </section>

    {selected&&activeStep==="visits"&&<>
    <div className="panel-title" style={{marginTop:20}}>إضافة عميل للرحلة</div>
    <div className="trip-customer-picker">
     <Search size={15}/><input className="field" value={customerQuery} onChange={e=>setCustomerQuery(e.target.value)} placeholder="ابحث باسم العميل أو الهاتف أو الكود..." aria-label="بحث العملاء للرحلة" autoComplete="off"/>
     {customerQuery.trim().length>=2&&<div className="trip-customer-results">{customerResults.length?customerResults.slice(0,8).map(c=><button type="button" key={c.id} onClick={()=>{setCustomers(current=>current.some(row=>row.id===c.id)?current:[c,...current]);setCustomer(c.id);setCustomerQuery("");setCustomerResults([]);}}><span><strong>{c.name}</strong><small>{c.phone||"بدون رقم هاتف"}</small></span><small>{c.code}</small></button>):<div className="trip-customer-no-results">مفيش عميل مطابق. جرّب جزءًا أكبر من الاسم أو الهاتف.</div>}</div>}
    </div>
    {customer&&<div className="trip-customer-selected"><span><CheckCircle2 size={15}/>{customers.find(c=>c.id===customer)?.name||"العميل المحدد"}</span><button type="button" onClick={()=>setCustomer("")}>تغيير العميل</button></div>}
    <button className="secondary" disabled={assigning||!selected||!customer||["completed","cancelled"].includes(selected?.status||"")} onClick={assign} style={{marginTop:8,width:"100%"}}><Users size={14}/> {assigning?"جاري إضافة العميل...":"إضافة العميل للرحلة"}</button>

    <section className="card trip-route-panel" style={{marginTop:14,background:"#f8fafc"}}>
     <div className="panel-title"><span><MapPinned size={15}/> ترتيب الزيارات</span><button className="secondary" onClick={optimizeRoute}><WandSparkles size={13}/> تحسين تلقائي</button></div>
     <div style={{fontSize:10,color:"#64748b",marginBottom:9}}>Nearest-neighbor من أول عميل عنده GPS. تقدر تعدّل الترتيب يدويًا.</div>
     {routeCustomers.map((c,i)=><div key={c.customer_id} style={{display:"flex",gap:7,alignItems:"center",padding:"8px 0",borderBottom:"1px solid #e2e8f0"}}>
      <span className="badge blue" style={{minWidth:24,justifyContent:"center"}}>{i+1}</span>
      <div style={{flex:1}}><strong style={{fontSize:11}}>{c.name}</strong><small style={{display:"block",color:"#64748b"}}>{c.code} · {c.primary_address||c.address_text||"بدون عنوان"} {c.latitude!=null&&c.longitude!=null?"· GPS":"· بدون GPS"}</small></div>
      <button className="secondary" disabled={i===0} onClick={()=>moveRoute(i,-1)} title="لفوق"><ArrowUp size={12}/></button>
      <button className="secondary" disabled={i===routeCustomers.length-1} onClick={()=>moveRoute(i,1)} title="لتحت"><ArrowDown size={12}/></button>
     </div>)}
     {!routeCustomers.length&&<div style={{padding:15,textAlign:"center",color:"#64748b"}}>أضف عملاء للرحلة علشان يظهر مسار الزيارة.</div>}
     {routeMsg&&<div className="badge blue" style={{marginTop:9}}>{routeMsg}</div>}
    </section>
    </>}
   </section>

   <section className="card">
    <div className="trip-list-panel-head"><div><strong>{showArchivedTrips?"أرشيف الرحلات":"رحلات التشغيل"}</strong><span className="badge blue">{visibleTrips.length.toLocaleString("ar-EG")} رحلة</span></div><button type="button" className="trip-cleanup-button" disabled={cleaningTrips||showArchivedTrips||!trips.length} onClick={()=>void cleanEmptyTrips()}><Trash2 size={14}/>{cleaningTrips?"جاري الأرشفة...":"إخفاء كل الرحلات الحالية"}</button></div>
    <label className="trip-archive-selector">عرض الرحلات<select value={showArchivedTrips?"archived":"active"} onChange={event=>{const archived=event.target.value==="archived";setShowArchivedTrips(archived);api<Trip[]>(archived?"/trips?archived_only=true":"/trips").then(setTrips).catch(error=>setMsg((error as Error).message))}}><option value="active">الرحلات الحالية</option><option value="archived">الأرشيف — استعادة الرحلات القديمة</option></select></label>
    <select className="field trip-existing-select" aria-label="الرحلة المختارة" value={selected?.id||""} onChange={e=>{const t=trips.find(t=>t.id===e.target.value)||null;if(t)selectTrip(t)}}><option value="">اختار رحلة للتشغيل...</option>{trips.map(t=><option key={t.id} value={t.id}>{t.display_name||t.trip_number} — {t.vehicle_name}</option>)}</select>
    <label className="trip-list-search"><Search size={15}/><input value={tripSearch} onChange={e=>setTripSearch(e.target.value)} placeholder="ابحث باسم الرحلة أو رقمها أو العربية أو المندوب..." aria-label="بحث الرحلات"/></label>
    <div className="trip-status-filter" aria-label="تصفية الرحلات"><button type="button" className={tripStatusFilter==="all"?"selected":""} onClick={()=>setTripStatusFilter("all")}>الكل ({trips.length})</button><button type="button" className={tripStatusFilter==="planned"?"selected":""} onClick={()=>setTripStatusFilter("planned")}>مخططة</button><button type="button" className={tripStatusFilter==="active"?"selected":""} onClick={()=>setTripStatusFilter("active")}>قيد التنفيذ</button><button type="button" className={tripStatusFilter==="completed"?"selected":""} onClick={()=>setTripStatusFilter("completed")}>مكتملة</button></div>
    {visibleTrips.map(t=><div className="trip-row-container" key={t.id}><button type="button" aria-pressed={selected?.id===t.id} disabled={showArchivedTrips} onClick={()=>selectTrip(t)} className={`trip-row ${selected?.id===t.id?"selected":""}`}>
     <span><strong>{t.display_name||t.trip_number}</strong><small style={{display:"block",color:"#64748b"}}>{t.display_name?`رقم ${t.trip_number} · `:""}{t.vehicle_name} · {t.rep_name}</small><small style={{display:"block",color:"#94a3b8"}}>{t.trip_date||"تاريخ غير محدد"}</small></span><span className={`badge ${t.status==="planned"?"blue":t.status==="completed"?"green":t.status==="cancelled"?"red":"amber"}`}>{t.status==="planned"?"مخططة":t.status==="completed"?"مكتملة":t.status==="cancelled"?"ملغاة":"نشطة"}</span>
    </button>{!showArchivedTrips&&<button type="button" className="secondary" style={{marginInlineStart:8,whiteSpace:"nowrap"}} onClick={()=>selectTrip(t)}><PackageCheck size={13}/> اختيار وتحميل الأصناف</button>}{showArchivedTrips&&<button type="button" className="trip-restore-button" disabled={restoringTripId===t.id} onClick={()=>void restoreTrip(t)}>{restoringTripId===t.id?"جاري الاستعادة...":"استعادة الرحلة"}</button>}</div>)}
    {!visibleTrips.length&&<div style={{padding:30,textAlign:"center",color:"#64748b"}}><Truck size={26}/><p>{trips.length?"مفيش رحلة مطابقة للبحث أو حالة الفلتر.":"مفيش رحلات لسه. أنشئ أول رحلة من النموذج."}</p></div>}
    {msg&&<div role="status" aria-live="polite" className={`badge ${msg.includes("بنجاح")?"green":"blue"}`} style={{display:"block",marginTop:12,padding:10,whiteSpace:"normal",lineHeight:1.8}}>{msg}</div>}


    {selected&&activeStep==="settlement"&&<section className="card trip-operation-card" style={{marginTop:14}}><div className="panel-title"><span><Banknote size={15}/> تسوية الرحلة وإقفالها</span><span className={`badge ${selected.status==="completed"?"green":"amber"}`}>{selected.status==="completed"?"تم الإقفال":"مراجعة قبل الإقفال"}</span></div><div className="trip-settlement-guide"><strong>قبل الإقفال</strong><p>راجع الرصيد الفعلي لكل صنف داخل العربية. سجّل النقدية اللي خرجت مع المندوب أول الرحلة، ثم النقدية اللي رجع بيها بعد التحصيل والمصروفات؛ النظام هيطلع المتوقع والفرق تلقائيًا.</p></div>{closingLines.length===0&&<p className="trip-empty-note">لا توجد حركات تحميل ظاهرة لهذه الرحلة. راجع سجل التحميل قبل التسوية.</p>}{closingLines.map((l,i)=><div key={l.product_id} className="trip-load-line"><span><strong>{l.name_ar}</strong><small>الرصيد المتوقع حاليًا من النظام</small></span><label>الكمية الفعلية<input aria-label={`رصيد التسوية ${l.name_ar}`} type="number" min="0" step="any" value={l.quantity} onChange={e=>setClosingLines(x=>x.map((v,j)=>j===i?{...v,quantity:Number(e.target.value)}:v))} disabled={["completed","cancelled"].includes(selected.status)}/></label></div>)}<div className="form-grid" style={{marginTop:10}}><label>نقدية خرجت مع المندوب (رصيد افتتاحي)<input aria-label="رصيد نقدية بداية الرحلة" className="field" type="number" min="0" step="0.01" value={openingCash} onChange={e=>setOpeningCash(e.target.value)} placeholder="0.00" disabled={["completed","cancelled"].includes(selected.status)}/></label><label>النقدية الفعلية اللي رجعت من الرحلة<input aria-label="النقدية الفعلية" className="field" type="number" min="0" step="0.01" value={actualCash} onChange={e=>setActualCash(e.target.value)} placeholder="اكتب النقدية المستلمة" disabled={["completed","cancelled"].includes(selected.status)}/></label></div><button className="primary" disabled={settling||["completed","cancelled"].includes(selected.status)||actualCash===""} onClick={settle} style={{marginTop:12}}>{settling?"جاري إقفال الرحلة...":selected.status==="completed"?"الرحلة مقفولة بالفعل":"مراجعة وإقفال الرحلة"}</button></section>}

    {selected&&activeStep==="settlement"&&<section className="card trip-operation-card" style={{marginTop:14}}>
     <div className="panel-title"><span><Banknote size={15}/> التقرير المالي التفصيلي للرحلة</span><div style={{display:"flex",gap:6,flexWrap:"wrap"}}><button type="button" className="secondary" disabled={tripReportLoading||exportingReport!==null||!tripDetailedReport||tripDetailedReport.trip.id!==selected.id} onClick={()=>void exportTripWorkbook()}><FileSpreadsheet size={13}/>{exportingReport==="xlsx"?"جاري تجهيز Excel...":"تصدير Excel"}</button><button type="button" className="secondary" disabled={tripReportLoading||exportingReport!==null||!tripDetailedReport||tripDetailedReport.trip.id!==selected.id} onClick={()=>void exportTripPdf()}><FileDown size={13}/>{exportingReport==="pdf"?"جاري تجهيز PDF...":"تصدير PDF"}</button><button type="button" className="secondary" disabled={tripReportLoading||exportingReport!==null} onClick={()=>selected&&loadTripDetailedReport(selected.id)}><RefreshCw size={13}/> تحديث التقرير</button></div></div>
     <p className="trip-operation-intro">التقرير بيفصل تكلفة البضاعة اللي خرجت عن قيمة المبيعات، والنقدي وقت إصدار الفاتورة عن الآجل والتحصيل بعد البيع، عشان كل جنيه يتحسب مرة واحدة.</p>
     {tripReportLoading&&<div role="status" className="trip-inline-empty">جاري تجميع تحميل العربية والفواتير والتحصيلات وحركة الخزنة...</div>}
      {tripReportMsg&&<div role="alert" className="badge red" style={{display:"block",padding:10,whiteSpace:"normal"}}>{tripReportMsg}</div>}
      {exportMsg&&<div role="status" className="badge blue" style={{display:"block",padding:10,whiteSpace:"normal",marginBottom:10}}>{exportMsg}</div>}
     {tripDetailedReport&&selected&&tripDetailedReport.trip.id===selected.id&&<>
      <div className="trip-context" style={{marginBottom:12}}><strong>{tripDetailedReport.trip.trip_number}</strong> · {tripDetailedReport.trip.vehicle_name}{tripDetailedReport.trip.plate_number?` (${tripDetailedReport.trip.plate_number})`:""} · المندوب: {tripDetailedReport.trip.rep_name} · {tripDetailedReport.trip.trip_date}</div>
      <div className="grid" style={{gridTemplateColumns:"repeat(auto-fit,minmax(145px,1fr))",marginBottom:14}}>
       {([["قيمة البضاعة الخارجة",tripDetailedReport.summary.loaded_value,"تكلفة التحميل"],["إجمالي المبيعات",tripDetailedReport.summary.sales_total,`${tripDetailedReport.summary.invoice_count} فاتورة`],["نقدي وقت البيع",tripDetailedReport.summary.sales_cash,"يدخل في عهدة النقدية"],["غير نقدي وقت البيع",tripDetailedReport.summary.sales_non_cash,"تحويل/بطاقة أو وسيلة غير نقدية"],["الآجل عند الإصدار",tripDetailedReport.summary.credit_at_sale,"ليس تحصيلًا عند إصدار الفاتورة"],["تحصيلات نقدية لاحقة",tripDetailedReport.summary.cash_collections_after_sale,"تدخل في عهدة النقدية"],["تحصيلات غير نقدية لاحقة",tripDetailedReport.summary.non_cash_collections_after_sale,"تظهر منفصلة عن عهدة الكاش"],["المتبقي على فواتير الرحلة",tripDetailedReport.summary.receivables_open_on_trip_invoices,"الرصيد الحالي"],["المصروفات المباشرة",tripDetailedReport.summary.trip_expenses+tripDetailedReport.summary.other_cash_outflow,"مصروفات الرحلة والمسحوبات/الأجور"],["النقدية المتوقعة",tripDetailedReport.summary.expected_cash,"نقدي فقط بعد الافتتاحي والمصروفات"]] as [string,number,string][]).map(([label,value,hint])=><div className="metric card" key={label}><span className="metric-label">{label}</span><div className="metric-value">{Number(value||0).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}<span className="metric-unit">{tripDetailedReport.trip.currency}</span></div><div className="metric-note">{hint}</div></div>)}
      </div>
      {tripDetailedReport.settlement&&<div className="trip-context" style={{marginBottom:12,whiteSpace:"normal"}}>تم إقفال الرحلة: النقدية المستلمة <strong>{Number(tripDetailedReport.summary.actual_cash||0).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} {tripDetailedReport.trip.currency}</strong> · فرق النقدية <strong>{Number(tripDetailedReport.summary.cash_variance||0).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} {tripDetailedReport.trip.currency}</strong> · فرق قيمة المخزون <strong>{Number(tripDetailedReport.summary.stock_variance_value||0).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} {tripDetailedReport.trip.currency}</strong><small style={{display:"block",marginTop:6,color:"#64748b"}}>{tripDetailedReport.settlement.opening_stock_basis==="snapshot"?"رصيد افتتاح المخزون محفوظ قبل أول حركة للرحلة؛ تم احتساب البضاعة المرحلة من رحلة سابقة.":"تنبيه: الرحلة قديمة ولا توجد لها لقطة افتتاحية موثقة؛ لم يتم تخمين رصيد البداية."}</small></div>}
      {tripDetailedReport.opening_items.length>0&&<><div className="panel-title" style={{marginTop:14}}>مخزون العربية قبل بدء الرحلة</div><div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>رصيد البداية</th></tr></thead><tbody>{tripDetailedReport.opening_items.map(row=><tr key={row.product_id}><td>{row.name_ar}</td><td>{row.sku}</td><td>{Number(row.quantity_base).toLocaleString("ar-EG")}</td></tr>)}</tbody></table></div></>}
      {tripDetailedReport.settlement&&<><div className="panel-title" style={{marginTop:16}}>مطابقة مخزون الإقفال</div><div className="table-wrap"><table><thead><tr><th>الصنف</th><th>افتتاحي</th><th>تحميل</th><th>مباع</th><th>مرتجع</th><th>تحويل صافي</th><th>تسوية صافي</th><th>متوقع</th><th>فعلي</th><th>فرق</th></tr></thead><tbody>{tripDetailedReport.stock_reconciliation.map(row=><tr key={row.product_id}><td>{row.name_ar}</td><td>{Number(row.opening_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.loaded_quantity_base).toLocaleString("ar-EG")}</td><td>{Math.abs(Number(row.sold_quantity_base)).toLocaleString("ar-EG")}</td><td>{Number(row.returned_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.transferred_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.adjustment_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.expected_closing_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.actual_closing_quantity_base).toLocaleString("ar-EG")}</td><td><strong>{Number(row.variance_quantity_base).toLocaleString("ar-EG")}</strong></td></tr>)}{!tripDetailedReport.stock_reconciliation.length&&<tr><td colSpan={10}>لا توجد بنود جرد محفوظة لهذه التسوية.</td></tr>}</tbody></table></div></>}
      <div className="panel-title" style={{marginTop:14}}>بضاعة التحميل</div>
      <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>الكمية المحملة</th><th>قيمة التكلفة وقت التحميل</th></tr></thead><tbody>{tripDetailedReport.loaded_items.map(row=><tr key={row.product_id}><td>{row.name_ar}</td><td>{row.sku}</td><td>{Number(row.quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.loaded_cost).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} {tripDetailedReport.trip.currency}</td></tr>)}{!tripDetailedReport.loaded_items.length&&<tr><td colSpan={4}>لا توجد حركات تحميل مسجلة.</td></tr>}</tbody></table></div>
      <div className="panel-title" style={{marginTop:16}}>الفواتير والآجل حسب العميل</div>
      <div className="table-wrap"><table><thead><tr><th>التاريخ / الفاتورة</th><th>العميل</th><th>قيمة الفاتورة</th><th>نقدي وقت البيع</th><th>غير نقدي وقت البيع</th><th>آجل عند الإصدار</th><th>المتبقي الحالي</th></tr></thead><tbody>{tripDetailedReport.sales_invoices.map(row=><tr key={row.id}><td>{row.invoice_date}<small style={{display:"block",color:"#64748b"}}>{row.document_number}</small></td><td>{row.customer_name}<small style={{display:"block",color:"#64748b"}}>{row.customer_code}</small></td><td>{Number(row.total).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.cash_at_sale).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.non_cash_at_sale).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.credit_at_sale).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.balance_due).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td></tr>)}{!tripDetailedReport.sales_invoices.length&&<tr><td colSpan={7}>لا توجد فواتير بيع على الرحلة حتى الآن.</td></tr>}</tbody></table></div>
      <div className="panel-title" style={{marginTop:16}}>تفاصيل الأصناف المباعة</div>
      <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>الكمية المباعة</th><th>قيمة البيع</th><th>تكلفة البضاعة</th><th>مجمل الربح</th></tr></thead><tbody>{tripDetailedReport.sold_items.map(row=><tr key={row.product_id}><td>{row.name_ar}</td><td>{row.sku}</td><td>{Number(row.sold_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(row.sales_value).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.cost_value).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{(Number(row.sales_value)-Number(row.cost_value)).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td></tr>)}{!tripDetailedReport.sold_items.length&&<tr><td colSpan={6}>لا توجد أصناف مباعة.</td></tr>}</tbody></table></div>
      <div className="panel-title" style={{marginTop:16}}>تحصيلات العملاء بعد البيع</div>
      <div className="table-wrap"><table><thead><tr><th>التاريخ</th><th>العميل</th><th>طريقة الدفع</th><th>الحساب</th><th>قيمة الإيصال</th><th>المخصص لفواتير الرحلة</th><th>التصنيف</th><th>المرجع</th></tr></thead><tbody>{tripDetailedReport.customer_collections.map(row=><tr key={row.id}><td>{row.date}</td><td>{row.customer_name}</td><td>{row.payment_method_name||"—"}</td><td>{row.financial_account_name||"—"}</td><td>{Number(row.amount).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{Number(row.allocated_to_trip).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{row.is_cash?"نقدي":"غير نقدي"}</td><td>{row.reference||"—"}</td></tr>)}{!tripDetailedReport.customer_collections.length&&<tr><td colSpan={8}>لا توجد تحصيلات مسجلة مرتبطة بفواتير الرحلة.</td></tr>}</tbody></table></div>
      <div className="panel-title" style={{marginTop:16}}>مصروفات الرحلة والحركات الأخرى</div>
      <div className="table-wrap"><table><thead><tr><th>التاريخ</th><th>البند</th><th>الحساب / المستلم</th><th>المبلغ</th><th>ملاحظات / مرجع</th></tr></thead><tbody>{tripDetailedReport.expenses.map(row=><tr key={row.id}><td>{row.expense_date}</td><td>{row.category}</td><td>{row.account_name||row.vehicle_name||"—"}</td><td>{Number(row.amount).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{row.notes||"—"}</td></tr>)}{tripDetailedReport.other_cash_movements.map(row=><tr key={row.id}><td>{row.transaction_date}</td><td>{row.category}</td><td>{row.beneficiary_name_snapshot||"الخزنة العامة"}</td><td>{Number(row.amount).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})}</td><td>{row.reference||"—"}</td></tr>)}{!tripDetailedReport.expenses.length&&!tripDetailedReport.other_cash_movements.length&&<tr><td colSpan={5}>لا توجد مصروفات أو مسحوبات مرتبطة بالرحلة.</td></tr>}</tbody></table></div>
     </>}
    </section>}

    {selected&&activeStep==="settlement"&&<section className="card trip-operation-card" style={{marginTop:14}}>
     <div className="panel-title"><span><PackageCheck size={15}/> تسوية الأصناف حسب المورد</span><button type="button" className="secondary" disabled={supplierSettlementLoading} onClick={()=>selected&&loadTripSupplierSettlements(selected.id)}><RefreshCw size={13}/> تحديث</button></div>
     <p className="trip-operation-intro">ملخص المنتجات المباعة والمرتجعة بحسب مصدر الشراء الفعلي. المبلغ هو تكلفة مصدر البضاعة وليس سعر بيع العميل، ويظل منفصلًا عن مديونية فواتير الشراء حتى لا يتكرر القيد.</p>
     {supplierSettlementLoading&&<div role="status" className="trip-inline-empty">جاري حساب كميات وتكلفة الأصناف لكل مورد...</div>}
     {supplierSettlementNote&&<div className="badge blue" role="note" style={{display:"block",padding:10,whiteSpace:"normal",marginBottom:10}}>{supplierSettlementNote}</div>}
     {!supplierSettlementLoading&&supplierSettlements.length===0&&<div className="trip-inline-empty">لا توجد مبيعات من مصدر شراء معروف في الرحلة حتى الآن. الأصناف القديمة أو غير المنسوبة لمورد ستظهر منفصلة عند وجود حركات بيع لها.</div>}
     {supplierSettlements.map((row,index)=><div key={`${row.supplier_id||"unknown"}-${index}`} style={{border:"1px solid #e2e8f0",borderRadius:12,padding:12,marginTop:10}}>
      <div style={{display:"flex",justifyContent:"space-between",gap:12,alignItems:"start",flexWrap:"wrap"}}><div><strong>{row.supplier_name}</strong><small style={{display:"block",color:"#64748b",marginTop:4}}>صافي الكمية {Number(row.net_quantity_base).toLocaleString("ar-EG")} · المباع {Number(row.sold_quantity_base).toLocaleString("ar-EG")} · المرتجع {Number(row.returned_quantity_base).toLocaleString("ar-EG")}</small></div><div style={{textAlign:"left"}}><strong style={{fontSize:18}}>{Number(row.settlement_amount).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} ج.م</strong><small style={{display:"block",color:"#64748b"}}>صافي تكلفة المصدر</small></div></div>
      <div className="table-wrap" style={{marginTop:10}}><table><thead><tr><th>الصنف</th><th>المباع</th><th>المرتجع</th><th>الصافي</th><th>تكلفة المصدر الصافية</th></tr></thead><tbody>{row.items.map(item=><tr key={item.product_id}><td>{item.product_name}</td><td>{Number(item.sold_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(item.returned_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(item.net_quantity_base).toLocaleString("ar-EG")}</td><td>{Number(item.settlement_amount).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} ج.م</td></tr>)}</tbody></table></div>
      {!row.supplier_id&&<div className="badge amber" style={{display:"block",padding:9,whiteSpace:"normal",marginTop:8}}>تحذير: مصدر بعض البضاعة غير منسوب لمورد؛ لن يتم إضافتها تلقائيًا لرصيد مورد بعينه.</div>}
     </div>)}
    </section>}

    {selected&&activeStep==="expenses"&&<section className="card trip-operation-card" style={{marginTop:14}}>
     <div className="panel-title"><span>مصروفات الرحلة</span><span className="badge blue">{tripExpenses.reduce((sum,item)=>sum+Number(item.amount),0).toLocaleString("ar-EG")} EGP</span></div>
     <p className="trip-operation-intro">سجّل الوقود أو الطريق أو الصيانة أو أي مصروف خاص بالرحلة. اختار الحساب والمبلغ والتاريخ؛ المصروفات المسجلة تدخل في التسوية وحساب الربحية.</p>
     <div className="form-grid">
      <label>نوع المصروف<input aria-label="نوع المصروف" value={expenseCategory} onChange={e=>setExpenseCategory(e.target.value)} maxLength={128} placeholder="وقود، طريق، صيانة..." disabled={!selected||expenseSaving||["completed","cancelled"].includes(selected?.status||"")}/></label>
      <label>الحساب المالي للمصروف<select aria-label="الحساب المالي للمصروف" value={expenseAccountId} onChange={e=>setExpenseAccountId(e.target.value)} disabled={!selected||expenseSaving||!expenseAccounts.length}>{expenseAccounts.map(a=><option key={a.id} value={a.id}>{a.name} — {a.code}</option>)}</select></label>
      <label>قيمة المصروف<input aria-label="قيمة المصروف" type="number" min="0.01" step="0.01" value={expenseAmount} onChange={e=>setExpenseAmount(e.target.value)} placeholder="0.00" disabled={!selected||expenseSaving||["completed","cancelled"].includes(selected?.status||"")}/></label>
      <label>تاريخ المصروف<input aria-label="تاريخ المصروف" type="date" value={expenseDate} onChange={e=>setExpenseDate(e.target.value)} disabled={!selected||expenseSaving||["completed","cancelled"].includes(selected?.status||"")}/></label>
      <label style={{gridColumn:"1 / -1"}}>ملاحظات (اختياري)<input aria-label="ملاحظات المصروف" value={expenseNotes} onChange={e=>setExpenseNotes(e.target.value)} maxLength={2000} placeholder="رقم الإيصال أو وصف المصروف" disabled={!selected||expenseSaving||["completed","cancelled"].includes(selected?.status||"")}/></label>
     </div>
     <button className="primary" disabled={expenseSaving||!expenseAccounts.length||["completed","cancelled"].includes(selected.status)} onClick={postExpense} style={{marginTop:10,width:"100%"}}>{expenseSaving?"جاري التسجيل...":"حفظ المصروف"}</button>
     {expenseMsg&&<div role="status" className="badge blue" style={{display:"block",marginTop:10,padding:10,whiteSpace:"normal"}}>{expenseMsg}</div>}
     {selected&&tripExpenses.map(e=><div key={e.id} style={{display:"flex",justifyContent:"space-between",gap:8,padding:"9px 0",borderBottom:"1px solid #eef2f7",fontSize:11}}><span><strong>{e.category}</strong><small style={{display:"block",color:"#64748b"}}>{e.expense_date} · {e.financial_account_name}{e.notes?` · ${e.notes}`:""}</small></span><strong>{Number(e.amount).toLocaleString("ar-EG")} EGP</strong></div>)}
     {selected&&!tripExpenses.length&&<div style={{padding:10,color:"#64748b",fontSize:11}}>لا توجد مصروفات مسجلة للرحلة المختارة.</div>}
    </section>}

    {selected&&activeStep==="settlement"&&<section className="card trip-operation-card" style={{marginTop:14}}>
     <div className="panel-title"><span>ملخص الربحية للرحلة المختارة</span><button className="secondary" disabled={!selected||profitLoading} onClick={()=>selected&&loadTripProfit(selected.id)}><RefreshCw size={13}/> تحديث الحساب</button></div>
     {!selected&&<div style={{color:"#64748b",fontSize:11}}>اختار رحلة علشان يظهر ملخص المبيعات والتكلفة والمصاريف.</div>}
     {profitLoading&&<div role="status" style={{fontSize:11,color:"#64748b"}}>جاري حساب الربحية من الفواتير والقيود المسجلة...</div>}
     {profitMsg&&<div role="alert" className="badge red" style={{display:"block",padding:10,whiteSpace:"normal"}}>{profitMsg}</div>}
     {tripProfit&&selected&&tripProfit.trip_number===selected.trip_number&&<>
      <div className="grid" style={{gridTemplateColumns:"repeat(auto-fit,minmax(145px,1fr))",marginBottom:12}}>
       <div className="metric card"><span className="metric-label">إجمالي المبيعات</span><div className="metric-value">{Number(tripProfit.sales_total).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">{tripProfit.sales_count} فاتورة</div></div>
       <div className="metric card"><span className="metric-label">مرتجعات العملاء</span><div className="metric-value">{Number(tripProfit.returns_total).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">{tripProfit.returns_count} مرتجع</div></div>
       <div className="metric card"><span className="metric-label">صافي المبيعات</span><div className="metric-value">{Number(tripProfit.net_sales).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">بعد خصم المرتجعات</div></div>
       <div className="metric card"><span className="metric-label">صافي تكلفة البضاعة المباعة</span><div className="metric-value">{Number(tripProfit.net_cost_of_goods_sold).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">بعد تكلفة الوحدات المرتجعة</div></div>
       <div className="metric card"><span className="metric-label">مجمل الربح</span><div className="metric-value">{Number(tripProfit.gross_profit).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">صافي المبيعات − صافي تكلفة البضاعة</div></div>
       <div className="metric card"><span className="metric-label">مصاريف الرحلة</span><div className="metric-value">{Number(tripProfit.trip_expenses).toLocaleString("ar-EG")}<span className="metric-unit">{tripProfit.currency}</span></div><div className="metric-note">المصروفات المسجلة على نفس الرحلة</div></div>
      </div>
      <div style={{display:"flex",justifyContent:"space-between",alignItems:"center",gap:12,padding:15,borderRadius:10,background:Number(tripProfit.net_trip_profit)>=0?"#f0fdf4":"#fef2f2",border:"1px solid #d1d5db"}}><div><strong>صافي ربح الرحلة التقديري</strong><small style={{display:"block",color:"#64748b",fontSize:10}}>مجمل الربح − المصروفات المباشرة المسجلة على الرحلة</small></div><strong style={{fontSize:22,color:Number(tripProfit.net_trip_profit)>=0?"#166534":"#991b1b"}}>{Number(tripProfit.net_trip_profit).toLocaleString("ar-EG")} {tripProfit.currency}</strong></div>
      <div style={{display:"grid",gap:6,marginTop:12}}><div className="badge amber" style={{display:"block",whiteSpace:"normal",padding:10}}>{tripProfit.costing_note}</div><div className="badge amber" style={{display:"block",whiteSpace:"normal",padding:10}}>{tripProfit.variance_policy_note}</div></div>
     </>}
    </section>}
   </section>
  </div>
 </main>
}
