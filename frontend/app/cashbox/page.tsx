"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";
import { Banknote, RefreshCw, Wallet, PlusCircle, Search } from "lucide-react";
import { api } from "@/lib/api";
import { getStableIdempotencyKey, clearStableIdempotencyKey } from "@/lib/idempotency";

type Account = { id:string; code:string; name:string; type:string; currency:string; balance:number; opening_balance:number; movement_balance:number };
type Vehicle = { id:string; name:string; code:string; plate_number?:string|null };
type Trip = { id:string; trip_number:string; vehicle_name:string; status:string };
type Employee = {id:string;name:string;phone?:string|null};
type Custody = {user_id:string;name:string;total_issued:number;total_returned:number;outstanding:number};
type Movement = { id:string; source_id:string; document_number:string; date:string; type:string; direction:"inbound"|"outbound"|"transfer"; amount:number; currency:string; party_name?:string|null; beneficiary_user_id?:string|null; category:string; account_id?:string|null; account_name?:string|null; destination_account_id?:string|null; destination_account_name?:string|null; trip_id?:string|null; trip_number?:string|null; vehicle_name?:string|null; reference?:string|null; notes?:string|null };
type Payload = { period:{date_from:string;date_to:string}; currency:string; summary:Record<string,number|string>; accounts:Account[]; general_cash_balance:number; movements:Movement[]; custody:Custody[]; notes:Record<string,boolean> };
type TxType = "opening_balance"|"other_income"|"owner_withdrawal"|"salary_payment"|"other_expense"|"account_transfer"|"custody_issue"|"custody_return";

const typeLabels:Record<TxType,string> = {
  opening_balance:"رصيد افتتاحي",
  other_income:"إيراد آخر",
  owner_withdrawal:"مسحوبات صاحب الشركة",
  salary_payment:"صرف راتب / أجر",
  other_expense:"مصروف نقدي آخر",
  account_transfer:"تحويل بين حسابات",
  custody_issue:"تسليم عهدة لموظف",
  custody_return:"استرداد عهدة من موظف",
};

function money(value:unknown,currency="EGP"){return `${Number(value||0).toLocaleString("ar-EG",{minimumFractionDigits:2,maximumFractionDigits:2})} ${currency}`}
function today(){return new Date(Date.now()-new Date().getTimezoneOffset()*60000).toISOString().slice(0,10)}

export default function CashboxPage(){
  const [report,setReport]=useState<Payload|null>(null);
  const [accounts,setAccounts]=useState<Account[]>([]);
  const [vehicles,setVehicles]=useState<Vehicle[]>([]);
  const [trips,setTrips]=useState<Trip[]>([]);
  const [employees,setEmployees]=useState<Employee[]>([]);
  const [custodyFilter,setCustodyFilter]=useState("");
  const [beneficiaryUserId,setBeneficiaryUserId]=useState("");
  const [from,setFrom]=useState(()=>`${today().slice(0,7)}-01`);
  const [to,setTo]=useState(today);
  const [accountFilter,setAccountFilter]=useState("");
  const [directionFilter,setDirectionFilter]=useState("");
  const [type,setType]=useState<TxType>("owner_withdrawal");
  const [amount,setAmount]=useState("");
  const [accountId,setAccountId]=useState("");
  const [destinationId,setDestinationId]=useState("");
  const [generalCash,setGeneralCash]=useState(true);
  const [date,setDate]=useState(today);
  const [category,setCategory]=useState("مسحوبات شريك");
  const [beneficiaryType,setBeneficiaryType]=useState<"owner"|"driver"|"employee">("owner");
  const [beneficiaryName,setBeneficiaryName]=useState("");
  const [vehicleId,setVehicleId]=useState("");
  const [tripId,setTripId]=useState("");
  const [reference,setReference]=useState("");
  const [notes,setNotes]=useState("");
  const [loading,setLoading]=useState(true);
  const [saving,setSaving]=useState(false);
  const [error,setError]=useState("");
  const [message,setMessage]=useState("");
  const [query,setQuery]=useState("");

  const load=async()=>{
    setLoading(true);setError("");
    try{
      const params=new URLSearchParams({date_from:from,date_to:to,limit:"500"});
      if(accountFilter)params.set("financial_account_id",accountFilter);
      if(directionFilter)params.set("direction",directionFilter);
      const [summary,vehicleRows,tripRows,employeeRows]=await Promise.all([
        api<Payload>(`/cashbox?${params.toString()}`),
        api<Vehicle[]>("/vehicles").catch(()=>[] as Vehicle[]),api<Trip[]>("/trips").catch(()=>[] as Trip[]),api<Employee[]>("/cashbox/beneficiaries").catch(()=>[] as Employee[]),
      ]);
      setReport(summary);setAccounts(summary.accounts);setVehicles(vehicleRows);setTrips(tripRows);setEmployees(employeeRows);
      setAccountId(current=>current||summary.accounts[0]?.id||"");
      setDestinationId(current=>current||summary.accounts[1]?.id||summary.accounts[0]?.id||"");
      setVehicleId(current=>current||vehicleRows[0]?.id||"");
      setError("");
    }catch(e){setError((e as Error).message||"تعذر تحميل دفتر الخزنة.")}
    finally{setLoading(false)}
  };
  useEffect(()=>{void load()},[]);

  const visible=useMemo(()=> (report?.movements||[]).filter(row=>{
    if(custodyFilter && row.beneficiary_user_id!==custodyFilter)return false;
    const value=`${row.document_number} ${row.category} ${row.party_name||""} ${row.account_name||""} ${row.trip_number||""} ${row.reference||""}`.toLocaleLowerCase();
    return !query.trim()||value.includes(query.trim().toLocaleLowerCase());
  }),[report,query,custodyFilter]);

  useEffect(()=>{
    const labels:Record<TxType,string>={opening_balance:"رصيد افتتاحي",other_income:"إيراد آخر",owner_withdrawal:"مسحوبات شريك",salary_payment:"راتب / أجر",other_expense:"مصروف نقدي",account_transfer:"تحويل داخلي",custody_issue:"تسليم عهدة لموظف",custody_return:"استرداد عهدة من موظف"};
    setCategory(labels[type]);
    if(type==="owner_withdrawal")setBeneficiaryType("owner");
    if(type==="salary_payment")setBeneficiaryType("driver");
    if(type==="other_income")setGeneralCash(false);
    if(type==="custody_issue")setCategory("تسليم عهدة موظف");
    if(type==="custody_return")setCategory("استرداد عهدة موظف");
  },[type]);

  const usesGeneralCash=["opening_balance","owner_withdrawal","salary_payment","other_expense","account_transfer","custody_issue","custody_return"].includes(type);
  const transfer=type==="account_transfer";
  const needsBeneficiary=type==="owner_withdrawal"||type==="salary_payment"||type==="custody_issue"||type==="custody_return";

  async function submit(event:FormEvent){
    event.preventDefault();setSaving(true);setError("");setMessage("");
    const value=Number(amount);
    if(!Number.isFinite(value)||value<=0){setError("اكتب مبلغًا أكبر من صفر.");setSaving(false);return}
    if(!generalCash&&!accountId){setError("اختر حساب الخزنة أو البنك.");setSaving(false);return}
    if(type==="account_transfer"&&(!destinationId||(!generalCash&&destinationId===accountId))){setError("اختار حسابًا مستلمًا مختلفًا عن حساب المصدر.");setSaving(false);return}
    if(needsBeneficiary&&!beneficiaryName.trim()&&!beneficiaryUserId){setError("اكتب اسم الشخص الذي استلم المبلغ.");setSaving(false);return}
    if((type==="custody_issue"||type==="custody_return")&&!beneficiaryUserId){setError("اختار موظفًا مسجلًا لربط العهدة بتقاريره.");setSaving(false);return}
    if(type==="other_income"&&!accountId){setError("حدد الحساب الذي سيستقبل الإيراد.");setSaving(false);return}
    const payload={transaction_type:type,amount:value,financial_account_id:accountId||undefined,source_is_general_cash:generalCash,
      destination_financial_account_id:transfer?destinationId||undefined:undefined,transaction_date:date,
      beneficiary_type:needsBeneficiary?(type.startsWith("custody_")?"employee":beneficiaryType):undefined,beneficiary_user_id:type.startsWith("custody_")?beneficiaryUserId||undefined:undefined,beneficiary_name:needsBeneficiary?beneficiaryName.trim()||undefined:undefined,
      vehicle_id:vehicleId||undefined,trip_id:tripId||undefined,category:category.trim(),reference:reference.trim()||undefined,notes:notes.trim()||undefined};
    try{
      const key=await getStableIdempotencyKey("cashbox",payload);
      const result=await api<{id:string;document_number:string;amount:string|number}>("/cashbox",{method:"POST",body:JSON.stringify({...payload,idempotency_key:key})});
      clearStableIdempotencyKey("cashbox",key);
      setMessage(`اتسجلت الحركة بنجاح — رقم المستند ${result.document_number} — ${money(result.amount,report?.currency)}`);
      setAmount("");setReference("");setNotes("");setBeneficiaryName("");setBeneficiaryUserId("");setTripId("");
      await load();
    }catch(e){setError((e as Error).message||"تعذر تسجيل الحركة.")}
    finally{setSaving(false)}
  }

  const summary=report?.summary||{};
  const tiles:[string,string,string][]=[
    ["إجمالي الداخل","total_inbound","positive"],["إجمالي الخارج","total_outbound","negative"],
    ["صافي حركة النقدية","net_cash_flow","neutral"],["الرصيد في الخزنة العامة","general_cash_balance","neutral"],
  ];

  return <main className="main cashbox-page" dir="rtl">
    <div className="topbar"><div><h1 className="title">الخزنة والحركة المالية</h1><div className="subtitle">كل جنيه داخل أو خارج مرتبط بمصدر ومستند وحساب، مع فصل التحويلات الداخلية عن التدفق النقدي.</div></div><button type="button" className="secondary" onClick={()=>void load()} disabled={loading}><RefreshCw size={14}/> تحديث</button></div>
    {error&&<div role="alert" className="card" style={{borderColor:"#fecaca",color:"#991b1b",marginBottom:12}}>{error}</div>}
    {message&&<div role="status" className="card" style={{borderColor:"#bbf7d0",marginBottom:12}}>{message}</div>}

    <section className="card" style={{marginBottom:14}}>
      <div className="panel-title"><span><Search size={15}/> فترة التقرير</span><small>الافتراضي من بداية الشهر لليوم</small></div>
      <div className="form-grid">
        <label>من تاريخ<input type="date" value={from} max={to} onChange={e=>setFrom(e.target.value)}/></label>
        <label>إلى تاريخ<input type="date" value={to} min={from} onChange={e=>setTo(e.target.value)}/></label>
        <label>الحساب المالي<select value={accountFilter} onChange={e=>setAccountFilter(e.target.value)}><option value="">كل الحسابات</option>{accounts.map(a=><option key={a.id} value={a.id}>{a.name} — {a.code}</option>)}</select></label>
        <label>نوع الحركة<select value={directionFilter} onChange={e=>setDirectionFilter(e.target.value)}><option value="">كل الحركات</option><option value="inbound">داخل</option><option value="outbound">خارج</option><option value="transfer">تحويل داخلي</option></select></label>
      </div>
      <button className="primary" type="button" style={{marginTop:10}} onClick={()=>void load()} disabled={loading}>عرض دفتر الخزنة</button>
    </section>

    <section className="grid cashbox-metrics" style={{marginBottom:16}}>
      {tiles.map(([label,key,kind])=><article className="card metric" key={key}><span className="metric-label">{label}</span><div className={`metric-value ${kind}`}>{money(key==="general_cash_balance"?report?.general_cash_balance:summary[key],report?.currency)}</div><small className="metric-note">{key==="general_cash_balance"?"حركة دفتر الأستاذ العام غير المخصصة لحساب محدد":"للفترة المختارة، دون تكرار التحويلات الداخلية"}</small></article>)}
    </section>

    <section className="card" style={{marginBottom:16}}>
      <div className="panel-title"><span><Wallet size={16}/> أرصدة الحسابات</span><span className="badge blue">{accounts.length} حساب</span></div>
      <div className="table-wrap"><table><thead><tr><th>الحساب</th><th>الرصيد الافتتاحي</th><th>صافي الحركة</th><th>الرصيد الحالي المحسوب</th></tr></thead><tbody>
        {(report?.accounts||[]).map(a=><tr key={a.id}><td><strong>{a.name}</strong><small style={{display:"block",color:"#64748b"}}>{a.code} · {a.type}</small></td><td>{money(a.opening_balance,a.currency)}</td><td>{money(a.movement_balance,a.currency)}</td><td><strong>{money(a.balance,a.currency)}</strong></td></tr>)}
        {!report?.accounts.length&&<tr><td colSpan={4}>لا توجد حسابات مالية مفعلة.</td></tr>}
      </tbody></table></div>
    </section>

    <section className="card" style={{marginBottom:16}}>
      <div className="panel-title"><span><PlusCircle size={16}/> تسجيل حركة خزنة جديدة</span><small>كل تسجيل ينشئ مستندًا وقيدًا محاسبيًا غير مكرر</small></div>
      <form onSubmit={submit}>
        <div className="form-grid">
          <label>نوع الحركة<select value={type} onChange={e=>setType(e.target.value as TxType)}>{Object.entries(typeLabels).map(([value,label])=><option key={value} value={value}>{label}</option>)}</select></label>
          <label>المبلغ (ج.م)<input aria-label="المبلغ" type="number" required min="0.01" step="0.01" value={amount} onChange={e=>setAmount(e.target.value)} placeholder="0.00"/></label>
          <label>التاريخ<input type="date" required value={date} onChange={e=>setDate(e.target.value)}/></label>
          <label>الحساب المالي المصدر / المستلم<select value={accountId} onChange={e=>setAccountId(e.target.value)} disabled={generalCash&&type!=="other_income"}><option value="">اختار حسابًا...</option>{accounts.map(a=><option key={a.id} value={a.id}>{a.name} — {a.code}</option>)}</select></label>
          {usesGeneralCash&&<label style={{display:"flex",alignItems:"center",gap:8,flexDirection:"row",gridColumn:"1 / -1"}}><input type="checkbox" checked={generalCash} onChange={e=>setGeneralCash(e.target.checked)} style={{width:18,height:18}}/> الحركة من الخزنة العامة غير المخصصة (نقدية المندوب أو النقدي غير المودع بحساب)</label>}
          {transfer&&<label>الحساب المستلم للتحويل<select value={destinationId} onChange={e=>setDestinationId(e.target.value)}><option value="">اختار حسابًا...</option>{accounts.filter(a=>generalCash||a.id!==accountId).map(a=><option key={a.id} value={a.id}>{a.name} — {a.code}</option>)}</select></label>}
          {needsBeneficiary&&<>
            <label>نوع المستلم<select value={beneficiaryType} onChange={e=>setBeneficiaryType(e.target.value as "owner"|"driver"|"employee")} disabled={type==="owner_withdrawal"||type.startsWith("custody_")}><option value="owner">صاحب الشركة / شريك</option><option value="driver">سائق</option><option value="employee">موظف</option></select></label>
            {type.startsWith("custody_")?<label>الموظف المسؤول عن العهدة<select required value={beneficiaryUserId} onChange={e=>{setBeneficiaryUserId(e.target.value);setBeneficiaryName(employees.find(u=>u.id===e.target.value)?.name||"")}}><option value="">اختار الموظف</option>{employees.map(u=><option key={u.id} value={u.id}>{u.name}{u.phone?` — ${u.phone}`:""}</option>)}</select></label>:<label>اسم المستلم<input required value={beneficiaryName} onChange={e=>setBeneficiaryName(e.target.value)} placeholder="اسم الشخص الذي استلم المبلغ"/></label>}
          </>}
          <label>السيارة (اختياري)<select value={vehicleId} onChange={e=>setVehicleId(e.target.value)}><option value="">بدون سيارة</option>{vehicles.map(v=><option key={v.id} value={v.id}>{v.name}{v.plate_number?` — ${v.plate_number}`:""}</option>)}</select></label>
          <label>الرحلة المرتبطة (اختياري)<select value={tripId} onChange={e=>setTripId(e.target.value)}><option value="">بدون رحلة</option>{trips.filter(t=>!['completed','cancelled'].includes(t.status)).map(t=><option key={t.id} value={t.id}>{t.trip_number} — {t.vehicle_name}</option>)}</select></label>
          <label>التصنيف / سبب الحركة<input required maxLength={128} value={category} onChange={e=>setCategory(e.target.value)} placeholder="مثال: راتب شهر أكتوبر"/></label>
          <label>رقم مرجع / إيصال (اختياري)<input maxLength={255} value={reference} onChange={e=>setReference(e.target.value)} placeholder="رقم إيصال أو تحويل بنكي"/></label>
          <label style={{gridColumn:"1 / -1"}}>ملاحظات (اختياري)<input maxLength={2000} value={notes} onChange={e=>setNotes(e.target.value)} placeholder="تفاصيل إضافية عن مصدر أو سبب الصرف"/></label>
        </div>
        {type==="opening_balance"&&<div className="trip-context" style={{marginTop:10}}>الرصيد الافتتاحي يتسجل مرة واحدة فقط لكل حساب. لو الحساب فيه رصيد افتتاحي سابق، سجّل تسوية موثقة بدل تكراره.</div>}
        <button className="primary" type="submit" disabled={saving||loading} style={{marginTop:12}}>{saving?"جاري تسجيل المستند والقيد...":"تسجيل الحركة وإصدار رقم مستند"}</button>
      </form>
    </section>

    <section className="card" style={{marginBottom:16}}>
      <div className="panel-title"><span><Wallet size={16}/> عهد الموظفين</span><span className="badge blue">{report?.custody?.length||0} موظف</span></div>
      <div className="grid cashbox-metrics">
        {(report?.custody||[]).map(c=><article className="card metric" key={c.user_id}><span className="metric-label">{c.name}</span><div className="metric-value">{money(c.outstanding,report?.currency)}</div><small className="metric-note">مسلّم: {money(c.total_issued,report?.currency)} · مسترد: {money(c.total_returned,report?.currency)}</small><button className="secondary" type="button" onClick={()=>{setCustodyFilter(c.user_id);setQuery("")}}>عرض حركات العهدة</button></article>)}
        {!report?.custody?.length&&<div className="trip-context">لا توجد عهد مسجلة بعد. استخدم «تسليم عهدة لموظف» لتسجيل مبلغ بعهدته.</div>}
      </div>
    </section>

    <section className="card">
      <div className="panel-title"><span><Banknote size={16}/> دفتر حركة النقدية</span><span className="badge blue">{visible.length} حركة</span></div>
      <label>تصفية حسب الموظف / العهدة<select value={custodyFilter} onChange={e=>setCustodyFilter(e.target.value)}><option value="">كل الحركات</option>{employees.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select></label>
      {custodyFilter&&<button type="button" className="secondary" onClick={()=>setCustodyFilter("")}>إلغاء فلتر العهدة</button>}
      <label className="trip-list-search"><Search size={15}/><input value={query} onChange={e=>setQuery(e.target.value)} placeholder="ابحث بالمستند أو السبب أو الشخص أو الرحلة..." aria-label="بحث حركة الخزنة"/></label>
      {loading&&<div role="status" style={{padding:12,color:"#64748b"}}>جاري تحميل دفتر الخزنة...</div>}
      <div className="table-wrap"><table><thead><tr><th>التاريخ / المستند</th><th>الحركة / المصدر</th><th>الحساب</th><th>الشخص / الرحلة / السيارة</th><th>الداخل</th><th>الخارج / المحول</th><th>المرجع</th></tr></thead><tbody>
        {visible.map(row=><tr key={row.id}><td><strong>{row.document_number}</strong><small style={{display:"block",color:"#64748b"}}>{row.date}</small></td><td>{row.category}<small style={{display:"block",color:"#64748b"}}>{row.type==="sale_cash"?"نقدي وقت البيع":row.type==="customer_collection"?"تحصيل لاحق":row.type==="supplier_payment"?"سداد مورد":row.type==="owner_withdrawal"?"مسحوبات مالك":row.type==="salary_payment"?"رواتب / أجور":row.type==="account_transfer"?`تحويل داخلي${row.destination_account_name?` إلى ${row.destination_account_name}`:""}`:row.type==="opening_balance"?"رصيد افتتاحي":row.type}</small></td><td>{row.account_name||"الخزنة العامة / غير موزع"}</td><td>{row.party_name||"—"}<small style={{display:"block",color:"#64748b"}}>{row.trip_number||""}{row.vehicle_name?` · ${row.vehicle_name}`:""}</small></td><td>{row.direction==="inbound"?money(row.amount,row.currency):"—"}</td><td>{row.direction==="outbound"||row.direction==="transfer"?money(row.amount,row.currency):"—"}</td><td>{row.reference||"—"}</td></tr>)}
        {!loading&&!visible.length&&<tr><td colSpan={7} style={{textAlign:"center",padding:24,color:"#64748b"}}>لا توجد حركات مطابقة في الفترة المختارة.</td></tr>}
      </tbody></table></div>
      <p className="trip-operation-intro" style={{marginTop:12}}>التحويل الداخلي يظهر كحركة تحويل ولا يُحسب دخلًا أو مصروفًا جديدًا على مستوى المؤسسة. مبيعات الآجل تُعرض كذمم على العملاء وليست نقدية. النقدي عند البيع يُقرأ من القيد الأصلي، لذلك لا يتكرر مع التحصيل اللاحق.</p>
    </section>
  </main>;
}
