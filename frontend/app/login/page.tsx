"use client";

import { FormEvent, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

export default function LoginPage() {
  const [organizationId,setOrganizationId]=useState("");
  const [login,setLogin]=useState("");
  const [password,setPassword]=useState("");
  const [deviceName,setDeviceName]=useState("NEXORA Web");
  const [error,setError]=useState("");
  const [loading,setLoading]=useState(false);
  const [retryAt,setRetryAt]=useState(0);

  useEffect(()=>{
    const organizationIdFromLink=new URLSearchParams(window.location.search).get("organization_id");
    if(organizationIdFromLink)setOrganizationId(organizationIdFromLink);
    const savedOrganizationId=localStorage.getItem("nexora_organization_id");
    if(!organizationIdFromLink&&savedOrganizationId)setOrganizationId(savedOrganizationId);

    const savedRetryAt=Number(localStorage.getItem("nexora_login_retry_at")||0);
    if(savedRetryAt>Date.now())setRetryAt(savedRetryAt);
    else localStorage.removeItem("nexora_login_retry_at");

    // A saved password is not a session. Reuse a token only if the API validates it.
    if(localStorage.getItem("nexora_token")){
      void api<{roles?:{key:string}[];organization_id?:string}>("/auth/me").then(user=>{
        const requestedOrganization=organizationIdFromLink||savedOrganizationId;
        if(requestedOrganization&&user.organization_id&&requestedOrganization!==user.organization_id)return;
        if(user.organization_id)localStorage.setItem("nexora_organization_id",user.organization_id);
        window.location.replace(user.roles?.some(role=>role.key==="field_rep")?"/field":"/");
      }).catch(()=>{
        // The shared API helper clears an expired token but keeps the saved organization.
      });
    }
  },[]);

  useEffect(()=>{
    if(!retryAt)return;
    const timer=window.setInterval(()=>{
      if(Date.now()>=retryAt){
        setRetryAt(0);
        localStorage.removeItem("nexora_login_retry_at");
      }
    },500);
    return()=>window.clearInterval(timer);
  },[retryAt]);

  async function submit(e:FormEvent<HTMLFormElement>){
    e.preventDefault();
    if(retryAt>Date.now()){
      setError(`استنى ${Math.ceil((retryAt-Date.now())/1000)} ثانية قبل المحاولة التالية.`);
      return;
    }
    setLoading(true); setError("");
    // Browser password managers can autofill inputs without updating React state.
    const formData=new FormData(e.currentTarget);
    const submittedOrganizationId=String(formData.get("organization_id")||organizationId).trim();
    const submittedLogin=String(formData.get("login")||login).trim();
    const submittedPassword=String(formData.get("password")||password);
    const submittedDeviceName=String(formData.get("device_name")||deviceName).trim();
    try{
      const result=await api<{token:string;user:{roles:{key:string}[]}}>("/auth/login",{method:"POST",body:JSON.stringify({
        organization_id:submittedOrganizationId,login:submittedLogin,password:submittedPassword,device_name:submittedDeviceName
      })});
      localStorage.setItem("nexora_token",result.token);
      localStorage.setItem("nexora_organization_id",submittedOrganizationId);
      localStorage.removeItem("nexora_login_retry_at");
      window.location.href=result.user?.roles?.some(role=>role.key==="field_rep")?"/field":"/";
    }catch(err){
      if(err instanceof ApiError&&err.status===429){
        const seconds=Math.max(1,err.retryAfterSeconds??60);
        const nextTry=Date.now()+seconds*1000;
        localStorage.setItem("nexora_login_retry_at",String(nextTry));
        setRetryAt(nextTry);
        setError(`وصلنا للحد المسموح من المحاولات. استنى ${seconds} ثانية قبل المحاولة التالية.`);
      }else setError(err instanceof Error?err.message:"تعذر تسجيل الدخول.");
    }
    finally{setLoading(false);}
  }

  return <main className="login">
    <form className="login-card" onSubmit={submit}>
      <div className="brand" style={{color:"var(--navy)"}}>NEXORA <span>Distribution</span></div>
      <h1 style={{marginBottom:6}}>تسجيل الدخول</h1>
      <p className="subtitle">إدارة المخزن، العربيات، المبيعات والرحلات من مكان واحد.</p>
      <div className="field"><label htmlFor="organization-id">Organization ID</label><input id="organization-id" name="organization_id" autoComplete="off" value={organizationId} onChange={e=>setOrganizationId(e.target.value)} required placeholder="ULID" /></div>
      <div className="field"><label htmlFor="login">الإيميل أو الهاتف</label><input id="login" name="login" autoComplete="username" value={login} onChange={e=>setLogin(e.target.value)} required /></div>
      <div className="field"><label htmlFor="password">كلمة المرور</label><input id="password" name="password" type="password" autoComplete="current-password" value={password} onChange={e=>setPassword(e.target.value)} required /></div>
      <div className="field"><label htmlFor="device-name">اسم الجهاز</label><input id="device-name" name="device_name" autoComplete="off" value={deviceName} onChange={e=>setDeviceName(e.target.value)} required /></div>
      {error && <div className="error">{error}</div>}
      <button className="primary" disabled={loading||retryAt>Date.now()}>{loading?"جارٍ الدخول...":retryAt>Date.now()?`انتظر ${Math.ceil((retryAt-Date.now())/1000)} ثانية`:"دخول"}</button>
    </form>
  </main>;
}
