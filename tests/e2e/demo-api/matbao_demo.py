"""Guarded client for the Mat Bao *demo* API.

Safety rules (issue #15):
- Only the demo host is allowed; any other host (production included) raises before a socket opens.
- TLS verification stays on; redirects are refused.
- Credentials come only from EINV_DEMO_MST / EINV_DEMO_USER / EINV_DEMO_PASS and are never logged.
- A create-invoice POST is never retried by this module.
- Responses are sanitized: tokens and download URLs are replaced before anything is stored.
"""

from __future__ import annotations

import json
import os
import ssl
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

DEMO_BASE_URL = "https://demo-api-hddt.matbao.in:11443"
ALLOWED_HOSTS = frozenset({"demo-api-hddt.matbao.in"})
ALLOWED_PORT = 11443
REDACTED_KEYS = frozenset({"accesstoken", "token", "refreshtoken", "urldownloadpdf", "urldownloadxml", "mkhau", "password"})
TIMEOUT_SECONDS = 60


class DemoGuardError(RuntimeError):
    """Raised when a request would leave the demo environment."""


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: D401, ANN001
        raise DemoGuardError(f"redirect refused (HTTP {code})")


def assert_demo_url(url: str) -> None:
    parts = urllib.parse.urlsplit(url)
    if parts.scheme != "https" or parts.hostname not in ALLOWED_HOSTS or parts.port != ALLOWED_PORT:
        raise DemoGuardError("refusing non-demo URL: " + (parts.hostname or "?"))


def sanitize(value: Any) -> Any:
    if isinstance(value, dict):
        return {k: ("***" if k.lower() in REDACTED_KEYS else sanitize(v)) for k, v in value.items()}
    if isinstance(value, list):
        return [sanitize(v) for v in value]
    return value


def credentials() -> dict[str, str]:
    values = {"MST": os.environ.get("EINV_DEMO_MST", ""), "TDNhap": os.environ.get("EINV_DEMO_USER", ""), "MKhau": os.environ.get("EINV_DEMO_PASS", "")}
    if not all(values.values()):
        raise SystemExit("Set EINV_DEMO_MST, EINV_DEMO_USER and EINV_DEMO_PASS (demo account from the vendor doc).")
    return values


class DemoClient:
    def __init__(self, base_url: str = DEMO_BASE_URL) -> None:
        assert_demo_url(base_url)
        self.base_url = base_url
        context = ssl.create_default_context()
        context.check_hostname = True
        context.verify_mode = ssl.CERT_REQUIRED
        self._opener = urllib.request.build_opener(_NoRedirect(), urllib.request.HTTPSHandler(context=context))
        self._token: str | None = None
        self.login_expired_date: str | None = None

    def _send(self, method: str, path: str, body: Any = None, token: str | None = None) -> tuple[int, Any]:
        url = self.base_url + path
        assert_demo_url(url)
        headers = {"Content-Type": "application/json", "Accept": "application/json"}
        if token:
            headers["Authorization"] = "Bearer " + token
        data = None if body is None else json.dumps(body, ensure_ascii=False).encode("utf-8")
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with self._opener.open(request, timeout=TIMEOUT_SECONDS) as response:
                raw = response.read()
                status = response.status
        except urllib.error.HTTPError as error:
            raw = error.read(65536)
            status = error.code
        try:
            return status, json.loads(raw)
        except (ValueError, UnicodeDecodeError):
            return status, {"non_json_body_bytes": len(raw)}

    def login(self) -> dict[str, Any]:
        status, body = self._send("POST", "/api/auth/login", credentials())
        data = body.get("data") if isinstance(body, dict) else None
        if status != 200 or not isinstance(data, dict) or not data.get("accessToken"):
            raise SystemExit(f"demo login failed: HTTP {status}")
        self._token = data["accessToken"]
        self.login_expired_date = data.get("expiredDate")
        return {"http": status, "keys": sorted(data.keys()), "expiredDate": self.login_expired_date}

    def call(self, method: str, path: str, body: Any = None) -> tuple[int, Any]:
        if self._token is None:
            self.login()
        status, response = self._send(method, path, body, self._token)
        return status, sanitize(response)

    def create_once(self, payload: dict[str, Any]) -> tuple[int, Any]:
        """One create-invoice POST (draft only). Never retried."""
        if payload.get("LoaiHDon") != 0:
            raise DemoGuardError("only drafts (LoaiHDon=0) may be created")
        return self.call("POST", "/api/invoice/create-invoice", [payload])

    def lookup(self, references: list[str], form: str, series: str) -> tuple[int, Any]:
        return self.call("POST", "/api/invoice/invoice-detail-batch", {"loai": 1, "mauSo": form, "kiHieu": series, "danhSach": references})

    def lookup_raw(self, body: dict[str, Any]) -> tuple[int, Any]:
        return self.call("POST", "/api/invoice/invoice-detail-batch", body)

    def detail(self, body: dict[str, Any]) -> tuple[int, Any]:
        return self.call("POST", "/api/invoice/invoice-detail", body)

    def templates(self, year: int) -> tuple[int, Any]:
        return self.call("GET", f"/api/invoice/templates?year={year}")


if __name__ == "__main__":
    # Self-test of the guard; no network.
    for bad in ("https://api-hddt.matbao.in:11443/x", "http://demo-api-hddt.matbao.in:11443/x", "https://demo-api-hddt.matbao.in.evil.test:11443/x", "https://demo-api-hddt.matbao.in/x"):
        try:
            assert_demo_url(bad)
        except DemoGuardError:
            continue
        raise SystemExit("guard accepted " + bad)
    assert_demo_url(DEMO_BASE_URL + "/api/auth/login")
    assert sanitize({"data": {"accessToken": "x", "urlDownloadPDF": "y", "a": 1}}) == {"data": {"accessToken": "***", "urlDownloadPDF": "***", "a": 1}}
    print("guard self-test ok")
