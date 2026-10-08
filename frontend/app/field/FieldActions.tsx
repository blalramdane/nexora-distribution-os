"use client";
import { useEffect,useState } from "react";
import { ArrowRight, CircleDollarSign, PackageCheck, RotateCcw, Search, ShoppingCart } from "lucide-react";
import { api, apiWithOfflineQueue } from "@/lib/api";
type Product={id:string;sku:string;name_ar:string;default_piece_price:number;quantity_base:number};
type Customer={id:string;name:string;code:string};
type Trip={id:string;trip_number:string;vehicle_name:string;vehicle_location_id:string};
type Mode="sale"|"collection"|"return";
export default function FieldActions({trip,customer,stock,onDone}:{trip:Trip;customer:Customer;stock:Product[];onDone:()=>void}){
 const [mode,setMode]=useState<Mode>("sale"),[q,setQ]=useState(""),[product,setProduct]=useState<Product|null>(null),[qty,setQty]=useState("1"),[price,setPrice]=useState(""),[amount,setAmount]=useState(""),[account,setAccount]=useState(""),[method,setMethod]=useState(""),[refs,setRefs]=useState<{accounts:{id:string;name:string}[];payment_methods:{id:string;name_ar:string}[]}>({accounts:[],payment_methods:[]}),[msg,setMsg]=useState("");
 const matches=stock.filter(p=>(p.name_ar+" "+p.sku).includes(q)).slice(0,6);
 useEffect(()=>{api<{accounts:{id:string;name:string}[];payment_methods:{id:string;name_ar:string}[]}>("/finance/references").then(x=>{setRefs(x);setAccount(x.accounts[0]?.id||"");setMethod(x.payment_methods[0]?.id||"")}).catch(()=>{});if(product)setPrice(String(product.default_piece_price||0))},[product]);
 const submit=async()=>{
  try{
   if(mode==="collection"){if(Number(amount)<=0||!account||!method)return setMsg("اختار حساب وطريقة الدفع وأدخل مبلغ التحصيل.");await apiWithOfflineQueue("/payments",{method:"POST",body:JSON.stringify({party_type:"customer",party_id:customer.id,financial_account_id:account,payment_method_id:method,direction:"inbound",amount:Number(amount),trip_id:trip.id,idempotency_key:crypto.randomUUID()})});setMsg("تم حفظ التحصيل.");onDone();return}
   if(!product||Number(qty)<=0)return setMsg("اختار الصنف والكمية.");
   const payload={customer_id:customer.id,location_id:"",trip_id:trip.id,idempotency_key:crypto.randomUUID(),items:[{product_id:product.id,quantity:Number(qty),unit_price:Number(price||0)}]};
   if(mode==="return"){await apiWithOfflineQueue("/returns/sales",{method:"POST",body:JSON.stringify(payload)});setMsg("تم حفظ المرتجع.");}
   else {await apiWithOfflineQueue("/sales",{method:"POST",body:JSON.stringify(payload)});setMsg("تم حفظ البيع.");}
   onDone();
  }catch(e){setMsg((e as Error).message)}
 };
 return <section className="field-card"><div className="field-card-title"><span>عملية العميل</span><span className="badge blue">{customer.name}</span></div>
 <div className="field-action-tabs"><button className={mode==="sale"?"active":""} onClick={()=>setMode("sale")}><ShoppingCart size={14}/> بيع</button><button className={mode==="collection"?"active":""} onClick={()=>setMode("collection")}><CircleDollarSign size={14}/> تحصيل</button><button className={mode==="return"?"active":""} onClick={()=>setMode("return")}><RotateCcw size={14}/> مرتجع</button></div>
 {mode==="collection"?<><label className="action-field">الحساب<select value={account} onChange={e=>setAccount(e.target.value)}>{refs.accounts.map(a=><option key={a.id} value={a.id}>{a.name}</option>)}</select></label><label className="action-field">طريقة الدفع<select value={method} onChange={e=>setMethod(e.target.value)}>{refs.payment_methods.map(m=><option key={m.id} value={m.id}>{m.name_ar}</option>)}</select></label><label className="action-field">المبلغ<input type="number" min="0" value={amount} onChange={e=>setAmount(e.target.value)} placeholder="0.00"/></label></>:<><div className="action-search"><Search size={14}/><input value={q} onChange={e=>setQ(e.target.value)} placeholder="الصنف من مخزون العربية..."/></div>{q&&matches.map(p=><button className="product-result" key={p.id} onClick={()=>{setProduct(p);setQ("")}}><span>{p.name_ar}<small>{p.sku} · متاح {p.quantity_base}</small></span><strong>{Number(p.default_piece_price||0).toLocaleString("ar-EG")} EGP</strong></button>)}{product&&<div className="action-row"><strong>{product.name_ar}</strong><input type="number" min="1" max={product.quantity_base} value={qty} onChange={e=>setQty(e.target.value)}/><input type="number" min="0" value={price} onChange={e=>setPrice(e.target.value)}/></div>}</>}
 {msg&&<div className="field-toast">{msg}</div>}<button className="primary" onClick={submit} style={{marginTop:10,width:"100%"}}>{mode==="sale"?"تسجيل البيع":mode==="collection"?"تسجيل التحصيل":"تسجيل المرتجع"} <ArrowRight size={14}/></button></section>
}