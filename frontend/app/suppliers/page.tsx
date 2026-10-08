"use client";
import { useEffect,useState } from "react";
import { Plus, Search, Users } from "lucide-react";
import { api } from "@/lib/api";

type Supplier={id:string;name:string;code:string;phone?:string;status:string};
export default function Suppliers(){
 const [rows,setRows]=useState<Supplier[]>([]),[q,setQ]=useState(""),[open,setOpen]=useState(false),[msg,setMsg]=useState(""),[saving,setSaving]=useState(false);
 const [form,setForm]=useState({name:"",code:"",phone:"",address:"",credit_terms_days:""});
 const load=()=>api<Supplier[]>("/suppliers?q="+encodeURIComponent(q)).then(setRows).catch(e=>setMsg(e.message));
 useEffect(()=>{const t=setTimeout(load,200);return()=>clearTimeout(t)},[q]);
 const submit=async(e:React.FormEvent)=>{e.preventDefault();setSaving(true);setMsg("");
  try{await api("/suppliers",{method:"POST",body:JSON.stringify({...form,code:form.code||null,credit_terms_days:Number(form.credit_terms_days||0)})});setOpen(false);setForm({name:"",code:"",phone:"",address:"",credit_terms_days:""});setMsg("تمت إضافة المورد بنجاح.");load();}
  catch(err){setMsg(err instanceof Error?err.message:"تعذر إضافة المورد.");}finally{setSaving(false);}
 };
 return <main className="main">
  <div className="topbar"><div><h1 className="title">الموردين</h1><div className="subtitle">Suppliers — المصدر والحسابات الدائنة</div></div><button className="primary" onClick={()=>setOpen(true)} style={{width:"auto",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> مورد جديد</button></div>
  {msg&&<div className="card" style={{marginBottom:12}}><span className="badge blue">{msg}</span></div>}
  <div className="card" style={{marginBottom:12,display:"flex",gap:8}}><Search size={15}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو الكود أو الهاتف..." style={{border:0,outline:0,flex:1}}/></div>
  <div className="table-wrap"><table><thead><tr><th>المورد</th><th>الكود</th><th>الهاتف</th><th>الحالة</th></tr></thead><tbody>{rows.map(s=><tr key={s.id}><td><strong>{s.name}</strong></td><td>{s.code}</td><td>{s.phone||"—"}</td><td><span className="badge green">نشط</span></td></tr>)}{!rows.length&&<tr><td colSpan={4}>لا يوجد موردين. أضف موردًا لتجربة دورة الشراء.</td></tr>}</tbody></table></div>
  {open&&<div className="modal-backdrop" onMouseDown={e=>e.currentTarget===e.target&&setOpen(false)}>
   <form className="modal-card" onSubmit={submit}>
    <div className="panel-title"><span>إضافة مورد</span><button type="button" className="secondary" onClick={()=>setOpen(false)}>إغلاق</button></div>
    <div className="form-grid">
      <label>اسم المورد<input required value={form.name} onChange={e=>setForm({...form,name:e.target.value})}/></label>
      <label>الكود<input value={form.code} onChange={e=>setForm({...form,code:e.target.value})} placeholder="اتركه تلقائيًا"/></label>
      <label>الهاتف<input value={form.phone} onChange={e=>setForm({...form,phone:e.target.value})}/></label>
      <label>أيام الائتمان<input type="number" min="0" value={form.credit_terms_days} onChange={e=>setForm({...form,credit_terms_days:e.target.value})}/></label>
      <label style={{gridColumn:"1/-1"}}>العنوان<input value={form.address} onChange={e=>setForm({...form,address:e.target.value})}/></label>
    </div>
    <div className="form-actions"><button className="primary" disabled={saving}>{saving?"جاري الحفظ...":"حفظ المورد"}</button></div>
   </form>
  </div>}
 </main>
}
