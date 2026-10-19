#!/usr/bin/env python3
"""X の返信候補に、返信の文案を付けて「押せばすぐ返信できる」ページにする。

  x_reply_pick.py pick の判定（judge-*.json）と投稿（posts-*.json）を読み、
  当社のトラッカーかシステムが当たる候補だけに文案を作る。

文案の決めごと:
  - 書くのは codex（gpt-6-sol）。gemma4 では文が売り込み調・的外れになった（2026-10-01）。
    **全候補を1回の呼び出しでまとめて書かせる**（1回2万トークン近く使うので1件ずつ呼ばない）。上限 MAX_ITEMS 件
  - 渡すのは投稿の本文と、当社が持っている事実（件数・期間・投稿に近い政府答弁3つ・システムの説明）だけ
  - 1件に2案（型は投稿に合わせて選ばせる）。お手本は当社が手で出してきた返信案（相手の投稿の中身を受け止め→国会の事実→当社の見方）
  - **検査**: 数字は事実か投稿にあるものだけ・「」の引用は会議録の文と一致・220字以内・「無料」や売り込みの言葉なし。通らない案は出さない
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
# そのうち国会議員（衆議院・参議院）の投稿に先に割り当てる枠。表示の多い順だけで選ぶと、議員の投稿（表示の中央値は
# 200前後）は報道機関（1,000〜数万）に毎回押し出され、2026-10-08 は分野の当たった議員の投稿174件（うち国会議員77件）
# から文案になったのは2件だった。地方議員・首長は今までどおり表示の多い順で競う（2026-10-08 ユーザー指示）
N_KOKKAI = 6
# 愛知の経営者の投稿に先に割り当てる枠（2026-10-19 ユーザー指示）。経営者は政策の話をあまりしないので、分野が
# 「どれでもない」でも経営の実務の語があれば候補にする。同じ投稿は一度メールに出したら次の回から出さない（6時間遡って読むため）
N_OWNER = 3
OWNER_RX = r"最低賃金|賃上げ|給料|人手不足|採用|求人|離職|人材|価格転嫁|値上げ|仕入|原価|円安|物価|金利|借入|融資|資金繰り|倒産|補助金|助成金|税|インボイス|社会保険|年収の壁|事業承継|DX|AI|システム|IT|デジタル|業務|効率|生産性|景気|売上|中小企業"
OWNER_SHOWN = os.path.join(X.OUT, "owner_shown.json")
# kdeck の worker（systemd）の PATH には nvm の bin が無いので、見つからなければ既定の場所を使う
CODEX_BIN = os.environ.get("CODEX_BIN") or __import__("shutil").which("codex") or "/home/kojima/.nvm/versions/node/v20.20.2/bin/codex"
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


WORK = "/home/kojima/work"
_CATALOG = None


def link_catalog():
    """トラッカーが当たらない投稿に付けるリンクの候補。当社が公開しているページの題名と説明だけを持つ
    （デモサイト（media mesh の lps）・VWork ブログ・note。politech とトラッカー一覧は付けない（2026-10-01 ユーザー指定）。
    規則で当たらなかった個別のトラッカーも、語が近ければ候補に入れる）"""
    global _CATALOG
    if _CATALOG is not None:
        return _CATALOG
    cat = []
    for p in sorted(glob.glob(os.path.join(WORK, "vwork", "blog", "*.md"))):
        head = open(p, encoding="utf-8").read().split("\n---", 1)[0]
        if re.search(r"^status:\s*draft", head, re.M):
            continue
        fm = {m.group(1): m.group(2).strip().strip('"') for m in re.finditer(r"^(title|description|tags):\s*(.+)$", head, re.M)}
        if fm.get("title"):
            stem = os.path.basename(p)[:-3]
            cat.append({"種類": "VWork ブログの記事", "題名": fm["title"], "説明": fm.get("description", "")[:200],
                        "url": f"https://exbridge.jp/vibeblog/{stem}.html"})
    try:
        mesh = json.load(open(os.path.join(WORK, "kurage_web", "scripts", "mesh_data.json"), encoding="utf-8"))
    except (OSError, ValueError):
        mesh = {}
    for m in mesh.get("media", []):
        if m.get("platform") == "note" and m.get("url", "").startswith("https://note.com/"):
            cat.append({"種類": "note の記事", "題名": m["name"], "説明": (m.get("to") or "")[:200], "url": m["url"]})
    for m in mesh.get("lps", []):
        if m.get("url", "").startswith("https://"):
            cat.append({"種類": "当社のシステムのデモ・紹介ページ", "題名": m["name"],
                        "説明": re.sub(r"実測[:：].*", "", m.get("keyword") or "")[:200], "url": m["url"]})
    # 経営者の制度カレンダーの項目ページ（「106万円の壁はいつから」のような投稿に、トップではなく該当の項目を出す。2026-10-05）
    try:
        php = open(os.path.join(WORK, "kseidocal", "php", "kseidocal.php"), encoding="utf-8").read()
        for m in re.finditer(r"\['slug' => '([\w-]+)', 'seo' => '([^']+)', 'name' => '([^']+)'.*?'what' => '([^']+)'", php, re.S):
            cat.append({"種類": "当社のシステムのデモ・紹介ページ", "題名": m.group(2) + "（" + m.group(3) + "）",
                        "説明": m.group(4)[:200], "url": f"https://kurage.exbridge.jp/kseidocal.php/i/{m.group(1)}/"})
    except OSError:
        pass
    for t in X.trackers():
        cat.append({"種類": "国会トラッカー（国会の質疑と政府答弁を会議録から集めたページ）", "題名": t["name"],
                    "説明": (t.get("lead") or "")[:200] + " 語: " + "・".join(t.get("words") or []),
                    "url": f"https://xb4g.com/giin/tracker/{t['key']}"})
    # 2文字の組の珍しさ（どの候補にも出る組は手がかりにならない）
    df = {}
    for c in cat:
        c["_bg"] = bigrams(c["題名"] + c["説明"])
        for b in c["_bg"]:
            df[b] = df.get(b, 0) + 1
    _CATALOG = (cat, df)
    return _CATALOG


def related_links(text, k=8):
    """投稿の本文に近いページを、2文字の組の重なり（珍しい組ほど重い）で k 件まで選ぶ。最後に選ぶのは codex"""
    cat, df = link_catalog()
    tb = bigrams(re.sub(r"https?://\S+|[#＃@]\S+", "", text))
    scored = []
    for c in cat:
        hit = tb & c["_bg"]
        sc = sum(1.0 / df[b] for b in hit if re.fullmatch(r"[一-龥々ァ-ヶーA-Za-z]{2}", b))
        if sc > 0:
            scored.append((sc / (len(c["_bg"]) ** 0.3), c))
    scored.sort(key=lambda x: -x[0])
    return [{"種類": c["種類"], "題名": c["題名"], "説明": c["説明"], "url": c["url"]} for sc, c in scored[:k] if sc >= 0.05]


SKIP = r"両陛下|天皇|皇后|皇族|宮内庁|ご逝去|訃報|亡くな|死亡|ご冥福|お悔やみ"


def tracker_facts(key):
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    q, g, d0, d1 = c.execute("select sum(kind='q'), sum(kind='gov'), min(date), max(date) from tracker_speech where tracker=?", (key,)).fetchone()
    years = c.execute("select substr(date,1,4) y, sum(kind='q'), sum(kind='gov') from tracker_speech where tracker=? group by y order by y desc limit 4", (key,)).fetchall()
    askers = c.execute("select speaker, kaiha_at, count(*) n from tracker_speech where tracker=? and kind='q' group by speaker order by n desc limit 5", (key,)).fetchall()
    parties = c.execute("select kaiha_at, count(*) n from tracker_speech where tracker=? and kind='q' and kaiha_at is not null group by kaiha_at order by n desc limit 5", (key,)).fetchall()
    return {"質疑の件数": q or 0, "政府答弁の件数": g or 0, "最初の発言": d0, "最新の発言": d1,
            "年ごとの件数（質疑・答弁）": {y: f"質疑{a}件・答弁{b}件" for y, a, b in years},
            "質問の多い議員": [f"{n}（{k or '会派不明'}）{m}件" for n, k, m in askers],
            "会派ごとの質疑": [f"{k}{m}件" for k, m in parties]}


def bigrams(t):
    t = re.sub(r"\s+", "", t)
    return {t[i:i + 2] for i in range(len(t) - 1)}


def closest_answers(key, post_text, words, k=8, kinds=("gov", "q", "ref")):
    """投稿に近い発言（政府の答弁と議員の質問）を、文字の2つ組の重なり＋新しさで上位k件選ぶ（規則）。
    抜粋は語を含む1文。どれを使うか・使わないかは文案の側で決める"""
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    pb = bigrams(post_text)
    this_year = datetime.date.today().year
    found = []
    for date, speaker, pos, kind, body, house, meeting, sid in c.execute(
            "select date, speaker, position, kind, body, house, meeting, speech_id from tracker_speech where tracker=? and kind in (" + ",".join("?" * len(kinds)) + ")", (key, *kinds)):
        sents = [x for x in re.split(r"(?<=。)", body or "") if any(all(q in x for q in w.split()) for w in words)]
        for sent in sents:
            sent = re.sub(r"^○[^　]+　", "", sent.strip())
            if not 25 <= len(sent) <= 160 or re.search(FILLER, sent):
                continue
            sc = len(pb & bigrams(sent)) / (len(bigrams(sent)) or 1)
            sc += max(0, 5 - (this_year - int(date[:4]))) * 0.02   # 直近5年の発言を少し優先
            found.append((sc, date, speaker, pos, kind, sent, f"{house}{meeting}", sid))
    found.sort(key=lambda x: -x[0])
    out, seen = [], set()
    for _, date, speaker, pos, kind, sent, mtg, sid in found:
        if sent in seen:
            continue
        seen.add(sent)
        who = f"{pos}の{speaker}氏" if pos else f"{speaker}議員"
        out.append({"date": date, "meeting": mtg, "who": who, "kind": {"gov": "政府の答弁", "ref": "参考人の答弁"}.get(kind, "議員の質問"), "quote": sent,
                    "_sid": sid})   # 質疑カードのURLに使う（codex には渡さない）
        if len(out) == k:
            break
    return out


KOKKAI = "https://kokkai.ndl.go.jp/api/speech"


def keywords(text):
    """投稿から会議録を検索する語を2つまで（gemma4 に語を抜き出させるだけ。文は書かせない）"""
    body = json.dumps({"model": "gemma4:12b-it-qat", "stream": False, "think": False, "options": {"num_predict": 60, "temperature": 0},
                       "prompt": "次の投稿の論点を、国会会議録で検索するための短い語（制度名・法律名・政策名。2〜10字）で2つまで、読点で区切って出力してください。人名・党名・地名は入れない。語だけを出力。\n\n" + text[:600]}).encode()
    req = urllib.request.Request("http://192.168.0.3:11434/api/generate", data=body, headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=120) as r:
        out = json.loads(r.read()).get("response", "")
    return [w.strip(" 「」・\n") for w in re.split(r"[、,，\n]", out) if 2 <= len(w.strip(" 「」・\n")) <= 12][:2]


def kokkai_facts(text):
    """トラッカーが無い論点は、国会会議録APIをその場で検索して、直近3年の発言から投稿に近い文を選ぶ"""
    words = keywords(text)
    if not words:
        return None, []
    since = f"{datetime.date.today().year - 3}-01-01"
    pb = bigrams(text)
    found, total = [], 0
    for w in words:
        u = KOKKAI + "?" + urllib.parse.urlencode({"any": w, "from": since, "recordPacking": "json", "maximumRecords": 30})
        try:
            d = json.loads(subprocess.run(["curl", "-s", "-m", "30", u], capture_output=True, text=True).stdout)
        except ValueError:
            continue
        total += int(d.get("numberOfRecords") or 0)
        for r in d.get("speechRecord") or []:
            pos = r.get("speakerPosition") or ""
            if r.get("speakerRole") in ("会議録情報",) or "委員長" in pos or "議長" in pos:
                continue
            for sent in re.split(r"(?<=。)", r.get("speech") or ""):
                sent = re.sub(r"^○[^　]+　", "", sent.strip())
                if w not in sent or not 25 <= len(sent) <= 160 or re.search(FILLER, sent):
                    continue
                who = f"{pos}の{r['speaker']}氏" if pos else f"{r['speaker']}議員"
                kind = "政府の答弁" if pos else "議員の質問"
                found.append((len(pb & bigrams(sent)) / (len(bigrams(sent)) or 1),
                              {"date": r["date"], "meeting": f"{r['nameOfHouse']}{r['nameOfMeeting']}", "who": who, "kind": kind, "quote": sent}))
        time.sleep(1)
    found.sort(key=lambda x: -x[0])
    seen, out = set(), []
    for _, f in found:
        if f["quote"] not in seen:
            seen.add(f["quote"]); out.append(f)
        if len(out) == 8:
            break
    return {"検索した語": "・".join(words), f"{since[:4]}年以降にこの語を含む発言の件数（両院）": total, "発言": out}, words


# 答弁の前置き・受け答えの決まり文句。中身が無いので抜粋に使わない
FILLER = r"お尋ね|お答え|御質問|ご質問|御指摘|ご指摘|でございます。$|について(で)?ございます|申し上げます。$|承知しております|御答弁|委員長"
BANNED = r"無料|網羅|お役に立|弊社|当事務所|！|!"


STYLE = """# お手本（当社が実際に出した返信案。この型・この温度で書く）

## 例1 投稿: 浜田聡（前参院議員）「岸田総理は実質増税した。手法: 税控除縮減・非課税措置見直し・新たな賦課金創設・保険料率引き上げ…」
- 答弁を示す型: 「新たな賦課金」の一つが子ども・子育て支援金ですね。岸田総理（当時）は国会で、歳出改革と賃上げの効果の範囲内で作るので「全体として実質的な負担が生じない」と答弁しました。政府の説明でも、令和10年度に加入者1人あたり月500円弱の拠出です。「実質的な負担なし」の検証こそ必要だと思います。
- 論点を示す型: 子ども・子育て支援金は、税ではなく医療保険料に上乗せして集める仕組みです。岸田総理（当時）は「全体として実質的な負担が生じない」と答弁しましたが、その根拠は歳出改革と賃上げの効果。国会でこの支援金に触れた質疑は183件あります。
- 短い型: 子ども・子育て支援金について、岸田総理（当時）は国会で「全体として実質的な負担が生じない」と答弁していました。政府の見込みでも令和10年度に加入者1人あたり月500円弱の拠出です。

## 例2 投稿: 鹿嶋祐介（衆院・元陸上自衛官）「防衛省関係法案の説明を受けた。隊員が安心して働き続けられる環境、責任と負担に見合う処遇が欠かせない」
- 数字を添える型: 現場を知る方の視点、心強いです。政府答弁では、令和7年度末の自衛官の充足率は88.1％。若手の士は令和7年3月時点で60.7％、令和6年度の中途退職約5,620人のうち半数超が士でした。採用だけでなく、辞めずに続けられる処遇と制度が要だと思います。
- 本人の発言に寄せる型: 7月の安全保障委員会での「宣誓の重みに応えることができる制度を整える」というご質疑、拝見しました。応募者は10年で約4割減り、中途退職は令和6年度で約5,620人。今回の法案がこの数字をどう変えるか、審議に注目しています。

## 例3 投稿: おときた駿（元参院議員）「誹謗中傷の慰謝料引き上げに賛成。慰謝料が低すぎ『やったもん勝ち』。懲罰的損害賠償まで視野に議論を」
- 経緯を示す型: 懲罰的損害賠償は、2022年1月の予算委員会で維新の岩谷議員が導入を求めています。そのときの法相答弁は「現実の損害を…補填をするということを目的とした制度」。今回の研究会で、慰謝料の水準からどこまで踏み込むかが焦点ですね。

## 例4 投稿: くさま剛（国交政務官）「TEC-FORCEが横須賀市・三浦市で土砂災害の被災状況を調査。台風26号も迫る中、二次被害防止へ」
- 現場に寄り添う型: TEC-FORCEの皆さんの調査、ありがとうございます。台風26号の前に、横須賀市と三浦市の土砂災害警戒区域を住所で確かめられるシステムを開発しています。PDFの地図を開かなくても確かめられるので、二次被害を防ぐ一助になればと思います。"""


def numbers(s):
    """文中の数（先頭の0は無視。「2025-05」の05と「5月」の5を同じに数える）。西暦には令和の年も足す"""
    out = set()
    for x in re.findall(r"\d+(?:\.\d+)?", s.replace(",", "").replace("，", "")):
        x = x.lstrip("0") or "0"
        out.add(x)
        if x.isdigit() and 2019 <= int(x) <= 2100:
            out.add(str(int(x) - 2018))
    return out


def xlen(t):
    """Xの数え方（全角1字=2・半角1字=1）。URLは含めない"""
    return sum(1 if ord(c) < 0x1100 else 2 for c in t)


def check(item, t):
    """文案の検査。通らなければ理由を返す"""
    if not t:
        return "空"
    # Xの数え方で280を超えると長文ポストになり、カード（OGP）が出ない（2026-10-17 実測）。URLは1本23字＋改行
    nurl = len([u for u in (item.get("url") or "").split("\n") if u])
    wl = xlen(t) + 24 * nurl
    if wl > 280:
        return f"長い（Xの数え方で{wl}）"
    if re.search(BANNED, t):
        return "売り込み・感想の言葉"
    allowed = numbers(item["post"]["text"]) | numbers(json.dumps(item["facts"], ensure_ascii=False))
    if not numbers(t) <= allowed:
        return "事実に無い数字"
    src = item["post"]["text"] + "".join(a["quote"] for a in item["facts"].get("発言", [])) + (item["facts"].get("カードに出る答弁") or {}).get("quote", "")
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
                  "type": "object", "additionalProperties": False, "required": ["id", "link", "link2", "drafts"],
                  "properties": {"id": {"type": "string"}, "link": {"type": "integer"}, "link2": {"type": "integer"}, "drafts": {"type": "array", "items": {
                      "type": "object", "additionalProperties": False, "required": ["type", "text"],
                      "properties": {"type": {"type": "string"}, "text": {"type": "string"}}}}}}}}}
    sp = os.path.join(workdir, "codex-schema.json"); op = os.path.join(workdir, "codex-out.json")
    json.dump(schema, open(sp, "w", encoding="utf-8"))
    cases = []
    for it in items:
        cases.append({"id": it["id"], "投稿した人": it["post"]["name"], "自己紹介": (it["post"].get("description") or "")[:120],
                      "投稿": it["post"]["text"][:700],
                      "紹介するもの": it["what"], "使ってよい事実": it["facts"]})
        if X.is_owner(it["post"]):
            cases[-1]["投稿した人の種類"] = "愛知の経営者"
        if it.get("url", "").startswith("https://xb4g.com/giin/tracker/"):
            cases[-1]["付けるトラッカー"] = it["label"]
        if it.get("url", "").startswith("https://exbridge.jp/keizai/"):
            cases[-1]["付ける経済指標のカード"] = it["label"]
        if it["links"]:
            cases[-1]["リンク候補"] = [dict(番号=i + 1, 種類=l["種類"], 題名=l["題名"], 説明=l["説明"]) for i, l in enumerate(it["links"])]
    prompt = f"""名古屋のシステム開発会社（株式会社エクスブリッジ）の担当者として、X の投稿への返信文を、下の候補それぞれに2つ書いてください（その投稿に合う型を2つ選び、どちらもそのまま投稿できる出来にする）。ファイルの読み書きやコマンドの実行はしないでください。

# いちばん大事なこと
- **ただの宣伝にしない。** 1文目で、相手の投稿の具体的な中身（相手の言葉・数字・出来事・問題意識）を拾って受け止める。決まり文句のほめ言葉ではなく、中身に触れた受け止めにする
- そのうえで、相手の論点を深める国会のやりとり（答弁・質問）や件数・年ごとの推移などの事実を、具体的に添える
- 最後に、見方を一言（「〜が要だと思います」「〜に注目しています」「〜の検証が必要だと思います」）。この文に「当社は」は付けない（人が話す言い方にする）
- 当社のシステムを紹介する場合も、まず相手の投稿への受け止め。システムは「〜を確かめられるシステムを開発しています」と書き、無料とは書かない（販売しているシステムのデモ）

# 守ること
- 1案は全角で60〜110字。**Xの数え方（全角1字=2・URL1本=23）でURL込み280以内に収める。**超えると長文ポストになり、リンク先のカード画像が出ない（お手本は型の参考で、長さはこちらに従う）。文ごとに改行（\n）する。型（答弁を示す・数字や論点を示す・本人の発言に寄せる・現場に寄り添う など）は、お手本から投稿に合うものを2つ選ぶ（2案は違う型・違う事実にする）
- 使ってよい事実に無い数字・日付・人名・会議名は書かない。かぎかっこで引用するときは quote の文字をそのまま使い、長ければ前後を「…」で切る（言い換えない）
- 引用する発言は、投稿の出来事そのものについての発言ではないことが多い。投稿の出来事について答えたと読める書き方はしない（年や会議名で区別する）
- 政治的な賛否で相手を責めない。人への批判はしない。感嘆符・ハッシュタグ・絵文字・URL は書かない（URL はこちらで付ける）
- 主語は「当社」（弊社は使わない）。「網羅」「お役に立てます」のような売り込みの言葉は使わない
- 返信しないほうがよい投稿（街頭演説やあいさつだけの投稿、他人への攻撃・罵倒、個人的な被害の吐露など、国会の事実を添えると失礼になるもの）は、drafts を空にする
- **リンクは当社のシステムのデモ・紹介ページ（LP）を優先する。** 「リンク候補」に投稿の中身に合う「当社のシステムのデモ・紹介ページ」があれば、それを link に入れる（国会トラッカーや記事より先）。
- link2 には、2つ目のURLとして付けると役に立つものを1つだけ入れる：link がデモ・紹介ページなら、投稿に合う国会トラッカー（リンク候補にあれば）。link が国会トラッカーや記事なら、投稿に合うデモ・紹介ページ。合うものが無ければ 0。link と同じ番号は入れない
- 候補に「付けるトラッカー」がある場合（トラッカーのカードは必ず付く。議員×テーマのカードか、質問と答弁が1枚になった質疑カード）、リンク候補（デモ・紹介ページ、または質問主意書）から投稿に合うものを1つ link に入れる。投稿者本人の質問主意書があれば、それを優先してよい。合うものが無ければ link は 0 でよい（その場合もトラッカーだけで返信を書く）。この場合 link2 は 0
- 「付けるトラッカー」が無く「リンク候補」がある候補は、投稿の中身にいちばん近いもの（デモ・紹介ページを優先）を1つ選んで link にその番号を入れる。投稿と話がずれるものしか無ければ link は 0 にし、drafts も空にする。選んだページは、題名と説明に書いてあることだけを使って、最後の一文で自然に触れてよい（「〜について記事に書きました」「〜を確かめられるシステムを開発しています」など）。リンク候補が無い候補は link を 0 にする
- 議員本人の投稿には、議員の問題意識に寄り添い、国会でのやりとりで論点を深める（本人の質問が発言にあればそれに触れる）。「投稿者」に本人の質疑や質問主意書の件数があれば、それに触れてよい
- 「カードに出る答弁」があれば、その答弁を文の中心にする（付けるカードと文が合う）
- 候補に「付ける経済指標のカード」がある場合（カードは必ず付く）、link は 0 にする。文は「カードに出る指標」の値と時点を中心にし、投稿の出来事と数字をつなぐ
- 「経済指標」があり、投稿が為替・金利・物価・景気の話なら、経済指標のカードを link に選んでよい（経営者の投稿には特に合う）。数字は「経済指標」に書いてある値と時点だけを使い、「〜月時点で」と時点を必ず添える。値の良し悪しや先行きの予想は書かない
- 「投稿した人の種類」が「愛知の経営者」の候補は、つながりたい相手。同じ地域の経営者として、相手の仕事の中身（業種・現場・悩み）を受け止め、経営の実務に役立つ事実（賃金・人手・物価・金利・制度の答弁や統計）を1つ添える。政治の話に引き込まない。売り込みや「ご相談ください」は書かない。投稿が日常・告知・宣伝だけで、添える事実が無ければ drafts を空にする
- 「関連する質問主意書」を使うときは、件名と提出者・提出日だけを事実として書く（答弁書の中身は書かない）

{STYLE}

# 候補
{json.dumps(cases, ensure_ascii=False, indent=1)}

replies に、候補の id ごとに link（選んだリンク候補の番号。無ければ 0）、link2（2つ目。無ければ 0）と drafts（type と text を2つ）を入れて返してください。"""
    env = dict(os.environ, PATH=os.path.dirname(CODEX_BIN) + ":" + os.environ.get("PATH", ""))   # codex は node で動く
    r = subprocess.run([CODEX_BIN, "exec", "--skip-git-repo-check", "--ephemeral", "-s", "read-only", "-m", CODEX_MODEL,
                        "--output-schema", sp, "-o", op, "-"], input=prompt, capture_output=True, text=True,
                       timeout=900, cwd=workdir, env=env)
    if r.returncode != 0 or not os.path.exists(op):
        raise RuntimeError(f"codex が失敗: {r.stderr[-500:]}")
    rep = json.loads(open(op, encoding="utf-8").read())["replies"]
    return {x["id"]: x["drafts"] for x in rep}, {x["id"]: (x.get("link", 0), x.get("link2", 0)) for x in rep}


# ---- カードを付ける（2026-10-19）----
# Xの返信は、貼ったページのカードで読まれ方が変わる（10/1〜18 実測: テーマ一覧のカードは2ページ目まで読んだ人7%、
# 議員×テーマのカードは32%）。一覧ではなく、投稿者本人や投稿の論点に合う1枚のカードを付ける
KSHUISHO_DB = os.path.join(WORK, "kshuisho", "php", "kshuisho_data", "kshuisho.sqlite")
KSHUISHO = "https://kurage.exbridge.jp/kshuisho.php"


def member_of(p):
    """投稿者が国会議員なら giin_all の行（Xのアカウント名で引く）。違えば None"""
    h = (p.get("screen_name") or "").lower()
    if not h:
        return None
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    cols = ["id", "slug", "name", "plain", "n_q", "n_gov"]
    r = c.execute("select " + ", ".join(cols) + " from giin_all where lower(x)=?", (h,)).fetchone()
    if r:
        return dict(zip(cols, r))
    # 名簿のXは1つだけ（蓮舫議員は事務所の renho_office、本人の投稿は renho_sha）。国会議員の投稿なら、
    # X の名簿（x_politicians.json）か表示名に、議員の名前がそのまま1人だけ入っていればその人
    if not X.is_kokkai(p):
        return None
    try:
        xp = json.load(open(os.path.join(X.ROOT, "data", "x_politicians.json"), encoding="utf-8"))
    except (OSError, ValueError):
        xp = {}
    t = re.sub(r"[\s　]", "", (xp.get(p.get("screen_name") or "") or "") + " " + (p.get("name") or ""))
    hit = [x for x in c.execute("select " + ", ".join(cols) + " from giin_all") if len(x[3]) >= 2 and x[3] in t]
    return dict(zip(cols, hit[0])) if len(hit) == 1 else None


def member_shuisho(m):
    """その議員の質問主意書の件数（scripts/build_kshuisho_giin.py が作る）"""
    try:
        return json.load(open(os.path.join(X.ROOT, "data", "kshuisho_giin.json"), encoding="utf-8")).get(m["slug"])
    except (OSError, ValueError):
        return None


def member_theme_card(m, key):
    """議員×テーマのカード（その議員がこのテーマで質問していれば）。id は会議録の表記の名前の crc32（lib/tracker_cards.php の g_tm_id）"""
    import zlib
    c = sqlite3.connect(os.path.join(X.ROOT, "data", "giin.sqlite"))
    r = c.execute("select speaker, count(*) from tracker_speech where tracker=? and giin_id=? and kind='q' group by speaker order by 2 desc limit 1",
                  (key, m["id"])).fetchone()
    if not r:
        return None, 0
    return f"https://xb4g.com/giin/tracker/{key}/m/{zlib.crc32(r[0].encode('utf-8')) & 0xffffffff:08x}", r[1]


def qa_card(key, post_text, words):
    """投稿にいちばん近い政府答弁の質疑カード（質問と答弁が1枚）。答弁が無ければ None。
    政府の答弁を先に探す（近い発言の上位には参考人の古い発言が入ることがある。2011年の証券会社の人など）"""
    for kind in ("gov", "ref"):
        a = closest_answers(key, post_text, words, k=1, kinds=(kind,))
        if a:
            return f"https://xb4g.com/giin/tracker/{key}/{a[0]['_sid']}", a[0]
    return None, None


def shuisho_hits(words, author=None, k=3, post=""):
    """質問主意書アシストの手元のDBを語で引く。件名に語が入るものを優先し、投稿者本人のものを先に、新しい順"""
    if not words or not os.path.exists(KSHUISHO_DB):
        return []
    c = sqlite3.connect(KSHUISHO_DB)
    me = (author or "").replace(" ", "")
    seen, out = set(), []
    for col in ("title", "q_text"):
        for w in words:
            parts = [x for x in w.split() if len(x) >= 2]
            if not parts:
                continue
            sql = ("select id, title, submitter, submit_date, evasive_json from q where " + " and ".join(f"{col} like ?" for _ in parts)
                   + " order by (replace(submitter, ' ', '')=?) desc, submit_date desc limit 6")
            for r in c.execute(sql, [f"%{x}%" for x in parts] + [me]):
                if r[0] not in seen:
                    seen.add(r[0]); out.append(r)
        if len(out) >= k:
            break
    # 語が一般的だと（「運用状況」など）論点の違うものが当たる。件名の定型（質問主意書・に関する）を除いた2文字の組が、
    # 投稿と2つ以上重なるものだけ残す
    if post:
        pb = bigrams(post)
        rel = lambda r: len(pb & {b for b in bigrams(re.sub(r"(再?質問主意書|に関する|について)", "", r[1])) if re.fullmatch(r"[一-龥々ァ-ヶーA-Za-zＡ-Ｚ]{2}", b)})
        out = [r for r in out if r[2].replace(" ", "") == me or rel(r) >= 2]
        out.sort(key=lambda r: (r[2].replace(" ", "") != me, -rel(r), -(int((r[3] or "0")[:4]) if r[3] else 0)))
    else:
        out.sort(key=lambda r: (r[2].replace(" ", "") != me, -(int((r[3] or "0")[:4]) if r[3] else 0)))
    return [{"id": r[0], "件名": r[1], "提出者": r[2], "提出日": r[3], "本人": r[2].replace(" ", "") == me,
             "定型句": list(json.loads(r[4] or "{}").keys())} for r in out[:k]]


def shuisho_links(hits):
    """質問主意書をリンク候補の形に（codex が link / link2 に選べる）"""
    return [{"種類": "質問主意書（1件ごとのカード。件名・提出者・質問と答弁書の抜き書きが1枚）",
             "題名": h["件名"], "説明": f"{h['提出者']}が{h['提出日']}に提出" + ("（投稿者本人の質問主意書）" if h["本人"] else ""),
             "url": f"{KSHUISHO}/q/{h['id']}"} for h in hits]


KEIZAI_JSON = os.path.join(WORK, "exbridge_jp", "keizai", "keizai.json")   # exbridge_jp/scripts/keizai_build.py が毎日作る


def keizai_hits(text, k=2):
    """投稿が為替・金利・物価・景気に触れていれば、経済指標のカード（exbridge.jp/keizai/<key>/）を候補にする"""
    try:
        items = json.load(open(KEIZAI_JSON, encoding="utf-8"))["items"]
    except (OSError, ValueError, KeyError):
        return []
    # 当たった語の種類が多い指標を先に（同じなら keizai.json の並び）。「日銀の利上げで借入金利が…」は
    # コールレートと貸出金利が2語ずつ当たるので、会社に近い貸出金利を先にする
    sc = [(len(set(re.findall(x["words"], text))) + (1 if x["key"] == "kashidashi-kinri" and re.search(r"借入|借り入れ|融資", text) else 0), -i, x)
          for i, x in enumerate(items)]
    hit = [x for n, _, x in sorted(sc, key=lambda t: (-t[0], -t[1])) if n > 0]
    return hit[:k]


def keizai_fact(x):
    t, v = x["latest"]
    u = x["unit"]
    s = f"{x['name']}（{x['sub']}）は{x['label']}に{v}{u}" + ("（前年同月比）" if x["kind"] == "yoy" else "")
    return s + "".join(f"、{n}（{lab}）は{pv}{u}" for n, lab, pv in x["compare"]) + "（日本銀行の統計）"


def make_item(p, field, mmdd, r=None):
    """投稿1件に、付けるリンク・codex に渡す事実を用意する。付けられるものが無ければ None"""
    tr = X.match_tracker(field, p["text"])
    sy = X.match_system(field, p["text"]) if field else next(
        (m for f in X.SYSTEMS for m in [X.match_system(f, p["text"])] if m), None)
    pol = X.is_politician(p)
    mem = member_of(p)
    kf, links, kw = None, [], []
    if tr:
        # トラッカーが当たった投稿でも、当社のシステムのデモ・紹介ページ（LP）が合えば一緒に付ける（2026-10-05 ユーザー指示「LPがあるならトラッカーより優先」）
        links = [l for l in related_links(p["text"], k=16) if l["種類"].startswith("当社のシステム")][:4]
    if not (tr or sy):
        # トラッカーもシステムも当たらない投稿は、会議録をその場で検索し、近い当社のページ（ブログ・note・デモ・
        # 解説ページ）を候補に出す。どれに付けるか（付けないか）は codex が投稿を読んで選ぶ
        links = related_links(p["text"], k=10)
        # LP（デモ・紹介ページ）を先頭に並べる（codex にも優先させる）
        links = [l for l in links if l["種類"].startswith("当社のシステム")] + [l for l in links if not l["種類"].startswith("当社のシステム")]
        # リンク先が無ければ出さない（トラッカーの一覧には付けない）。質問主意書と議員ページも候補になるので、判定は最後で
        kf, kw = kokkai_facts(p["text"])
        if kf and not kf["発言"]:
            kf = None
    ref = f"x-{(p['screen_name'] or 'x').lower()}-{mmdd}"
    if not (tr or sy):
        what = ("国会会議録（全国の国会議員の質疑と政府の答弁）と、下のリンク候補（当社が公開している記事・システムのデモ・解説ページ）"
                if kf else "下のリンク候補（当社が公開している記事・システムのデモ・解説ページ）")
        facts = kf or {}
        url = ""   # codex が選んだリンク候補だけを付ける（選ばれなければ出さない）
        label = "関連ページ（トラッカー未作成）"
        fb = ""
    elif tr:
        what = f"国会トラッカー「{tr['name']}」: 全国の国会議員の質疑と政府の答弁を、国会会議録から集めて並べたもの"
        facts = tracker_facts(tr["key"])
        facts["発言"] = closest_answers(tr["key"], p["text"], tr.get("words") or [tr["short"]])
        url = with_ref(f"https://xb4g.com/giin/tracker/{tr['key']}", ref)
        label = f"トラッカー: {tr['short']}"
        # 一覧ではなく1枚のカードを付ける: 投稿者本人がこのテーマで質問していれば議員×テーマのカード、
        # なければ投稿にいちばん近い政府答弁の質疑カード
        mc, mn = member_theme_card(mem, tr["key"]) if mem else (None, 0)
        if mc:
            url = with_ref(mc, ref)
            label = f"議員×テーマのカード: {tr['short']}（本人の質疑{mn}件）"
            facts["投稿者本人のこのテーマの質疑"] = f"{mn}件"
        else:
            qc, qa = qa_card(tr["key"], p["text"], tr.get("words") or [tr["short"]])
            if qc:
                url = with_ref(qc, ref)
                label = f"質疑カード: {tr['short']}（{qa['who']}の答弁）"
                facts["カードに出る答弁"] = {k: v for k, v in qa.items() if not k.startswith("_")}
        fb = f"この論点が国会でどう議論されてきたか、質疑{facts['質疑の件数']}件と政府答弁{facts['政府答弁の件数']}件を会議録から並べています。"
    else:
        what = f"{sy[0]}: {SYSTEM_FACTS.get(sy[0], '')}"
        facts = {"説明": SYSTEM_FACTS.get(sy[0], "")}
        url = with_ref(sy[1], ref)
        label = f"システム: {sy[0]}"
        fb = f"{SYSTEM_FACTS.get(sy[0], '').split('。')[0]}を開発しています。"
    # 質問主意書のカード（投稿者本人のものを先に）。トラッカーの語、なければ会議録を引いた語で探す
    if tr:
        words = (tr.get("words") or [tr["short"]])[:4]
    elif not sy:
        words = [w for w in kw if w]
    else:   # システムが当たった投稿は、質問主意書の話のときだけ探す
        words = [w for w in keywords(p["text"]) if w] if sy[0] == "質問主意書の検索" else []
    sh = shuisho_hits(words, mem["name"] if mem else None, post=p["text"])
    if sh:
        links = links + shuisho_links(sh)
        facts = dict(facts, 関連する質問主意書=[{k: v for k, v in h.items() if k != "id"} for h in sh])
    if mem:
        ms = member_shuisho(mem)
        facts = dict(facts, 投稿者=f"{mem['plain']}議員（国会での質疑{mem['n_q']}件・答弁{mem['n_gov']}件（2023年から）"
                     + (f"・提出した質問主意書{ms['n']}件（{ms['first'][:4]}年から）" if ms else "") + "）")
        if not (tr or sy) or (not tr and not links):
            links = links + [{"種類": "議員ページ（投稿者本人の国会での質疑と質問主意書の件数のカード）", "題名": f"{mem['plain']}議員の国会発言",
                              "説明": "国会での質疑・答弁の件数、年ごとの推移、直近の質問、提出した質問主意書の件数", "url": f"https://xb4g.com/giin/{mem['slug']}"}]
    # 経済指標のカード（経営者の投稿で、円安・物価・金利・景気に触れているとき）
    kz = keizai_hits(p["text"])
    if kz and not (tr or sy):
        # 経済のニュース・投稿（トラッカーもシステムも当たらない）は、経済指標のカード1枚を必ず付ける（2026-10-19 ユーザー指示）。
        # ほかのリンク候補は付けない（URLを1本にして、Xの数え方の280に本文の余裕を残し、カードを確実に出す）
        x = kz[0]
        url = with_ref(x["url"], ref)
        label = f"経済指標のカード: {x['name']}"
        what = f"経済指標のカード「{x['name']}」: 日本銀行の統計から、最新の値と1年前との差、2年分の推移を1枚にした当社のページ"
        facts = dict(facts or {}, 経済指標=[keizai_fact(y) for y in kz], カードに出る指標=keizai_fact(x))
        t, v = x["latest"]
        fb = f"{x['name']}は{x['label']}時点で{v}{x['unit']}" + (f"、1年前は{x['compare'][-1][2]}{x['unit']}でした。" if x["compare"] else "でした。")
        links = []
    elif kz:
        links = [{"種類": "経済指標のカード（当社のページ。最新の値と2年の推移のグラフが1枚。日本銀行の統計）", "題名": x["name"],
                  "説明": keizai_fact(x), "url": x["url"]} for x in kz] + links
        facts = dict(facts, 経済指標=[keizai_fact(x) for x in kz])
    if not url and not links:
        return None
    for a in facts.get("発言", []) if isinstance(facts, dict) else []:
        a.pop("_sid", None)
    return {"id": p["id"], "post": p, "r": r or {}, "what": what, "facts": facts, "url": url, "label": label, "fb": fb,
            "links": links, "ref": ref}



def with_ref(url, ref):
    """計測の ref を #ref=… で付ける（2026-10-10）。?ref= だと Google が ref ごとに別のURLとして拾い、
    canonical を書いていても ref 付きのURLが検索結果に出て、本来のURLが読まれなかった。# から後ろは
    Google がURLとして扱わず、simpletrack は location.href ごと記録するので人数は数えられる"""
    return url + ("&" if "#" in url else "#") + "ref=" + ref

def finish(items, texts, picks):
    """codex の答え（文案・選んだリンク）を検査して、ページに出すカードにする"""
    cards = []
    for it in items:
        n, n2 = picks.get(it["id"], (0, 0))
        tracker_url = it["url"] if it["url"].startswith("https://xb4g.com/giin/tracker/") else ""
        if tracker_url and it["links"] and 1 <= n <= len(it["links"]):
            # トラッカー＋LP：LP を先に、トラッカーを2つ目に
            ln = it["links"][n - 1]
            it["url"] = with_ref(ln["url"], it["ref"]) + "\n" + tracker_url
            it["label"] = f"{ln['種類'].split('（')[0]}「{ln['題名'][:30]}」＋{it['label']}"
            it["facts"] = dict(it["facts"], リンク先=ln["題名"] + "。" + ln["説明"])
            n = 0
        if it["links"] and 1 <= n <= len(it["links"]):   # codex が選んだ関連ページに付け替える
            ln = it["links"][n - 1]
            it["url"] = with_ref(ln["url"], it["ref"])
            if ln["url"].startswith("https://xb4g.com/giin/tracker/"):   # 既存のトラッカー（分野の規則では当たらず、語の近さで選んだ）
                it["label"] = f"トラッカー: {ln['題名'][:40]}（語の近さで選択）"
            else:
                it["label"] = f"関連: {ln['種類'].split('（')[0]}「{ln['題名'][:40]}」"
            it["facts"] = dict(it["facts"], リンク先=ln["題名"] + "。" + ln["説明"])
            if 1 <= n2 <= len(it["links"]) and n2 != n:   # 2つ目のURL（デモ＋トラッカー、記事＋デモ など）。LP を先に置く
                l2 = it["links"][n2 - 1]
                u2 = with_ref(l2["url"], it["ref"])
                lp_first = l2["種類"].startswith("当社のシステム") and not ln["種類"].startswith("当社のシステム")
                it["url"] = u2 + "\n" + it["url"] if lp_first else it["url"] + "\n" + u2
                it["label"] += f"＋「{l2['題名'][:30]}」"
        if not it["url"]:
            continue   # 関連ページ（デモ・ブログ・note・個別のトラッカー）が選ばれなかった
        drafts = []
        for d in texts.get(it["id"], []):
            t = re.sub(r"https?://\S+", "", d.get("text", "")).strip()
            why = check(it, t)
            if not why:   # 検査に通らない案（引用の不一致・事実に無い数字など）は出さない
                drafts.append({"type": d.get("type", ""), "text": t + "\n" + it["url"]})
        if not texts.get(it["id"]):
            continue   # codex が「返信しないほうがよい」と判断した投稿
        if not drafts:
            if not it["fb"]:
                continue
            drafts = [{"type": "定型文（codex の案はすべて検査で落ちた）", "text": it["fb"] + "\n" + it["url"]}]
        cards.append({"p": it["post"], "r": it["r"], "label": it["label"], "drafts": drafts})
    return cards


# 返信は投稿から60分以内が目安。候補に出すのは、ページを書き出す時点で投稿から30分以内のものだけ（2026-10-09 ユーザー指示「30分以内にしようか」）。
# 集めるのは直近40分でも、判定と文案に15〜20分かかり、公開時点では45〜61分たっていた（10/9 06:33〜10:34 の各回を実測）
MAX_AGE_MIN = 30


def age_min(p, now=None):
    """投稿からの経過分。created_timestamp が無ければ投稿IDの時刻部分（snowflake）から出す"""
    ts = p.get("created_timestamp")
    if not ts:
        m = re.search(r"(\d{15,})", str(p.get("id") or p.get("url") or ""))
        ts = ((int(m.group(1)) >> 22) + 1288834974657) / 1000 if m else 0
    return ((now or time.time()) - ts) / 60 if ts else 1e9


def max_age(p):
    """候補に出す投稿の古さの上限（分）。愛知の経営者はつながるのが目的なので、時事の速さより拾い漏れを防ぐ"""
    return X.OWNER_WITHIN_MIN if X.is_owner(p) else MAX_AGE_MIN


def build(posts_path):
    day = os.path.basename(os.path.dirname(posts_path))
    stamp = os.path.basename(posts_path)[6:-5]
    posts = {p["id"]: p for p in json.load(open(posts_path, encoding="utf-8"))}
    judge = json.load(open(sorted(glob.glob(os.path.join(os.path.dirname(posts_path), f"judge-jevlocal-{stamp}*.json")),
                                  key=os.path.getmtime)[-1], encoding="utf-8"))
    mmdd = day[5:7] + day[8:10]
    cards, items = [], []
    try:
        shown = set(json.load(open(OWNER_SHOWN, encoding="utf-8")))
    except (OSError, ValueError):
        shown = set()
    for r in judge:
        p = posts[r["id"]]
        own = X.is_owner(p)
        if own and p["id"] in shown:
            continue
        if r["field"] == "none" and not (own and re.search(OWNER_RX, p["text"])):
            continue
        if re.search(SKIP, p["text"]):   # 皇室・弔事・事故の死傷には売り込みをしない
            continue
        it = make_item(p, r["field"], mmdd, r)
        if it:
            items.append(it)
    # 文案を作る対象: 国会議員の投稿を N_KOKKAI 件まで先に取り（その中は表示の多い順）、残りの枠は表示の多い順。
    # 国会議員を全部先に取ると議員だけで枠が埋まり、表示の多いニュースが落ちるので、上限を決めて分ける
    items = [it for it in items if age_min(it["post"]) <= max_age(it["post"])]   # 文案を作る前に、もう古いものは外す
    by_views = sorted(items, key=lambda it: -it["post"]["views"])
    kokkai = [it for it in by_views if X.is_kokkai(it["post"])][:N_KOKKAI]
    owner = [it for it in by_views if X.is_owner(it["post"])][:N_OWNER]
    ids = {it["id"] for it in kokkai + owner}
    items = kokkai + owner + [it for it in by_views if it["id"] not in ids][:MAX_ITEMS - len(kokkai) - len(owner)]
    texts, picks = codex_batch(items, os.path.abspath(os.path.dirname(posts_path))) if items else ({}, {})
    cards = finish(items, texts, picks)
    cards = [c for c in cards if age_min(c["p"]) <= max_age(c["p"])]   # 文案を作っている間に30分を過ぎたものも外す
    # ページは国会議員を先に、次に愛知の経営者、その中と残りは表示の多い順
    cards.sort(key=lambda c: (not X.is_kokkai(c["p"]), not X.is_owner(c["p"]), -c["p"]["views"]))
    if any(X.is_owner(c["p"]) for c in cards):
        json.dump(sorted(shown | {c["p"]["id"] for c in cards if X.is_owner(c["p"])}), open(OWNER_SHOWN, "w", encoding="utf-8"))
    out = os.path.join(os.path.dirname(posts_path), f"reply-{stamp}.html")
    empty = None
    if not cards:
        # 0件の回でも公開ページは置き換わる。深夜は直近40分の投稿が十数件しかなく0件になる（2026-10-02 04:11 実測:
        # 投稿18件・表示1,000以上3件）。理由と、候補が出ていた直前の回（deploy が <日付>-<時分>.html で残している）を出す
        prev = []
        for rp in sorted(glob.glob(os.path.join(X.OUT, "*", "reply-*.html")), reverse=True):
            m = re.search(r"から (\d+)件", open(rp, encoding="utf-8").read())
            if m and int(m.group(1)) > 0:
                prev.append((os.path.basename(os.path.dirname(rp)), os.path.basename(rp)[6:-5], int(m.group(1))))
            if len(prev) >= 3:
                break
        empty = {"posts": len(posts), "v1000": sum(1 for p in posts.values() if (p.get("views") or 0) >= 1000), "prev": prev}
    open(out, "w", encoding="utf-8").write(page(day, stamp, cards, empty))
    # メール通知用の要約（x_reply_jobs.py が読んで katsushi2441@gmail.com へ送る。2026-10-02 ユーザー指示）
    summary = [{"name": c["p"].get("name"), "screen_name": c["p"].get("screen_name"), "views": c["p"].get("views"),
                "url": c["p"].get("url"), "text": re.sub(r"\s+", " ", c["p"].get("text") or "")[:200], "label": c["label"],
                "politician": X.is_politician(c["p"]), "owner": X.is_owner(c["p"]),
                "drafts": [d["text"] for d in c["drafts"]],
                "intents": ["https://x.com/intent/post?" + urllib.parse.urlencode({"in_reply_to": c["p"]["id"], "text": d["text"]})
                            for d in c["drafts"]]} for c in cards]
    json.dump(summary, open(out[:-5] + ".json", "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    return out, len(cards)


def card_html(c, pre="t"):
    """カード1枚の HTML（定時のページと、URL を入れて作った1件で同じものを使う）。pre は textarea の id の頭"""
    p = c["p"]
    ago = int((time.time() - p["created"]) / 60)
    body = html.escape(re.sub(r"\s+", " ", p["text"])[:400])
    ds, n = [], 0
    for d in c["drafts"]:
        intent = "https://x.com/intent/post?" + urllib.parse.urlencode({"in_reply_to": p["id"], "text": d["text"]})
        ds.append(f"""<div class="draft"><div class="dtype">{html.escape(d['type'])}</div>
<textarea id="{pre}{n}" rows="5">{html.escape(d['text'])}</textarea>
<div class="btns"><button type="button" class="cp" data-i="{pre}{n}">返信文をコピー</button>
<a class="go" data-i="{pre}{n}" href="{html.escape(intent)}" target="_blank" rel="noopener">文入りで返信画面を開く</a></div></div>""")
        n += 1
    return f"""<article>
<div class="who">{'<span class="pol kokkai">国会議員</span>' if X.is_kokkai(p) else ('<span class="pol">議員</span>' if X.is_politician(p) else ('<span class="pol">愛知の経営者</span>' if X.is_owner(p) else ''))}<b>{html.escape(p['name'] or '')}</b> @{html.escape(p['screen_name'] or '')}・フォロワー{p['followers']:,}・表示{p['views']:,}・♥{p['likes']:,}・{ago}分前</div>
<p class="post">{body}</p>
<a class="src" href="{html.escape(p['url'])}" target="_blank" rel="noopener">元の投稿を開く</a>
<div class="tag">{html.escape(c['label'])}</div>
{''.join(ds)}
</article>"""


# 「URL を入れて1件作る」の取り次ぎ（ask.php）を置いたときだけ、ページに入力欄を出す（無いと押しても失敗するだけなので）
ASK_PHP = os.path.join(HERE, "xreply_ask.php")


def page(day, stamp, cards, empty=None):
    rows = [card_html(c, f"c{i}-") for i, c in enumerate(cards)]
    if empty is not None:
        links = "".join(f'<li><a href="{d}-{t}.html">{d[5:7]}/{d[8:10]} {t[:2]}:{t[2:]} の回（{n}件）</a></li>' for d, t, n in empty["prev"])
        rows = [f"""<article><p><b>この回（{stamp[:2]}:{stamp[2:]}）は候補が0件でした。</b>直近40分に集まった投稿は{empty['posts']}件、表示1,000以上は{empty['v1000']}件で、返信に向く投稿がありませんでした。深夜〜早朝は投稿が少なく、0件になりやすい時間です。</p>
{f'<p>候補が出ていた直前の回:</p><ul>{links}</ul>' if links else ''}<p class="lead">返信したい投稿があれば、上の欄に URL を入れると、その場で返信候補を作れます。</p></article>"""]
    form = "" if os.path.exists(ASK_PHP) else " hidden"
    return f"""<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title>X 返信候補 {day} {stamp}</title><style>
body{{margin:0;background:#f4f7f8;color:#13232c;font:15px/1.7 system-ui,"Noto Sans JP",sans-serif}}
main{{max-width:760px;margin:0 auto;padding:16px}} h1{{font-size:20px;margin:8px 0 4px}} .lead{{color:#4d5f68;font-size:13px;margin:0 0 16px}}
article{{background:#fff;border:1px solid #d8e3e7;border-radius:10px;padding:14px;margin:0 0 14px;overflow-wrap:anywhere}}
.who{{font-size:13px;color:#4d5f68}} .post{{margin:6px 0}} .src{{font-size:13px}} .tag{{margin:8px 0 4px;font-size:12px;font-weight:700;color:#0a726b}}
textarea{{box-sizing:border-box;width:100%;font:inherit;border:1px solid #c5d3d8;border-radius:8px;padding:8px}}
.pol{{display:inline-block;background:#b7791f;color:#fff;font-size:11px;font-weight:700;border-radius:4px;padding:0 6px;margin-right:6px}}
.pol.kokkai{{background:#0a726b}}
.draft{{border-top:1px dashed #d8e3e7;margin-top:10px;padding-top:8px}} .dtype{{font-size:12px;color:#4d5f68;margin-bottom:4px}}
.btns{{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}} button,.go{{font:inherit;font-size:14px;font-weight:700;border-radius:99px;padding:7px 16px;cursor:pointer;text-decoration:none}}
button{{background:#fff;border:1px solid #0a9a8f;color:#0a726b}} .go{{background:#0a9a8f;color:#fff;border:1px solid #0a9a8f}}
.one{{background:#fff;border:2px solid #0a9a8f;border-radius:10px;padding:12px 14px;margin:0 0 16px}} .one label{{display:block;font-size:13px;font-weight:700;margin-bottom:6px}}
.one .row{{display:flex;gap:8px;flex-wrap:wrap}} .one input{{flex:1 1 220px;min-width:0;font:inherit;border:1px solid #c5d3d8;border-radius:8px;padding:8px}}
.one button{{background:#0a9a8f;color:#fff}} .one button:disabled{{opacity:.5;cursor:wait}} .ostate{{font-size:13px;color:#4d5f68;margin-top:6px;min-height:1.2em}} .ostate.err{{color:#b42318}}
#mine article{{border:2px solid #0a9a8f;margin-top:4px}} .h2{{font-size:16px;margin:18px 0 8px}} [hidden]{{display:none!important}}
.minehead{{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#4d5f68}} .del{{font-size:12px;padding:2px 10px;border-color:#c5d3d8;color:#4d5f68}}
.auto{{background:#fff7e6;border:3px solid #e07a2e;border-radius:12px;padding:14px 16px;margin:0 0 16px}} .auto b{{display:block;font-size:17px}}
.auto .ostate{{font-size:15px;color:#13232c;font-weight:700}} .auto a{{font-size:13px}} .hl{{outline:4px solid #e07a2e;outline-offset:3px;border-radius:10px}}
</style></head><body><main><h1>X 返信候補 {day} {stamp[:2]}:{stamp[2:]}</h1>
<section id="auto" class="auto" hidden><b id="autot">メールのリンクから開きました。この投稿の返信候補を作っています（1〜2分）</b>
<a id="autou" href="#" target="_blank" rel="noopener">元の投稿を開く</a><div id="autos" class="ostate">受け付けています…</div></section>
<form id="one" class="one"{form}><label for="ourl">返信したい X の投稿の URL を入れると、その投稿の返信候補を作ります（1〜3分）</label>
<div class="row"><input id="ourl" type="url" required placeholder="https://x.com/アカウント/status/数字"><button type="submit" id="obtn">返信候補を作る</button></div>
<div id="ostate" class="ostate"></div></form>
<section id="minewrap" hidden><h2 class="h2">URL から作った候補（7日・30件まで残ります）</h2><div id="mine"></div></section>
<h2 class="h2">定時の候補</h2>
<p class="lead">定時の候補: 直近40分の、表示1,000以上の投稿と議員・首長（表示100以上）の投稿から {len(cards)}件（表示の多い順）。文は直してから使える（ボタンは直した文を使う）。投稿するのは人。</p>
{''.join(rows)}
<script>
function cur(i){{return document.getElementById(i).value}}
document.addEventListener('click',function(e){{
  var b=e.target.closest('.cp');
  if(b){{navigator.clipboard.writeText(cur(b.dataset.i)).then(function(){{b.textContent='コピーしました';setTimeout(function(){{b.textContent='返信文をコピー'}},1500)}});return}}
  var a=e.target.closest('.go');
  if(a){{var u=new URL(a.href);u.searchParams.set('text',cur(a.dataset.i));a.href=u.toString();}}
}});
var st=document.getElementById('ostate'),btn=document.getElementById('obtn'),mine=document.getElementById('mine');
function add(it,top){{
  if(document.getElementById('j'+it.id))return;
  var w=document.createElement('div');w.id='j'+it.id;w.className='mineitem';
  var t=new Date(it.t*1000),hm=(t.getMonth()+1)+'/'+t.getDate()+' '+t.getHours()+':'+('0'+t.getMinutes()).slice(-2);
  w.innerHTML='<div class="minehead"><span>'+hm+' に作成（「分前」は作成時点）</span><button type="button" class="del" data-id="'+it.id+'">消す</button></div>'+it.html;
  if(top)mine.prepend(w);else mine.appendChild(w);
  document.getElementById('minewrap').hidden=false;
}}
fetch('ask.php?list=1').then(function(r){{return r.json()}}).then(function(d){{(d.items||[]).forEach(function(it){{add(it,false)}})}}).catch(function(){{}});
mine.addEventListener('click',function(e){{var b=e.target.closest('.del');if(!b)return;
  fetch('ask.php?del='+encodeURIComponent(b.dataset.id)).then(function(){{var x=document.getElementById('j'+b.dataset.id);if(x)x.remove();
  if(!mine.children.length)document.getElementById('minewrap').hidden=true}})}});
var AUTO=false;
function say(t,err){{st.textContent=t;st.className='ostate'+(err?' err':'');
  if(AUTO){{var a=document.getElementById('autos');a.textContent=t;a.className='ostate'+(err?' err':'')}}}}
document.getElementById('one').addEventListener('submit',function(e){{
  e.preventDefault();var url=document.getElementById('ourl').value.trim();
  btn.disabled=true;say('受け付けています…');
  var fd=new FormData();fd.append('url',url);
  fetch('ask.php',{{method:'POST',body:fd}}).then(function(r){{return r.json()}}).then(function(d){{
    if(!d.id){{btn.disabled=false;say(d.error||'受け付けられませんでした',1);return}}
    var t0=Date.now();
    (function poll(){{fetch('ask.php?id='+encodeURIComponent(d.id)).then(function(r){{return r.json()}}).then(function(s){{
      if(s.state==='done'){{btn.disabled=false;
        if(s.html){{say('できました（'+Math.round((Date.now()-t0)/1000)+'秒）。下の「URL から作った候補」に残ります');add({{id:d.id,t:Date.now()/1000,html:s.html}},true);
          if(AUTO){{document.getElementById('autot').textContent='できました。すぐ下に返信候補を出しています';
            var x=document.getElementById('j'+d.id);if(x){{x.classList.add('hl');x.scrollIntoView({{behavior:'smooth',block:'start'}})}}}}}}
        else {{say(s.message||'返信候補を作れませんでした',1);if(AUTO)document.getElementById('autot').textContent='この投稿の返信候補は作れませんでした（理由は下）'}}return}}
      if(s.state==='error'){{btn.disabled=false;say(s.message||'失敗しました',1);if(AUTO)document.getElementById('autot').textContent='返信候補を作れませんでした（理由は下）';return}}
      say((s.message||'作っています')+'…（'+Math.round((Date.now()-t0)/1000)+'秒）');setTimeout(poll,4000);
    }}).catch(function(){{setTimeout(poll,6000)}})}})();
  }}).catch(function(){{btn.disabled=false;say('送れませんでした。時間をおいてもう一度',1)}});
}});

// メール（国会議員の投稿の通知）のリンクから ?url=… で開いたら、その投稿の返信候補を自動で作り始める（2026-10-08）
(function(){{try{{var q=new URLSearchParams(location.search).get('url');
  if(q&&/^https:\/\/(x|twitter)\.com\/[A-Za-z0-9_]+\/status\/\d+/.test(q)){{
    AUTO=true;var box=document.getElementById('auto');box.hidden=false;document.getElementById('autou').href=q;
    var f=document.getElementById('one');document.getElementById('ourl').value=q;
    window.scrollTo(0,0);f.requestSubmit?f.requestSubmit():f.dispatchEvent(new Event('submit',{{cancelable:true}}));
    history.replaceState(null,'',location.pathname+'#one')}}}}catch(e){{}}}})();
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
    f.storbinary(f"STOR {d}/.htaccess", io.BytesIO(b"AddHandler php-script .php\nHeader set X-Robots-Tag \"noindex, nofollow\"\nOptions -Indexes\n"))
    # 「URL を入れて1件作る」の取り次ぎ。合言葉は kaima/.env から埋める（表示しない・リポジトリに入れない）
    tok = next((ln.split("=", 1)[1].strip() for ln in open("/home/kojima/work/kaima/.env", encoding="utf-8")
                if ln.startswith("RELAY_XREPLY_TOKEN=")), "")
    php = open(ASK_PHP, encoding="utf-8").read().replace("__TOKEN__", tok)
    f.storbinary(f"STOR {d}/ask.php", io.BytesIO(php.encode()))
    # URL から作った候補の保存先。PHP はフォルダに新しいファイルを作れない（heteml・Permission denied）ので、
    # 無いときだけ空で作って書き込み可にする（あれば中身を残す）
    if "mine.json" not in [os.path.basename(x) for x in f.nlst(d)]:
        f.storbinary(f"STOR {d}/mine.json", io.BytesIO(b"[]"))
    f.sendcmd(f"SITE CHMOD 666 {d}/mine.json")
    f.storbinary(f"STOR {d}/index.html", io.BytesIO(data))
    f.storbinary(f"STOR {d}/{day}-{stamp}.html", io.BytesIO(data))
    f.quit()
    return f"https://proto.exbridge.jp/xreply-{token}/"


# 作った1件は投稿IDごとに残し、同じ投稿ならその場で返す（2026-10-09）。押してから codex で作ると毎回10〜40秒待たされ、
# 同時に1件しか作れないので続けて押すと断られていた。国会議員のX監視は「★高い」をメールの前にここで作っておく
ONE_CACHE = os.path.join(X.OUT, "one", "cache")
ONE_CACHE_SEC = 6 * 3600


def one_cache_path(post_id):
    return os.path.join(ONE_CACHE, f"{post_id}.json")


def one(url, use_cache=True):
    """URL を入れた1件の返信候補（ページの「返信候補を作る」から呼ぶ）。{"html": カード} か {"message": 作れなかった理由}"""
    m = re.search(r"(?:x|twitter)\.com/([A-Za-z0-9_]+)/status/(\d+)", url or "")
    if not m:
        return {"message": "X の投稿の URL（https://x.com/…/status/数字）を入れてください"}
    cp = one_cache_path(m.group(2))
    if use_cache and os.path.exists(cp) and time.time() - os.path.getmtime(cp) < ONE_CACHE_SEC:
        try:
            return json.load(open(cp, encoding="utf-8"))
        except ValueError:
            pass
    r = _one(url, m)
    if r.get("html"):
        os.makedirs(ONE_CACHE, exist_ok=True)
        json.dump(r, open(cp, "w", encoding="utf-8"), ensure_ascii=False)
    return r


def _one(url, m):
    r = subprocess.run(["curl", "-s", "-m", "30", "-A", "Mozilla/5.0", f"https://api.fxtwitter.com/2/status/{m.group(2)}"],
                       capture_output=True, text=True).stdout
    try:
        t = json.loads(r).get("status") or json.loads(r).get("tweet")
    except ValueError:
        t = None
    if not t:
        return {"message": "投稿を読めませんでした（消された・鍵つき・URL違いのどれか）"}
    p = X.slim(t)
    p["url"] = p.get("url") or f"https://x.com/{m.group(1)}/status/{m.group(2)}"
    # 引用の投稿は、話の中身が引用元にあることが多い（玉木代表「調査すると明言していただきたかった」＋引用元の速報）。
    # 引用元の本文も渡さないと、付けるページも文案も決められない（2026-10-08）
    q = t.get("quote") or {}
    if q.get("text"):
        p["text"] = (p.get("text") or "") + "\n［引用元 @" + ((q.get("author") or {}).get("screen_name") or "") + "］" + q["text"]
    it = make_item(p, None, datetime.date.today().strftime("%m%d"))
    if not it:
        return {"message": "この投稿に付けられる当社のページ（トラッカー・デモ・ブログ・note）が見つかりませんでした"}
    wd = os.path.join(X.OUT, "one", datetime.date.today().isoformat(), p["id"])
    os.makedirs(wd, exist_ok=True)
    texts, picks = codex_batch([it], wd)
    cards = finish([it], texts, picks)
    if not cards:
        return {"message": "返信しないほうがよい投稿か、付けられるページが無いと判断しました（codex）"}
    return {"html": card_html(cards[0], f"m{p['id']}-"), "label": cards[0]["label"]}


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--posts")
    ap.add_argument("--deploy", action="store_true")
    ap.add_argument("--url", help="この1件だけ返信候補を作り、結果を JSON で標準出力に出す")
    a = ap.parse_args()
    if a.url:
        print(json.dumps(one(a.url), ensure_ascii=False))
        sys.exit(0)
    pp = a.posts or sorted(glob.glob(os.path.join(X.OUT, datetime.date.today().isoformat(), "posts-*.json")))[-1]
    out, n = build(pp)
    print(out, n)
    if a.deploy:
        print(deploy(out))
