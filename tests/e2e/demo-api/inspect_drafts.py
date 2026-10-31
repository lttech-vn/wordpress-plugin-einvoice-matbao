"""Prints how Mat Bao renders existing demo drafts (read-only: lookup, invoice-detail, download).

    EINV_DEMO_MST=... EINV_DEMO_USER=... EINV_DEMO_PASS=... \
        uv run --with pymupdf python -I tests/e2e/demo-api/inspect_drafts.py --series C26TAT REF1 REF2

For each `MTChieu` it prints the lookup rows, the `nMua_*` fields and totals from
`invoice-detail`, and the buyer block, item table and amount in words read from the PDF.
Tax codes and 12-digit numbers are masked in the output. Nothing is created, signed or deleted.
"""

from __future__ import annotations

import argparse
import base64
import datetime
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from matbao_demo import DemoClient  # noqa: E402

FORM = "1"
NUMBER_MASK = re.compile(r"\b\d{10}(?:-\d{3})?\b|\b\d{12}\b")


def mask(text: str) -> str:
    return NUMBER_MASK.sub("***", text)


def section(text: str, start: str, end: str) -> str:
    a = text.find(start)
    z = text.find(end, a + 1)
    return text[a:z] if a >= 0 and z > a else ""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--series", default="C26TAT")
    parser.add_argument("references", nargs="+")
    args = parser.parse_args()
    import pymupdf  # Imported late so the guard self-test does not need it.

    client = DemoClient()
    for reference in args.references:
        status, body = client.lookup([reference], FORM, args.series)
        invoices = (body.get("data") or {}).get("invoices") or []
        print(f"== {reference}: lookup HTTP {status} errorCode {body.get('errorCode')} rows {len(invoices)}")
        for invoice in invoices:
            day = (invoice.get("nLap") or "")[:10] or datetime.date.today().isoformat()
            _, detail = client.detail({"KHMSHDon": FORM, "KHHDon": args.series, "TNLap": day, "DNLap": day, "LoaiHDon": 1, "TThaiHDon": 1, "MaSoHDon": invoice["maSoHDon"], "Trang": 1})
            rows = detail.get("data") if isinstance(detail.get("data"), list) else []
            if rows:
                row = rows[0]
                buyer = {k: mask(str(v)) for k, v in row.items() if k.startswith("nMua_") and v not in (None, "")}
                print("   detail buyer:", buyer, "totals:", row.get("tgThTien"), row.get("tgTThue"), row.get("tgTTTBSo"), "words:", row.get("tgTTTBChu"))
                for line in row.get("dshhdVu") or []:
                    print("   detail line:", {k: line.get(k) for k in ("sLuong", "dGia", "thTienChuaCK", "tlcKhau", "stcKhau", "thTien", "tSuat", "tThue", "tgTien")})
            _, download = client.call("POST", "/api/invoice/download-invoice", {"MaTraCuu": invoice.get("maTraCuu"), "MaSoHDon": invoice.get("maSoHDon")})
            pdf_b64 = (download.get("data") or {}).get("data_PDF_Base64") if isinstance(download.get("data"), dict) else None
            if not pdf_b64:
                print("   pdf: not available", download.get("errorCode"))
                continue
            text = pymupdf.open(stream=base64.b64decode(pdf_b64), filetype="pdf")[0].get_text()
            print("   pdf buyer block:", mask(section(text, "Họ tên người mua", "Hình thức thanh toán")).replace("\n", " | "))
            print("   pdf items:", mask(section(text, "Số (No.)", "Người mua hàng (Buyer)")).replace("\n", " | ")[:400])
            print("   pdf words:", section(text, "BẰNG CHỮ", "Tra cứu").replace("\n", " | ")[:200])


if __name__ == "__main__":
    main()
