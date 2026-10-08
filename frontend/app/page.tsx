"use client";

import { useEffect, useState } from "react";
import { ArrowLeft, Box, CarFront, CircleDollarSign, ClipboardList, LayoutDashboard, LogOut, Package, Search, Users } from "lucide-react";
import { api } from "@/lib/api";

type Dashboard = {
  sales: number;
  purchases: number;
  collections: number;
  expenses: number;
  receivables: number;
  payables: number;
  today: string;
};

const quick: Array<{ label: string; href: string; Icon: React.ComponentType<{ size?: number }> }> = [
  { label: "بيع سريع", href: "/sales", Icon: CircleDollarSign },
  { label: "فاتورة شراء", href: "/purchases", Icon: ClipboardList },
  { label: "عميل جديد", href: "/customers", Icon: Users },
  { label: "تحميل عربية", href: "/trips", Icon: CarFront },
];

export default function Home() {
  const [data, setData] = useState<Dashboard | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    api<Dashboard>("/dashboard").then(setData).catch((e) => setError(e.message));
  }, []);

  const logout = async () => {
    try { await api("/auth/logout", { method: "POST" }); } finally {
      localStorage.removeItem("nexora_token");
      window.location.href = "/login";
    }
  };

  return (
    <div className="app">
      <aside className="sidebar">
        <div className="brand">NEXORA <span>Distribution</span></div>
        <nav className="nav">
          <button className="active">لوحة التحكم</button>
          <button>المبيعات</button>
          <button>المشتريات</button>
          <button>المخزون</button>
          <button>العملاء</button>
          <button>الموردين</button>
          <button>التوزيع والرحلات</button>
          <button>التقارير</button>
        </nav>
        <button onClick={logout} style={{marginTop:30,border:0,background:"transparent",color:"#94a3b8"}}><LogOut size={16}/> خروج</button>
      </aside>

      <main className="main">
        <div className="topbar">
          <div>
            <h1 className="title">لوحة التحكم</h1>
            <div className="subtitle">Warehouse → Vehicle → Route → Customer → Sale → Settlement</div>
          </div>
          <div className="badge green">النظام متصل</div>
        </div>

        {error && <div className="card error">{error}</div>}

        <section className="grid">
          {[
            ["مبيعات اليوم", data?.sales ?? 0, "EGP"],
            ["مشتريات اليوم", data?.purchases ?? 0, "EGP"],
            ["تحصيلات اليوم", data?.collections ?? 0, "EGP"],
            ["مصروفات اليوم", data?.expenses ?? 0, "EGP"],
            ["مستحقات العملاء", data?.receivables ?? 0, "EGP"],
            ["مستحقات الموردين", data?.payables ?? 0, "EGP"],
            ["المخزون", "—", "Live"],
            ["الرحلات", "—", "Today"],
          ].map(([label,value,unit]) => (
            <div className="card" key={String(label)}>
              <div className="metric-label">{label}</div>
              <div className="metric-value">{typeof value === "number" ? value.toLocaleString("ar-EG") : value}</div>
              <div className="metric-label">{unit}</div>
            </div>
          ))}
        </section>

        <section className="section">
          <h2>Quick Actions</h2>
          <div className="quick">
            {quick.map(({label,href,Icon}) => (
              <button key={label} onClick={() => window.location.href=href}>
                <Icon size={20} />
                <strong style={{display:"block",marginTop:8}}>{label}</strong>
                <span className="metric-label">أقل عدد ممكن من الخطوات</span>
              </button>
            ))}
          </div>
        </section>

        <section className="section">
          <h2>حالة التشغيل</h2>
          <div className="table-wrap">
            <table>
              <thead><tr><th>المجال</th><th>الحالة</th><th>ملاحظة</th></tr></thead>
              <tbody>
                <tr><td>المخزون</td><td><span className="badge green">Ready</span></td><td>Ledger-based</td></tr>
                <tr><td>المبيعات</td><td><span className="badge green">Ready</span></td><td>Atomic posting</td></tr>
                <tr><td>المشتريات</td><td><span className="badge green">Ready</span></td><td>Stock receipt + AP</td></tr>
                <tr><td>Offline</td><td><span className="badge amber">Building</span></td><td>Dexie queue</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </main>

      <nav className="mobile-nav">
        <button><LayoutDashboard size={17}/><br/>الرئيسية</button>
        <button><Search size={17}/><br/>بحث</button>
        <button><Package size={17}/><br/>مخزون</button>
        <button><Users size={17}/><br/>عملاء</button>
      </nav>
    </div>
  );
}