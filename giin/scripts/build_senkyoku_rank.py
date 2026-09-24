#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""選挙区ごとの指標に、全国での順位と中央値を付ける。

  /usr/bin/python3 scripts/build_senkyoku_rank.py

**なぜ要るか。** 選挙区ページ289枚は同じ型で、中身は数字の表だけだった。
Google は「クロール済み、しかし索引に登録されていない」と返していた（2026-09-24 実測。
URL検査で `Crawled - currently not indexed`）。技術的な不備ではなく、
**289枚が互いに似すぎていて、1枚ずつの固有の中身が無い**という判定。

そこで、同じ数字から**その選挙区にしか書けない事実**を作る:
  - 全国289区（指標によっては収録区のみ）の中で何位か
  - 中央値の何倍か
  - 1位・最下位はどこか
数字は足さない。並べ替えて位置を出すだけなので、出典も時点も変わらない。

表 senkyoku_rank(key, metric, rank, total, median, ratio, top_key, top_value)
"""
from __future__ import annotations

import os
import sqlite3
import statistics
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")

SCHEMA = """
CREATE TABLE IF NOT EXISTS senkyoku_rank(
  key TEXT, metric TEXT, rank INTEGER, total INTEGER,
  median REAL, ratio REAL, top_key TEXT, top_value INTEGER,
  PRIMARY KEY(key, metric));
CREATE INDEX IF NOT EXISTS ix_sr_metric ON senkyoku_rank(metric, rank);
"""


def main() -> int:
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    con.executescript(SCHEMA)
    con.execute("DELETE FROM senkyoku_rank")

    by_metric: dict[str, list[tuple[str, int]]] = {}
    for key, metric, value in con.execute("SELECT key, metric, value FROM senkyoku_stat"):
        if value is None:
            continue
        by_metric.setdefault(metric, []).append((key, int(value)))

    rows = []
    for metric, items in by_metric.items():
        items.sort(key=lambda x: -x[1])
        total = len(items)
        med = statistics.median([v for _, v in items])
        top_key, top_value = items[0]
        for i, (key, value) in enumerate(items, 1):
            ratio = (value / med) if med else None
            rows.append((key, metric, i, total, med, ratio, top_key, top_value))
    con.executemany("INSERT OR REPLACE INTO senkyoku_rank VALUES (?,?,?,?,?,?,?,?)", rows)
    con.commit()
    print(f"順位 {len(rows):,}件 / 指標 {len(by_metric)}種類")
    for m in ("dosha", "shelters", "houmon", "gakudo_waiting"):
        if m not in by_metric:
            continue
        n = len(by_metric[m])
        med = statistics.median([v for _, v in by_metric[m]])
        print(f"  {m:18} {n}区 中央値 {med:,.0f}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
