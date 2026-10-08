"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import {
  ArrowLeft, BarChart3, Bell, Box, CarFront, CircleDollarSign, ClipboardList,
  FileText, LayoutDashboard, LogOut, Package, Search, Settings, Truck, Users,
  WalletCards, Warehouse
} from "lucide-react";
import { api } from "@/lib/api";

type Dashboard={sales:number;purchases:number;collections:number;expenses:number;receivables:number;payables:number;active_trips:number;inventory_units:number;inventory_value:number;today:string};

const nav=[
  ["الرئيسية","/",LayoutDashboard],["المبيعات","/sales",CircleDollarSign],["العملاء","/customers",Users],["المشتريات","/purchases",ClipboardList],
  ["المخزون","/inventory",Package],["الأصناف","/products",Package],["المستودعات","/locations",Warehouse],["السيارات والتوزيع","/trips",Truck],["الموردين","/suppliers",Users],
  ["التحصيلات","/payments",WalletCards],["المرتجعات","/returns",FileText],["التقارير","/reports",BarChart3]
] as const;

const quick=[
  ["بيع سريع","/sales",CircleDollarSign,"أسرع مسار للبيع"],
  ["فاتورة شراء","/purchases",ClipboardList,"إدخال جماعي"],
  ["عميل جديد","/customers",Users,"إضافة في ثواني"],
  ["تحميل عربية","/trips",Truck,"Warehouse → Vehicle"],
] as const;

export default function Home(){
 const [data,setData]=useState<Dashboard|null>(null); const [error,setError]=useState("");
 useEffect(()=>{api<Dashboard>("/dashboard").then(setData).catch(e=>setError(e.message))},[]);
 const logout=async()=>{try{await api("/auth/logout",{method:"POST"})}finally{localStorage.removeItem("nexora_token");window.location.href="/login"}};
 const money=(v:number)=>v.toLocaleString("ar-EG");
 return <div className="app">
  <aside className="sidebar">
   <div className="brand"><div className="brand-mark">N</div><div className="brand-name">NEXORA<small>Distribution OS</small></div></div>
   <nav className="nav">
    <div className="nav-section">إدارة التشغيل</div>
    {nav.map(([label,href,Icon],i)=><Link key={label} href={href} className={"nav-link "+(i===0?"active":"")}><Icon size={16}/><span>{label}</span></Link>)}
    <div className="nav-section">النظام</div><button><Settings size={16}/><span>الإعدادات</span></button>
   </nav>
   <div className="sidebar-bottom">
    <div className="user-mini"><div className="avatar">AD</div><div>Admin<small>مدير النظام</small></div></div>
    <button className="logout" onClick={logout}><LogOut size={14}/> خروج</button>
   </div>
  </aside>
  <main className="main">
   <div className="topbar">
    <div><h1 className="title">لوحة التحكم</h1><div className="subtitle">مركز التحكم في المبيعات والمخزون والتوزيع والتحصيلات</div></div>
    <div className="top-actions"><div className="command"><Search size={14}/> بحث في العملاء والمنتجات والفواتير <kbd>Ctrl K</kbd></div><button className="icon-btn"><Bell size={16}/></button><div className="connection"><span className="dot"/> النظام متصل</div></div>
   </div>
   {error&&<div className="card error">{error}</div>}
   <section className="hero">
    <div><h2 className="hero-title">NEXORA Distribution OS</h2><div className="hero-sub">من المخزن للعربية للعميل — كل حركة مسجلة ومترابطة في نظام واحد.</div></div>
    <div className="hero-flow">{["المخزن","العربية","الرحلة","العميل","البيع","التحصيل"].map((x,i)=><div key={x} style={{display:"flex",alignItems:"center",gap:7}}><span className="flow-pill">{x}</span>{i<5&&<ArrowLeft size={12} className="flow-arrow"/>}</div>)}</div>
   </section>
   <section className="grid">
    {([
      ["مبيعات اليوم",data?.sales??0,CircleDollarSign,"EGP","نشاط اليوم"],
      ["تحصيلات اليوم",data?.collections??0,WalletCards,"EGP","تم التحصيل"],
      ["مستحقات العملاء",data?.receivables??0,Users,"EGP","ذمم مدينة"],
      ["مستحقات الموردين",data?.payables??0,Warehouse,"EGP","ذمم دائنة"],
      ["مشتريات اليوم",data?.purchases??0,ClipboardList,"EGP","وارد اليوم"],
      ["مصروفات اليوم",data?.expenses??0,WalletCards,"EGP","تشغيل"],
      ["الرحلات النشطة",data?.active_trips??0,Truck,"رحلة","اليوم"],
      ["المخزون",data?.inventory_units??0,Box,"قطعة","إجمالي الوحدات"],
    ] as [string,number,import("lucide-react").LucideIcon,string,string][]).map(([label,value,Icon,unit,note])=><div className="card metric" key={String(label)}><div className="metric-top"><span className="metric-label">{label}</span><span className="metric-icon"><Icon size={15}/></span></div><div className="metric-value">{typeof value==="number"?money(value):value}<span className="metric-unit">{unit}</span></div><div className="metric-note">{note}</div></div>)}
   </section>
   <section className="section"><div className="section-head"><h2>إجراءات سريعة</h2><span className="section-link">Minimum Input → Maximum Result</span></div>
    <div className="quick">{quick.map(([label,href,Icon,sub])=><button key={label} onClick={()=>window.location.href=href}><span className="quick-icon"><Icon size={16}/></span><span><strong>{label}</strong><span>{sub}</span></span><ArrowLeft size={14} style={{marginRight:"auto",color:"#94a3b8"}}/></button>)}</div>
   </section>
   <section className="section split">
    <div className="card"><div className="panel-title"><span>حركة المبيعات والتحصيلات</span><span className="badge blue">هذا الأسبوع</span></div><div className="bars">{[42,65,54,82,61,92,73].map((h,i)=><div className="bar-wrap" key={i}><div className={`bar ${i===5?"primary":""}`} style={{height:`${h}%`}}/><span className="bar-label">{["سبت","أحد","اثن","ثلا","أرب","خمي","جمع"][i]}</span></div>)}</div></div>
    <div className="card"><div className="panel-title"><span>حالة التشغيل</span><span className="badge green">Healthy</span></div><div className="status-list">
      {[["المخزون","Ledger-based",""],["المبيعات","Atomic posting",""],["المشتريات","Stock + AP",""],["Offline Sync","Building","amber"]].map(([a,b,c])=><div className="status-row" key={a}><div className="status-main"><span className={`status-dot ${c}`}/><div>{a}<div className="status-sub">{b}</div></div></div><span className={`badge ${c==="amber"?"amber":"green"}`}>{c==="amber"?"قيد التطوير":"جاهز"}</span></div>)}
    </div></div>
   </section>
   <section className="section"><div className="section-head"><h2>آخر العمليات</h2><span className="section-link">عرض الكل</span></div><div className="table-wrap"><table><thead><tr><th>العملية</th><th>الطرف</th><th>المبلغ</th><th>الحالة</th><th>التوقيت</th></tr></thead><tbody>
    {[["فاتورة بيع","—","—","مكتملة","اليوم"],["تحصيل عميل","—","—","مكتملة","اليوم"],["تحميل عربية","—","—","قيد التنفيذ","اليوم"],["فاتورة شراء","—","—","مكتملة","أمس"]].map(x=><tr key={x[0]}>{x.map((v,i)=><td key={i}>{i===3?<span className={`badge ${v==="قيد التنفيذ"?"amber":"green"}`}>{v}</span>:v}</td>)}</tr>)}
   </tbody></table></div></section>
  </main>
  <nav className="mobile-nav"><button className="active"><LayoutDashboard size={17}/><br/>الرئيسية</button><button><Search size={17}/><br/>بحث</button><button><Package size={17}/><br/>مخزون</button><button><Users size={17}/><br/>عملاء</button></nav>
 </div>
}
