#!/usr/bin/env python3
"""政府の決定（閣議決定）の日付表と、各国会トラッカーの語がその本文に出てくるかを作る（2026-11-15）。

  /usr/bin/python3 scripts/fetch_gov_decisions.py            # 取得（済みのPDFは使い回す）→ data/gov_decisions.json
  /usr/bin/python3 scripts/fetch_gov_decisions.py --refetch  # PDFも取り直す

国政報告の「自分の質疑で政府を動かした」を確かめる材料。質疑の日付と、その話題が閣議決定の本文に
いつ書かれたかを、lib/timeline.php が同じ時系列に並べる。

対象（公式の一覧ページから取る。日付は一覧の記載どおり）:
- 経済財政運営と改革の基本方針（骨太の方針） 内閣府 honebuto-index.html（2016年以降）
- 総合経済対策など                         内閣府 keizaitaisaku.html（一覧ページに載っているもの）
- 税制改正の大綱                           財務省 tax_reform/outline/（財務省のサイトにある年度だけ。それ以前は国立国会図書館の保存版）

つなぎ方: トラッカーの語（「救急車 有料化」のような空白区切りは、すべてが同じ文に入るとき）が本文の文に出てくれば、
その決定とトラッカーをつなぐ。引用はPDFから取り出した文そのもの（改行を除くだけ）。要約はしない。
"""
import json
import re
import subprocess
import sys
import unicodedata
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RAW = ROOT / "outputs" / "gov_decisions" / "raw"
OUT = ROOT / "data" / "gov_decisions.json"
UA = {"User-Agent": "Mozilla/5.0 (compatible; xb4g-giin/1.0)"}
REFETCH = "--refetch" in sys.argv
ERA = {"令和": 2018, "平成": 1988}


def get(url: str) -> bytes:
    return urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=120).read()


def text_of(b: bytes) -> str:
    for enc in ("utf-8", "cp932", "euc-jp"):
        try:
            return b.decode(enc)
        except UnicodeDecodeError:
            pass
    return b.decode("utf-8", "ignore")


def z2h(s: str) -> str:
    return s.translate(str.maketrans("０１２３４５６７８９", "0123456789"))


def wareki(s: str) -> str:
    """「令和７年６月13日」→ 2025-06-13。見つからなければ ''"""
    m = re.search(r"(令和|平成)(元|[0-9０-９]+)年\s*([0-9０-９]+)月\s*([0-9０-９]+)日", s)
    if not m:
        return ""
    y = 1 if m.group(2) == "元" else int(z2h(m.group(2)))
    return f"{ERA[m.group(1)] + y:04d}-{int(z2h(m.group(3))):02d}-{int(z2h(m.group(4))):02d}"


def links(html: str):
    for m in re.finditer(r'<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>', html, re.S):
        yield m.group(1), re.sub(r"<[^>]+>|&nbsp;|\s+", " ", m.group(2)).strip()


def absu(base: str, u: str) -> str:
    return urllib.parse.urljoin(base, u)


def decisions() -> list:
    out = []
    # 骨太の方針
    idx = "https://www5.cao.go.jp/keizai-shimon/kaigi/cabinet/honebuto/honebuto-index.html"
    for u, t in links(text_of(get(idx))):
        if "閣議決定" not in t or "基本方針" not in t:
            continue
        d = wareki(t)
        if not d or d < "2016-01-01":
            continue
        page = absu(idx, u)
        pdf = ""
        for pu, pt in links(text_of(get(page))):
            # 本文PDF。2026年のページはリンクの文に「閣議決定」が無いので、ファイル名（*_basicpolicies_ja.pdf）でも見る
            if pu.endswith(".pdf") and "_en" not in pu and "概要" not in pt and ("閣議決定" in pt or pu.endswith("basicpolicies_ja.pdf")):
                pdf = absu(page, pu); break
        title = re.sub(r"（.*?閣議決定）", "", t).strip()
        m = re.match(r"(経済財政運営と改革の基本方針\s*\d{4})", title)
        out.append({"id": "honebuto-" + d[:4], "kind": "honebuto", "title": (m.group(1) if m else title).replace(" ", ""),
                    "subtitle": title, "date": d, "how": "閣議決定", "page": page, "url": pdf})
    # 経済対策（一覧ページの本文PDF）
    idx = "https://www5.cao.go.jp/keizai1/keizaitaisaku/keizaitaisaku.html"
    for u, t in links(text_of(get(idx))):
        if not u.endswith(".pdf") or not re.search(r"閣議決定|関係閣僚会議決定", t):
            continue
        if "Comprehensive" in t or "効果" in t:
            continue
        d = wareki(t)
        title = re.sub(r"^（[0-9０-９]+）", "", t)
        title = re.sub(r"（(令和|平成).*?決定）.*$", "", title).strip()
        how = "閣議決定" if "閣議決定" in t else "関係閣僚会議決定"
        out.append({"id": "taisaku-" + d, "kind": "taisaku", "title": title, "subtitle": "", "date": d, "how": how,
                    "page": idx, "url": absu(idx, u)})
    # 税制改正の大綱（財務省のサイトにある年度）
    idx = "https://www.mof.go.jp/tax_policy/tax_reform/outline/index.html"
    for u, t in links(text_of(get(idx))):
        m = re.search(r"fy(\d{4})/(\d{8})taikou\.pdf$", u)
        if not m:
            continue
        fy, ymd = int(m.group(1)), m.group(2)
        d = f"{ymd[:4]}-{ymd[4:6]}-{ymd[6:]}"
        out.append({"id": f"zeisei-taikou-fy{fy}", "kind": "zeisei", "title": f"令和{fy - 2018}年度税制改正の大綱", "subtitle": "",
                    "date": d, "how": "閣議決定", "page": idx, "url": absu(idx, u)})
    # 同じ決定が二度出ないように
    seen, uniq = set(), []
    for x in out:
        if x["id"] in seen or not x["url"] or not x["date"]:
            continue
        seen.add(x["id"]); uniq.append(x)
    return sorted(uniq, key=lambda x: x["date"])


def pdf_text(dec: dict) -> str:
    RAW.mkdir(parents=True, exist_ok=True)
    p = RAW / (dec["id"] + ".pdf")
    if REFETCH or not p.exists():
        p.write_bytes(get(dec["url"]))
    t = subprocess.run(["pdftotext", "-layout", str(p), "-"], capture_output=True, text=True).stdout
    # 康熙部首・CJK互換漢字などを通常の字に（NFKC）。引用もこの正規化後の文から取る
    t = unicodedata.normalize("NFKC", t)
    (RAW / (dec["id"] + ".txt")).write_text(t, encoding="utf-8")
    return t


def sentences(t: str) -> list:
    # 行をつなぎ（日本語の改行は語の途中で切れる）、ページ番号だけの行・行頭の空白を落としてから「。」で切る
    lines = [ln.strip() for ln in t.splitlines()]
    lines = [ln for ln in lines if ln and not re.fullmatch(r"[-‐－ー\s]*\d+[-‐－ー\s]*", ln)]
    body = re.sub(r"\s+", "", "".join(lines))
    return [s + "。" for s in body.split("。") if s]


def check_date(dec: dict, t: str) -> bool:
    """一覧の日付が本文の冒頭にも書かれているか（取り違えの検出）"""
    head = re.sub(r"\s+", "", t[:3000])
    d = dec["date"]
    y, m, dd = int(d[:4]), int(d[5:7]), int(d[8:])
    era = f"令和{y - 2018}" if y >= 2019 else f"平成{y - 1988}"
    alts = [f"{era}年{m}月{dd}日", f"{'令和元' if y == 2019 else era}年{m}月{dd}日"]
    return any(a in head for a in alts)


def main():
    decs = decisions()
    T = json.load(open(ROOT / "data" / "trackers.json", encoding="utf-8"))
    hits = {}
    for dec in decs:
        t = pdf_text(dec)
        dec["date_in_text"] = check_date(dec, t)
        S = sentences(t)
        dec["sentences"] = len(S)
        for tr in T:
            n, quote, word = 0, "", ""
            for w in tr.get("words", []):
                parts = [unicodedata.normalize("NFKC", p) for p in re.split(r"[\s　]+", w.strip()) if p]
                if not parts:
                    continue
                for s in S:
                    # 目次（点線の引出し線や、見出しが続けて連なった長い文）は数えない
                    if re.search(r"\.{4,}|…{2,}|‥", s) or len(s) > 350:
                        continue
                    if all(p in s for p in parts):
                        n += 1
                        if not quote:
                            quote, word = s, parts[0]
            if n:
                # 長い文は語の前後を切り出す（切り出した部分は本文の連続した文字列のまま）
                if len(quote) > 160:
                    i = quote.find(word)
                    s0 = max(0, i - 70)
                    quote = ("…" if s0 else "") + quote[s0:s0 + 150] + ("…" if s0 + 150 < len(quote) else "")
                hits.setdefault(tr["key"], {})[dec["id"]] = {"n": n, "word": word, "quote": quote}
        print(f"{dec['date']} {dec['how']} {dec['title'][:40]}  文{dec['sentences']}  日付の照合={'OK' if dec['date_in_text'] else '本文に見当たらず'}")
    OUT.write_text(json.dumps({"generated_at": __import__("datetime").date.today().isoformat(),
                               "note": "日付は内閣府・財務省の一覧ページの記載（本文冒頭の日付と照合済み）。引用はPDFの本文（NFKC正規化・改行除去のみ）。語のすぐ後ろの数字は脚注の番号のことがある。",
                               "decisions": decs, "hits": hits}, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"決定 {len(decs)} 件 / つながったトラッカー {len(hits)} / {len(T)} → {OUT}")


if __name__ == "__main__":
    main()
