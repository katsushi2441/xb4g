#!/usr/bin/env python3
"""Kurage 国会ファクト（xb4g.com/fact/）を作る。

問いへの答えは data/questions.json に人が書いたもの（会議録の原文で確かめた範囲だけ）。
党ごとの件数・各党の最近の質疑・政府の最近の答弁は、国会トラッカーの表 tracker_speech から機械的に並べる。
個人の投稿は載せない。「デマ」「誤り」のような判定のラベルは付けない。

  /usr/bin/python3 fact/scripts/build.py      # fact/out/ に作る
  /usr/bin/python3 fact/scripts/deploy.py     # xb4g.com/fact/ へ
"""
import html, json, re, sqlite3, urllib.parse
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "out"
DB = ROOT.parent / "giin" / "data" / "giin.sqlite"
BASE = "https://xb4g.com/fact/"
SITE = "Kurage 国会ファクト"
TAG = "問いに、国会の記録で答える"
CSS = (ROOT / "data" / "giin.css").read_text()
ABOUT = (ROOT / "data" / "exb_about.html").read_text()
e = lambda s: html.escape(str(s), quote=True)

PARTIES = [("国民民主", "国民民主"), ("自由民主", "自民"), ("立憲", "立憲民主"), ("維新", "維新"), ("公明", "公明"),
           ("共産", "共産"), ("中道", "中道改革連合"), ("れいわ", "れいわ"), ("参政", "参政"), ("社会民主", "社民"),
           ("社民", "社民"), ("みらい", "チームみらい"), ("保守", "日本保守"), ("民主", "旧民主・民進"), ("希望", "希望")]


def party(k: str) -> str:
    for w, n in PARTIES:
        if w in (k or ""):
            return n
    return "その他・無所属"


def clean(b: str) -> str:
    b = re.sub(r"^○[^\s　]+(?:君)?[\s　]*", "", (b or "").replace("\r", "").replace("　", ""))
    return re.sub(r"\s+", "", b)


def snip(b: str, terms, n=150) -> str:
    t = clean(b)
    pos = min([t.find(w) for w in terms if t.find(w) >= 0] or [0])
    s = max(0, pos - 40)
    return ("…" if s else "") + t[s:s + n] + ("…" if s + n < len(t) else "")


def jp(d: str) -> str:
    y, m, dd = d.split("-")
    return f"{int(y)}年{int(m)}月{int(dd)}日"


def head(title, desc, url, ld, ref):
    share_text = f"【{SITE}】{title}"
    x = "https://x.com/intent/post?text=" + urllib.parse.quote(share_text) + "&url=" + urllib.parse.quote(url + "#ref=x-share-" + ref)
    copy = share_text + "\n" + url + "#ref=copy-" + ref
    corp = "fact-corpbar"
    return f"""<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{e(title)}</title><meta name="description" content="{e(desc)}"><link rel="canonical" href="{e(url)}">
<meta property="og:type" content="website"><meta property="og:title" content="{e(title)}"><meta property="og:description" content="{e(desc)}">
<meta property="og:url" content="{e(url)}"><meta property="og:image" content="https://xb4g.com/giin/ogp.png?b=k4"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json">{json.dumps(ld, ensure_ascii=False)}</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments)}}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&display=swap" rel="stylesheet">
<style>{CSS}
.fq{{background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);padding:18px 20px;margin:14px 0}}
.fq h2{{margin-top:0}}
.lead-ans{{font-size:17px;line-height:1.85;background:var(--mint-xl);border-left:4px solid var(--teal);padding:14px 16px;border-radius:0 12px 12px 0;margin:14px 0}}
.pt{{border-top:1px solid var(--line);padding:14px 0}}.pt:first-child{{border-top:0}}
.pt b{{display:block;font-size:16px;line-height:1.6;margin-bottom:6px}}
blockquote.kq{{margin:6px 0;padding:10px 14px;background:#f6fbf9;border-left:3px solid var(--mint);font-size:14.5px;line-height:1.85;border-radius:0 8px 8px 0}}
.src{{font-size:13px;color:var(--gray)}}.src a{{font-weight:700}}
.prow{{display:grid;grid-template-columns:9.5em 1fr 3.5em;gap:8px;align-items:center;font-size:14px;margin:5px 0}}
.prow .bar{{height:12px;background:var(--teal);border-radius:6px}}
.prow .n{{text-align:right;font-variant-numeric:tabular-nums}}
.rule li{{margin-bottom:6px}}
.qlist a.qi{{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;margin:10px 0;box-shadow:var(--sh)}}
.qlist a.qi:hover{{border-color:var(--teal)}}.qlist .qt{{font-size:18px;font-weight:900;line-height:1.5;color:var(--navy)}}.qlist .qs{{font-size:14px;color:var(--gray);margin-top:4px}}
@media (max-width:560px){{.prow{{grid-template-columns:7.5em 1fr 3em;font-size:13px}}.lead-ans{{font-size:16px}}}}
</style></head><body>
<div class="corpbar"><div class="inner"><a class="cb-co" href="https://exbridge.jp/#ref={corp}"><img src="https://exbridge.jp/images/logo-mark-128.png" width="20" height="20" alt="">株式会社エクスブリッジ</a><nav class="cb-links" aria-label="運営会社"><a href="https://exbridge.jp/ai-it-komon.html#ref={corp}">AI-IT顧問</a><a href="https://exbridge.jp/ai-sns-komon.html#ref={corp}">AI-SNS顧問</a><a href="https://kurage.exbridge.jp/reseller.html#ref={corp}">代理店募集</a><a href="https://kappstore.exbridge.jp/#ref={corp}">Kurage App Store</a></nav></div></div>
<header class="site"><div class="inner"><a class="brand" href="{BASE}"><img class="mark" src="https://xb4g.com/giin/img/kurage-mascot-128.png" width="34" height="38" alt="Kurage"><span><b class="kg">Kurage</b> 国会ファクト<small>{TAG}</small></span></a>
<nav class="gnav" aria-label="サイト内"><a href="{BASE}">問いの一覧</a><a href="{BASE}#rule">決まり</a><a href="https://xb4g.com/giin/tracker#ref=fact-nav">国会トラッカー</a><a href="https://xb4g.com/giin/#ref=fact-nav">国会議員 発言ログ</a></nav></div></header>
<main class="wrap">
<SHARE>""".replace("<SHARE>", "<!--share-->"), x, copy


def share_bar(x, copy):
    return ('<div class="sharebar" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:14px 0 6px">'
            '<span style="font-size:14px;font-weight:700;color:var(--gray)">このページを共有</span>'
            f'<a class="btn" style="padding:8px 16px;font-size:14px" href="{e(x)}" target="_blank" rel="noopener">Xで共有</a>'
            f'<button class="btn o" style="padding:8px 16px;font-size:14px" type="button" data-copy="{e(copy)}" onclick="fCopy(this)">タイトルとURLをコピー</button></div>'
            '<script>function fCopy(b){var t=b.getAttribute("data-copy");function ok(){b.textContent="コピーしました"}'
            'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok)}else{var a=document.createElement("textarea");'
            'a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");a.remove();ok()}}</script>')


def foot():
    return ("</main><footer class=\"site\"><div class=\"inner\">"
            f"<p style=\"font-size:13px;color:var(--gray)\">{SITE}は、国立国会図書館の国会会議録検索システムの発言を出典に、問いに対する国会の記録を並べています。"
            "特定の政党・候補者を支援するものではありません。誤りや載せ方へのご意見は "
            "<a href=\"https://exbridge.jp/contact.php#ref=fact-foot\">お問い合わせ</a> からお知らせください。直した内容は各ページの「確かめた日」とあわせて残します。</p>"
            + ABOUT + "</div></footer>"
            '<script>(function(){var s=document.createElement("script");s.src="https://xb4g.com/simpletrack.php?url="+encodeURIComponent(location.href)+"&ref="+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>'
            '<script src="https://kurage.exbridge.jp/partner-bar.js" defer></script>'
            "</body></html>")


def page(title, desc, url, ld, ref, body):
    h, x, copy = head(title, desc, url, ld, ref)
    # 共有の行は最初の見出しの直後（全ページ共通の決まり）
    i = body.find("</h1>")
    body = body[:i + 5] + share_bar(x, copy) + body[i + 5:] if i >= 0 else share_bar(x, copy) + body
    return h.replace("<!--share-->", "") + body + foot()


def build_q(c, q):
    url = BASE + q["slug"] + "/"
    t, terms = q["tracker"], q["terms"]
    rows = c.execute("select date,house,meeting,speaker,kaiha_at,kind,body,speech_url from tracker_speech where tracker=? and body is not null", (t,)).fetchall()
    qs = [r for r in rows if r[5] == "q"]
    gov = [r for r in rows if r[5] == "gov"]
    members = len({r[3] for r in qs})
    first = min(r[0] for r in rows)
    cnt = {}
    for r in qs:
        cnt[party(r[4])] = cnt.get(party(r[4]), 0) + 1
    top = sorted(cnt.items(), key=lambda kv: -kv[1])[:8]
    mx = top[0][1] if top else 1
    bars = "".join(f'<div class="prow"><span>{e(p)}</span><span class="bar" style="width:{max(2, round(n / mx * 100))}%"></span><span class="n">{n:,}</span></div>' for p, n in top)

    def hit(r, need=2):
        # 語が2回以上出てくる発言だけ（ついでに触れただけの発言を拾わない）
        b = clean(r[6])
        return sum(b.count(w) for w in terms) >= need
    # 各党の最近の質疑（党ごとに1件・語を含むもの）
    seen, recent_q = set(), []
    for r in sorted(qs, key=lambda r: r[0], reverse=True):
        p = party(r[4])
        if p in seen or not hit(r):
            continue
        seen.add(p); recent_q.append(r)
        if len(recent_q) == 8:
            break
    recent_g = [r for r in sorted(gov, key=lambda r: r[0], reverse=True) if hit(r)][:3]

    def item(r, who):
        return (f'<div class="pt"><span class="src">{jp(r[0])}・{e(r[1])} {e(r[2])}・<b>{e(r[3])}</b>（{e(who)}）</span>'
                f'<blockquote class="kq">{e(snip(r[6], terms))}</blockquote>'
                f'<span class="src"><a href="{e(r[7])}" target="_blank" rel="noopener">会議録で読む</a></span></div>')

    pts = "".join(f'<div class="pt"><b>{e(p["text"])}</b><blockquote class="kq">{e(p["quote"])}</blockquote>'
                  f'<span class="src">{jp(p["date"])}・{e(p["meeting"])}・{e(p["speaker"])}　<a href="{e(p["url"])}" target="_blank" rel="noopener">会議録で読む</a></span></div>'
                  for p in q["points"])
    opn = "".join(f'<li>{e(o["text"])}（<a href="{e(o["url"])}" target="_blank" rel="noopener">会議録</a>）</li>' for o in q["open"])
    desc = q["lead"][:118]
    faq = {"@type": "FAQPage", "mainEntity": [{"@type": "Question", "name": q["question"], "acceptedAnswer": {"@type": "Answer", "text": q["lead"]}}]}
    ld = {"@context": "https://schema.org", "@graph": [
        {"@type": "WebPage", "@id": url, "url": url, "name": q["title"], "description": desc, "inLanguage": "ja", "dateModified": q["checked_at"],
         "isPartOf": {"@type": "WebSite", "name": SITE, "url": BASE}, "publisher": {"@type": "Organization", "name": "株式会社エクスブリッジ", "url": "https://exbridge.jp/"}},
        faq,
        {"@type": "BreadcrumbList", "itemListElement": [{"@type": "ListItem", "position": 1, "name": SITE, "item": BASE}, {"@type": "ListItem", "position": 2, "name": q["question"], "item": url}]}]}
    body = f"""<section class="hero"><p class="crumb"><a href="{BASE}">{SITE}</a> › 問い</p>
<h1>{e(q["question"])}</h1>
<p class="lead-ans">{e(q["lead"])}</p>
<p class="src">確かめた日：{jp(q["checked_at"])}。答えの文は、下に引いた会議録の原文の範囲で書いています。</p></section>
<section class="fq"><h2>国会の記録</h2>{pts}</section>
<section class="fq"><h2>まだ決まっていないこと・議論が続いていること</h2><ul>{opn}</ul></section>
<section class="fq"><h2>この話題を国会で取り上げた党</h2>
<p class="src">{jp(first)}から、この話題にふれた議員の質疑は{len(qs):,}件、政府の答弁は{len(gov):,}件、取り上げた議員は{members}人です（会派ごとの質疑の件数）。</p>{bars}</section>
<section class="fq"><h2>各党の最近の質疑</h2><p class="src">党ごとに、いちばん新しい質疑を1件ずつ機械的に並べています。</p>{"".join(item(r, party(r[4])) for r in recent_q)}</section>
<section class="fq"><h2>政府の最近の答弁</h2>{"".join(item(r, "政府") for r in recent_g)}</section>
<p><a class="btn" href="https://xb4g.com/giin/tracker/{t}#ref=fact-{q['slug']}">この話題の国会トラッカーで、すべての質疑と答弁を見る</a></p>"""
    return page(q["title"], desc, url, ld, "fact-" + q["slug"], body)


def build_index(qs):
    items = "".join(f'<a class="qi" href="{BASE}{q["slug"]}/"><div class="qt">{e(q["question"])}</div><div class="qs">{e(q["lead"][:80])}…</div></a>' for q in qs)
    title = "国会ファクト｜政策の疑問に、国会の会議録で答える"
    desc = "「年収の壁は178万円に？」「食料品の消費税はいつ下がる？」のような政策の疑問に、国会でだれが何を言い、政府が何と答えたかを、会議録の原文とリンクで答えます。判定のラベルは付けず、個人の投稿も載せません。"
    ld = {"@context": "https://schema.org", "@graph": [{"@type": "WebSite", "name": SITE, "url": BASE, "inLanguage": "ja", "description": desc,
                                                         "publisher": {"@type": "Organization", "name": "株式会社エクスブリッジ", "url": "https://exbridge.jp/"}}]}
    body = f"""<section class="hero"><h1>政策の疑問に、国会の記録で答える</h1>
<p class="lead">SNSや検索でよく見かける政策の疑問に、国会でだれが何を言い、政府が何と答え、何が決まったかを、会議録の原文とリンクで答えます。どの党の政策も同じ決まりで扱います。</p></section>
<section class="qlist"><h2>問いの一覧</h2>{items}</section>
<section class="fq" id="rule"><h2>決まり</h2><ul class="rule">
<li><b>答えは、会議録の原文で確かめられる範囲だけを書きます。</b>すべての答えに、国会会議録へのリンクを付けます。</li>
<li><b>「デマ」「誤り」のような判定のラベルは付けません。</b>記録を並べ、判断は読む人にお任せします。</li>
<li><b>個人の投稿は載せません。</b>問いは、よく見かける疑問を当サイトの言葉で書き直したものです。</li>
<li><b>どの党も同じ決まりで扱います。</b>党ごとの質疑の件数と、各党の最近の質疑を並べます。</li>
<li><b>AIが会議録から下書きを作り、人が原文と照らして確かめてから公開します。</b>確かめた日を各ページに書きます。</li>
<li><b>誤りのご指摘は、お問い合わせから受け付けます。</b>直した内容は確かめた日とあわせて残します。</li>
</ul></section>"""
    return page(title, desc, BASE, ld, "fact-top", body)


def main():
    qs = json.loads((ROOT / "data" / "questions.json").read_text())["questions"]
    c = sqlite3.connect(DB)
    OUT.mkdir(exist_ok=True)
    (OUT / "index.html").write_text(build_index(qs))
    for q in qs:
        d = OUT / q["slug"]; d.mkdir(exist_ok=True)
        (d / "index.html").write_text(build_q(c, q))
    today = date.today().isoformat()
    sm = "".join(f"<url><loc>{BASE}{p}</loc><lastmod>{lm}</lastmod></url>" for p, lm in [("", today)] + [(q["slug"] + "/", q["checked_at"]) for q in qs])
    (OUT / "sitemap.xml").write_text(f'<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">{sm}</urlset>')
    (OUT / ".htaccess").write_text("DirectoryIndex index.html\nAddCharset UTF-8 .html\n")
    print("built", len(qs), "questions →", OUT)


if __name__ == "__main__":
    main()
