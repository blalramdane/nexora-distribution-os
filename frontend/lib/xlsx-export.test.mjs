import test from "node:test";
import assert from "node:assert/strict";
import { createXlsxWorkbook } from "./xlsx-export.mjs";

function zipEntries(bytes) {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  const decoder = new TextDecoder();
  let offset = 0;
  const entries = new Map();
  while (offset < bytes.length - 22) {
    const signature = view.getUint32(offset, true);
    if (signature === 0x02014b50) break;
    assert.equal(signature, 0x04034b50, "valid local ZIP header");
    const size = view.getUint32(offset + 18, true);
    const nameLength = view.getUint16(offset + 26, true);
    const extraLength = view.getUint16(offset + 28, true);
    const nameStart = offset + 30;
    const contentStart = nameStart + nameLength + extraLength;
    const name = decoder.decode(bytes.subarray(nameStart, nameStart + nameLength));
    entries.set(name, decoder.decode(bytes.subarray(contentStart, contentStart + size)));
    offset = contentStart + size;
  }
  return entries;
}

test("creates a valid OOXML package with multiple Arabic RTL sheets", () => {
  const bytes = createXlsxWorkbook([
    { name: "ملخص الرحلة", rows: [["البند", "القيمة"], ["إجمالي البيع", 1250.5]] },
    { name: "فواتير البيع", rows: [["الفاتورة", "العميل"], ["INV-1", "أحمد & أولاده"]] },
  ]);
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
  assert.equal(view.getUint32(0, true), 0x04034b50);
  assert.equal(view.getUint32(bytes.length - 22, true), 0x06054b50);
  const entries = zipEntries(bytes);
  assert.ok(entries.has("[Content_Types].xml"));
  assert.ok(entries.has("xl/worksheets/sheet1.xml"));
  assert.ok(entries.has("xl/worksheets/sheet2.xml"));
  assert.match(entries.get("xl/workbook.xml"), /ملخص الرحلة/);
  assert.match(entries.get("xl/worksheets/sheet1.xml"), /rightToLeft="1"/);
  assert.match(entries.get("xl/worksheets/sheet1.xml"), /<v>1250\.5<\/v>/);
  assert.match(entries.get("xl/worksheets/sheet2.xml"), /أحمد &amp; أولاده/);
});

test("keeps user-provided text as text and rejects invalid worksheet names", () => {
  const workbook = createXlsxWorkbook([{ name: "البيانات", rows: [["=2+2", "نص"]] }]);
  const entries = zipEntries(workbook);
  const xml = entries.get("xl/worksheets/sheet1.xml");
  assert.match(xml, /t="inlineStr"/);
  assert.match(xml, /=2\+2/);
  assert.throws(() => createXlsxWorkbook([{ name: "invalid/name", rows: [] }]), /Invalid worksheet name/);
  assert.throws(() => createXlsxWorkbook([{ name: "مكرر", rows: [] }, { name: "مكرر", rows: [] }]), /Duplicate worksheet name/);
});
