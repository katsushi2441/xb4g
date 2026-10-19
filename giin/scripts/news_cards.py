#!/usr/bin/env python3
"""ニュースと、それに関係する当社のカード（国会トラッカー・経済指標）を並べる公開ページを作る。

毎時の返信候補（x_reply_draft.py が書く outputs/x_reply_pick/<日付>/reply-<時分>.json）のうち、
報道機関（data/x_news_accounts.json）の投稿に付いたカードだけを拾い、直近7日分を新しい順に1ページにする。
カードごとに「見出しとURLをコピー」「Xに投稿」のボタンを付け、だれでもカードを使って投稿できるようにする
（2026-10-20 ユーザー指示「ニュースとカードを表示するページに表示させたい。タイトルとカードのURLを簡単に
コピーできたり、Xに投稿できる機能があると、使ってもらいやすい」）。

  /usr/bin/python3 scripts/news_cards.py            # outputs/news_cards/index.html を作る
  /usr/bin/python3 scripts/news_cards.py --deploy   # 確認用の proto.exbridge.jp/news-cards-<token>/ に置く（noindex）

返信の文案そのもの（議員あての文）は載せない。載せるのはニュースの見出し（投稿の1行目）・出どころ・カードだけ。
"""
import argparse, datetime, glob, html, io, json, os, re, secrets, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
PICK = os.path.join(ROOT, "outputs", "x_reply_pick")
OUT = os.path.join(ROOT, "outputs", "news_cards")
CACHE = os.path.join(OUT, "og_cache.json")
OWN = ("xb4g.com", "exbridge.jp", "kurage.exbridge.jp", "kappstore.exbridge.jp")
REF = "x-share-newscard"     # 「Xで共有」ボタンの型（reference_x_share_button: ref=x-share-<製品>）
DAYS = 7
UA = "Mozilla/5.0 (newscards; +https://exbridge.jp/)"
e = html.escape


def news_handles():
    return {h.lower() for h in json.load(open(os.path.join(ROOT, "data", "x_news_accounts.json"), encoding="utf-8"))["handles"]}


def own_urls(text):
    out = []
    for u in re.findall(r"https?://\S+", text or ""):
        host = urllib.parse.urlparse(u).netloc
        if host in OWN:
            out.append(u.split("#")[0])
    return out


def kind_of(url):
    if "/keizai/" in url:
        return "経済指標"
    if "/giin/tracker/" in url:
        return "国会トラッカー"
    if "kshuisho.php" in url:
        return "質問主意書"
    if "/giin/bill/" in url:
        return "法案"
    if "xb4g.com/giin/" in url:
        return "議員の記録"
    return "当社のシステム"


def og(url, cache):
    """カードの og:image と og:title。同じURLは1度だけ取りに行く"""
    if url in cache:
        return cache[url]
    img = title = ""
    try:
        h = urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": UA}), timeout=20).read().decode("utf-8", "ignore")
        m = re.search(r'<meta property="og:image" content="([^"]+)"', h)
        img = html.unescape(m.group(1)) if m else ""
        m = re.search(r'<meta property="og:title" content="([^"]+)"', h)
        title = html.unescape(m.group(1)) if m else ""
    except Exception:
        pass
    cache[url] = {"img": img, "title": title}
    return cache[url]


def headline(text):
    """投稿の1行目（URLを除く）を見出しにする。長ければ切る"""
    first = next((ln.strip() for ln in (text or "").splitlines() if ln.strip() and not ln.strip().startswith("http")), "")
    first = re.sub(r"https?://\S+|[#＃]\S+", "", first).strip()   # URLとハッシュタグ（#nhk_news など）は見出しに入れない
    return first[:80] + ("…" if len(first) > 80 else "")


def collect(days=DAYS):
    news = news_handles()
    since = (datetime.date.today() - datetime.timedelta(days=days - 1)).isoformat()
    items, seen = [], set()
    for f in sorted(glob.glob(os.path.join(PICK, "20*", "reply-*.json")), reverse=True):
        day = os.path.basename(os.path.dirname(f))
        if day < since:
            continue
        stamp = os.path.basename(f)[6:-5]
        try:
            rows = json.load(open(f, encoding="utf-8"))
        except ValueError:
            continue
        for r in rows:
            if (r.get("screen_name") or "").lower() not in news:
                continue
            urls = own_urls((r.get("drafts") or [""])[0])
            if not urls:
                continue
            card = urls[-1]   # 文案の最後のURLが付けたカード（トラッカーなど。LPが先に来る場合がある）
            key = (r.get("url"), card)
            if key in seen:
                continue
            seen.add(key)
            items.append({"day": day, "time": f"{stamp[:2]}:{stamp[2:]}", "source": r.get("name") or r.get("screen_name"),
                          "post": r.get("url"), "headline": headline(r.get("text")), "card": card, "kind": kind_of(card)})
    return items


def page(items, cache):
    gen = datetime.datetime.now().strftime("%Y年%m月%d日 %H:%M")
    kinds = sorted({i["kind"] for i in items})
    cards = []
    for i in items:
        o = og(i["card"], cache)
        share = f"{i['card']}#ref={REF}"
        text = f"{i['headline']}\n{o['title'] or i['kind']}"
        intent = "https://x.com/intent/post?" + urllib.parse.urlencode({"text": text, "url": share})
        copy = f"{i['headline']}\n{share}"
        cards.append(f"""<article class="item" data-kind="{e(i['kind'])}">
<div class="meta"><span class="tag">{e(i['kind'])}</span><span>{e(i['day'][5:].replace('-', '/'))} {e(i['time'])}</span><span>{e(i['source'])}</span></div>
<h2><a href="{e(i['post'])}" target="_blank" rel="noopener">{e(i['headline'])}</a></h2>
<a class="card" href="{e(share)}" target="_blank" rel="noopener">{f'<img src="{e(o["img"])}" alt="{e(o["title"])}" loading="lazy" width="1200" height="630">' if o['img'] else ''}<span>{e(o['title'] or i['card'])}</span></a>
<div class="btns"><button type="button" class="cp" data-copy="{e(copy)}">見出しとURLをコピー</button><a class="x" href="{e(intent)}" target="_blank" rel="noopener">Xに投稿</a></div>
</article>""")
    return f"""<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ニュースと国会の記録カード</title><meta name="robots" content="noindex,nofollow">
<style>
:root{{--bg:#f6f8f9;--paper:#fff;--ink:#16232b;--sub:#56656f;--line:#dfe6ea;--teal:#0a7a6e;--teal-l:#e3f3f0}}
*{{box-sizing:border-box}}body{{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans JP","Hiragino Sans",system-ui,sans-serif;line-height:1.7}}
header,main{{max-width:1080px;margin:0 auto;padding:16px max(16px,env(safe-area-inset-left))}}
h1{{font-size:22px;margin:8px 0 4px}}.lead{{color:var(--sub);font-size:14px;margin:0 0 10px}}
.filters{{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 4px}}.filters button{{border:1px solid var(--line);background:var(--paper);border-radius:999px;padding:4px 12px;font-size:13px;cursor:pointer}}
.filters button.on{{background:var(--teal);color:#fff;border-color:var(--teal)}}
.grid{{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px}}
.item{{background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:12px;display:flex;flex-direction:column;gap:8px;min-width:0}}
.meta{{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:12px;color:var(--sub)}}.tag{{background:var(--teal-l);color:var(--teal);border-radius:999px;padding:0 8px;font-weight:700}}
h2{{font-size:15px;line-height:1.55;margin:0}}h2 a{{color:var(--ink);text-decoration:none}}h2 a:hover{{text-decoration:underline}}
.card{{display:block;border:1px solid var(--line);border-radius:8px;overflow:hidden;color:var(--sub);text-decoration:none;font-size:12.5px}}
.card img{{display:block;width:100%;height:auto;aspect-ratio:1200/630;object-fit:contain;background:#eef2f4}}.card span{{display:block;padding:6px 8px}}
.btns{{display:flex;gap:8px;margin-top:auto}}.btns>*{{flex:1;text-align:center;border-radius:8px;padding:8px 6px;font-size:13px;white-space:nowrap;font-weight:700;cursor:pointer}}
.cp{{border:1px solid var(--teal);background:var(--paper);color:var(--teal)}}.x{{background:#111;color:#fff;text-decoration:none;border:1px solid #111}}
.foot{{color:var(--sub);font-size:12.5px;margin:18px 0}}
</style></head><body>
<header><h1>ニュースと国会の記録カード</h1>
<p class="lead">報道各社のニュースに関係する、国会の質疑・政府の答弁や経済指標のカードです。「Xに投稿」でカードつきで共有できます。カードは国会会議録・日本銀行の統計などの公開データから作っています（{e(gen)}更新・直近{DAYS}日・{len(items)}件）。</p>
<div class="filters"><button type="button" class="on" data-k="">すべて</button>{''.join(f'<button type="button" data-k="{e(k)}">{e(k)}</button>' for k in kinds)}</div></header>
<main><div class="grid">{''.join(cards) or '<p>まだありません。</p>'}</div>
<p class="foot">作成：株式会社エクスブリッジ。見出しは各社の投稿の1行目で、記事の中身は各社のページでご確認ください。</p></main>
<script>
document.querySelectorAll('.cp').forEach(function(b){{b.addEventListener('click',function(){{var t=b.dataset.copy;var ok=function(){{var o=b.textContent;b.textContent='コピーしました';setTimeout(function(){{b.textContent=o}},1500)}};
if(navigator.clipboard&&window.isSecureContext){{navigator.clipboard.writeText(t).then(ok,function(){{fb()}})}}else{{fb()}}
function fb(){{var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{{document.execCommand('copy');ok()}}catch(e){{}}a.remove()}}}})}});
document.querySelectorAll('.filters button').forEach(function(b){{b.addEventListener('click',function(){{document.querySelectorAll('.filters button').forEach(function(x){{x.classList.toggle('on',x===b)}});
var k=b.dataset.k;document.querySelectorAll('.item').forEach(function(it){{it.hidden=!!k&&it.dataset.kind!==k}})}})}});
</script></body></html>"""


EXPORT = "/home/kojima/work/kpayload/data/news-cards.json"
KEEP_DAYS = 90   # 日ごとの保管ページ（exbridge.jp/news/<日付>.html）に残す日数


def tracker_page(card):
    """カードが国会トラッカーのものなら、そのテーマのトラッカーのページ（xb4g.com/giin/tracker/<key>）"""
    m = re.match(r"(https://xb4g\.com/giin/tracker/[a-z0-9-]+)", card)
    return m.group(1) if m else ""


def export(cache):
    """exbridge.jp/news/（kpayload の build-news.ts）用のデータ。日付・見出し・出どころ・カード・テーマのトラッカー"""
    names = {t["key"]: t.get("name") or t.get("short") for t in json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))}
    rows = []
    for i in collect(KEEP_DAYS):
        o = og(i["card"], cache)
        tp = tracker_page(i["card"])
        rows.append({**i, "cardTitle": o["title"], "cardImage": o["img"], "tracker": tp,
                     "trackerName": names.get(tp.rsplit("/", 1)[-1], "") if tp else ""})
    json.dump(cache, open(CACHE, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    json.dump({"generated": datetime.datetime.now().isoformat(timespec="minutes"), "items": rows},
              open(EXPORT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    return f"{EXPORT} {len(rows)}"


def deploy(path):
    """確認用に proto.exbridge.jp/news-cards-<token>/ に置く（noindex・推測されにくいURL）。公開URLはユーザーが決めてから"""
    import ftplib
    env = {}
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        m = re.match(r"(FTP_[A-Z]+)=(.*)", ln.strip())
        if m:
            env[m.group(1)] = m.group(2).strip("\"'")
    tk = os.path.join(OUT, ".token")
    if not os.path.exists(tk):
        open(tk, "w").write(secrets.token_hex(6))
    token = open(tk).read().strip()
    d = f"/web/proto_exbridge_jp/news-cards-{token}"
    f = ftplib.FTP_TLS(env["FTP_HOST"], timeout=60); f.login(env["FTP_USER"], env["FTP_PASS"]); f.prot_p()
    try:
        f.mkd(d)
    except ftplib.error_perm:
        pass
    f.storbinary(f"STOR {d}/.htaccess", io.BytesIO(b'Header set X-Robots-Tag "noindex, nofollow"\nOptions -Indexes\n'))
    f.storbinary(f"STOR {d}/index.html", open(path, "rb"))
    f.quit()
    return f"https://proto.exbridge.jp/news-cards-{token}/"


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--deploy", action="store_true")
    ap.add_argument("--export", action="store_true", help="kpayload/data/news-cards.json を書く（exbridge.jp/news/ の元データ。build-news.ts が読む）")
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    cache = json.load(open(CACHE, encoding="utf-8")) if os.path.exists(CACHE) else {}
    items = collect()
    p = os.path.join(OUT, "index.html")
    open(p, "w", encoding="utf-8").write(page(items, cache))
    json.dump(cache, open(CACHE, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(p, len(items))
    if a.deploy:
        print(deploy(p))
    if a.export:
        print(export(cache))
