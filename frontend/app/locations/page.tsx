"use client";

import { useEffect, useState } from "react";
import { Warehouse, Plus, X, RefreshCw, MapPin, Hash, Info, Check } from "lucide-react";
import { api } from "@/lib/api";

type Location = { id: string; code: string; name: string; type: string; status: string };
type WarehouseForm = { name: string; code: string; address: string };
const blankForm: WarehouseForm = { name: "", code: "", address: "" };

export default function Locations() {
  const [rows, setRows] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [msg, setMsg] = useState("");
  const [form, setForm] = useState<WarehouseForm>(blankForm);

  const load = async () => {
    setLoading(true);
    try { setRows(await api<Location[]>("/locations")); }
    catch (error) { setMsg((error as Error).message || "تعذر تحميل المخازن."); }
    finally { setLoading(false); }
  };

  useEffect(() => { void load(); }, []);

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setSaving(true);
    setMsg("");
    try {
      const created = await api<Location>("/locations", {
        method: "POST",
        body: JSON.stringify({ name: form.name.trim(), code: form.code.trim() || null, address: form.address.trim() || null, type: "warehouse" }),
      });
      setRows((current) => [...current, created].sort((a, b) => a.name.localeCompare(b.name, "ar")));
      setForm(blankForm);
      setOpen(false);
      setMsg(`تم إنشاء المخزن «${created.name}» وربطه بموقع المخزون.`);
    } catch (error) {
      setMsg((error as Error).message || "تعذر إنشاء المخزن.");
    } finally {
      setSaving(false);
    }
  };

  return <main className="main">
    <div className="topbar">
      <div><h1 className="title">المستودعات والمواقع</h1><div className="subtitle">إنشاء مخازن فعلية وربطها بأرصدة المخزون واستلام المشتريات.</div></div>
      <div style={{ display: "flex", gap: 8 }}>
        <button type="button" className="secondary" onClick={() => void load()} disabled={loading} style={{ width: "auto", display: "flex", alignItems: "center", gap: 6 }}><RefreshCw size={14}/> تحديث</button>
        <button type="button" className="primary" onClick={() => { setMsg(""); setOpen(true); }} style={{ width: "auto", display: "flex", alignItems: "center", gap: 7 }}><Plus size={15}/> إضافة مخزن</button>
      </div>
    </div>
    {msg && !open && <div className="badge blue" role="status" style={{ display: "block", padding: 11, marginBottom: 12, whiteSpace: "normal" }}>{msg}</div>}
    {loading ? <div className="card">جاري تحميل المخازن والمواقع...</div> : <div className="grid">
      {rows.map((location) => <div className="card" key={location.id} style={{ display: "grid", gap: 9, alignContent: "start" }}>
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}><Warehouse size={18}/><span className="badge green">{location.status === "active" ? "نشط" : location.status}</span></div>
        <h3 style={{ margin: 0 }}>{location.name}</h3>
        <small>{location.code} · {location.type === "warehouse" ? "مخزن" : location.type}</small>
        <small style={{ color: "#64748b" }}><MapPin size={12} style={{ verticalAlign: "middle", marginInlineEnd: 4 }}/>موقع مخزون متاح للاستلام والتسوية.</small>
      </div>)}
      {!rows.length && <div className="card" style={{ gridColumn: "1 / -1", display: "grid", justifyItems: "center", gap: 8, padding: 32 }}><Warehouse size={28}/><strong>لا توجد مخازن بعد</strong><span>أنشئ المخزن الرئيسي أو مخزنًا إضافيًا قبل إدخال الرصيد الافتتاحي.</span><button type="button" className="primary" onClick={() => setOpen(true)}><Plus size={14}/> إنشاء أول مخزن</button></div>}
    </div>}
    {open && <div className="warehouse-modal-backdrop">
      <form className="card warehouse-modal" onSubmit={save}>
        <div className="warehouse-modal-head">
          <div className="warehouse-modal-heading">
            <span className="warehouse-modal-icon"><Warehouse size={21}/></span>
            <span><strong>إضافة مخزن جديد</strong><small>أدخل بيانات المخزن وموقعه داخل النظام</small></span>
          </div>
          <button type="button" className="icon-btn warehouse-modal-close" aria-label="إغلاق" onClick={() => { setOpen(false); setMsg(""); }}><X size={16}/></button>
        </div>

        <div className="warehouse-form-grid">
          <label className="warehouse-form-field">
            <span className="warehouse-form-label"><Warehouse size={14}/> اسم المخزن <b>*</b></span>
            <input required autoFocus maxLength={180} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="مثال: مخزن دمياط الرئيسي"/>
            <small>اسم واضح يظهر للموظفين والمندوبين.</small>
          </label>
          <label className="warehouse-form-field">
            <span className="warehouse-form-label"><Hash size={14}/> كود المخزن <em>اختياري</em></span>
            <input maxLength={64} value={form.code} onChange={(event) => setForm({ ...form, code: event.target.value })} placeholder="مثال: DAM-WH-02" dir="ltr"/>
            <small>سيُنشأ كود تلقائيًا لو تركته فارغًا.</small>
          </label>
          <label className="warehouse-form-field warehouse-form-field-full">
            <span className="warehouse-form-label"><MapPin size={14}/> العنوان أو وصف الموقع <em>اختياري</em></span>
            <textarea rows={3} maxLength={2000} value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} placeholder="المنطقة، الشارع، أو أي وصف يساعد على تحديد مكان المخزن"/>
          </label>
        </div>

        <div className="warehouse-modal-note"><span><Info size={17}/></span><p><strong>ماذا سيحدث عند الحفظ؟</strong><small>سيُنشأ المخزن وموقع المخزون المرتبط به معًا، ليصبح متاحًا في فواتير الشراء وأرصدة وتسويات المخزون.</small></p></div>
        {msg && <div className="warehouse-modal-error" role="alert">{msg}</div>}
        <div className="warehouse-modal-actions">
          <button type="button" className="warehouse-modal-cancel" onClick={() => { setOpen(false); setMsg(""); }}>إلغاء</button>
          <button className="primary warehouse-modal-submit" disabled={saving || !form.name.trim()}>{saving ? <><RefreshCw size={15} className="warehouse-saving-icon"/> جارٍ حفظ المخزن...</> : <><Check size={16}/> حفظ المخزن</>}</button>
        </div>
      </form>
    </div>}
  </main>;
}
