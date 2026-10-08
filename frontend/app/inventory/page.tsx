"use client";
import { useEffect,useState } from "react";
import { Box, Search, Warehouse, AlertTriangle } from "lucide-react";
import { api } from "@/lib/api";
type Row={id:string;sku:string;name_ar:string;location_name:string;location_code:string;quantity:number;average_cost:number};
export default function Inventory(){
 const [data,setData]=useState<{data:Row[];total:number}>({data:[],total:0}),[q,setQ]=useState("");
 useEffect(()=>{api<{data:Row[];total:number}>("/inventory?per_page=100").then(setData).catch(()=>{})},[]);
 const rows=data.data.filter(x=>(x.name_ar+" "+x.sku).toLowerCase().includes(q.toLowerCase()));
 return <main className="main"><div className="topbar"><div><h1 className="title">المخزون</h1><div className="subtitle">Stock Ledger — الكمية، التكلفة، والموقع لحظيًا حسب آخر مزامنة</div></div><span className="connection"><span className="dot"/> آخر مزامنة</span></div>
 <div className="grid" style={{marginBottom:14}}>{([["أصناف ظاهرة",rows.length,Box],["مواقع المخزون",new Set(rows.map(x=>x.location_code)).size,Warehouse],["منخفض",rows.filter(x=>Number(x.quantity)<10).length,AlertTriangle],["إجمالي السجلات",data.total,Box]] as [string,string|number,import("lucide-react").LucideIcon][]).map(([a,b,I])=><div className="card metric" key={String(a)}><div className="metric-top"><span className="metric-label">{a}</span><span className="metric-icon"><I size={15}/></span></div><div className="metric-value">{b}</div></div>)}</div>
 <div className="card" style={{marginBottom:10,display:"flex",gap:10,alignItems:"center"}}><Search size={15} color="#64748b"/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو SKU..." style={{border:0,outline:0,flex:1,fontSize:12}}/></div>
 <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>الموقع</th><th>الكمية</th><th>متوسط التكلفة</th><th>الحالة</th></tr></thead><tbody>{rows.map(r=><tr key={r.id}><td><strong>{r.name_ar}</strong></td><td>{r.sku}</td><td>{r.location_name||r.location_code}</td><td>{Number(r.quantity).toLocaleString("ar-EG")}</td><td>{Number(r.average_cost||0).toLocaleString("ar-EG")} EGP</td><td><span className={`badge ${Number(r.quantity)<10?"amber":"green"}`}>{Number(r.quantity)<10?"منخفض":"متاح"}</span></td></tr>)}</tbody></table></div>
 </main>
}