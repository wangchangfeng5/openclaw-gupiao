import json
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "workers"))

from advice_ingestor import extract_text, process_file  # noqa: E402


class AdviceIngestorTests(unittest.TestCase):
    def test_extract_text_list_content(self):
        content = [
            {"type": "text", "text": "第一段"},
            {"type": "toolCall", "name": "abc"},
            {"type": "text", "text": "第二段"},
        ]
        self.assertEqual(extract_text(content), "第一段\n第二段")

    def test_process_file_reads_assistant_messages(self):
        rows = [
            {
                "type": "message",
                "id": "m1",
                "timestamp": 1776141605529,
                "message": {
                    "role": "assistant",
                    "content": [{"type": "text", "text": "请关注600519和601318的止损位与止盈位，并评估仓位风险"}],
                },
            },
            {
                "type": "message",
                "id": "m2",
                "timestamp": 1776141605530,
                "message": {
                    "role": "user",
                    "content": [{"type": "text", "text": "忽略我"}],
                },
            },
        ]

        temp_dir = ROOT / "storage" / "cache" / "test_tmp"
        temp_dir.mkdir(parents=True, exist_ok=True)
        f = temp_dir / "session.jsonl"
        with f.open("w", encoding="utf-8") as fp:
            for row in rows:
                fp.write(json.dumps(row, ensure_ascii=False) + "\n")

        items, offset = process_file(f, 0)
        self.assertEqual(len(items), 1)
        self.assertTrue(offset > 0)
        self.assertEqual(items[0]["message_id"], "m1")
        self.assertIn("600519", items[0]["symbols"])



if __name__ == "__main__":
    unittest.main()
