import assert from "node:assert/strict";
import { test } from "node:test";
import { isNexoraInternalSalesInvoice, suggestInvoiceFields, suggestInvoiceLines, suggestSupplierFields } from "./invoice-ocr.ts";

test("prefers the explicit invoice total instead of the total column and first quantity", () => {
  const text = [
    "Invoice Number: INV-0081",
    "Date: 2026-10-17",
    "# SKU quantity unit price total",
    "1 Product A 1 10.00 10.00",
    "2 Product B 2 25.00 50.00",
    "Subtotal: 60.00 EGP",
    "Grand Total: 60.00 EGP",
  ].join("\n");

  assert.deepEqual(suggestInvoiceFields(text), {
    invoice_number: "INV-0081",
    invoice_date: "2026-10-17",
    total: "60.00",
  });
});

test("does not invent a total from the first row when OCR only sees a table heading", () => {
  const text = [
    "فاتورة مشتريات",
    "# SKU الكمية سعر الوحدة الإجمالي",
    "1 صنف تجريبي 1 50.00 50.00",
    "2 صنف آخر 2 25.00 50.00",
  ].join("\n");

  assert.equal(suggestInvoiceFields(text).total, "");
});

test("strips PDF directional marks while extracting the invoice number", () => {
  const text = "رقم الفاتورة: \u200eSI100041\u200f\nالتاريخ: 17-10-2026\nالإجمالي النهائي: 860.00 EGP";
  assert.deepEqual(suggestInvoiceFields(text), {
    invoice_number: "SI100041",
    invoice_date: "2026-10-17",
    total: "860.00",
  });
});

test("detects a NEXORA internal sales invoice so it cannot be re-posted as a purchase", () => {
  const text = [
    "فاتورة مبيعات — نسخة من النظام الداخلي",
    "NEXORA Distribution",
    "رحلة التوزيع: TRIP-000041",
    "فاتورة إلى العميل",
    "كود العميل: 005-17191914",
  ].join("\n");
  assert.equal(isNexoraInternalSalesInvoice("SI000041.pdf", text), true);
});

test("does not classify an ordinary supplier purchase invoice as an internal sales invoice", () => {
  const text = "فاتورة مشتريات\nرقم الفاتورة: SI-0041\nالمورد: شركة الأجهزة\nالإجمالي النهائي: 900.00";
  assert.equal(isNexoraInternalSalesInvoice("SI-0041.pdf", text), false);
});

test("recognizes the supplier's legal name and external reference from a supplier invoice header", () => {
  const text = [
    "ELARABY",
    "أحد شركات مجموعة العربي",
    "شركة العريي للتجارة والتوزيع",
    "كود المورد : 508-0001",
    "فاتورة مشتريات تاريخ الفاتورة : 2024/10/15",
  ].join("\n");
  assert.deepEqual(suggestSupplierFields(text), {
    supplier_name: "شركة العربي للتجارة والتوزيع",
    supplier_code: "508-0001",
    supplier_phone: "",
    supplier_tax_number: "",
    supplier_address: "",
  });
});

test("finds a supplier company name shortly after a standalone supplier label and ignores the intervening date line", () => {
  const text = [
    "INVOICE DATE SUPPLIER NAME",
    "اسم المورد",
    "Le on Fs 2026-10-17",
    "شركة 931( للأجهزة المنزلية",
    "العنوان: شارع التجارة. المنطقة الصناعية CURRENCY",
    "Supplier Code: SUP-TEST-008",
  ].join("\n");
  assert.deepEqual(suggestSupplierFields(text), {
    supplier_name: "شركة 931( للأجهزة المنزلية",
    supplier_code: "SUP-TEST-008",
    supplier_phone: "",
    supplier_tax_number: "",
    supplier_address: "شارع التجارة. المنطقة الصناعية",
  });
});

test("suggests SKU rows and leaves malformed OCR prices blank instead of inventing amounts", () => {
  const text = [
    "# SKU الكمية سعر الوحدة الإجمالي",
    "ELE-REF-001 | 1 وصف تجريبي قطعة 2 2450000 4900000",
    "ELE-WAS-002 2 وصف آخر قطعة 3 12,800.00 38,400.00",
  ].join("\n");
  assert.deepEqual(suggestInvoiceLines(text).map(({ supplier_sku, quantity, unit_cost, line_total }) => ({ supplier_sku, quantity, unit_cost, line_total })), [
    { supplier_sku: "ELE-REF-001", quantity: "2", unit_cost: "", line_total: "" },
    { supplier_sku: "ELE-WAS-002", quantity: "3", unit_cost: "12800.00", line_total: "38400.00" },
  ]);
});

test("recognizes the real invoice number and expected line prices from the NEXORA test invoice OCR", () => {
  const text = [
    "NOUR HOME APPLIANCES",
    "SUPPLIER PURCHASE INVOICE SAMPLE / TEST ONLY",
    "رقم الفاتورة INVOICE NO. فاتورة اختبار أصناف جديدة TEST-PO-2026-001",
    "INVOICE DATE SUPPLIER NAME",
    "اسم المورد شركة الأفق للأجهزة المنزلية 2026-10-7",
    "--- قراءة بديلة ---",
    "و ‎wt eye‏ .. 2026-10-7",
    "Issued: 2026-10-17",
    "Issued: 2026-10-7",
    "ELE-FAN-18 Stand Fan 18 inch",
    "1 8 8 2 1,250.00 2,500.00",
    "ELE-FAN-18 | 2 | 1,250.00 | 2,500.00",
    "ELK-KET-17 Electric Kettle 1.7 Liter",
    "2 ae 5 3 200 1,260.00",
    "ELK-KET-17 | 3 | 420.00 | 1,260.00",
    "EIR-DRY-22 Steam Iron 2200 Watt",
    "3 1 890.00 890.00",
    "EIR-DRY-22 | 1 | 890.00 | 890.00",
    "Invoice Total: EGP 4,650.00",
    "الإجمالي النهائي: 4,650.00 جنيه",
  ].join("\n");

  assert.deepEqual(suggestInvoiceFields(text), {
    invoice_number: "TEST-PO-2026-001",
    invoice_date: "2026-10-17",
    total: "4650.00",
  });
  assert.deepEqual(suggestInvoiceLines(text).map(({ supplier_sku, description, quantity, unit_cost, line_total }) => ({ supplier_sku, description, quantity, unit_cost, line_total })), [
    { supplier_sku: "ELE-FAN-18", description: "Stand Fan 18 inch", quantity: "2", unit_cost: "1250.00", line_total: "2500.00" },
    { supplier_sku: "ELK-KET-17", description: "Electric Kettle 1.7 Liter", quantity: "3", unit_cost: "420.00", line_total: "1260.00" },
    { supplier_sku: "EIR-DRY-22", description: "Steam Iron 2200 Watt", quantity: "1", unit_cost: "890.00", line_total: "890.00" },
  ]);
});

test("links Arabic product names to their SKU when OCR places the invoice number on the next line", () => {
  const text = [
    "NOUR HOME APPLIANCES",
    "رقم الفاتورة INVOICE NO.",
    "فاتورة اختبار أصناف جديدة TEST-P0O-2026-001",
    "ELE-FAN-18 Stand Fan 18 inch",
    "2,500.00 1,0 2 : : 1",
    "مروحة ستاند 18 بوصة",
    "ELE-FAN-18 | 2 | 1,250.00 | 2,500.00",
    "ELK-KET-17 Electric Kettle 1.7 Liter",
    "1,260.00 420.00 3 : ع 2",
    "غلاية كهربائية 1.7 لتر",
    "ELK-KET-17 | 3 | 420.00 | 1,260.00",
    "EIR-DRY-22 Steam Iron 2200 Watt",
    "890.00 890.00 1",
    "مكواة بخار 2200 وات",
    "EIR-DRY-22 | 1 | 890.00 | 890.00",
    "الإجمالي النهائي: 4,650.00 جنيه",
  ].join("\n");

  assert.equal(suggestInvoiceFields(text).invoice_number, "TEST-P0O-2026-001");
  assert.deepEqual(suggestInvoiceLines(text).map(({ supplier_sku, description, description_ar, quantity, unit_cost, line_total }) => ({ supplier_sku, description, description_ar, quantity, unit_cost, line_total })), [
    { supplier_sku: "ELE-FAN-18", description: "Stand Fan 18 inch", description_ar: "مروحة ستاند 18 بوصة", quantity: "2", unit_cost: "1250.00", line_total: "2500.00" },
    { supplier_sku: "ELK-KET-17", description: "Electric Kettle 1.7 Liter", description_ar: "غلاية كهربائية 1.7 لتر", quantity: "3", unit_cost: "420.00", line_total: "1260.00" },
    { supplier_sku: "EIR-DRY-22", description: "Steam Iron 2200 Watt", description_ar: "مكواة بخار 2200 وات", quantity: "1", unit_cost: "890.00", line_total: "890.00" },
  ]);
});

test("does not prefill a canonical Arabic product name from mixed-script OCR noise", () => {
  const text = [
    "ELE-FAN-18 Stand Fan 18 inch",
    "--- قراءة بديلة ---",
    "مروحة ستاند 18 dog)",
    "ELE-FAN-18 | 2 | 1250.00 | 2500.00",
  ].join("\n");

  const [line] = suggestInvoiceLines(text);
  assert.deepEqual({ supplier_sku: line.supplier_sku, description: line.description, quantity: line.quantity, unit_cost: line.unit_cost, line_total: line.line_total }, {
    supplier_sku: "ELE-FAN-18", description: "Stand Fan 18 inch", quantity: "2", unit_cost: "1250.00", line_total: "2500.00",
  });
  assert.equal(line.description_ar, undefined);
});

test("extracts Arabic supplier invoice lines that have no SKU column", () => {
  const text = [
    "رشيا رويال لاند للاستيراد والتصدير",
    "بيان اسعار رقم (508)",
    "السيد : رمضان يونس",
    "م | الصنف | الوحدة | الكمية | السعر | القيمة",
    "1 * كاتل ديجيتال الماني 2 لتر حور 2200 وات | كرتونة | 10.00 | 3100.00 | 31000.00",
    "2 مكنسة برميل سوكاني | قطعة | 20.00 | 2500.00 | 50000.00",
    "3 كنكة قهوة استانلس 20 | كرتونة | 3.00 | 3800.00 | 11400.00",
    "4 كنكة قهوة بلاستيك حور 800 وات | كرتونة | 3.00 | 2160.00 | 6480.00",
    "الإجمالي 98880.00",
    "رصيد سابق 372545.00",
    "الإجمالي 471425.00",
  ].join("\n");

  assert.deepEqual(suggestInvoiceLines(text).map(({ description, quantity, unit_cost, line_total }) => ({ description, quantity, unit_cost, line_total })), [
    { description: "كاتل ديجيتال الماني 2 لتر حور 2200 وات كرتونة", quantity: "10", unit_cost: "3100.00", line_total: "31000.00" },
    { description: "مكنسة برميل سوكاني قطعة", quantity: "20", unit_cost: "2500.00", line_total: "50000.00" },
    { description: "كنكة قهوة استانلس 20 كرتونة", quantity: "3", unit_cost: "3800.00", line_total: "11400.00" },
    { description: "كنكة قهوة بلاستيك حور 800 وات كرتونة", quantity: "3", unit_cost: "2160.00", line_total: "6480.00" },
  ]);
});

test("does not turn ambiguous OCR lines into invoice products", () => {
  const text = [
    "م | الصنف | الوحدة | الكمية | السعر | القيمة",
    "1 مكنسة برميل سوكاني 20 قطعة 245000 5000000",
    "الإجمالي 98880",
  ].join("\n");
  assert.deepEqual(suggestInvoiceLines(text), []);
});
