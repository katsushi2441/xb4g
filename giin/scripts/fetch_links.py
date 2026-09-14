#!/usr/bin/env python3
"""議員の公式サイトから、公式SNSのURLを取り出して data/links.json に入れる。

**APIは使わない。** 公式サイトに本人が載せているリンクを読むだけ。
XのAPIは従量課金だし、YouTubeのAPIキーも要らない（新着はチャンネルRSSで取れる）。

**なりすましを載せないための決め事:**
  - **議員本人の公式サイトに載っているリンクだけ**を採る。検索結果から拾わない。
  - どこで確認したか（source）を必ず一緒に保存する。
  - 見つからない人は空のままにする。埋めない。

  /usr/bin/python3 scripts/fetch_links.py mizuno-koichi https://mizuno.ne.jp/
  /usr/bin/python3 scripts/fetch_links.py --list
"""
import json, os, re, sys, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LINKS = os.path.join(ROOT, "data", "links.json")
UA = {"User-Agent": "Mozilla/5.0 (compatible; xb4g-giin/1.0; +https://xb4g.com/giin/)"}

# 拾う先と、正規化のしかた
PATTERNS = [
    # 終端は行末だけでなく、HTMLの引用符や > も見る（href="...twitter.com/name" で外していた）
    ("x",         r"https?://(?:www\.)?(?:x|twitter)\.com/([A-Za-z0-9_]{1,15})(?=[/?#\"'\s>]|$)"),
    ("youtube",   r"https?://(?:www\.)?youtube\.com/(channel/[\w-]+|@[\w.-]+|c/[\w-]+|user/[\w-]+)"),
    ("instagram", r"https?://(?:www\.)?instagram\.com/([A-Za-z0-9_.]+)/?"),
    ("facebook",  r"https?://(?:www\.)?facebook\.com/([A-Za-z0-9_.\-]+)/?"),
    ("line",      r"https?://line\.me/ti/p/(@?[\w.\-]+)"),
    ("note",      r"https?://note\.com/([A-Za-z0-9_]+)(?=[/?#\"'\s>]|$)"),
]


def load():
    if os.path.isfile(LINKS):
        return json.load(open(LINKS, encoding="utf-8"))
    return {}


def save(d):
    json.dump(d, open(LINKS, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    open(LINKS, "a", encoding="utf-8").write("\n")


def scrape(url):
    html = urllib.request.urlopen(
        urllib.request.Request(url, headers=UA), timeout=60).read().decode("utf-8", errors="ignore")
    found = {}
    for key, pat in PATTERNS:
        for m in re.finditer(pat, html):
            # サイト内の共有ボタン（intent/share）は本人のアカウントではない
            if re.search(r"/(intent|share|sharer)", m.group(0)):
                continue
            if key == "youtube" and "/watch" in m.group(0):
                continue
            found.setdefault(key, m.group(0).rstrip("/"))
    return found


def main():
    d = load()
    if "--list" in sys.argv:
        for slug, v in sorted(d.items()):
            got = [k for k in ("site", "x", "youtube", "instagram", "facebook", "line") if v.get(k)]
            print(f"  {slug:<22} {', '.join(got) or '(なし)'}")
        print(f"  計 {len(d)}人")
        return
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(1)
    slug, site = sys.argv[1], sys.argv[2]
    found = scrape(site)
    rec = d.get(slug, {})
    rec["site"] = site.rstrip("/")
    rec.update(found)
    rec["source"] = site.rstrip("/")          # どこで確認したか
    rec["checked_at"] = __import__("datetime").date.today().isoformat()
    d[slug] = rec
    save(d)
    print(f"{slug}:")
    for k, v in rec.items():
        print(f"  {k:<12} {v}")


if __name__ == "__main__":
    main()
