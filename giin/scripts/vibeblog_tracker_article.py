#!/usr/bin/env python3
"""国会トラッカーのデータから、vibeblog（exbridge.jp）に「◯◯の国会答弁まとめ」の記事を書く。

**なぜ要るか（2026-10-13）**: 国会トラッカー（xb4g.com/giin）は X からは1日数百人来るが、検索からは13日で7人。
狙っていた語（年収の壁110,000・不登校74,000）はニュースや省庁が上位を取っていて、できて1か月の xb4g.com は
100位にも入らない。検索で読まれている exbridge.jp の vibeblog に、会議録の実データでまとめた記事を置き、
トラッカーへ送る。

数字・答弁の抜粋はすべて tracker_speech（国会会議録の全文）から機械的に取る。外へのリンクは貼らない
（会議録の出典は文中に書く）。

  /usr/bin/python3 scripts/vibeblog_tracker_article.py <トラッカーのキー>... [--date 2026-10-13]
  出力: /home/kojima/work/vwork/blog/<日付>-kokkai-toben-<キー>.md
"""
import collections
import datetime
import json
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
OUT = "/home/kojima/work/vwork/blog"
args = [a for a in sys.argv[1:] if not a.startswith("--")]
day = sys.argv[sys.argv.index("--date") + 1] if "--date" in sys.argv else datetime.date.today().isoformat()
if "--date" in sys.argv:
    args.remove(day)

T = {t["key"]: t for t in json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))}
c = sqlite3.connect(DB)
c.row_factory = sqlite3.Row


def jd(s):
    y, m, d = s[:10].split("-")
    return f"{int(y)}年{int(m)}月{int(d)}日"


def party(k):
    # 会派名は国会ごとに表記が揺れるので、主な党の名前に寄せて数える
    k = k or ""
    for name, pat in (("自民党", "自由民主"), ("立憲民主党", "立憲"), ("国民民主党", "国民民主"), ("公明党", "公明"),
                      ("日本維新の会", "維新"), ("共産党", "共産"), ("れいわ新選組", "れいわ"), ("参政党", "参政"),
                      ("チームみらい", "チームみらい"), ("中道改革連合", "中道"), ("社民党", "社会民主"),
                      ("日本保守党", "日本保守"), ("NHK党", "ＮＨＫ|NHK")):
        if re.search(pat, k):
            return name
    return "その他・無所属" if k else "不明"


def excerpt(body, words, n=170):
    body = re.sub(r"\s+", "", body.replace("\r\n", ""))
    body = re.sub(r"^○[^　]+　", "", body)
    pos = -1
    for w in words:
        for part in w.split():
            i = body.find(part)
            if i >= 0 and (pos < 0 or i < pos):
                pos = i
    pos = max(0, pos - 40)
    s = body[pos:pos + n]
    return ("…" if pos > 0 else "") + s + ("…" if pos + n < len(body) else "")


def article(key):
    t = T[key]
    w = t.get("seo_word") or t.get("short") or t["name"]
    rows = c.execute("SELECT * FROM tracker_speech WHERE tracker=? ORDER BY date", (key,)).fetchall()
    q = [r for r in rows if r["kind"] == "q"]
    g = [r for r in rows if r["kind"] == "gov"]
    if not q:
        raise SystemExit(f"{key}: 質疑が無い")
    years = collections.Counter(r["date"][:4] for r in q)
    parties = collections.Counter(party(r["kaiha_at"]) for r in q)
    people = collections.Counter((r["speaker"], party(r["kaiha_at"])) for r in q)
    # 同じ人が会派を移っている場合は、いちばん多い党で1行にまとめる
    per = collections.defaultdict(collections.Counter)
    for (sp, pa), n in people.items():
        per[sp][pa] += n
    top = sorted(((sum(v.values()), sp, v.most_common(1)[0][0]) for sp, v in per.items()), reverse=True)[:8]
    y_last = max(years)
    recent_g = list(reversed(g))[:3]
    first_q = q[0]
    ref = f"vibeblog-toben-{key}"
    url = f"https://xb4g.com/giin/tracker/{key}#ref={ref}"
    nq, ng, nm = len(q), len(g), len(per)
    title = f"{w}の国会答弁まとめ——だれが質問し、政府は何と答えたか（質疑{nq:,}件・答弁{ng:,}件）"
    seo = f"{w}の国会答弁まとめ｜質問した議員と政府の答え"
    if len(seo) > 32:
        seo = f"{w}の国会答弁まとめ"
    desc = (f"{w}について、国会で全国の議員が質問した{nq:,}件と政府の答弁{ng:,}件を、国会会議録から集めて数えました"
            f"（{jd(rows[0]['date'])}〜{jd(rows[-1]['date'])}）。年ごと・会派ごとの件数、多く質問した議員、最近の政府の答弁をまとめています。")
    L = []
    L += ["---",
          f'title: "{title}"',
          f'title_aixsns: "{w}は国会でどう議論されてきたか。会議録から質疑{nq:,}件・政府答弁{ng:,}件を集め、年ごと・会派ごとの件数と、多く質問した議員、最近の答弁をまとめました"',
          f'title_hatena: "{w}の国会答弁まとめ：質疑{nq:,}件・答弁{ng:,}件を会議録から"',
          f'title_blogger: "{w}は国会でどう議論されたか（会議録の集計）"',
          f"date: {day}",
          "layout: default",
          f"permalink: /blog/{day}-kokkai-toben-{key}.html",
          f"tags: [{w}, 国会答弁, 国会質疑, 国会会議録, 国会トラッカー]",
          f'description: "{desc}"',
          f'seo_title: "{seo}"',
          f'head_keyword: "{w} 国会答弁"',
          "---", ""]
    L.append(f"名古屋でAIシステム開発の会社をやっています。国会会議録から、テーマごとに全国の議員の質疑と政府の答弁を集める"
             f"「国会トラッカー」を作っています。このページは、そのデータから{w}についてまとめたものです。")
    L.append("")
    L.append(f"- [{t['name']}：国会の質疑と答弁（国会トラッカー）]({url})")
    L.append("")
    L.append(f"## 1. {w}は国会でどれだけ議論されてきたか")
    L.append("")
    L.append(t["lead"])
    L.append("")
    L.append(f"国会会議録から、{w}にふれた発言を集めると、**議員の質疑が{nq:,}件、政府の答弁が{ng:,}件**ありました"
             f"（{jd(rows[0]['date'])}〜{jd(rows[-1]['date'])}）。質問した議員は{nm}人です。")
    L.append("")
    L.append("| 年 | 質疑の件数 |")
    L.append("|---|---:|")
    for y in sorted(years):
        L.append(f"| {y}年 | {years[y]:,} |")
    L.append("")
    peak = max(years, key=years.get)
    L.append(f"いちばん多かったのは{peak}年の{years[peak]:,}件です。{y_last}年は{years[y_last]:,}件でした"
             f"（{y_last}年は{jd(rows[-1]['date'])}までの分）。")
    L.append("")
    L.append(f"## 2. どの会派が質問しているか")
    L.append("")
    L.append("| 会派 | 質疑の件数 | 割合 |")
    L.append("|---|---:|---:|")
    for pa, n in parties.most_common(8):
        L.append(f"| {pa} | {n:,} | {n * 100 / nq:.0f}% |")
    L.append("")
    L.append("会派の名前は国会ごとに表記が変わるので、主な党の名前に寄せて数えています。")
    L.append("")
    L.append(f"## 3. 多く質問した議員")
    L.append("")
    L.append("| 議員 | 会派 | 質疑の件数 |")
    L.append("|---|---|---:|")
    for n, sp, pa in top:
        L.append(f"| {sp} | {pa} | {n:,} |")
    L.append("")
    L.append(f"会議録で、{w}にふれた質疑がいちばん早かったのは、{jd(first_q['date'])}の{first_q['meeting']}での{first_q['speaker']}議員です。")
    L.append("")
    L.append(f"## 4. 最近の政府の答弁")
    L.append("")
    for r in recent_g:
        L.append(f"**{jd(r['date'])}・{r['meeting']}（{r['speaker']}）**")
        L.append("")
        L.append(f"> {excerpt(r['body'], t['words'])}")
        L.append("")
    L.append("答弁は会議録の本文から、テーマの言葉のまわりを抜き出したものです。全文は国会会議録検索システムで読めます。")
    L.append("")
    L.append(f"## 5. 国会で問われている論点")
    L.append("")
    for x in t.get("questions", []):
        L.append(f"- {x}")
    L.append("")
    L.append(f"それぞれの論点について、だれがいつ質問し、政府が何と答えたかは、国会トラッカーで日付順に読めます。")
    L.append("")
    L.append(f"- [{t['name']}：国会の質疑と答弁]({url})")
    L.append(f"- [国会答弁の検索（{len(T)}テーマ）](https://xb4g.com/giin/tracker#ref={ref})")
    L.append("")
    L.append("## よくある質問")
    L.append("")
    L.append(f"### {w}について、国会で何件の質問がありましたか？")
    L.append("")
    L.append(f"国会会議録で{w}にふれた議員の質疑は{nq:,}件、政府の答弁は{ng:,}件です（{jd(rows[0]['date'])}〜{jd(rows[-1]['date'])}）。")
    L.append("")
    L.append(f"### {w}について、国会でいちばん多く質問した議員はだれですか？")
    L.append("")
    L.append(f"{top[0][1]}議員（{top[0][2]}）の{top[0][0]:,}件がいちばん多く、次が{top[1][1]}議員（{top[1][2]}）の{top[1][0]:,}件です。")
    L.append("")
    L.append(f"### {w}について、政府は最近何と答えていますか？")
    L.append("")
    L.append(f"いちばん新しい答弁は、{jd(recent_g[0]['date'])}の{recent_g[0]['meeting']}での{recent_g[0]['speaker']}の答弁です。"
             f"抜粋は上の「最近の政府の答弁」にあります。")
    L.append("")
    path = os.path.join(OUT, f"{day}-kokkai-toben-{key}.md")
    open(path, "w", encoding="utf-8").write("\n".join(L))
    return path


for k in args:
    print(article(k))
