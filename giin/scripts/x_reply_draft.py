#!/usr/bin/env python3
"""X の返信候補に、返信の文案を付けて「押せばすぐ返信できる」ページにする。

  x_reply_pick.py pick の判定（judge-*.json）と投稿（posts-*.json）を読み、
  当社のトラッカーかシステムが当たる候補だけに文案を作る。

文案の決めごと:
  - 書くのは gemma4（0.3 直・think:false）。渡すのは投稿の本文と、当社が持っている事実（件数・期間・システムの説明）だけ
  - **文案に出てくる数字は、渡した事実か投稿の本文にあるものに限る**。無い数字が出たら書き直させ、2回だめなら定型文にする
  - URL は文案に書かせず、こちらで最後に付ける（ref=x-<相手>-<月日>。どの返信から何人来たかを media mesh で数える）
  - 返信の投稿は人がやる。ページのボタンは X の返信画面（intent）を文入りで開くだけ

使い方: /usr/bin/python3 scripts/x_reply_draft.py [--posts outputs/.../posts-1021.json]
出力:   outputs/x_reply_pick/<日付>/reply-<時分>.html
"""
import argparse, datetime, glob, html, json, os, re, sqlite3, sys, time, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import x_reply_pick as X  # noqa: E402

OLLAMA = "http://192.168.0.3:11434/api/generate"
MODEL = "gemma4:12b-it-qat"
SYSTEM_FACTS = {
    "被災者支援ナビ": "被災したときに使える国の支援制度（約100制度）を、状況（家が壊れた・浸水した・仕事を失った など22の状況）から探せる。罹災証明書の案内もある。無料で使える",
    "土砂災害ハザードマップ": "住所を入れると、土砂災害警戒区域・特別警戒区域に入っているかを判定できる。無料で使える",
    "洪水・内水ハザードマップ": "住所を入れると、川の氾濫と内水（大雨で下水があふれる）の想定浸水の深さを判定できる。無料で使える",
    "防災AIチャット": "住所か現在地を入れると、キキクル・警報・避難情報・ハザードマップを1画面に集めて答える。無料で使える",
    "質問主意書の検索": "衆議院・参議院の質問主意書と答弁書を、本文まで検索できる",
    "都市計画ナビ": "住所を入れると、用途地域・建ぺい率・容積率などの都市計画を調べられる。全国の市区町村に対応",
}


SKIP = r"両陛下|天皇|皇后|皇族|宮内庁|ご逝去|訃報|亡くな|死亡|ご冥福|お悔やみ"


def tracker_facts(key):
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    q, g, d0, d1 = c.execute("select sum(kind='q'), sum(kind='gov'), min(date), max(date) from tracker_speech where tracker=?", (key,)).fetchone()
    return {"質疑の件数": q or 0, "政府答弁の件数": g or 0, "最初の発言": d0, "最新の発言": d1}


def bigrams(t):
    t = re.sub(r"\s+", "", t)
    return {t[i:i + 2] for i in range(len(t) - 1)}


def closest_answer(key, post_text, words):
    """投稿に一番近い政府答弁を、文字の2つ組の重なりで選ぶ（規則。モデルには選ばせない）。抜粋は語を含む1文"""
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    pb = bigrams(post_text)
    best = None
    for date, speaker, pos, body in c.execute(
            "select date, speaker, position, body from tracker_speech where tracker=? and kind='gov'", (key,)):
        sents = [x for x in re.split(r"(?<=。)", body or "") if any(all(q in x for q in w.split()) for w in words)]
        for sent in sents:
            sent = re.sub(r"^○[^　]+　", "", sent.strip())
            if not 25 <= len(sent) <= 160 or re.search(FILLER, sent):
                continue
            sc = len(pb & bigrams(sent)) / (len(bigrams(sent)) or 1)
            if not best or sc > best[0]:
                best = (sc, date, speaker, pos, sent)
    if not best:
        return None
    _, date, speaker, pos, sent = best
    who = f"{pos}の{speaker}氏" if pos else f"{speaker}氏"
    return {"答弁の日付": date, "答弁した人（このとおり書く）": who, "答弁の抜粋": sent}


# 答弁の前置き・受け答えの決まり文句。中身が無いので抜粋に使わない
FILLER = r"お尋ね|お答え|御質問|ご質問|御指摘|ご指摘|でございます。$|について(で)?ございます|申し上げます。$|承知しております|御答弁|委員長"
BANNED = r"網羅|お役に立|役立つ|役立ち|素晴らし|感動|敬服|敬意|興味深|注目が集|弊社|当事務所|！|!|ですね"


def gemma(prompt):
    body = json.dumps({"model": MODEL, "prompt": prompt, "stream": False, "think": False,
                       "options": {"num_predict": 300, "temperature": 0.4}}).encode()
    req = urllib.request.Request(OLLAMA, data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=180) as r:
        return json.loads(r.read()).get("response", "").strip()


def numbers(s):
    return set(re.findall(r"\d[\d,]*", s.replace(",", "")))


def draft(post, what, facts):
    facts_txt = "\n".join(f"- {k}: {v}" for k, v in facts.items())
    prompt = f"""あなたは名古屋のシステム開発会社の担当者として、X（旧Twitter）の投稿に返信する文を1つ書きます。

# 返信する投稿（{post['name']}）
{post['text'][:800]}

# 当社が紹介するもの
{what}

# 使ってよい事実（これ以外の数字・日付・固有名詞は書かない）
{facts_txt}

# 書き方
- 日本語で、全体を110字以内。2文まで（X は全角140字までで、URLの分を空ける）
- 答弁の抜粋があるときは、1文目で「〇年に〈答弁した人〉が国会で「…」と答弁しています」のように事実を先に書く。かぎかっこの中は抜粋の文字をそのまま使い、長ければ要の部分だけを残して前後を「…」で切る（言い換え・要約はしない）
- 2文目で、国会の質疑と答弁（またはシステム）を下のURLで見られることを、人が口で言う長さで一言添える
- 相手をほめる言葉・感想（「素晴らしい」「感動」「興味深い」「敬意」）、売り込みの言葉（「網羅」「役立つ」「お役に立てます」）、感嘆符は書かない
- 主語に「弊社」「当事務所」は使わない。名乗るなら「当社」
- 政治的な賛否・人への批判は書かない
- URL・ハッシュタグ・絵文字は書かない（URLはこちらで付ける）
- 返信の文だけを出力する"""
    allowed = numbers(post["text"]) | numbers(facts_txt)
    for _ in range(4):
        t = gemma(prompt).strip().strip("「」\"")
        t = re.sub(r"https?://\S+", "", t).strip()
        quotes = re.findall(r"「(.+?)」", t)
        src = facts.get("答弁の抜粋", "") + post["text"]
        who = facts.get("答弁した人（このとおり書く）")
        if who and who not in t:
            continue
        # かぎかっこの中は「…」で切った断片ごとに、抜粋か投稿の本文にそのままあること
        quote_ok = all(all(part.strip("。、") in src for part in re.split(r"…+|\.\.\.", q) if part.strip("。、"))
                       for q in quotes) and (not facts.get("答弁の抜粋") or quotes)
        if t and len(t) <= 115 and numbers(t) <= allowed and not re.search(BANNED, t) and quote_ok:
            return t, "gemma4"
    return None, "fallback"


def build(posts_path):
    day = os.path.basename(os.path.dirname(posts_path))
    stamp = os.path.basename(posts_path)[6:-5]
    posts = {p["id"]: p for p in json.load(open(posts_path, encoding="utf-8"))}
    judge = json.load(open(os.path.join(os.path.dirname(posts_path), f"judge-jevlocal-{stamp}.json"), encoding="utf-8"))
    mmdd = day[5:7] + day[8:10]
    cards = []
    for r in judge:
        if r["field"] == "none":
            continue
        p = posts[r["id"]]
        if re.search(SKIP, p["text"]):   # 皇室・弔事・事故の死傷には売り込みをしない
            continue
        tr = X.match_tracker(r["field"], p["text"])
        sy = X.match_system(r["field"], p["text"])
        if not (tr or sy):
            continue
        ref = f"x-{(p['screen_name'] or 'x').lower()}-{mmdd}"
        if tr:
            what = f"国会トラッカー「{tr['name']}」: 全国の国会議員の質疑と政府の答弁を、国会会議録から集めて並べたもの"
            facts = tracker_facts(tr["key"])
            ans = closest_answer(tr["key"], p["text"], tr.get("words") or [tr["short"]])
            if ans:
                facts.update(ans)
            url = f"https://xb4g.com/giin/tracker/{tr['key']}?ref={ref}"
            label = f"トラッカー: {tr['short']}"
            fb = f"この論点が国会でどう議論されてきたか、質疑{facts['質疑の件数']}件と政府答弁{facts['政府答弁の件数']}件を会議録から並べています。"
        else:
            what = f"{sy[0]}: {SYSTEM_FACTS.get(sy[0], '')}"
            facts = {"説明": SYSTEM_FACTS.get(sy[0], "")}
            url = f"{sy[1]}?ref={ref}"
            label = f"システム: {sy[0]}"
            fb = f"{SYSTEM_FACTS.get(sy[0], '').split('。')[0]}システムを開発しています。"
        text, how = draft(p, what, facts)
        text = (text or fb) + "\n" + url
        cards.append({"p": p, "r": r, "label": label, "text": text, "how": how})
        print(f"\r文案 {len(cards)}件", end="", flush=True)
    print()
    cards.sort(key=lambda c: -c["p"]["likes"])
    out = os.path.join(os.path.dirname(posts_path), f"reply-{stamp}.html")
    open(out, "w", encoding="utf-8").write(page(day, stamp, cards))
    return out, len(cards)


def page(day, stamp, cards):
    rows = []
    for i, c in enumerate(cards):
        p = c["p"]
        intent = "https://x.com/intent/post?" + urllib.parse.urlencode({"in_reply_to": p["id"], "text": c["text"]})
        ago = int((time.time() - p["created"]) / 60)
        body = html.escape(re.sub(r"\s+", " ", p["text"])[:280])
        rows.append(f"""<article>
<div class="who"><b>{html.escape(p['name'] or '')}</b> @{html.escape(p['screen_name'] or '')}・フォロワー{p['followers']:,}・♥{p['likes']:,}・{ago}分前</div>
<p class="post">{body}</p>
<a class="src" href="{html.escape(p['url'])}" target="_blank" rel="noopener">元の投稿を開く</a>
<div class="tag">{html.escape(c['label'])}{'（定型文）' if c['how'] == 'fallback' else ''}</div>
<textarea id="t{i}" rows="4">{html.escape(c['text'])}</textarea>
<div class="btns"><button type="button" onclick="cp({i},this)">返信文をコピー</button>
<a class="go" href="{html.escape(intent)}" target="_blank" rel="noopener">文入りで返信画面を開く</a></div>
</article>""")
    return f"""<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>X 返信候補 {day} {stamp}</title><style>
body{{margin:0;background:#f4f7f8;color:#13232c;font:15px/1.7 system-ui,"Noto Sans JP",sans-serif}}
main{{max-width:760px;margin:0 auto;padding:16px}} h1{{font-size:20px;margin:8px 0 4px}} .lead{{color:#4d5f68;font-size:13px;margin:0 0 16px}}
article{{background:#fff;border:1px solid #d8e3e7;border-radius:10px;padding:14px;margin:0 0 14px;overflow-wrap:anywhere}}
.who{{font-size:13px;color:#4d5f68}} .post{{margin:6px 0}} .src{{font-size:13px}} .tag{{margin:8px 0 4px;font-size:12px;font-weight:700;color:#0a726b}}
textarea{{box-sizing:border-box;width:100%;font:inherit;border:1px solid #c5d3d8;border-radius:8px;padding:8px}}
.btns{{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}} button,.go{{font:inherit;font-size:14px;font-weight:700;border-radius:99px;padding:7px 16px;cursor:pointer;text-decoration:none}}
button{{background:#fff;border:1px solid #0a9a8f;color:#0a726b}} .go{{background:#0a9a8f;color:#fff;border:1px solid #0a9a8f}}
</style></head><body><main><h1>X 返信候補 {day} {stamp[:2]}:{stamp[2:]}</h1>
<p class="lead">直近の投稿から、当社のトラッカーかシステムで答えられるもの {len(cards)}件。文は直してから使える（ボタンは直した文を使う）。投稿するのは人。</p>
{''.join(rows)}
<script>
function cur(i){{return document.getElementById('t'+i).value}}
function cp(i,b){{navigator.clipboard.writeText(cur(i)).then(function(){{b.textContent='コピーしました';setTimeout(function(){{b.textContent='返信文をコピー'}},1500)}})}}
document.querySelectorAll('.go').forEach(function(a,i){{a.addEventListener('click',function(){{
  var u=new URL(a.href);u.searchParams.set('text',cur(i));a.href=u.toString();}});}});
</script></main></body></html>"""


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--posts")
    a = ap.parse_args()
    pp = a.posts or sorted(glob.glob(os.path.join(X.OUT, datetime.date.today().isoformat(), "posts-*.json")))[-1]
    out, n = build(pp)
    print(out, n)
