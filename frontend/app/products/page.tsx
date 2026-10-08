"use client";

import { FormEvent, useEffect, useState } from "react";
import { Package, Plus, Search } from "lucide-react";
import { api } from "@/lib/api";

type Product={id:string;sku:string;name_ar:string;brand?:string;default_cost:number;default_piece_price:number};
type Meta={units:{id:string;name_ar:string;code:string}[];categories:{id:string;name_ar:string;code:string}[]};

export default function Products(){
 const [rows,setRows]=useState<Product[]>([]);
 const [meta,setMeta]=useState<Meta>({units:[],categories:[]});
 const [q,setQ]=useState("");
 const [open,setOpen]=useState(false);
 const [saving,setSaving]=useState(false);
 const [msg,setMsg]=useState("");
 const [form,setForm]=useState({sku:"",name_ar:"",name_en:"",brand:"",base_unit_id:"",category_id:"",default_cost:"",default_piece_price:""});

 const load=()=>api<Product[]>("/products?q="+encodeURIComponent(q)).then(setRows).catch(e=>setMsg(e.message));
 useEffect(()=>{load();api<Meta>("/products/meta").then(x=>{setMeta(x);setForm(f=>({...f,base_unit_id:f.base_unit_id||x.units.find(u=>u.code==="piece")?.id||""}))}).catch(e=>setMsg(e.message))},[q]);

 const submit=async(e:FormEvent)=>{e.preventDefault();setSaving(true);setMsg("");
  try{await api("/products",{method:"POST",body:JSON.stringify({...form,default_cost:Number(form.default_cost||0),default_piece_price:Number(form.default_piece_price||0),category_id:form.category_id||null})});setOpen(false);setForm(f=>({...f,sku:"",name_ar:"",name_en:"",brand:"",default_cost:"",default_piece_price:""}));setMsg("تمت إضافة المنتج بنجاح.");load();}
  catch(err){setMsg(err instanceof Error?err.message:"تعذر إضافة المنتج.");}finally{setSaving(false);}
 };

 return <main className="main">
  <div className="topbar"><div><h1 className="title">المنتجات</h1><div className="subtitle">Product Master — SKU، وحدة القياس، التكلفة وسعر البيع</div></div><button className="primary" onClick={()=>setOpen(true)} style={{width:"auto",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> منتج جديد</button></div>
  {msg&&<div className="card" style={{marginBottom:12}}><span className="badge blue">{msg}</span></div>}
  <div className="card" style={{marginBottom:12,display:"flex",gap:8,alignItems:"center"}}><Search size={15}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو SKU..." style={{border:0,outline:0,flex:1}}/><span className="badge blue"><Package size={11}/> {rows.length}</span></div>
  <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>البراند</th><th>التكلفة</th><th>سعر البيع</th><th>الحالة</th></tr></thead><tbody>
   {rows.map(p=><tr key={p.id}><td><strong>{p.name_ar}</strong></td><td>{p.sku}</td><td>{p.brand||"—"}</td><td>{Number(p.default_cost).toLocaleString("ar-EG")} EGP</td><td>{Number(p.default_piece_price).toLocaleString("ar-EG")} EGP</td><td><span className="badge green">نشط</span></td></tr>)}
   {!rows.length&&<tr><td colSpan={6}>لا توجد منتجات. أضف أول منتج وابدأ اختبار الشراء والبيع.</td></tr>}
  </tbody></table></div>

  {open&&<div className="modal-backdrop" onMouseDown={e=>e.currentTarget===e.target&&setOpen(false)}>
   <form className="modal-card" onSubmit={submit}>
    <div className="panel-title"><span>إضافة منتج</span><button type="button" className="secondary" onClick={()=>setOpen(false)}>إغلاق</button></div>
    <div className="form-grid">
      <label>SKU<input required value={form.sku} onChange={e=>setForm({...form,sku:e.target.value})} placeholder="SKU-001"/></label>
      <label>اسم المنتج بالعربي<input required value={form.name_ar} onChange={e=>setForm({...form,name_ar:e.target.value})} placeholder="شنيور كهرباء"/></label>
      <label>الاسم بالإنجليزي<input value={form.name_en} onChange={e=>setForm({...form,name_en:e.target.value})}/></label>
      <label>البراند<input value={form.brand} onChange={e=>setForm({...form,brand:e.target.value})}/></label>
      <label>وحدة القياس<select required value={form.base_unit_id} onChange={e=>setForm({...form,base_unit_id:e.target.value})}>{meta.units.map(u=><option key={u.id} value={u.id}>{u.name_ar}</option>)}</select></label>
      <label>التصنيف<select value={form.category_id} onChange={e=>setForm({...form,category_id:e.target.value})}><option value="">بدون تصنيف</option>{meta.categories.map(c=><option key={c.id} value={c.id}>{c.name_ar}</option>)}</select></label>
      <label>التكلفة الافتراضية<input type="number" min="0" step="0.01" value={form.default_cost} onChange={e=>setForm({...form,default_cost:e.target.value})}/></label>
      <label>سعر البيع<input type="number" min="0" step="0.01" value={form.default_piece_price} onChange={e=>setForm({...form,default_piece_price:e.target.value})}/></label>
    </div>
    <div className="form-actions"><button className="primary" disabled={saving}>{saving?"جاري الحفظ...":"حفظ المنتج"}</button></div>
   </form>
  </div>}
 </main>
}
