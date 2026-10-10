"use client";

import { useEffect,useState } from "react";
import { Plus, Route, Truck, Users, PlayCircle, MapPinned, ArrowUp, ArrowDown, WandSparkles } from "lucide-react";
import { api } from "@/lib/api";

type Vehicle={id:string;code:string;name:string;plate_number?:string};
type Location={id:string;name:string;code:string};
type Customer={id:string;name:string;code:string};
type Product={id:string;sku:string;name_ar:string;default_cost:number};
type LoadLine={product:Product;quantity:number};
type ClosingLine={product_id:string;name_ar:string;quantity:number};
type Trip={id:string;trip_number:string;status:string;trip_date:string;vehicle_name:string;rep_name:string};
type Me={id:string;name:string};
type RouteCustomer={customer_id:string;sequence:number;visit_status:string;code:string;name:string;phone?:string;address_text?:string;primary_address?:string;latitude?:number|null;longitude?:number|null};

export default function Trips(){
 const [products,setProducts]=useState<Product[]>([]),[loadLines,setLoadLines]=useState<LoadLine[]>([]),[closingLines,setClosingLines]=useState<ClosingLine[]>([]),[actualCash,setActualCash]=useState(""),[loadMsg,setLoadMsg]=useState(""),[vehicles,setVehicles]=useState<Vehicle[]>([]),[locations,setLocations]=useState<Location[]>([]),[customers,setCustomers]=useState<Customer[]>([]),[trips,setTrips]=useState<Trip[]>([]),[vehicle,setVehicle]=useState(""),[location,setLocation]=useState(""),[customer,setCustomer]=useState(""),[selected,setSelected]=useState<Trip|null>(null),[me,setMe]=useState<Me|null>(null),[msg,setMsg]=useState(""),[routeCustomers,setRouteCustomers]=useState<RouteCustomer[]>([]),[routeMsg,setRouteMsg]=useState(""),[loadingError,setLoadingError]=useState("");

 const load=()=>api<Trip[]>("/trips").then(setTrips).catch(e=>setLoadingError((e as Error).message));
 const loadRoute=(tripId:string)=>api<RouteCustomer[]>(`/trips/${tripId}/route`).then(setRouteCustomers).catch(e=>setRouteMsg((e as Error).message));

 useEffect(()=>{
  Promise.all([api<Vehicle[]>("/vehicles"),api<Location[]>("/locations"),api<Customer[]>("/customers"),api<Product[]>("/products"),api<Me>("/auth/me"),api<Trip[]>("/trips")]).then(([v,l,c,p,m,t])=>{setVehicles(v);setVehicle(v[0]?.id||"");setLocations(l);setLocation(l[0]?.id||"");setCustomers(c);setProducts(p);setMe(m);setTrips(t);setLoadingError("")}).catch(e=>setLoadingError((e as Error).message));
 },[]);

 const selectTrip=(t:Trip)=>{setSelected(t);loadRoute(t.id);refreshClosing(t.id)};

 const refreshClosing=(tripId:string)=>api<{product_id:string;name_ar:string;quantity_base:number}[]>(`/trips/${tripId}/stock`).then(rows=>setClosingLines(rows.map(r=>({product_id:r.product_id,name_ar:r.name_ar,quantity:Number(r.quantity_base)})))).catch(()=>setClosingLines([]));

 const create=async()=>{
  if(loadingError)return setMsg("بيانات الرحلات لم تُحمّل: "+loadingError);
  if(!vehicle||!location||!me)return setMsg("لازم يكون فيه عربية وموقع نشط ومستخدم مسجل قبل إنشاء الرحلة.");
  try{const t=await api<Trip>("/trips",{method:"POST",body:JSON.stringify({vehicle_id:vehicle,rep_user_id:me.id,origin_location_id:location})});setMsg("تم إنشاء الرحلة.");await load();selectTrip(t)}catch(e){setMsg((e as Error).message)}
 };

 const addLoad=(p:Product)=>{setLoadLines(x=>x.some(l=>l.product.id===p.id)?x.map(l=>l.product.id===p.id?{...l,quantity:l.quantity+1}:l):[...x,{product:p,quantity:1}])};

 const postLoad=async()=>{
  if(loadingError)return setLoadMsg("بيانات الشاشة لم تُحمّل: "+loadingError);
  if(!selected||!location||!loadLines.length)return setLoadMsg("اختار الرحلة والمخزن وأضف الأصناف.");
  if(loadLines.some(l=>!Number.isFinite(l.quantity)||l.quantity<=0))return setLoadMsg("كمية التحميل لازم تكون أكبر من صفر.");
  setLoadMsg("");
  try{await api("/trip-loads",{method:"POST",body:JSON.stringify({trip_id:selected.id,from_location_id:location,idempotency_key:crypto.randomUUID(),items:loadLines.map(l=>({product_id:l.product.id,quantity_base:l.quantity}))})});setLoadMsg("تم تحميل العربية وتحديث المخزون.");setLoadLines([]);load()}catch(e){setLoadMsg((e as Error).message)}
 };

 const assign=async()=>{
  if(loadingError)return setMsg("بيانات الشاشة لم تُحمّل: "+loadingError);
  if(!selected||!customer)return setMsg("اختار الرحلة والعميل.");
  try{await api(`/trips/${selected.id}/customers`,{method:"POST",body:JSON.stringify({customer_id:customer})});setMsg("تم إضافة العميل للرحلة.");setCustomer("");loadRoute(selected.id)}catch(e){setMsg((e as Error).message)}
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
  try{const result=await api<{expected_cash:number;cash_variance:number;stock_variance_value:number}>("/trip-settlements",{method:"POST",body:JSON.stringify({trip_id:selected.id,actual_cash:Number(actualCash),opening_cash:0,closing_items:closingLines.map(l=>({product_id:l.product_id,quantity_base:l.quantity}))})});setLoadMsg(`تمت التسوية — المتوقع ${result.expected_cash} EGP، فرق النقدية ${result.cash_variance} EGP`);load()}catch(e){setLoadMsg((e as Error).message)}
 };

 return <main className="main">
  <div className="topbar"><div><h1 className="title">العربيات والرحلات</h1><div className="subtitle">Vehicle → Load → Route → Customer Visits → Sales → Settlement</div></div><span className="badge blue"><Route size={11}/> Distribution</span></div>
  {loadingError&&<div className="badge red" role="alert" style={{display:"block",padding:12,marginBottom:14,whiteSpace:"normal"}}>تعذر تحميل بيانات التشغيل: {loadingError} <button className="secondary" onClick={()=>{setLoadingError("");Promise.all([api<Vehicle[]>("/vehicles"),api<Location[]>("/locations"),api<Customer[]>("/customers"),api<Product[]>("/products"),api<Me>("/auth/me"),api<Trip[]>("/trips")]).then(([v,l,c,p,m,t])=>{setVehicles(v);setVehicle(v[0]?.id||"");setLocations(l);setLocation(l[0]?.id||"");setCustomers(c);setProducts(p);setMe(m);setTrips(t)}).catch(e=>setLoadingError((e as Error).message))}}>إعادة المحاولة</button></div>}

  <div className="split">
   <section className="card">
    <div className="panel-title"><span>إنشاء رحلة</span><span className="badge green">{trips.length} رحلات</span></div>
    <div className="form-grid">
     <label>العربية<select value={vehicle} onChange={e=>setVehicle(e.target.value)}>{vehicles.map(v=><option key={v.id} value={v.id}>{v.name} — {v.code}</option>)}</select></label>
     <label>نقطة الانطلاق<select value={location} onChange={e=>setLocation(e.target.value)}>{locations.map(l=><option key={l.id} value={l.id}>{l.name}</option>)}</select></label>
    </div>
    <button className="primary" onClick={create} style={{marginTop:14,display:"flex",justifyContent:"center",gap:8}}><Plus size={15}/> إنشاء رحلة اليوم</button>

    <div className="panel-title" style={{marginTop:20}}>إضافة عميل للرحلة</div>
    <select className="field" value={selected?.id||""} onChange={e=>{const t=trips.find(t=>t.id===e.target.value)||null;if(t)selectTrip(t);}}><option value="">اختار الرحلة...</option>{trips.map(t=><option key={t.id} value={t.id}>{t.trip_number} — {t.vehicle_name}</option>)}</select>
    <select className="field" value={customer} onChange={e=>setCustomer(e.target.value)} style={{marginTop:8}}><option value="">اختار العميل...</option>{customers.map(c=><option key={c.id} value={c.id}>{c.name} — {c.code}</option>)}</select>
    <button className="secondary" onClick={assign} style={{marginTop:8,width:"100%"}}><Users size={14}/> إضافة للرحلة</button>

    {selected&&<section className="card" style={{marginTop:14,background:"#f8fafc"}}>
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
    </section>}
   </section>

   <section className="card">
    <div className="panel-title">رحلات التشغيل</div>
    {trips.map(t=><button key={t.id} onClick={()=>selectTrip(t)} style={{width:"100%",textAlign:"right",border:0,background:"#fff",padding:"13px 4px",borderBottom:"1px solid #eef2f7",display:"flex",justifyContent:"space-between",alignItems:"center"}}>
     <span><strong>{t.trip_number}</strong><small style={{display:"block",color:"#64748b"}}>{t.vehicle_name} · {t.rep_name}</small></span><span className={`badge ${t.status==="planned"?"blue":"green"}`}>{t.status==="planned"?"مخططة":"نشطة"}</span>
    </button>)}
    {!trips.length&&<div style={{padding:40,textAlign:"center",color:"#64748b"}}><Truck size={28}/><p>مفيش رحلات لسه. أنشئ أول رحلة.</p></div>}
    {msg&&<div className="badge blue" style={{marginTop:12}}>{msg}</div>}
    <div className="card" style={{marginTop:15,background:"#f8fafc"}}><div className="panel-title">الخطوة التالية</div><div style={{display:"flex",gap:9,alignItems:"center",fontSize:11}}><PlayCircle size={18}/> تحميل مخزون العربية ثم بدء الزيارات والمبيعات والتحصيلات.</div></div>

    <section className="card" style={{marginTop:14}}><div className="panel-title"><span>تحميل العربية</span><span className="badge blue">{loadLines.length} أصناف</span></div><div style={{display:"flex",gap:7,flexWrap:"wrap"}}>{products.slice(0,12).map(p=><button key={p.id} className="secondary" onClick={()=>addLoad(p)} style={{fontSize:9}}>{p.name_ar}</button>)}</div>{loadLines.map((l,i)=><div key={l.product.id} style={{display:"flex",justifyContent:"space-between",padding:"8px 0",borderBottom:"1px solid #eef2f7",fontSize:10}}><span>{l.product.name_ar}</span><input type="number" min="1" value={l.quantity} onChange={e=>setLoadLines(x=>x.map((v,j)=>j===i?{...v,quantity:Number(e.target.value)}:v))} style={{width:70,padding:5,border:"1px solid #e2e8f0",borderRadius:6}}/></div>)}<button className="primary" onClick={postLoad} style={{marginTop:10}}>تحميل العربية</button></section>

    <section className="card" style={{marginTop:14}}><div className="panel-title"><span>تسوية الرحلة</span><span className="badge amber">Settlement</span></div>{closingLines.map((l,i)=><div key={l.product_id} style={{display:"flex",justifyContent:"space-between",padding:"7px 0",borderBottom:"1px solid #eef2f7",fontSize:10}}><span>{l.name_ar}</span><input type="number" min="0" value={l.quantity} onChange={e=>setClosingLines(x=>x.map((v,j)=>j===i?{...v,quantity:Number(e.target.value)}:v))} style={{width:75,padding:5,border:"1px solid #e2e8f0",borderRadius:6}}/></div>)}<label className="metric-label">النقدية الفعلية</label><input className="field" type="number" value={actualCash} onChange={e=>setActualCash(e.target.value)} placeholder="0.00" style={{marginTop:6}}/><button className="primary" onClick={settle} style={{marginTop:10}}>إغلاق وتسوية الرحلة</button>{loadMsg&&<div className="badge blue" style={{marginTop:10}}>{loadMsg}</div>}</section>
   </section>
  </div>
 </main>
}
