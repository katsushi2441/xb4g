#!/usr/bin/env python3
"""公式YouTubeチャンネルの**全動画**を取り込む。

`fetch_youtube.py` はチャンネルRSSを読むが、**RSSは新着15本しか返さない**。
質疑と動画を対応づけるには、過去の動画まで要る（水野議員の場合、
2025年11月の初質疑の動画5本はRSSに入らない）。

取得は yt-dlp の `--flat-playlist`。**APIキーは要らない。**
持つのは動画ID・タイトル・URLだけで、本文も字幕も持たない。

  /usr/bin/python3 scripts/fetch_youtube_all.py
  /usr/bin/python3 scripts/fetch_youtube_all.py --slug mizuno-koichi
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import subprocess
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
LINKS = os.path.join(ROOT, "data", "links.json")
YTDLP = os.environ.get("GIIN_YTDLP", "/home/kojima/.local/bin/yt-dlp")
WAIT = 2.0


def db():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout=5000")
    con.execute("""CREATE TABLE IF NOT EXISTS video(
      video_id TEXT PRIMARY KEY, giin_id INTEGER, title TEXT, published TEXT,
      thumb TEXT, channel TEXT, fetched_at TEXT)""")
    return con


def listing(channel_id: str):
    """チャンネルの動画を全部。--flat-playlist なので1本ずつ開かない（速い・軽い）。"""
    url = f"https://www.youtube.com/channel/{channel_id}/videos"
    # lang=ja を付けないと YouTube の自動翻訳題名（英語）が返り、
    # 題名の日付で質疑と結びつける build_speech_video.py がほぼ空振りする。
    # 2026-09-16 実測: 伊藤孝恵議員の558本が全部英語題名で、対応づけは1日だけだった。
    r = subprocess.run([YTDLP, "--flat-playlist", "--no-warnings", "--no-update",
                        "--extractor-args", "youtube:lang=ja",
                        "--print", "%(id)s\t%(title)s", url],
                       capture_output=True, text=True, timeout=900)
    out = []
    for line in (r.stdout or "").splitlines():
        if "\t" in line:
            vid, title = line.split("\t", 1)
            if vid and vid != "NA":
                out.append((vid.strip(), title.strip()))
    if not out and r.stderr:
        print(f"    取れず: {r.stderr.strip()[-120:]}", file=sys.stderr)
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug")
    a = ap.parse_args()
    if not os.path.isfile(LINKS):
        print("data/links.json がありません", file=sys.stderr)
        return 1
    links = json.load(open(LINKS, encoding="utf-8"))
    con = db()
    now = datetime.datetime.now().isoformat(timespec="seconds")
    total = 0
    for slug, v in links.items():
        if a.slug and slug != a.slug:
            continue
        ch = v.get("youtube_channel_id")
        if not ch:
            continue
        g = con.execute("SELECT id, plain FROM giin WHERE slug=?", (slug,)).fetchone()
        if not g:
            continue
        rows = listing(ch)
        time.sleep(WAIT)
        new = 0
        for vid, title in rows:
            # **RSSで入った分の published を消さない。** 一覧APIは日付を返さないので、
            # 既にある行は title だけ直し、無い行は published を空で入れる。
            cur = con.execute("SELECT published FROM video WHERE video_id=?", (vid,)).fetchone()
            if cur:
                con.execute("UPDATE video SET title=?, giin_id=?, fetched_at=? WHERE video_id=?",
                            (title, g["id"], now, vid))
            else:
                con.execute("INSERT INTO video VALUES(?,?,?,?,?,?,?)",
                            (vid, g["id"], title, "",
                             f"https://i.ytimg.com/vi/{vid}/hqdefault.jpg", "", now))
                new += 1
        con.commit()
        total += len(rows)
        print(f"  {g['plain']:10} 全{len(rows):3}本（新規 {new}）")
    print(f"\n合計 {total}本")
    return 0


if __name__ == "__main__":
    sys.exit(main())
