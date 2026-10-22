"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { AlertTriangle, ArrowRight, CheckCircle2, FileImage, FileText, LoaderCircle, Package, Plus, RefreshCw, ScanText, Trash2, UploadCloud, Boxes, Sparkles, CirclePlus, UserPlus } from "lucide-react";
import { API_URL, api } from "@/lib/api";
import { isNexoraInternalSalesInvoice, recognizeInvoice, suggestInvoiceFields, suggestInvoiceLines, suggestSupplierFields } from "@/lib/invoice-ocr";
import { isPurchaseLineReady, normalizePurchaseLine, safeArabicCatalogName } from "@/lib/purchase-line-validation";

type Supplier = { id: string; name: string; code: string };
type Product = { id: string; sku: string; name_ar: string; default_cost: number; default_piece_price?: number; base_unit_id?: string; category_id?: string | null };
type Location = { id: string; name: string; code: string; type?: string };
type Unit = { id: string; code: string; name_ar: string; precision?: number };
type Category = { id: string; code: string; name_ar: string };
type NewProductDraft = { sku: string; supplier_sku: string; supplier_name: string; name_ar: string; name_en: string; brand: string; category_id: string; base_unit_id: string; default_piece_price: string; purchase_packaging_name: string; purchase_packaging_unit_id: string; purchase_conversion_to_base: string };
type PurchaseLine = { id: string; product_id: string; quantity: string; unit_cost: string; invoice_piece_cost?: string; invoice_unit_name?: string; invoice_unit_kind?: "carton" | "piece" | "unknown"; invoice_unit_confirmed?: boolean; item_mode?: "existing" | "new"; invoice_item_name?: string; new_product?: NewProductDraft };
type Extracted = { invoice_number?: string; invoice_date?: string; total?: string; supplier_id?: string; supplier_name?: string; supplier_code?: string; supplier_phone?: string; supplier_tax_number?: string; supplier_address?: string; location_id?: string; lines?: PurchaseLine[]; confidence?: number; pages?: number; manual_review_required?: boolean };
type PurchaseImport = {
  id: string;
  original_name: string;
  mime_type: string;
  file_size: number;
  sha256?: string;
  status: "uploaded" | "needs_review" | "posted" | string;
  extracted_text?: string | null;
  extraction_json?: Extracted | string | null;
  purchase_invoice_id?: string | null;
  posted_document_number?: string | null;
  created_at?: string;
  duplicate?: boolean;
  supplier_id: string;
  supplier_name?: string;
  supplier_code?: string;
  supplier_phone?: string;
  supplier_tax_number?: string;
  supplier_address?: string;
  location_id: string;
  invoice_number: string;
  invoice_date: string;
  recognized_total: string;
  lines: PurchaseLine[];
  progress?: string;
  local_error?: string;
  saving?: boolean;
};

const money = (value: number) => value.toLocaleString("ar-EG", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const id = () => crypto.randomUUID();

function parseExtracted(value: PurchaseImport["extraction_json"]): Extracted {
  if (!value) return {};
  if (typeof value === "string") {
    try { return JSON.parse(value) as Extracted; } catch { return {}; }
  }
  return value;
}

function blankNewProduct(baseUnitId = ""): NewProductDraft {
  return {
    sku: "", supplier_sku: "", supplier_name: "", name_ar: "", name_en: "", brand: "",
    category_id: "", base_unit_id: baseUnitId, default_piece_price: "",
    purchase_packaging_name: "", purchase_packaging_unit_id: "", purchase_conversion_to_base: "1",
  };
}

function mapSuggestedLines(text: string, products: Product[], units: Unit[]): PurchaseLine[] {
  const candidates = suggestInvoiceLines(text);
  const pieceUnit = units.find((unit) => /قطعة|قطع|piece|pcs?/i.test(`${unit.name_ar} ${unit.code}`)) || units[0] || null;

  return candidates.map((candidate) => {
    const normalizedName = `${candidate.description} ${candidate.description_ar || ""}`.toLocaleLowerCase().replace(/\s+/g, " ").trim();
    const existingProduct = (candidate.supplier_sku ? products.find((product) => product.sku.trim().toLocaleLowerCase() === candidate.supplier_sku.toLocaleLowerCase()) : undefined)
      || products.find((product) => normalizedName.length >= 5 && (normalizedName.includes(product.name_ar.toLocaleLowerCase().trim()) || product.name_ar.toLocaleLowerCase().trim().includes(normalizedName)));
    if (existingProduct) {
      return {
        id: id(), product_id: existingProduct.id, item_mode: "existing" as const,
        invoice_item_name: candidate.description || existingProduct.name_ar,
        invoice_unit_name: candidate.unit_name || "",
        invoice_unit_kind: candidate.unit_kind || "unknown",
        invoice_unit_confirmed: false,
        quantity: candidate.quantity, unit_cost: candidate.unit_cost,
      };
    }

    const sourceName = candidate.description_ar || candidate.description || candidate.supplier_sku || "صنف جديد يحتاج مراجعة الاسم";
    return {
      id: id(), product_id: "", item_mode: "new" as const,
      invoice_item_name: sourceName,
      invoice_unit_name: candidate.unit_name || "",
      invoice_unit_kind: candidate.unit_kind || "unknown",
      invoice_unit_confirmed: false,
      quantity: candidate.quantity, unit_cost: candidate.unit_cost,
      new_product: {
        ...blankNewProduct(pieceUnit?.id || ""),
        supplier_sku: candidate.supplier_sku || "",
        supplier_name: sourceName,
        // If OCR returned Arabic, use it as the editable initial catalog name;
        // otherwise leave this blank instead of silently turning English/OCR
        // noise into the canonical Arabic product name.
        name_ar: safeArabicCatalogName(candidate.description_ar || candidate.description),
        name_en: candidate.description,
      },
    };
  });
}

function normalizeDigits(value: string): string {
  return value.replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)));
}

export default function PurchaseImportsPage() {
  const fileInput = useRef<HTMLInputElement>(null);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [units, setUnits] = useState<Unit[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [imports, setImports] = useState<PurchaseImport[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [selectedFiles, setSelectedFiles] = useState<File[]>([]);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [creatingSupplier, setCreatingSupplier] = useState(false);
  const [message, setMessage] = useState("");
  const [pageError, setPageError] = useState("");

  const selected = imports.find((item) => item.id === selectedId) || null;
  const selectedLines = selected?.lines || [];
  const subtotal = selectedLines.reduce((sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.unit_cost) || 0), 0);
  const unitReviewCount = selectedLines.filter((line) => !!line.invoice_unit_name?.trim() && !line.invoice_unit_confirmed).length;
  const recognizedTotal = selected?.recognized_total ? Number(selected.recognized_total) : NaN;
  const hasDetectedTotal = Number.isFinite(recognizedTotal) && recognizedTotal >= 0;
  const totalDifference = hasDetectedTotal ? Math.abs(subtotal - recognizedTotal) : 0;
  const knownSupplier = useMemo(() => {
    if (!selected) return null;
    const text = (selected.extracted_text || "").toLocaleLowerCase();
    const suggestedName = (selected.supplier_name || "").trim().toLocaleLowerCase();
    return suppliers.find((supplier) => {
      const name = supplier.name.trim().toLocaleLowerCase();
      const code = supplier.code.trim().toLocaleLowerCase();
      const exactName = !!suggestedName && (name === suggestedName || (Math.min(name.length, suggestedName.length) >= 8 && (name.includes(suggestedName) || suggestedName.includes(name))));
      return exactName || (!!code && text.includes(code)) || (!!name && name.length >= 8 && text.includes(name));
    }) || null;
  }, [selected?.id, selected?.extracted_text, selected?.supplier_name, suppliers]);
  const reviewedSupplierId = selected?.supplier_id || knownSupplier?.id || "";
  const internalSalesInvoice = selected ? isNexoraInternalSalesInvoice(selected.original_name, selected.extracted_text || "") : false;

  const patchImport = (documentId: string, patch: Partial<PurchaseImport>) => {
    setImports((rows) => rows.map((row) => row.id === documentId ? { ...row, ...patch } : row));
  };

  const loadInitial = async () => {
    setLoading(true);
    setPageError("");
    try {
      const [supplierRows, productRows, locationRows, importResponse, catalogReferences] = await Promise.all([
        api<Supplier[]>("/suppliers"), api<Product[]>("/products"), api<Location[]>("/locations"),
        api<PurchaseImport[]>("/purchase-imports"),
        api<{ units: Unit[]; categories: Category[] }>("/catalog/references"),
      ]);
      setSuppliers(supplierRows);
      setProducts(productRows);
      setUnits(catalogReferences.units);
      setCategories(catalogReferences.categories);
      setLocations(locationRows);
      const rows = importResponse.map((row) => {
        const extracted = parseExtracted(row.extraction_json);
        const parsedHeader = suggestInvoiceFields(row.extracted_text || "");
        const supplierFields = suggestSupplierFields(row.extracted_text || "");
        const savedLines = extracted.lines?.length ? extracted.lines : mapSuggestedLines(row.extracted_text || "", productRows, catalogReferences.units);
        return {
          ...row,
          supplier_id: extracted.supplier_id || "",
          supplier_name: extracted.supplier_name || supplierFields.supplier_name || "",
          supplier_code: extracted.supplier_code || supplierFields.supplier_code || "",
          supplier_phone: extracted.supplier_phone || supplierFields.supplier_phone || "",
          supplier_tax_number: extracted.supplier_tax_number || supplierFields.supplier_tax_number || "",
          supplier_address: extracted.supplier_address || supplierFields.supplier_address || "",
          location_id: extracted.location_id || locationRows.find((location) => location.type === "warehouse")?.id || locationRows[0]?.id || "",
          invoice_number: extracted.invoice_number || parsedHeader.invoice_number || "",
          invoice_date: extracted.invoice_date || parsedHeader.invoice_date || "",
          recognized_total: extracted.total || parsedHeader.total || "",
          lines: savedLines.map((line) => normalizePurchaseLine(line as unknown as Record<string, unknown>, id(), catalogReferences.units[0]?.id || "")),
        } as PurchaseImport;
      });
      setImports(rows);
      setSelectedId((current) => current || rows.find((row) => row.status !== "posted")?.id || rows[0]?.id || "");
    } catch (error) {
      setPageError((error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void loadInitial(); }, []);

  const downloadBlob = async (documentId: string, fileName: string): Promise<File> => {
    const token = localStorage.getItem("nexora_token");
    const response = await fetch(`${API_URL}/purchase-imports/${encodeURIComponent(documentId)}/file`, {
      headers: token ? { Authorization: `Bearer ${token}`, Accept: "*/*" } : { Accept: "*/*" },
    });
    if (!response.ok) throw new Error("تعذر تحميل النسخة الأصلية من الفاتورة من التخزين الخاص.");
    const blob = await response.blob();
    return new File([blob], fileName, { type: blob.type || "application/octet-stream" });
  };

  const runOcr = async (document: PurchaseImport, localFile?: File) => {
    if (document.status === "posted" || document.purchase_invoice_id) return;
    patchImport(document.id, { progress: "جاري تجهيز الملف...", local_error: "" });
    try {
      const file = localFile || await downloadBlob(document.id, document.original_name);
      const ocr = await recognizeInvoice(file, (progress) => patchImport(document.id, { progress }));
      const extracted = suggestInvoiceFields(ocr.text);
      const supplierFields = suggestSupplierFields(ocr.text);
      const suggestedLines = mapSuggestedLines(ocr.text, products, units);
      const extractionJson: Extracted = { ...extracted, ...supplierFields, lines: suggestedLines, pages: ocr.pages, confidence: Number(ocr.confidence.toFixed(2)), manual_review_required: true };
      await api(`/purchase-imports/${document.id}/extraction`, {
        method: "PUT",
        body: JSON.stringify({
          extracted_text: ocr.text,
          extraction_json: extractionJson,
          ocr_engine: "tesseract.js ara+eng",
          ocr_confidence: Number(ocr.confidence.toFixed(2)),
        }),
      });
      patchImport(document.id, {
        status: "needs_review", extracted_text: ocr.text,
        extraction_json: extractionJson,
        supplier_name: supplierFields.supplier_name,
        supplier_code: supplierFields.supplier_code,
        supplier_phone: supplierFields.supplier_phone,
        supplier_tax_number: supplierFields.supplier_tax_number,
        supplier_address: supplierFields.supplier_address,
        invoice_number: extracted.invoice_number || document.invoice_number,
        invoice_date: normalizeDate(extracted.invoice_date || document.invoice_date),
        recognized_total: extracted.total || document.recognized_total,
        lines: suggestedLines,
        progress: "اكتملت القراءة — راجع الحقول والبنود قبل الترحيل.",
      });
      setMessage(suggestedLines.length
        ? `تمت قراءة ${document.original_name}. تم اقتراح ${suggestedLines.length} بندًا من الفاتورة؛ راجع الاسم والوحدة والكمية والتكلفة والإجمالي قبل الترحيل.`
        : `تمت قراءة ${document.original_name} لكن لم تُستخرج بنود مؤكدة تلقائيًا. استخدم «إعادة اقتراح المطابقة»؛ وإذا كانت الصورة غير واضحة أعد تصوير الفاتورة كاملة بإضاءة مستقيمة. لن يتم ترحيل أي مخزون أو حساب قبل اكتمال المراجعة.`);
    } catch (error) {
      patchImport(document.id, { progress: "", local_error: (error as Error).message });
    }
  };

  const createSupplierFromInvoice = async () => {
    if (!selected || creatingSupplier) return;
    const supplierName = (selected.supplier_name || "").trim();
    if (supplierName.length < 3) {
      setMessage("اكتب اسم المورد كما يظهر في أصل الفاتورة، ثم اضغط تسجيل المورد.");
      return;
    }

    setCreatingSupplier(true);
    setMessage("");
    try {
      const created = await api<Supplier>("/suppliers", {
        method: "POST",
        body: JSON.stringify({
          name: supplierName,
          code: null,
          phone: selected.supplier_phone || null,
          tax_number: selected.supplier_tax_number || null,
          address_text: selected.supplier_address || null,
        }),
      });

      setSuppliers((current) => [...current.filter((supplier) => supplier.id !== created.id), created].sort((a, b) => a.name.localeCompare(b.name, "ar")));
      const current = parseExtracted(selected.extraction_json);
      const draft: Extracted = {
        ...current,
        invoice_number: selected.invoice_number,
        invoice_date: selected.invoice_date,
        total: selected.recognized_total,
        supplier_id: created.id,
        supplier_name: created.name,
        supplier_code: selected.supplier_code || current.supplier_code || "",
        supplier_phone: selected.supplier_phone || "",
        supplier_tax_number: selected.supplier_tax_number || "",
        supplier_address: selected.supplier_address || "",
        location_id: selected.location_id,
        lines: selected.lines,
        manual_review_required: true,
      };
      patchImport(selected.id, { supplier_id: created.id, supplier_name: created.name, status: "needs_review", extraction_json: draft });

      try {
        await api(`/purchase-imports/${selected.id}/extraction`, {
          method: "PUT",
          body: JSON.stringify({
            extracted_text: selected.extracted_text,
            extraction_json: draft,
            ocr_engine: "tesseract.js ara+eng",
            ocr_confidence: Number(current.confidence || 0),
          }),
        });
        setMessage(`تم تسجيل المورد «${created.name}» بكود داخلي ${created.code} وربطه بالفاتورة. راجع الإجمالي وبنود الأصناف لإكمال الخطوة التالية.`);
      } catch {
        setMessage(`تم تسجيل المورد «${created.name}»، لكن حفظ الربط على المستند تعذر. اضغط «حفظ المراجعة» قبل مغادرة الشاشة.`);
      }
    } catch (error) {
      setMessage((error as Error).message || "تعذر تسجيل المورد من بيانات الفاتورة.");
    } finally {
      setCreatingSupplier(false);
    }
  };

  const uploadAndRecognize = async () => {
    if (!selectedFiles.length) return setMessage("اختار صورة أو PDF واحد على الأقل.");
    const unsupported = selectedFiles.find((file) => !["application/pdf", "image/jpeg", "image/png", "image/webp"].includes(file.type));
    if (unsupported) return setMessage(`نوع الملف غير مدعوم: ${unsupported.name}`);
    if (selectedFiles.some((file) => file.size > 15 * 1024 * 1024)) return setMessage("حجم كل ملف لازم يكون 15MB أو أقل.");
    if (selectedFiles.length > 10) return setMessage("ارفع 10 فواتير كحد أقصى في الدفعة الواحدة.");

    setUploading(true);
    setMessage("");
    try {
      const form = new FormData();
      selectedFiles.forEach((file) => form.append("documents[]", file));
      const response = await api<{ documents: Array<PurchaseImport & { duplicate?: boolean }> }>("/purchase-imports", { method: "POST", body: form });
      const uploaded = response.documents.map((row) => {
        const extracted = parseExtracted(row.extraction_json);
        return {
          ...row,
          supplier_id: "",
          location_id: locations.find((location) => location.type === "warehouse")?.id || locations[0]?.id || "",
          invoice_number: extracted.invoice_number || "",
          invoice_date: normalizeDate(extracted.invoice_date || ""),
          recognized_total: extracted.total || "",
          lines: (extracted.lines?.length ? extracted.lines : mapSuggestedLines(row.extracted_text || "", products, units)).map((line) => normalizePurchaseLine(line as unknown as Record<string, unknown>, id(), units[0]?.id || "")),
        } as PurchaseImport;
      });
      setImports((current) => {
        const map = new Map(current.map((row) => [row.id, row]));
        uploaded.forEach((row) => {
          const existing = map.get(row.id);
          map.set(row.id, existing ? { ...existing, ...row, ...preserveReview(existing) } : row);
        });
        return Array.from(map.values()).sort((a, b) => b.id.localeCompare(a.id));
      });
      if (uploaded[0]) setSelectedId(uploaded[0].id);
      for (const [index, row] of uploaded.entries()) {
        if (row.status === "posted" || row.purchase_invoice_id) continue;
        if (row.status === "needs_review" && row.extracted_text) continue;
        await runOcr(row, selectedFiles[index]);
      }
      setMessage(`تم حفظ ${uploaded.length} مستند/مستندات في التخزين الخاص. راجع النص المستخرج وأدخل البنود قبل ترحيل أي فاتورة.`);
      setSelectedFiles([]);
      if (fileInput.current) fileInput.current.value = "";
    } catch (error) {
      setMessage((error as Error).message);
    } finally {
      setUploading(false);
    }
  };

  const addLine = () => {
    if (!selected) return;
    patchImport(selected.id, { lines: [...selectedLines, { id: id(), product_id: "", quantity: "", unit_cost: "", item_mode: "existing", invoice_item_name: "" }] });
  };

  const addNewProductLine = (invoiceItemName = "") => {
    if (!selected) return;
    patchImport(selected.id, { lines: [...selectedLines, {
      id: id(), product_id: "", quantity: "", unit_cost: "", item_mode: "new", invoice_item_name: invoiceItemName,
      new_product: {
        ...blankNewProduct(units[0]?.id || ""), supplier_name: invoiceItemName,
        name_ar: safeArabicCatalogName(invoiceItemName),
        name_en: /[A-Za-z]/.test(invoiceItemName) ? invoiceItemName.trim() : "",
      },
    }] });
    setMessage("أضفت بند صنف جديد. اكتب الاسم الداخلي والوحدة والكمية والتكلفة من الفاتورة الأصلية.");
  };

  const switchLineMode = (line: PurchaseLine, mode: "existing" | "new") => {
    if (mode === line.item_mode) return;
    if (mode === "new") {
      const sourceName = line.invoice_item_name || products.find((product) => product.id === line.product_id)?.name_ar || "";
      patchLine(line.id, {
        item_mode: "new", product_id: "", invoice_item_name: sourceName,
        new_product: {
          ...blankNewProduct(units[0]?.id || ""), name_ar: safeArabicCatalogName(sourceName),
          name_en: /[A-Za-z]/.test(sourceName) ? sourceName.trim() : "", supplier_name: sourceName,
        },
      });
    } else {
      patchLine(line.id, { item_mode: "existing", product_id: "", new_product: undefined });
    }
  };

  const patchNewProduct = (lineId: string, patch: Partial<NewProductDraft>) => {
    if (!selected) return;
    patchImport(selected.id, { lines: selectedLines.map((line) => line.id === lineId ? { ...line, new_product: { ...blankNewProduct(units[0]?.id || ""), ...(line.new_product || {}), ...patch } } : line) });
  };

  const patchLine = (lineId: string, patch: Partial<PurchaseLine>) => {
    if (!selected) return;
    patchImport(selected.id, { lines: selectedLines.map((line) => line.id === lineId ? { ...line, ...patch } : line) });
  };

  const suggestProductMatches = () => {
    if (!selected?.extracted_text) return setMessage("شغّل OCR الأول.");
    const suggestions = mapSuggestedLines(selected.extracted_text, products, units);
    if (!suggestions.length) {
      if (selectedLines.length) {
        document.getElementById("purchase-import-lines")?.scrollIntoView({ behavior: "smooth", block: "center" });
        return setMessage("ما قدرتش أستخرج أكواد أصناف واضحة من النص؛ راجع أصل الفاتورة وأضف البند يدويًا أو اختار صنفًا موجودًا.");
      }
      addNewProductLine();
      setMessage("لم تظهر أكواد أصناف يمكن قراءتها بثقة؛ أضف كل بند يدويًا من واقع أصل الفاتورة.");
      return;
    }
    const sourceKey = (line: PurchaseLine): string => {
      if (line.item_mode === "new") return (line.new_product?.supplier_sku || line.invoice_item_name || "").toLocaleLowerCase();
      return products.find((product) => product.id === line.product_id)?.sku.toLocaleLowerCase() || "";
    };
    const oldSourceCodes = new Set(selectedLines.map(sourceKey).filter(Boolean));
    const additions = suggestions.filter((line) => {
      const code = sourceKey(line);
      return !!code && !oldSourceCodes.has(code);
    });
    if (!additions.length) {
      document.getElementById("purchase-import-lines")?.scrollIntoView({ behavior: "smooth", block: "center" });
      return setMessage(`تم تحميل ${suggestions.length} بند مقترح من أكواد الفاتورة بالفعل. راجع الوصف والأسعار وأكمل الحقول الناقصة.`);
    }
    patchImport(selected.id, { lines: [...selectedLines, ...additions] });
    setMessage(`تم تجهيز ${additions.length} بند للمراجعة. ${additions.filter((line) => line.item_mode === "new").length} بند غير مطابق للكتالوج ويحتاج مراجعة/إنشاء صنف. راجع الوحدة والسعر والكمية يدويًا قبل الترحيل.`);
  };

  const saveReviewDraft = async () => {
    if (!selected?.extracted_text || selected.status === "posted") return;
    patchImport(selected.id, { saving: true });
    try {
      const current = parseExtracted(selected.extraction_json);
      const draft: Extracted = {
        ...current,
        invoice_number: selected.invoice_number,
        invoice_date: selected.invoice_date,
        total: selected.recognized_total,
        supplier_id: reviewedSupplierId,
        supplier_name: selected.supplier_name || knownSupplier?.name || "",
        supplier_code: selected.supplier_code || "",
        supplier_phone: selected.supplier_phone || "",
        supplier_tax_number: selected.supplier_tax_number || "",
        supplier_address: selected.supplier_address || "",
        location_id: selected.location_id,
        lines: selectedLines,
        manual_review_required: true,
      };
      await api(`/purchase-imports/${selected.id}/extraction`, {
        method: "PUT",
        body: JSON.stringify({
          extracted_text: selected.extracted_text,
          extraction_json: draft,
          ocr_engine: "tesseract.js ara+eng",
          ocr_confidence: Number(current.confidence || 0),
        }),
      });
      patchImport(selected.id, { saving: false, status: "needs_review", extraction_json: draft });
      setMessage("تم حفظ بيانات المراجعة على مستند الفاتورة. تقدر ترجع تكملها لاحقًا من قائمة المستندات المحفوظة.");
    } catch (error) {
      patchImport(selected.id, { saving: false });
      setMessage((error as Error).message);
    }
  };

  const postPurchase = async () => {
    if (!selected) return;
    if (selected.status === "posted" || selected.purchase_invoice_id) return setMessage("المستند مرحّل بالفعل ولا يمكن ترحيله مرة أخرى.");
    if (internalSalesInvoice) return setMessage("تم إيقاف الترحيل لحماية المخزون والحسابات: الملف فاتورة مبيعات داخلية مرتبطة بعميل ورحلة توزيع، وليس فاتورة مشتريات مورد.");
    if (selected.status !== "needs_review" || !selected.extracted_text) return setMessage("لازم ترفع المستند وتكمل OCR الأول.");
    if (!reviewedSupplierId || !selected.location_id || !selected.lines.length) return setMessage("حدد المورد والمخزن وأضف بنود الفاتورة.");
    if (!hasDetectedTotal) return setMessage("اكتب الإجمالي النهائي من أصل الفاتورة قبل الترحيل، عشان نقدر نتأكد إن مجموع البنود مطابق.");
    if (selected.lines.some((line) => {
      const isNewProduct = line.item_mode === "new";
      const productReady = isNewProduct
        ? !!line.new_product?.name_ar.trim() && !!line.new_product?.base_unit_id
        : !!line.product_id;
      return !productReady || !line.quantity.trim() || !line.unit_cost.trim() || !Number.isFinite(Number(line.quantity)) || Number(line.quantity) <= 0 || !Number.isFinite(Number(line.unit_cost)) || Number(line.unit_cost) < 0 || (!!line.invoice_unit_name?.trim() && !line.invoice_unit_confirmed);
    })) {
      return setMessage("راجع نوع الصنف وبياناته، والوحدة الأساسية، والكمية والتكلفة؛ وأكّد وحدة الفاتورة (كرتونة/قطعة) لكل بند قبل الترحيل.");
    }
    if (hasDetectedTotal && totalDifference > 0.02) {
      return setMessage(`لن أرحّل الفاتورة لأن إجمالي البنود ${money(subtotal)} لا يطابق الإجمالي المقروء ${money(recognizedTotal)}. راجع الكميات والتكاليف والخصومات/الضريبة أولًا.`);
    }

    patchImport(selected.id, { saving: true });
    setMessage("");
    try {
      const result = await api<{ id: string; document_number: string; created_products?: Array<{ id: string; sku: string; name_ar: string }> }>("/purchases", {
        method: "POST",
        body: JSON.stringify({
          supplier_id: reviewedSupplierId,
          location_id: selected.location_id,
          supplier_invoice_number: selected.invoice_number || undefined,
          invoice_date: selected.invoice_date || undefined,
          import_document_id: selected.id,
          discount: 0,
          tax: 0,
          idempotency_key: `invoice-import-${selected.id}`,
          items: selected.lines.map((line) => {
            const isNewProduct = line.item_mode === "new";
            const newProduct = isNewProduct ? line.new_product || blankNewProduct(units[0]?.id || "") : undefined;
            return {
              product_id: isNewProduct ? null : line.product_id,
              new_product: isNewProduct ? {
                ...newProduct,
                sku: typeof newProduct?.sku === "string" ? newProduct.sku.trim() || undefined : undefined,
                category_id: newProduct?.category_id || null,
                default_piece_price: typeof newProduct?.default_piece_price !== "string" || newProduct.default_piece_price.trim() === "" ? undefined : Number(newProduct.default_piece_price),
                supplier_name: (typeof line.invoice_item_name === "string" ? line.invoice_item_name.trim() : "") || newProduct?.supplier_name || newProduct?.name_ar || "",
              } : undefined,
              quantity: Number(line.quantity),
              unit_cost: Number(line.unit_cost),
              unit_id: isNewProduct ? newProduct?.base_unit_id : undefined,
            };
          }),
        }),
      });
      patchImport(selected.id, { status: "posted", purchase_invoice_id: result.id, posted_document_number: result.document_number, saving: false });
      const createdCount = result.created_products?.length ?? selected.lines.filter((line) => line.item_mode === "new").length;
      setMessage(`تم ترحيل ${result.document_number} وربط الملف الأصلي به. ${createdCount ? `تم إنشاء ${createdCount} صنف جديد في الكتالوج وإضافته للمخزون من نفس العملية. ` : "تمت إضافة بنود الفاتورة للمخزون. "}حساب المورد والمخزون اتحدّثوا معًا.`);
      try { setProducts(await api<Product[]>("/products")); } catch { /* Posting already succeeded; catalog refresh is only a convenience. */ }
      await loadOpenBalanceTrigger();
    } catch (error) {
      patchImport(selected.id, { saving: false });
      setMessage((error as Error).message);
    }
  };

  const loadOpenBalanceTrigger = async () => {
    try { await api("/purchases/open-balances"); } catch { /* The purchase post result remains authoritative. */ }
  };

  const selectedProductLinesTotal = selectedLines.reduce((sum, line) => sum + Number(line.quantity || 0) * Number(line.unit_cost || 0), 0);
  const selectedExtraction: Extracted = selected ? parseExtracted(selected.extraction_json) : {};
  const selectedLinesValid = selectedLines.length > 0 && selectedLines.every(isPurchaseLineReady);
  const newProductLineCount = selectedLines.filter((line) => line.item_mode === "new").length;
  const reviewedHeaderValid = !!reviewedSupplierId && !!selected?.location_id;
  const totalsMatch = hasDetectedTotal && totalDifference <= 0.02;
  const importStep = !selected ? 1 : selected.status === "posted" || selected.purchase_invoice_id ? 5 : !selected.extracted_text ? 2 : !reviewedHeaderValid ? 3 : (!selectedLinesValid || !totalsMatch) ? 4 : 5;

  const runMainAction = () => {
    if (!selected) return;
    if (selected.status === "posted" || selected.purchase_invoice_id) return;
    if (internalSalesInvoice) {
      setMessage("المستند المحدد فاتورة مبيعات داخلية، وليس فاتورة مشتريات من مورد. اختار فاتورة المورد الأصلية بدلًا منها.");
      return;
    }
    if (!reviewedSupplierId) {
      document.getElementById("purchase-import-supplier-setup")?.scrollIntoView({ behavior: "smooth", block: "center" });
      setMessage("حدد موردًا مسجلًا أو سجّل المورد الظاهر على الفاتورة من القسم الموضح، وبعدها هتنتقل لمراجعة البنود.");
      return;
    }
    if (!hasDetectedTotal) {
      document.getElementById("purchase-import-total")?.scrollIntoView({ behavior: "smooth", block: "center" });
      setMessage("OCR لم يلتقط الإجمالي النهائي بثقة. اكتبه من أصل الفاتورة، ثم راجع البنود.");
      return;
    }
    if (!selected.extracted_text) {
      void runOcr(selected);
      return;
    }
    if (!selectedLines.length) {
      suggestProductMatches();
      return;
    }
    document.getElementById("purchase-import-lines")?.scrollIntoView({ behavior: "smooth", block: "center" });
  };

  return <main className="main">
    <div className="topbar">
      <div>
        <h1 className="title">استيراد فواتير المشتريات بالصور وPDF</h1>
        <div className="subtitle">ارفع أكثر من فاتورة، اقرأها بالعربي والإنجليزي، راجع الحقول والبنود، ثم رحّل مرة واحدة بأمان.</div>
      </div>
      <Link href="/purchases" className="secondary" style={{ display: "inline-flex", gap: 8, alignItems: "center", textDecoration: "none" }}><ArrowRight size={14}/> إدخال يدوي</Link>
    </div>

    <section className="card invoice-import-stepper-card" aria-label="خطوات استيراد الفاتورة">
      <div className="invoice-import-stepper-heading"><div><strong>{selected ? selected.original_name : "ابدأ برفع فاتورة المورد"}</strong><small>{selected ? (selected.status === "posted" ? `تم الترحيل وربط الفاتورة ${selected.posted_document_number || "بالنظام"}.` : selected.progress || "المستند محفوظ بأمان؛ أكمل الخطوة الحالية ثم انتقل للخطوة التالية.") : "كل خطوة واضحة، ومفيش مخزون أو حسابات هتتغير قبل موافقتك النهائية."}</small></div>{selected && <span className={`badge ${selected.status === "posted" ? "green" : selected.status === "needs_review" ? "blue" : "amber"}`}>{selected.status === "posted" ? "مكتملة" : selected.status === "needs_review" ? "بانتظار المراجعة" : "مرفوعة"}</span>}</div>
      <div className="invoice-import-stepper">
        {[{title:"رفع الفاتورة",help:"صورة أو PDF"},{title:"قراءة النص",help:"OCR عربي/إنجليزي"},{title:"مراجعة البيانات",help:"المورد والمخزن والإجمالي"},{title:"مطابقة الأصناف",help:"الكمية والتكلفة"},{title:"الترحيل للمخزون",help:"بعد التأكيد فقط"}].map((step,index)=>{const number=index+1;const posted=!!(selected&&(selected.status==="posted"||selected.purchase_invoice_id));const complete=posted||importStep>number;const active=!posted&&importStep===number;return <div key={step.title} className={`invoice-import-step ${active?"active":""} ${complete?"complete":""}`} aria-current={active?"step":undefined}><span>{complete?<CheckCircle2 size={15}/>:number}</span><div><strong>{step.title}</strong><small>{step.help}</small></div></div>;})}
      </div>
      {selected && selected.status !== "posted" && <div className="invoice-import-next-step"><span><ScanText size={15}/><strong>الخطوة المطلوبة الآن:</strong> {importStep===2?"شغّل قراءة الملف واستنى استخراج النص.":importStep===3?(!reviewedSupplierId?`المورد ${selected.supplier_name ? `«${selected.supplier_name}»` : "غير محدد"} مش مربوط بسجل مورد بعد؛ اختاره أو سجّله من بيانات الفاتورة.`:"أكد المورد ومخزن الاستلام."):importStep===4?(!hasDetectedTotal?"اكتب الإجمالي النهائي من أصل الفاتورة، ثم أكمل مطابقة الصنف والكمية والتكلفة.":"طابق كل صنف وأدخل الكمية والتكلفة من أصل الفاتورة."):"كل المتطلبات مكتملة. راجع النسخة الأصلية ثم اضغط تأكيد الترحيل."}</span><span className="invoice-import-confidence">{Number.isFinite(Number(selectedExtraction.confidence))?`دقة OCR ${Number(selectedExtraction.confidence).toLocaleString("ar-EG",{maximumFractionDigits:1})}% · مراجعة بشرية مطلوبة`:"OCR لم يكتمل بعد"}</span></div>}
    </section>

    <section className="card" style={{ marginBottom: 14 }}>
      <div className="panel-title"><span><UploadCloud size={16}/> 1. رفع صور الفواتير أو PDF</span><span className="badge blue">10 ملفات كحد أقصى · 15MB لكل ملف</span></div>
      <div style={{ display: "grid", gridTemplateColumns: "minmax(0,1fr) auto", gap: 10, alignItems: "center" }}>
        <input ref={fileInput} type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" onChange={(event) => setSelectedFiles(Array.from(event.target.files || []))} />
        <button className="primary" disabled={loading || uploading || !selectedFiles.length} onClick={() => void uploadAndRecognize()} style={{ display: "inline-flex", gap: 8, alignItems: "center" }}>
          {uploading ? <LoaderCircle size={16} className="spin"/> : <ScanText size={16}/>} {uploading ? "جاري الرفع والقراءة..." : `ارفع واقرأ ${selectedFiles.length || "الفواتير"}`}
        </button>
      </div>
      {!!selectedFiles.length && <div className="invoice-import-selected-files">{selectedFiles.map((file)=> <span key={`${file.name}-${file.size}`}><FileText size={13}/>{file.name}<small>{(file.size/1024/1024).toFixed(2)} MB</small></span>)}</div>}
      <p className="invoice-import-safety-note"><CheckCircle2 size={14}/> الملف بيتحفظ في التخزين الخاص بالمؤسسة، والقراءة بتحصل محليًا في المتصفح. مفيش مخزون أو حساب مورد بيتغير قبل مراجعتك وتأكيد الترحيل.</p>
    </section>

    {pageError && <div role="alert" className="badge red" style={{ display: "block", padding: 12, whiteSpace: "normal", marginBottom: 12 }}>{pageError}</div>}
    {message && <div role="status" className="badge blue" style={{ display: "block", padding: 12, whiteSpace: "normal", marginBottom: 12 }}>{message}</div>}

    <div className="split">
      <section className="card">
        <div className="panel-title"><span>المستندات المحفوظة</span><button className="secondary" onClick={() => void loadInitial()}><RefreshCw size={13}/> تحديث</button></div>
        {loading && <div role="status">جاري تحميل المستندات...</div>}
        {!loading && !imports.length && <div style={{ padding: 28, textAlign: "center", color: "#64748b" }}>ارفع أول فاتورة. تقدر ترفع صور أو PDF، كل ملف مستقل.</div>}
        {imports.map((document) => <button key={document.id} onClick={() => { setSelectedId(document.id); setMessage(""); }} style={{ width: "100%", textAlign: "right", border: 0, borderBottom: "1px solid #e2e8f0", background: selectedId === document.id ? "#f1f5f9" : "#fff", padding: 12, display: "flex", gap: 10, alignItems: "center" }}>
          {document.mime_type === "application/pdf" ? <FileText size={20}/> : <FileImage size={20}/>}<span style={{ flex: 1, minWidth: 0 }}><strong style={{ display: "block", overflowWrap: "anywhere" }}>{document.original_name}</strong><small style={{ display: "block", color: "#64748b" }}>{(document.file_size / 1024 / 1024).toFixed(2)} MB{document.created_at ? ` · ${new Date(document.created_at).toLocaleDateString("ar-EG")}` : " · مستند محفوظ"}</small></span>
          <span className={`badge ${document.status === "posted" ? "green" : document.status === "needs_review" ? "blue" : "amber"}`}>{document.status === "posted" ? (document.posted_document_number || "مرحّل") : document.status === "needs_review" ? "بانتظار المراجعة" : "مرفوع"}</span>
        </button>)}
      </section>

      {selected && <section className="card invoice-import-review-card">
        <div className="panel-title"><span>مراجعة المستند المختار</span>{selected.status === "posted" ? <span className="badge green"><CheckCircle2 size={12}/> تم الترحيل</span> : <span className="badge amber"><AlertTriangle size={12}/> لا ترحيل قبل المراجعة</span>}</div>
        <div className="invoice-import-file-title"><span className="invoice-import-file-icon">{selected.mime_type === "application/pdf" ? <FileText size={22}/> : <FileImage size={22}/>}</span><div><strong>{selected.original_name}</strong><small>{(selected.file_size / 1024 / 1024).toFixed(2)} MB · {selected.created_at ? new Date(selected.created_at).toLocaleString("ar-EG") : "مستند محفوظ"}</small></div><button type="button" className="secondary" onClick={async () => { try { const file = await downloadBlob(selected.id, selected.original_name); const url = URL.createObjectURL(file); window.open(url, "_blank", "noopener,noreferrer"); setTimeout(() => URL.revokeObjectURL(url), 60_000); } catch (error) { setMessage((error as Error).message); } }}><FileText size={13}/> عرض الأصل</button></div>
        {selected.status !== "posted" && <div className="invoice-import-actionbar">
          <div><span className="invoice-import-current-step">الخطوة {importStep} من 5</span><strong>{internalSalesInvoice?"المستند فاتورة مبيعات وليست مشتريات":importStep===2?"اقرأ النص من الملف":importStep===3?"أكد بيانات الفاتورة":importStep===4?"راجع الصنف والكمية والتكلفة":"راجع المستند قبل الترحيل"}</strong><small>{internalSalesInvoice?"الملف فيه بيانات عميل ورحلة توزيع داخلية؛ اختار فاتورة المورد الأصلية.":!selected.extracted_text?"القراءة هتستخرج النص فقط؛ مش هتضيف أي مخزون.":!selectedLines.length?"اختار المورد وراجع الإجمالي، ثم اقترح مطابقة الأصناف.":"قارن الأرقام مع الصورة الأصلية وتأكد إن كل بند متطابق."}</small></div>
          <button type="button" className="primary invoice-import-main-action" disabled={uploading||!!selected.progress||selected.saving||internalSalesInvoice} onClick={runMainAction}>{internalSalesInvoice?<><AlertTriangle size={16}/> اختر فاتورة مشتريات من المورد</>:!selected.extracted_text?<><ScanText size={16}/> قراءة الفاتورة</>:!reviewedSupplierId?<><UserPlus size={16}/> تحديد المورد للمتابعة</>:!hasDetectedTotal?<><ArrowRight size={16}/> أدخل إجمالي الفاتورة</>:!selectedLines.length?<><ScanText size={16}/> اقتراح بنود الأصناف</>:<><ArrowRight size={16}/> مراجعة البنود أدناه</>}</button>
          <button type="button" className="secondary invoice-import-save-action" disabled={selected.status === "posted" || selected.saving || !selected.extracted_text} onClick={() => void saveReviewDraft()}><CheckCircle2 size={13}/> {selected.saving?"جاري الحفظ...":"حفظ المراجعة"}</button>
          <details className="invoice-import-more-tools"><summary>أدوات إضافية</summary><div><button type="button" className="secondary" disabled={!!selected.progress||selected.status === "posted"} onClick={() => void runOcr(selected)}><RefreshCw size={13}/> إعادة قراءة الملف</button><button type="button" className="secondary" disabled={selected.status === "posted"||!selected.extracted_text} onClick={suggestProductMatches}><ScanText size={13}/> إعادة اقتراح المطابقة</button></div></details>
        </div>}
        {internalSalesInvoice && <div role="alert" className="invoice-import-document-type-warning">
          <span className="invoice-import-document-type-icon"><AlertTriangle size={21}/></span>
          <div className="invoice-import-document-type-copy">
            <strong>الملف المحدد فاتورة مبيعات داخلية، وليس فاتورة مشتريات من مورد.</strong>
            <p>الـ OCR وجد رقم فاتورة <b>SI</b> وبيانات عميل ورحلة توزيع. ترحيلها هنا كمشتريات ممكن يكرر حركة المخزون ويشوّه مستحقات المورد، لذلك أوقفنا الترحيل تلقائيًا.</p>
            <small>ارفع صورة أو PDF الفاتورة التي استلمتها من المورد نفسه. لو هدفك مراجعة حركة البيع الداخلية، افتح <Link href="/sales">شاشة المبيعات</Link>.</small>
            <button type="button" className="secondary" onClick={() => { fileInput.current?.scrollIntoView({ behavior: "smooth", block: "center" }); fileInput.current?.click(); }}>اختيار فاتورة المورد الصحيحة</button>
          </div>
        </div>}
        {selected.progress && <div role="status" className="badge blue" style={{ display: "block", whiteSpace: "normal", padding: 10, marginBottom: 10 }}><LoaderCircle size={13} className="spin"/> {selected.progress}</div>}
        {selected.local_error && <div role="alert" className="badge red" style={{ display: "block", padding: 10, whiteSpace: "normal", marginBottom: 10 }}>{selected.local_error}</div>}

        {selected.status === "posted" ? <div className="card" style={{ background: "#f0fdf4" }}><CheckCircle2 color="#16a34a"/> المستند ده مرتبط بفاتورة مشتريات {selected.posted_document_number || "مرحّلة"}. ممنوع ترحيله مرة تانية.</div> : <>
          <div className="form-grid">
            <label>المورد<select value={reviewedSupplierId} onChange={(event) => patchImport(selected.id, { supplier_id: event.target.value })}><option value="">اختار المورد</option>{suppliers.map((supplier) => <option value={supplier.id} key={supplier.id}>{supplier.name} — {supplier.code}</option>)}</select></label>
            <label>المخزن المستلم<select value={selected.location_id} onChange={(event) => patchImport(selected.id, { location_id: event.target.value })}><option value="">اختار المخزن</option>{locations.filter((location) => !location.type || location.type === "warehouse").map((location) => <option value={location.id} key={location.id}>{location.name}</option>)}</select></label>
            <label>رقم فاتورة المورد<input value={selected.invoice_number} onChange={(event) => patchImport(selected.id, { invoice_number: event.target.value })} placeholder="راجع الرقم المقروء" /></label>
            <label>تاريخ الفاتورة<input type="date" value={selected.invoice_date} onChange={(event) => patchImport(selected.id, { invoice_date: event.target.value })} /></label>
            <label id="purchase-import-total">الإجمالي النهائي من أصل الفاتورة<input aria-label="الإجمالي النهائي من أصل الفاتورة" type="number" min="0.01" step="0.01" value={selected.recognized_total} onChange={(event) => patchImport(selected.id, { recognized_total: event.target.value })} placeholder="اكتب الإجمالي النهائي كما هو في الأصل" /></label>
            <label>إجمالي البنود المدخلة<input readOnly value={money(selectedProductLinesTotal)} /></label>
          </div>

          {!reviewedSupplierId && <section id="purchase-import-supplier-setup" className="invoice-import-supplier-setup" aria-label="تحديد أو إنشاء المورد">
            <div className="invoice-import-supplier-setup-heading"><span className="invoice-import-supplier-setup-icon"><UserPlus size={17}/></span><div><strong>المورد غير مربوط بسجل في النظام</strong><small>راجع الاسم المقروء. تقدر تختار موردًا موجودًا من القائمة بالأعلى، أو تسجّل هذا المورد هنا.</small></div></div>
            <div className="invoice-import-supplier-setup-grid">
              <label>اسم المورد كما قرأه OCR<input aria-label="اسم المورد المقروء" value={selected.supplier_name || ""} onChange={(event) => patchImport(selected.id, { supplier_name: event.target.value })} placeholder="اكتب الاسم من رأس الفاتورة"/></label>
              {selected.supplier_code && <div className="invoice-import-external-code"><span>الكود المرجعي الظاهر في الفاتورة</span><strong>{selected.supplier_code}</strong><small>سيُنشأ للمورد كود داخلي مستقل داخل NEXORA.</small></div>}
              <label>رقم الهاتف (اختياري)<input aria-label="هاتف المورد الجديد" value={selected.supplier_phone || ""} onChange={(event) => patchImport(selected.id, { supplier_phone: event.target.value })} placeholder="رقم تواصل المورد"/></label>
              <label>العنوان (اختياري)<input aria-label="عنوان المورد الجديد" value={selected.supplier_address || ""} onChange={(event) => patchImport(selected.id, { supplier_address: event.target.value })} placeholder="العنوان من رأس الفاتورة"/></label>
              <label>الرقم الضريبي (اختياري)<input aria-label="الرقم الضريبي للمورد الجديد" value={selected.supplier_tax_number || ""} onChange={(event) => patchImport(selected.id, { supplier_tax_number: event.target.value })} placeholder="إن كان ظاهرًا بوضوح"/></label>
            </div>
            <div className="invoice-import-supplier-setup-footer"><small>تسجيل المورد يحفظ ملفه فقط؛ لن يتم ترحيل الفاتورة أو تغيير المخزون بهذه الخطوة.</small><button type="button" className="primary" disabled={creatingSupplier || (selected.supplier_name || "").trim().length < 3} onClick={() => void createSupplierFromInvoice()}>{creatingSupplier?<LoaderCircle size={14} className="spin"/>:<UserPlus size={14}/>} {creatingSupplier?"جاري تسجيل المورد...":"تسجيل المورد وربطه بالفاتورة"}</button></div>
          </section>}

          {hasDetectedTotal && totalDifference > 0.02 && <div role="alert" className="badge red" style={{ display: "block", padding: 10, whiteSpace: "normal", marginTop: 10 }}><AlertTriangle size={14}/> فرق {money(totalDifference)} EGP بين إجمالي الفاتورة المقروء ومجموع البنود؛ الترحيل موقوف لحد ما تراجع الكميات والتكلفة. الضريبة وخصم الشراء غير مفعّلين محاسبيًا حتى اعتماد السياسة.</div>}
          {!hasDetectedTotal && <div role="alert" className="invoice-import-total-warning"><AlertTriangle size={16}/><span><strong>الإجمالي غير مؤكد</strong><small>OCR لم يستخرج إجماليًا موثوقًا. اكتب الإجمالي النهائي من أصل الفاتورة؛ الترحيل متوقف حتى يتم إدخاله ومطابقته مع البنود.</small></span></div>}

          <div className="panel-title invoice-import-lines-title" id="purchase-import-lines" style={{ marginTop: 18 }}>
            <span>4. راجع كل صنف في الفاتورة <span className="badge blue">{selectedLines.length} بند</span>{newProductLineCount > 0 && <span className="badge amber">{newProductLineCount} صنف جديد</span>}</span>
            <div className="invoice-import-line-add-actions"><button type="button" className="secondary" onClick={addLine}><Plus size={13}/> بند لصنف موجود</button><button type="button" className="primary" disabled={!units.length} onClick={() => addNewProductLine()}><CirclePlus size={14}/> صنف جديد</button></div>
          </div>
          {!units.length && <div role="alert" className="invoice-import-total-warning"><AlertTriangle size={15}/><span><strong>الوحدات الأساسية لم تُحمّل</strong><small>حدّث الشاشة أو راجع إعدادات الكتالوج قبل إنشاء أصناف جديدة؛ لازم كل صنف يكون له وحدة أساسية.</small></span></div>}
          {selectedLines.map((line, index) => {
            const isNewProduct = line.item_mode === "new";
            const draft = line.new_product || blankNewProduct(units[0]?.id || "");
            const matchedProduct = products.find((product) => product.id === line.product_id);
            return <article className={`invoice-import-line-card ${isNewProduct ? "new-product" : "existing-product"}`} key={line.id}>
              <div className="invoice-import-line-card-head">
                <div className="invoice-import-line-card-title"><span>{index + 1}</span><div><strong>بند {index + 1}</strong><small>{isNewProduct ? "سيتم إنشاء الصنف ثم استلامه مع الفاتورة في عملية واحدة" : matchedProduct ? `${matchedProduct.name_ar} · ${matchedProduct.sku}` : "اختر الصنف الذي يطابق وصف المورد"}</small></div></div>
                <div className="invoice-import-line-mode" role="group" aria-label={`نوع البند ${index + 1}`}><button type="button" className={!isNewProduct ? "selected" : ""} aria-pressed={!isNewProduct} onClick={() => switchLineMode(line, "existing")}><Boxes size={12}/> موجود بالكتالوج</button><button type="button" className={isNewProduct ? "selected new" : ""} aria-pressed={isNewProduct} onClick={() => switchLineMode(line, "new")}><Sparkles size={12}/> صنف جديد</button></div>
                <button type="button" className="icon-btn invoice-import-remove-line" aria-label={`حذف بند ${index + 1}`} onClick={() => patchImport(selected.id, { lines: selectedLines.filter((row) => row.id !== line.id) })}><Trash2 size={13}/></button>
              </div>

              <label className="invoice-import-source-name">اسم الصنف كما هو مكتوب في فاتورة المورد<input value={line.invoice_item_name || ""} onChange={(event) => {
                const value = event.target.value;
                patchImport(selected.id, { lines: selectedLines.map((row) => {
                  if (row.id !== line.id) return row;
                  const previous = row.invoice_item_name || "";
                  const currentName = row.new_product?.name_ar || "";
                  const syncCatalogName = row.item_mode === "new" && (!currentName || currentName === previous);
                  return { ...row, invoice_item_name: value, ...(row.item_mode === "new" ? { new_product: { ...blankNewProduct(units[0]?.id || ""), ...(row.new_product || {}), supplier_name: value, ...(syncCatalogName ? { name_ar: value } : {}), ...(row.new_product?.purchase_packaging_name ? {} : { purchase_packaging_name: row.invoice_unit_name || "" }) } } : {}) };
                }) });
              }} placeholder="انسخ الوصف من الفاتورة الأصلية"/></label>

              {isNewProduct ? <div className="invoice-import-new-product-panel">
                <div className="invoice-import-new-product-heading"><span className="badge amber"><Sparkles size={11}/> سيتم إنشاء صنف جديد</span><small>الكود الداخلي اختياري؛ لو تركته فارغًا سيولّد النظام كودًا تلقائيًا.</small></div>
                <div className="invoice-import-new-product-grid">
                  <label>اسم الصنف في الكتالوج (عربي)<input required value={draft.name_ar} onChange={(event) => patchNewProduct(line.id, { name_ar: event.target.value })} placeholder="مثال: مروحة ستاند 18 بوصة"/></label>
                  <label>الكود الداخلي SKU (اختياري)<input value={draft.sku} onChange={(event) => patchNewProduct(line.id, { sku: event.target.value.toUpperCase() })} placeholder="يُنشأ تلقائيًا عند تركه فارغًا"/></label>
                  <label>كود المورد / الباركود<input value={draft.supplier_sku} onChange={(event) => patchNewProduct(line.id, { supplier_sku: event.target.value })} placeholder="الكود المكتوب على فاتورة المورد"/></label>
                  <label>الاسم بالإنجليزية (اختياري)<input value={draft.name_en} onChange={(event) => patchNewProduct(line.id, { name_en: event.target.value })} placeholder="Product name"/></label>
                  <label>العلامة التجارية (اختياري)<input value={draft.brand} onChange={(event) => patchNewProduct(line.id, { brand: event.target.value })} placeholder="Tornado / Fresh..."/></label>
                  <label>التصنيف<select value={draft.category_id} onChange={(event) => patchNewProduct(line.id, { category_id: event.target.value })}><option value="">بدون تصنيف</option>{categories.map((category) => <option value={category.id} key={category.id}>{category.name_ar}</option>)}</select></label>
                  <label>الوحدة الأساسية<select required value={draft.base_unit_id} onChange={(event) => patchNewProduct(line.id, { base_unit_id: event.target.value })}><option value="">اختار الوحدة</option>{units.map((unit) => <option value={unit.id} key={unit.id}>{unit.name_ar} ({unit.code})</option>)}</select><small>الكمية المستلمة هتتسجل بالوحدة دي.</small></label>
                  <label>اسم عبوة الشراء من الفاتورة<input required={Number(draft.purchase_conversion_to_base || 1) > 1} value={draft.purchase_packaging_name} onChange={(event) => patchNewProduct(line.id, { purchase_packaging_name: event.target.value })} placeholder="مثال: كرتونة 12 قطعة"/><small>يتحفظ كاختيار شراء لهذا الصنف.</small></label>
                  <label>وحدة العبوة<select required={Number(draft.purchase_conversion_to_base || 1) > 1} value={draft.purchase_packaging_unit_id} onChange={(event) => patchNewProduct(line.id, { purchase_packaging_unit_id: event.target.value })}><option value="">اختار وحدة العبوة</option>{units.map((unit) => <option value={unit.id} key={unit.id}>{unit.name_ar || unit.code} ({unit.code})</option>)}</select></label>
                  <label>عدد القطع داخل العبوة<input required type="number" min="0.000001" step="any" value={draft.purchase_conversion_to_base} onChange={(event) => patchNewProduct(line.id, { purchase_conversion_to_base: event.target.value })} placeholder="1"/><small>مثال: كرتونة 12 قطعة = 12. ما تدخلش كمية الكراتين هنا.</small></label>
                  <label>سعر البيع الافتراضي (اختياري)<input type="number" min="0" step="0.01" value={draft.default_piece_price} onChange={(event) => patchNewProduct(line.id, { default_piece_price: event.target.value })} placeholder="0"/></label>
                </div>
              </div> : <div className="invoice-import-existing-product-panel">
                <label>الصنف في الكتالوج<select aria-label={`صنف فاتورة ${index + 1}`} value={line.product_id} onChange={(event) => { const product = products.find((row) => row.id === event.target.value); patchLine(line.id, { product_id: event.target.value, invoice_item_name: line.invoice_item_name || product?.name_ar || "", unit_cost: line.unit_cost || String(product?.default_cost ?? "") }); }}><option value="">اختار صنفًا موجودًا</option>{products.map((product) => <option value={product.id} key={product.id}>{product.name_ar} · {product.sku}</option>)}</select></label>
                {line.product_id && <button type="button" className="invoice-import-convert-new" onClick={() => switchLineMode(line, "new")}><Sparkles size={12}/> مش لاقي الصنف؟ سجّله كصنف جديد</button>}
              </div>}

              <div className="invoice-import-line-numbers">
                <label>الكمية في الفاتورة<input aria-label={`كمية بند ${index + 1}`} type="number" min="0.000001" step="any" value={line.quantity} onChange={(event) => patchLine(line.id, { quantity: event.target.value })} placeholder="الكمية"/></label>
                <label>تكلفة وحدة الفاتورة (ج.م)<input aria-label={`تكلفة بند ${index + 1}`} type="number" min="0" step="0.01" value={line.unit_cost} onChange={(event) => patchLine(line.id, { unit_cost: event.target.value })} placeholder="سعر الكرتونة/الوحدة من الفاتورة"/></label>
                <div className="invoice-import-line-total"><span>قيمة البند</span><strong>{money((Number(line.quantity) || 0) * (Number(line.unit_cost) || 0))} ج.م</strong></div>
                {(line.invoice_piece_cost || (isNewProduct && Number(draft.purchase_conversion_to_base) > 1)) && <div className="invoice-import-line-total"><span>تكلفة القطعة الأساسية</span><strong>{money(line.invoice_piece_cost ? Number(line.invoice_piece_cost) : (Number(line.unit_cost) || 0) / (Number(draft.purchase_conversion_to_base) || 1))} ج.م</strong><small>سعر العبوة ÷ معامل التحويل</small></div>}
              </div>
              {line.invoice_unit_name?.trim() && <div className="invoice-import-unit-confirm" style={{ marginTop: 10, padding: 10, borderRadius: 8, border: "1px solid #f59e0b", background: "#fffbeb" }}>
                <strong>الوحدة المقروءة من الفاتورة: {line.invoice_unit_name}</strong>
                <p style={{ margin: "4px 0 8px", fontSize: 12 }}>اتأكد إن سعر الوحدة والكمية بنفس الوحدة المكتوبة. لو كرتونة، لازم معامل تحويل الكرتونة للصنف يكون معروفًا في الكتالوج قبل الاستلام.</p>
                <label style={{ display: "flex", alignItems: "center", gap: 8 }}><input type="checkbox" checked={!!line.invoice_unit_confirmed} onChange={(event) => patchLine(line.id, { invoice_unit_confirmed: event.target.checked })}/> راجعت الوحدة والكمية وتكلفة الوحدة مع أصل الفاتورة</label>
              </div>}
            </article>;
          })}
          {!selectedLines.length && <div className="invoice-import-no-lines"><Package size={25}/><strong>لسه مفيش أصناف متطابقة</strong><span>استخدم اقتراح المطابقة لو الـ SKU أو الاسم ظاهر في النص، أو اضغط «إضافة بند» واختار الصنف بنفسك. لازم تدخل الكمية والتكلفة من الفاتورة الأصلية.</span><button type="button" className="secondary" onClick={addLine}><Plus size={13}/> إضافة أول بند</button></div>}

          <div style={{ marginTop: 12, display: "flex", justifyContent: "space-between", alignItems: "center" }}><span>إجمالي البنود</span><strong>{money(subtotal)} EGP</strong></div>
          {unitReviewCount > 0 && <div role="alert" className="invoice-import-total-warning" style={{ marginTop: 10 }}><AlertTriangle size={15}/><span><strong>تأكيد الوحدات مطلوب</strong><small>راجع {unitReviewCount} بند قبل الترحيل. مطابقة الإجمالي وحدها لا تكفي إذا كان السعر للكرتونة والكمية سُجلت على أنها قطعة.</small></span></div>}
          <button className="primary invoice-import-post" disabled={internalSalesInvoice || selected.saving || selected.status !== "needs_review" || !selectedLinesValid || !reviewedSupplierId || !selected.location_id || !hasDetectedTotal || totalDifference > 0.02} onClick={() => void postPurchase()} style={{ width: "100%", marginTop: 12, display: "flex", gap: 8, justifyContent: "center" }}>
            {selected.saving ? <LoaderCircle size={15} className="spin"/> : <CheckCircle2 size={15}/>} {selected.saving ? "جاري الترحيل..." : "5. تأكيد الفاتورة وترحيل المخزون"}
          </button>
          <p style={{ color: "#64748b", fontSize: 10, marginBottom: 0 }}>الترحيل يستخدم محرك المعاملات الحالي، ويربط الملف بالفاتورة في نفس العملية. إذا فشل الطلب، فلن يتغير المخزون أو حساب المورد.</p>
        </>}

        <details style={{ marginTop: 14 }}>
          <summary style={{ cursor: "pointer", fontWeight: 700 }}>النص المستخرج من الصورة / PDF</summary>
          <textarea readOnly dir="auto" value={selected.extracted_text || "لسه مفيش نص مستخرج. اضغط قراءة الملف من التخزين."} style={{ width: "100%", minHeight: 220, marginTop: 8, whiteSpace: "pre-wrap" }}/>
          {!!selected.extracted_text && <small style={{ color: "#64748b" }}>القراءة آلية وممكن تخطئ في الأرقام أو العربي؛ اعتمد على أصل الفاتورة عند المراجعة.</small>}
        </details>
      </section>}
    </div>
  </main>;
}

function normalizeDate(value: string): string {
  if (!value) return "";
  const normalized = normalizeDigits(value).trim().replace(/[./]/g, "-");
  if (/^20\d{2}-\d{1,2}-\d{1,2}$/.test(normalized)) {
    const [year, month, day] = normalized.split("-").map(Number);
    return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
  }
  const match = normalized.match(/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/);
  if (!match) return "";
  const day = Number(match[1]); const month = Number(match[2]); const year = Number(match[3].length === 2 ? `20${match[3]}` : match[3]);
  if (month < 1 || month > 12 || day < 1 || day > 31) return "";
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function preserveReview(existing: PurchaseImport): Partial<PurchaseImport> {
  return {
    supplier_id: existing.supplier_id,
    location_id: existing.location_id,
    invoice_number: existing.invoice_number,
    invoice_date: existing.invoice_date,
    recognized_total: existing.recognized_total,
    lines: existing.lines,
  };
}
