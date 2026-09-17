#!/usr/bin/env python3
"""質疑をした日と、その日の公式動画を対応づける。

**この道具にしかできない。** 会議録（読む）と公式動画（見る・聞く）の
両方を持っているので、同じ日の仕事を両側から辿れるようにできる。

対応づけは**動画の題名に入っている日付**で行う。推測はしない。
「2026年5月18日」「5月18日」のように書かれているものだけを拾い、
題名に日付が無い動画は対応づけない（当てずっぽうで結びつけない）。

  python3 scripts/build_speech_video.py
  python3 scripts/build_speech_video.py --show mizuno-koichi
"""
import argparse
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")


def db():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout=5000")
    con.execute("""CREATE TABLE IF NOT EXISTS speech_video(
        giin_id INTEGER, date TEXT, video_id TEXT,
        PRIMARY KEY(giin_id, date, video_id))""")
    con.execute("CREATE INDEX IF NOT EXISTS ix_sv ON speech_video(giin_id, date)")
    return con


def dates_in(title: str, year_hint: set) -> set:
    """題名から日付を拾う。年が無い題名は、その議員が質疑した年だけを候補にする。"""
    out = set()
    for m in re.finditer(r"(20\d{2})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})\s*日", title):
        out.add(f"{m.group(1)}-{int(m.group(2)):02d}-{int(m.group(3)):02d}")
    # 「2026/7/10」「26/7/10」「2026.7.10」も拾う（野村美穂議員の題名が「26/7/10」形式）
    for m in re.finditer(r"(?<!\d)(20\d{2}|\d{2})[/.](\d{1,2})[/.](\d{1,2})(?!\d)", title):
        y = m.group(1) if len(m.group(1)) == 4 else "20" + m.group(1)
        mo, d = int(m.group(2)), int(m.group(3))
        if 1 <= mo <= 12 and 1 <= d <= 31:
            out.add(f"{y}-{mo:02d}-{d:02d}")
    if not out:
        for m in re.finditer(r"(?<!\d)(\d{1,2})\s*月\s*(\d{1,2})\s*日", title):
            for y in year_hint:
                out.add(f"{y}-{int(m.group(1)):02d}-{int(m.group(2)):02d}")
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--show")
    a = ap.parse_args()
    con = db()

    if a.show:
        g = con.execute("SELECT id, plain FROM giin WHERE slug=?", (a.show,)).fetchone()
        for r in con.execute("""SELECT sv.date, v.title FROM speech_video sv
            JOIN video v ON v.video_id=sv.video_id WHERE sv.giin_id=?
            ORDER BY sv.date DESC""", (g["id"],)):
            print(f"  {r['date']}  {r['title'][:64]}")
        return 0

    con.execute("DELETE FROM speech_video")
    n = 0
    for g in con.execute("SELECT id, plain, slug FROM giin"):
        days = {r[0][:10] for r in con.execute(
            "SELECT DISTINCT date FROM speech WHERE giin_id=? AND kind='q'", (g["id"],))}
        if not days:
            continue
        years = {d[:4] for d in days}
        hit = 0
        for v in con.execute("SELECT video_id, title FROM video WHERE giin_id=?", (g["id"],)):
            for d in dates_in(v["title"], years):
                if d in days:
                    con.execute("INSERT OR REPLACE INTO speech_video VALUES(?,?,?)",
                                (g["id"], d, v["video_id"]))
                    hit += 1
        if hit:
            covered = con.execute("SELECT COUNT(DISTINCT date) FROM speech_video WHERE giin_id=?",
                                  (g["id"],)).fetchone()[0]
            print(f"  {g['plain']:10} 動画{hit:3}本 → 質疑{len(days)}日のうち{covered}日に対応")
            n += hit
    con.commit()
    print(f"\n対応づけ {n}件")
    return 0


if __name__ == "__main__":
    sys.exit(main())
