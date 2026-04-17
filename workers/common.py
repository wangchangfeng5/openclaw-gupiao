import json
import os
import time
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict


def load_env(env_path: Path) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not env_path.exists():
        return values

    for line in env_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def getenv(key: str, default: str = "") -> str:
    return os.environ.get(key, default)


def request_json(url: str, timeout: int = 10, headers: Dict[str, str] | None = None) -> Any:
    req_headers = {"User-Agent": "openclaw-invest-worker/1.0"}
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(url, headers=req_headers)
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        content = resp.read().decode("utf-8", errors="ignore")
    return json.loads(content)


def post_json(url: str, payload: Dict[str, Any], headers: Dict[str, str] | None = None, timeout: int = 10) -> Dict[str, Any]:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req_headers = {
        "Content-Type": "application/json",
        "User-Agent": "openclaw-invest-worker/1.0",
    }
    if headers:
        req_headers.update(headers)

    req = urllib.request.Request(url, data=data, headers=req_headers, method="POST")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        body = resp.read().decode("utf-8", errors="ignore")

    try:
        return json.loads(body)
    except json.JSONDecodeError:
        return {"ok": False, "error": "invalid json", "raw": body}


def safe_sleep(seconds: int) -> None:
    for _ in range(seconds):
        time.sleep(1)


def log_quality(base_url: str, ingest_key: str, job_name: str, status: str, message: str, retry_count: int = 0, context: Dict[str, Any] | None = None) -> None:
    try:
        post_json(
            f"{base_url}/api/internal/quality/log",
            {
                "job_name": job_name,
                "status": status,
                "message": message,
                "retry_count": retry_count,
                "context": context or {},
            },
            headers={"X-Ingest-Key": ingest_key},
            timeout=8,
        )
    except Exception:
        pass
