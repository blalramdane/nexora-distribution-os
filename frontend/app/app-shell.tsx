"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  BarChart3, Box, CircleDollarSign, ClipboardList, FileText, LayoutDashboard,
  LogOut, Package, Settings, Truck, Users, WalletCards, Warehouse
} from "lucide-react";
import { api } from "@/lib/api";

const nav = [
  ["الرئيسية","/",LayoutDashboard],
  ["المبيعات","/sales",CircleDollarSign],
  ["العملاء","/customers",Users],
  ["المشتريات","/purchases",ClipboardList],
  ["المنتجات","/products",Package],
  ["المخزون","/inventory",Box],
  ["المستودعات","/locations",Warehouse],
  ["السيارات والتوزيع","/trips",Truck],
  ["الموردين","/suppliers",Users],
  ["التحصيلات","/payments",WalletCards],
  ["المرتجعات","/returns",FileText],
  ["التقارير","/reports",BarChart3],
] as const;

export default function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const router = useRouter();

  if (pathname === "/login" || pathname.startsWith("/field")) return <>{children}</>;

  const logout = async () => {
    try { await api("/auth/logout", { method: "POST" }); }
    catch {}
    localStorage.removeItem("nexora_token");
    router.replace("/login");
  };

  return (
    <div className="app">
      <aside className="sidebar">
        <Link href="/" className="brand">
          <div className="brand-mark">N</div>
          <div className="brand-name">NEXORA<small>Distribution OS</small></div>
        </Link>

        <nav className="nav">
          <div className="nav-section">إدارة التشغيل</div>
          {nav.map(([label, href, Icon]) => {
            const active = href === "/" ? pathname === "/" : pathname.startsWith(href);
            return (
              <Link key={href} href={href} className={"nav-link " + (active ? "active" : "")}>
                <Icon size={16}/><span>{label}</span>
              </Link>
            );
          })}
          <div className="nav-section">النظام</div>
          <button type="button"><Settings size={16}/><span>الإعدادات</span></button>
        </nav>

        <div className="sidebar-bottom">
          <div className="user-mini">
            <div className="avatar">AD</div>
            <div>Admin<small>مدير النظام</small></div>
          </div>
          <button className="logout" onClick={logout}><LogOut size={14}/> خروج</button>
        </div>
      </aside>

      <div className="shell-content">{children}</div>

      <nav className="mobile-nav">
        {[
          ["الرئيسية","/",LayoutDashboard],
          ["بيع","/sales",CircleDollarSign],
          ["مخزون","/inventory",Package],
          ["عملاء","/customers",Users],
        ].map(([label, href, Icon]) => {
          const active = href === "/" ? pathname === "/" : pathname.startsWith(href as string);
          return (
            <Link key={href as string} href={href as string} className={active ? "active" : ""}>
              <Icon size={17}/><span>{label}</span>
            </Link>
          );
        })}
      </nav>
    </div>
  );
}
