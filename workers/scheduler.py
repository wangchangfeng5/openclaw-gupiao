import argparse
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

        interval = 60 if is_trading_time(now) else 900
        print(f"next run in {interval}s")
        import time
        time.sleep(interval)


if __name__ == "__main__":
    raise SystemExit(main())
