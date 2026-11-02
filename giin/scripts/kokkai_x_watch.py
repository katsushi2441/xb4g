#!/usr/bin/env python3
"""国会議員全員の X の投稿を見て、返信する価値のある政治的な投稿をメールで知らせる（2026-10-08 作成）。

  /usr/bin/python3 scripts/kokkai_x_watch.py run [--dry]     # 前回から今までの投稿を見る（--dry はメールを送らず表示だけ）

- 対象は data/kokkai_x.json の verified=True のアカウント（scripts/build_kokkai_x.py で作る）。
- X は fxtwitter の検索（ログイン不要）で「(from:a OR from:b …) since_time:前回」を20人ずつ引く。返信は除く（引用は入れる）。
- 価値は手元の gemma4（0.3 の Ollama・think:false・JSON スキーマ）で 0〜3 の4段階。2以上を知らせ、3は件名に「要返信」。
  0=挨拶・告知・日常 1=活動報告だけ 2=政策・制度への意見や問題提起 3=具体的な政策・法案・数字・政府への批判や提案があり論点が明確
- メールは投稿ごとに複数のリンク: 元の投稿／返信候補を作る（X返信候補ページを ?url= で開くと自動で作り始める）／
  衆参の議員紹介ページ／（愛知の議員なら）xb4g の議員ページ。国会会議録の検索画面は発言者をURLで指定できない（全件が出た）ので付けない。
- 一度知らせた投稿は state.json に残して二度知らせない。
- 2026-11-03 から大手ニュース（data/x_news_accounts.json）も同じ窓で見る。判定は NEWS_PROMPT（当社のデータで中身のある
  返信ができる話題か。芸能・スポーツ・事件の速報は0）。メールでは「【ニュース】」の印で議員と分け、上位 NEWS_MAX_MAIL 件まで。
  Codex の作り置きはニュースの★高いのうち NEWS_PREBUILD_MAX 件まで。--dry --show-mail で送るはずの本文を表示する。
"""
import argparse, datetime, json, os, re, smtplib, sys, time, urllib.parse, urllib.request
from email.header import Header
from email.mime.text import MIMEText

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
sys.path.insert(0, HERE)
import x_reply_pick as X  # noqa: E402

LIST = os.path.join(ROOT, "data", "kokkai_x.json")
OUT = os.path.join(ROOT, "outputs", "kokkai_x_watch")
STATE = os.path.join(OUT, "state.json")
OLLAMA = "http://192.168.0.3:11434"
LLM = "gemma4:12b-it-qat"
MAIL_TO = "katsushi2441@gmail.com"
# 返信は元の投稿から60分以内に出したいので、知らせるのは投稿から30分以内のものだけ（2026-10-08 は40分、10-09 ユーザー指示で30分）。
# 15分おきに回すので、ふだんは投稿から15〜20分で届く。止まっていた後でも30分より前はさかのぼらない
TARGET_AGE = 30 * 60
REPLY_LIMIT = 60 * 60
FIRST_WINDOW = TARGET_AGE
MAX_WINDOW = TARGET_AGE

SCHEMA = {"type": "object", "properties": {
    "value": {"type": "integer", "minimum": 0, "maximum": 3},
    "point": {"type": "string"}, "reason": {"type": "string"}},
    "required": ["value", "point", "reason"]}
PROMPT = """国会議員のXの投稿です。政策の議論として、外から数字や国会での経緯を添えて返信する価値があるかを判定してください。
value: 0=価値なし（挨拶・告知・お祝い・日常・イベント案内）、1=低い（活動報告・視察・出席の報告だけ）、2=ある（政策・制度への意見や問題提起がある）、3=高い（具体的な政策・法案・数字・政府への批判や提案があり、論点が明確）。
point: 投稿の論点を20字以内（例: 消費税減税の財源）。value が0か1なら空でよい。
reason: 判定の理由を30字以内。

投稿:
"""
# 大手ニュースのアカウント（2026-11-03 ユーザー指示「国会議員返信候補に大手ニュースのアカウントを足して」）。
# 11/2 に @livedoornews への返信が表示40.7万回と、ほかの返信の1,000倍以上伸びた。一覧は報道機関だけを集める
# news_only.py・定期の返信候補と同じ data/x_news_accounts.json（fxtwitter で公式と確かめたものだけ）。
# ニュースは議員と違い「意見があるか」ではなく「当社のデータで中身のある返信ができる話題か」で判定する
NEWS_LIST = os.path.join(ROOT, "data", "x_news_accounts.json")
NEWS_PROMPT = """大手報道機関のXのニュース投稿（見出し）です。当社は、国会会議録（全国の国会議員の質疑と政府の答弁）、テーマごとに国会の議論を並べた国会トラッカー、行政・制度のデータを使ったシステム（介護・医療・防災・税・社会保障・補助金・子育て・教育・働き方・デジタル/AI・地方自治・物価や金利の経済指標など）を持っています。
このニュースに、それらのデータで国会での議論の経緯や数字を添えて、中身のある返信ができるかを判定してください。
value: 0=価値なし（芸能・スポーツ・事件や事故の速報・天気・訃報・海外の出来事だけ・商品やイベントの紹介・生活の小ネタ）、1=低い（政治や行政の話だが、人事・日程・発言の速報だけで、国会の議論やデータを添える余地が小さい）、2=ある（制度・政策・法案・予算・税・社会保障・防災などの話題で、国会の議論やデータを添えられる）、3=高い（国の制度・法案・政府の方針・統計の数字が見出しの中心で、国会での議論の経緯を添えると話が深まる）。
point: ニュースの論点を20字以内（例: 介護報酬の改定）。value が0か1なら空でよい。
reason: 判定の理由を30字以内。

投稿:
"""
# ニュースは投稿が多い（41アカウントで30分に60〜80件）。メールに載せる数と、Codex で返信候補を作り置きする数に上限を掛ける。
# 作り置きは1回2件まで＝1日6回で最大12回（議員の★高いの作り置きはそのまま）。残りはリンクを押したときに作る
NEWS_MAX_MAIL = 8
NEWS_PREBUILD_MAX = 2


def load_state():
    try:
        return json.load(open(STATE, encoding="utf-8"))
    except Exception:
        return {"last_ts": 0, "sent": []}


def search_alive():
    """fxtwitter の検索が今使えるか（1回だけ試す）"""
    try:
        d = json.load(urllib.request.urlopen(urllib.request.Request(
            "https://api.fxtwitter.com/2/search?q=" + urllib.parse.quote("国会"), headers={"User-Agent": "xb4g-giin/1.0"}), timeout=30))
        return bool(d.get("results"))
    except Exception:
        return False


def news_handles():
    try:
        return [h for h in json.load(open(NEWS_LIST, encoding="utf-8"))["handles"] if h]
    except (OSError, ValueError, KeyError):
        return []


def news_page(text):
    """ニュースに当たる当社のページ（規則だけ。トラッカーの語かシステムの語が本文に出るもの）。メールに添え、作り置きの優先に使う"""
    try:
        tr = X.match_tracker(None, text)
        if tr:
            return f"国会トラッカー「{tr['short']}」", f"https://xb4g.com/giin/tracker/{tr['key']}"
        sy = next((m for f in X.SYSTEMS for m in [X.match_system(f, text)] if m), None)
        if sy:
            return f"当社のシステム「{sy[0]}」", sy[1]
    except Exception:  # noqa: BLE001
        pass
    return None


def fx_search(q, pages=3):
    out, cursor = [], ""
    for _ in range(pages):
        u = "https://api.fxtwitter.com/2/search?q=" + urllib.parse.quote(q) + (f"&cursor={urllib.parse.quote(cursor)}" if cursor else "")
        try:
            d = json.load(urllib.request.urlopen(urllib.request.Request(u, headers={"User-Agent": "xb4g-giin/1.0"}), timeout=40))
        except urllib.error.HTTPError as e:
            if e.code == 404:
                # 2026-10-09 22時ごろから検索が 404。議員ごとの時系列で代わりに読む（x_reply_pick.fx_timeline）
                hs = re.findall(r"from:([A-Za-z0-9_]+)", q)
                st = int((re.search(r"since_time:(\d+)", q) or [0, 0])[1])
                return X.fx_timeline(hs, st) if hs else []
            break
        except Exception:
            break
        out += d.get("results") or []
        cursor = (d.get("cursor") or {}).get("bottom") if isinstance(d.get("cursor"), dict) else d.get("cursor")
        if not cursor or not d.get("results"):
            break
        time.sleep(1.5)
    return out


def judge(text, prompt=PROMPT):
    b = json.dumps({"model": LLM, "prompt": prompt + text[:900], "stream": False, "format": SCHEMA, "think": False,
                    "options": {"temperature": 0, "num_predict": 200}}).encode()
    r = json.load(urllib.request.urlopen(urllib.request.Request(OLLAMA + "/api/generate", data=b,
                                                                headers={"Content-Type": "application/json"}), timeout=300))
    try:
        d = json.loads(r.get("response") or "{}")
        return int(d.get("value", 0)), (d.get("point") or "").strip(), (d.get("reason") or "").strip()
    except Exception:
        return 0, "", ""


def page_url():
    tok = open(os.path.join(X.OUT, ".token")).read().strip()
    return f"https://proto.exbridge.jp/xreply-{tok}/"


def aichi_slugs():
    try:
        return {re.sub(r"\s", "", r["name"]): r["slug"] for r in json.load(open(os.path.join(ROOT, "data", "roster.json"), encoding="utf-8"))}
    except Exception:
        return {}


def run(dry=False):
    os.makedirs(OUT, exist_ok=True)
    rows = [r for r in json.load(open(LIST, encoding="utf-8")) if r.get("verified") and r.get("x")]
    by_handle = {r["x"].lower(): r for r in rows}
    st = load_state()
    now = int(time.time())
    # 検索（/2/search）が止まっている間は、議員ごとの時系列で読む（1回で約580回の読み取り）。fxtwitter に負担を
    # かけすぎないよう、時系列で読むのは25分に1回にする（kdeck は15分おきのまま。検索が戻れば自動で毎回に戻る）。2026-10-10
    if not search_alive():
        if now - int(st.get("last_fallback") or 0) < 25 * 60:
            print("検索が止まっているので、時系列での読み取りは25分に1回。今回は見送り")
            return {"posts": 0, "hits": 0, "skipped": "search-down"}
        st["last_fallback"] = now
    since = max(st.get("last_ts") or (now - FIRST_WINDOW), now - MAX_WINDOW)
    handles = sorted(by_handle)
    posts = {}
    for i in range(0, len(handles), 20):
        q = "(" + " OR ".join(f"from:{h}" for h in handles[i:i + 20]) + f") since_time:{since} -filter:replies"
        for t in fx_search(q):
            if t.get("type") != "status" or (t.get("created_timestamp") or 0) < max(since, now - TARGET_AGE):
                continue
            h = (t.get("author") or {}).get("screen_name", "").lower()
            if h in by_handle and t["id"] not in st["sent"]:
                posts[t["id"]] = t
        time.sleep(2)
    print(f"{len(rows)}人を確認・{len(posts)}件の新しい投稿（{datetime.datetime.fromtimestamp(since):%H:%M} 以降）")
    # 大手ニュース（2026-11-03）。議員と同じ時間の窓（投稿から30分以内）で、20アカウントずつ from: で引く
    news = news_handles()
    news_by = {h.lower(): h for h in news}
    nposts = {}
    for i in range(0, len(news), 20):
        q = "(" + " OR ".join(f"from:{h}" for h in news[i:i + 20]) + f") since_time:{since} -filter:replies"
        for t in fx_search(q, pages=5):
            if t.get("type") != "status" or (t.get("created_timestamp") or 0) < max(since, now - TARGET_AGE):
                continue
            h = (t.get("author") or {}).get("screen_name", "").lower()
            if h in news_by and t["id"] not in st["sent"] and t["id"] not in posts:
                nposts[t["id"]] = t
        time.sleep(2)
    print(f"ニュース {len(news)}アカウントを確認・{len(nposts)}件の新しい投稿")
    hits = []
    for t in posts.values():
        text = (t.get("text") or "") + (("\n［引用］" + t["quote"].get("text", "")) if t.get("quote") else "")
        if len(text) < 40:
            continue
        v, point, reason = judge(text)
        if v >= 2:
            r = by_handle[t["author"]["screen_name"].lower()]
            hits.append({"value": v, "point": point, "reason": reason, "r": r, "t": t})
    hits.sort(key=lambda x: (-x["value"], -(x["t"].get("views") or 0)))
    try:   # 訃報・皇室など返信しない話題（定期の返信候補と同じ語）は gemma4 に聞く前に落とす
        import x_reply_draft as D
        skip = D.SKIP
    except Exception:  # noqa: BLE001
        skip = r"訃報|亡くな|死亡|ご冥福|お悔やみ|天皇|皇族|宮内庁"
    nhits = []
    for t in nposts.values():
        text = (t.get("text") or "") + (("\n［引用］" + t["quote"].get("text", "")) if t.get("quote") else "")
        if len(re.sub(r"https?://\S+", "", text).strip()) < 12 or re.search(skip, text):
            continue
        v, point, reason = judge(text, NEWS_PROMPT)
        if v >= 2:
            a = t.get("author") or {}
            r = {"name": a.get("name") or a.get("screen_name"), "display": a.get("name") or a.get("screen_name"),
                 "handle": a.get("screen_name"), "followers": a.get("followers") or 0, "house": "ニュース"}
            nhits.append({"value": v, "point": point, "reason": reason, "r": r, "t": t, "news": True, "page": news_page(text)})
    # 高い順・当社のページが当たる順・表示の多い順。メールに載せるのは上位 NEWS_MAX_MAIL 件まで
    nhits.sort(key=lambda x: (-x["value"], not x["page"], -(x["t"].get("views") or 0)))
    nall = len(nhits)
    nhits = nhits[:NEWS_MAX_MAIL]
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M")
    json.dump([{"value": h["value"], "point": h["point"], "reason": h["reason"], "name": h["r"]["name"], "house": h["r"]["house"],
                "url": h["t"].get("url"), "text": h["t"].get("text"), **({"news": True, "page": h["page"]} if h.get("news") else {})}
               for h in hits + nhits],
              open(os.path.join(OUT, f"hits-{stamp}.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"返信する価値あり 議員 {len(hits)}件（高い {sum(1 for h in hits if h['value'] == 3)}件）・"
          f"ニュース {nall}件（高い {sum(1 for h in nhits if h['value'] == 3)}件・メールに載せるのは {len(nhits)}件）")
    if not dry:
        # 燃えている投稿は、まだ知らせていないものだけ。メールが送れたら「知らせた」にして二度出さない（2026-10-28）
        ej, ej_ready = enjo_pending()
        res = mail(hits, ej, ej_ready, nhits, nall) if (hits or nhits) else mail_status(
            enjo_subject(ej) + f"国会議員の投稿 0件・ニュース 0件（{datetime.datetime.now():%H:%M}）",
            [f"{len(rows)}人を確認し、{datetime.datetime.fromtimestamp(since):%H:%M} 以降の新しい投稿は {len(posts)}件でした。",
             f"大手ニュース {len(news)}アカウントの新しい投稿は {len(nposts)}件でした。",
             "そのうち返信する価値があると判定したものは 0件です（処理は最後まで動いています）。"] + enjo_lines(ej, ej_ready) + trend_lines())
        print("メール:", res)
        if res.startswith("sent"):
            enjo_mark_sent(ej)
    if not dry:
        st["last_ts"] = now
        st["sent"] = (list(posts) + list(nposts) + st.get("sent", []))[:5000]
        json.dump(st, open(STATE, "w", encoding="utf-8"))
    else:
        for h in hits + nhits:
            print(h["value"], "【ニュース】" if h.get("news") else "", h["r"]["name"], h["point"], h["t"].get("url"),
                  (h.get("page") or ("", ""))[0])
        if "--show-mail" in sys.argv:
            print("\n".join(mail_body(hits, (), (), nhits, nall, ready=set())[0]))
    return {"posts": len(posts), "hits": len(hits), "news_posts": len(nposts), "news_hits": len(nhits)}


def _password():
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("GMAIL_APP_PASSWORD="):
            return ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
    return ""



def trend_lines():
    """いま X のトレンドにある語のうち、国会の質疑かトラッカーに当たるもの（2026-10-25 ユーザー指示「メールに足して」）。
    投稿の本文の最初の1行にトレンドの語を入れ、リンクは件数入りのカードが出る検索ページにする。取れなくてもメールは止めない。"""
    try:
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        import trend_match
        hits = trend_match.match(min_n=3)
    except Exception as e:  # noqa: BLE001
        return ["", f"（トレンドの突き合わせは取れませんでした: {type(e).__name__}）"]
    L = ["", "■いま X のトレンドにある語と、当たる当社のページ（本文の1行目に語を入れて投稿すると伸びやすい）"]
    if not hits:
        L.append("・政治・社会の話題で国会の質疑に当たる語は、いまはありません（人名・芸能・アニメなどのトレンドは除いています）")
    for h in hits[:8]:
        L.append(f"・{h['word']}：{h.get('label', '')} 質疑{h['n']}件・{h['people']}人 → {h['url']}")
        try:   # 投稿文の下書き（1語につき1日1回作って保存。gemma4 が失敗したら決まった型）
            L += ["  投稿文の案（そのまま貼れます・人が確かめてから）:"] + ["  " + ln for ln in trend_match.draft(h).split("\n")] + [""]
        except Exception as e:  # noqa: BLE001
            L.append(f"  （投稿文は作れませんでした: {type(e).__name__}）")
    return L


def _enjo():
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    import enjo_watch
    return enjo_watch


def enjo_pending():
    """まだ知らせていない燃えている投稿と、返信候補を作り置きできたもの（その投稿について1回だけ作る）。取れなくてもメールは止めない"""
    try:
        items = _enjo().pending()
        return items, _enjo().prebuild(items)
    except Exception:  # noqa: BLE001
        return [], set()


def enjo_mark_sent(items):
    try:
        _enjo().mark_sent(items)
    except Exception:  # noqa: BLE001
        pass


def enjo_lines(items, ready=()):
    """いま燃えている本人の投稿（2026-10-27 ユーザー指示。熊谷代表・石井めぐみ議員のような投稿）。取れなくてもメールは止めない。"""
    try:
        return _enjo().enjo_lines(items, page_url(), ready)
    except Exception as e:  # noqa: BLE001
        return ["", f"（いま燃えている投稿は取れませんでした: {type(e).__name__}）"]


def enjo_subject(items):
    """まだ知らせていない燃えている投稿があれば、件名の頭に【炎上】名前 を付ける（0件の回のメールでも埋もれないように）。"""
    return f"【炎上】{re.sub(r'[【（(].*', '', items[0]['name']).strip()}{'ほか' if len(items) > 1 else ''} " if items else ""


def mail_status(subject, lines):
    """0件の回と失敗した回の知らせ"""
    pw = _password()
    if not pw:
        return "skip (no app password)"
    msg = MIMEText("\n".join(lines + ["", "kdeck の giin-kokkai-x-watch から自動で送っています。"]), "plain", "utf-8")
    msg["Subject"] = Header(subject, "utf-8")
    msg["From"] = MAIL_TO
    msg["To"] = MAIL_TO
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465, timeout=30) as sm:
            sm.login(MAIL_TO, pw)
            sm.sendmail(MAIL_TO, [MAIL_TO], msg.as_string())
        return "sent 0"
    except Exception as e:  # noqa: BLE001
        return f"error {type(e).__name__}"


def prebuild(hits, news=()):
    """「★高い」は、メールを送る前に返信候補を作っておく（押してから作ると10〜40秒待たされる。2026-10-09）。
    作ったものは x_reply_draft.one のキャッシュに残り、メールのリンクを押すとすぐ出る。codex の枠を守るため高いものだけ。
    ニュースの★高いは1回 NEWS_PREBUILD_MAX 件まで（当社のページが規則で当たるもの→表示の多いものの順。2026-11-03）"""
    import x_reply_draft as D
    done = set()
    todo = [h for h in hits if h["value"] == 3]
    todo += sorted([h for h in news if h["value"] == 3], key=lambda h: (not h.get("page"), -(h["t"].get("views") or 0)))[:NEWS_PREBUILD_MAX]
    for h in todo:
        try:
            if D.one(h["t"].get("url") or "").get("html"):
                done.add(h["t"].get("url"))
        except Exception:  # noqa: BLE001  作れなくてもメールは送る（押せばその場で作る）
            pass
    return done


def _reply_link(pu, t, ready):
    return (f"・返信候補{'（作成済み・開くとすぐ出ます）' if t.get('url') in ready else 'を作る（開くと作り始めます）'}: "
            f"{pu}?url={urllib.parse.quote(t.get('url') or '', safe='')}")


def mail_body(hits, ej=(), ej_ready=(), news=(), news_all=0, ready=None):
    """メールの本文と件名。ニュースは議員と分けて「【ニュース】」の印を付ける（2026-11-03）"""
    pu, slugs = page_url(), aichi_slugs()
    if ready is None:
        ready = prebuild(hits, news)
    n3 = sum(1 for h in hits if h["value"] == 3)
    m3 = sum(1 for h in news if h["value"] == 3)
    L = [f"国会議員の投稿のうち、返信する価値があると判定したもの {len(hits)}件（高い {n3}件）・"
         f"大手ニュースの投稿 {news_all}件（高い {m3}件{f'・上位{len(news)}件を載せています' if news_all > len(news) else ''}）。"
         "投稿から30分以内のものだけです。返信は投稿から60分以内が目安です。", ""]
    if hits:
        L += ["━━ 国会議員の投稿 ━━", ""]
    for i, h in enumerate(hits, 1):
        r, t = h["r"], h["t"]
        ago = int((time.time() - (t.get("created_timestamp") or time.time())) / 60)
        left = max(0, REPLY_LIMIT // 60 - ago)
        L += [f"■{i}. {'★高い ' if h['value'] == 3 else ''}{r['display']}（{r['house']}・{r.get('kaiha', '')}・{r.get('district', '')}）{ago}分前の投稿・返信の目安まであと{left}分・表示{(t.get('views') or 0):,}",
              f"論点: {h['point']}　／　判定の理由: {h['reason']}",
              "内容: " + re.sub(r"\s+", " ", t.get("text") or "")[:220], "",
              f"・元の投稿: {t.get('url')}",
              _reply_link(pu, t, ready)]
        if r.get("profile"):
            L.append(f"・{r['house']}の議員紹介: {r['profile']}")
        if r["name"] in slugs:
            L.append(f"・議員ページ（愛知の国会議員 発言ログ）: https://xb4g.com/giin/{slugs[r['name']]}")
        L.append("")
    if news:
        L += ["━━ 【ニュース】大手報道の投稿（当社のデータで中身のある返信ができる話題） ━━", ""]
    for i, h in enumerate(news, 1):
        r, t = h["r"], h["t"]
        ago = int((time.time() - (t.get("created_timestamp") or time.time())) / 60)
        left = max(0, REPLY_LIMIT // 60 - ago)
        L += [f"■N{i}. 【ニュース】{'★高い ' if h['value'] == 3 else ''}{r['display']}（@{r['handle']}・フォロワー{(r.get('followers') or 0):,}）{ago}分前の投稿・返信の目安まであと{left}分・表示{(t.get('views') or 0):,}",
              f"論点: {h['point']}　／　判定の理由: {h['reason']}",
              "内容: " + re.sub(r"\s+", " ", t.get("text") or "")[:220], "",
              f"・元の投稿: {t.get('url')}",
              _reply_link(pu, t, ready)]
        if h.get("page"):
            L.append(f"・当たる当社のページ: {h['page'][0]} {h['page'][1]}")
        L.append("")
    L += enjo_lines(ej, ej_ready)
    L += trend_lines()
    L.append("")
    L.append("価値の判定は手元のAI（gemma4）の推定です。投稿は人が確かめてから行ってください。kdeck の giin-kokkai-x-watch から自動で送っています。")
    if hits:
        first = hits[0]["r"]["display"].replace("　", "")
    else:
        first = "ニュース " + news[0]["r"]["display"].replace("　", "")
    subj = (enjo_subject(ej) + f"{'【要返信】' if (n3 or m3) else ''}国会議員の投稿 {len(hits)}件"
            f"{f'・【ニュース】{news_all}件' if news_all else ''}（{first}ほか）")
    return L, subj


def mail(hits, ej=(), ej_ready=(), news=(), news_all=0):
    pw = _password()
    if not pw:
        return "skip (no app password)"
    L, subj = mail_body(hits, ej, ej_ready, news, news_all)
    msg = MIMEText("\n".join(L), "plain", "utf-8")
    msg["Subject"] = Header(subj, "utf-8")
    msg["From"] = MAIL_TO
    msg["To"] = MAIL_TO
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465, timeout=30) as sm:
            sm.login(MAIL_TO, pw)
            sm.sendmail(MAIL_TO, [MAIL_TO], msg.as_string())
        return f"sent {len(hits) + len(news)}"
    except Exception as e:  # noqa: BLE001
        return f"error {type(e).__name__}"


# 見回りは1日6回（2026-11-01 ユーザー指示「国会議員の X 返信候補は C案」＝7・10・13・16・19・22時）。
# Codex の週の枠を、★高いの返信の作り置き（1日70〜120回）が食っていた。kdeck は時刻を指定できないので15分おきに起こし、
# この時刻の最初の1回だけ見回る。それ以外はすぐ戻る（fxtwitter も gemma4 も codex も呼ばない）。
# 知らせるのは投稿から30分以内のもの（TARGET_AGE・10/9 の指示）のままなので、各時刻の直前30分の投稿だけを見る
WATCH_HOURS = (7, 10, 13, 16, 19, 22)
SLOT = os.path.join(OUT, "last_slot.txt")


def run_kokkai_x_watch_job(**_):
    """kdeck から呼ぶ入口。失敗した回もメールで知らせてから、ジョブとしては失敗にする"""
    now = datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=9)))
    slot = now.strftime("%Y-%m-%d %H")
    done = open(SLOT).read().strip() if os.path.exists(SLOT) else ""
    if now.hour not in WATCH_HOURS or done == slot:
        return {"ok": True, "items": 0, "skipped": f"{now.hour}時（見回るのは{'・'.join(map(str, WATCH_HOURS))}時の1回）"}
    with open(SLOT, "w") as f:
        f.write(slot)
    try:
        r = run()
    except Exception as e:  # noqa: BLE001
        import traceback
        mail_status(f"国会議員の投稿 監視が失敗（{datetime.datetime.now():%H:%M}）",
                    [f"{type(e).__name__}: {e}", "", traceback.format_exc()[-1500:]])
        raise
    return {"ok": True, **r, "items": r["hits"] + r.get("news_hits", 0)}


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["run"])
    ap.add_argument("--dry", action="store_true")
    ap.add_argument("--show-mail", action="store_true", help="--dry のとき、送るはずのメール本文を表示する（作り置きはしない）")
    a = ap.parse_args()
    run(dry=a.dry)
