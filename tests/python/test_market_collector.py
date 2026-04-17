import pathlib
import sys
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[2]
WORKERS = ROOT / "workers"
if str(WORKERS) not in sys.path:
    sys.path.insert(0, str(WORKERS))

import market_collector  # noqa: E402


class MarketCollectorTests(unittest.TestCase):
    def test_parse_sina_quote_text_extracts_name_and_symbol(self):
        raw = 'var hq_str_sh600030="中信证券,26.05,26.19,25.98,26.39,25.97,0,0,997115,2605120596";'
        rows = market_collector.parse_sina_quote_text(raw, "2026-04-15 14:40:00")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["symbol"], "600030")
        self.assertEqual(rows[0]["name"], "中信证券")
        self.assertEqual(rows[0]["market"], "A_STOCK_MAIN")

    def test_merge_quote_items_backfills_sector(self):
        primary = [
            {
                "symbol": "600030",
                "name": "中信证券",
                "sector_name": "",
                "trend_direction": "down_bias",
                "price": 25.98,
            }
        ]
        secondary = [
            {
                "symbol": "600030",
                "name": "中信证券",
                "sector_name": "券商信托",
                "trend_direction": "down_bias",
                "price": 25.97,
            }
        ]
        merged = market_collector.merge_quote_items(primary, secondary)
        self.assertEqual(len(merged), 1)
        self.assertEqual(merged[0]["sector_name"], "券商信托")
        self.assertEqual(merged[0]["name"], "中信证券")


if __name__ == "__main__":
    unittest.main()
