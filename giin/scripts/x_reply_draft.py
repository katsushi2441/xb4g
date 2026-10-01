#!/usr/bin/env python3
"""X の返信候補に、返信の文案を付けて「押せばすぐ返信できる」ページにする。

  x_reply_pick.py pick の判定（judge-*.json）と投稿（posts-*.json）を読み、
  当社のトラッカーかシステムが当たる候補だけに文案を作る。

文案の決めごと:
  - 書くのは codex（gpt-6-sol）。gemma4 では文が売り込み調・的外れになった（2026-10-01）。
    **全候補を1回の呼び出しでまとめて書かせる**（1回2万トークン近く使うので1件ずつ呼ばない）。上限 MAX_ITEMS 件
  - 渡すのは投稿の本文と、当社が持っている事実（件数・期間・投稿に近い政府答弁3つ・システムの説明）だけ
  - **検査**: 数字は事実か投稿にあるものだけ・「」の引用は会議録の文と一致・115字以内・売り込みの言葉なし。通らなければ定型文にして理由を出す
  - URL は文案に書かせず、こちらで最後に付ける（ref=x-<相手>-<月日>。どの返信から何人来たかを media mesh で数える）
  - 返信の投稿は人がやる。ページのボタンは X の返信画面（intent）を文入りで開くだけ

使い方: /usr/bin/python3 scripts/x_reply_draft.py [--posts outputs/.../posts-1021.json] [--deploy]
出力:   outputs/x_reply_pick/<日付>/reply-<時分>.html
--deploy: リモートからスマホ・PCで開けるよう、proto.exbridge.jp/xreply-<token>/ に置く（index.html＝最新、
          <日付>-<時分>.html＝その回）。token は outputs/x_reply_pick/.token（git に入れない）。検索には載せない（noindex）
"""
import argparse, datetime, glob, html, json, os, re, sqlite3, subprocess, sys, time, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import x_reply_pick as X  # noqa: E402

MAX_ITEMS = 15   # 1回に文案を作る上限（codex の枠を守る）
CODEX_MODEL = "gpt-6-sol"   # astra ではなく sol（2026-10-01 ユーザー指定。codex CLI 0.159.3 以上が要る）
# 当社が販売しているシステムのデモ。「無料で使える」とは書かない（デモサイトであって無料提供ではない）
SYSTEM_FACTS = {
    "被災者支援ナビ": "被災したときに使える国の支援制度（約100制度）を、状況（家が壊れた・浸水した・仕事を失った など22の状況）から探せるシステム。罹災証明書の案内もある。デモで試せる",
    "土砂災害ハザードマップ": "住所を入れると、土砂災害警戒区域・特別警戒区域に入っているかを判定するシステム。デモで試せる",
    "洪水・内水ハザードマップ": "住所を入れると、川の氾濫と内水（大雨で下水があふれる）の想定浸水の深さを判定するシステム。デモで試せる",
    "防災AIチャット": "住所か現在地を入れると、キキクル・警報・避難情報・ハザードマップを1画面に集めて答えるシステム。デモで試せる",
    "質問主意書の検索": "衆議院・参議院の質問主意書と答弁書を、本文まで検索できるシステム。デモで試せる",
    "都市計画ナビ": "住所を入れると、用途地域・建ぺい率・容積率などの都市計画を調べられるシステム。全国の市区町村に対応。デモで試せる",
}


SKIP = r"両陛下|天皇|皇后|皇族|宮内庁|ご逝去|訃報|亡くな|死亡|ご冥福|お悔やみ"


def tracker_facts(key):
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    q, g, d0, d1 = c.execute("select sum(kind='q'), sum(kind='gov'), min(date), max(date) from tracker_speech where tracker=?", (key,)).fetchone()
    return {"質疑の件数": q or 0, "政府答弁の件数": g or 0, "最初の発言": d0, "最新の発言": d1}


def bigrams(t):
    t = re.sub(r"\s+", "", t)
    return {t[i:i + 2] for i in range(len(t) - 1)}


def closest_answers(key, post_text, words, k=5):
    """投稿に近い発言（政府の答弁と議員の質問）を、文字の2つ組の重なり＋新しさで上位k件選ぶ（規則）。
    抜粋は語を含む1文。どれを使うか・使わないかは文案の側で決める"""
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    pb = bigrams(post_text)
    this_year = datetime.date.today().year
    found = []
    for date, speaker, pos, kind, body, house, meeting in c.execute(
            "select date, speaker, position, kind, body, house, meeting from tracker_speech where tracker=? and kind in ('gov','q')", (key,)):
        sents = [x for x in re.split(r"(?<=。)", body or "") if any(all(q in x for q in w.split()) for w in words)]
        for sent in sents:
            sent = re.sub(r"^○[^　]+　", "", sent.strip())
            if not 25 <= len(sent) <= 160 or re.search(FILLER, sent):
                continue
            sc = len(pb & bigrams(sent)) / (len(bigrams(sent)) or 1)
            sc += max(0, 5 - (this_year - int(date[:4]))) * 0.02   # 直近5年の発言を少し優先
            found.append((sc, date, speaker, pos, kind, sent, f"{house}{meeting}"))
    found.sort(key=lambda x: -x[0])
    out, seen = [], set()
    for _, date, speaker, pos, kind, sent, mtg in found:
        if sent in seen:
            continue
        seen.add(sent)
        who = f"{pos}の{speaker}氏" if pos else f"{speaker}議員"
        out.append({"date": date, "meeting": mtg, "who": who, "kind": "政府の答弁" if kind == "gov" else "議員の質問", "quote": sent})
        if len(out) == k:
            break
    return out


# 答弁の前置き・受け答えの決まり文句。中身が無いので抜粋に使わない
FILLER = r"お尋ね|お答え|御質問|ご質問|御指摘|ご指摘|でございます。$|について(で)?ございます|申し上げます。$|承知しております|御答弁|委員長"
BANNED = r"無料|網羅|お役に立|役立つ|役立ち|素晴らし|感動|敬服|敬意|興味深|注目が集|弊社|当事務所|！|!|ですね"


STYLE = """（当社が実際に送った返信。この型で書く）
- 現場から国の法律を変えた例です。今年の改正では衆参の環境委員会がそれぞれ附帯決議で、この施設設置の特例を「規制緩和措置」として、運用時に生活環境の保全へ配慮するよう求めています。
- 東京都の確認は自己申告ですが、国の日本版DBSでは対象の事業者が国のシステムで犯罪事実を確認します。下着の窃盗やつきまといが対象外になった理由も、2024年の審議で政府が答弁しています。"""


def numbers(s):
    return set(re.findall(r"\d[\d,]*", s.replace(",", "")))


def check(item, t):
    """文案の検査。通らなければ理由を返す"""
    if not t:
        return "空"
    if len(t) > 115:
        return f"長い（{len(t)}字）"
    if re.search(BANNED, t):
        return "売り込み・感想の言葉"
    allowed = numbers(item["post"]["text"]) | numbers(json.dumps(item["facts"], ensure_ascii=False))
    if not numbers(t) <= allowed:
        return "事実に無い数字"
    src = item["post"]["text"] + "".join(a["quote"] for a in item["facts"].get("発言", []))
    for q in re.findall(r"「(.+?)」", t):
        for part in re.split(r"…+|\.\.\.", q):
            part = part.strip("。、")
            if part and part not in src:
                return "引用が会議録と一致しない"
    return None


def codex_batch(items, workdir):
    """全候補の文案を codex に1回で書かせる（codex は1回で2万トークン近く使うので、1件ずつ呼ばない）"""
    schema = {"type": "object", "additionalProperties": False, "required": ["replies"],
              "properties": {"replies": {"type": "array", "items": {
                  "type": "object", "additionalProperties": False, "required": ["id", "text"],
                  "properties": {"id": {"type": "string"}, "text": {"type": "string"}}}}}}
    sp = os.path.join(workdir, "codex-schema.json"); op = os.path.join(workdir, "codex-out.json")
    json.dump(schema, open(sp, "w", encoding="utf-8"))
    cases = []
    for it in items:
        cases.append({"id": it["id"], "投稿した人": it["post"]["name"], "投稿": it["post"]["text"][:700],
                      "紹介するもの": it["what"], "使ってよい事実": it["facts"]})
    prompt = f"""名古屋のシステム開発会社（株式会社エクスブリッジ）の担当者として、X の投稿への返信文を、下の候補それぞれに1つずつ書いてください。ファイルの読み書きやコマンドの実行はしないでください。

# 書き方
- 日本語で、1件115字以内（X は全角140字までで、URL の分を空ける）。2文まで
- 相手の投稿の具体的な論点（地名・制度名・数字・出来事）に、国会でのやりとりという事実で応える。「発言」の中から、その論点に本当に関係するものを1つだけ選び、「〇年に〈who〉が国会で「…」と答弁しています（議員の質問なら「質問しています」）」の形で書く
- 引用する発言は、投稿の出来事そのものについての発言ではない（年も災害も違うことが多い）。「〇〇に関連して、〇年に…」のように、投稿の出来事について答えたと読める書き方はしない。違う出来事なら「〇年の〈meeting〉では」「過去の災害でも」のように区別して書く
- 関係の薄い発言しか無ければ引用しない。そのときは質疑・答弁の件数や最新の発言の時期など、使ってよい事実で短く書く
- かぎかっこの中は quote の文字をそのまま使う。長ければ要の部分だけ残して前後を「…」で切る。言い換え・要約はしない
- 使ってよい事実に無い数字・日付・人名は書かない
- 当社のものは、下にURLが付くので「国会の質疑と答弁はこちらで見られます」程度に短く添える。システムは「〜できるシステムを開発しています」と書き、無料とは書かない（販売しているシステムのデモ）
- 相手をほめる言葉や感想（素晴らしい・感動・興味深い・敬意）、売り込みの言葉（網羅・役立つ・お役に立てます）、感嘆符、「ですね」は使わない。主語は「当社」（弊社は使わない）
- 政治的な賛否・人への批判は書かない。URL・ハッシュタグ・絵文字は書かない
- AIが書いたような言い換えの多い文にしない。人が口で言う長さで

{STYLE}

# 候補
{json.dumps(cases, ensure_ascii=False, indent=1)}

replies に、候補の id ごとに text を入れて返してください。"""
    r = subprocess.run(["codex", "exec", "--skip-git-repo-check", "--ephemeral", "-s", "read-only", "-m", CODEX_MODEL,
                        "--output-schema", sp, "-o", op, "-"], input=prompt, capture_output=True, text=True,
                       timeout=900, cwd=workdir)
    if r.returncode != 0 or not os.path.exists(op):
        raise RuntimeError(f"codex が失敗: {r.stderr[-500:]}")
    return {x["id"]: x["text"].strip() for x in json.loads(open(op, encoding="utf-8").read())["replies"]}


def build(posts_path):
    day = os.path.basename(os.path.dirname(posts_path))
    stamp = os.path.basename(posts_path)[6:-5]
    posts = {p["id"]: p for p in json.load(open(posts_path, encoding="utf-8"))}
    judge = json.load(open(sorted(glob.glob(os.path.join(os.path.dirname(posts_path), f"judge-jevlocal-{stamp}*.json")),
                                  key=os.path.getmtime)[-1], encoding="utf-8"))
    mmdd = day[5:7] + day[8:10]
    cards, items = [], []
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
            facts["発言"] = closest_answers(tr["key"], p["text"], tr.get("words") or [tr["short"]])
            url = f"https://xb4g.com/giin/tracker/{tr['key']}?ref={ref}"
            label = f"トラッカー: {tr['short']}"
            fb = f"この論点が国会でどう議論されてきたか、質疑{facts['質疑の件数']}件と政府答弁{facts['政府答弁の件数']}件を会議録から並べています。"
        else:
            what = f"{sy[0]}: {SYSTEM_FACTS.get(sy[0], '')}"
            facts = {"説明": SYSTEM_FACTS.get(sy[0], "")}
            url = f"{sy[1]}?ref={ref}"
            label = f"システム: {sy[0]}"
            fb = f"{SYSTEM_FACTS.get(sy[0], '').split('。')[0]}を開発しています。"
        items.append({"id": p["id"], "post": p, "r": r, "what": what, "facts": facts, "url": url, "label": label, "fb": fb})
    items = sorted(items, key=lambda it: -it["post"]["views"])[:MAX_ITEMS]
    texts = codex_batch(items, os.path.dirname(posts_path)) if items else {}
    for it in items:
        t = re.sub(r"https?://\S+", "", texts.get(it["id"], "")).strip()
        why = check(it, t)
        cards.append({"p": it["post"], "r": it["r"], "label": it["label"],
                      "text": (it["fb"] if why else t) + "\n" + it["url"], "how": "codex" if not why else f"定型文（codex の文は{why}）"})
    cards.sort(key=lambda c: -c["p"]["views"])
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
<div class="who"><b>{html.escape(p['name'] or '')}</b> @{html.escape(p['screen_name'] or '')}・フォロワー{p['followers']:,}・表示{p['views']:,}・♥{p['likes']:,}・{ago}分前</div>
<p class="post">{body}</p>
<a class="src" href="{html.escape(p['url'])}" target="_blank" rel="noopener">元の投稿を開く</a>
<div class="tag">{html.escape(c['label'])}{'' if c['how'] == 'codex' else '・' + html.escape(c['how'])}</div>
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
<p class="lead">直近60分・表示5,000以上の投稿から、当社のトラッカーかシステムで答えられるもの {len(cards)}件（表示の多い順）。文は直してから使える（ボタンは直した文を使う）。投稿するのは人。</p>
{''.join(rows)}
<script>
function cur(i){{return document.getElementById('t'+i).value}}
function cp(i,b){{navigator.clipboard.writeText(cur(i)).then(function(){{b.textContent='コピーしました';setTimeout(function(){{b.textContent='返信文をコピー'}},1500)}})}}
document.querySelectorAll('.go').forEach(function(a,i){{a.addEventListener('click',function(){{
  var u=new URL(a.href);u.searchParams.set('text',cur(i));a.href=u.toString();}});}});
</script></main></body></html>"""


def deploy(path):
    """heteml へ1接続（FTPS）で送る。認証は aixec/.env の FTP_*（表示しない）"""
    import ftplib, io
    env = {}
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        m = re.match(r"(FTP_[A-Z]+)=(.*)", ln.strip())
        if m:
            env[m.group(1)] = m.group(2).strip("\"'")
    token = open(os.path.join(X.OUT, ".token")).read().strip()
    d = f"/web/proto_exbridge_jp/xreply-{token}"
    day = os.path.basename(os.path.dirname(path)); stamp = os.path.basename(path)[6:-5]
    data = open(path, "rb").read()
    f = ftplib.FTP_TLS(env["FTP_HOST"], timeout=60); f.login(env["FTP_USER"], env["FTP_PASS"]); f.prot_p()
    try:
        f.mkd(d)
    except ftplib.error_perm:
        pass
    f.storbinary(f"STOR {d}/.htaccess", io.BytesIO(b"Header set X-Robots-Tag \"noindex, nofollow\"\nOptions -Indexes\n"))
    f.storbinary(f"STOR {d}/index.html", io.BytesIO(data))
    f.storbinary(f"STOR {d}/{day}-{stamp}.html", io.BytesIO(data))
    f.quit()
    return f"https://proto.exbridge.jp/xreply-{token}/"


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--posts")
    ap.add_argument("--deploy", action="store_true")
    a = ap.parse_args()
    pp = a.posts or sorted(glob.glob(os.path.join(X.OUT, datetime.date.today().isoformat(), "posts-*.json")))[-1]
    out, n = build(pp)
    print(out, n)
    if a.deploy:
        print(deploy(out))
