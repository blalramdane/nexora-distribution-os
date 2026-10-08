"use client";
import { useEffect,useState } from "react";
import { Plus, Search, MapPin, WalletCards, UserRound, ArrowRight, type LucideIcon } from "lucide-react";
import { api } from "@/lib/api";

type Customer={id:string;code:string;name:string;phone?:string;credit_limit:number;status:string};
export default function Customers(){
 const [rows,setRows]=useState<Customer[]>([]),[q,setQ]=useState(""),[loading,setLoading]=useState(true);
 const [open,setOpen]=useState(false),[saving,setSaving]=useState(false),[msg,setMsg]=useState("");
 const [form,setForm]=useState({name:"",phone:"",code:"",address_text:"",credit_limit:"",payment_terms_days:""});
 const load=()=>{setLoading(true);api<Customer[]>("/customers?q="+encodeURIComponent(q)).then(setRows).catch(e=>setMsg(e.message)).finally(()=>setLoading(false))};
 useEffect(()=>{const t=setTimeout(load,250);return()=>clearTimeout(t)},[q]);

 const submit=async(e:React.FormEvent)=>{e.preventDefault();setSaving(true);setMsg("");
  try{await api("/customers",{method:"POST",body:JSON.stringify({...form,credit_limit:Number(form.credit_limit||0),payment_terms_days:Number(form.payment_terms_days||0),code:form.code||null})});setOpen(false);setForm({name:"",phone:"",code:"",address_text:"",credit_limit:"",payment_terms_days:""});setMsg("تمت إضافة العميل بنجاح.");load();}
  catch(err){setMsg(err instanceof Error?err.message:"تعذر إضافة العميل.");}finally{setSaving(false);}
 };

 return <main className="main">
  <div className="topbar"><div><h1 className="title">العملاء / التجار</h1><div className="subtitle">Customer 360 — بيانات التاجر، الرصيد، الائتمان وحركة البيع</div></div><button className="primary" onClick={()=>setOpen(true)} style={{width:"auto",padding:"10px 15px",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> تاجر جديد</button></div>
  {msg&&<div className="card" style={{marginBottom:12}}><span className="badge blue">{msg}</span></div>}
  <div className="card" style={{marginBottom:12,display:"flex",gap:10,alignItems:"center"}}><Search size={16} color="#64748b"/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو الهاتف أو كود العميل..." style={{border:0,outline:0,flex:1,fontSize:12,background:"transparent"}}/><span className="badge blue">{rows.length} نتيجة</span></div>
  <div className="grid" style={{marginBottom:14}}>
   {([["إجمالي التجار",rows.length,UserRound],["نشط الآن",rows.filter(x=>x.status==="active").length,MapPin],["حسابات آجلة",rows.filter(x=>Number(x.credit_limit)>0).length,WalletCards],["بحث سريع","Ctrl K",Search]] as [string,string|number,LucideIcon][]).map(([a,b,I])=><div className="card metric" key={String(a)}><div className="metric-top"><span className="metric-label">{a}</span><span className="metric-icon"><I size={15}/></span></div><div className="metric-value">{b}</div></div>)}
  </div>
  <div className="table-wrap"><table><thead><tr><th>التاجر</th><th>الكود</th><th>الهاتف</th><th>حد الائتمان</th><th>الحالة</th><th></th></tr></thead><tbody>
   {loading?<tr><td colSpan={6}>جاري التحميل...</td></tr>:rows.map(c=><tr key={c.id}><td><strong>{c.name}</strong></td><td>{c.code}</td><td>{c.phone||"—"}</td><td>{Number(c.credit_limit).toLocaleString("ar-EG")} EGP</td><td><span className="badge green">نشط</span></td><td><button className="icon-btn"><ArrowRight size={14}/></button></td></tr>)}
   {!loading&&!rows.length&&<tr><td colSpan={6}>لا يوجد تجار مطابقون للبحث.</td></tr>}
  </tbody></table></div>

  {open&&<div className="modal-backdrop" onMouseDown={e=>e.currentTarget===e.target&&setOpen(false)}>
   <form className="modal-card" onSubmit={submit}>
    <div className="panel-title"><span>إضافة تاجر / عميل</span><button type="button" className="secondary" onClick={()=>setOpen(false)}>إغلاق</button></div>
    <div className="form-grid">
      <label>اسم التاجر<input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})} placeholder="مثال: محل النور"/></label>
      <label>الهاتف<input value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})} placeholder="01xxxxxxxxx"/></label>
      <label>كود العميل<input value={form.code} onChange={e=>setForm({...form,code:e.target.value})} placeholder="اتركه تلقائيًا"/></label>
      <label>العنوان<input value={form.address_text} onChange={e=>setForm({...form,address_text:e.target.value})}/></label>
      <label>حد الائتمان<input type="number" min="0" step="0.01" value={form.credit_limit} onChange={e=>setForm({...form,credit_limit:e.target.value})}/></label>
      <label>أيام الائتمان<input type="number" min="0" value={form.payment_terms_days} onChange={e=>setForm({...form,payment_terms_days:e.target.value})}/></label>
    </div>
    <div className="form-actions"><button className="primary" disabled={saving}>{saving?"جاري الحفظ...":"حفظ التاجر"}</button></div>
   </form>
  </div>}
 </main>
}
