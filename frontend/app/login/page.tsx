"use client";

import { FormEvent, useState } from "react";
import { api } from "@/lib/api";

export default function LoginPage() {
  const [organizationId,setOrganizationId]=useState("");
  const [login,setLogin]=useState("");
  const [password,setPassword]=useState("");
  const [deviceName,setDeviceName]=useState("NEXORA Web");
  const [error,setError]=useState("");
  const [loading,setLoading]=useState(false);

  async function submit(e:FormEvent){
    e.preventDefault(); setLoading(true); setError("");
    try{
      const result=await api<{token:string}>("/auth/login",{method:"POST",body:JSON.stringify({
        organization_id:organizationId,login,password,device_name:deviceName
      })});
      localStorage.setItem("nexora_token",result.token);
      window.location.href="/";
    }catch(err){setError(err instanceof Error?err.message:"تعذر تسجيل الدخول.");}
    finally{setLoading(false);}
  }

  return <main className="login">
    <form className="login-card" onSubmit={submit}>
      <div className="brand" style={{color:"var(--navy)"}}>NEXORA <span>Distribution</span></div>
      <h1 style={{marginBottom:6}}>تسجيل الدخول</h1>
      <p className="subtitle">إدارة المخزن، العربيات، المبيعات والرحلات من مكان واحد.</p>
      <div className="field"><label>Organization ID</label><input value={organizationId} onChange={e=>setOrganizationId(e.target.value)} required placeholder="ULID" /></div>
      <div className="field"><label>الإيميل أو الهاتف</label><input value={login} onChange={e=>setLogin(e.target.value)} required /></div>
      <div className="field"><label>كلمة المرور</label><input type="password" value={password} onChange={e=>setPassword(e.target.value)} required /></div>
      <div className="field"><label>اسم الجهاز</label><input value={deviceName} onChange={e=>setDeviceName(e.target.value)} required /></div>
      {error && <div className="error">{error}</div>}
      <button className="primary" disabled={loading}>{loading?"جارٍ الدخول...":"دخول"}</button>
    </form>
  </main>;
}