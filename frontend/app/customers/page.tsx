"use client";
import { useEffect,useState } from "react";
import { Plus, Search, MapPin, Phone, WalletCards, UserRound, ArrowRight } from "lucide-react";
import { api } from "@/lib/api";

type Customer={id:string;code:string;name:string;phone?:string;credit_limit:number;status:string};
export default function Customers(){
 const [rows,setRows]=useState<Customer[]>([]),[q,setQ]=useState(""),[loading,setLoading]=useState(true);
 const load=()=>{setLoading(true);api<Customer[]>(`/customers?q=${encodeURIComponent(q)}`).then(setRows).finally(()=>setLoading(false))};
 useEffect(()=>{const t=setTimeout(load,250);return()=>clearTimeout(t)},[q]);
 return <main className="main">
  <div className="topbar"><div><h1 className="title">العملاء</h1><div className="subtitle">Customer 360 — العملاء، الأرصدة، المواقع، وحركة البيع</div></div><button className="primary" style={{width:"auto",padding:"10px 15px",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> عميل جديد</button></div>
  <div className="card" style={{marginBottom:12,display:"flex",gap:10,alignItems:"center"}}><Search size={16} color="#64748b"/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو الهاتف أو كود العميل..." style={{border:0,outline:0,flex:1,fontSize:12,background:"transparent"}}/><span className="badge blue">{rows.length} نتيجة</span></div>
  <div className="grid" style={{marginBottom:14}}>
   {[["إجمالي العملاء",rows.length,UserRound],["نشط الآن",rows.filter(x=>x.status==="active").length,MapPin],["حسابات آجلة",rows.filter(x=>Number(x.credit_limit)>0).length,WalletCards],["بحث سريع","Ctrl K",Search]].map(([a,b,I])=><div className="card metric" key={String(a)}><div className="metric-top"><span className="metric-label">{a}</span><span className="metric-icon"><I size={15}/></span></div><div className="metric-value">{b}</div></div>)}
  </div>
  <div className="table-wrap"><table><thead><tr><th>العميل</th><th>الكود</th><th>الهاتف</th><th>حد الائتمان</th><th>الحالة</th><th></th></tr></thead><tbody>
   {loading?<tr><td colSpan={6}>جاري التحميل...</td></tr>:rows.map(c=><tr key={c.id}><td><strong>{c.name}</strong></td><td>{c.code}</td><td>{c.phone||"—"}</td><td>{Number(c.credit_limit).toLocaleString("ar-EG")} EGP</td><td><span className="badge green">نشط</span></td><td><button className="icon-btn"><ArrowRight size={14}/></button></td></tr>)}
   {!loading&&!rows.length&&<tr><td colSpan={6}>لا يوجد عملاء مطابقون للبحث.</td></tr>}
  </tbody></table></div>
 </main>
}