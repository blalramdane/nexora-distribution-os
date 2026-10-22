"use client";

import { useEffect, useMemo, useState } from "react";
import { AlertCircle, CheckCircle2, ClipboardCheck, Fuel, RefreshCw, Save, Search } from "lucide-react";
import { api, apiWithOfflineQueue } from "@/lib/api";
import { clearStableIdempotencyKey, getStableIdempotencyKey } from "@/lib/idempotency";

type Trip = { id: string; trip_number: string; vehicle_id: string; vehicle_name: string; vehicle_location_id: string };
type FinancialAccount = { id: string; name: string; currency?: string };
type Expense = { id: string; category: string; amount: string | number; expense_date: string; notes?: string | null; financial_account_name: string };
type StockLine = { product_id: string; sku: string; name_ar: string; base_unit_name?: string | null; expected_quantity_base: number | string };
type StockCountLine = { product_id: string; sku: string; product_name: string; base_unit_name?: string | null; expected_quantity_base: number; counted_quantity_base: number; variance_quantity_base: number };
type CountHistory = { id: string; status: string; item_count: number; variance_items_count: number; notes?: string | null; submitted_at?: string | null; items?: StockCountLine[] };

function wholeDigits(value: string): string {
  return value
    .replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660))
    .replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06f0));
}

export default function FieldTripTools({ trip, mode, onChanged }: { trip: Trip; mode: "expenses" | "stocktake"; onChanged: () => void }) {
  const [accounts, setAccounts] = useState<FinancialAccount[]>([]);
  const [accountId, setAccountId] = useState("");
  const [category, setCategory] = useState("وقود");
  const [amount, setAmount] = useState("");
  const [expenseDate, setExpenseDate] = useState(() => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10));
  const [expenseNotes, setExpenseNotes] = useState("");
  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [stockLines, setStockLines] = useState<StockLine[]>([]);
  const [history, setHistory] = useState<CountHistory[]>([]);
  const [counted, setCounted] = useState<Record<string, string>>({});
  const [stockSearch, setStockSearch] = useState("");
  const [countNotes, setCountNotes] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState("");

  const refresh = async (clearMessage = true) => {
    setLoading(true); if (clearMessage) setMessage("");
    try {
      if (mode === "expenses") {
        const [refs, rows] = await Promise.all([
          api<{ accounts: FinancialAccount[]; payment_methods: unknown[] }>("/field/references"),
          api<Expense[]>(`/field/expenses?trip_id=${encodeURIComponent(trip.id)}`),
        ]);
        setAccounts(refs.accounts); setAccountId((current) => current || refs.accounts[0]?.id || ""); setExpenses(rows);
      } else {
        const response = await api<{ items: StockLine[]; history: CountHistory[] }>(`/field/stocktake?trip_id=${encodeURIComponent(trip.id)}`);
        setStockLines(response.items); setHistory(response.history);
        setCounted((current) => {
          const next = { ...current };
          for (const line of response.items) if (next[line.product_id] === undefined) next[line.product_id] = "";
          return next;
        });
      }
    } catch (error) { setMessage((error as Error).message); }
    finally { setLoading(false); }
  };

  useEffect(() => { void refresh(); }, [mode, trip.id]);

  const visibleStock = useMemo(() => {
    const term = stockSearch.trim().toLocaleLowerCase();
    if (!term) return stockLines;
    return stockLines.filter((line) => `${line.name_ar} ${line.sku}`.toLocaleLowerCase().includes(term));
  }, [stockLines, stockSearch]);
  const countedLines = stockLines.filter((line) => counted[line.product_id] !== undefined && counted[line.product_id].trim() !== "");
  const varianceCount = countedLines.filter((line) => Math.abs(Number(counted[line.product_id]) - Number(line.expected_quantity_base)) > 0.000001).length;

  const saveExpense = async () => {
    if (!category.trim() || !/^\d+$/.test(amount) || !Number.isSafeInteger(Number(amount)) || Number(amount) <= 0 || !accountId) { setMessage("اختار نوع المصروف والحساب واكتب مبلغًا صحيحًا بالجنيه من غير كسور."); return; }
    setSaving(true); setMessage("");
    const payload = { category: category.trim(), amount: Number(amount), financial_account_id: accountId, expense_date: expenseDate, trip_id: trip.id, vehicle_id: trip.vehicle_id, notes: expenseNotes.trim() || null };
    try {
      const key = await getStableIdempotencyKey("field-expense", payload);
      const result = await apiWithOfflineQueue<{ queued?: boolean; category?: string; amount?: number | string }>("/expenses", { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      if (!result.queued) clearStableIdempotencyKey("field-expense", key);
      setMessage(result.queued ? "المصروف اتحفظ على الجهاز وهتتم مزامنته عند عودة الإنترنت." : `تم تسجيل ${result.category ?? category} بقيمة ${Number(result.amount ?? amount).toLocaleString("ar-EG")} جنيه.`);
      setAmount(""); setExpenseNotes("");
      if (!result.queued) setExpenses(await api<Expense[]>(`/field/expenses?trip_id=${encodeURIComponent(trip.id)}`));
      onChanged();
    } catch (error) { setMessage((error as Error).message); }
    finally { setSaving(false); }
  };

  const submitStocktake = async () => {
    if (!countedLines.length) { setMessage("سجّل الكمية المعدودة لصنف واحد على الأقل قبل إرسال الجرد."); return; }
    if (countedLines.some((line) => !/^\d+$/.test(counted[line.product_id]) || !Number.isSafeInteger(Number(counted[line.product_id])))) { setMessage("كميات الجرد لازم تكون أرقامًا صحيحة من غير كسور."); return; }
    setSaving(true); setMessage("");
    const payload = {
      trip_id: trip.id,
      notes: countNotes.trim() || null,
      items: countedLines.map((line) => ({ product_id: line.product_id, counted_quantity_base: Number(counted[line.product_id]) })),
    };
    try {
      const key = await getStableIdempotencyKey("field-stocktake", payload);
      const response = await api<{ id: string; item_count: number; variance_items_count: number }>("/field/stocktake", { method: "POST", body: JSON.stringify({ ...payload, idempotency_key: key }) });
      clearStableIdempotencyKey("field-stocktake", key);
      setMessage(`تم إرسال الجرد للمراجعة: ${response.item_count} صنف، و${response.variance_items_count} صنف بفرق يحتاج مراجعة.`);
      setCountNotes(""); setCounted({});
      await refresh(false); onChanged();
    } catch (error) { setMessage((error as Error).message); }
    finally { setSaving(false); }
  };

  return <section className="field-card field-tools-card">
    <div className="field-tools-heading">
      <span className="field-tools-icon">{mode === "expenses" ? <Fuel size={20}/> : <ClipboardCheck size={20}/>}</span>
      <div><h2>{mode === "expenses" ? "مصروفات الرحلة" : "جرد العربية"}</h2><p>{trip.trip_number} · {trip.vehicle_name}</p></div>
      <button type="button" className="icon-btn" onClick={() => void refresh()} disabled={loading || saving} aria-label="تحديث البيانات"><RefreshCw size={16}/></button>
    </div>

    {loading ? <div className="field-tools-loading">جاري تحميل البيانات...</div> : mode === "expenses" ? <>
      <div className="field-form-stack">
        <label className="action-field">نوع المصروف<select value={category} onChange={(event) => setCategory(event.target.value)}>{["وقود", "تحميل وتنزيل", "رسوم طريق", "صيانة", "مبيت", "ضيافة", "أخرى"].map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
        <div className="field-entry-grid"><label className="action-field">المبلغ بالجنيه<input className="whole-number-input" aria-label="قيمة المصروف" inputMode="numeric" pattern="[0-9]*" type="text" autoComplete="off" value={amount} onChange={(event) => { const value = wholeDigits(event.target.value); if (/^\d*$/.test(value)) setAmount(value); }} placeholder="مثال: 150"/></label><label className="action-field">تاريخ المصروف<input aria-label="تاريخ المصروف" type="date" value={expenseDate} onChange={(event) => setExpenseDate(event.target.value)}/></label></div>
        <label className="action-field">الدفع من الحساب / الخزنة<select value={accountId} onChange={(event) => setAccountId(event.target.value)}><option value="">اختار حسابًا...</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.name}{account.currency ? ` · ${account.currency}` : ""}</option>)}</select></label>
        <label className="action-field">ملاحظة أو رقم إيصال (اختياري)<input value={expenseNotes} onChange={(event) => setExpenseNotes(event.target.value)} placeholder="رقم الإيصال أو تفاصيل مختصرة"/></label>
        <button type="button" className="primary field-submit-button" onClick={saveExpense} disabled={saving || loading}><Save size={17}/>{saving ? "جاري الحفظ..." : "تسجيل المصروف"}</button>
      </div>
      <div className="field-tools-history"><div className="field-card-title"><span>المصروفات المسجلة في الرحلة</span><strong>{expenses.length}</strong></div>
        {expenses.length ? expenses.map((expense) => <div key={expense.id} className="field-expense-row"><span><strong>{expense.category}</strong><small>{expense.financial_account_name} · {expense.expense_date}{expense.notes ? ` · ${expense.notes}` : ""}</small></span><strong>{Number(expense.amount).toLocaleString("ar-EG")} ج.م</strong></div>) : <p className="field-empty-hint">لسه مفيش مصروفات مسجلة على الرحلة دي.</p>}
      </div>
    </> : <>
      <div className="field-stocktake-summary"><div><span>أصناف العربية</span><strong>{stockLines.length.toLocaleString("ar-EG")}</strong></div><div><span>تم عدّها</span><strong>{countedLines.length.toLocaleString("ar-EG")}</strong></div><div className={varianceCount ? "has-variance" : ""}><span>أصناف بفرق</span><strong>{varianceCount.toLocaleString("ar-EG")}</strong></div></div>
      <label className="action-field field-stocktake-search"><span>ابحث في أصناف العربية</span><div className="action-search"><Search size={17}/><input value={stockSearch} onChange={(event) => setStockSearch(event.target.value)} placeholder="اسم الصنف أو الكود..." aria-label="بحث الجرد"/></div></label>
      <div className="field-stocktake-list">
        {visibleStock.map((line) => {
          const value = counted[line.product_id] ?? "";
          const hasCount = value.trim() !== "";
          const variance = hasCount ? Number(value) - Number(line.expected_quantity_base) : 0;
          return <article className="field-stocktake-row" key={line.product_id}>
            <div className="field-stocktake-product"><strong>{line.name_ar}</strong><small>{line.sku}</small><span>المتوقع: {Number(line.expected_quantity_base).toLocaleString("ar-EG", { maximumFractionDigits: 3 })} {line.base_unit_name ?? "وحدة"}</span></div>
            <label className="field-stocktake-count"><span>المعدود فعليًا</span><input className="whole-number-input" type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off" aria-label={`الكمية المعدودة ${line.name_ar}`} value={value} onChange={(event) => { const next = wholeDigits(event.target.value); if (/^\d*$/.test(next)) setCounted((current) => ({ ...current, [line.product_id]: next })); }} placeholder="اكتب الكمية"/><small className={hasCount && Math.abs(variance) > 0.000001 ? "has-variance" : ""}>{hasCount ? `الفرق: ${variance > 0 ? "+" : ""}${variance.toLocaleString("ar-EG", { maximumFractionDigits: 0 })}` : "لسه ما اتعدّش"}</small></label>
          </article>;
        })}
        {!visibleStock.length && <p className="field-empty-hint">مفيش أصناف مطابقة للبحث.</p>}
      </div>
      <label className="action-field field-stocktake-notes">ملاحظات الجرد (اختياري)<textarea rows={2} value={countNotes} onChange={(event) => setCountNotes(event.target.value)} placeholder="سبب الفرق، كسر أو عبوة مفتوحة..."/></label>
      <div className="field-review-warning"><AlertCircle size={18}/><span>إرسال الجرد يحفظ الكميات الفعلية والفروق للمراجعة فقط. لن يتم تعديل المخزون أو تسجيل عجز/زيادة محاسبيًا تلقائيًا.</span></div>
      <button type="button" className="primary field-submit-button" onClick={submitStocktake} disabled={saving || !countedLines.length}><CheckCircle2 size={17}/>{saving ? "جاري إرسال الجرد..." : `إرسال الجرد للمراجعة (${countedLines.length})`}</button>
      {!!history.length && <div className="field-tools-history"><div className="field-card-title"><span>آخر محاضر الجرد</span><strong>{history.length}</strong></div>{history.map((entry) => <div key={entry.id} className="field-expense-row"><span><strong>{Number(entry.item_count).toLocaleString("ar-EG")} صنف · {entry.variance_items_count ? `${entry.variance_items_count} بفرق` : "بدون فرق"}</strong><small>{entry.submitted_at ? new Date(entry.submitted_at).toLocaleString("ar-EG") : "مسجل"} · {entry.notes ?? "بدون ملاحظات"}</small></span><span className="badge blue">{entry.status === "submitted" ? "بانتظار المراجعة" : entry.status}</span></div>)}</div>}
    </>}
    {message && <div role="status" aria-live="polite" className="field-toast">{message}</div>}
  </section>;
}
