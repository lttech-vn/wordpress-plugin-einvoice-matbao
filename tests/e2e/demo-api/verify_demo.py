"""Issue #15 checks against the Mat Bao demo API (drafts only, never signed or issued).

Usage (from the repo root; credentials only through the environment):

    EINV_DEMO_E2E=1 EINV_DEMO_MST=... EINV_DEMO_USER=... EINV_DEMO_PASS=... \
        uv run python -I tests/e2e/demo-api/verify_demo.py --out /tmp/einv-demo-verify.json

Every run creates new drafts under fresh `MTChieu` values (prefix `E2E` + time). No create-invoice
POST is retried; the concurrency case fires its parallel POSTs exactly once each. The output JSON
is sanitized: tokens and download URLs are masked and the demo account values are replaced by ***.
"""

from __future__ import annotations

import argparse
import concurrent.futures
import datetime
import json
import os
import sys
import threading
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))
from matbao_demo import DemoClient  # noqa: E402

VN = datetime.timezone(datetime.timedelta(hours=7))
FORM = "1"
SERIES = "C26TAT"  # VAT template of the demo account with numbers left.
OTHER_SERIES = "C26TKK"  # Second VAT template with numbers left, for the cross-template case.
EXHAUSTED_SERIES = "C26TMA"  # VAT template listed with cLai 0.
UNKNOWN_SERIES = "C26ZZZ"
WALK_IN_NAME = "Khách hàng không lấy hóa đơn"


def secrets() -> list[str]:
    return [v for v in (os.environ.get("EINV_DEMO_MST"), os.environ.get("EINV_DEMO_USER"), os.environ.get("EINV_DEMO_PASS")) if v]


def scrub(value: Any) -> Any:
    if isinstance(value, dict):
        return {k: scrub(v) for k, v in value.items()}
    if isinstance(value, list):
        return [scrub(v) for v in value]
    if isinstance(value, str):
        for secret in secrets():
            value = value.replace(secret, "***")
    return value


def line(dgia: float, qty: float, net: int, rate: int = 10, before_discount: int | None = None, pct: float = 0, discount: int = 0, name: str = "Dịch vụ thử nghiệm E2E #15") -> dict[str, Any]:
    tax = round(net * rate / 100) if rate > 0 else 0
    return {"TChat": 1, "STT": 1, "THHDVu": name, "DVTinh": "Gói", "SLuong": qty, "DGia": dgia, "ThTienChuaCK": net if before_discount is None else before_discount, "TLCKhau": pct, "STCKhau": discount, "ThTien": net, "TSuat": rate, "TThue": tax, "TgTien": net + tax}


def payload(reference: str, lines: list[dict[str, Any]], words: str, buyer: dict[str, str] | None = None, series: str = SERIES) -> dict[str, Any]:
    net = sum(x["ThTien"] for x in lines)
    tax = sum(x["TThue"] for x in lines)
    now = datetime.datetime.now(VN)
    body = dict(buyer or {"NMua_Ten": WALK_IN_NAME})
    body.update({"KHMSHDon": FORM, "KHHDon": series, "NLap": now.strftime("%Y-%m-%dT00:00:00"), "LoaiHDon": 0, "KTraMTChieuTrung": 1, "TCHDon": 0, "LoaiTraHang": 0, "DVTTe": 704, "TGia": 1, "HTTToan": "CK", "MTChieu": reference, "GChu": "E2E #15 - chi tao nhap, khong phat hanh", "DSHHDVu": lines, "TTCKTMai": 0, "TGTKhac": 0, "TgThTien": net, "TgTThue": tax, "TgTTTBSo": net + tax, "TgTTTBChu": words})
    return body


def item_summary(status: int, response: Any) -> dict[str, Any]:
    out: dict[str, Any] = {"http": status}
    if not isinstance(response, dict):
        return out
    out.update({k: response.get(k) for k in ("success", "errorCode", "message", "errors", "title") if k in response})
    data = response.get("data")
    if isinstance(data, list) and data and isinstance(data[0], dict):
        item = data[0]
        out["item"] = {k: item.get(k) for k in ("success", "errorCode", "message")}
        created = item.get("data") if isinstance(item.get("data"), dict) else {}
        out["item"]["created"] = {k: created.get(k) for k in ("khmshDon", "khhDon", "mtChieu", "maTraCuu", "shDon", "nLap") if k in created}
        out["item"]["has_maSoHDon"] = bool(created.get("maSoHDon"))
    return out


def lookup_summary(client: DemoClient, reference: str, series: str = SERIES) -> dict[str, Any]:
    status, body = client.lookup([reference], FORM, series)
    data = body.get("data") if isinstance(body, dict) else None
    invoices = data.get("invoices", []) if isinstance(data, dict) else []
    return {"http": status, "errorCode": body.get("errorCode"), "count": len(invoices), "notFound": (data or {}).get("notFound") if isinstance(data, dict) else None, "invoices": [{k: x.get(k) for k in ("khhDon", "mtChieu", "maTraCuu", "maTThaiHDon", "tenTThaiHDon", "nLap", "shDon")} | {"keys_include_buyer": any(k.startswith("nMua_") for k in x), "keys_include_total": "tgTTTBSo" in x} for x in invoices], "_ids": [x.get("maSoHDon") for x in invoices]}


def detail_of(client: DemoClient, invoice_id: str, series: str = SERIES) -> dict[str, Any]:
    today = datetime.datetime.now(VN).strftime("%Y-%m-%d")
    status, body = client.detail({"KHMSHDon": FORM, "KHHDon": series, "TNLap": today, "DNLap": today, "LoaiHDon": 1, "TThaiHDon": 1, "MaSoHDon": invoice_id, "Trang": 1})
    rows = body.get("data") if isinstance(body, dict) and isinstance(body.get("data"), list) else []
    if not rows:
        return {"http": status, "errorCode": body.get("errorCode") if isinstance(body, dict) else None, "found": False}
    row = rows[0]
    keep = ("mtChieu", "maTraCuu", "nLap", "httToan", "tgThTien", "tgTThue", "tgTTTBSo", "tgTTTBChu", "tenTThaiHDon")
    out = {"http": status, "found": True} | {k: row.get(k) for k in keep}
    out["buyer"] = {k: row.get(k) for k in row if k.startswith("nMua_") and row.get(k) not in (None, "")}
    out["lines"] = [{k: x.get(k) for k in ("sLuong", "dGia", "thTienChuaCK", "tlcKhau", "stcKhau", "thTien", "tSuat", "tThue", "tgTien")} for x in row.get("dshhdVu") or []]
    return out


def run(out_path: Path) -> dict[str, Any]:
    client = DemoClient()
    login = client.login()
    stamp = datetime.datetime.now(VN).strftime("%m%d%H%M%S")
    ref = lambda tag: ("E2E" + stamp + tag)[:20]  # noqa: E731
    results: dict[str, Any] = {"run_at_vn": datetime.datetime.now(VN).isoformat(timespec="seconds"), "base_url": client.base_url, "template": f"{FORM}/{SERIES}"}

    def save() -> None:
        out_path.write_text(json.dumps(scrub(results), ensure_ascii=False, indent=2))

    # (d) expiredDate time zone.
    utc_now = datetime.datetime.now(datetime.timezone.utc)
    expired = datetime.datetime.fromisoformat(login["expiredDate"]) if login.get("expiredDate") else None
    results["expired_date"] = {"login_keys": login["keys"], "expiredDate": login.get("expiredDate"), "utc_now": utc_now.isoformat(timespec="seconds"), "hours_ahead_if_vn": round(((expired.replace(tzinfo=VN) - utc_now).total_seconds() / 3600), 2) if expired else None, "hours_ahead_if_utc": round(((expired.replace(tzinfo=datetime.timezone.utc) - utc_now).total_seconds() / 3600), 2) if expired else None}
    save()

    # (a) concurrency: N parallel creates with the same MTChieu under one template.
    concurrency_ref = ref("C")
    body = payload(concurrency_ref, [line(100000, 1, 100000)], "Một trăm mười nghìn đồng")
    barrier = threading.Barrier(5)

    def fire(_: int) -> dict[str, Any]:
        barrier.wait()
        started = datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="milliseconds")
        return {"started_utc": started} | item_summary(*client.create_once(body))

    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        parallel = list(pool.map(fire, range(5)))
    results["a_concurrent_same_template"] = {"reference": concurrency_ref, "parallel_creates": parallel, "lookup": lookup_summary(client, concurrency_ref)}
    save()

    # (a) sequential duplicate after the parallel burst, same template.
    results["a_sequential_duplicate"] = item_summary(*client.create_once(body))
    save()

    # (a) the same MTChieu under another template.
    other = payload(concurrency_ref, [line(100000, 1, 100000)], "Một trăm mười nghìn đồng", series=OTHER_SERIES)
    results["a_cross_template"] = {"create": item_summary(*client.create_once(other)), "lookup_other_template": lookup_summary(client, concurrency_ref, OTHER_SERIES), "lookup_first_template": lookup_summary(client, concurrency_ref)}
    save()

    # (b) walk-in buyer: only NMua_Ten with the plugin default name.
    walk_in_ref = ref("W")
    created = item_summary(*client.create_once(payload(walk_in_ref, [line(100000, 1, 100000)], "Một trăm mười nghìn đồng")))
    found = lookup_summary(client, walk_in_ref)
    results["b_walk_in"] = {"reference": walk_in_ref, "create": created, "lookup": found, "detail": detail_of(client, found["_ids"][0]) if found["_ids"] else None}
    save()

    # (c) discount, plugin shape: price after discount, ThTienChuaCK = ThTien, TLCKhau = STCKhau = 0.
    plugin_ref = ref("P")
    created = item_summary(*client.create_once(payload(plugin_ref, [line(180000, 3, 540000)], "Năm trăm chín mươi tư nghìn đồng")))
    found = lookup_summary(client, plugin_ref)
    results["c_discount_plugin_shape"] = {"reference": plugin_ref, "create": created, "detail": detail_of(client, found["_ids"][0]) if found["_ids"] else None}
    save()

    # (c) discount, list-price shape: DGia before discount with TLCKhau/STCKhau filled.
    list_ref = ref("L")
    created = item_summary(*client.create_once(payload(list_ref, [line(200000, 3, 540000, before_discount=600000, pct=10, discount=60000)], "Năm trăm chín mươi tư nghìn đồng")))
    found = lookup_summary(client, list_ref)
    results["c_discount_list_price_shape"] = {"reference": list_ref, "create": created, "detail": detail_of(client, found["_ids"][0]) if found["_ids"] else None}
    save()

    # (c) inconsistent discount fields: does Mat Bao recalculate or reject?
    bad_ref = ref("X")
    created = item_summary(*client.create_once(payload(bad_ref, [line(200000, 3, 540000, before_discount=600000, pct=0, discount=0)], "Năm trăm chín mươi tư nghìn đồng")))
    found = lookup_summary(client, bad_ref)
    results["c_discount_inconsistent"] = {"reference": bad_ref, "create": created, "detail": detail_of(client, found["_ids"][0]) if found["_ids"] else None}
    save()

    # (d) non-terminating DGia (100000 / 3), as the plugin rounds it (6 decimals).
    fraction_ref = ref("F")
    created = item_summary(*client.create_once(payload(fraction_ref, [line(33333.333333, 3, 100000)], "Một trăm mười nghìn đồng")))
    found = lookup_summary(client, fraction_ref)
    results["d_fractional_unit_price"] = {"reference": fraction_ref, "create": created, "lookup": found, "detail": detail_of(client, found["_ids"][0]) if found["_ids"] else None}
    save()

    # (d) unknown template (expect 308) and exhausted template (expect 327).
    results["d_unknown_template"] = item_summary(*client.create_once(payload(ref("U"), [line(100000, 1, 100000)], "Một trăm mười nghìn đồng", series=UNKNOWN_SERIES)))
    save()
    results["d_exhausted_template"] = item_summary(*client.create_once(payload(ref("E"), [line(100000, 1, 100000)], "Một trăm mười nghìn đồng", series=EXHAUSTED_SERIES)))
    save()

    for entry in results.values():
        if isinstance(entry, dict):
            for value in entry.values():
                if isinstance(value, dict):
                    value.pop("_ids", None)
            entry.pop("_ids", None)
    save()
    return results


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--out", type=Path, default=Path("/tmp/einv-demo-verify.json"))
    args = parser.parse_args()
    if os.environ.get("EINV_DEMO_E2E") != "1":
        raise SystemExit("Set EINV_DEMO_E2E=1 to create drafts on the Mat Bao demo API.")
    run(args.out)
    print("results written to", args.out)


if __name__ == "__main__":
    main()
