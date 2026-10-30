"""Đối soát A và thử B/C đã lưu; không gửi lại case đã attempted."""
import datetime
import json
import ssl
import urllib.error
import urllib.request
from pathlib import Path

BASE = "https://demo-api-hddt.matbao.in:11443"
PLAN = Path("docs/research/demo-payloads.json")
OUT = Path("docs/research/demo-results.json")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

OPENER = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))

def call(path, body, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request(BASE + path, data=json.dumps(body).encode(), headers=headers)
    try:
        with OPENER.open(request, timeout=30) as response:
            value = json.loads(response.read())
            def sanitize(item):
                if isinstance(item, dict):
                    return {key: "[REDACTED]" if key.lower() in ("urldownloadpdf", "urldownloadxml", "token", "accesstoken", "secret") else sanitize(child) for key, child in item.items()}
                if isinstance(item, list):
                    return [sanitize(child) for child in item]
                return item
            return response.status, sanitize(value)
    except urllib.error.HTTPError as error:
        raw = error.read(8192)
        try:
            value = json.loads(raw)
            safe = {key: value.get(key) for key in ("success", "errorCode", "message", "errors", "title", "status")}
        except (ValueError, UnicodeDecodeError):
            safe = {"non_json_error": True, "content_type": error.headers.get("Content-Type"), "body_bytes": len(raw)}
        return error.code, safe

def login():
    items = []
    def walk(xs):
        for item in xs:
            if "item" in item:
                walk(item["item"])
            if item.get("name") == "login" and "request" in item:
                items.append(item)
    source = Path("OneDrive_1_10-13-2026/HoaDonBanRa/API HDDT - PROXY - DEMO.postman_collection.json")
    walk(json.loads(source.read_text())["item"])
    status, auth = call("/api/auth/login", json.loads(items[0]["request"]["body"]["raw"]))
    if status != 200 or auth.get("success") is not True:
        raise RuntimeError("Login demo thất bại")
    # Login cần token trong memory, không qua sanitizer của call.
    request = urllib.request.Request(BASE + "/api/auth/login", data=items[0]["request"]["body"]["raw"].encode(), headers={"Content-Type": "application/json"})
    with OPENER.open(request, timeout=30) as response:
        return json.loads(response.read())["data"]["accessToken"]

def lookup(payload, token):
    return call("/api/invoice/invoice-detail-batch", {"loai": 1, "mauSo": payload["KHMSHDon"], "kiHieu": payload["KHHDon"], "danhSach": [payload["MTChieu"]]}, token)

def main():
    plan = json.loads(PLAN.read_text())
    results = json.loads(OUT.read_text())
    import sys
    if sys.argv[1:] not in (["missing-buyer"], ["name-only"]):
        raise SystemExit("Chọn một case: missing-buyer hoặc name-only")
    case = sys.argv[1]
    fixture = Path("docs/research/demo-" + case + ".json")
    if fixture.exists():
        raise SystemExit("Fixture đã tồn tại; không gửi lại.")
    payload = dict(plan[0])
    reference = "PB" + datetime.datetime.now(datetime.timezone.utc).strftime("%y%m%d%H%M%S") + "D"
    payload.update(MTChieu=reference, MaTraCuu=reference)
    if case == "name-only":
        payload["NMua_Ten"] = "TEST BUYER - KHONG PHAT HANH"
    fixture.write_text(json.dumps(payload, ensure_ascii=False, indent=2))
    plan = [plan[0], payload]
    token = login()
    def save():
        OUT.write_text(json.dumps(results, ensure_ascii=False, indent=2))
    status, detail = lookup(plan[0], token)
    results[0].update(followup_lookup_http=status, followup_lookup=detail)
    save()
    print("A lookup", status, detail.get("errorCode"))
    for payload in plan[1:]:
        if any(entry["reference"] == payload["MTChieu"] for entry in results):
            continue
        assert payload["LoaiHDon"] == 0 and payload["KTraMTChieuTrung"] == 1
        entry = {"reference": payload["MTChieu"], "attempted_at": datetime.datetime.now(datetime.timezone.utc).isoformat()}
        results.append(entry)
        save()
        try:
            status, response = call("/api/invoice/create-invoice", [payload], token)
            entry.update(http=status, response=response)
            save()
            print("create", payload["MTChieu"], status, json.dumps({k: response.get(k) for k in ("success", "errorCode", "message", "non_json_error")}, ensure_ascii=False))
            lookup_status, detail = lookup(payload, token)
            entry.update(lookup_http=lookup_status, lookup=detail)
            save()
            print("lookup", lookup_status, detail.get("success"), detail.get("errorCode"))
            if status != 200 or response.get("success") is not True:
                print("Dừng create để phân tích lỗi.")
                break
        except Exception as error:
            entry["transport_error"] = type(error).__name__
            save()
            print("Dừng; kết quả chưa rõ", type(error).__name__)
            break

if __name__ == "__main__":
    main()
