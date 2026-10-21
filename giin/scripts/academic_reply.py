#!/usr/bin/env python3
"""論文トラッカー（exbridge.jp/academic/）のページを貼れる X の返信候補を作る（2026-10-21 ユーザー指示）。

  /usr/bin/python3 scripts/academic_reply.py [--minutes 40] [--deploy] [--no-codex]

ふだんの X 返信候補（x_reply_draft.py）とは別のページにする。貼るページは3種類で、どれもカード画像がある:
  論文のページ   /academic/paper/<OpenAlex ID>.html   … 投稿の中身が論文の題名・要約に近いとき
  研究者のページ /academic/researcher/<crid>.html     … 投稿の中身が科研費の研究に近いとき・本人の名前が出るとき
  テーマのページ /academic/theme/<slug>.html          … 投稿にテーマの語（themes.json の x_rx）があるとき
集める投稿: Yahoo!リアルタイム検索（テーマの語・論文の告知の言葉・東海の大学の研究成果）と、東海の研究者の名簿
（data/x_tokai_academics.json）の時系列。文案は x_reply_draft と同じく codex で1回にまとめて書き、同じ検査を通す。
出力: outputs/x_reply_pick/<日付>/academic-<時分>.html（--deploy で proto の xreply-<token>/academic.html）
"""
import argparse, datetime, glob, html, json, os, re, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import x_reply_pick as X  # noqa: E402
import x_reply_draft as D  # noqa: E402

KP = "/home/kojima/work/kpayload/data/academic"
BASE = "https://exbridge.jp/academic"
MAX_ITEMS = 12
# 返信するのは、投稿から40分以内の、報道機関・東海の研究者・有名な経営者と起業家・フォロワーの多いアカウント（1万人以上）の投稿だけ
# （2026-10-21 ユーザー指示「返信候補は40分以内に投稿されたもの。ニュースとか研究者か、フォロワー数多い大きなアカウントの投稿にして」）
WITHIN_MIN = 40
MIN_FOLLOWERS = 10000
TH_P = float(os.environ.get("ACADEMIC_TH_P", "0.1"))   # 論文の似ている度合いの下限（珍しい組の重み付き。10/21 の試験で決めた）
TH_R = float(os.environ.get("ACADEMIC_TH_R", "0.1"))   # 研究者
# 論文の告知・研究成果の言葉（研究者本人や大学が論文を知らせる投稿）
ANNOUNCE = ["論文が掲載", "論文が出版", "論文が公開", "論文が受理", "論文がアクセプト", "研究成果 名古屋大学", "研究成果 岐阜大学",
            "研究成果 三重大学", "研究成果 名古屋工業大学", "研究成果 名古屋市立大学", "研究成果 豊橋技術科学大学"]
THEME_WORDS = ["PFAS", "有機フッ素化合物", "マイクロプラスチック", "生成AI 自治体", "生成AI 行政", "自治体DX", "AIエージェント 行政", "行政 デジタル化"]
TYPE_PAPER = "論文トラッカー：論文のページ（題名・要約・東海・関東の大学の著者・同じテーマの国会の議論。カードに論文の題名が出る）"
TYPE_PERSON = "論文トラッカー：研究者のページ（科研費の研究・同じテーマの国会の議論。カードに研究者の名前と大学が出る）"
TYPE_THEME = "論文トラッカー：テーマのページ（東海・関東の大学の科研費の研究・論文と、国会の質疑と答弁の件数。カードに件数が出る）"


def load():
    themes = json.load(open(os.path.join(KP, "themes.json"), encoding="utf-8"))
    sums = json.load(open(os.path.join(KP, "summaries.json"), encoding="utf-8"))
    papers, people, tpages = [], {}, []
    for th in themes:
        try:
            d = json.load(open(os.path.join(KP, th["slug"] + ".json"), encoding="utf-8"))
        except (OSError, ValueError):
            continue
        projs = [x for x in d["projects"] if not th.get("kaken_must") or re.search(th["kaken_must"], (x["title"] or "") + (x["outline"] or ""))]
        nkok = sum(k["count"] for k in d["kokkai"])
        tpages.append({"theme": th, "n_proj": len(projs), "n_work": len(d["works"]), "n_kok": nkok, "kokkai": d["kokkai"],
                       "url": f"{BASE}/theme/{th['slug']}.html"})
        for w in d["works"]:
            s = sums.get(w["id"], {})
            tok = [a for a in w["authors"] if a["tokai"]]
            papers.append({"theme": th, "id": w["id"], "title_ja": s.get("title_ja") or w["title"], "summary": s.get("summary_ja") or "",
                           "date": w["date"], "venue": w.get("venue") or "", "tokai": [f"{a['name']}（{a['tokai'][0]}）" for a in tok],
                           "url": f"{BASE}/paper/{w['id']}.html", "_bg": D.bigrams((s.get("title_ja") or "") + (s.get("summary_ja") or "") + (w["title"] or ""))})
        for x in projs:
            for r in x["researchers"]:
                if r.get("tokai") and r.get("crid") and r.get("name"):
                    q = people.setdefault(r["crid"], {"name": r["name"], "aff": r["affiliation"], "theme": th, "projects": [], "url": f"{BASE}/researcher/{r['crid']}.html", "_bg": set()})
                    q["projects"].append(x["title"])
                    q["_bg"] |= D.bigrams((x["title"] or "") + (x["outline"] or "")[:300])
    build_df(papers + list(people.values()))
    return themes, papers, people, tpages


DF, NDOC = {}, 1
OK2 = re.compile(r"[一-龥々ァ-ヶーA-Za-z]{2}")
# 地名・大学名・研究の一般語は、中身が近いかの手がかりにしない（「名古屋」で図書館の論文が藤前干潟の投稿に当たった）
STOP = set()
for _w in ["名古屋", "愛知", "岐阜", "三重", "東海", "大学", "大学院", "研究", "論文", "研究者", "研究室", "教授", "附属", "学部", "日本", "国内",
           "豊橋", "四日市", "鈴鹿", "中京", "南山", "名城", "中部", "金城", "椿山", "藤田", "技術", "科学", "工業", "市立", "県立"]:
    STOP |= {_w[i:i + 2] for i in range(len(_w) - 1)}


def build_df(entries):
    """論文・研究者の全体で、2文字の組がいくつの項目に出るか（どこにでも出る組は手がかりにしない）"""
    global NDOC
    NDOC = len(entries)
    for e in entries:
        for b in e["_bg"]:
            DF[b] = DF.get(b, 0) + 1


def sim(tb, bg):
    """珍しさで重み付けした2文字の組の、コサイン類似度（0〜1）。長い投稿ほど当たりやすくならないよう、両方の長さで割る。
    重なりが3組未満なら0"""
    import math
    w = lambda b: math.log((NDOC + 1) / (DF.get(b, 0) + 1)) if OK2.fullmatch(b) and b not in STOP else 0
    hit = [b for b in tb & bg if w(b) > 0]
    if len(hit) < 3:
        return 0
    nt = math.sqrt(sum(w(b) ** 2 for b in tb)) or 1
    nd = math.sqrt(sum(w(b) ** 2 for b in bg)) or 1
    return sum(w(b) ** 2 for b in hit) / (nt * nd)


def match(post, themes, papers, people, tpages):
    """投稿に貼れる論文のページと研究者のページを返す。テーマのページは候補にしない
    （2026-10-21 ユーザー指示「テーマじゃない」「全部のOGPを試したい」。テーマのページがあると codex が毎回それを選んだ）。
    投稿にテーマの語（x_rx）があれば、そのテーマの中で近い論文3本・研究者2人を必ず候補にする。無ければ全体から、近いものだけ"""
    text = post["text"]
    tb = D.bigrams(re.sub(r"https?://\S+|[#＃@]\S+", "", text))
    hit_th = {t["theme"]["slug"] for t in tpages if t["theme"].get("x_rx") and re.search(t["theme"]["x_rx"], text)}
    if hit_th:
        ps = sorted([p for p in papers if p["theme"]["slug"] in hit_th], key=lambda p: -sim(tb, p["_bg"]))[:3]
        qs = sorted([q for q in people.values() if q["theme"]["slug"] in hit_th], key=lambda q: -sim(tb, q["_bg"]))[:2]
    else:
        ps = [p for p in sorted(papers, key=lambda p: -sim(tb, p["_bg"]))[:2] if sim(tb, p["_bg"]) >= TH_P]
        qs = [q for q in sorted(people.values(), key=lambda q: -sim(tb, q["_bg"]))[:2] if sim(tb, q["_bg"]) >= TH_R]
    name_hit = [q for q in people.values() if q["name"].replace(" ", "") in (post.get("name") or "").replace(" ", "") + text.replace(" ", "")]
    links = [{"種類": TYPE_PAPER, "題名": p["title_ja"], "説明": f"{p['summary']}（{p['date']}・{p['venue']}）東海・関東の大学の著者: {'、'.join(p['tokai'][:3])}", "url": p["url"]} for p in ps]
    for q in name_hit + [q for q in qs if q not in name_hit]:
        links.append({"種類": TYPE_PERSON, "題名": f"{q['name']}（{q['aff']}）", "説明": f"{q['aff']}の{q['name']}さんの科研費の研究: " + "／".join(q["projects"][:2]), "url": q["url"]})
    return links[:6]


def business():
    """有名な起業家・上場企業の経営者（2026-10-21 ユーザー指示「上場企業の経営者」「有名起業家、フォロワー数多い起業家、経営者」）。
    名簿は data/x_big_business.json（本人確認したものだけ）"""
    try:
        return json.load(open(os.path.join(X.ROOT, "data", "x_big_business.json"), encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def news_handles():
    return json.load(open(os.path.join(X.ROOT, "data", "x_news_accounts.json"), encoding="utf-8"))["handles"]


def big_enough(p):
    return p.get("query") in ("from:報道一覧", "from:東海の研究者", "from:経営者・起業家") or X.is_academic(p) or (p.get("followers") or 0) >= MIN_FOLLOWERS


def collect(minutes):
    since = int(time.time()) - minutes * 60
    seen = {}
    for q in THEME_WORDS + ANNOUNCE:
        for x in X.yahoo_search(f"{q} since_time:{since}", since):
            if x.get("type") == "status" and (x.get("created_timestamp") or 0) >= since and not x.get("replying_to") and x["id"] not in seen:
                s = X.slim(x); s["query"] = q; seen[x["id"]] = s
        print(f"\r語 {len(seen)}件", end="", flush=True)
    print()
    for label, hs in (("from:東海の研究者", sorted(X.academics())), ("from:経営者・起業家", sorted(business())), ("from:報道一覧", news_handles())):
        for x in X.fx_timeline(hs, since, workers=6):
            if x["id"] not in seen:
                s = X.slim(x); s["query"] = label; seen[x["id"]] = s
    print(f"東海の研究者・経営者・報道を含めて {len(seen)}件")
    return list(seen.values())


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--minutes", type=int, default=WITHIN_MIN)
    ap.add_argument("--deploy", action="store_true")
    ap.add_argument("--no-codex", action="store_true", help="文案を作らず、当たったページだけを表示する（試し）")
    a = ap.parse_args()
    day = datetime.date.today().isoformat(); stamp = datetime.datetime.now().strftime("%H%M"); mmdd = day[5:7] + day[8:10]
    outdir = os.path.join(X.OUT, day); os.makedirs(outdir, exist_ok=True)
    themes, papers, people, tpages = load()
    shown_p = os.path.join(X.OUT, "academic_reply_shown.json")
    try:
        shown = set(json.load(open(shown_p, encoding="utf-8")))
    except (OSError, ValueError):
        shown = set()
    posts = [p for p in collect(a.minutes) if p["id"] not in shown and not re.search(D.SKIP, p["text"]) and big_enough(p)]
    items = []
    for p in posts:
        links = match(p, themes, papers, people, tpages)
        if not links:
            continue
        th = next((t for t in tpages if any(l["url"] in (t["url"],) or t["theme"]["name"] in l["説明"] or t["theme"]["name"] == l["題名"] for l in links)), None)
        th = th or next((t for t in tpages if any(pp["url"] == l["url"] and pp["theme"]["slug"] == t["theme"]["slug"] for l in links for pp in papers)), None)
        facts = {}
        if th:
            # テーマに結び付けた国会トラッカーから、投稿に近い答弁を3つ（数字・引用の検査の元になる）
            ans = []
            for k in th["theme"]["trackers"]:
                t = next((x for x in X.trackers() if x["key"] == k), None)
                if t:
                    ans += D.closest_answers(k, p["text"], t.get("words") or [t["short"]], k=2)
            for x in ans:
                x.pop("_sid", None)
            facts = {"テーマ": th["theme"]["name"], "テーマの件数": f"科研費の研究{th['n_proj']}件・論文{th['n_work']}本・国会の質疑と答弁{th['n_kok']}件", "発言": ans[:3]}
        items.append({"id": p["id"], "post": p, "r": {}, "what": ("論文トラッカー（東海・関東の大学の研究と国会の議論を並べた当社のページ）の、論文のページか研究者のページ。どちらもXにその論文・研究者のカードが出る。"
                               "リンク候補から、投稿の話題にいちばん近い論文か研究者を1つ選び、返信文の中でその論文の中身か研究者（大学名と名前）を具体的に紹介する"
                               "（「◯◯大学の◯◯さんは、◯◯を研究しています」のように）。紹介に使ってよいのは、リンク候補の説明にある題名・大学名・名前・研究の中身だけ。"
                               "候補がどれも投稿の話題と無関係なら選ばない（0）"),
                      "facts": facts, "url": "", "label": "論文トラッカー", "fb": "", "links": links, "ref": f"x-{(p['screen_name'] or 'x').lower()}-{mmdd}"})
    items.sort(key=lambda it: (it["post"]["query"] not in ("from:東海の研究者", "from:経営者・起業家"), -sum(1 for l in it["links"] if l["種類"] != TYPE_THEME), -(it["post"].get("views") or 0)))
    items = items[:MAX_ITEMS]
    print(f"貼れるページが当たった投稿 {len(items)}件")
    for it in items:
        print(f"  @{it['post']['screen_name']} {it['post']['text'][:40]!r} → " + " / ".join(l["種類"][7:12] + ":" + l["題名"][:20] for l in it["links"]))
    if a.no_codex:
        return
    # 候補が0件の回も、公開ページを「0件」に置き換える（前の回の候補が古いまま残らないように）
    texts, picks = D.codex_batch(items, os.path.abspath(outdir)) if items else ({}, {})
    cards = [c for c in D.finish(items, texts, picks) if D.age_min(c["p"]) <= a.minutes] if items else []
    out = os.path.join(outdir, f"academic-{stamp}.html")
    page = D.page(day, stamp, cards, None if cards else {"posts": len(posts), "v1000": 0, "prev": []})
    page = page.replace("<title>", "<title>論文トラッカー ", 1)
    open(out, "w", encoding="utf-8").write(page)
    json.dump(sorted(shown | {c["p"]["id"] for c in cards}), open(shown_p, "w", encoding="utf-8"))
    # メール用の要約（x_reply_jobs.run_academic_reply_job が読む）
    import urllib.parse
    json.dump([{"name": c["p"].get("name"), "screen_name": c["p"].get("screen_name"), "views": c["p"].get("views"), "url": c["p"].get("url"),
                "text": re.sub(r"\s+", " ", c["p"].get("text") or "")[:200], "label": c["label"], "academic": X.is_academic(c["p"]),
                "drafts": [d["text"] for d in c["drafts"]],
                "intents": ["https://x.com/intent/post?" + urllib.parse.urlencode({"in_reply_to": c["p"]["id"], "text": d["text"]}) for d in c["drafts"]]}
               for c in cards], open(out[:-5] + ".json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(out, f"{len(cards)}件")
    if a.deploy:
        import ftplib, io
        env = {m.group(1): m.group(2).strip("\"'") for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8") for m in [re.match(r"(FTP_[A-Z]+)=(.*)", ln.strip())] if m}
        token = open(os.path.join(X.OUT, ".token")).read().strip()
        d = f"/web/proto_exbridge_jp/xreply-{token}"
        f = ftplib.FTP_TLS(env["FTP_HOST"], timeout=60); f.login(env["FTP_USER"], env["FTP_PASS"]); f.prot_p()
        data = open(out, "rb").read()
        f.storbinary(f"STOR {d}/academic.html", io.BytesIO(data))
        f.storbinary(f"STOR {d}/academic-{day}-{stamp}.html", io.BytesIO(data))
        f.quit()
        print(f"https://proto.exbridge.jp/xreply-{token}/academic.html")


if __name__ == "__main__":
    main()
