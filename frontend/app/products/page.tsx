"use client";

import { useEffect, useMemo, useState } from "react";
import { AlertTriangle, Boxes, CheckCircle2, Package, Plus, Search, SlidersHorizontal, X, Warehouse, Tag, Truck, Link2, Hash, Info, Check, Pencil, Trash2, RotateCcw, RefreshCw } from "lucide-react";
import { api } from "@/lib/api";

type Product = {
  id: string; sku: string; name_ar: string; name_en?: string; brand?: string;
  base_unit_id: string; base_unit_name?: string; category_id?: string | null; category_name?: string | null;
  default_cost: number; default_piece_price: number; quantity_base: number; stock_value: number;
  active?: boolean;
};
type ProductDetails = Product & { color?: string | null; purchase_packaging?: { id: string; carton_unit_id: string; carton_unit_name: string; pieces_per_carton: number | string; carton_purchase_price: number | string | null } | null };
type Unit = { id: string; code: string; name_ar: string; name_en?: string; precision?: number };
type Category = { id: string; code: string; name_ar: string; parent_id?: string | null };
type References = { units: Unit[]; categories: Category[] };
type Location = { id: string; name: string; code: string; type?: string; status?: string };
type Supplier = { id: string; name: string; code: string };
type EntryMode = "new" | "supplier";
type ProductForm = {
  sku: string; name_ar: string; brand: string; category_id: string; base_unit_id: string; carton_unit_id: string;
  pieces_per_carton: string; carton_purchase_price: string; default_piece_price: string;
  supplier_id: string; supplier_sku: string; supplier_name: string;
  initial_cartons: string; initial_pieces: string; initial_location_id: string;
};
type ProductEditForm = { sku: string; name_ar: string; brand: string; category_id: string; base_unit_id: string; carton_unit_id: string; pieces_per_carton: string; carton_purchase_price: string; default_piece_price: string };
const blankForm: ProductForm = {
  sku: "", name_ar: "", brand: "", category_id: "", base_unit_id: "", carton_unit_id: "",
  pieces_per_carton: "1", carton_purchase_price: "", default_piece_price: "",
  supplier_id: "", supplier_sku: "", supplier_name: "", initial_cartons: "0", initial_pieces: "0", initial_location_id: "",
};
const money = (value: number) => `${Number(value || 0).toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ج.م`;
const unitHas = (unit: Unit, words: string[]) => words.some((word) => (unit.name_ar || "").includes(word) || (unit.code || "").toLowerCase().includes(word.toLowerCase()));

export default function Products() {
  const [rows, setRows] = useState<Product[]>([]);
  const [allProducts, setAllProducts] = useState<Product[]>([]);
  const [refs, setRefs] = useState<References>({ units: [], categories: [] });
  const [locations, setLocations] = useState<Location[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [q, setQ] = useState("");
  const [locationId, setLocationId] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [stockStatus, setStockStatus] = useState("all");
  const [catalogStatus, setCatalogStatus] = useState("active");
  const [threshold, setThreshold] = useState("5");
  const [open, setOpen] = useState(false);
  const [categoryOpen, setCategoryOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [editLoading, setEditLoading] = useState(false);
  const [editSaving, setEditSaving] = useState(false);
  const [editingProductId, setEditingProductId] = useState("");
  const [editMsg, setEditMsg] = useState("");
  const [editForm, setEditForm] = useState<ProductEditForm>({ sku: "", name_ar: "", brand: "", category_id: "", base_unit_id: "", carton_unit_id: "", pieces_per_carton: "", carton_purchase_price: "", default_piece_price: "" });
  const [mode, setMode] = useState<EntryMode>("new");
  const [linkProductId, setLinkProductId] = useState("");
  const [linkSearch, setLinkSearch] = useState("");
  const [saving, setSaving] = useState(false);
  const [categorySaving, setCategorySaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [refsReady, setRefsReady] = useState(false);
  const [msg, setMsg] = useState("");
  const [form, setForm] = useState<ProductForm>(blankForm);
  const [categoryForm, setCategoryForm] = useState({ name_ar: "", code: "", parent_id: "" });

  const pieceUnits = useMemo(() => refs.units.filter((unit) => unitHas(unit, ["قطعة", "PCS", "PIECE"])), [refs.units]);
  const cartonUnits = useMemo(() => refs.units.filter((unit) => unitHas(unit, ["كرتونة", "كرتون", "كرتونه", "CARTON", "CTN", "BOX"])), [refs.units]);
  const effectivePieceUnits = pieceUnits.length ? pieceUnits : refs.units;
  const editPieceUnits = (pieceUnits.length ? refs.units.filter((unit) => pieceUnits.some((pieceUnit) => pieceUnit.id === unit.id) || unit.id === editForm.base_unit_id) : refs.units);
  const effectiveCartonUnits = cartonUnits.length ? cartonUnits : refs.units.filter((unit) => unit.id !== form.base_unit_id);
  const editCartonUnits = (cartonUnits.length
    ? refs.units.filter((unit) => cartonUnits.some((cartonUnit) => cartonUnit.id === unit.id) || unit.id === editForm.carton_unit_id)
    : refs.units).filter((unit) => unit.id !== editForm.base_unit_id);
  const piecesPerCarton = Math.max(1, Number(form.pieces_per_carton) || 1);
  const customSupplierPack = form.pieces_per_carton.trim() !== "";
  const cartonCost = Math.max(0, Number(form.carton_purchase_price) || 0);
  const pieceCost = cartonCost / piecesPerCarton;
  const openingCartons = Math.max(0, Number(form.initial_cartons) || 0);
  const openingLoosePieces = Math.max(0, Number(form.initial_pieces) || 0);
  const openingTotalPieces = openingCartons * piecesPerCarton + openingLoosePieces;
  const filteredLinkProducts = useMemo(() => {
    const search = linkSearch.trim().toLocaleLowerCase();
    return allProducts.filter((product) => !search || product.name_ar.toLocaleLowerCase().includes(search) || product.sku.toLocaleLowerCase().includes(search)).slice(0, 80);
  }, [allProducts, linkSearch]);

  const load = async () => {
    setLoading(true);
    const params = new URLSearchParams();
    if (q.trim()) params.set("q", q.trim());
    if (locationId) params.set("location_id", locationId);
    if (categoryId) params.set("category_id", categoryId);
    params.set("stock_status", stockStatus);
    params.set("catalog_status", catalogStatus);
    params.set("low_stock_threshold", threshold || "5");
    try {
      const [products, references, locationRows, supplierRows, catalogProducts] = await Promise.all([
        api<Product[]>(`/products?${params.toString()}`),
        api<References>("/catalog/references"),
        api<Location[]>("/locations"),
        api<Supplier[]>("/suppliers?status=active").catch(() => [] as Supplier[]),
        api<Product[]>("/products?stock_status=all&low_stock_threshold=0&catalog_status=active").catch(() => [] as Product[]),
      ]);
      setRows(products);
      setRefs(references);
      setLocations(locationRows.filter((location) => !location.type || location.type === "warehouse"));
      setSuppliers(supplierRows);
      setAllProducts(catalogProducts);
      setRefsReady(true);
      setForm((current) => ({
        ...current,
        base_unit_id: current.base_unit_id || references.units.find((unit) => unitHas(unit, ["قطعة", "PCS", "PIECE"]))?.id || references.units[0]?.id || "",
        carton_unit_id: current.carton_unit_id || references.units.find((unit) => unitHas(unit, ["كرتونة", "كرتون", "CARTON", "CTN", "BOX"]))?.id || "",
        initial_location_id: current.initial_location_id || locationRows.find((location) => location.type === "warehouse")?.id || "",
      }));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const timer = setTimeout(() => { void load().catch((error) => setMsg((error as Error).message)); }, 180);
    return () => clearTimeout(timer);
  }, [q, locationId, categoryId, stockStatus, catalogStatus, threshold]);

  const openEntry = (nextMode: EntryMode = "new") => {
    setMsg("");
    setMode(nextMode);
    setLinkProductId("");
    setLinkSearch("");
    setForm((current) => ({ ...blankForm, category_id: categoryId, pieces_per_carton: nextMode === "supplier" ? "" : "1", base_unit_id: current.base_unit_id, carton_unit_id: current.carton_unit_id, initial_location_id: current.initial_location_id }));
    setOpen(true);
  };

  const switchMode = (nextMode: EntryMode) => {
    setMode(nextMode);
    setLinkProductId("");
    setForm((current) => ({ ...current, pieces_per_carton: nextMode === "supplier" ? "" : "1", carton_purchase_price: "" }));
  };

  const openEdit = async (productId: string) => {
    setEditOpen(true);
    setEditLoading(true);
    setEditingProductId(productId);
    setEditMsg("");
    try {
      const details = await api<ProductDetails>(`/products/${encodeURIComponent(productId)}`);
      setEditForm({
        sku: details.sku || "", name_ar: details.name_ar || "", brand: details.brand || "",
        category_id: details.category_id || "", base_unit_id: details.base_unit_id || "",
        carton_unit_id: details.purchase_packaging?.carton_unit_id || "",
        pieces_per_carton: details.purchase_packaging ? String(details.purchase_packaging.pieces_per_carton) : "",
        carton_purchase_price: details.purchase_packaging?.carton_purchase_price == null ? "" : String(details.purchase_packaging.carton_purchase_price),
        // A saved zero means no selling price was configured; render the field
        // as empty with an explicit hint instead of making zero look like a price.
        default_piece_price: Number(details.default_piece_price ?? 0) > 0 ? String(Number(details.default_piece_price)) : "",
      });
    } catch (error) {
      setEditMsg((error as Error).message || "تعذر تحميل بيانات الصنف.");
    } finally {
      setEditLoading(false);
    }
  };

  const saveEdit = async (event: React.FormEvent) => {
    event.preventDefault();
    setEditMsg("");
    if (!editingProductId) return;
    if ((editForm.carton_unit_id.trim() === "") !== (editForm.pieces_per_carton.trim() === "")) {
      return setEditMsg("لو هتعدّل عبوة الكرتونة، اختار وحدة الكرتونة واكتب عدد القطع داخلها معًا.");
    }
    if (editForm.pieces_per_carton.trim() && (!Number.isInteger(Number(editForm.pieces_per_carton)) || Number(editForm.pieces_per_carton) < 1)) {
      return setEditMsg("عدد القطع داخل الكرتونة يجب أن يكون عددًا صحيحًا أكبر من صفر.");
    }
    setEditSaving(true);
    try {
      const payload: Record<string, unknown> = {
        sku: editForm.sku.trim(), name_ar: editForm.name_ar.trim(),
        brand: editForm.brand.trim() || null, category_id: editForm.category_id || null,
        base_unit_id: editForm.base_unit_id, default_piece_price: Number(editForm.default_piece_price || 0),
      };
      if (editForm.carton_unit_id && editForm.pieces_per_carton.trim()) {
        payload.carton_unit_id = editForm.carton_unit_id;
        payload.pieces_per_carton = Number(editForm.pieces_per_carton);
        payload.carton_purchase_price = editForm.carton_purchase_price.trim() === "" ? null : Number(editForm.carton_purchase_price);
      }
      await api(`/products/${encodeURIComponent(editingProductId)}`, { method: "PUT", body: JSON.stringify(payload) });
      setEditOpen(false);
      setMsg("تم حفظ تعديلات الصنف بنجاح.");
      await load();
    } catch (error) {
      setEditMsg((error as Error).message || "تعذر حفظ تعديلات الصنف.");
    } finally {
      setEditSaving(false);
    }
  };

  const deleteProduct = async (product: Product) => {
    const confirmed = window.confirm(`هل تريد حذف/إيقاف الصنف «${product.name_ar}»؟\n\nلو الصنف مرتبط بفواتير أو حركات مخزون، سيتم إيقافه من الكتالوج مع الحفاظ على التاريخ. الحذف النهائي مسموح فقط للصنف الذي لا يملك حركات مرتبطة.`);
    if (!confirmed) return;
    try {
      const result = await api<{ action: "deleted" | "deactivated"; message: string }>(`/products/${encodeURIComponent(product.id)}`, { method: "DELETE" });
      setMsg(result.message);
      await load();
    } catch (error) {
      setMsg((error as Error).message || "تعذر حذف/إيقاف الصنف.");
    }
  };

  const restoreProduct = async (product: Product) => {
    try {
      const result = await api<{ message: string }>(`/products/${encodeURIComponent(product.id)}/restore`, { method: "POST", body: JSON.stringify({}) });
      setMsg(result.message);
      await load();
    } catch (error) {
      setMsg((error as Error).message || "تعذر إعادة تفعيل الصنف.");
    }
  };

  const save = async (event: React.FormEvent) => {
    event.preventDefault();
    setMsg("");
    if (!refs.units.length) return setMsg("الوحدات غير محمّلة. حدّث الشاشة أو راجع إعدادات الوحدات أولًا.");
    if (mode === "new" && !form.base_unit_id) return setMsg("حدد الوحدة الأساسية (القطعة).");
    if (mode === "new" && (!form.carton_unit_id || form.carton_unit_id === form.base_unit_id)) return setMsg("حدد وحدة الكرتونة، ويجب أن تختلف عن وحدة القطعة.");
    if (mode === "new" && (!Number.isInteger(piecesPerCarton) || piecesPerCarton < 1)) return setMsg("عدد القطع داخل الكرتونة يجب أن يكون عددًا صحيحًا أكبر من صفر.");
    if (mode === "supplier" && customSupplierPack && (!Number.isInteger(piecesPerCarton) || piecesPerCarton < 1 || !form.carton_unit_id || form.carton_unit_id === form.base_unit_id)) return setMsg("لاستخدام عبوة مورد مختلفة، حدد وحدة الكرتونة وعدد القطع داخلها.");
    if (mode === "supplier" && (!linkProductId || !form.supplier_id)) return setMsg("اختار الصنف الموجود والمورد الذي تريد ربطه به.");
    if (mode === "new" && !form.sku.trim()) return setMsg("اكتب كود الصنف يدويًا.");
    if (openingTotalPieces > 0 && !form.initial_location_id) return setMsg("اختار المخزن الذي يوجد فيه الرصيد الافتتاحي.");
    setSaving(true);
    try {
      if (mode === "supplier") {
        const payload: Record<string, unknown> = {
          supplier_id: form.supplier_id,
          supplier_sku: form.supplier_sku.trim() || null,
          supplier_name: form.supplier_name.trim() || null,
          carton_purchase_price: form.carton_purchase_price.trim() === "" ? null : Number(form.carton_purchase_price),
        };
        if (customSupplierPack) {
          payload.carton_unit_id = form.carton_unit_id;
          payload.pieces_per_carton = piecesPerCarton;
        }
        await api(`/products/${encodeURIComponent(linkProductId)}/suppliers`, { method: "POST", body: JSON.stringify(payload) });
        setMsg("تم ربط المورد بالصنف الموجود. لم يتم إنشاء صنف مكرر.");
      } else {
        const payload: Record<string, unknown> = {
          sku: form.sku.trim(),
          name_ar: form.name_ar.trim(),
          brand: form.brand.trim() || null,
          category_id: form.category_id || null,
          base_unit_id: form.base_unit_id,
          carton_unit_id: form.carton_unit_id,
          pieces_per_carton: piecesPerCarton,
          carton_purchase_price: form.carton_purchase_price.trim() === "" ? null : Number(form.carton_purchase_price),
          default_cost: form.carton_purchase_price.trim() === "" ? 0 : pieceCost,
          default_piece_price: Number(form.default_piece_price || 0),
          supplier_id: form.supplier_id || null,
          supplier_sku: form.supplier_sku.trim() || null,
          supplier_name: form.supplier_name.trim() || null,
          initial_cartons: openingCartons,
          initial_pieces: openingLoosePieces,
          initial_location_id: openingTotalPieces > 0 ? form.initial_location_id : null,
        };
        await api<Product>("/products", { method: "POST", body: JSON.stringify(payload) });
        setMsg(openingTotalPieces > 0
          ? `تم إنشاء الصنف وتسجيل رصيد افتتاحي ${openingTotalPieces.toLocaleString("ar-EG")} قطعة في المخزن المحدد.`
          : "تم إنشاء الصنف ووحدة الكرتونة بنجاح.");
      }
      setOpen(false);
      await load();
    } catch (error) {
      setMsg((error as Error).message || "تعذر حفظ البيانات.");
    } finally {
      setSaving(false);
    }
  };

  const saveCategory = async (event: React.FormEvent) => {
    event.preventDefault();
    setCategorySaving(true);
    setMsg("");
    try {
      const created = await api<Category>("/categories", {
        method: "POST",
        body: JSON.stringify({ name_ar: categoryForm.name_ar.trim(), code: categoryForm.code.trim() || null, parent_id: categoryForm.parent_id || null }),
      });
      setRefs((current) => ({ ...current, categories: [...current.categories, created].sort((a, b) => a.name_ar.localeCompare(b.name_ar, "ar")) }));
      setCategoryId(created.id);
      setForm((current) => ({ ...current, category_id: created.id }));
      setCategoryForm({ name_ar: "", code: "", parent_id: "" });
      setCategoryOpen(false);
      setMsg(open ? "" : `تم إضافة التصنيف «${created.name_ar}».`);
    } catch (error) {
      setMsg((error as Error).message || "تعذر إضافة التصنيف.");
    } finally {
      setCategorySaving(false);
    }
  };

  return <main className="main">
    <div className="topbar product-page-topbar">
      <div><span className="product-eyebrow">PRODUCT CATALOG / LIVE STOCK</span><h1 className="title">الأصناف والمنتجات المتاحة</h1><div className="subtitle">كود موحد للصنف، عبوة شراء بالكرتونة، ومخزون محسوب بالقطع مع ربط اختياري بالمورد.</div></div>
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        <button type="button" className="secondary" onClick={() => { setMsg(""); setCategoryOpen(true); }} style={{ width: "auto", padding: "11px 14px", display: "flex", gap: 7, alignItems: "center" }}><Tag size={15}/> إضافة تصنيف</button>
        <button type="button" className="secondary" onClick={() => openEntry("supplier")} style={{ width: "auto", padding: "11px 14px", display: "flex", gap: 7, alignItems: "center" }}><Link2 size={15}/> ربط مورد بصنف موجود</button>
        <button type="button" className="primary" onClick={() => openEntry("new")} style={{ width: "auto", padding: "11px 16px", display: "flex", gap: 7, alignItems: "center" }}><Plus size={15}/> إضافة صنف</button>
      </div>
    </div>
    <div className="product-metrics">
      <div className="card product-metric"><span><Boxes size={16}/> أصناف مطابقة</span><strong>{rows.length.toLocaleString("ar-EG")}</strong><small>يعرض أول 200 صنف كحد أقصى</small></div>
      <div className="card product-metric"><span><CheckCircle2 size={16}/> أصناف متاحة</span><strong>{rows.filter((product) => Number(product.quantity_base) > Number(threshold || 5)).length.toLocaleString("ar-EG")}</strong><small>أعلى من حد التنبيه</small></div>
      <div className="card product-metric product-metric-warning"><span><AlertTriangle size={16}/> رصيد منخفض</span><strong>{rows.filter((product) => Number(product.quantity_base) > 0 && Number(product.quantity_base) <= Number(threshold || 5)).length.toLocaleString("ar-EG")}</strong><small>يحتاج متابعة</small></div>
      <div className="card product-metric product-metric-danger"><span><Package size={16}/> بدون رصيد</span><strong>{rows.filter((product) => Number(product.quantity_base) <= 0).length.toLocaleString("ar-EG")}</strong><small>غير متوفر بالموقع المختار</small></div>
    </div>
    <section className="card product-filter-card">
      <div className="product-filter-heading"><div><h2><SlidersHorizontal size={16}/> بحث وتصفية الكتالوج</h2><p>ابحث بالاسم أو الكود ثم حدد المخزن والتصنيف وحالة التوفر.</p></div><button type="button" className="secondary" onClick={() => { setQ(""); setLocationId(""); setCategoryId(""); setStockStatus("all"); setCatalogStatus("active"); setThreshold("5"); }}>إعادة ضبط</button></div>
      <div className="product-filter-grid">
        <label className="product-catalog-search"><Search size={16}/><input value={q} onChange={(event) => setQ(event.target.value)} placeholder="ابحث باسم الصنف أو الكود أو البراند..." aria-label="بحث في كتالوج المنتجات"/>{q && <button type="button" onClick={() => setQ("")} aria-label="مسح البحث"><X size={13}/></button>}</label>
        <label>المخزن / موقع الرصيد<select value={locationId} onChange={(event) => setLocationId(event.target.value)}><option value="">كل المواقع</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
        <div style={{ display: "flex", alignItems: "end", gap: 8 }}><label style={{ flex: 1 }}>التصنيف<select value={categoryId} onChange={(event) => setCategoryId(event.target.value)}><option value="">كل التصنيفات</option>{refs.categories.map((category) => <option key={category.id} value={category.id}>{category.name_ar}</option>)}</select></label><button type="button" className="icon-btn" title="إضافة تصنيف جديد" aria-label="إضافة تصنيف جديد" onClick={() => setCategoryOpen(true)}><Plus size={15}/></button></div>
        <label>حالة المخزون<select value={stockStatus} onChange={(event) => setStockStatus(event.target.value)}><option value="all">كل الحالات</option><option value="available">متاح فوق الحد</option><option value="low">رصيد منخفض</option><option value="out">نفد المخزون</option></select></label>
        <label>حالة الصنف<select value={catalogStatus} onChange={(event) => setCatalogStatus(event.target.value)}><option value="active">الأصناف النشطة</option><option value="inactive">الأصناف المؤرشفة</option><option value="all">كل الأصناف</option></select></label>
        {(stockStatus === "low" || stockStatus === "available") && <label>حد التنبيه<input type="number" min="0" step="any" value={threshold} onChange={(event) => setThreshold(event.target.value)}/></label>}
      </div>
      <div className="product-filter-foot"><span>الوحدات الأساسية: <strong>{refs.units.length}</strong></span><span>التصنيفات: <strong>{refs.categories.length}</strong></span><span>النتائج: <strong>{rows.length}</strong></span>{loading && <span className="product-loading-indicator">جاري تحديث النتائج…</span>}</div>
    </section>
    {msg && !open && !categoryOpen && !editOpen && <div className="badge blue" role="status" style={{ marginBottom: 10, padding: 10, whiteSpace: "normal" }}>{msg}</div>}
    <section className="card product-table-card"><div className="product-table-heading"><div><h2>قائمة المنتجات</h2><p>الرصيد المعروض بالوحدة الأساسية (القطعة)، والتكلفة وسعر البيع للقطعة.</p></div><span className="badge blue">{rows.length} نتيجة</span></div>
      <div className="table-wrap"><table><thead><tr><th>المنتج</th><th>SKU</th><th>التصنيف</th><th>المخزن المحدد</th><th>رصيد القطع</th><th>تكلفة القطعة</th><th>قيمة الرصيد</th><th>سعر بيع القطعة</th><th>الإجراءات</th></tr></thead><tbody>
        {loading ? <tr><td colSpan={9}>جاري تحميل المنتجات وأرصدة المخزون...</td></tr> : rows.map((product) => { const quantity = Number(product.quantity_base || 0); const isActive = product.active !== false; const sellingPrice = Number(product.default_piece_price || 0); return <tr key={product.id} className={!isActive ? "product-row-inactive" : ""}><td><div className="product-name-cell"><span className="product-table-icon"><Package size={17}/></span><span><strong>{product.name_ar}</strong>{product.brand && <small>{product.brand}</small>}{!isActive && <small className="product-inactive-label">مؤرشف — غير متاح للبيع</small>}</span></div></td><td><code>{product.sku}</code></td><td>{product.category_name || "بدون تصنيف"}</td><td>{locationId ? (locations.find((location) => location.id === locationId)?.name || "الموقع المحدد") : "كل المواقع"}</td><td><div className="product-stock-cell"><strong className={quantity <= 0 ? "stock-out" : quantity <= Number(threshold || 5) ? "stock-low" : "stock-available"}>{quantity.toLocaleString("ar-EG", { maximumFractionDigits: 3 })} قطعة</strong><span className={`product-stock-badge ${quantity <= 0 ? "out" : quantity <= Number(threshold || 5) ? "low" : "available"}`}>{quantity <= 0 ? "غير متوفر" : quantity <= Number(threshold || 5) ? "رصيد منخفض" : "متاح"}</span></div></td><td>{money(Number(product.default_cost || 0))}</td><td><strong>{money(Number(product.stock_value || 0))}</strong></td><td>{sellingPrice > 0 ? money(sellingPrice) : <span className="product-price-unset">غير محدد</span>}</td><td><div className="product-row-actions"><button type="button" className="product-row-action edit" title="تعديل الصنف" aria-label={`تعديل ${product.name_ar}`} onClick={() => void openEdit(product.id)}><Pencil size={15}/><span>تعديل</span></button>{isActive ? <button type="button" className="product-row-action archive" title="حذف أو أرشفة الصنف" aria-label={`حذف أو أرشفة ${product.name_ar}`} onClick={() => void deleteProduct(product)}><Trash2 size={15}/><span>حذف</span></button> : <><button type="button" className="product-row-action restore" title="إعادة تفعيل الصنف" aria-label={`إعادة تفعيل ${product.name_ar}`} onClick={() => void restoreProduct(product)}><RotateCcw size={15}/><span>استرجاع</span></button><button type="button" className="product-row-action archive" title="حذف نهائي إذا لم توجد سجلات مرتبطة" aria-label={`حذف ${product.name_ar}`} onClick={() => void deleteProduct(product)}><Trash2 size={15}/><span>حذف</span></button></>}</div></td></tr>; })}
        {!loading && !rows.length && <tr><td colSpan={9}><div className="product-empty"><Package size={28}/><strong>{catalogStatus === "inactive" ? "لا توجد أصناف مؤرشفة" : "لا توجد منتجات مطابقة"}</strong><span>{catalogStatus === "inactive" ? "الأصناف التي تؤرشفها ستظهر هنا ويمكنك استرجاعها." : "أضف أول صنف أو غيّر الفلاتر."}</span></div></td></tr>}
      </tbody></table></div>
    </section>

    {open && <div style={{ position: "fixed", inset: 0, background: "#0b173080", display: "grid", placeItems: "center", zIndex: 50, padding: 18 }}>
      <form className="card product-entry-form" onSubmit={save} style={{ width: "min(850px,100%)", maxHeight: "92vh", overflow: "auto", display: "grid", gap: 14 }}>
        <div className="panel-title"><span style={{ fontSize: 17 }}>{mode === "new" ? "إضافة صنف جديد" : "ربط مورد بصنف موجود"}</span><button type="button" aria-label="إغلاق نافذة الأصناف" className="icon-btn" onClick={() => setOpen(false)}><X size={14}/></button></div>
        <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}><button type="button" className={mode === "new" ? "primary" : "secondary"} onClick={() => switchMode("new")}><Plus size={14}/> إنشاء صنف جديد</button><button type="button" className={mode === "supplier" ? "primary" : "secondary"} onClick={() => switchMode("supplier")}><Link2 size={14}/> الصنف موجود — أضف موردًا</button></div>
        {!refsReady && <div className="badge amber" role="status" style={{ display: "block", padding: 10 }}>جاري تحميل الوحدات والتصنيفات...</div>}
        {refsReady && (!effectivePieceUnits.length || !effectiveCartonUnits.length) && <div className="badge red" role="alert" style={{ display: "block", padding: 10 }}>لا توجد وحدة قطعة أو كرتونة مناسبة في الكتالوج. أضف وحدات القطع والكرتون من إعدادات الكتالوج أولًا.</div>}
        {mode === "supplier" && <div style={{ display: "grid", gap: 10 }}>
          <label>ابحث عن الصنف الموجود<input value={linkSearch} onChange={(event) => setLinkSearch(event.target.value)} placeholder="اسم الصنف أو SKU"/></label>
          <label>الصنف<select required value={linkProductId} onChange={(event) => { setLinkProductId(event.target.value); const selected = allProducts.find((product) => product.id === event.target.value); setForm((current) => ({ ...current, base_unit_id: selected?.base_unit_id || current.base_unit_id, supplier_name: selected?.name_ar || "" })); }}><option value="">اختار الصنف — لن يتم إنشاء صنف مكرر</option>{filteredLinkProducts.map((product) => <option key={product.id} value={product.id}>{product.name_ar} · {product.sku}</option>)}</select></label>
        </div>}
        {mode === "new" && <div className="form-grid">
          <label>كود الصنف (SKU) *<input required inputMode="numeric" autoComplete="off" value={form.sku} onChange={(event) => setForm({ ...form, sku: event.target.value })} placeholder="اكتب رقم أو كود الصنف"/></label>
          <label>اسم الصنف بالعربي *<input required value={form.name_ar} onChange={(event) => setForm({ ...form, name_ar: event.target.value })} placeholder="مثال: كبة 8 لتر سوكاني"/></label>
          <label>البراند (اختياري)<input value={form.brand} onChange={(event) => setForm({ ...form, brand: event.target.value })} placeholder="اسم العلامة التجارية"/></label>
          <div style={{ display: "flex", alignItems: "end", gap: 8 }}><label style={{ flex: 1 }}>التصنيف<select value={form.category_id} onChange={(event) => setForm({ ...form, category_id: event.target.value })}><option value="">بدون تصنيف</option>{refs.categories.map((category) => <option key={category.id} value={category.id}>{category.name_ar}</option>)}</select></label><button type="button" className="icon-btn" aria-label="إنشاء تصنيف جديد" title="إنشاء تصنيف جديد" onClick={() => setCategoryOpen(true)}><Plus size={15}/></button></div>
          <label>وحدة القطعة الأساسية<select required value={form.base_unit_id} onChange={(event) => setForm({ ...form, base_unit_id: event.target.value })}>{effectivePieceUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name_ar}</option>)}</select><small>المخزون يتسجل ويتحاسب بالقطع.</small></label>
        </div>}
        <div style={{ padding: 13, background: "#f8fafc", border: "1px solid #dbe4f0", borderRadius: 12, display: "grid", gap: 10 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}><Package size={17}/><strong>{mode === "new" ? "عبوة الشراء والتحويل التلقائي" : "عبوة هذا المورد (اختياري)"}</strong></div>
          <div className="form-grid">
            <label>وحدة العبوة<select required={mode === "new" || customSupplierPack} value={form.carton_unit_id} onChange={(event) => setForm({ ...form, carton_unit_id: event.target.value })}><option value="">{mode === "supplier" ? "استخدم العبوة المسجلة للصنف" : "اختار كرتونة"}</option>{effectiveCartonUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name_ar}</option>)}</select></label>
            <label>عدد القطع داخل الكرتونة {mode === "new" ? "*" : "(اختياري)"}<input required={mode === "new"} type="number" min="1" step="1" value={form.pieces_per_carton} onChange={(event) => setForm({ ...form, pieces_per_carton: event.target.value })} placeholder={mode === "supplier" ? "اتركه فارغًا لاستخدام العبوة الحالية" : "مثال: 2"}/><small>{mode === "new" ? "مثال: كل كرتونة كبة = قطعتين، اكتب 2." : "اكتب قيمة فقط لو عبوة المورد مختلفة عن عبوة الصنف المسجلة."}</small></label>
            <label>تكلفة الكرتونة من المورد (ج.م)<input type="number" min="0" step="0.01" value={form.carton_purchase_price} onChange={(event) => setForm({ ...form, carton_purchase_price: event.target.value })} placeholder="اختياري"/></label>
            {mode === "new" && <><label>تكلفة القطعة المحسوبة (ج.م)<input readOnly value={form.carton_purchase_price.trim() === "" ? "—" : pieceCost.toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 4 })}/><small>تكلفة الكرتونة ÷ عدد القطع.</small></label><label>سعر بيع القطعة (ج.م)<input type="number" min="0" step="0.01" value={form.default_piece_price} onChange={(event) => setForm({ ...form, default_piece_price: event.target.value })} placeholder="اختياري"/></label></>}
          </div>
          {mode === "new" ? <div style={{ fontSize: 13, color: "#1e3a5f" }}>كرتونة واحدة = {piecesPerCarton.toLocaleString("ar-EG")} قطعة. المخزون سيُحوّل تلقائيًا إلى قطع.</div> : <small style={{ color: "#475569" }}>لو تركت عدد القطع فارغًا، يستخدم النظام عبوة الشراء الافتراضية للصنف بدل إنشاء عبوة أخرى.</small>}
        </div>
        <div style={{ padding: 13, border: "1px solid #dbe4f0", borderRadius: 12, display: "grid", gap: 10 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}><Truck size={17}/><strong>المورد (اختياري)</strong><span className="badge">يمكن إضافة أكثر من مورد لنفس الصنف</span></div>
          <div className="form-grid">
            <label>المورد<select value={form.supplier_id} onChange={(event) => setForm({ ...form, supplier_id: event.target.value, supplier_name: "" })}><option value="">بدون مورد حاليًا</option>{suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name} · {supplier.code}</option>)}</select></label>
            <label>كود الصنف عند المورد<input value={form.supplier_sku} onChange={(event) => setForm({ ...form, supplier_sku: event.target.value })} placeholder="كود مختلف عند هذا المورد"/></label>
          </div>
          {mode === "supplier" && <small>سيتم إنشاء أو تحديث علاقة المورد بالصنف المختار فقط. لا ينشأ SKU جديد في الكتالوج.</small>}
        </div>
        {mode === "new" && <div style={{ padding: 13, border: "1px solid #dbe4f0", borderRadius: 12, display: "grid", gap: 10 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}><Warehouse size={17}/><strong>الرصيد الافتتاحي (اختياري)</strong></div>
          <div className="form-grid">
            <label>المخزن<select value={form.initial_location_id} onChange={(event) => setForm({ ...form, initial_location_id: event.target.value })}><option value="">اختار المخزن عند إدخال رصيد</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.name} · {location.code}</option>)}</select></label>
            <label>عدد الكراتين الموجودة<input type="number" min="0" step="1" value={form.initial_cartons} onChange={(event) => setForm({ ...form, initial_cartons: event.target.value })}/></label>
            <label>قطع مفردة خارج الكراتين<input type="number" min="0" step="1" value={form.initial_pieces} onChange={(event) => setForm({ ...form, initial_pieces: event.target.value })}/></label>
            <div style={{ display: "grid", gap: 4, alignContent: "center", padding: 10, borderRadius: 10, background: "#eff6ff" }}><small>إجمالي رصيد القطع الذي سيتم تسجيله</small><strong style={{ fontSize: 20, color: "#1d4ed8" }}>{openingTotalPieces.toLocaleString("ar-EG")} قطعة</strong><small>{openingCartons.toLocaleString("ar-EG")} كرتونة × {piecesPerCarton} + {openingLoosePieces} قطعة مفردة</small></div>
          </div>
          {openingTotalPieces > 0 && <div className="badge amber" role="note" style={{ display: "block", padding: 10, whiteSpace: "normal" }}>سيُسجَّل الرصيد كحركة مخزون افتتاحية موثقة في المخزن المختار. يتطلب ذلك صلاحية تسوية المخزون.</div>}
        </div>}
        {msg && <div className="badge red" role="alert" style={{ display: "block", padding: 10, whiteSpace: "normal" }}>{msg}</div>}
        <div style={{ display: "flex", gap: 8, justifyContent: "flex-end" }}><button type="button" className="secondary" onClick={() => setOpen(false)}>إلغاء</button><button className="primary" disabled={saving || !refsReady || !effectivePieceUnits.length || !effectiveCartonUnits.length}>{saving ? "جاري الحفظ..." : mode === "new" ? "حفظ الصنف" : "ربط المورد بالصنف"}</button></div>
      </form>
    </div>}

    {editOpen && <div className="product-edit-modal-backdrop">
      <form className="card product-edit-modal" onSubmit={saveEdit}>
        <div className="product-edit-modal-head">
          <div className="product-edit-modal-heading"><span className="product-edit-modal-icon"><Pencil size={20}/></span><span><strong>تعديل بيانات الصنف</strong><small>عدّل الكود والاسم والتصنيف والسعر وعبوة الكرتونة</small></span></div>
          <button type="button" className="icon-btn product-edit-modal-close" aria-label="إغلاق نافذة تعديل الصنف" onClick={() => { setEditOpen(false); setEditMsg(""); }}><X size={16}/></button>
        </div>
        {editLoading ? <div className="product-edit-loading"><span className="warehouse-saving-icon"><RefreshCw size={19}/></span> جاري تحميل بيانات الصنف...</div> : <>
          <div className="product-edit-form-grid">
            <label className="product-edit-field"><span>كود الصنف (SKU) *</span><input required maxLength={128} value={editForm.sku} onChange={(event) => setEditForm({ ...editForm, sku: event.target.value })} dir="ltr"/></label>
            <label className="product-edit-field"><span>اسم الصنف بالعربي *</span><input required maxLength={255} value={editForm.name_ar} onChange={(event) => setEditForm({ ...editForm, name_ar: event.target.value })}/></label>
            <label className="product-edit-field"><span>البراند (اختياري)</span><input maxLength={255} value={editForm.brand} onChange={(event) => setEditForm({ ...editForm, brand: event.target.value })} placeholder="اسم العلامة التجارية"/></label>
            <label className="product-edit-field"><span>التصنيف</span><select value={editForm.category_id} onChange={(event) => setEditForm({ ...editForm, category_id: event.target.value })}><option value="">بدون تصنيف</option>{refs.categories.map((category) => <option key={category.id} value={category.id}>{category.name_ar}</option>)}</select></label>
            <label className="product-edit-field"><span>وحدة المخزون الأساسية *</span><select required value={editForm.base_unit_id} onChange={(event) => setEditForm({ ...editForm, base_unit_id: event.target.value })}>{editPieceUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name_ar}</option>)}</select></label>
            <label className="product-edit-field product-edit-price-field"><span>سعر بيع القطعة الواحدة (ج.م)</span><input type="number" min="0" max="1000000000" step="0.01" inputMode="decimal" value={editForm.default_piece_price} placeholder="اكتب سعر القطعة مثل ٧٠٠" onChange={(event) => setEditForm({ ...editForm, default_piece_price: event.target.value })}/><small className={Number(editForm.default_piece_price || 0) > 0 ? "product-edit-price-set" : "product-edit-price-unset"}>{Number(editForm.default_piece_price || 0) > 0 ? "ده السعر اللي هيظهر افتراضيًا عند البيع." : "سعر البيع غير محدد للصنف ده حاليًا. اكتب السعر هنا."}</small></label>
          </div>
          <div className="product-edit-packaging">
            <div className="product-edit-packaging-title"><Package size={17}/><span><strong>عبوة الكرتونة</strong><small>سيتم إنشاء نسخة جديدة من العبوة عند تغيير التحويل، مع الاحتفاظ بالعبوة التاريخية المرتبطة بالفواتير.</small></span></div>
            <div className="product-edit-form-grid">
              <label className="product-edit-field"><span>وحدة الكرتونة</span><select value={editForm.carton_unit_id} onChange={(event) => setEditForm({ ...editForm, carton_unit_id: event.target.value })}><option value="">بدون تعديل العبوة</option>{editCartonUnits.map((unit) => <option key={unit.id} value={unit.id}>{unit.name_ar}</option>)}</select></label>
              <label className="product-edit-field"><span>القطع داخل الكرتونة</span><input type="number" min="1" max="1000000" step="1" value={editForm.pieces_per_carton} onChange={(event) => setEditForm({ ...editForm, pieces_per_carton: event.target.value })} placeholder="مثال: 2"/><small>لو عدلتها، حدد وحدة الكرتونة أيضًا.</small></label>
              <label className="product-edit-field"><span>تكلفة الكرتونة من المورد (ج.م)</span><input type="number" min="0" max="1000000000" step="0.01" value={editForm.carton_purchase_price} onChange={(event) => setEditForm({ ...editForm, carton_purchase_price: event.target.value })} placeholder="اختياري"/></label>
              <div className="product-edit-piece-cost"><small>تكلفة القطعة المحسوبة</small><strong>{editForm.carton_purchase_price.trim() && Number(editForm.pieces_per_carton) > 0 ? money(Number(editForm.carton_purchase_price) / Number(editForm.pieces_per_carton)) : "—"}</strong><small>تكلفة الكرتونة ÷ عدد القطع</small></div>
            </div>
          </div>
        </>}
        {editMsg && <div className="product-edit-error" role="alert">{editMsg}</div>}
        <div className="product-edit-modal-actions"><button type="button" className="product-category-modal-cancel" onClick={() => { setEditOpen(false); setEditMsg(""); }}>إلغاء</button><button className="primary product-category-modal-submit" disabled={editLoading || editSaving || !editForm.sku.trim() || !editForm.name_ar.trim()}>{editSaving ? "جاري حفظ التعديلات..." : <><Check size={16}/> حفظ التعديلات</>}</button></div>
      </form>
    </div>}

    {categoryOpen && <div className="product-category-modal-backdrop">
      <form className="card product-category-modal" onSubmit={saveCategory}>
        <div className="product-category-modal-head">
          <div className="product-category-modal-heading">
            <span className="product-category-modal-icon"><Tag size={21}/></span>
            <span><strong>إضافة تصنيف جديد</strong><small>نظّم أصنافك في مجموعات واضحة وسهلة البحث</small></span>
          </div>
          <button type="button" className="icon-btn product-category-modal-close" aria-label="إغلاق نافذة التصنيف" onClick={() => { setCategoryOpen(false); setMsg(""); }}><X size={16}/></button>
        </div>

        <div className="product-category-form-grid">
          <label className="product-category-form-field product-category-form-field-full">
            <span className="product-category-form-label"><Tag size={14}/> اسم التصنيف بالعربي <b>*</b></span>
            <input required autoFocus maxLength={180} value={categoryForm.name_ar} onChange={(event) => setCategoryForm({ ...categoryForm, name_ar: event.target.value })} placeholder="مثال: أدوات كهربائية، أجهزة مطبخ، كابلات"/>
            <small>اختار اسمًا واضحًا يعبّر عن مجموعة الأصناف الموجودة تحته.</small>
          </label>
          <label className="product-category-form-field">
            <span className="product-category-form-label"><Hash size={14}/> كود التصنيف <em>اختياري</em></span>
            <input maxLength={64} dir="ltr" value={categoryForm.code} onChange={(event) => setCategoryForm({ ...categoryForm, code: event.target.value })} placeholder="مثال: KITCHEN-TOOLS"/>
            <small>لو تركته فارغًا، النظام هيولّد كودًا تلقائيًا.</small>
          </label>
          <label className="product-category-form-field">
            <span className="product-category-form-label"><Boxes size={14}/> التصنيف الرئيسي <em>اختياري</em></span>
            <select value={categoryForm.parent_id} onChange={(event) => setCategoryForm({ ...categoryForm, parent_id: event.target.value })}>
              <option value="">تصنيف رئيسي — بدون أب</option>
              {refs.categories.map((category) => <option key={category.id} value={category.id}>{category.name_ar}</option>)}
            </select>
            <small>اختار تصنيفًا موجودًا لو عايز تعمل تصنيفًا فرعيًا.</small>
          </label>
        </div>

        <div className="product-category-modal-note"><span><Info size={17}/></span><p><strong>التصنيف هيكون متاحًا فور الحفظ</strong><small>هيظهر في فلتر الكتالوج وفي نموذج إضافة الصنف، وتقدر تختاره مباشرة من غير ما تعيد تحميل الصفحة.</small></p></div>
        {msg && <div className="product-category-modal-error" role="alert">{msg}</div>}
        <div className="product-category-modal-actions">
          <button type="button" className="product-category-modal-cancel" onClick={() => { setCategoryOpen(false); setMsg(""); }}>إلغاء</button>
          <button className="primary product-category-modal-submit" disabled={categorySaving || !categoryForm.name_ar.trim()}>{categorySaving ? <><span className="warehouse-saving-icon"><Hash size={15}/></span> جارٍ حفظ التصنيف...</> : <><Check size={16}/> حفظ التصنيف</>}</button>
        </div>
      </form>
    </div>}
  </main>;
}
