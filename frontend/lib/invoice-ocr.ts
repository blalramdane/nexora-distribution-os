"use client";

export type InvoiceOcrResult = {
  text: string;
  confidence: number;
  pages: number;
};

export type SuggestedSupplierFields = {
  supplier_name: string;
  supplier_code: string;
  supplier_phone: string;
  supplier_tax_number: string;
  supplier_address: string;
};

export type SuggestedInvoiceLine = {
  supplier_sku: string;
  description: string;
  description_ar?: string;
  unit_name?: string;
  unit_kind?: "carton" | "piece" | "unknown";
  quantity: string;
  unit_cost: string;
  line_total: string;
};

function normalizeOcrMoneyToken(value: string): string {
  const clean = value.replace(/[٬,]/g, "").replace(/٫/g, ".").trim();
  if (!/^\d+(?:\.\d{1,4})?$/.test(clean)) return "";
  const amount = Number(clean);
  return Number.isFinite(amount) && amount >= 0 && amount <= 100_000_000 ? amount.toFixed(2) : "";
}

function cleanOcrText(text: string): string {
  return text
    .replace(/[\u200e\u200f\u202a-\u202e\u2066-\u2069\uFEFF]/g, "")
    .replace(/\u00a0/g, " ");
}

const MAX_PDF_PAGES = 12;

export async function recognizeInvoice(file: File, onProgress?: (label: string) => void): Promise<InvoiceOcrResult> {
  const { createWorker } = await import("tesseract.js");
  const worker = await createWorker("ara+eng", 1, {
    logger: (event) => {
      if (event.status) onProgress?.(event.status === "recognizing text" ? "جاري قراءة النص" : "جاري تجهيز محرك القراءة");
    },
  });

  try {
    if (file.type === "application/pdf") {
      const pdfjs = await import("pdfjs-dist/legacy/build/pdf.mjs");
      pdfjs.GlobalWorkerOptions.workerSrc = new URL("pdfjs-dist/legacy/build/pdf.worker.min.mjs", import.meta.url).toString();
      const pdf = await pdfjs.getDocument({ data: new Uint8Array(await file.arrayBuffer()) }).promise;
      if (pdf.numPages > MAX_PDF_PAGES) {
        throw new Error(`الفاتورة تحتوي على ${pdf.numPages} صفحة؛ الحد الحالي ${MAX_PDF_PAGES} صفحة لكل ملف.`);
      }

      const pages: string[] = [];
      const confidences: number[] = [];
      for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
        onProgress?.(`قراءة صفحة ${pageNumber} من ${pdf.numPages}`);
        const page = await pdf.getPage(pageNumber);
        // Supplier invoice tables are small on a full-page scan; a higher
        // raster scale substantially improves Arabic labels and numeric cells.
        const viewport = page.getViewport({ scale: 2.5 });
        const canvas = document.createElement("canvas");
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        const context = canvas.getContext("2d");
        if (!context) throw new Error("تعذر تجهيز صفحة PDF للقراءة.");
        await page.render({ canvas, canvasContext: context, viewport }).promise;
        const fullPage = await worker.recognize(canvas, {}, { text: true, blocks: true });
        // Table-oriented segmentation fallback: change page layout mode and
        // preserve inter-word spacing for invoices printed in columns.
        await worker.setParameters({ tessedit_pageseg_mode: "11" as never, preserve_interword_spaces: "1" });
        const structuredPass = await worker.recognize(canvas, {}, { text: true, blocks: true });
        await worker.setParameters({ tessedit_pageseg_mode: "3" as never, preserve_interword_spaces: "1" });
        const firstText = fullPage.data.text || "";
        const secondText = structuredPass.data.text || "";
        pages.push(`--- صفحة ${pageNumber} ---\n${firstText}${secondText && secondText !== firstText ? `\n--- قراءة جدول بديلة ---\n${secondText}` : ""}`);
        confidences.push(Number(fullPage.data.confidence || 0));
        canvas.width = 1;
        canvas.height = 1;
      }

      return {
        text: pages.join("\n\n"),
        confidence: confidences.length ? confidences.reduce((sum, value) => sum + value, 0) / confidences.length : 0,
        pages: pdf.numPages,
      };
    }

    onProgress?.("جاري قراءة الصورة");
    // Normalize photographed receipts before OCR: upscale and create a
    // contrast-enhanced greyscale copy so colored light/shadows affect less.
    const image = await createImageBitmap(file);
    const scale = Math.min(3, Math.max(1.8, 2200 / Math.max(image.width, image.height)));
    const canvas = document.createElement("canvas");
    canvas.width = Math.ceil(image.width * scale);
    canvas.height = Math.ceil(image.height * scale);
    const context = canvas.getContext("2d", { willReadFrequently: true });
    if (!context) throw new Error("تعذر تجهيز الصورة للقراءة.");
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    image.close();
    const pixels = context.getImageData(0, 0, canvas.width, canvas.height);
    for (let i = 0; i < pixels.data.length; i += 4) {
      const luminance = (pixels.data[i] * 0.299 + pixels.data[i + 1] * 0.587 + pixels.data[i + 2] * 0.114);
      const enhanced = luminance < 155 ? Math.max(0, luminance * 0.75) : Math.min(255, 155 + (luminance - 155) * 1.3);
      pixels.data[i] = enhanced;
      pixels.data[i + 1] = enhanced;
      pixels.data[i + 2] = enhanced;
    }
    context.putImageData(pixels, 0, 0);
    const result = await worker.recognize(canvas, {}, { text: true, blocks: true });
    // A second layout pass improves small header fields (notably invoice dates)
    // that can be mistaken for a one-digit day on photographed/scanned invoices.
    // Keep both readings: the conservative field parser can compare candidates
    // rather than permanently trusting the first occurrence in the image.
    await worker.setParameters({ tessedit_pageseg_mode: "11" as never, preserve_interword_spaces: "1" });
    const structuredPass = await worker.recognize(canvas, {}, { text: true, blocks: true });
    canvas.width = 1;
    canvas.height = 1;
    const firstText = result.data.text || "";
    const secondText = structuredPass.data.text || "";
    return {
      text: secondText && secondText !== firstText ? `${firstText}\n--- قراءة بديلة ---\n${secondText}` : firstText,
      confidence: Number(result.data.confidence || 0),
      pages: 1,
    };
  } finally {
    await worker.terminate();
  }
}

function westernDigits(value: string): string {
  return value.replace(/[٠-٩]/g, (digit) => String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/[۰-۹]/g, (digit) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)));
}

export function suggestInvoiceFields(text: string): { invoice_number: string; invoice_date: string; total: string } {
  const normalized = westernDigits(cleanOcrText(text)).replace(/٬/g, ",").replace(/٫/g, ".");
  const lines = normalized.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  const datePattern = /\b(20\d{2}[./\-]\d{1,2}[./\-]\d{1,2}|\d{1,2}[./\-]\d{1,2}[./\-](?:20)?\d{2})\b/g;
  const normalizedDate = (raw: string): string => {
    const parts = raw.replace(/[./]/g, "-").split("-").map(Number);
    if (parts.length !== 3 || parts.some((part) => !Number.isFinite(part))) return "";
    let year: number;
    let month: number;
    let day: number;
    if (parts[0] >= 2000) {
      [year, month, day] = parts;
    } else {
      [day, month, year] = parts;
      year = year < 100 ? 2000 + year : year;
    }
    if (year < 2000 || month < 1 || month > 12 || day < 1 || day > 31) return "";
    const candidate = `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    const date = new Date(`${candidate}T00:00:00Z`);
    return Number.isNaN(date.valueOf()) || date.toISOString().slice(0, 10) !== candidate ? "" : candidate;
  };
  const dateCandidates = lines.flatMap((line, lineIndex) =>
    [...line.matchAll(datePattern)].map((match) => ({ raw: match[1], date: normalizedDate(match[1]), line, lineIndex })),
  ).filter((candidate) => candidate.date);
  const dateLabel = /(?:\binvoice\s*date\b|\bdate\s*of\s*invoice\b|\bdate\s*issued\b|\bissued\b|\bdate\b|تاريخ\s*(?:الفاتورة|الاصدار|الإصدار|التحرير|الفاتوره))/i;
  const dateScore = (candidate: typeof dateCandidates[number]): number => {
    let score = 0;
    // A date explicitly labelled on the same line is stronger evidence than a
    // date merely appearing near a heading that OCR may have rearranged.
    if (dateLabel.test(candidate.line)) score += 10;
    // OCR sometimes separates the label and value into adjacent lines.
    if (candidate.lineIndex > 0 && /(?:\binvoice\s*date\b|\bdate\s*of\s*invoice\b|\bdate\b|تاريخ\s*(?:الفاتورة|الاصدار|الإصدار|التحرير|الفاتوره))/i.test(lines[candidate.lineIndex - 1])) score += 5;
    if (candidate.lineIndex > 1 && /(?:\binvoice\s*date\b|\bdate\s*of\s*invoice\b|تاريخ\s*(?:الفاتورة|الاصدار|الإصدار|التحرير|الفاتوره))/i.test(lines[candidate.lineIndex - 2])) score += 2;
    // OCR can produce both "2026-10-17" and "2026-10-7" from the same
    // printed date. Prefer a two-digit day when label/context scores tie, so
    // repeated incomplete readings do not overrule the more complete date.
    const rawParts = candidate.raw.replace(/[./]/g, "-").split("-");
    const rawDay = Number(rawParts[0]) >= 2000 ? rawParts[2] : rawParts[0];
    if (rawDay?.length === 2) score += 2;
    return score;
  };
  const dateCounts = new Map<string, number>();
  for (const candidate of dateCandidates) dateCounts.set(candidate.date, (dateCounts.get(candidate.date) || 0) + 1);
  const selectedDate = [...dateCounts.keys()].sort((left, right) => {
    const leftScore = Math.max(...dateCandidates.filter((candidate) => candidate.date === left).map(dateScore));
    const rightScore = Math.max(...dateCandidates.filter((candidate) => candidate.date === right).map(dateScore));
    if (rightScore !== leftScore) return rightScore - leftScore;
    // Repeated OCR votes break ties only after label/context confidence. A
    // duplicated but incomplete header date must not overrule an explicit
    // footer issue date (e.g. 2026-10-7 vs 2026-10-17).
    const countDelta = (dateCounts.get(right) || 0) - (dateCounts.get(left) || 0);
    if (countDelta) return countDelta;
    return dateCandidates.findIndex((candidate) => candidate.date === left) - dateCandidates.findIndex((candidate) => candidate.date === right);
  })[0] || "";
  const invoiceLabel = /(?:رقم\s*(?:الفاتورة|فاتورة)|invoice\s*(?:number|no\.?|#)|bill\s*(?:number|no\.?|#))/i;
  const invoiceNumberPattern = /\b[A-Z][A-Z0-9]*(?:[-/_][A-Z0-9]+)+\b|\b[A-Z][A-Z0-9]{4,}\b/gi;
  // Match an identifier on a line explicitly labelled as the invoice number,
  // skipping generic heading words such as "INVOICE", "NO", or "SAMPLE".
  // Some bilingual supplier templates print both Arabic and English labels
  // before the actual code on the same line.
  const labelledInvoiceNumber = lines.flatMap((line, index) => {
    if (!invoiceLabel.test(line)) return [];
    // OCR commonly moves the actual value to the next line when Arabic and
    // English labels are interleaved. Search a small local window, not the
    // entire page, so a SKU or amount lower in the invoice is not captured.
    return lines.slice(index, Math.min(lines.length, index + 4))
      .flatMap((candidateLine) => [...candidateLine.matchAll(invoiceNumberPattern)].map((match) => match[0]));
  }).find((candidate) => /\d/.test(candidate) && !/^(?:INVOICE|NUMBER|SAMPLE|TEST|BILL)$/i.test(candidate));
  const fallbackNumberMatch = normalized.match(/(?:رقم\s*(?:الفاتورة|فاتورة)|invoice\s*(?:number|no\.?|#)?|bill\s*(?:number|no\.?|#)?)\s*[:：#\-]?\s*([A-Z0-9][A-Z0-9\/_\-]{2,})/i);
  const invoiceNumber = labelledInvoiceNumber || fallbackNumberMatch?.[1] || "";
  const amountFromLine = (line: string, label: RegExp): string => {
    const match = label.exec(line);
    if (!match || match.index === undefined) return "";
    const afterLabel = line.slice(match.index + match[0].length);
    const values = [...afterLabel.matchAll(/\d[\d,]*(?:\.\d{1,4})?/g)];
    const lastValue = values.at(-1)?.[0]?.replace(/,/g, "") || "";
    return lastValue && Number.isFinite(Number(lastValue)) ? Number(lastValue).toFixed(2) : "";
  };

  // Prefer explicit invoice-level summary labels. Never scan arbitrarily
  // across a table heading, since the next row may start with quantity 1 and
  // be mistaken for the invoice total (the bug seen on internal SI PDFs).
  const explicitTotalLabel = /(?:الإجمالي\s*(?:النهائي|الكلي|المستحق)|الاجمالي\s*(?:النهائي|الكلي|المستحق)|إجمالي\s*(?:الفاتورة|المستحق)|اجمالي\s*(?:الفاتورة|المستحق)|صافي\s*الفاتورة|المبلغ\s*المستحق|grand\s*total|net\s*total|invoice\s*total|total\s*due)/i;
  let cleanedTotal = "";
  for (const line of [...lines].reverse()) {
    cleanedTotal = amountFromLine(line, explicitTotalLabel);
    if (cleanedTotal) break;
  }

  // Generic "Total" is acceptable only when it is a stand-alone summary line;
  // a product table header containing SKU/quantity/unit-price is not a total.
  if (!cleanedTotal) {
    const genericTotalLine = /^\s*(?:الإجمالي|الاجمالي|المجموع|total)\s*[:：#-]?\s*(?!الفرعي|الفرعى|subtotal\b)(.+)$/i;
    for (const line of [...lines].reverse()) {
      if (/(?:\bsku\b|\bbarcode\b|الكمية|سعر\s*الوحدة|unit\s*price|رقم\s*الصنف)/i.test(line)) continue;
      cleanedTotal = amountFromLine(line, genericTotalLine);
      if (cleanedTotal) break;
    }
  }

  const invoiceDate = selectedDate;

  return {
    invoice_number: invoiceNumber.trim(),
    invoice_date: invoiceDate,
    total: cleanedTotal,
  };
}

export function suggestSupplierFields(text: string): SuggestedSupplierFields {
  const normalized = westernDigits(cleanOcrText(text)).replace(/[ \t]+/g, " ");
  const lines = normalized.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);

  const explicitName = lines.map((line) => line.match(/^\s*(?:اسم\s*المورد|المورد|supplier\s*name)\s*[:：-]\s*([^\r\n]{3,180})/i)?.[1]?.trim() || "").find(Boolean) || "";
  const supplierLabelIndex = lines.findIndex((line) => /^(?:اسم\s*المورد|المورد|supplier\s*name)\s*[:：-]?\s*$/i.test(line));
  const nearbyCompanyName = supplierLabelIndex >= 0
    ? lines.slice(supplierLabelIndex + 1, Math.min(lines.length, supplierLabelIndex + 5))
      .find((line) => /(?:^|\s)(?:شركة|مجموعة)\s+[^\r\n]{3,140}/i.test(line) && !/(?:invoice\s*date|تاريخ\s*الفاتورة|supplier\s*code|كود\s*المورد)/i.test(line)) || ""
    : "";
  // Prefer a recognizable legal company name over a nearby marketing line
  // such as "one of the El Araby Group companies".
  const elArabyCompany = normalized.match(/شركة\s*(?:العربي|العريي|العريبي|العربى)\s*للتجارة\s*والتوزيع/i);
  const elArabyGroup = normalized.match(/مجموعة\s*(?:العربي|العريي|العربى)/i);
  const companyName = elArabyCompany ? "شركة العربي للتجارة والتوزيع" : elArabyGroup ? "مجموعة العربي" : "";

  const externalCode = normalized.match(/(?:كود\s*المورد|supplier\s*code)\s*[:：#-]?\s*([A-Z0-9][A-Z0-9/_-]{2,})/i)?.[1]?.trim() || "";
  const phoneLine = lines.find((line) => /(?:هاتف|تليفون|موبايل|phone|tel\.?)/i.test(line));
  const supplierPhone = phoneLine?.match(/(?:هاتف|تليفون|موبايل|phone|tel\.?)\D{0,12}(\+?[\d ()-]{7,20}\d)/i)?.[1]?.replace(/[ ()-]/g, "") || "";
  const supplierTax = normalized.match(/(?:الرقم\s*الضريبي|رقم\s*التسجيل\s*الضريبي|tax\s*(?:id|number)|vat\s*(?:id|number))\s*[:：#-]?\s*([A-Z0-9/-]{5,})/i)?.[1]?.trim() || "";
  const address = (normalized.match(/(?:عنوان\s*المورد|عنوان|address)\s*[:：-]\s*([^\r\n]{5,220})/i)?.[1] || "")
    .replace(/\s+(?:CURRENCY|SUPPLIER\s+CODE|INVOICE\s+DATE|SUPPLIER\s+NAME)\b.*$/i, "")
    .trim();

  return {
    supplier_name: explicitName || companyName || nearbyCompanyName,
    supplier_code: externalCode,
    supplier_phone: supplierPhone,
    supplier_tax_number: supplierTax,
    supplier_address: address,
  };
}

/**
 * Conservative line candidate extraction. Prefer SKU-backed table rows, then
 * fall back to no-SKU Arabic/English invoice rows where quantity × unit price
 * validates against the printed row total.
 */
export function suggestInvoiceLines(text: string): SuggestedInvoiceLine[] {
  const normalized = westernDigits(cleanOcrText(text)).replace(/٬/g, ",").replace(/٫/g, ".");
  const result: SuggestedInvoiceLine[] = [];
  const seen = new Set<string>();
  const lines = normalized.split(/\r?\n/);
  const descriptionsBySku = new Map<string, string>();
  const descriptionsArBySku = new Map<string, string>();

  // Supplier PDFs often print the SKU + product description on one line and
  // repeat the SKU with quantity/cost/total on a separate compact row. Capture
  // the description first so the numeric row can still map to a useful draft.
  for (const [lineIndex, line] of lines.entries()) {
    const skuMatch = line.match(/\b([A-Z]{2,8}[-_][A-Z0-9]{2,}(?:[-_][A-Z0-9]{2,})?)\b/i);
    if (!skuMatch) continue;
    const sku = skuMatch[1].toUpperCase();
    if (/^(?:PS|SI|PI|SUP|TRIP)-/i.test(sku)) continue;

    const tail = line.slice((skuMatch.index ?? 0) + skuMatch[0].length);
    const numericCells = [...tail.matchAll(/\d[\d,]*(?:\.\d{1,4})?/g)];
    if (numericCells.length >= 3) continue;

    const description = tail
      .replace(/[|:：]+/g, " ")
      .replace(/\s+/g, " ")
      .trim();
    if (description.length >= 2 && !descriptionsBySku.has(sku)) {
      descriptionsBySku.set(sku, description);
    }

    // Many Arabic/English supplier invoices put the Arabic product name on a
    // separate line between the description row and the numeric SKU row. Link
    // the nearest such line to this SKU, stopping at the next SKU or summary.
    for (const nearbyLine of lines.slice(lineIndex + 1, Math.min(lines.length, lineIndex + 5))) {
      if (/\b[A-Z]{2,8}[-_][A-Z0-9]{2,}(?:[-_][A-Z0-9]{2,})?\b/i.test(nearbyLine)) break;
      if (/(?:الإجمالي|الاجمالي|invoice\s*total|grand\s*total|subtotal|الضريبة|الخصم)/i.test(nearbyLine)) break;
      if (/^\s*[-–—_=\s]*(?:قراءة\s+(?:جدول\s+)?بديلة|صفحة\s*\d+)\s*[-–—_=\s]*$/u.test(nearbyLine)) continue;
      if (!/[\u0600-\u06FF]/.test(nearbyLine)) continue;
      const arabicLetterCount = (nearbyLine.match(/[\u0621-\u064A]/g) || []).length;
      const latinLetterCount = (nearbyLine.match(/[A-Za-z]/g) || []).length;
      const numericCellCount = (nearbyLine.match(/\d[\d,]*(?:\.\d{1,4})?/g) || []).length;
      // Canonical Arabic catalog names must come from an Arabic-only candidate.
      // Mixed-script OCR such as "مروحة ستاند 18 dog)" is not reliable enough
      // to prefill the Arabic product name; leave it for explicit human review.
      // The SKU-backed English description remains available separately.
      if (arabicLetterCount < 4 || latinLetterCount > 0 || numericCellCount >= 3) continue;
      const arabicDescription = nearbyLine
        .replace(/[|:：]+/g, " ")
        .replace(/\s+/g, " ")
        .trim();
      if (arabicDescription.length >= 3 && !descriptionsArBySku.has(sku)) {
        descriptionsArBySku.set(sku, arabicDescription);
        break;
      }
    }
  }

  for (const line of lines) {
    const skuMatch = line.match(/\b([A-Z]{2,8}[-_][A-Z0-9]{2,}(?:[-_][A-Z0-9]{2,})?)\b/i);
    if (!skuMatch || /(?:رقم\s*الفاتورة|invoice\s*(?:number|no\.?))\s*[:：#-]?\s*$/i.test(line)) continue;
    const sku = skuMatch[1].toUpperCase();
    if (/^(?:PS|SI|PI|SUP|TRIP)-/i.test(sku)) continue;
    if (seen.has(sku)) continue;

    const tail = line.slice((skuMatch.index ?? 0) + skuMatch[0].length);
    const values = [...tail.matchAll(/\d[\d,]*(?:\.\d{1,4})?/g)].map((match) => match[0]);
    if (values.length < 3) continue;

    const quantityRaw = values[values.length - 3];
    const costRaw = values[values.length - 2];
    const totalRaw = values[values.length - 1];
    const quantity = Number(quantityRaw.replace(/,/g, ""));
    if (!Number.isFinite(quantity) || quantity <= 0 || quantity > 1_000_000) continue;

    const normalizeMoney = (value: string): string => {
      const clean = value.replace(/,/g, "");
      // OCR often turns e.g. "2,450.00" into "245000". Do not present that
      // as a valid price because it could silently inflate stock valuation.
      if (!/[.,]/.test(value) && clean.length > 5) return "";
      const amount = Number(clean);
      if (!Number.isFinite(amount) || amount < 0 || amount > 100_000_000) return "";
      return amount.toFixed(2);
    };

    // Remove only the final three numeric cells (quantity, unit cost and line
    // total). Earlier numbers may be part of the product description, such as
    // "Kettle 1.7 Liter" or "Iron 2200 Watt", and must be preserved.
    const descriptionEnd = values.length >= 3
      ? (tail.match(/\d[\d,]*(?:\.\d{1,4})?/g) || []).length >= 3
        ? [...tail.matchAll(/\d[\d,]*(?:\.\d{1,4})?/g)][values.length - 3]?.index ?? tail.length
        : tail.length
      : tail.length;
    const inlineDescription = tail.slice(0, descriptionEnd);
    const description = (inlineDescription
      .replace(/\b(?:قطعة|قطع|وحدة|وحدات|pcs?|units?)\b/gi, " ")
      .replace(/[|:：]+/g, " ")
      .replace(/\s+/g, " ")
      .trim()) || descriptionsBySku.get(sku) || "";
    result.push({
      supplier_sku: sku,
      description: description || descriptionsBySku.get(sku) || "",
      ...(descriptionsArBySku.has(sku) ? { description_ar: descriptionsArBySku.get(sku) } : {}),
      quantity: String(quantity),
      unit_cost: normalizeMoney(costRaw),
      line_total: normalizeMoney(totalRaw),
    });
    seen.add(sku);
  }

  if (result.length) return result.slice(0, 100);

  // Egyptian suppliers frequently use no SKU column at all. Fall back to
  // rows containing a product description and trailing quantity/unit-price/
  // line-total cells. Only accept a row when quantity * unit price equals the
  // printed line total; this catches OCR column/order mistakes early.
  const arabicOrLatinLetters = /[\u0600-\u06FFA-Za-z]/;
  const parseRow = (line: string): SuggestedInvoiceLine | null => {
    const trimmed = line.trim().replace(/[|│]+/g, " ").replace(/\s+/g, " ");
    if (!trimmed || /(?:الإجمالي|الاجمالي|المجموع|الرصيد السابق|الرصيد|الضريبة|الخصم|total|subtotal|balance|previous|date|التاريخ)/i.test(trimmed) || !arabicOrLatinLetters.test(trimmed)) return null;
    const tokens = [...trimmed.matchAll(/\d[\d,٬]*(?:[.٫]\d{1,4})?/g)];
    if (tokens.length < 3) return null;
    // Prefer the final three numeric cells; item descriptions may contain
    // technical numbers such as 2200W or 2 liters before the price columns.
    const triple = tokens.slice(-3);
    const raw = triple.map((token) => token[0]);
    const quantity = Number(raw[0].replace(/[٬,]/g, ""));
    const unitCost = normalizeOcrMoneyToken(raw[1]);
    const lineTotal = normalizeOcrMoneyToken(raw[2]);
    if (!Number.isFinite(quantity) || quantity <= 0 || quantity > 1_000_000 || !unitCost || !lineTotal) return null;
    const expected = quantity * Number(unitCost);
    if (Math.abs(expected - Number(lineTotal)) > Math.max(0.03, Number(lineTotal) * 0.001)) return null;
    const prefix = trimmed.slice(0, triple[0].index).replace(/^[\s\-–—:*#\d.]+/, "").trim();
    const unitMatch = prefix.match(/(?:^|\s|[|│])((?:كرتونة|كرتونه|كرتونات|كرتون|قطعة|قطع|وحدة|وحدات|cartons?|pieces?|pcs?))\s*(?:[|│]|$)/i);
    const unitName = unitMatch?.[1] || "";
    const unitKind: SuggestedInvoiceLine["unit_kind"] = /كرتون|carton/i.test(unitName) ? "carton" : /قطعة|قطع|وحدة|وحدات|piece|pcs?/i.test(unitName) ? "piece" : "unknown";
    const desc = prefix.replace(/[|│]+/g, " ").replace(/\b(?:كرتونة|كرتونه|كرتونات|قطعة|قطع|وحدة|وحدات|كرتون|carton|cartons|piece|pieces|pcs?)\b/gi, " ").replace(/\s+/g, " ").trim();
    if (desc.replace(/\d/g, "").trim().length < 3) return null;
    return { supplier_sku: "", description: desc, quantity: String(quantity), unit_cost: unitCost, line_total: lineTotal, unit_name: unitName, unit_kind: unitKind };
  };

  const fallback: SuggestedInvoiceLine[] = [];
  const seenDescriptions = new Set<string>();
  for (const line of lines) {
    const candidate = parseRow(line);
    if (!candidate) continue;
    const key = candidate.description.toLocaleLowerCase().replace(/\s+/g, " ");
    if (seenDescriptions.has(key)) continue;
    seenDescriptions.add(key);
    fallback.push(candidate);
  }
  return fallback.slice(0, 100);
}

/**
 * Identifies a particularly dangerous import mismatch: an invoice exported by
 * NEXORA's own sales flow (customer + trip + SI document) should not be posted
 * again as supplier purchases. The conservative, multi-signal check avoids
 * flagging an ordinary supplier invoice merely because its number starts SI.
 */
export function isNexoraInternalSalesInvoice(fileName: string, text: string): boolean {
  const normalized = cleanOcrText(westernDigits(text)).toLocaleLowerCase();
  const hasSalesLabel = /فاتورة\s*مبيعات/.test(normalized);
  const hasTripReference = /(?:trip\s*[-#:]?\s*\d{3,}|رحلة\s*التوزيع)/i.test(normalized);
  const hasCustomerReference = /كود\s*العميل|فاتورة\s*إلى|فاتورة\s*الى/.test(normalized);
  const hasInternalOrigin = /nexora\s*distribution|نسخة\s*من\s*النظام\s*الداخلي|نسخة\s*تشغيلية/.test(normalized);
  const hasSalesInvoiceNumber = /^\s*SI\d{3,}(?:\.pdf)?\s*$/i.test(fileName.trim());

  return hasSalesLabel && hasTripReference && hasCustomerReference && (hasInternalOrigin || hasSalesInvoiceNumber);
}
