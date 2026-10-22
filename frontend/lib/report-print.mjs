function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

function renderCell(value) {
  if (typeof value === "number" && Number.isFinite(value)) {
    return escapeHtml(value.toLocaleString("en-US", { maximumFractionDigits: 4 }));
  }
  return escapeHtml(value ?? "—");
}

/** Build a self-contained, escaped RTL HTML document for client-side PDF rendering. */
export function buildReportPrintHtml({ title, subtitle, summaryRows = [], tables = [], notes = [] }) {
  const summary = summaryRows.map(([label, value]) =>
    `<tr><th>${escapeHtml(label)}</th><td>${renderCell(value)}</td></tr>`,
  ).join("");
  const tablesHtml = tables.map((table) => {
    const headings = table.headers.map((header) => `<th>${escapeHtml(header)}</th>`).join("");
    const rows = table.rows.length
      ? table.rows.map((row) => `<tr>${row.map((cell) => `<td>${renderCell(cell)}</td>`).join("")}</tr>`).join("")
      : `<tr><td colspan="${Math.max(1, table.headers.length)}" class="empty">لا توجد بيانات مسجلة.</td></tr>`;
    return `<section class="section"><h2>${escapeHtml(table.title)}</h2><table><thead><tr>${headings}</tr></thead><tbody>${rows}</tbody></table></section>`;
  }).join("");
  const notesHtml = notes.length
    ? `<section class="notes"><h2>ملاحظات ومراجعة</h2><ul>${notes.map((note) => `<li>${escapeHtml(note)}</li>`).join("")}</ul></section>`
    : "";

  return `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>
    *{box-sizing:border-box}body{font-family:Tahoma,Arial,sans-serif;color:#142238;background:#fff;font-size:10px;line-height:1.6;margin:0;direction:rtl}
    h1{font-size:21px;margin:0 0 5px;color:#17365d}h2{font-size:13px;margin:16px 0 7px;color:#17365d;border-bottom:1px solid #d8e0ec;padding-bottom:5px}
    .header{border-bottom:3px solid #17365d;padding:8px 0 12px;margin-bottom:10px}.subtitle{font-size:10px;color:#52627a}.generated{font-size:9px;color:#64748b;margin-top:6px}
    table{border-collapse:collapse;width:100%;table-layout:auto;margin:0 0 8px}th,td{border:1px solid #d7deea;padding:5px 6px;text-align:right;vertical-align:top;overflow-wrap:anywhere}
    thead th{background:#17365d;color:#fff;font-weight:bold}tbody tr:nth-child(even){background:#f5f7fa}.summary th{width:34%;background:#eef2f8;color:#243b58}
    .section{break-inside:avoid;margin-top:8px}.notes{border:1px solid #d7deea;padding:8px 12px;margin-top:14px}.notes h2{margin-top:0}.empty{text-align:center;color:#64748b}
    @page{size:A4 landscape;margin:10mm}
  </style></head><body><header class="header"><h1>${escapeHtml(title)}</h1><div class="subtitle">${escapeHtml(subtitle)}</div><div class="generated">تاريخ إنشاء التقرير: ${escapeHtml(new Date().toLocaleString("ar-EG"))}</div></header>
  ${summaryRows.length ? `<section class="section"><h2>الملخص</h2><table class="summary"><tbody>${summary}</tbody></table></section>` : ""}
  ${tablesHtml}${notesHtml}</body></html>`;
}

