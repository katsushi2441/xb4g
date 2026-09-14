#!/usr/bin/env python3
"""議員マスタを作る。

**対象の線引き＝「愛知の有権者だけが投票用紙に書ける候補」。**
  衆議院 愛知1〜16区 ／ 衆議院 比例東海ブロック ／ 参議院 愛知県選挙区
参議院比例は全国共通なので入れない。この線なら恣意的な取捨選択が無く、
「なぜこの人が入っていて、あの人が入っていないのか」に一言で答えられる。

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
    ("国民民主", "国民民主"), ("自由民主", "自民"), ("自民", "自民"),
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


def fetch_sangiin():
    # 参議院CSVの会派欄は略称（「民主」など）なので、kaiha.csv で正式名に戻す
    full = {}
    for r in csv.DictReader(io.StringIO(get(SAN_KAIHA))):
        full[r["略称"].strip()] = r["会派名"].strip()
    body = get(SAN_CSV)
    rows = []
    for r in csv.DictReader(io.StringIO(body)):
        if r.get("選挙区", "").strip() != "愛知":
            continue
        disp = r["議員氏名"].strip()
        rows.append({
            "house": "参議院",
            "display": disp,
            "name": norm_name(disp),
            "kana": r.get("読み方", "").strip(),
            "district": "愛知",
            "wins": r.get("当選回数", "").strip(),
            "kaiha": full.get(r.get("会派", "").strip(), r.get("会派", "").strip()),
            "profile": r.get("議員個人の紹介ページ", "").strip(),
            "photo": r.get("写真URL", "").strip(),
            "term_end": r.get("任期満了", "").strip(),
        })
    return rows


def main():
    shu = fetch_shugiin()
    target = [r for r in shu if re.match(r"^愛知\d+$", r["district"]) or "東海" in r["district"]]
    san = fetch_sangiin()
    rows = target + san
    for i, r in enumerate(rows, 1):
        r["id"] = i
        r["party"] = party_of(r["kaiha"])
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(rows, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"衆議院 全体 {len(shu)}人 → 対象 {len(target)}人")
    print(f"参議院 愛知選挙区 {len(san)}人")
    print(f"合計 {len(rows)}人 → {OUT}")
    from collections import Counter
    for k, v in Counter(r["party"] for r in rows).most_common():
        print(f"  {k:<26} {v:>3}人")


if __name__ == "__main__":
    main()
