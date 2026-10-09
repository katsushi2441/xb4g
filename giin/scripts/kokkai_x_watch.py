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


def judge(text):
    b = json.dumps({"model": LLM, "prompt": PROMPT + text[:900], "stream": False, "format": SCHEMA, "think": False,
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
    stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M")
    json.dump([{"value": h["value"], "point": h["point"], "reason": h["reason"], "name": h["r"]["name"], "house": h["r"]["house"],
                "url": h["t"].get("url"), "text": h["t"].get("text")} for h in hits],
              open(os.path.join(OUT, f"hits-{stamp}.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"返信する価値あり {len(hits)}件（高い {sum(1 for h in hits if h['value'] == 3)}件）")
    if not dry:
        # 0件の回も送る（2026-10-09 ユーザー指示。黙ると、止まったのか0件なのか分からない）
        print("メール:", mail(hits) if hits else mail_status(
            f"国会議員の投稿 0件（{datetime.datetime.now():%H:%M}）",
            [f"{len(rows)}人を確認し、{datetime.datetime.fromtimestamp(since):%H:%M} 以降の新しい投稿は {len(posts)}件でした。",
             "そのうち返信する価値があると判定したものは 0件です（処理は最後まで動いています）。"]))
    if not dry:
        st["last_ts"] = now
        st["sent"] = (list(posts) + st.get("sent", []))[:3000]
        json.dump(st, open(STATE, "w", encoding="utf-8"))
    else:
        for h in hits:
            print(h["value"], h["r"]["name"], h["point"], h["t"].get("url"))
    return {"posts": len(posts), "hits": len(hits)}


def _password():
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("GMAIL_APP_PASSWORD="):
            return ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
    return ""


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


def prebuild(hits):
    """「★高い」は、メールを送る前に返信候補を作っておく（押してから作ると10〜40秒待たされる。2026-10-09）。
    作ったものは x_reply_draft.one のキャッシュに残り、メールのリンクを押すとすぐ出る。codex の枠を守るため高いものだけ"""
    import x_reply_draft as D
    done = set()
    for h in hits:
        if h["value"] != 3:
            continue
        try:
            if D.one(h["t"].get("url") or "").get("html"):
                done.add(h["t"].get("url"))
        except Exception:  # noqa: BLE001  作れなくてもメールは送る（押せばその場で作る）
            pass
    return done


def mail(hits):
    pw = ""
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("GMAIL_APP_PASSWORD="):
            pw = ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
    if not pw:
        return "skip (no app password)"
    pu, slugs = page_url(), aichi_slugs()
    ready = prebuild(hits)
    n3 = sum(1 for h in hits if h["value"] == 3)
    L = [f"国会議員の投稿のうち、返信する価値があると判定したもの {len(hits)}件（高い {n3}件）。投稿から30分以内のものだけです。返信は投稿から60分以内が目安です。", ""]
    for i, h in enumerate(hits, 1):
        r, t = h["r"], h["t"]
        ago = int((time.time() - (t.get("created_timestamp") or time.time())) / 60)
        left = max(0, REPLY_LIMIT // 60 - ago)
        L += [f"■{i}. {'★高い ' if h['value'] == 3 else ''}{r['display']}（{r['house']}・{r.get('kaiha', '')}・{r.get('district', '')}）{ago}分前の投稿・返信の目安まであと{left}分・表示{(t.get('views') or 0):,}",
              f"論点: {h['point']}　／　判定の理由: {h['reason']}",
              "内容: " + re.sub(r"\s+", " ", t.get("text") or "")[:220], "",
              f"・元の投稿: {t.get('url')}",
              f"・返信候補{'（作成済み・開くとすぐ出ます）' if t.get('url') in ready else 'を作る（開くと作り始めます）'}: {pu}?url={urllib.parse.quote(t.get('url') or '', safe='')}"]
        if r.get("profile"):
            L.append(f"・{r['house']}の議員紹介: {r['profile']}")
        if r["name"] in slugs:
            L.append(f"・議員ページ（愛知の国会議員 発言ログ）: https://xb4g.com/giin/{slugs[r['name']]}")
        L.append("")
    L.append("価値の判定は手元のAI（gemma4）の推定です。投稿は人が確かめてから行ってください。kdeck の giin-kokkai-x-watch から自動で送っています。")
    msg = MIMEText("\n".join(L), "plain", "utf-8")
    first = hits[0]["r"]["display"].replace("　", "")
    msg["Subject"] = Header(f"{'【要返信】' if n3 else ''}国会議員の投稿 {len(hits)}件（{first}ほか）", "utf-8")
    msg["From"] = MAIL_TO
    msg["To"] = MAIL_TO
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465, timeout=30) as sm:
            sm.login(MAIL_TO, pw)
            sm.sendmail(MAIL_TO, [MAIL_TO], msg.as_string())
        return f"sent {len(hits)}"
    except Exception as e:  # noqa: BLE001
        return f"error {type(e).__name__}"


def run_kokkai_x_watch_job(**_):
    """kdeck から呼ぶ入口。失敗した回もメールで知らせてから、ジョブとしては失敗にする"""
    try:
        r = run()
    except Exception as e:  # noqa: BLE001
        import traceback
        mail_status(f"国会議員の投稿 監視が失敗（{datetime.datetime.now():%H:%M}）",
                    [f"{type(e).__name__}: {e}", "", traceback.format_exc()[-1500:]])
        raise
    return {"ok": True, "items": r["hits"], **r}


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("cmd", choices=["run"])
    ap.add_argument("--dry", action="store_true")
    a = ap.parse_args()
    run(dry=a.dry)
