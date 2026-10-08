"use client";

import { useEffect, useState } from "react";
import { Package, Plus, Search, X } from "lucide-react";
import { api } from "@/lib/api";

type Product={id:string;sku:string;name_ar:string;name_en?:string;brand?:string;base_unit_id:string;default_cost:number;default_piece_price:number};
type Ref={units:{id:string;name_ar:string}[];categories:{id:string;name_ar:string}[]};

export default function Products(){
 const [rows,setRows]=useState<Product[]>([]),[refs,setRefs]=useState<Ref>({units:[],categories:[]}),[q,setQ]=useState(""),[open,setOpen]=useState(false),[saving,setSaving]=useState(false),[msg,setMsg]=useState("");
 const [form,setForm]=useState({sku:"",name_ar:"",name_en:"",brand:"",category_id:"",base_unit_id:"",default_cost:"",default_piece_price:""});
 const load=()=>Promise.all([api<Product[]>("/products?q="+encodeURIComponent(q)),api<Ref>("/catalog/references")]).then(([p,r])=>{setRows(p);setRefs(r);if(!form.base_unit_id&&r.units[0])setForm(x=>({...x,base_unit_id:r.units[0].id}))});
 useEffect(()=>{const t=setTimeout(()=>load().catch(e=>setMsg(e.message)),180);return()=>clearTimeout(t)},[q]);
 const save=async(e:React.FormEvent)=>{e.preventDefault();setSaving(true);setMsg("");try{await api("/products",{method:"POST",body:JSON.stringify({...form,default_cost:Number(form.default_cost||0),default_piece_price:Number(form.default_piece_price||0),category_id:form.category_id||null})});setMsg("تمت إضافة الصنف بنجاح.");setOpen(false);setForm(x=>({...x,sku:"",name_ar:"",name_en:"",brand:"",category_id:"",default_cost:"",default_piece_price:""}));await load()}catch(e){setMsg((e as Error).message)}finally{setSaving(false)}};
 return <main className="main">
  <div className="topbar"><div><h1 className="title">الأصناف والكتالوج</h1><div className="subtitle">Product Master — تعريف الأصناف والأسعار والوحدات</div></div><button className="primary" onClick={()=>setOpen(true)} style={{width:"auto",padding:"10px 15px",display:"flex",gap:7,alignItems:"center"}}><Plus size={15}/> صنف جديد</button></div>
  <div className="grid" style={{marginBottom:12}}>{[["الأصناف",rows.length],["الوحدات",refs.units.length],["التصنيفات",refs.categories.length],["نتائج البحث",rows.length]].map(([a,b])=><div className="card metric" key={String(a)}><div className="metric-top"><span className="metric-label">{a}</span><span className="metric-icon"><Package size={15}/></span></div><div className="metric-value">{b}</div></div>)}</div>
  <div className="card" style={{marginBottom:12,display:"flex",gap:9,alignItems:"center"}}><Search size={16} color="#64748b"/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="ابحث بالاسم أو SKU..." style={{border:0,outline:0,flex:1,fontSize:12}}/>{q&&<button className="icon-btn" onClick={()=>setQ("")}><X size={13}/></button>}</div>
  {msg&&<div className="badge blue" style={{marginBottom:10,padding:8}}>{msg}</div>}
  <div className="table-wrap"><table><thead><tr><th>الصنف</th><th>SKU</th><th>البراند</th><th>الوحدة</th><th>التكلفة</th><th>سعر البيع</th></tr></thead><tbody>{rows.map(p=><tr key={p.id}><td><strong>{p.name_ar}</strong>{p.name_en&&<small style={{display:"block",color:"#64748b"}}>{p.name_en}</small>}</td><td>{p.sku}</td><td>{p.brand||"—"}</td><td>{refs.units.find(u=>u.id===p.base_unit_id)?.name_ar||"—"}</td><td>{Number(p.default_cost||0).toLocaleString("ar-EG")} EGP</td><td><strong>{Number(p.default_piece_price||0).toLocaleString("ar-EG")} EGP</strong></td></tr>)}{!rows.length&&<tr><td colSpan={6}>لا توجد أصناف.</td></tr>}</tbody></table></div>
  {open&&<div style={{position:"fixed",inset:0,background:"#0b173080",display:"grid",placeItems:"center",zIndex:50,padding:18}}><form className="card" onSubmit={save} style={{width:"min(620px,100%)",maxHeight:"90vh",overflow:"auto"}}><div className="panel-title"><span style={{fontSize:15}}>إضافة صنف جديد</span><button type="button" className="icon-btn" onClick={()=>setOpen(false)}><X size={14}/></button></div><div className="form-grid">
   <label>SKU<input required value={form.sku} onChange={e=>setForm({...form,sku:e.target.value})}/></label>
   <label>اسم الصنف<input required value={form.name_ar} onChange={e=>setForm({...form,name_ar:e.target.value})}/></label>
   <label>الاسم بالإنجليزي<input value={form.name_en} onChange={e=>setForm({...form,name_en:e.target.value})}/></label>
   <label>البراند<input value={form.brand} onChange={e=>setForm({...form,brand:e.target.value})}/></label>
   <label>التصنيف<select value={form.category_id} onChange={e=>setForm({...form,category_id:e.target.value})}><option value="">بدون تصنيف</option>{refs.categories.map(c=><option key={c.id} value={c.id}>{c.name_ar}</option>)}</select></label>
   <label>الوحدة الأساسية<select required value={form.base_unit_id} onChange={e=>setForm({...form,base_unit_id:e.target.value})}>{refs.units.map(u=><option key={u.id} value={u.id}>{u.name_ar}</option>)}</select></label>
   <label>التكلفة<input type="number" min="0" step="0.01" value={form.default_cost} onChange={e=>setForm({...form,default_cost:e.target.value})}/></label>
   <label>سعر البيع<input type="number" min="0" step="0.01" value={form.default_piece_price} onChange={e=>setForm({...form,default_piece_price:e.target.value})}/></label>
  </div><button className="primary" disabled={saving} style={{marginTop:14}}>{saving?"جاري الحفظ...":"حفظ الصنف"}</button></form></div>}
 </main>
}
