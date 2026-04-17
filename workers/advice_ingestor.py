import argparse
import json
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

from common import getenv, load_env, post_json, log_quality

STOCK_CODE_RE = re.compile(r"(?<!\d)\d{6}(?!\d)")
CN_STOCK_PREFIXES = ("000", "001", "002", "003", "300", "301", "600", "601", "603", "605", "688", "689", "900")


def extract_text(content: Any) -> str:
    if isinstance(content, str):
        return content.strip()
    if isinstance(content, list):
        chunks: List[str] = []
        for item in content:
            if isinstance(item, dict) and item.get("type") == "text":
                text = item.get("text", "")
                if isinstance(text, str):
                    chunks.append(text)
        return "\n".join(chunks).strip()
    return ""


def read_state(state_file: Path) -> Dict[str, Any]:
    if not state_file.exists():
        return {"files": {}}
    try:
        return json.loads(state_file.read_text(encoding="utf-8"))
    except Exception:
        return {"files": {}}


def write_state(state_file: Path, state: Dict[str, Any]) -> None:
    state_file.parent.mkdir(parents=True, exist_ok=True)
    state_file.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")


def infer_tags(text: str) -> List[str]:
    tags = ["openclaw"]
    lower = text.lower()
    if "止损" in text or "stop loss" in lower:
        tags.append("止损")
    if "止盈" in text or "take profit" in lower:
        tags.append("止盈")
    if "板块" in text:
        tags.append("板块")
    if "持仓" in text:
        tags.append("持仓")
    return sorted(set(tags))


def is_likely_cn_stock(symbol: str) -> bool:
    return len(symbol) == 6 and symbol.isdigit() and symbol.startswith(CN_STOCK_PREFIXES)


def load_dynamic_ingest_username(target_file: Path) -> str:
    if not target_file.exists():
        return ""
    try:
        data = json.loads(target_file.read_text(encoding="utf-8"))
    except Exception:
        return ""

    if isinstance(data, dict):
        username = data.get("username", "")
        if isinstance(username, str):
            return username.strip()
    return ""


def discover_session_files(session_index_path: Path) -> List[Path]:
    if not session_index_path.exists():
        return []

    data = json.loads(session_index_path.read_text(encoding="utf-8"))
    files: List[Path] = []
    if isinstance(data, dict):
        for v in data.values():
            if isinstance(v, dict) and "sessionFile" in v:
                p = Path(str(v["sessionFile"]))
                if p.exists() and p.suffix.lower() == ".jsonl":
                    files.append(p)
    return files


def process_file(path: Path, start_offset: int) -> tuple[list[dict[str, Any]], int]:
    items: list[dict[str, Any]] = []
    offset = start_offset

    with path.open("r", encoding="utf-8", errors="ignore") as f:
        if start_offset > 0:
            f.seek(start_offset)

        while True:
            pos = f.tell()
            line = f.readline()
            if not line:
                break

            offset = f.tell()
            line = line.strip()
            if not line:
                continue

            try:
                record = json.loads(line)
            except json.JSONDecodeError:
                continue

            if not isinstance(record, dict):
                continue

            if record.get("type") != "message":
                continue

            msg = record.get("message", {})
            if not isinstance(msg, dict) or msg.get("role") != "assistant":
                continue

            text = extract_text(msg.get("content"))
            if len(text) < 20:
                continue

            symbols = sorted({s for s in STOCK_CODE_RE.findall(text) if is_likely_cn_stock(s)})
            message_id = str(record.get("id") or msg.get("id") or f"fallback-{pos}")
            ts = record.get("timestamp") or msg.get("timestamp")

            try:
                if isinstance(ts, (int, float)):
                    suggested_at = datetime.fromtimestamp(float(ts) / 1000).strftime("%Y-%m-%d %H:%M:%S")
                else:
                    suggested_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            except Exception:
                suggested_at = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")

            items.append(
                {
                    "session_id": record.get("session_id") or "agent-main",
                    "message_id": message_id,
                    "source_file": str(path),
                    "suggested_at": suggested_at,
                    "content": text,
                    "symbols": symbols,
                    "tags": infer_tags(text),
                    "confidence": 72,
                    "ingest_source": "auto",
                }
            )

    return items, offset


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest OpenClaw assistant suggestions from session JSONL")
    parser.add_argument("--once", action="store_true", help="Run once")
    args = parser.parse_args()

    project_root = Path(__file__).resolve().parents[1]
    env_vals = load_env(project_root / ".env")

    base_url = getenv("APP_URL", env_vals.get("APP_URL", "http://127.0.0.1:8080")).rstrip("/")
    ingest_key = getenv("INGEST_API_KEY", env_vals.get("INGEST_API_KEY", ""))
    ingest_target_file = Path(
        getenv(
            "INGEST_TARGET_FILE",
            env_vals.get("INGEST_TARGET_FILE", str(project_root / "storage" / "cache" / "ingest_target.json")),
        )
    )
    dynamic_username = load_dynamic_ingest_username(ingest_target_file)
    ingest_username = getenv(
        "INGEST_USERNAME",
        dynamic_username or env_vals.get("INGEST_DEFAULT_USERNAME", env_vals.get("ADMIN_USERNAME", "")),
    )
    session_index = Path(
        getenv(
            "OPENCLAW_SESSION_INDEX",
            env_vals.get("OPENCLAW_SESSION_INDEX", r"C:\Users\Administrator\.openclaw\agents\main\sessions\sessions.json"),
        )
    )

    state_file = project_root / "storage" / "cache" / "advice_ingestor_state.json"

    if not ingest_key:
        print("INGEST_API_KEY is required")
        return 1

    state = read_state(state_file)
    file_offsets: Dict[str, int] = state.get("files", {}) if isinstance(state.get("files"), dict) else {}

    files = discover_session_files(session_index)
    total_added = 0

    for session_file in files:
        current_offset = int(file_offsets.get(str(session_file), 0))
        items, next_offset = process_file(session_file, current_offset)

        if items:
            try:
                resp = post_json(
                    f"{base_url}/api/internal/ingest/suggestions",
                    {
                        "consumer_name": "advice_ingestor",
                        "username": ingest_username,
                        "session_id": session_file.stem,
                        "last_file": str(session_file),
                        "last_offset": next_offset,
                        "items": items,
                    },
                    headers={"X-Ingest-Key": ingest_key},
                    timeout=12,
                )
                if resp.get("ok"):
                    total_added += int(resp.get("data", {}).get("added", 0))
                else:
                    log_quality(base_url, ingest_key, "advice_ingestor", "error", str(resp.get("error", "ingest failed")), context={"file": str(session_file)})
            except Exception as exc:
                log_quality(base_url, ingest_key, "advice_ingestor", "error", str(exc), context={"file": str(session_file)})

        file_offsets[str(session_file)] = next_offset

    write_state(state_file, {"files": file_offsets, "updated_at": datetime.now(timezone.utc).isoformat()})

    log_quality(base_url, ingest_key, "advice_ingestor", "ok", f"done, added={total_added}")
    print(f"advice_ingestor done, added={total_added}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
