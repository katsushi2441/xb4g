#!/usr/bin/env python3
"""議員マスタを作る。

**対象の線引き＝「愛知の有権者の一票が当落に効く議員」。**
  衆議院 愛知1〜16区 ／ 衆議院 比例東海ブロック ／ 参議院 愛知県選挙区
参議院比例は全国共通なので入れない。この線なら恣意的な取捨選択が無く、
「なぜこの人が入っていて、あの人が入っていないのか」に一言で答えられる。
例外＝参議院比例のうち、愛知の地方議会の議員を務めた人（AICHI_LOCAL_HIREI。2026-10-08 伊藤辰夫・元愛知県議）。
  愛知の有権者が地方選で選んできた人なので入れる。id がずれないよう名簿の末尾に足す。

出典:
  衆議院 会派別議員一覧 https://www.shugiin.go.jp/internet/itdb_annai.nsf/html/statics/syu/0NNkaiha.htm
    （1ページ＝1会派。<TR>/<TD> が大文字・文字コードは Shift_JIS）
  参議院 smartnews-smri/house-of-councillors の data/giin.csv（MIT・選挙区つき）

  /usr/bin/python3 scripts/build_roster.py
"""
import csv, io, json, os, re, time, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "data", "roster.json")
UA = {"User-Agent": "Mozilla/5.0 (compatible; xb4g-giin/1.0; +https://xb4g.com/giin/)"}

SHU_BASE = "https://www.shugiin.go.jp/internet/itdb_annai.nsf/html/statics/syu/"
SAN_CSV = "https://raw.githubusercontent.com/smartnews-smri/house-of-councillors/main/data/giin.csv"
SAN_KAIHA = "https://raw.githubusercontent.com/smartnews-smri/house-of-councillors/main/data/kaiha.csv"

# 会派名は衆参で表記が違う（衆「国民民主党・無所属クラブ」／参「国民民主党・新緑風会」）。
# 並べて比べるための短い名前をここで1つに寄せる。会派≠政党なので、あくまで表示用。
PARTY = [
    ("国民民主", "国民民主"), ("中道", "中道改革連合"), ("志士の会", "志士の会"), ("自由民主", "自民"), ("自民", "自民"),
    ("立憲", "立憲"), ("公明", "公明"), ("維新", "維新"), ("共産", "共産"),
    ("参政", "参政"), ("チームみらい", "チームみらい"), ("中道改革", "中道改革連合"),
    ("社会民主", "社民"), ("保守", "日本保守党"), ("いのち", "いのちの党"),
    ("沖縄", "沖縄の風"),
]


def party_of(kaiha):
    for key, label in PARTY:
        if key in kaiha:
            return label
    return "無所属"


def get(url, enc=None):
    req = urllib.request.Request(url, headers=UA)
    raw = urllib.request.urlopen(req, timeout=90).read()
    return raw.decode(enc, errors="ignore") if enc else raw.decode("utf-8", errors="ignore")


def strip_tags(x):
    import html as H
    return H.unescape(re.sub(r"<[^>]+>", "", x)).strip()


def norm_name(s):
    """NDL の speaker と突き合わせるため、全角・半角の空白を全部落とす。"""
    return re.sub(r"[\s　]+", "", s).rstrip("君")


def fetch_shugiin():
    # 会派ページの一覧は 011kaiha.htm のヘッダーから取れる
    top = get(SHU_BASE + "011kaiha.htm", "shift_jis")
    pages = {}
    for h, t in re.findall(r'(?i)<a\s+href="(\d+kaiha\.htm)"[^>]*>(.*?)</a>', top):
        pages.setdefault(h, strip_tags(t))
    rows = []
    for fn, kaiha in sorted(pages.items()):
        s = top if fn == "011kaiha.htm" else get(SHU_BASE + fn, "shift_jis")
        for tr in re.findall(r"(?is)<tr[^>]*>(.*?)</tr>", s):
            tds = re.findall(r"(?is)<td[^>]*>(.*?)</td>", tr)
            if len(tds) < 4:
                continue
            disp = strip_tags(tds[0]).rstrip("君")
            if not disp or disp in ("氏名", "議員名"):
                continue
            href = re.search(r"href='([^']+)'", tds[0])
            rows.append({
                "house": "衆議院",
                "display": disp,
                "name": norm_name(disp),
                "kana": strip_tags(tds[1]),
                "district": strip_tags(tds[2]),
                "wins": strip_tags(tds[3]),
                "kaiha": kaiha,
                "profile": ("https://www.shugiin.go.jp" +
                            href.group(1).replace("../../../..", "/internet")) if href else "",
            })
        if fn != "011kaiha.htm":
            time.sleep(2)
    return rows


# 参議院比例のうち、愛知の地方議会の議員を務めた人（議員氏名＝参議院CSVの表記）
AICHI_LOCAL_HIREI = {"伊藤　辰夫": "元愛知県議会議員"}


def fetch_sangiin(all_districts=False):
    # 参議院CSVの会派欄は略称（「民主」など）なので、kaiha.csv で正式名に戻す
    full = {}
    for r in csv.DictReader(io.StringIO(get(SAN_KAIHA))):
        full[r["略称"].strip()] = r["会派名"].strip()
    body = get(SAN_CSV)
    rows = []
    for r in csv.DictReader(io.StringIO(body)):
        disp = r["議員氏名"].strip()
        ku = r.get("選挙区", "").strip()
        if not all_districts and ku != "愛知" and not (ku == "比例" and disp in AICHI_LOCAL_HIREI):
            continue
        rows.append({
            "house": "参議院",
            "display": disp,
            "name": norm_name(disp),
            "kana": r.get("読み方", "").strip(),
            "district": ku,
            "wins": r.get("当選回数", "").strip(),
            "kaiha": full.get(r.get("会派", "").strip(), r.get("会派", "").strip()),
            "profile": r.get("議員個人の紹介ページ", "").strip(),
            "photo": r.get("写真URL", "").strip(),
            "term_end": r.get("任期満了", "").strip(),
        })
    return rows


def _is_aichi(r):
    """くわしいページ（YouTube・本人の発信・AIの考察）を作る「愛知の有権者の一票が当落に効く議員」か"""
    if r["house"] == "衆議院":
        return bool(re.match(r"^愛知\d+$", r["district"])) or "東海" in r["district"]
    return r["district"] == "愛知" or (r["district"] == "比例" and r["display"] in AICHI_LOCAL_HIREI)


def main():
    """全国の国会議員の名簿（2026-10-15〜。それまでは愛知の46人だけ）。

    **id と slug は動かさない。** 議員ページのURL（/giin/<slug>）と発言の giin_id がこれに乗っている。
    前回の roster.json にいる人（院と名前が同じ）は id・slug をそのまま使い、新しい人だけ末尾に足す。
    名簿から外れた人（引退・落選）も消さずに残し current=False にする（過去の発言のページは残す）。
    detail=True が愛知の議員（くわしいページ）。それ以外は発言・ことがら・トラッカーだけの軽いページ。
    """
    sys_path_romaji()
    from romaji import slug_of
    old = json.load(open(OUT, encoding="utf-8")) if os.path.exists(OUT) else []
    by_key = {(r["house"], r["name"]): r for r in old}
    shu = fetch_shugiin()
    san = fetch_sangiin(all_districts=True)
    # 愛知を先に並べる（初めて作るときに id 1〜46 が愛知になるように。2回目以降は既存の id を使う）
    cur = sorted(shu + san, key=lambda r: (not _is_aichi(r), r["house"] != "衆議院"))
    rows = [dict(r) for r in old]
    for r in rows:
        r["current"] = False
    idx = {(r["house"], r["name"]): i for i, r in enumerate(rows)}
    used = {r.get("slug") for r in rows}
    nid = max([r["id"] for r in rows] or [0])
    added = 0
    for r in cur:
        r["party"] = party_of(r["kaiha"])
        r["detail"] = _is_aichi(r)
        r["current"] = True
        k = (r["house"], r["name"])
        if k in idx:
            o = rows[idx[k]]
            o.update({x: r[x] for x in r if x not in ("id", "slug")})
            continue
        nid += 1
        slug = slug_of(r.get("kana", "")) or f"giin-{nid}"
        if slug in used:   # 同じ読みの人（院や選挙区で分ける）
            alt = slug + ("-shu" if r["house"] == "衆議院" else "-san")
            slug = alt if alt not in used else f"{slug}-{nid}"
        used.add(slug)
        r["id"], r["slug"] = nid, slug
        rows.append(r)
        idx[k] = len(rows) - 1
        added += 1
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(rows, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    from collections import Counter
    print(f"衆議院 {len(shu)}人・参議院 {len(san)}人 → 名簿 {len(rows)}人（新しく {added}人・"
          f"愛知 {sum(1 for r in rows if r.get('detail'))}人・名簿から外れた {sum(1 for r in rows if not r['current'])}人）")
    for k, v in Counter(r["party"] for r in rows if r["current"]).most_common():
        print(f"  {k:<26} {v:>3}人")


def sys_path_romaji():
    import sys
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))


if __name__ == "__main__":
    main()
