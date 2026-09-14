#!/usr/bin/env python3
"""「ことがらの最近の動き」を取り込む。

**源は2つだけ。どちらも商用利用が明文で許されているものに限る。**

1. 国会の議案 — smartnews-smri/house-of-councillors の gian.csv（MIT）
   提出日・件名・種類・提出者・議案URL。**提出者が議員名なので45人と突き合わせられる。**
2. 省庁の報道発表 RSS — 公共データ利用規約 PDL1.0（出典記載で商用可）

**一般ニュースのRSSは使わない。** NHK は「個人の方の利用のためのみ。ブログや
プログラム等によって、商業目的での利用を含め再配信や再提供を許可するものでは
ありません」と明記している（2026-09-14 実測）。当社は商用サイトなので対象外。
Yahoo!・新聞社も同様の条件なので当たらない。

**見出し・日付・発表元・リンクだけを持つ。本文は取らない。要約も論評もしない。**
PDL1.0 は編集・加工した場合その旨の明記を求めるが、こちらは加工しないので
出典表示だけで足りる。

  /usr/bin/python3 scripts/fetch_news.py
  /usr/bin/python3 scripts/fetch_news.py --days 120
"""
import argparse, csv, hashlib, io, json, os, re, sqlite3, sys, time, urllib.request
from datetime import date, timedelta

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
UA = {"User-Agent": "xb4g-giin/1.0 (+https://xb4g.com/giin/)"}

GIAN_CSV = "https://raw.githubusercontent.com/smartnews-smri/house-of-councillors/main/data/gian.csv"

# 取れることが実測できた省庁だけ。403/404 のところは載せない（推測で並べない）
PRESS = [
    ("厚生労働省",   "https://www.mhlw.go.jp/stf/news.rdf"),
    ("国土交通省",   "https://www.mlit.go.jp/pressrelease.rdf"),
    ("総務省",       "https://www.soumu.go.jp/news.rdf"),
    ("内閣府",       "https://www.cao.go.jp/rss/news.rdf"),
    ("デジタル庁",   "https://www.digital.go.jp/rss/news.xml"),
    ("文部科学省",   "https://www.mext.go.jp/b_menu/news/index.rdf"),
]

SCHEMA = """
CREATE TABLE IF NOT EXISTS news(
  id TEXT PRIMARY KEY, source TEXT, title TEXT, url TEXT, date TEXT,
  publisher TEXT, kind TEXT, submitter TEXT, giin_id INTEGER, fetched_at TEXT);
CREATE INDEX IF NOT EXISTS ix_news_date ON news(date DESC);
CREATE INDEX IF NOT EXISTS ix_news_giin ON news(giin_id, date DESC);
CREATE TABLE IF NOT EXISTS news_theme(news_id TEXT, theme TEXT,
  PRIMARY KEY(news_id, theme));
CREATE INDEX IF NOT EXISTS ix_nt_theme ON news_theme(theme);
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


def get(url, enc=None):
    raw = urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60).read()
    if enc is None:
        enc = "shift_jis" if b"Shift_JIS" in raw[:200] or b"shift_jis" in raw[:200] else "utf-8"
    return raw.decode(enc, errors="ignore")


def nid(*parts):
    return hashlib.sha1("|".join(parts).encode()).hexdigest()[:16]


def untag(x):
    import html as H
    x = re.sub(r"<!\[CDATA\[(.*?)\]\]>", r"\1", x, flags=re.S)
    return re.sub(r"\s+", " ", H.unescape(re.sub(r"<[^>]+>", "", x))).strip()


# ------------------------------------------------------------------ 議案

def fetch_gian(con, since):
    body = get(GIAN_CSV, "utf-8")
    rows = list(csv.DictReader(io.StringIO(body)))
    giin = {r["plain"]: r["id"] for r in
            con.execute("SELECT id, plain FROM giin").fetchall()
            } if False else {r[1]: r[0] for r in con.execute("SELECT id, plain FROM giin")}
    n = 0
    for r in rows:
        d = (r.get("議案審議情報一覧 - 提出日") or "").strip()
        if not d or d < since:
            continue
        title = (r.get("件名") or "").strip()
        url = (r.get("議案URL") or "").strip()
        if not title:
            continue
        sub = (r.get("議案審議情報一覧 - 提出者") or
               r.get("議案審議情報一覧 - 発議者") or "").strip()
        # 「古川元久君   外2名」から名前だけ取り出して45人と突き合わせる
        gid = None
        head = re.split(r"君|\s", sub)[0].strip() if sub else ""
        if head and head in giin:
            gid = giin[head]
        i = nid("gian", title, d)
        con.execute(
            "INSERT OR REPLACE INTO news(id,source,title,url,date,publisher,kind,submitter,giin_id,fetched_at)"
            " VALUES(?,?,?,?,?,?,?,?,?,datetime('now','localtime'))",
            (i, "gian", title, url, d, "国会", (r.get("種類") or "").strip(), sub, gid))
        n += 1
    return n


# ------------------------------------------------------------------ 省庁の報道発表

def fetch_press(con, since):
    n = 0
    for pub, url in PRESS:
        try:
            s = get(url)
        except Exception as e:
            print(f"  ! {pub} 取得できず: {e}", file=sys.stderr)
            continue
        got = 0
        for block in re.findall(r"(?is)<item[\s>](.*?)</item>", s):
            t = re.search(r"(?is)<title[^>]*>(.*?)</title>", block)
            l = re.search(r"(?is)<link[^>]*>(.*?)</link>", block)
            d = re.search(r"(?is)<dc:date[^>]*>(.*?)</dc:date>", block) \
                or re.search(r"(?is)<pubDate[^>]*>(.*?)</pubDate>", block)
            if not t:
                continue
            title = untag(t.group(1))
            link = untag(l.group(1)) if l else ""
            ds = untag(d.group(1)) if d else ""
            m = re.search(r"(\d{4})-(\d{2})-(\d{2})", ds)
            if m:
                day = m.group(0)
            else:
                ts = None
                if ds:
                    try:
                        from email.utils import parsedate_to_datetime
                        ts = parsedate_to_datetime(ds)
                    except Exception:
                        ts = None
                day = ts.strftime("%Y-%m-%d") if ts else date.today().isoformat()
            if day < since or not title:
                continue
            i = nid("press", pub, title, day)
            con.execute(
                "INSERT OR REPLACE INTO news(id,source,title,url,date,publisher,kind,submitter,giin_id,fetched_at)"
                " VALUES(?,?,?,?,?,?,?,?,NULL,datetime('now','localtime'))",
                (i, "press", title, link, day, pub, "報道発表", ""))
            got += 1
        print(f"  {pub:<12} {got:>4}件")
        n += got
        time.sleep(1)
    return n


# ------------------------------------------------------------------ ことがらの割り当て

def assign_themes(con):
    themes = json.load(open(os.path.join(ROOT, "data", "themes.json"), encoding="utf-8"))
    con.execute("DELETE FROM news_theme")
    hit = 0
    for t in themes:
        for w in t["words"]:
            con.execute(
                "INSERT OR IGNORE INTO news_theme(news_id, theme)"
                " SELECT id, ? FROM news WHERE title LIKE ?", (t["slug"], "%" + w + "%"))
    hit = con.execute("SELECT COUNT(*) FROM news_theme").fetchone()[0]
    return hit


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--days", type=int, default=365)
    a = ap.parse_args()
    since = (date.today() - timedelta(days=a.days)).isoformat()
    con = db()
    print(f"{since} 以降を取り込みます")
    print("— 国会の議案（smartnews-smri, MIT）—")
    g = fetch_gian(con, since)
    con.commit()
    print(f"  議案 {g}件")
    print("— 省庁の報道発表（PDL1.0）—")
    p = fetch_press(con, since)
    con.commit()
    print("— ことがらの割り当て —")
    h = assign_themes(con)
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('news_updated_at',datetime('now','localtime'))")
    con.commit()
    tot = con.execute("SELECT COUNT(*) FROM news").fetchone()[0]
    linked = con.execute("SELECT COUNT(*) FROM news WHERE giin_id IS NOT NULL").fetchone()[0]
    print(f"\n合計 {tot}件（議案{g} / 報道発表{p}）  ことがら割当 {h}件  45人に紐づいた議案 {linked}件")
    for r in con.execute("SELECT theme, COUNT(*) c FROM news_theme GROUP BY theme ORDER BY c DESC LIMIT 8"):
        print(f"   {r[0]:<18} {r[1]:>4}件")


if __name__ == "__main__":
    main()
