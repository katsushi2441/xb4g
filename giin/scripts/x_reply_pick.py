#!/usr/bin/env python3
"""X の返信先選び（自社用）。

毎日 X から投稿を集め、「当社の国会トラッカーやシステムの数字を添えて返信すると話が深まる投稿か」
「どの分野か」を判断モデルに選ばせて、返信の候補を並べる。返信の文を書くのと投稿は人がやる。

  collect  … fxtwitter の検索（ログイン不要・X の検索演算子が効く）で投稿を集める
  pick     … 判断モデルで分野（どれでもない を含む13択）を判定し、分野からトラッカーとシステムを規則で当てて候補表を作る

X は api.fxtwitter.com/2/search で読む（twitter-cli やログインは使わない）。

使い方:
  /usr/bin/python3 scripts/x_reply_pick.py collect [--within 60] [--min-faves 5]   # 直近60分の投稿
  /usr/bin/python3 scripts/x_reply_pick.py pick [--min-views 2000] [--backend jevlocal|laya|ollama]
出力: outputs/x_reply_pick/<日付>/posts-<時分>.json・candidates-<判断>-<時分>.md
"""
import argparse, datetime, json, os, re, subprocess, sys, time, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = os.path.join(ROOT, "outputs", "x_reply_pick")
sys.path.insert(0, HERE)
import decide_backends  # noqa: E402

# 分野（判断モデルの選択肢）。トラッカーの theme をまとめたもの。キーは短い英字（ロジットで読むため）
FIELDS = {
    "bousai": "防災・災害・被災者支援（大雨、地震、土砂、浸水、罹災証明、避難）",
    "iryo": "医療・介護・出産（病院、薬、介護保険、救急、内密出産、特別養子縁組）",
    "kosodate": "子育て・教育（保育、学校、教員、こども、不登校、児童手当）",
    "zeisei": "税・財政・年収の壁（消費税、減税、所得税、控除、予算、国債）",
    "rodo": "働き方・中小企業（育休、カスハラ、公益通報、価格転嫁、賃上げ）",
    "anzen": "安全保障・外交（防衛、自衛隊、情報機関、経済安保、敵国条項）",
    "kotsu": "交通（自動運転、空港、物流、運転代行、リニア）",
    "seiji": "政治とカネ・選挙（政党交付金、収支報告書、裏金、議員の活動）",
    "digital": "デジタル・AI（AI、マイナンバー、電子カルテ、デジタル民主主義）",
    "josei": "女性の人権・男女共同参画（更年期、性暴力、選択的夫婦別姓）",
    "chiho": "地方自治・都市計画（特別市、自治体、用途地域、まちづくり）",
    "shiho": "司法・治安（誹謗中傷、刑事手続、犯罪）",
    "none": "上のどれでもない（芸能、スポーツ、日常、商品の宣伝、政策と関係ない話）",
}
THEME_TO_FIELD = {
    "nankai-bosai": "bousai", "iryo-kaigo": "iryo", "naimitsu-shussan": "iryo",
    "kosodate": "kosodate", "zeisei": "zeisei", "zaisei": "zeisei", "nenshu-no-kabe": "zeisei",
    "rodo": "rodo", "chusho": "rodo", "anzen-hosho": "anzen", "kotsu": "kotsu",
    "seiji-kaikaku": "seiji", "digital": "digital", "ai": "digital",
    "josei-jinken": "josei", "chiho": "chiho", "shiho-chian": "shiho",
}
# トラッカー以外に返信で案内できる自社システム（分野→URL）。規則で当てる
SYSTEMS = {
    "bousai": [("被災者支援ナビ", "https://kurage.exbridge.jp/khisai.php/", r"罹災|被災|支援金|応急修理|片付け|がれき"),
               ("土砂災害ハザードマップ", "https://kurage.exbridge.jp/khazard.php/", r"土砂|がけ|崖|地すべり"),
               ("洪水・内水ハザードマップ", "https://kurage.exbridge.jp/kflood.php/", r"浸水|冠水|洪水|内水|氾濫|川"),
               ("防災AIチャット", "https://kurage.exbridge.jp/kbousai.php/", r"台風|避難|警報|大雨")],
    "seiji": [("質問主意書の検索", "https://kurage.exbridge.jp/kshuisho.php/", r"質問主意書|答弁書")],
    "chiho": [("都市計画ナビ", "https://kurage.exbridge.jp/ktoshikeikaku.php/", r"用途地域|建ぺい|容積|市街化|都市計画")],
}
FIT = {"yes": "あてはまる", "no": "あてはまらない", "unknown": "わからない"}
FIT_Q = ("この投稿は、国の制度・法律・政策・災害について述べていて、"
         "国会での答弁や公的な統計の事実を添えて返信すると、話が深まる投稿か")
FIELD_Q = "この投稿は、どの分野の話題か"


def fx_timeline(handles, since_ts, workers=4):
    """議員ごとの最新の投稿（fxtwitter /2/profile/<handle>/statuses）。検索が使えないときの代わり（2026-10-10）。
    2026-10-09 22時ごろから fxtwitter の /2/search がどの語でも 404 を返すようになり、返信候補も国会議員の監視も
    集める投稿が0件になった。投稿1件・プロフィール・この時系列の入口は動いている。返信とリポストは検索と同じく除く"""
    import concurrent.futures as cf
    def one(h):
        u = f"https://api.fxtwitter.com/2/profile/{urllib.parse.quote(h)}/statuses"
        r = subprocess.run(["curl", "-s", "-m", "20", "-A", "Mozilla/5.0", u], capture_output=True, text=True).stdout
        try:
            res = json.loads(r).get("results") or []
        except ValueError:
            return []
        return [x for x in res if x.get("type") == "status" and (x.get("created_timestamp") or 0) >= since_ts
                and not x.get("replying_to") and not x.get("reposted_by")]
    out = []
    with cf.ThreadPoolExecutor(workers) as ex:
        for res in ex.map(one, handles):
            out += res
    return out


SEARCH_DOWN = {"down": False}
YAHOO_UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36"


def _snowflake_ts(tid):
    try:
        return ((int(tid) >> 22) + 1288834974657) / 1000
    except (TypeError, ValueError):
        return 0


def yahoo_search(q, since_ts=0, pages=1):
    """語の検索の代わり: Yahoo!リアルタイム検索（ログイン不要・curl で読める。2026-10-10）。
    fxtwitter の /2/search が止まっている間、語の検索に使う。表示回数が取れないので、いいねの条件を満たす投稿か
    名前が議員らしい投稿だけ fxtwitter の投稿1件の読み取り（/2/status は動いている）で数字を補って、検索と同じ形で返す"""
    words = re.sub(r"\s*(since_time:\d+|min_faves:\d+|lang:\w+|-filter:\w+)", "", q).strip()
    mf = int((re.search(r"min_faves:(\d+)", q) or [0, 0])[1])
    st = since_ts or int((re.search(r"since_time:(\d+)", q) or [0, 0])[1])
    acc = []
    def walk(o):
        if isinstance(o, dict):
            if "screenName" in o and ("text" in o or "displayText" in o):
                acc.append(o)
            for v in o.values():
                walk(v)
        elif isinstance(o, list):
            for v in o:
                walk(v)
    for b in [1, 11, 21][:pages]:
        u = "https://search.yahoo.co.jp/realtime/search?" + urllib.parse.urlencode({"p": words, "ei": "UTF-8", "b": b})
        h = subprocess.run(["curl", "-s", "-m", "30", "-A", YAHOO_UA, u], capture_output=True, text=True).stdout
        m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', h, re.S)
        if not m:
            break
        try:
            walk(json.loads(m.group(1)))
        except ValueError:
            break
        time.sleep(1)
    out = []
    for t in acc:
        tid = str(t.get("id") or "")
        if not tid or t.get("inReplyTo") or _snowflake_ts(tid) < st:
            continue
        if (t.get("likesCount") or 0) < mf and not re.search(POLITICIAN, t.get("name") or ""):
            continue
        r = subprocess.run(["curl", "-s", "-m", "20", "-A", "Mozilla/5.0", f"https://api.fxtwitter.com/2/status/{tid}"],
                           capture_output=True, text=True).stdout
        try:
            x = json.loads(r).get("status")
        except ValueError:
            x = None
        if x and x.get("type") == "status":
            out.append(x)
    return out


def fx_search(q, pages=1, since_ts=0):
    """X の検索（fxtwitter）。1ページ20件。pages>1 なら cursor で次のページへ。since_ts より古い投稿が出たら止める"""
    out, cursor = [], None
    for _ in range(pages):
        params = {"q": q}
        if cursor:
            params["cursor"] = cursor
        u = "https://api.fxtwitter.com/2/search?" + urllib.parse.urlencode(params)
        r = subprocess.run(["curl", "-s", "-m", "30", "-A", "Mozilla/5.0", u], capture_output=True, text=True).stdout
        try:
            d = json.loads(r)
        except ValueError:
            break
        res = d.get("results", [])
        if not res and d.get("code") == 404:
            # 検索が止まっている。from: の検索なら議員ごとの時系列で代わりに読む（語の検索には代わりが無い）
            SEARCH_DOWN["down"] = True
            hs = re.findall(r"from:([A-Za-z0-9_]+)", q)
            if hs:
                st = since_ts or int((re.search(r"since_time:(\d+)", q) or [0, 0])[1])
                return fx_timeline(hs, st)
            return yahoo_search(q, since_ts)
        out += res
        cursor = (d.get("cursor") or {}).get("bottom")
        if not res or not cursor or min((x.get("created_timestamp") or 0) for x in res) < since_ts:
            break
        time.sleep(1.5)
    return out


def fx_status(url):
    m = re.search(r"x\.com/([^/]+)/status/(\d+)", url)
    if not m:
        return None
    u = f"https://api.fxtwitter.com/{m.group(1)}/status/{m.group(2)}"
    r = subprocess.run(["curl", "-s", "-m", "30", "-A", "Mozilla/5.0", u], capture_output=True, text=True).stdout
    try:
        return json.loads(r).get("tweet")
    except ValueError:
        return None


def slim(x):
    a = x.get("author") or {}
    return {"id": x["id"], "url": x.get("url"), "text": x.get("text") or "",
            "likes": x.get("likes") or 0, "views": x.get("views") or 0,
            "created": x.get("created_timestamp") or 0,
            "screen_name": a.get("screen_name"), "name": a.get("name"), "followers": a.get("followers") or 0,
            "description": a.get("description") or ""}


# 国会議員・地方議員・首長は表示が少なくても候補にする（名前か自己紹介で判定する規則）
POLITICIAN = r"(衆議院|参議院|衆院|参院|国会|都議会|道議会|府議会|県議会|市議会|区議会|町議会|村議会|[都道府県市区町村])議員(?!の|に|を|が|へ|と|たち|さん|秘書|事務所|連盟|会館|選)|[都道府県市区町村]議(?!論|会|事|題|決)|(?<![社学部課係])長(?=です|$)|(知事|市長|区長|町長|村長)(?!選|候補)"


POLITICIAN_WORDS = {"一般質問", "委員会", "視察", "議会", "国会", "予算", "陳情", "要望", "政策", "法案", "質問", "答弁", "街頭", "衆議院", "参議院"}


# 役所・公式アカウントは議員ではない（自己紹介に「区長」などが出るので先に外す）
NOT_POLITICIAN = r"広報|公式|役所|危機管理|事務局|県庁|都庁|市役所|区役所|町役場|村役場|消防|警察|ニュース|新聞|放送"


# 国会議員（衆議院・参議院・前職を含む）。地方議員・首長と分けて返信の枠を取るため（2026-10-08 ユーザー指示
# 「国会議員への返信を増やしたい」「衆議院と参議院のキーワードフィルタ」「前職外さなくていい」）
# 「衆議院 東京16区」「参議院 比例」のように「議員」を書かない人も多いので、「衆議院」「参議院」だけでも当てる
# （2026-10-08 ユーザー指摘）。ただし「衆院選」「参院選」（市議の自己紹介に出る）、元・前、候補、秘書、
# 事務局・調査局・法制局（職員）、担当（記者）は当てない
KOKKAI_NAME = r"(衆議院|参議院)(?!選|議員候補|議員選|議員総選|議員秘書|議員事務所|事務局|調査局|法制局|担当|議員では)"
# 自己紹介では「衆議院」だけだと支持者の「〇〇衆議院議員を応援」「衆議院の…」まで当たる（10/8 に132人中27人が誤り）ので、
# 自己紹介は「議員」まで続く書き方だけ。名前の欄（肩書き）では「衆議院」「参議院」だけでも当てる
# 「国会議員」は一般の人の文脈（「仕事しない国会議員はウンザリ」）で出るので自己紹介では当てない。「〇年まで衆議院議員」は前職
KOKKAI_DESC = r"(衆議院|参議院|衆院|参院)議員(?!候補|選|総選|秘書|事務所|を|の|に|と|さん|が|へ|→|では|じゃ|\)推)"
# 院の名前を名乗る bot・まとめアカウント（例: @sangiin「参議院に関係するニュースを…botです（非公式）」）
NOT_KOKKAI = r"bot|ボット|非公式|まとめ|ニュース|速報|中継|広報|公式"


def is_kokkai(p):
    t = (p.get("name") or "") + " " + (p.get("description") or "")
    if re.search(NOT_KOKKAI, t) or re.search(NOT_POLITICIAN, p.get("name") or ""):
        return False
    # 前職（元・前の衆議院議員など）も国会議員として扱う（2026-10-08 ユーザー指示「前職外さなくていい」）
    name, desc = p.get("name") or "", p.get("description") or ""
    # 自己紹介は冒頭60字だけを見る。後ろの方は、支持者がほかの議員の名前と肩書きに触れていることが多い
    # （「玉木雄一郎衆議院議員」「櫻井祥子参議院議員→」）。冒頭に党員・支持・応援・秘書などがあれば本人ではない
    head = desc[:60]
    hit = re.search(KOKKAI_NAME, name) or re.search(KOKKAI_DESC, name) or (
        re.search(KOKKAI_DESC, head) and not re.search(r"党員|サポーター|後援会|秘書|支持|応援|ファン|推", head))
    return bool(hit) and not re.search(r"(?:県|市|区|町|村|都|道|府)議会議員", name)


def is_politician(p):
    if re.search(NOT_POLITICIAN, p.get("name") or ""):
        return False
    if is_kokkai(p):   # 「衆議院」「参議院」だけ書く国会議員は POLITICIAN（「議員」が要る）に当たらないので先に見る
        return True
    return bool(re.search(POLITICIAN, (p.get("name") or "") + " " + (p.get("description") or "")))


def trackers():
    return json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))


def queries(since_ts, min_faves):
    qs = set()
    for t in trackers():
        w = t.get("seo_word") or (t.get("words") or [t["short"]])[0]
        qs.add(w.split()[0])
    qs |= {"国会", "法案", "政府", "大臣", "災害", "被災", "補助金", "制度"}
    # since_time: は Unix 時刻（X の検索演算子。fxtwitter の検索でも効く）。返信は投稿から早いほど読まれるので、
    # 既定は直近60分。60分では「いいね」がまだ少ないので min_faves も小さく（既定5）
    return [f"{w} since_time:{since_ts} min_faves:{min_faves} lang:ja -filter:replies" for w in sorted(qs)]


def collect(args):
    day = args.date
    os.makedirs(os.path.join(OUT, day), exist_ok=True)
    seen = {}
    since_ts = int(time.time()) - args.within * 60
    # 持ち時間（2026-10-09）。議員一覧が増え続け（10/9 だけで 2,697→2,735 人）、混む時間帯は集めるだけで
    # 20分の制限を超えて、集めた分ごと失敗していた（12:55・18:14 の回）。超えたら残りの検索を打ち切って先へ進む
    deadline = time.time() + args.budget
    cut = []
    def over(stage):
        if time.time() > deadline:
            if stage not in cut:
                cut.append(stage)
                print(f"\n持ち時間（{args.budget}秒）を超えたので「{stage}」以降の検索を打ち切る", flush=True)
            return True
        return False
    qs = queries(since_ts, args.min_faves)
    for i, q in enumerate(qs, 1):
        if over("語の検索"):
            break
        for x in fx_search(q):
            if x.get("type") == "status" and x["id"] not in seen:
                if (x.get("created_timestamp") or 0) < since_ts:   # 念のため手元でも時刻で切る
                    continue
                s = slim(x); s["query"] = q.split(" since_time:")[0]; seen[x["id"]] = s
        print(f"\r{i}/{len(qs)} {len(seen)}件", end="", flush=True)
        time.sleep(2)
    print()
    # 議員・首長はいいねが少なくても対象。
    # ① これまでに見つけた議員のアカウントを from: でまとめて引く（語に関係なく、その時間の投稿を全部拾う）
    reg_path = os.path.join(ROOT, "data", "x_politicians.json")
    reg = json.load(open(reg_path, encoding="utf-8")) if os.path.exists(reg_path) else {}
    handles = sorted(reg)
    # 国会議員を先に読む。検索が止まって議員ごとの時系列で読むときは、持ち時間で後ろが打ち切られるため（2026-10-10）
    try:
        kok = {r["x"].lower() for r in json.load(open(os.path.join(ROOT, "data", "kokkai_x.json"), encoding="utf-8")) if r.get("verified") and r.get("x")}
        handles = [h for h in handles if h.lower() in kok] + [h for h in handles if h.lower() not in kok]
    except Exception:
        pass
    npol = 0
    for i in range(0, len(handles), 20):
        if over("議員一覧"):
            break
        q = "(" + " OR ".join(f"from:{h}" for h in handles[i:i + 20]) + f") since_time:{since_ts} -filter:replies"
        for x in fx_search(q, pages=3, since_ts=since_ts):
            if x.get("type") == "status" and x["id"] not in seen and (x.get("created_timestamp") or 0) >= since_ts:
                s = slim(x)
                if is_politician(s):
                    s["query"] = "from:議員一覧"; seen[x["id"]] = s; npol += 1
        print(f"\r議員一覧 {min(i + 20, len(handles))}/{len(handles)} {npol}件", end="", flush=True)
        time.sleep(2)
    print()
    # ①' 報道機関。いいねが付きにくく（朝日は表示1,000超でもいいね0〜10）、見出しにトラッカーの語も出ないので、
    #     語の検索（min_faves つき）では拾えない。from: でその時間の投稿を全部引き、表示の下限は pick で掛ける
    news = json.load(open(os.path.join(ROOT, "data", "x_news_accounts.json"), encoding="utf-8"))["handles"]
    nnews = 0
    for i in range(0, len(news), 20):
        if over("報道一覧"):
            break
        q = "(" + " OR ".join(f"from:{h}" for h in news[i:i + 20]) + f") since_time:{since_ts} -filter:replies"
        for x in fx_search(q, pages=5, since_ts=since_ts):
            if x.get("type") == "status" and x["id"] not in seen and (x.get("created_timestamp") or 0) >= since_ts:
                s = slim(x); s["query"] = "from:報道一覧"; seen[x["id"]] = s; nnews += 1
        time.sleep(2)
    print(f"報道一覧 {len(news)}アカウント {nnews}件")
    # ② いいねの条件なしで、トラッカーの語と議員がよく使う語を引き（3ページまで）、作者が議員のものだけ残す
    pol_qs = sorted({q.split(" since_time:")[0] for q in qs} | POLITICIAN_WORDS)
    for i, w in enumerate(pol_qs, 1):
        if over("議員がよく使う語"):
            break
        for x in fx_search(f"{w} since_time:{since_ts} lang:ja -filter:replies", pages=3, since_ts=since_ts):
            if x.get("type") != "status" or x["id"] in seen or (x.get("created_timestamp") or 0) < since_ts:
                continue
            s = slim(x)
            if is_politician(s):
                s["query"] = w; seen[x["id"]] = s; npol += 1
        print(f"\r議員 {i}/{len(pol_qs)} {npol}件", end="", flush=True)
        time.sleep(2)
    print()
    # 見つけた議員を一覧に足す（次の回から from: で漏れなく拾う）
    for v in seen.values():
        if is_politician(v) and v.get("screen_name"):
            reg[v["screen_name"]] = v.get("name") or ""
    json.dump(dict(sorted(reg.items())), open(reg_path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"議員一覧 {len(reg)}人" + (f"（持ち時間切れで打ち切り: {'・'.join(cut)}）" if cut else ""))
    stamp = datetime.datetime.now().strftime("%H%M")
    p = os.path.join(OUT, day, f"posts-{stamp}.json")
    json.dump(sorted(seen.values(), key=lambda s: -s["likes"]), open(p, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(p)


def match_tracker(field, text):
    """分野が決まったら、その分野のトラッカーのうち語が本文に出るものを規則で選ぶ（判断モデルには選ばせない）"""
    best = []
    for t in trackers():
        if field and THEME_TO_FIELD.get(t["theme"]) != field:
            continue
        words = set(t.get("words") or []) | {t["short"], t.get("seo_word") or ""}
        hit = [w for w in words if w and all(p in text for p in w.split())]
        if hit:
            best.append((len(max(hit, key=len)), t))
    if not best and field:   # 分野の判定がずれても、語が本文に出るトラッカーは当てる
        return match_tracker(None, text)
    best.sort(key=lambda b: -b[0])
    return best[0][1] if best else None


def match_system(field, text):
    for name, url, rx in SYSTEMS.get(field, []):
        if re.search(rx, text):
            return name, url
    return None


def judge(backend, posts):
    out = []
    for p in posts:
        state = {"post": p["text"][:1500], "author": p.get("name") or ""}
        t0 = time.time()
        # 「返信向きか」を別に聞くと小さいモデルはほとんど「いいえ」か「わからない」と答える（2026-10-01 実測:
        # jevlocal は正解54件中7件しか拾えず、Laya は99件中67件が「わからない」）。
        # 分野の選択肢に「none（どれでもない）」を入れて1問で聞くと、jevlocal は54件中51件を拾った（docs/x_reply_pick_RESULTS.md）
        a = backend.decide(state, {"field": {"instructions": FIELD_Q, "criteria": FIELDS}})
        out.append({"id": p["id"], "field": a["field"]["choice"], "field_conf": a["field"]["confidence"],
                    "ms": round((time.time() - t0) * 1000)})
    return out


def pick(args):
    day = args.date
    import glob
    pp = args.posts or sorted(glob.glob(os.path.join(OUT, day, "posts-*.json")))[-1]
    posts = [x for x in json.load(open(pp, encoding="utf-8"))
             if (x.get("views") or 0) >= args.min_views
             or (is_politician(x) and (x.get("views") or 0) >= args.min_views_politician)
             or (is_kokkai(x) and (x.get("views") or 0) >= args.min_views_kokkai)]
    stamp = os.path.basename(pp)[6:-5]
    jp = os.path.join(OUT, day, f"judge-{args.backend}-{stamp}-v{args.min_views}.json")
    if os.path.exists(jp) and not args.rejudge:   # 判定は重いので、同じ日の判定があれば使い回す
        res = {r["id"]: r for r in json.load(open(jp, encoding="utf-8"))}
    else:
        res = {r["id"]: r for r in judge(decide_backends.get(args.backend), posts)}
    rows = []
    for p in posts:
        r = res[p["id"]]
        if r["field"] == "none":
            continue
        tr = match_tracker(r["field"], p["text"])
        sy = match_system(r["field"], p["text"])
        rows.append((p, r, tr, sy))
    rows.sort(key=lambda x: -(x[0]["views"]))
    hit = [x for x in rows if x[2] or x[3]][:args.top]
    rest = [x for x in rows if not (x[2] or x[3])][:20]
    L = [f"# X の返信候補 {day}（判断: {args.backend}）\n",
         f"集めた投稿 {len(posts)}件 → 政策・制度・災害の話題 {len(rows)}件"
         f"（うち当社のトラッカーかシステムが当たる {sum(1 for x in rows if x[2] or x[3])}件）。"
         f"表示の多い順に、当たるもの上位{len(hit)}件と、分野だけ当たるもの上位{len(rest)}件。返信の文と投稿は人が決める。\n",
         "# 当社のトラッカー・システムが当たる\n"]

    def block(p, r, tr, sy):
        ago = int((time.time() - p["created"]) / 60)
        L.append(f"## {p['name']}（@{p['screen_name']}・フォロワー{p['followers']:,}）表示{p['views']:,}・♥{p['likes']:,}・{ago}分前")
        L.append(f"- {p['url']}")
        L.append(f"- 分野: {r['field']}（{r['field_conf']:.2f}）")
        if tr:
            L.append(f"- トラッカー: {tr['short']} https://xb4g.com/giin/tracker/{tr['key']}")
        if sy:
            L.append(f"- システム: {sy[0]} {sy[1]}")
        body = re.sub(r"\s+", " ", p["text"])[:200]
        L.append(f"- 本文: {body}\n")
    for x in hit:
        block(*x)
    L.append("# 分野だけ当たる（新しいトラッカーの種）\n")
    for x in rest:
        block(*x)
    path = os.path.join(OUT, day, f"candidates-{args.backend}-{stamp}.md")
    open(path, "w", encoding="utf-8").write("\n".join(L))
    json.dump(list(res.values()), open(jp, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(path, len(rows))


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["collect", "pick"])
    today = datetime.date.today()
    ap.add_argument("--date", default=today.isoformat())
    ap.add_argument("--within", type=int, default=40, help="何分以内の投稿を対象にするか")
    ap.add_argument("--min-faves", type=int, default=5)
    ap.add_argument("--budget", type=int, default=840, help="collect の持ち時間（秒）。超えたら残りの検索を打ち切って保存する（ジョブの制限は1200秒）")
    ap.add_argument("--min-views", type=int, default=1000, help="インプレッション（表示回数）がこれ以上の投稿だけ判定する（議員・首長は除く）。X の検索に条件が無いので集めたあとで絞る")
    ap.add_argument("--min-views-politician", type=int, default=100, help="議員・首長の投稿の表示の下限")
    ap.add_argument("--min-views-kokkai", type=int, default=30,
                    help="国会議員（衆議院・参議院）の投稿の表示の下限。投稿から40分以内だと表示がまだ少ない（丹野議員の投稿は見つけた時点で27）")
    ap.add_argument("--posts", help="pick で使う posts-*.json（省略時はその日の最新）")
    ap.add_argument("--backend", default="jevlocal")
    ap.add_argument("--top", type=int, default=30)
    ap.add_argument("--rejudge", action="store_true")
    a = ap.parse_args()
    {"collect": collect, "pick": pick}[a.cmd](a)
