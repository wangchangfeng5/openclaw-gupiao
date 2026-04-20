import argparse
import os
import subprocess
import sys
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo


def is_trading_time(now: datetime) -> bool:
    if now.weekday() >= 5:
        return False

    minutes = now.hour * 60 + now.minute
    morning = 9 * 60 + 25 <= minutes <= 11 * 60 + 35
    afternoon = 12 * 60 + 55 <= minutes <= 15 * 60 + 10
    return morning or afternoon


def run_worker(script: Path) -> int:
    proc = subprocess.run([sys.executable, str(script), "--once"], capture_output=True, text=True)
    out = (proc.stdout or "").strip()
    err = (proc.stderr or "").strip()
    if out:
        print(out)
    if err:
        print(err, file=sys.stderr)
    return proc.returncode


def in_window(now: datetime, start_h: int, start_m: int, end_h: int, end_m: int) -> bool:
    if now.weekday() >= 5:
        return False
    minutes = now.hour * 60 + now.minute
    return (start_h * 60 + start_m) <= minutes <= (end_h * 60 + end_m)


def review_snapshot_marker(root: Path, slot: str) -> Path:
    return root.parent / "storage" / "runtime" / f"review_snapshot_{slot}_last_date.txt"


def should_run_review_snapshot(root: Path, slot: str, now: datetime) -> bool:
    marker = review_snapshot_marker(root, slot)
    if not marker.exists():
        return True
    try:
        saved = marker.read_text(encoding="utf-8", errors="ignore").strip()
    except Exception:
        return True
    return saved != now.strftime("%Y-%m-%d")


def mark_review_snapshot_done(root: Path, slot: str, now: datetime) -> None:
    marker = review_snapshot_marker(root, slot)
    marker.parent.mkdir(parents=True, exist_ok=True)
    marker.write_text(now.strftime("%Y-%m-%d"), encoding="utf-8")


def run_review_snapshot(root: Path, slot: str) -> int:
    php_candidates = [
        os.getenv("PHP_BIN", "").strip(),
        os.getenv("PHP_PATH", "").strip(),
        r"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe",
        "php",
    ]
    php_bin = next((p for p in php_candidates if p), "php")
    script = root.parent / "scripts" / "review_snapshot.php"
    if not script.exists():
        print(f"review snapshot script missing: {script}")
        return 1

    proc = subprocess.run(
        [
            php_bin,
            str(script),
            f"--slot={slot}",
            "--sync=1",
            "--watchlist-limit=360",
            "--insight-limit=36",
        ],
        capture_output=True,
        text=True,
    )
    out = (proc.stdout or "").strip()
    err = (proc.stderr or "").strip()
    if out:
        print(out)
    if err:
        print(err, file=sys.stderr)
    return proc.returncode


def main() -> int:
    parser = argparse.ArgumentParser(description="Run invest panel workers with dynamic schedule")
    parser.add_argument("--once", action="store_true", help="Run workers once and exit")
    args = parser.parse_args()

    root = Path(__file__).resolve().parent
    advice = root / "advice_ingestor.py"
    market = root / "market_collector.py"

    if args.once:
        code1 = run_worker(advice)
        code2 = run_worker(market)
        return 0 if code1 == 0 and code2 == 0 else 1

    tz = ZoneInfo("Asia/Shanghai")

    while True:
        now = datetime.now(tz)
        print(f"[{now.isoformat()}] scheduler tick")
        run_worker(advice)
        run_worker(market)

        if in_window(now, 12, 8, 12, 20) and should_run_review_snapshot(root, "midday", now):
            print("trigger review snapshot: midday")
            code = run_review_snapshot(root, "midday")
            if code == 0:
                mark_review_snapshot_done(root, "midday", now)
            else:
                print(f"review snapshot midday failed with code {code}", file=sys.stderr)

        if in_window(now, 15, 8, 15, 25) and should_run_review_snapshot(root, "close", now):
            print("trigger review snapshot: close")
            code = run_review_snapshot(root, "close")
            if code == 0:
                mark_review_snapshot_done(root, "close", now)
            else:
                print(f"review snapshot close failed with code {code}", file=sys.stderr)

        interval = 60 if is_trading_time(now) else 900
        print(f"next run in {interval}s")
        import time
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
