"use client";

import { useEffect,useState } from "react";
import { Plus, Search, MapPin, WalletCards, UserRound, ArrowRight, X, type LucideIcon } from "lucide-react";
import { api } from "@/lib/api";

type Customer={id:string;code:string;name:string;phone?:string;credit_limit:number;status:string;address_text?:string;payment_terms_days?:number};
type Form={name:string;phone:string;code:string;credit_limit:string;payment_terms_days:string;address_text:string;latitude:string;longitude:string};
const emptyForm:Form={name:"",phone:"",code:"",credit_limit:"",payment_terms_days:"",address_text:"",latitude:"",longitude:""};

export default function Customers(){
 const [rows,setRows]=useState<Customer[]>([]),[q,setQ]=useState(""),[loading,setLoading]=useState(true),[open,setOpen]=useState(false),[saving,setSaving]=useState(false),[msg,setMsg]=useState(""),[form,setForm]=useState<Form>(emptyForm);
 const load=()=>{setLoading(true);api<Customer[]>(`/customers?q=${encodeURIComponent(q)}`).then(setRows).catch(e=>setMsg(e.message)).finally(()=>setLoading(false))};
 useEffect(()=>{const t=setTimeout(load,250);return()=>clearTimeout(t)},[q]);
 const save=async(e:React.FormEvent)=>{e.preventDefault();setSaving(true);setMsg("");try{await api<Customer>("/customers",{method:"POST",body:JSON.stringify({name:form.name,phone:form.phone||null,code:form.code||null,credit_limit:Number(form.credit_limit||0),payment_terms_days:Number(form.payment_terms_days||0),address_text:form.address_text||null,latitude:form.latitude?Number(form.latitude):null,longitude:form.longitude?Number(form.longitude):null})});setMsg("تمت إضافة العميل بنجاح.");setOpen(false);setForm(emptyForm);await load()}catch(e){setMsg((e as Error).message)}finally{setSaving(false)}};
 return <main className="main">
  <div className="topbar"><div><h1 className="title">العملاء</h1><div className="subtitle">Customer 360 — العملاء، الأرصدة، المواقع، وحركة البيع</div></div><button className="primary" onClick={()=>{setMsg("");setOpen(true)}} style={{width:"auto",padding:"10px 15px",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> عميل جديد</button></div>
  <div className="card" style={{marginBottom:12,display:"flex",gap:10,alignItems:"center"}}><Search size={16} color="#64748b"/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو الهاتف أو كود العميل..." style={{border:0,outline:0,flex:1,fontSize:12,background:"transparent"}}/><span className="badge blue">{rows.length} نتيجة</span></div>
  {msg&&!open&&<div className="badge blue" style={{marginBottom:10,padding:9}}>{msg}</div>}
  <div className="grid" style={{marginBottom:14}}>{([["إجمالي العملاء",rows.length,UserRound],["نشط الآن",rows.filter(x=>x.status==="active").length,MapPin],["حسابات آجلة",rows.filter(x=>Number(x.credit_limit)>0).length,WalletCards],["بحث سريع","Ctrl K",Search]] as [string,string|number,LucideIcon][]).map(([a,b,I])=><div className="card metric" key={String(a)}><div className="metric-top"><span className="metric-label">{a}</span><span className="metric-icon"><I size={15}/></span></div><div className="metric-value">{b}</div></div>)}</div>
  <div className="table-wrap"><table><thead><tr><th>العميل</th><th>الكود</th><th>الهاتف</th><th>حد الائتمان</th><th>أجل</th><th>العنوان</th><th>الحالة</th><th></th></tr></thead><tbody>
   {loading?<tr><td colSpan={8}>جاري التحميل...</td></tr>:rows.map(c=><tr key={c.id}><td><strong>{c.name}</strong></td><td>{c.code}</td><td>{c.phone||"—"}</td><td>{Number(c.credit_limit).toLocaleString("ar-EG")} EGP</td><td>{Number(c.payment_terms_days||0)} يوم</td><td>{c.address_text||"—"}</td><td><span className="badge green">نشط</span></td><td><button className="icon-btn"><ArrowRight size={14}/></button></td></tr>)}
   {!loading&&!rows.length&&<tr><td colSpan={8}>لا يوجد عملاء مطابقون للبحث.</td></tr>}
  </tbody></table></div>
  {open&&<div style={{position:"fixed",inset:0,background:"#0b173080",display:"grid",placeItems:"center",zIndex:50,padding:18}}><form className="card" onSubmit={save} style={{width:"min(680px,100%)",maxHeight:"90vh",overflow:"auto"}}>
    <div className="panel-title"><span style={{fontSize:15}}>إضافة عميل جديد</span><button type="button" className="icon-btn" onClick={()=>setOpen(false)}><X size={14}/></button></div>
    <div className="form-grid">
      <label>اسم العميل<input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></label>
      <label>الهاتف<input value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></label>
      <label>كود العميل<input value={form.code} onChange={e=>setForm({...form,code:e.target.value})} placeholder="يتولد تلقائيًا لو فاضي"/></label>
      <label>حد الائتمان<input type="number" min="0" step="0.01" value={form.credit_limit} onChange={e=>setForm({...form,credit_limit:e.target.value})}/></label>
      <label>مدة السداد<input type="number" min="0" step="1" value={form.payment_terms_days} onChange={e=>setForm({...form,payment_terms_days:e.target.value})} placeholder="بالأيام"/></label>
      <label>العنوان<input value={form.address_text} onChange={e=>setForm({...form,address_text:e.target.value})}/></label>
      <label>Latitude<input type="number" step="any" value={form.latitude} onChange={e=>setForm({...form,latitude:e.target.value})}/></label>
      <label>Longitude<input type="number" step="any" value={form.longitude} onChange={e=>setForm({...form,longitude:e.target.value})}/></label>
    </div>
    {msg&&<div className="badge blue" role="alert" style={{display:"block",padding:10,marginTop:12,whiteSpace:"normal"}}>{msg}</div>}
    <div style={{display:"flex",gap:8,marginTop:14,justifyContent:"flex-end"}}><button type="button" className="icon-btn" onClick={()=>setOpen(false)}>إلغاء</button><button className="primary" disabled={saving}>{saving?"جاري الحفظ...":"حفظ العميل"}</button></div>
  </form></div>}
 </main>
}
