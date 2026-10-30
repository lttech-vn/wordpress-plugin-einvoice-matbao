"""Khảo sát tạo nháp demo; không retry POST, không ký/phát hành."""
import datetime
import json
import ssl
import urllib.error
import urllib.request
from pathlib import Path

BASE = "https://demo-api-hddt.matbao.in:11443"
OUT = Path("docs/research/demo-results.json")
PLAN = Path("docs/research/demo-payloads.json")

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))

def call(path, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    request = urllib.request.Request(BASE + path, data=json.dumps(body).encode() if body is not None else None, headers=headers)
    try:
        with opener.open(request, timeout=30) as response:
            return response.status, json.loads(response.read())
    except urllib.error.HTTPError as error:
        raw = error.read(8192)
        try:
            value = json.loads(raw)
            safe = {key: value.get(key) for key in ("success", "errorCode", "message")}
        except (ValueError, UnicodeDecodeError):
            safe = {"non_json_error": True, "content_type": error.headers.get("Content-Type"), "body_bytes": len(raw)}
        return error.code, safe

items = []
def walk(xs):
    for item in xs:
        if "item" in item:
            walk(item["item"])
        if "request" in item:
            items.append(item)

source = Path("OneDrive_1_10-13-2026/HoaDonBanRa/API HDDT - PROXY - DEMO.postman_collection.json")
walk(json.loads(source.read_text())["item"])
credentials = json.loads(next(x for x in items if x["name"] == "login")["request"]["body"]["raw"])
_, auth = call("/api/auth/login", credentials)
assert auth["success"] is True
TOKEN = auth["data"]["accessToken"]
_, templates = call("/api/invoice/templates?year=2026", token=TOKEN)
assert any(x["khmshDon"] == "1" and x["khhDon"] == "C26TAT" and x["cLai"] > 0 for x in templates["data"])
if PLAN.exists() or OUT.exists():
    raise SystemExit("Đã có plan/result; dừng để tránh tạo lại test. Không gửi lại case A.")
now = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=7)))
ref = "PB" + now.strftime("%y%m%d%H%M%S")
base = {"KHMSHDon": "1", "KHHDon": "C26TAT", "NLap": now.strftime("%Y-%m-%dT00:00:00"), "LoaiHDon": 0, "KTraMTChieuTrung": 1, "TCHDon": 0, "LoaiTraHang": 0, "DVTTe": 704, "TGia": 1, "HTTToan": "CK", "GChu": "TEST PI-BOLT - CHI TAO NHAP", "DSHHDVu": [{"TChat": 1, "STT": 1, "MHHDVu": "PBTEST", "THHDVu": "Dich vu thu nghiem - khong phat hanh", "DVTinh": "Goi", "SLuong": 1, "DGia": 100000, "ThTienChuaCK": 100000, "TLCKhau": 0, "STCKhau": 0, "ThTien": 100000, "TSuat": 10, "TThue": 10000, "TgTien": 110000}], "TTCKTMai": 0, "TGTKhac": 0, "TgThTien": 100000, "TgTThue": 10000, "TgTTTBSo": 110000, "TgTTTBChu": "Mot tram muoi nghin dong"}
cases = []
for suffix, buyer in [("A", {}), ("B", {"NMua_Ten": "CA NHAN TEST", "NMua_HVTNMHang": "CA NHAN TEST", "NMua_DChi": "Dia chi thu nghiem", "NMua_CCCDan": "000000000000", "NMua_SDThoai": "0000000000", "NMua_DCTDTu": "test@example.invalid"}), ("C", {"NMua_Ten": "DOANH NGHIEP TEST", "NMua_MST": credentials["MST"], "NMua_DChi": "Dia chi thu nghiem", "NMua_SDThoai": "0000000000", "NMua_DCTDTu": "test@example.invalid"})]:
    payload = dict(base, **buyer, MTChieu=ref + suffix, MaTraCuu=ref + suffix)
    cases.append(payload)
PLAN.write_text(json.dumps(cases, ensure_ascii=False, indent=2))
results = []
for payload in cases:
    entry = {"reference": payload["MTChieu"]}
    results.append(entry)
    OUT.write_text(json.dumps(results, ensure_ascii=False, indent=2))
    try:
        status, response = call("/api/invoice/create-invoice", [payload], TOKEN)
        entry.update(http=status, response=response)
        OUT.write_text(json.dumps(results, ensure_ascii=False, indent=2))
        status, lookup = call("/api/invoice/invoice-detail-batch", {"loai": 1, "mauSo": "1", "kiHieu": "C26TAT", "danhSach": [payload["MTChieu"]]}, TOKEN)
        entry.update(lookup_http=status, lookup=lookup)
        OUT.write_text(json.dumps(results, ensure_ascii=False, indent=2))
        print(payload["MTChieu"], "HTTP", entry["http"], "success", response.get("success"), "errorCode", response.get("errorCode"), "message", response.get("message"), "lookup success", lookup.get("success"))
    except Exception as error:
        entry["transport_error"] = type(error).__name__
        OUT.write_text(json.dumps(results, ensure_ascii=False, indent=2))
        print("Dừng; kết quả chưa rõ:", type(error).__name__)
        break
