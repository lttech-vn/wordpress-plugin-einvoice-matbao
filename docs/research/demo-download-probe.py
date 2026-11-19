"""Read-only probe of download-invoice on the Mat Bao demo API.

Never creates, signs or deletes invoices. Credentials come from the environment only and are never printed:
EINV_DEMO_MST, EINV_DEMO_USER, EINV_DEMO_PASS. Output carries structure only (key names, types, sizes):
no token, base64, MaTraCuu or MaSoHDon value.

Run: uv run --no-project python -I docs/research/demo-download-probe.py
"""
import base64
import datetime
import json
import os
import ssl
import urllib.error
import urllib.request

BASE = "https://demo-api-hddt.matbao.in:11443"


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPSHandler(context=ssl.create_default_context()))


def call(method, path, body=None, token=None):
    headers = {"Content-Type": "application/json"}
    if token:
        headers["Authorization"] = "Bearer " + token
    data = json.dumps(body).encode() if body is not None else None
    request = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
    try:
        with opener.open(request, timeout=60) as response:
            return response.status, response.headers.get("Content-Type"), json.loads(response.read())
    except urllib.error.HTTPError as error:
        raw = error.read(1 << 20)
        try:
            return error.code, error.headers.get("Content-Type"), json.loads(raw)
        except ValueError:
            return error.code, error.headers.get("Content-Type"), {"non_json_bytes": len(raw)}


def shape(value, depth=0):
    """Key names and types only; never values."""
    if isinstance(value, dict):
        return {k: shape(v, depth + 1) for k, v in value.items()} if depth < 4 else "object"
    if isinstance(value, list):
        return [len(value), shape(value[0], depth + 1)] if value else [0]
    return type(value).__name__


def envelope(body):
    return {k: (v if k in ("success", "errorCode", "message") and not isinstance(v, (dict, list)) else shape(v)) for k, v in body.items()}


def find(value, *names):
    """First value of any key (case-insensitive) in the nested JSON."""
    if isinstance(value, dict):
        for key, item in value.items():
            if key.lower() in names and isinstance(item, (str, int)):
                return item
        for item in value.values():
            found = find(item, *names)
            if found not in (None, ""):
                return found
    elif isinstance(value, list):
        for item in value:
            found = find(item, *names)
            if found not in (None, ""):
                return found
    return None


mst, user, password = (os.environ.get(k) for k in ("EINV_DEMO_MST", "EINV_DEMO_USER", "EINV_DEMO_PASS"))
if not (mst and user and password):
    raise SystemExit("Set EINV_DEMO_MST, EINV_DEMO_USER, EINV_DEMO_PASS.")

status, _, auth = call("POST", "/api/auth/login", {"MST": mst, "TDNhap": user, "MKhau": password})
print("login: HTTP", status, "success", auth.get("success"))
token = (auth.get("data") or {}).get("accessToken")
if not token:
    raise SystemExit("login failed")

status, _, templates = call("GET", "/api/invoice/templates?year=%d" % datetime.date.today().year, token=token)
print("templates: HTTP", status, "success", templates.get("success"), "shape", shape(templates.get("data")))
today = datetime.date.today()


def detail(form, series, state, days):
    body = {"KHMSHDon": form, "KHHDon": series, "SHDon": 0, "TNLap": (today - datetime.timedelta(days=days)).isoformat(), "DNLap": today.isoformat(), "LoaiHDon": 0, "TThaiHDon": state, "TTKhachHang": "", "MaSoHDon": ""}
    return call("POST", "/api/invoice/invoice-detail", body, token)


chosen = {}
summary = {}  # (state, errorCode, success) -> count of detail calls, to keep the output short
WINDOW = 9  # the API limits one search to 10 days
pairs = []
for template in templates.get("data") or []:
    form = str(template.get("khmshDon") or template.get("KHMSHDon") or "")
    series = str(template.get("khhDon") or template.get("KHHDon") or "")
    if form and series:
        pairs.append((form, series))
# Templates the E2E research used first, then the rest.
pairs.sort(key=lambda p: p != ("1", "C26TAT"))
for form, series in pairs:
    if 4 in chosen:
        break
    for state in (4, 0):  # 4 issued, 0 draft (shape of a failed download)
        if state in chosen:
            continue
        for window in range(3):  # 3 windows of 9 days back from today
            end = today - datetime.timedelta(days=window * WINDOW)
            body = {"KHMSHDon": form, "KHHDon": series, "SHDon": 0, "TNLap": (end - datetime.timedelta(days=WINDOW)).isoformat(), "DNLap": end.isoformat(), "LoaiHDon": 0, "TThaiHDon": state, "TTKhachHang": "", "MaSoHDon": ""}
            status, _, answer = call("POST", "/api/invoice/invoice-detail", body, token)
            key = (state, status, answer.get("success"), answer.get("errorCode"))
            summary[key] = summary.get(key, 0) + 1
            code, invoice_id = find(answer.get("data"), "matracuu"), find(answer.get("data"), "masohdon")
            if code and invoice_id:
                print("invoice-detail found state %d in %s/%s: data shape %s" % (state, form, series, shape(answer.get("data"))))
                chosen[state] = (str(invoice_id), str(code))
                break

print("invoice-detail calls (state, HTTP, success, errorCode) -> count:", summary)

for state, label in ((4, "issued"), (0, "draft")):
    if state not in chosen:
        print("download %s: no invoice with MaSoHDon and MaTraCuu found on demo" % label)
        continue
    invoice_id, code = chosen[state]
    status, content_type, body = call("POST", "/api/invoice/download-invoice", {"MaTraCuu": code, "MaSoHDon": invoice_id}, token)
    print("download %s: HTTP %s content-type %s envelope %s" % (label, status, content_type, envelope(body)))
    data = body.get("data")
    if isinstance(data, dict):
        print("  data keys:", {k: (type(v).__name__ + (" len %d" % len(v) if isinstance(v, str) else "")) for k, v in data.items()})
        encoded = next((v for k, v in data.items() if k.lower() == "data_pdf_base64" and isinstance(v, str)), None)
        if encoded is not None:
            try:
                pdf = base64.b64decode(encoded, validate=True)
                print("  decoded bytes:", len(pdf), "starts with %PDF-:", pdf.startswith(b"%PDF-"))
            except ValueError:
                print("  data_PDF_Base64 is not valid base64")
        else:
            print("  no data_PDF_Base64 key")
