#!/usr/bin/env python3
"""議員の公式YouTubeチャンネルの新着を取り込む。

**APIキーは要らない。** チャンネルRSS
  https://www.youtube.com/feeds/videos.xml?channel_id=<ID>
で、タイトル・動画ID・公開日・サムネが取れる（実測）。
Data API の search.list は1日100回の枠があるが、こちらには要らない。

持つのは**タイトル・日付・動画ID・サムネURLだけ**。本文も字幕も持たない。
再生は YouTube の公式埋め込み（youtube-nocookie.com）に任せる。
（字幕はAPI仕様上、本人の認証なしには取れない。取ろうとしない。）

  /usr/bin/python3 scripts/fetch_youtube.py
"""
import json, os, re, sqlite3, sys, time, urllib.request
from html import unescape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
LINKS = os.path.join(ROOT, "data", "links.json")
UA = {"User-Agent": "Mozilla/5.0 (compatible; xb4g-giin/1.0; +https://xb4g.com/giin/)"}

SCHEMA = """
CREATE TABLE IF NOT EXISTS video(
  video_id TEXT PRIMARY KEY, giin_id INTEGER, title TEXT, published TEXT,
  thumb TEXT, channel TEXT, fetched_at TEXT);
CREATE INDEX IF NOT EXISTS ix_video_giin ON video(giin_id, published DESC);
"""


def db():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    con.executescript(SCHEMA)
    return con


def channel_id(url):
    """公式サイトに載っているURLから channel_id を得る。@handle は HTML から拾う。"""
    m = re.search(r"/channel/([\w-]+)", url)
    if m:
        return m.group(1)
    try:
        html = urllib.request.urlopen(
            urllib.request.Request(url, headers=UA), timeout=60).read().decode("utf-8", errors="ignore")
    except Exception:
        return None
    m = re.search(r'"(?:externalId|channelId)"\s*:\s*"(UC[\w-]+)"', html) \
        or re.search(r'channel_id=(UC[\w-]+)', html)
    return m.group(1) if m else None


def feed(cid):
    url = "https://www.youtube.com/feeds/videos.xml?channel_id=" + cid
    x = urllib.request.urlopen(
        urllib.request.Request(url, headers=UA), timeout=60).read().decode("utf-8", errors="ignore")
    ch = unescape((re.search(r"<title>(.*?)</title>", x, re.S) or ["", ""])[1]).strip()
    out = []
    for e in re.findall(r"(?s)<entry>(.*?)</entry>", x):
        g = lambda p: (re.search(p, e, re.S) or ["", ""])[1]  # noqa: E731
        vid = g(r"<yt:videoId>(.*?)</yt:videoId>")
        if not vid:
            continue
        out.append({
            "video_id": vid,
            "title": unescape(g(r"<title>(.*?)</title>")).strip(),
            "published": g(r"<published>(.*?)</published>")[:10],
            "thumb": (re.search(r'<media:thumbnail url="([^"]+)"', e) or ["", ""])[1],
            "channel": ch,
        })
    return out


def main():
    if not os.path.isfile(LINKS):
        print("data/links.json がありません（scripts/fetch_links.py で作る）", file=sys.stderr)
        return
    links = json.load(open(LINKS, encoding="utf-8"))
    con = db()
    slugs = {r[1]: r[0] for r in con.execute("SELECT id, slug FROM giin")}
    total = 0
    for slug, v in links.items():
        yt = v.get("youtube")
        if not yt or slug not in slugs:
            continue
        cid = v.get("youtube_channel_id") or channel_id(yt)
        if not cid:
            print(f"  ! {slug}: channel_id を取れず ({yt})", file=sys.stderr)
            continue
        v["youtube_channel_id"] = cid
        try:
            vids = feed(cid)
        except Exception as e:
            print(f"  ! {slug}: RSS取得できず {e}", file=sys.stderr)
            continue
        for x in vids:
            con.execute(
                "INSERT OR REPLACE INTO video(video_id,giin_id,title,published,thumb,channel,fetched_at)"
                " VALUES(?,?,?,?,?,?,datetime('now','localtime'))",
                (x["video_id"], slugs[slug], x["title"], x["published"], x["thumb"], x["channel"]))
        con.commit()
        print(f"  {slug:<20} {len(vids):>3}本  {vids[0]['channel'] if vids else ''}")
        total += len(vids)
        time.sleep(1)
    json.dump(links, open(LINKS, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('video_updated_at',datetime('now','localtime'))")
    con.commit()
    print(f"\n合計 {total}本  ({con.execute('SELECT COUNT(*) FROM video').fetchone()[0]}本 収録)")


if __name__ == "__main__":
    main()
