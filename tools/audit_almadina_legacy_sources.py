"""Read-only preflight audit for local Al Madina PDF/CSV sources.

This script does not import or modify business data. It writes extracted PDF
text, raw numbered-row staging candidates, and an aggregate audit report only
under the Git-ignored legacy-import/ directory. Never print source names,
phone numbers, invoice values, or row data.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
import sqlite3
from collections import Counter
from pathlib import Path

from pypdf import PdfReader


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "legacy-import" / "almadina-source"
TEXT_DIR = OUTPUT / "extracted-text"
CSV_SOURCE = ROOT / "NEXORA_Customers_Test_Import_Candidates.csv"
DB_SOURCE = ROOT / "backend" / "database" / "distribution-local.sqlite"
PDF_NAMES = ("اصناف.pdf", "البضاعة.pdf", "عملا.pdf")
ROW_NUMBER_AT_END = re.compile(r"-\s*[0-9٠-٩]+\s*$")
ROW_NUMBER_CAPTURE = re.compile(r"-\s*([0-9٠-٩]+)\s*$")
PHONE_DIGITS = re.compile(r"\D+")


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def audit_pdf(path: Path) -> dict:
    reader = PdfReader(str(path), strict=False)
    extracted_pages: list[str] = []
    row_count = 0
    per_page_rows: list[int] = []
    pages_with_text = 0
    extracted_chars = 0
    negative_quantity_like_lines = 0
    row_candidates: list[dict[str, str | int]] = []

    for page_number, page in enumerate(reader.pages, start=1):
        text = page.extract_text() or ""
        extracted_pages.append(text)
        if text.strip():
            pages_with_text += 1
        extracted_chars += len(text.strip())
        lines = [line.strip() for line in text.splitlines() if line.strip()]
        numbered_rows = [line for line in lines if ROW_NUMBER_AT_END.search(line)]
        per_page_rows.append(len(numbered_rows))
        row_count += len(numbered_rows)
        for line_number, line in enumerate(lines, start=1):
            match = ROW_NUMBER_CAPTURE.search(line)
            if match:
                row_candidates.append({
                    "source_file": path.name,
                    "source_page": page_number,
                    "source_line_number": line_number,
                    "source_row_number": match.group(1),
                    "raw_text": line,
                    "review_status": "needs_manual_column_mapping",
                })
        negative_quantity_like_lines += sum(
            bool(re.search(r"\(\s*[0-9٠-٩]+(?:[.,٫][0-9٠-٩]+)?\s*\)", line))
            for line in lines
        )

    TEXT_DIR.mkdir(parents=True, exist_ok=True)
    text_path = TEXT_DIR / f"{path.stem}.txt"
    text_path.write_text(
        "\n\n".join(
            f"===== PAGE {index} =====\n{text}"
            for index, text in enumerate(extracted_pages, start=1)
        ),
        encoding="utf-8",
    )
    staging_dir = OUTPUT / "staging-candidates"
    staging_dir.mkdir(parents=True, exist_ok=True)
    staging_path = staging_dir / f"{path.stem}.csv"
    with staging_path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=[
            "source_file", "source_page", "source_line_number", "source_row_number", "raw_text", "review_status"
        ])
        writer.writeheader()
        writer.writerows(row_candidates)

    return {
        "source_file": path.name,
        "source_bytes": path.stat().st_size,
        "source_sha256": sha256(path),
        "pages": len(reader.pages),
        "pages_with_text": pages_with_text,
        "extracted_characters": extracted_chars,
        "probable_numbered_rows": row_count,
        "probable_rows_per_page_min": min(per_page_rows, default=0),
        "probable_rows_per_page_max": max(per_page_rows, default=0),
        "negative_quantity_like_lines": negative_quantity_like_lines,
        "extracted_text_file": str(text_path.relative_to(ROOT)).replace("\\", "/"),
        "raw_row_candidates_csv": str(staging_path.relative_to(ROOT)).replace("\\", "/"),
        "raw_row_candidates_count": len(row_candidates),
        "warning": "Text extraction joins some right-to-left columns; row counts are estimates and require visual/source reconciliation.",
    }


def audit_csv(path: Path) -> dict:
    if not path.exists():
        return {"source_file": path.name, "present": False}

    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        headers = reader.fieldnames or []
        rows = list(reader)

    codes = [row.get("code", "").strip() for row in rows if row.get("code", "").strip()]
    phones = [PHONE_DIGITS.sub("", row.get("phone", "")) for row in rows]
    phone_lengths = Counter(len(phone) for phone in phones if phone)
    statuses = Counter(row.get("import_status", "").strip() for row in rows)
    shared_review = Counter(row.get("shared_phone_review", "").strip() for row in rows)
    code_counts = Counter(codes)
    phone_counts = Counter(phone for phone in phones if phone)

    return {
        "source_file": path.name,
        "present": True,
        "bytes": path.stat().st_size,
        "sha256": sha256(path),
        "rows": len(rows),
        "headers": headers,
        "rows_missing_name_or_phone": sum(
            not row.get("name", "").strip() or not PHONE_DIGITS.sub("", row.get("phone", ""))
            for row in rows
        ),
        "duplicate_code_groups": sum(count > 1 for count in code_counts.values()),
        "duplicate_phone_groups": sum(count > 1 for count in phone_counts.values()),
        "phone_digit_length_counts": {str(key): value for key, value in sorted(phone_lengths.items())},
        "import_status_counts": dict(statuses),
        "shared_phone_review_counts": dict(shared_review),
        "warning": "Contact candidates are not proof of customer status; review shared/ambiguous phones before any messaging or invoice delivery.",
    }


def audit_local_database(path: Path) -> dict:
    if not path.exists():
        return {"database_file": str(path.relative_to(ROOT)), "present": False}

    before_hash = sha256(path)
    uri = f"file:{path.resolve().as_posix()}?mode=ro"
    counts: dict[str, int | str] = {}
    with sqlite3.connect(uri, uri=True) as connection:
        integrity = connection.execute("PRAGMA integrity_check").fetchone()[0]
        for table in (
            "organizations", "customers", "products", "suppliers",
            "sales_invoices", "purchase_invoices", "stock_movements", "payments",
        ):
            try:
                counts[table] = connection.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
            except sqlite3.Error:
                counts[table] = "table_missing"

    after_hash = sha256(path)
    return {
        "database_file": str(path.relative_to(ROOT)).replace("\\", "/"),
        "sha256_before": before_hash,
        "sha256_after": after_hash,
        "unchanged_during_audit": before_hash == after_hash,
        "integrity_check": integrity,
        "table_counts": counts,
        "read_only": True,
    }


def main() -> int:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    pdf_results = []
    missing_pdfs = []
    for name in PDF_NAMES:
        source = ROOT / name
        if source.exists():
            pdf_results.append(audit_pdf(source))
        else:
            missing_pdfs.append(name)

    report = {
        "audit_version": 1,
        "mode": "READ_ONLY_SOURCE_AUDIT_NO_IMPORT",
        "pdf_sources": pdf_results,
        "missing_pdf_sources": missing_pdfs,
        "contact_csv": audit_csv(CSV_SOURCE),
        "local_database": audit_local_database(DB_SOURCE),
        "next_gate": "Create structured staging CSVs, validate column mappings and quantities, compare against the target tenant, then request owner approval before any import.",
    }
    report_path = OUTPUT / "audit-report.json"
    report_path.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    print(f"AUDIT_REPORT={report_path.relative_to(ROOT)}")
    print(f"MODE={report['mode']}")
    for pdf in pdf_results:
        print(
            f"PDF={pdf['source_file']} pages={pdf['pages']} text_pages={pdf['pages_with_text']} "
            f"probable_rows={pdf['probable_numbered_rows']} chars={pdf['extracted_characters']}"
        )
    csv_report = report["contact_csv"]
    if csv_report.get("present"):
        print(
            f"CONTACT_CSV rows={csv_report['rows']} missing_name_or_phone={csv_report['rows_missing_name_or_phone']} "
            f"duplicate_code_groups={csv_report['duplicate_code_groups']} "
            f"duplicate_phone_groups={csv_report['duplicate_phone_groups']}"
        )
    db_report = report["local_database"]
    print(
        f"LOCAL_DB integrity={db_report.get('integrity_check')} "
        f"unchanged={db_report.get('unchanged_during_audit')}"
    )
    print("IMPORT_EXECUTED=False")
    return 0 if db_report.get("integrity_check") == "ok" and db_report.get("unchanged_during_audit") else 2


if __name__ == "__main__":
    raise SystemExit(main())
