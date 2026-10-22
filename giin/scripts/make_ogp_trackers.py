#!/usr/bin/env python3
"""国会トラッカーのテーマごとの OGP 画像（img/og/tracker-<key>.png、1200×630）。

議員発言ログ（make_ogp_all.py）からも、Kurage 国会トラッカー単体からも使う。
単体の設置では、画像の左下に出す公開URLを TRACKER_PUBLIC_URL（例: https://example.com/kokkai/）で決める。

  /usr/bin/python3 scripts/make_ogp_trackers.py
"""
import json, os, sqlite3, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "scripts"))
from make_ogp_all import card, OUT, DB   # noqa: E402


def make(con, base: str) -> int:
    """base は画像に出すURLの頭（例: xb4g.com/giin）。作った枚数を返す"""
    os.makedirs(OUT, exist_ok=True)
    trackers = json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))
    has = con.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='tracker_speech'").fetchone()[0]
    n = 0
    for t in trackers:
        r = con.execute("""SELECT COUNT(*), SUM(kind='q'), SUM(kind='gov'),
                                  COUNT(DISTINCT CASE WHEN kind='q' THEN speaker END), MAX(date)
                           FROM tracker_speech WHERE tracker=?""", (t["key"],)).fetchone() if has else (0, 0, 0, 0, "")
        card(os.path.join(OUT, f"tracker-{t['key']}.png"),
             "国会会議録から、全国の発言を機械的に集めました",
             t["name"] + "は、",
             "国会でどこまで来たか。",
             f"質疑 {int(r[1] or 0):,}件・答弁 {int(r[2] or 0):,}件・議員 {int(r[3] or 0)}人（{r[4] or ''}まで）",
             "だれが質問し、政府が何と答えたか。日付と会議録リンク。",
             "Kurage 国会トラッカー",
             f"{base.rstrip('/')}/tracker/{t['key']}")
        n += 1
    return n


if __name__ == "__main__":
    pub = os.environ.get("TRACKER_PUBLIC_URL", "")
    base = pub.split("://", 1)[-1] if pub else "example.com"
    con = sqlite3.connect(DB)
    print(f"{make(con, base)}枚 → {OUT}")
