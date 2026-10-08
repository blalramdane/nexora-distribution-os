"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ArrowLeft, BarChart3, CircleDollarSign, ClipboardList, Package, Truck, Users, WalletCards } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { api } from "@/lib/api";

type Dashboard = {
  sales:number; purchases:number; collections:number; expenses:number;
  receivables:number; payables:number; active_trips:number;
  inventory_units:number; inventory_value:number; today:string;
};

const quick: Array<[string, string, LucideIcon, string]> = [
  ["بيع سريع","/sales",CircleDollarSign,"بيع وتحصيل في نفس المسار"],
  ["إضافة منتج","/products",Package,"SKU + تكلفة + سعر بيع"],
  ["عميل جديد","/customers",Users,"إنشاء تاجر/عميل في ثواني"],
  ["فاتورة شراء","/purchases",ClipboardList,"إدخال المخزون من المورد"],
] as const;

export default function Home() {
  const [data,setData] = useState<Dashboard|null>(null);
  const [error,setError] = useState("");

  useEffect(() => {
    api<Dashboard>("/dashboard").then(setData).catch(e => setError(e.message));
  }, []);

  const money=(v:number)=>Number(v||0).toLocaleString("ar-EG");

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">لوحة التحكم</h1><div className="subtitle">من المخزن للعربية للعميل — كل حركة مترابطة في نظام واحد.</div></div>
      <span className="connection"><span className="dot"/> النظام متصل</span>
    </div>

    {error && <div className="card error">تعذر تحميل أرقام لوحة التحكم: {error}</div>}

    <section className="hero">
      <div><h2 className="hero-title">NEXORA Distribution OS</h2><div className="hero-sub">Warehouse → Vehicle → Route → Customer → Sale → Collection → Settlement</div></div>
      <div className="hero-flow">{["المخزن","العربية","الرحلة","العميل","البيع","التحصيل"].map((x,i)=><div key={x} style={{display:"flex",alignItems:"center",gap:7}}><span className="flow-pill">{x}</span>{i<5&&<ArrowLeft size={12}/>}</div>)}</div>
    </section>

    <section className="grid">
      {[
        ["مبيعات اليوم",data?.sales??0,CircleDollarSign,"EGP"],
        ["تحصيلات اليوم",data?.collections??0,WalletCards,"EGP"],
        ["مستحقات العملاء",data?.receivables??0,Users,"EGP"],
        ["مستحقات الموردين",data?.payables??0,WalletCards,"EGP"],
        ["مشتريات اليوم",data?.purchases??0,ClipboardList,"EGP"],
        ["مصروفات اليوم",data?.expenses??0,WalletCards,"EGP"],
        ["الرحلات النشطة",data?.active_trips??0,Truck,"رحلة"],
        ["وحدات المخزون",data?.inventory_units??0,Package,"قطعة"],
      ].map(([label,value,Icon,unit]) => <div className="card metric" key={String(label)}>
        <div className="metric-top"><span className="metric-label">{label}</span><span className="metric-icon"><Icon size={15}/></span></div>
        <div className="metric-value">{money(Number(value))}<span className="metric-unit">{unit}</span></div>
        <div className="metric-note">بيانات حقيقية من الـAPI</div>
      </div>)}
    </section>

    <section className="section">
      <div className="section-head"><h2>ابدأ التشغيل</h2><span className="section-link">Minimum Input → Maximum Result</span></div>
      <div className="quick">{quick.map(([label,href,Icon,sub])=><Link key={href} href={href}><span className="quick-icon"><Icon size={16}/></span><span><strong>{label}</strong><span>{sub}</span></span><ArrowLeft size={14} style={{marginRight:"auto",color:"#94a3b8"}}/></Link>)}</div>
    </section>

    <section className="section split">
      <div className="card">
        <div className="panel-title"><span>تشغيل النظام</span><span className="badge green">API Connected</span></div>
        <div className="status-list">
          {[["Products","إضافة المنتج من الشاشة نفسها","/products"],["Customers","إنشاء عميل ثم استخدامه في البيع","/customers"],["Sales","البيع يخصم المخزون ويسجل الحساب","/sales"],["Inventory","الرصيد يتحدث بعد الحركات","/inventory"]].map(([a,b,href])=><Link href={href} className="status-row" key={a}><div className="status-main"><span className="status-dot"/><div>{a}<div className="status-sub">{b}</div></div></div><ArrowLeft size={13}/></Link>)}
        </div>
      </div>
      <div className="card">
        <div className="panel-title"><span>قيمة المخزون</span><BarChart3 size={16}/></div>
        <div className="metric-value">{money(data?.inventory_value??0)} <span className="metric-unit">EGP</span></div>
        <div className="metric-note">متوسط التكلفة × الكمية من stock balances</div>
      </div>
    </section>
  </main>;
}
