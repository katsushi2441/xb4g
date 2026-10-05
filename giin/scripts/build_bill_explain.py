#!/usr/bin/env python3
"""法律案の「この法案で変わること」を3行で作る（手元の生成AI・gemma4）。

  /usr/bin/python3 scripts/build_bill_explain.py              # まだ作っていない法案（新しい会期から）
  /usr/bin/python3 scripts/build_bill_explain.py --limit 5    # 試し
  /usr/bin/python3 scripts/build_bill_explain.py --id 221-kaku-39 --force

- 元の文は**参議院の議案要旨**（無ければ法律案の本文の末尾の「理由」）だけ。生成AIに元の文以外のことは書かせない。
- 生成AIは当社のサーバーの gemma4（192.168.0.3・think:false）。外部のAPIには送らない。
- **検査に1つでも落ちたら載せない**（理由を bill.explain_reject に残す）:
  数字（漢数字も直して比べる）が元の文にすべてある／評価・賛否・政党名を書いていない／2〜3行・1行70字以内。
- 画面では「AIによる要約」と明記し、元の文書（PDF）へのリンクを必ず付ける（lib/bills.php）。
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sqlite3
import subprocess
import tempfile
import time
import urllib.request
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
OLLAMA = os.environ.get("OLLAMA_URL", "http://192.168.0.3:11434")
MODEL = os.environ.get("BILL_EXPLAIN_MODEL", "gemma4:12b-it-qat")
CURL_UA = {"User-Agent": "curl/8.5.0"}
COLS = {"explain": "TEXT", "explain_source": "TEXT", "explain_model": "TEXT", "explain_at": "TEXT", "explain_reject": "TEXT"}

PROMPT = """次は国会に出された法律案「{title}」の{kind}です。この法律案で何が変わるかを、中学生にも分かる言葉で3行にまとめてください。

決まりごと:
- 1行は40〜70字。3行とも「〜する。」「〜になる。」のように事実だけを書く
- 元の文に書いていないことは書かない。数字・日付・期間は元の文のとおりに書き、元の文に無い数字は書かない
- 良い・悪い・必要・問題などの評価、賛成・反対、政党名は書かない
- 施行の時期だけの行は作らない（中身を優先する）
- 「この法律案を提出する」「〜について定める」のような、中身の無い行は書かない
- 1つのことを2行に分けない。元の文が短ければ2行でよい

元の文:
{text}

次のJSONだけを返してください: {{"lines": ["1行目", "2行目", "3行目"]}}"""

KANJI = {"〇": 0, "零": 0, "一": 1, "二": 2, "三": 3, "四": 4, "五": 5, "六": 6, "七": 7, "八": 8, "九": 9}
UNIT = {"十": 10, "百": 100, "千": 1000}
BIG = {"万": 10_000, "億": 100_000_000, "兆": 1_000_000_000_000}
BANNED = re.compile(r"良い|悪い|すばらしい|画期的|問題がある|問題だ|懸念|批判|賛成|反対|評価|べき|自民|立憲|公明|維新|国民民主|共産|れいわ|参政|社民|与党|野党")


def kanji_num(s: str) -> int:
    total, section, digit = 0, 0, 0
    for ch in s:
        if ch in KANJI:
            digit = KANJI[ch]
        elif ch in UNIT:
            section += (digit or 1) * UNIT[ch]
            digit = 0
        elif ch in BIG:
            total += (section + digit) * BIG[ch]
            section = digit = 0
    return total + section + digit


def numbers(text: str) -> set[int]:
    """文中の数（算用数字と漢数字）を集める。「第三九号」のような並べ書きの漢数字も一つの数として読む。"""
    out: set[int] = set()
    t = text.replace(",", "").replace("，", "")
    t = t.translate(str.maketrans("０１２３４５６７８９", "0123456789"))
    for m in re.finditer(r"\d+(?:\.\d+)?", t):
        out.add(int(float(m.group(0))))
    for m in re.finditer(r"[〇零一二三四五六七八九十百千万億兆]+", t):
        g = m.group(0)
        if not any(c in UNIT or c in BIG for c in g) and len(g) > 1:
            out.add(int("".join(str(KANJI.get(c, 0)) for c in g)))      # 三九 → 39
        out.add(kanji_num(g))
    return out


def clean_pdf_text(raw: str) -> str:
    t = re.sub(r"(?<=[^\x00-\x7F])[ 　]+(?=[^\x00-\x7F])", "", raw)   # 1字ごとの空白を取る
    t = re.sub(r"\n\s*[一二三四五六七八九十]+\s*\n", "\n", t)              # ページ番号
    t = re.sub(r"\n\s*\n", "\n", t)
    return t.strip()


def pdf_text(url: str) -> str:
    req = urllib.request.Request(url, headers=CURL_UA)
    data = urllib.request.urlopen(req, timeout=90).read()
    with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
        f.write(data)
        f.flush()
        raw = subprocess.run(["pdftotext", f.name, "-"], capture_output=True, text=True, timeout=120).stdout
    return clean_pdf_text(raw)


def source_of(b: dict) -> tuple[str, str, str]:
    """(元の文, 種類, URL)。議案要旨があればそれ、無ければ本文の末尾の「理由」。"""
    if b["summary_url"]:
        t = pdf_text(b["summary_url"])
        t = re.sub(r"^.*?要旨", "", t, count=1, flags=re.S).strip() or t
        return t[:4000], "要旨", b["summary_url"]
    if b["text_url"]:
        t = pdf_text(b["text_url"])
        m = re.search(r"\n\s*理\s*由\s*\n(.+)$", t, re.S)
        if m:
            return m.group(1).strip()[:2000], "理由", b["text_url"]
    return "", "", ""


def ask(title: str, kind: str, text: str) -> list[str]:
    body = {"model": MODEL, "prompt": PROMPT.format(title=title, kind="議案要旨" if kind == "要旨" else "提出の理由", text=text),
            "stream": False, "think": False, "format": "json", "options": {"temperature": 0.2, "num_predict": 600}}
    req = urllib.request.Request(OLLAMA + "/api/generate", data=json.dumps(body).encode(), headers={"Content-Type": "application/json"})
    r = json.loads(urllib.request.urlopen(req, timeout=300).read())
    lines = json.loads(r.get("response") or "{}").get("lines") or []
    return [re.sub(r"\s+", "", str(x)).strip("・- ") for x in lines if str(x).strip()]


def check(lines: list[str], source: str) -> str:
    """問題が無ければ空文字。あれば理由。"""
    if not 2 <= len(lines) <= 3:
        return f"行数が{len(lines)}"
    for x in lines:
        if len(x) > 70:
            return "1行が70字を超えた"
        m = BANNED.search(x)
        if m and m.group(0) not in source:     # 「炭素排出量評価」のように元の文にある言葉は止めない
            return "評価・賛否・政党名を含む: " + m.group(0)
        if re.search(r"法律案(を|は).{0,6}提出|提出される|提出する", x):
            return "中身の無い行（提出の言い回し）"
    extra = numbers("".join(lines)) - numbers(source)
    if extra:
        return "元の文に無い数字: " + ",".join(str(n) for n in sorted(extra)[:5])
    return ""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--id")
    ap.add_argument("--force", action="store_true")
    a = ap.parse_args()
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=10000")
    con.row_factory = sqlite3.Row
    have = {r[1] for r in con.execute("PRAGMA table_info(bill)")}
    for c, t in COLS.items():
        if c not in have:
            con.execute(f"ALTER TABLE bill ADD COLUMN {c} {t}")
    q = "SELECT * FROM bill WHERE kind_code IN ('kaku','shu','san')"
    args: list = []
    if a.id:
        q += " AND id=?"
        args.append(a.id)
    if not a.force:
        q += " AND explain_at IS NULL"
    q += " ORDER BY session DESC, number"
    rows = [dict(r) for r in con.execute(q, args)]
    if a.limit:
        rows = rows[:a.limit]
    ok = ng = 0
    for i, b in enumerate(rows, 1):
        try:
            src, kind, url = source_of(b)
            if not src:
                con.execute("UPDATE bill SET explain=NULL, explain_at=?, explain_reject=? WHERE id=?", (datetime.now().isoformat(timespec="seconds"), "元の文が取れない", b["id"]))
                ng += 1
                continue
            lines = ask(b["title"], kind, src)
            why = check(lines, src)
            if why:   # 1回だけ作り直す（生成AIは同じ元の文でも書き方が揺れる）
                lines = ask(b["title"], kind, src)
                why = check(lines, src)
            con.execute("UPDATE bill SET explain=?, explain_source=?, explain_model=?, explain_at=?, explain_reject=? WHERE id=?",
                        (None if why else json.dumps(lines, ensure_ascii=False), kind, MODEL, datetime.now().isoformat(timespec="seconds"), why or None, b["id"]))
            ok += not why
            ng += bool(why)
            print(f"{i}/{len(rows)} {b['id']} {'OK' if not why else 'NG ' + why}")
        except Exception as e:
            print(f"{i}/{len(rows)} {b['id']} 失敗 {repr(e)[:120]}")
        con.commit()
        time.sleep(0.3)
    con.commit()
    print(f"載せる {ok}件・載せない {ng}件")


if __name__ == "__main__":
    main()
