#!/usr/bin/env python3
"""議員の公式Xの新着を取り込む。

**開発者APIもキーも使わない。** X の公式埋め込みウィジェットが読んでいるのと同じ
公開エンドポイントを読む:

    https://syndication.twitter.com/srv/timeline-profile/screen-name/<name>

返ってくる HTML の __NEXT_DATA__ に投稿が入っている（実測21件）。
ログインも要らず、自分のアカウントのセッションも使わない。
**自分のログインで他人のタイムラインを取りに行かない**——それは規約の線を越えるし、
アカウントを危険にさらす。ここは「Xが埋め込み用に公開しているもの」だけを読む。

持つのは**本文・日付・投稿ID・リポストかどうか**だけ。画像も動画も持たない。
表示は抜粋とXへのリンクで、全文はXで読んでもらう。要約はしない。

  /usr/bin/python3 scripts/fetch_x.py
"""
import json, os, re, sqlite3, subprocess, sys, time
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
LINKS = os.path.join(ROOT, "data", "links.json")
# 埋め込みウィジェットと同じ見え方で頼む。素っ気ないUAだと 429 を返しやすい（実測）
UA = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "ja,en;q=0.9",
    "Referer": "https://platform.twitter.com/",
}
BASE = "https://syndication.twitter.com/srv/timeline-profile/screen-name/"

SCHEMA = """
CREATE TABLE IF NOT EXISTS xpost(
  post_id TEXT PRIMARY KEY, giin_id INTEGER, screen_name TEXT, body TEXT,
  posted TEXT, is_repost INTEGER DEFAULT 0, fetched_at TEXT);
CREATE INDEX IF NOT EXISTS ix_xpost_giin ON xpost(giin_id, posted DESC);
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


def screen_name(url):
    m = re.search(r"(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})", url or "")
    return m.group(1) if m else None


def timeline(name, tries=4):
    """埋め込みが読んでいるのと同じ公開ページを取る。

    **curl で取る。** 同じURL・同じヘッダでも Python の urllib だと 429 が返り、
    curl だと 200 が返る（2026-09-14 実測。HTTP/2 と TLS の見え方の違いと思われる）。
    ここで粘らず、動く方で取る。
    """
    cmd = ["curl", "-sS", "--compressed", "--max-time", "40",
           "-A", ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"),
           "-H", "Accept-Language: ja,en;q=0.9",
           "-H", "Referer: https://platform.twitter.com/",
           "-w", "\n%{http_code}", BASE + name]
    raw = ""
    for i in range(tries):
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        body, _, code = (r.stdout or "").rpartition("\n")
        if code.strip() == "200" and body:
            raw = body
            break
        if i == tries - 1:
            raise RuntimeError(f"HTTP {code.strip() or '?'}")
        wait = 30 * (i + 1)
        print(f"    HTTP {code.strip()}。{wait}秒待って再試行（{i+1}/{tries-1}）", flush=True)
        time.sleep(wait)
    m = re.search(r'<script id="__NEXT_DATA__" type="application/json">(.*?)</script>', raw, re.S)
    if not m:
        return []
    d = json.loads(m.group(1))
    ents = (d.get("props", {}).get("pageProps", {}).get("timeline", {}) or {}).get("entries") or []
    out = []
    for e in ents:
        t = (e.get("content") or {}).get("tweet") or {}
        pid = t.get("id_str")
        text = t.get("full_text") or t.get("text") or ""
        if not pid or not text:
            continue
        try:
            when = datetime.strptime(t.get("created_at", ""), "%a %b %d %H:%M:%S %z %Y")
            posted = when.astimezone().strftime("%Y-%m-%d %H:%M")
        except Exception:
            posted = ""
        out.append({
            "post_id": pid,
            "body": re.sub(r"\s+", " ", text).strip(),
            "posted": posted,
            # RT は本人の言葉ではないので、画面で見分けられるように印をつける
            "is_repost": 1 if text.startswith("RT @") else 0,
        })
    return out


def main():
    if not os.path.isfile(LINKS):
        print("data/links.json がありません", file=sys.stderr)
        return
    links = json.load(open(LINKS, encoding="utf-8"))
    con = db()
    slugs = {r[1]: r[0] for r in con.execute("SELECT id, slug FROM giin")}
    total = 0
    for slug, v in links.items():
        name = screen_name(v.get("x", ""))
        if not name or slug not in slugs:
            continue
        try:
            posts = timeline(name)
        except Exception as e:
            print(f"  ! {slug}: 取得できず {e}", file=sys.stderr)
            continue
        for p in posts:
            con.execute(
                "INSERT OR REPLACE INTO xpost(post_id,giin_id,screen_name,body,posted,is_repost,fetched_at)"
                " VALUES(?,?,?,?,?,?,datetime('now','localtime'))",
                (p["post_id"], slugs[slug], name, p["body"], p["posted"], p["is_repost"]))
        con.commit()
        own = sum(1 for p in posts if not p["is_repost"])
        print(f"  {slug:<20} {len(posts):>3}件（本人{own} / RT{len(posts)-own}）  @{name}")
        total += len(posts)
        time.sleep(20)   # 次の人まで間を空ける（IP単位で締まるため）
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('x_updated_at',datetime('now','localtime'))")
    con.commit()
    print(f"\n合計 {total}件  ({con.execute('SELECT COUNT(*) FROM xpost').fetchone()[0]}件 収録)")


if __name__ == "__main__":
    main()
