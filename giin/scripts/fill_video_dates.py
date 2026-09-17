#!/usr/bin/env python3
"""公式YouTubeの動画に投稿日を入れる（チャンネルRSSが 404 のときの代わり）。

**直近だけでよい。** 日付を使うのは「公式YouTubeの新着」の並び順だけで、
質疑との対応づけ（build_speech_video.py）は題名の日付で行う。全動画を回すと
1本ごとに数秒かかる（73本で約5分）ので、チャンネル一覧の順（新しい順）で上から N 本だけ埋める。

  /usr/bin/python3 scripts/fill_video_dates.py --slug tanno-midori            # 直近15本
  /usr/bin/python3 scripts/fill_video_dates.py --slug tanno-midori --limit 40
"""
import argparse, os, sqlite3, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
YTDLP = os.environ.get("GIIN_YTDLP", "/home/kojima/.local/bin/yt-dlp")


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug", required=True)
    ap.add_argument("--limit", type=int, default=15, help="チャンネル一覧の上から何本まで（新しい順）")
    a = ap.parse_args()
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    g = con.execute("SELECT id, plain FROM giin WHERE slug=?", (a.slug,)).fetchone()
    if not g:
        print("議員が見つからない", file=sys.stderr)
        return 1
    # fetch_youtube_all.py はチャンネル一覧（新しい順）の順に INSERT するので rowid の小さい方が新しい
    rows = con.execute("SELECT video_id, published FROM video WHERE giin_id=? ORDER BY rowid LIMIT ?",
                       (g[0], a.limit)).fetchall()
    need = [v for v, pub in rows if not pub]
    if not need:
        print(f"  {g[1]}: 直近{len(rows)}本は日付あり")
        return 0
    out = subprocess.run([YTDLP, "--skip-download", "--no-warnings", "--print", "%(id)s %(upload_date)s",
                          "--sleep-requests", "1"] + [f"https://www.youtube.com/watch?v={v}" for v in need],
                         capture_output=True, text=True, timeout=1800)
    n = 0
    for line in out.stdout.splitlines():
        p = line.split()
        if len(p) == 2 and len(p[1]) == 8 and p[1].isdigit():
            con.execute("UPDATE video SET published=? WHERE video_id=? AND (published IS NULL OR published='')",
                        (f"{p[1][:4]}-{p[1][4:6]}-{p[1][6:]}", p[0]))
            n += 1
    con.commit()
    print(f"  {g[1]}: 直近{len(rows)}本のうち {n}本に日付を入れた")
    return 0


if __name__ == "__main__":
    sys.exit(main())
