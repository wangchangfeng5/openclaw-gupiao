import argparse
import hashlib
import json
import re
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List

from common import getenv, load_env, post_json, request_json, log_quality

SINA_QUOTE_URL = "http://hq.sinajs.cn/list="
SINA_HEADERS = {
    "Referer": "http://finance.sina.com.cn",
    "User-Agent": "Mozilla/5.0",
}


def trend_from_change(change_pct: float) -> str:
    if change_pct >= 2.0:
        return "strong_up"
    if change_pct >= 0.5:
        return "up_bias"
    if change_pct <= -2.0:
        return "strong_down"
    if change_pct <= -0.5:
        return "down_bias"
    return "range"


def extract_a_share_symbols(symbol_rows: List[Dict[str, Any]]) -> List[str]:
    symbols: List[str] = []
    for row in symbol_rows:
        symbol = str(row.get("symbol", "")).strip()
        market = str(row.get("market", "A_STOCK_MAIN")).strip()
        if market != "A_STOCK_MAIN":
            continue
        if not re.fullmatch(r"\d{6}", symbol):
            continue
        symbols.append(symbol)

    if not symbols:
        symbols = ["600519", "601318", "600036", "000001", "600030", "600809"]

    return list(dict.fromkeys(symbols))[:220]


def symbol_to_sina(symbol: str) -> str:
    raw = symbol.strip().lower()
    if re.fullmatch(r"(sh|sz)\d{6}", raw):
        return raw
    if re.fullmatch(r"\d{6}", raw):
        return ("sh" if raw[0] in {"5", "6", "9"} else "sz") + raw
    return ""


def urlopen_no_proxy(req: urllib.request.Request, timeout: int):
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    return opener.open(req, timeout=timeout)


def request_json_no_proxy(url: str, timeout: int = 10, headers: Dict[str, str] | None = None) -> Any:
    req_headers = {"User-Agent": "openclaw-invest-worker/1.0"}
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(url, headers=req_headers)
    with urlopen_no_proxy(req, timeout=timeout) as resp:
        content = resp.read().decode("utf-8", errors="ignore")
    return json.loads(content)


def fetch_sector_data(timeout: int = 10) -> List[Dict[str, Any]]:
    url = (
        "https://push2.eastmoney.com/api/qt/clist/get?pn=1&pz=20&po=1&np=1"
        "&fltt=2&invt=2&fid=f3&fs=m:90+t:2&fields=f12,f14,f3,f104"
    )
    payload = request_json_no_proxy(url, timeout=timeout)
    diff = ((payload or {}).get("data") or {}).get("diff") or []

    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    sectors: List[Dict[str, Any]] = []

    for row in diff:
        if not isinstance(row, dict):
            continue
        name = str(row.get("f14", "")).strip()
        if not name:
            continue

        change_pct = float(row.get("f3") or 0)
        active_count = int(row.get("f104") or 0)
        strength = round(change_pct * 1.8 + min(active_count, 200) * 0.02, 4)

        sectors.append(
            {
                "sector_code": str(row.get("f12", "")),
                "sector_name": name,
                "strength_score": strength,
                "change_pct": change_pct,
                "leading_symbol": "",
                "active_count": active_count,
                "sample_time": now,
                "source": "eastmoney",
            }
        )

    return sectors


def fetch_rss(url: str, timeout: int = 8) -> List[Dict[str, Any]]:
    req = urllib.request.Request(url, headers={"User-Agent": "openclaw-invest-worker/1.0"})
    with urlopen_no_proxy(req, timeout=timeout) as resp:
        xml_text = resp.read().decode("utf-8", errors="ignore")

    root = ET.fromstring(xml_text)
    items: List[Dict[str, Any]] = []

    for item in root.findall(".//item")[:25]:
        title = (item.findtext("title") or "").strip()
        link = (item.findtext("link") or "").strip()
        desc = (item.findtext("description") or "").strip()
        if not title:
            continue

        dedupe = hashlib.sha256(f"{title}|{link}".encode("utf-8", errors="ignore")).hexdigest()
        items.append(
            {
                "title": re.sub(r"<[^>]+>", "", title),
                "summary": re.sub(r"<[^>]+>", "", desc)[:280],
                "url": link,
                "source": url,
                "category": "macro",
                "sentiment": "neutral",
                "published_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "dedupe_hash": dedupe,
                "tags": ["rss"],
            }
        )

    return items


def fetch_symbol_universe(base_url: str, ingest_key: str, timeout: int = 8) -> List[Dict[str, Any]]:
    try:
        payload = request_json(
            f"{base_url}/api/internal/market/symbols?limit=220&suggestions=400",
            timeout=timeout,
            headers={"X-Ingest-Key": ingest_key},
        )
        if isinstance(payload, dict) and payload.get("ok"):
            data = payload.get("data") or {}
            symbols = data.get("symbols") or []
            if isinstance(symbols, list):
                return [x for x in symbols if isinstance(x, dict)]
    except Exception:
        return []
    return []


def build_quote_items(symbol_rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    symbols = extract_a_share_symbols(symbol_rows)
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    result: List[Dict[str, Any]] = []

    for symbol in symbols:
        secid = f"1.{symbol}" if symbol.startswith("6") else f"0.{symbol}"
        url = f"https://push2.eastmoney.com/api/qt/stock/get?secid={secid}&fields=f58,f43,f170,f47,f48,f127,f128"
        try:
            payload = request_json_no_proxy(url, timeout=6)
            data = (payload or {}).get("data") or {}
            raw_price = float(data.get("f43") or 0)
            price = raw_price / 100 if raw_price > 1000 else raw_price
            change_pct = float(data.get("f170") or 0) / 100
            sector_name = str(data.get("f127") or data.get("f128") or "").strip()
            result.append(
                {
                    "symbol": symbol,
                    "market": "A_STOCK_MAIN",
                    "name": str(data.get("f58") or "").strip(),
                    "sector_name": sector_name,
                    "trend_direction": trend_from_change(change_pct),
                    "price": round(price, 4),
                    "change_pct": change_pct,
                    "volume": float(data.get("f47") or 0),
                    "turnover": float(data.get("f48") or 0),
                    "quote_time": now,
                    "source": "eastmoney",
                }
            )
        except Exception:
            continue

    return result


def fetch_company_sector_name(symbol: str, timeout: int = 8) -> str:
    symbol = symbol.strip()
    if not re.fullmatch(r"\d{6}", symbol):
        return ""

    em_code = ("SH" if symbol[0] in {"5", "6", "9"} else "SZ") + symbol
    url = f"https://emweb.securities.eastmoney.com/PC_HSF10/CompanySurvey/CompanySurveyAjax?code={em_code}"
    payload = request_json_no_proxy(url, timeout=timeout)
    if not isinstance(payload, dict):
        return ""

    jbzl = payload.get("jbzl") or {}
    if not isinstance(jbzl, dict):
        return ""

    sector = str(jbzl.get("sshy") or "").strip()
    if sector:
        return sector

    # fallback classification
    return str(jbzl.get("sszjhhy") or "").strip()


def enrich_sector_names(quotes: List[Dict[str, Any]], timeout: int = 8, max_requests: int = 80) -> List[Dict[str, Any]]:
    if not quotes:
        return quotes

    cache: Dict[str, str] = {}
    requests_left = max(0, max_requests)

    for item in quotes:
        sector_name = str(item.get("sector_name", "")).strip()
        symbol = str(item.get("symbol", "")).strip()
        if sector_name or not symbol:
            continue

        if symbol in cache:
            item["sector_name"] = cache[symbol]
            continue

        if requests_left <= 0:
            continue

        requests_left -= 1
        try:
            resolved = fetch_company_sector_name(symbol, timeout=timeout)
        except Exception:
            resolved = ""
        cache[symbol] = resolved
        if resolved:
            item["sector_name"] = resolved

    return quotes


def parse_sina_quote_text(raw_text: str, quote_time: str, source: str = "streamlit_panel") -> List[Dict[str, Any]]:
    result: List[Dict[str, Any]] = []
    pattern = re.compile(r'var hq_str_(\w+)="(.*?)";')

    for symbol, payload in pattern.findall(raw_text):
        if not payload:
            continue

        fields = payload.split(",")
        if len(fields) < 6:
            continue

        try:
            name = str(fields[0]).strip()
            pre_close = float(fields[2] or 0)
            latest = float(fields[3] or 0)
            if latest <= 0:
                continue
            change_pct = ((latest - pre_close) / pre_close * 100) if pre_close else 0.0
            volume = float(fields[8] or 0) if len(fields) > 8 else 0.0
            amount = float(fields[9] or 0) if len(fields) > 9 else 0.0
        except ValueError:
            continue

        code = symbol[2:] if len(symbol) >= 8 else symbol
        if not re.fullmatch(r"\d{6}", code):
            continue

        result.append(
            {
                "symbol": code,
                "market": "A_STOCK_MAIN",
                "name": name,
                "sector_name": "",
                "trend_direction": trend_from_change(change_pct),
                "price": round(latest, 4),
                "change_pct": round(change_pct, 4),
                "volume": volume,
                "turnover": amount,
                "quote_time": quote_time,
                "source": source,
            }
        )

    return result


def fetch_streamlit_quote_items(streamlit_panel_url: str, symbol_rows: List[Dict[str, Any]], timeout: int = 8) -> List[Dict[str, Any]]:
    panel_url = streamlit_panel_url.strip().rstrip("/")
    if not panel_url:
        return []

    # Use streamlit panel as readiness gate; actual quote protocol follows the same Sina source as the panel.
    request_json_no_proxy(f"{panel_url}/_stcore/host-config", timeout=timeout)

    symbols = extract_a_share_symbols(symbol_rows)
    sina_symbols = [symbol_to_sina(x) for x in symbols]
    sina_symbols = [x for x in sina_symbols if x]
    if not sina_symbols:
        return []

    req = urllib.request.Request(
        SINA_QUOTE_URL + ",".join(sina_symbols),
        headers=SINA_HEADERS,
    )
    with urlopen_no_proxy(req, timeout=timeout) as resp:
        text = resp.read().decode("gbk", errors="ignore")

    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return parse_sina_quote_text(text, now, "streamlit_panel")


def quote_index_by_symbol(quotes: List[Dict[str, Any]]) -> Dict[str, Dict[str, Any]]:
    rows: Dict[str, Dict[str, Any]] = {}
    for item in quotes:
        symbol = str(item.get("symbol", "")).strip()
        if not symbol:
            continue
        rows[symbol] = item
    return rows


def merge_quote_items(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Keep primary quote values (streamlit/sina names and latest price),
    then backfill missing fields (sector_name, trend hints) from secondary rows.
    """
    if not primary:
        return secondary
    if not secondary:
        return primary

    second = quote_index_by_symbol(secondary)
    merged: List[Dict[str, Any]] = []
    used = set()

    for item in primary:
        symbol = str(item.get("symbol", "")).strip()
        base = dict(item)
        extra = second.get(symbol, {})

        if not str(base.get("name", "")).strip():
            base["name"] = str(extra.get("name", "")).strip()
        if not str(base.get("sector_name", "")).strip():
            base["sector_name"] = str(extra.get("sector_name", "")).strip()
        if not str(base.get("trend_direction", "")).strip():
            base["trend_direction"] = str(extra.get("trend_direction", "")).strip()
        if ("change_pct" not in base or base.get("change_pct") is None) and extra.get("change_pct") is not None:
            base["change_pct"] = extra.get("change_pct")
        if ("price" not in base or base.get("price") is None) and extra.get("price") is not None:
            base["price"] = extra.get("price")
        if ("volume" not in base or base.get("volume") is None) and extra.get("volume") is not None:
            base["volume"] = extra.get("volume")
        if ("turnover" not in base or base.get("turnover") is None) and extra.get("turnover") is not None:
            base["turnover"] = extra.get("turnover")

        merged.append(base)
        if symbol:
            used.add(symbol)

    for item in secondary:
        symbol = str(item.get("symbol", "")).strip()
        if symbol in used:
            continue
        merged.append(item)

    return merged


def fallback_sectors() -> List[Dict[str, Any]]:
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    return [
        {
            "sector_code": "SIM001",
            "sector_name": "半导体设备",
            "strength_score": 86.2,
            "change_pct": 2.31,
            "leading_symbol": "603986",
            "active_count": 48,
            "sample_time": now,
            "source": "fallback",
        },
        {
            "sector_code": "SIM002",
            "sector_name": "AI算力",
            "strength_score": 82.7,
            "change_pct": 1.89,
            "leading_symbol": "300308",
            "active_count": 55,
            "sample_time": now,
            "source": "fallback",
        },
        {
            "sector_code": "SIM003",
            "sector_name": "券商",
            "strength_score": 76.4,
            "change_pct": 1.21,
            "leading_symbol": "600030",
            "active_count": 32,
            "sample_time": now,
            "source": "fallback",
        },
    ]


def fallback_news() -> List[Dict[str, Any]]:
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    seeds = [
        ("美联储官员释放观望信号，全球风险资产波动加剧", "macro"),
        ("国内新能源车销量继续增长，产业链景气度维持", "industry"),
        ("半导体设备国产替代进程提速，机构上调相关预期", "industry"),
    ]
    rows: List[Dict[str, Any]] = []
    for idx, (title, category) in enumerate(seeds, start=1):
        dedupe = hashlib.sha256(f"fallback|{idx}|{title}".encode("utf-8", errors="ignore")).hexdigest()
        rows.append(
            {
                "title": title,
                "summary": title,
                "url": "",
                "source": "fallback",
                "category": category,
                "sentiment": "neutral",
                "published_at": now,
                "dedupe_hash": dedupe,
                "tags": ["fallback", "local"],
            }
        )
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description="Collect market sectors/news and ingest into panel")
    parser.add_argument("--once", action="store_true")
    parser.parse_args()

    project_root = Path(__file__).resolve().parents[1]
    env_vals = load_env(project_root / ".env")

    base_url = getenv("APP_URL", env_vals.get("APP_URL", "http://127.0.0.1:8080")).rstrip("/")
    ingest_key = getenv("INGEST_API_KEY", env_vals.get("INGEST_API_KEY", ""))
    timeout = int(getenv("MARKET_FETCH_TIMEOUT", env_vals.get("MARKET_FETCH_TIMEOUT", "10")) or 10)
    sector_lookup_limit = int(getenv("SECTOR_PROFILE_LOOKUP_LIMIT", env_vals.get("SECTOR_PROFILE_LOOKUP_LIMIT", "80")) or 80)
    streamlit_panel_url = getenv(
        "STREAMLIT_PANEL_URL",
        env_vals.get("STREAMLIT_PANEL_URL", "http://127.0.0.1:8501"),
    ).strip()

    if not ingest_key:
        print("INGEST_API_KEY is required")
        return 1

    headers = {"X-Ingest-Key": ingest_key}

    symbol_rows = fetch_symbol_universe(base_url, ingest_key, timeout=timeout)

    # sectors
    try:
        sectors = fetch_sector_data(timeout=timeout)
        post_json(f"{base_url}/api/internal/ingest/sectors", {"items": sectors}, headers=headers, timeout=12)
        log_quality(base_url, ingest_key, "market_collector", "ok", f"sectors={len(sectors)}")
    except Exception as exc:
        sectors = fallback_sectors()
        post_json(f"{base_url}/api/internal/ingest/sectors", {"items": sectors}, headers=headers, timeout=12)
        log_quality(base_url, ingest_key, "market_collector", "warn", f"sectors fallback used: {exc}")

    # quotes
    try:
        eastmoney_quotes = build_quote_items(symbol_rows)
        quotes = eastmoney_quotes
        quote_source = "eastmoney"

        if streamlit_panel_url:
            try:
                streamlit_quotes = fetch_streamlit_quote_items(streamlit_panel_url, symbol_rows, timeout=timeout)
                if streamlit_quotes:
                    quotes = merge_quote_items(streamlit_quotes, eastmoney_quotes)
                    quote_source = "streamlit_panel+eastmoney"
                else:
                    log_quality(base_url, ingest_key, "market_collector", "warn", "streamlit panel reachable but no quote rows")
            except Exception as exc:
                log_quality(base_url, ingest_key, "market_collector", "warn", f"streamlit quote fallback to eastmoney: {exc}")

        quotes = enrich_sector_names(quotes, timeout=timeout, max_requests=sector_lookup_limit)
        post_json(f"{base_url}/api/internal/ingest/quotes", {"items": quotes}, headers=headers, timeout=12)
        with_sector = sum(1 for x in quotes if str(x.get("sector_name", "")).strip())
        log_quality(
            base_url,
            ingest_key,
            "market_collector",
            "ok",
            f"quotes={len(quotes)} sector_filled={with_sector} source={quote_source}",
        )
    except Exception as exc:
        log_quality(base_url, ingest_key, "market_collector", "error", f"quotes failed: {exc}")

    # news
    rss_sources = [
        "https://feeds.bbci.co.uk/news/business/rss.xml",
        "https://feeds.reuters.com/reuters/businessNews",
    ]

    all_news: List[Dict[str, Any]] = []
    for source in rss_sources:
        try:
            all_news.extend(fetch_rss(source, timeout=timeout))
        except Exception as exc:
            log_quality(base_url, ingest_key, "market_collector", "warn", f"rss failed: {source} -> {exc}")

    try:
        if all_news:
            post_json(f"{base_url}/api/internal/ingest/news", {"items": all_news}, headers=headers, timeout=12)
        else:
            all_news = fallback_news()
            post_json(f"{base_url}/api/internal/ingest/news", {"items": all_news}, headers=headers, timeout=12)
        log_quality(base_url, ingest_key, "market_collector", "ok", f"news={len(all_news)}")
    except Exception as exc:
        log_quality(base_url, ingest_key, "market_collector", "error", f"news ingest failed: {exc}")

    print(f"market_collector done sectors={len(locals().get('sectors', []))} news={len(all_news)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
