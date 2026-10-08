#!/usr/bin/env python3
"""国会議員全員（衆議院465・参議院248）の X アカウントの一覧を作る（2026-10-08 作成）。

  /usr/bin/python3 scripts/build_kokkai_x.py roster     # ① 名簿（衆議院の会派別名簿・参議院の議員一覧CSV）
  /usr/bin/python3 scripts/build_kokkai_x.py match      # ② Wikidata と既存の議員一覧（x_politicians.json）で照合
  /usr/bin/python3 scripts/build_kokkai_x.py search     # ③ 見つからない人を名前で X の投稿検索（1人2秒・約20分）
  /usr/bin/python3 scripts/build_kokkai_x.py verify     # ④ 見つけたアカウントのプロフィールを確かめる
  → data/kokkai_x.json  [{house, display, name, kana, kaiha, district, x, source, verified, followers, ...}]

- 名簿は公式の一覧から作る（Wikidata は過去の議員にも任期の終わりが入っていないことが多く、現職の絞り込みに使えない）。
- Wikidata（CC0）は「名前→X のユーザー名」の辞書としてだけ使う。衆議院議員 Q17506823・参議院議員 Q14552828。
- 既存の一覧 x_politicians.json は {ハンドル: 表示名}。表示名に名前（空白なし）が入っていれば当てる。
- 検索で見つけた候補は、プロフィール（名前・自己紹介）に本人の姓と「議員」「衆議院」「参議院」があるときだけ採る。
- verify は X 上のプロフィール（fxtwitter）で、存在・名前の一致・議員の肩書きを確かめる。同姓同名の取り違えを避けるため、
  名前の欄か自己紹介に姓が無いものは verified=False のまま残し、通知の対象にしない。
"""
import csv, io, json, os, re, sys, time, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
OUT = os.path.join(ROOT, "data", "kokkai_x.json")
REG = os.path.join(ROOT, "data", "x_politicians.json")
sys.path.insert(0, HERE)
import build_roster as B  # noqa: E402

UA = {"User-Agent": "xb4g-giin/1.0 (https://xb4g.com/giin/)"}


def load():
    return json.load(open(OUT, encoding="utf-8")) if os.path.exists(OUT) else []


def save(rows):
    json.dump(rows, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)


def roster():
    old = {(r["house"], r["name"]): r for r in load()}
    rows = []
    for r in B.fetch_shugiin():
        rows.append({k: r.get(k, "") for k in ("house", "display", "name", "kana", "district", "kaiha", "profile")})
    full = {x["略称"].strip(): x["会派名"].strip() for x in csv.DictReader(io.StringIO(B.get(B.SAN_KAIHA)))}
    for x in csv.DictReader(io.StringIO(B.get(B.SAN_CSV))):
        disp = x["議員氏名"].strip()
        rows.append({"house": "参議院", "display": disp, "name": B.norm_name(disp), "kana": x.get("読み方", "").strip(),
                     "district": x.get("選挙区", "").strip(), "kaiha": full.get(x.get("会派", "").strip(), x.get("会派", "").strip()),
                     "profile": x.get("議員個人の紹介ページ", "").strip()})
    for r in rows:   # 前回見つけたアカウントは引き継ぐ
        o = old.get((r["house"], r["name"]))
        if o:
            for k in ("x", "source", "verified", "followers", "x_name", "x_desc", "checked_at"):
                if k in o:
                    r[k] = o[k]
    save(rows)
    from collections import Counter
    print("名簿", len(rows), dict(Counter(r["house"] for r in rows)))


def wikidata():
    q = ("SELECT ?pLabel ?x WHERE { VALUES ?pos { wd:Q17506823 wd:Q14552828 } ?p wdt:P39 ?pos ; wdt:P2002 ?x . "
         "SERVICE wikibase:label { bd:serviceParam wikibase:language \"ja\". } }")
    u = "https://query.wikidata.org/sparql?" + urllib.parse.urlencode({"query": q})
    d = json.load(urllib.request.urlopen(urllib.request.Request(u, headers={**UA, "Accept": "application/sparql-results+json"}), timeout=180))
    m = {}
    for b in d["results"]["bindings"]:
        m.setdefault(re.sub(r"\s", "", b["pLabel"]["value"]), set()).add(b["x"]["value"])
    return m


def match():
    rows = load()
    wd = wikidata()
    reg = json.load(open(REG, encoding="utf-8")) if os.path.exists(REG) else {}
    n_wd = n_reg = 0
    for r in rows:
        if r.get("x"):
            continue
        hs = wd.get(r["name"])
        if hs and len(hs) == 1:
            r["x"], r["source"] = next(iter(hs)), "wikidata"
            n_wd += 1
            continue
        hit = [h for h, nm in reg.items() if r["name"] in re.sub(r"\s", "", nm or "")]
        if len(hit) == 1:
            r["x"], r["source"] = hit[0], "x_politicians"
            n_reg += 1
    save(rows)
    print(f"Wikidata {n_wd}人・既存の一覧 {n_reg}人・合計 {sum(1 for r in rows if r.get('x'))}/{len(rows)}人")


def fx_user(h):
    try:
        return json.load(urllib.request.urlopen(urllib.request.Request(f"https://api.fxtwitter.com/{h}", headers=UA), timeout=30)).get("user")
    except Exception:
        return None


def surname(r):
    # 衆議院の名簿は「姓　名」、参議院は「姓　名」。全角空白の前を姓とする（無ければ先頭2字）
    d = r.get("display") or ""
    return re.split(r"[\s　]+", d.strip())[0] if re.search(r"[\s　]", d) else r["name"][:2]


def name_hit(r, name, desc):
    t = (name or "") + " " + (desc or "")
    sn, kana_sn = surname(r), re.split(r"[\s　]+", (r.get("kana") or "").strip())[0]
    return sn in t or bool(kana_sn and kana_sn in t)


def looks_like(r, name, desc):
    t = (name or "") + " " + (desc or "")
    sn, kana_sn = surname(r), re.split(r"[\s　]+", (r.get("kana") or "").strip())[0]
    has_name = sn in t or (kana_sn and kana_sn in t)
    has_title = bool(re.search(r"衆議院|参議院|衆院|参院|議員", t))
    return has_name and has_title


def search():
    rows = load()
    todo = [r for r in rows if not r.get("x")]
    print("検索する人", len(todo))
    for i, r in enumerate(todo, 1):
        q = f'"{r["name"]}" (衆議院 OR 参議院 OR 議員)'
        try:
            d = json.load(urllib.request.urlopen(urllib.request.Request(
                "https://api.fxtwitter.com/2/search?q=" + urllib.parse.quote(q), headers=UA), timeout=40))
        except Exception:
            d = {}
        cands = {}
        for t in d.get("results") or []:
            a = t.get("author") or {}
            if looks_like(r, a.get("name"), a.get("description")):
                cands[a["screen_name"]] = a
        if len(cands) == 1:
            r["x"], r["source"] = next(iter(cands)), "search"
        print(f"\r{i}/{len(todo)} 見つかった {sum(1 for x in todo if x.get('x'))}", end="", flush=True)
        if i % 20 == 0:
            save(rows)
        time.sleep(2)
    print()
    save(rows)


def from_posts(rows):
    """Wikidata のユーザー名が古い（アカウントが見つからない）人を、X返信候補で集めた投稿の作者から名前で探して置き換える"""
    import glob
    auth = {}
    for pp in glob.glob(os.path.join(ROOT, "outputs", "x_reply_pick", "*", "posts-*.json")):
        for p in json.load(open(pp, encoding="utf-8")):
            if p.get("screen_name"):
                auth[p["screen_name"]] = (p.get("name") or "", p.get("description") or "")
    n = 0
    for r in rows:
        if r.get("verified"):
            continue
        hits = [h for h, (nm, ds) in auth.items() if looks_like(r, nm, ds) and r["name"] in re.sub(r"\s", "", nm + ds)]
        if len(hits) == 1 and hits[0] != r.get("x"):
            r["x"], r["source"] = hits[0], "posts"
            r.pop("note", None)
            n += 1
    print("集めた投稿の作者から置き換え", n)


def verify():
    rows = load()
    from_posts(rows)
    for i, r in enumerate([r for r in rows if r.get("x")], 1):
        u = fx_user(r["x"])
        if not u:
            r["verified"] = False
            r["note"] = "アカウントが見つからない"
        else:
            r["x_name"], r["x_desc"], r["followers"] = u.get("name"), (u.get("description") or "")[:160], u.get("followers")
            # Wikidata の対応は人が登録したものなので、名前（姓）が一致すれば確かめたとする。公式アカウントは
            # 自己紹介に「議員」を書かないことが多い（稲田朋美・江藤拓・大野敬太郎「MP」・麻生太郎事務所 2026-10-08）。
            # 検索・既存の一覧で見つけたものは、名前と議員の肩書きの両方を求める
            if r.get("source") == "wikidata":
                r["verified"] = name_hit(r, u.get("name"), u.get("description"))
            else:
                r["verified"] = looks_like(r, u.get("name"), u.get("description"))
        r["checked_at"] = time.strftime("%Y-%m-%d")
        print(f"\r確認 {i}", end="", flush=True)
        time.sleep(1.2)
    print()
    save(rows)
    from collections import Counter
    for h in ("衆議院", "参議院"):
        hs = [r for r in rows if r["house"] == h]
        print(h, len(hs), "人・X あり", sum(1 for r in hs if r.get("x")), "・確認済み", sum(1 for r in hs if r.get("verified")))
    print("出どころ", dict(Counter(r.get("source") for r in rows if r.get("verified"))))


if __name__ == "__main__":
    {"roster": roster, "match": match, "search": search, "verify": verify}[sys.argv[1]]()
