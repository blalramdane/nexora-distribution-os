import test from "node:test";
import assert from "node:assert/strict";
import { buildReportPrintHtml } from "./report-print.mjs";

test("builds an Arabic RTL printable report and escapes untrusted cell content", () => {
  const html = buildReportPrintHtml({
    title: "تقرير رحلة",
    subtitle: "العربية 1",
    summaryRows: [["إجمالي المبيعات", 1250.5]],
    tables: [{ title: "العملاء", headers: ["الاسم", "القيمة"], rows: [["<script>alert(1)</script>", "أحمد & أولاده"]] }],
    notes: ["التقرير للعرض والمراجعة فقط."],
  });
  assert.match(html, /dir="rtl"/);
  assert.match(html, /إجمالي المبيعات/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<script>alert\(1\)<\/script>/);
  assert.match(html, /أحمد &amp; أولاده/);
});
