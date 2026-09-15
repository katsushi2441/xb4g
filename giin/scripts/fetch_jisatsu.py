#!/usr/bin/env python3
"""子どもの自殺に関する公表統計を取り込む。**議員事務所が「知らない」状態をなくすための道具。**

年に529人、538人という数は、報道されるのはごく一部である。
報道を追うと「報道された事案」だけに反応する形になり、実態から偏る。
だから**公表統計を軸にする**。報道されなかった子も数に入る。

取るもの:
- 警察庁・厚生労働省「◯年中における自殺の状況」（毎年3月ごろ公表）
  … 小中高生の自殺者数（小・中・高別、男女別）。**月別の内訳は年次資料にしかない。**
- 警察庁「月別の自殺者数について（暫定値）」（毎月）
  … 総数と都道府県別のみ。小中高生の内訳は無い。
- 文部科学省「児童生徒の問題行動・不登校等調査」（毎年10月ごろ）
  … 学校が把握した自殺、背景調査の実施件数、遺族への説明件数

**数字はAIに書かせない。** 公表PDFから機械で取り、取れなかったものは空にする。
取れたふりをしない。

    python3 scripts/fetch_jisatsu.py            # 取り込んで data/jisatsu.json を更新
    python3 scripts/fetch_jisatsu.py --draft    # 定期発信の文案を出す（数字は取り込んだ値だけ）
"""
import argparse
import datetime
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "data", "jisatsu.json")
UA = {"User-Agent": "Mozilla/5.0 (compatible; xb4g-giin/1.0; +https://xb4g.com/giin/)"}
NPA_INDEX = "https://www.npa.go.jp/publications/statistics/safetylife/jisatsu.html"
NPA = "https://www.npa.go.jp"


def get(url: str) -> bytes:
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=120) as r:
        return r.read()


def pdftext(data: bytes) -> str:
    with tempfile.NamedTemporaryFile(suffix=".pdf", delete=False) as f:
        f.write(data)
        p = f.name
    try:
        r = subprocess.run(["pdftotext", "-layout", p, "-"],
                           capture_output=True, text=True, timeout=180)
        return r.stdout or ""
    finally:
        os.unlink(p)


def links():
    """警察庁の一覧から、年次資料と月次暫定値のURLを拾う。"""
    html = get(NPA_INDEX).decode("utf-8", "ignore")
    year_pdf = month_pdf = None
    for href, text in re.findall(r'href="([^"]+)"[^>]*>(.*?)</a>', html, re.S):
        t = re.sub(r"<[^>]+>", "", text)
        if not year_pdf and "における自殺の状況" in t and "資料" in t and href.endswith(".pdf"):
            year_pdf = NPA + href if href.startswith("/") else href
        if not month_pdf and "月別自殺者数" in t and href.endswith(".pdf"):
            month_pdf = NPA + href if href.startswith("/") else href
    return year_pdf, month_pdf


def _n(x: str) -> int:
    """全角の数字にも対応する。公表資料は「前年から９人増加の538人」のように混在する。"""
    z = x.translate(str.maketrans("０１２３４５６７８９", "0123456789"))
    return int(re.sub(r"[^\d]", "", z))


def parse_year(txt: str) -> dict:
    """小中高生の数を年次資料から取る。取れない項目は入れない。"""
    t = re.sub(r"[ \t]+", " ", txt).replace("\n", " ")
    out = {}
    m = re.search(r"小中高生の自殺者数は前年から\s*([0-9０-９,，]+)\s*人(増加|減少)の"
                  r"\s*([0-9０-９,，]+)\s*人", t)
    if m:
        out["total"] = _n(m.group(3))
        out["diff"] = (1 if m.group(2) == "増加" else -1) * _n(m.group(1))
    m = re.search(r"統計のある\s*([0-9０-９]{4})\s*[（(]?[^)）]{0,12}[)）]?\s*年以降で最多", t)
    if m:
        out["record_since"] = _n(m.group(1))
        out["is_record"] = True
    # 小・中・高の内訳（図表1-2 の行）
    m = re.search(r"小学生 中学生 高校生\s*総数\s*[\d,]+\s*[\d,]+\s*([\d,]+)\s*([\d,]+)\s*([\d,]+)\s*([\d,]+)", t)
    if m:
        out["breakdown"] = {"小学生": int(m.group(2).replace(",", "")),
                            "中学生": int(m.group(3).replace(",", "")),
                            "高校生": int(m.group(4).replace(",", ""))}
    m = re.search(r"(令和\s*\d+)\s*年中における自殺の状況", t)
    if m:
        out["era"] = re.sub(r"\s+", "", m.group(1)) + "年"
    return out


def parse_month(txt: str) -> dict:
    """月次暫定値。**総数のみ。小中高生の内訳は公表されていない。**"""
    t = re.sub(r"[ \t]+", " ", txt)
    out = {}
    m = re.search(r"（(\S+?)末の暫定値）", t)
    if m:
        out["asof"] = m.group(1)
    m = re.search(r"総数\s+([\d,]+)((?:\s+[\d,\-]+){12})", t)
    if m:
        out["total"] = int(m.group(1).replace(",", ""))
        out["monthly"] = [None if x == "-" else int(x.replace(",", ""))
                          for x in m.group(2).split()]
    return out


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--draft", action="store_true")
    a = ap.parse_args()

    old = json.load(open(OUT, encoding="utf-8")) if os.path.isfile(OUT) else {}

    if a.draft:
        return draft(old)

    y_url, m_url = links()
    now = datetime.datetime.now().strftime("%Y-%m-%d")
    rec = {"checked_at": now}
    if y_url:
        d = parse_year(pdftext(get(y_url)))
        d["source_url"] = y_url
        d["source"] = "警察庁・厚生労働省「自殺の状況」"
        rec["year"] = d
        dif = d.get('diff')
        print(f"  年次: {d.get('era','?')} 小中高生 {d.get('total','取れず')}人 "
              f"（前年比 {('+' + str(dif)) if isinstance(dif, int) and dif > 0 else dif}）"
              f"  内訳 {d.get('breakdown','取れず')}")
    if m_url:
        d = parse_month(pdftext(get(m_url)))
        d["source_url"] = m_url
        d["source"] = "警察庁「月別の自殺者数について（暫定値）」"
        d["note"] = "総数のみ。小中高生の内訳は月次では公表されていない。"
        rec["month"] = d
        print(f"  月次: {d.get('asof','?')} 総数 {d.get('total','取れず')}人")

    # 文科省の調査は年1回・様式が変わるので、いまは data/stats.json に人が登録する
    rec["mext_note"] = ("背景調査の実施件数・遺族への説明件数は "
                        "文部科学省「児童生徒の問題行動・不登校等調査」から。"
                        "様式が毎年変わるため data/stats.json に人が登録する。")

    changed = json.dumps(rec.get("year"), ensure_ascii=False) != \
              json.dumps(old.get("year"), ensure_ascii=False)
    rec["changed"] = changed
    json.dump(rec, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"\n{'★ 年次の数字が変わりました' if changed else '年次の数字に変化なし'} → {OUT}")
    return 0


def draft(d: dict) -> int:
    """定期発信の文案。**数字は取り込んだものだけを使い、文章は型で組む。**
    AIに書かせない（数え間違いを文章の形で配らないため）。"""
    y = d.get("year") or {}
    if not y.get("total"):
        print("年次の数字が取れていません。先に取り込んでください。", file=sys.stderr)
        return 1
    b = y.get("breakdown") or {}
    lines = [
        f"{y.get('era','昨年')}、{y['total']}人の小中高生が自らの命を絶ちました。",
        "お一人お一人のご冥福をお祈りします。",
        "",
    ]
    if b:
        lines.append("　内訳は小学生{小学生}人、中学生{中学生}人、高校生{高校生}人です。".format(**b))
    if y.get("diff") is not None:
        lines.append(f"　前年から{abs(y['diff'])}人{'増えました' if y['diff'] > 0 else '減りました'}。")
    if y.get("record_since"):
        lines.append(f"　統計のある{y['record_since']}年以降で最も多い数です。")
    lines += ["",
              "この数には、報道されなかった子どもも含まれています。",
              "なぜ亡くなったのかを調べる仕組みはありますが、十分に使われていません。",
              "調べられないまま終わる子を、一人でも減らしたいと考えています。",
              "",
              f"出典：{y.get('source','')}（{d.get('checked_at','')}確認）",
              y.get("source_url", "")]
    print("\n".join(lines))
    return 0


if __name__ == "__main__":
    sys.exit(main())
