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


def fx_search(q):
    u = "https://api.fxtwitter.com/2/search?" + urllib.parse.urlencode({"q": q})
    r = subprocess.run(["curl", "-s", "-m", "30", "-A", "Mozilla/5.0", u], capture_output=True, text=True).stdout
    try:
        return json.loads(r).get("results", [])
    except ValueError:
        return []


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
POLITICIAN = r"(衆議院|参議院|衆院|参院|国会|都議会|道議会|府議会|県議会|市議会|区議会|町議会|村議会)議員|[都道府県市区町村]議|議員(?!秘書)|知事|市長|区長|町長|村長"


def is_politician(p):
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
    qs = queries(since_ts, args.min_faves)
    for i, q in enumerate(qs, 1):
        for x in fx_search(q):
            if x.get("type") == "status" and x["id"] not in seen:
                if (x.get("created_timestamp") or 0) < since_ts:   # 念のため手元でも時刻で切る
                    continue
                s = slim(x); s["query"] = q.split(" since_time:")[0]; seen[x["id"]] = s
        print(f"\r{i}/{len(qs)} {len(seen)}件", end="", flush=True)
        time.sleep(2)
    print()
    stamp = datetime.datetime.now().strftime("%H%M")
    p = os.path.join(OUT, day, f"posts-{stamp}.json")
    json.dump(sorted(seen.values(), key=lambda s: -s["likes"]), open(p, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(p)


def match_tracker(field, text):
    """分野が決まったら、その分野のトラッカーのうち語が本文に出るものを規則で選ぶ（判断モデルには選ばせない）"""
    best = []
    for t in trackers():
        if THEME_TO_FIELD.get(t["theme"]) != field:
            continue
        words = set(t.get("words") or []) | {t["short"], t.get("seo_word") or ""}
        hit = [w for w in words if w and all(p in text for p in w.split())]
        if hit:
            best.append((len(max(hit, key=len)), t))
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
    posts = [x for x in json.load(open(pp, encoding="utf-8")) if (x.get("views") or 0) >= args.min_views or is_politician(x)]
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
    ap.add_argument("--within", type=int, default=60, help="何分以内の投稿を対象にするか")
    ap.add_argument("--min-faves", type=int, default=5)
    ap.add_argument("--min-views", type=int, default=2000, help="インプレッション（表示回数）がこれ以上の投稿だけ判定する（議員・首長は除く）。X の検索に条件が無いので集めたあとで絞る")
    ap.add_argument("--posts", help="pick で使う posts-*.json（省略時はその日の最新）")
    ap.add_argument("--backend", default="jevlocal")
    ap.add_argument("--top", type=int, default=30)
    ap.add_argument("--rejudge", action="store_true")
    a = ap.parse_args()
    {"collect": collect, "pick": pick}[a.cmd](a)
