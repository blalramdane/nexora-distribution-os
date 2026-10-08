"use client";
import { useEffect,useState } from "react";
import { Warehouse } from "lucide-react";
import { api } from "@/lib/api";
type Location={id:string;code:string;name:string;type:string;status:string};
export default function Locations(){const[rows,setRows]=useState<Location[]>([]);useEffect(()=>{api<Location[]>("/locations").then(setRows)},[]);return <main className="main"><div className="topbar"><div><h1 className="title">المستودعات والمواقع</h1><div className="subtitle">Warehouse / Vehicle Locations</div></div></div><div className="grid">{rows.map(l=><div className="card" key={l.id}><Warehouse size={18}/><h3>{l.name}</h3><small>{l.code} · {l.type}</small><div style={{marginTop:8}}><span className="badge green">{l.status}</span></div></div>)}</div></main>}