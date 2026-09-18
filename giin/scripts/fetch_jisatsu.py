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



# ---- 図表３－４ 原因・動機／図表３－５ 都道府県別 --------------------------------
# **列の対応は推測しない。** pdftotext は折り返した見出しを行ごとにばらすので、
# 見出しの位置から列を当てるのは当てにならない。かわりに**検算で確かめる**:
#   ・学校問題の「計」＝ 内訳8項目の和
#   ・各区分の「総計」＝「男性」＋「女性」
# どちらかが合わなければ、その年は取り込まない（取れたふりをしない）。

# 学校問題の内訳。上の検算が通ったときだけ、この順で名前をつける。
SCHOOL_COLS = ["学業不振", "進路に関する悩み（入試以外）", "入試に関する悩み", "いじめ",
               "学友との不和（いじめ以外）", "教師との人間関係", "性別による差別", "その他"]
TOP_COLS = ["家庭問題", "健康問題", "経済・生活問題", "勤務問題", "交際問題", "学校問題"]
ROWS = [(g, s) for g in ("小学生", "中学生", "高校生", "合計") for s in ("総計", "男性", "女性")]


def _table_start(txt: str, name: str) -> int:
    """表の本体の位置。**目次にも同じ見出しが出る**ので、目次の行（・・・で頁へ送る）は飛ばす。"""
    for m in re.finditer(re.escape(name), txt):
        line = txt[txt.rfind("\n", 0, m.start()) + 1: txt.find("\n", m.start())]
        if "・・・" not in line:
            return m.start()
    return -1


def _ints(line: str):
    """半角数字だけを拾う。`\d` は全角も拾ってしまい「令和７年」の７が混ざる。"""
    return [int(x) for x in re.findall(r"(?<![0-9.])[0-9]+(?![0-9.%])", line)]


def parse_motive(txt: str) -> dict:
    """図表３－４から、小中高生別の原因・動機を取る。検算に通らなければ {} を返す。"""
    i = _table_start(txt, "図表３－４")
    if i < 0:
        return {}
    rows = []
    for line in txt[i:].split("\n"):
        v = _ints(line)
        if len(v) == 16:
            rows.append(v)
        if len(rows) == 12:          # 最初の12行＝最新年。以降は前年の表。
            break
    if len(rows) != 12:
        return {}

    for v in rows:                                    # 検算1: 学校問題の計＝内訳の和
        if v[5] != sum(v[6:14]):
            return {}
    for k in range(0, 12, 3):                         # 検算2: 総計＝男性＋女性
        if any(rows[k][c] != rows[k + 1][c] + rows[k + 2][c] for c in range(16)):
            return {}

    out = {}
    for (grp, sex), v in zip(ROWS, rows):
        d = dict(zip(TOP_COLS, v[:6]))
        d["学校問題の内訳"] = dict(zip(SCHOOL_COLS, v[6:14]))
        d["その他"], d["不詳"] = v[14], v[15]
        out.setdefault(grp, {})[sex] = d
    return out


def parse_pref(txt: str, want: str = "愛知県") -> dict:
    """図表３－５から1県ぶんだけ取る。**全国の一覧は持たない**（順位表にしないため）。

    `*` は伏せ字。警察庁の注記では「各欄の数値が２人以下の場合」非公表で、
    ３人以上でも他の非公表値が分かってしまう場合は非公表にする、とされている。
    **0 に読み替えたり、合計から引き算して復元したりはしない。** None のまま持つ。
    """
    i = _table_start(txt, "図表３－５")
    if i < 0:
        return {}
    cols = ["合計", "小学生男性", "小学生女性", "中学生男性", "中学生女性", "高校生男性", "高校生女性"]
    for line in txt[i:].split("\n"):
        s = line.strip()
        if not s.startswith(want):
            continue
        cells = s[len(want):].split()
        if len(cells) != 7 or not all(c == "*" or c.isdigit() for c in cells):
            continue
        return {"name": want,
                "values": {k: (None if c == "*" else int(c)) for k, c in zip(cols, cells)},
                "masked_note": "「—」は非公表。警察庁の注記では、各欄の数値が２人以下の場合に"
                               "非公表とし、３人以上でも表示すると他の非公表の数値が明らかになる"
                               "場合は非公表とする、とされている。",
                "basis": "自殺者数は生前の住居地に基づいて集計されている。"}
    return {}


# ---- 画面へ渡す --------------------------------------------------------------
STATS = os.path.join(ROOT, "data", "stats.json")
NOTICE = "つらい気持ちを抱えている方へ：厚生労働省の「まもろうよ こころ」に電話・SNSの相談窓口がまとまっています。"
NOTICE_URL = "https://www.mhlw.go.jp/mamorouyokokoro/"
NOTICE_LABEL = "まもろうよ こころ（厚生労働省・相談窓口）"
THEME = "kosodate"
TRACKER = "jisatsu-taisaku"


def _row(label, value, note=""):
    r = {"label": label, "value": value}
    if note:
        r["note"] = note
    return r


def _by_grade(m, key, sub=None):
    """小・中・高の内訳を「小学生8／中学生61／高校生78」の形にする。"""
    g = []
    for k in ("小学生", "中学生", "高校生"):
        d = m[k]["総計"]
        g.append(f"{k}{(d['学校問題の内訳'][sub] if sub else d[key])}")
    return "／".join(g)


def build_stats(rec: dict) -> list:
    """jisatsu.json から、画面に出す統計ブロックを組む。**手で書いた分とは混ぜない。**

    出来上がりは data/stats.json の stats[] に `auto: "jisatsu"` 印をつけて入れる。
    次に走ったときは、その印のものだけ捨てて作り直す。
    """
    out = []
    era = (rec.get("year") or {}).get("era", "")
    # tracker を付けると、同じことがらの別トラッカー（こども誰でも通園など）には出ない。
    common = {"theme": THEME, "tracker": TRACKER, "auto": "jisatsu", "notice": NOTICE,
              "notice_url": NOTICE_URL, "notice_label": NOTICE_LABEL,
              "checked_at": rec.get("checked_at", "")}

    pf = rec.get("pref") or {}
    if pf.get("values"):
        v = pf["values"]
        rows = [_row("合計", ("—" if v["合計"] is None else f"{v['合計']}人"))]
        for g in ("小学生", "中学生", "高校生"):
            for sx in ("男性", "女性"):
                c = v.get(g + sx)
                rows.append(_row(f"{g} {sx}", "—" if c is None else f"{c}人",
                                 "2人以下のため非公表" if c is None else ""))
        out.append(dict(common, title=f"{pf['name']}の小中高生の自殺者数（都道府県別）",
                        asof=era, rows=rows,
                        source=pf.get("source", ""), source_url=pf.get("source_url", ""),
                        caveat="「—」は非公表です。警察庁の注記では、各欄の数値が2人以下の場合は非公表とし、"
                               "3人以上でも表示すると他の非公表の数値が明らかになる場合は非公表とする、"
                               "とされています。当サイトでは合計から差し引いて復元することはしていません。"
                               "自殺者数は生前の住居地に基づく集計です。"
                               "他の都道府県の数値は出典の資料にありますが、当サイトでは並べません。"
                               "順位の形にすると、対策の中身ではなく順位が話題になるためです。"
                               "なお、市区町村ごとの分析（地域自殺実態プロファイル）は、いのち支える自殺対策推進センターが"
                               "毎年つくって自治体へ提供していますが、一般には公開されていません。"
                               "お住まいの市区町村の状況は、自治体の自殺対策の担当課が把握しています。"))

    mv = rec.get("motive") or {}
    m = mv.get("data") or {}
    if m.get("合計", {}).get("総計"):
        tot = m["合計"]["総計"]
        rows = [_row(k, f"{tot[k]}件", _by_grade(m, k))
                for k in ("家庭問題", "健康問題", "学校問題", "交際問題", "経済・生活問題", "勤務問題")]
        rows.append(_row("その他", f"{tot['その他']}件", _by_grade(m, "その他")))
        rows.append(_row("不詳", f"{tot['不詳']}件", _by_grade(m, "不詳")))
        out.append(dict(common, title="小中高生の自殺の原因・動機（全国）",
                        asof=mv.get("era", era), rows=rows,
                        source=mv.get("source", ""), source_url=mv.get("source_url", ""),
                        caveat="原因・動機は一人につき複数計上されるため、区分の合計は自殺者数と一致しません。"
                               "計上されるのは遺書など状況から推定できたものに限られ、"
                               "「不詳」が学年を問わず多いことにも注意が必要です。"))

        sub = tot["学校問題の内訳"]
        rows = [_row(k, f"{sub[k]}件", _by_grade(m, "学校問題", k)) for k in sub]
        out.append(dict(common, title="「学校問題」の内訳（全国）",
                        asof=mv.get("era", era), rows=rows,
                        source=mv.get("source", ""), source_url=mv.get("source_url", ""),
                        caveat="国会でいちばん議論される区分なので内訳を出します。"
                               "いじめは学校問題の一部で、件数としては学業不振や進路・入試の悩みのほうが"
                               "多く計上されています。ただしこれは「いじめが問題でない」という意味ではなく、"
                               "警察が状況から推定できた範囲の計上です。上の表と同じく複数計上されます。"))
    return out


def sync_stats(rec: dict) -> int:
    """data/stats.json の `auto: "jisatsu"` 分を入れ替える。手書きの分はそのまま。"""
    d = json.load(open(STATS, encoding="utf-8")) if os.path.isfile(STATS) else {"stats": []}
    kept = [s for s in d.get("stats", []) if s.get("auto") != "jisatsu"]
    made = build_stats(rec)
    d["stats"] = kept + made
    json.dump(d, open(STATS, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    return len(made)


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
        ytxt = pdftext(get(y_url))
        d = parse_year(ytxt)
        d["source_url"] = y_url
        d["source"] = "警察庁・厚生労働省「自殺の状況」"
        rec["year"] = d
        mv = parse_motive(ytxt)
        if mv:
            rec["motive"] = {"data": mv, "source": d["source"], "source_url": y_url,
                             "era": d.get("era"),
                             "note": "原因・動機は一人につき複数計上されるため、"
                                     "合計は自殺者数と一致しない。"}
            print(f"  原因・動機: 検算OK（学校問題 合計 {mv['合計']['総計']['学校問題']}件）")
        else:
            print("  原因・動機: 検算に通らなかったので取り込みませんでした")
        pf = parse_pref(ytxt)
        if pf:
            rec["pref"] = dict(pf, source=d["source"], source_url=y_url, era=d.get("era"))
            print(f"  都道府県: {pf['name']} 合計 {pf['values']['合計']}人")
        else:
            print("  都道府県: 取れませんでした")
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
    n = sync_stats(rec)
    print(f"  画面へ: data/stats.json に {n} ブロック（相談窓口つき）")
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
