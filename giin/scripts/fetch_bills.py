#!/usr/bin/env python3
"""法案（議案）を取り込み、国会での発言・質問主意書・参議院の会派別の賛否とつなぐ。

  /usr/bin/python3 scripts/fetch_bills.py                 # 取り込み＋つなぎ（新しい会期を優先・古い会期は1回だけ）
  /usr/bin/python3 scripts/fetch_bills.py --only-csv      # 議案の一覧だけ更新（ネットワーク負荷を抑える）
  /usr/bin/python3 scripts/fetch_bills.py --limit 20      # 試し

**源は公開されていて商用で使えるものだけ。**
- 議案の一覧と経過: smartnews-smri/house-of-councillors の gian.csv（MIT・参議院の議案情報を整形したもの。
  衆議院の委員会・本会議の経過、公布日、議案要旨・法律案本文・投票結果のURLまで入っている）
- 国会での発言: 国立国会図書館 国会会議録検索システムAPI（件名をそのまま引く）
- 会派別の賛否: 参議院「本会議投票結果」ページ（押しボタン式で採決したものだけある）
- 質問主意書: 当社の Kurage 質問主意書アシスト（件名から法律名を作って引き、件数のある語だけ載せる）

**要約も論評もしない。** 件名・日付・結果・リンクと、発言から件名を含む前後を機械的に抜くだけ。
法案に賛成・反対の立場は書かない。
"""
from __future__ import annotations

import argparse
import csv
import io
import json
import os
import re
import sqlite3
import time
import urllib.parse
import urllib.request
from datetime import date, datetime, timedelta

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
UA = {"User-Agent": "xb4g-giin/1.0 (+https://xb4g.com/giin/)"}
GIAN_CSV = "https://raw.githubusercontent.com/smartnews-smri/house-of-councillors/main/data/gian.csv"
KOKKAI = "https://kokkai.ndl.go.jp/api/speech?"
KSHUISHO = "https://kurage.exbridge.jp/kshuisho.php/search?q="
FIRST_SESSION = 211          # 2023年の通常会から（発言の取り込み範囲と合わせる）
KINDS = {"法律案（内閣提出）": "kaku", "法律案（衆法）": "shu", "法律案（参法）": "san", "予算": "yosan", "条約": "joyaku"}
GOV = re.compile(r"大臣|副大臣|政務官|政府参考人|政府特別補佐人|長官|局長|審議官|会計検査院")
CHAIR = re.compile(r"委員長|議長|会長|理事$")

SCHEMA = """
CREATE TABLE IF NOT EXISTS bill(
  id TEXT PRIMARY KEY, session INTEGER, kind TEXT, kind_code TEXT, submit_session INTEGER, number INTEGER,
  title TEXT, law_name TEXT, submitter TEXT, submitted TEXT, sen_first TEXT,
  shu_committee TEXT, shu_committee_date TEXT, shu_committee_result TEXT,
  shu_plenary_date TEXT, shu_plenary_result TEXT, shu_plenary_mode TEXT,
  san_committee TEXT, san_committee_date TEXT, san_committee_result TEXT,
  san_plenary_date TEXT, san_plenary_result TEXT, san_plenary_mode TEXT, san_vote_url TEXT,
  promulgated TEXT, law_number TEXT, status TEXT, last_date TEXT, sessions TEXT,
  url TEXT, summary_url TEXT, text_url TEXT, law_url TEXT, note TEXT,
  speech_count INTEGER, speech_q INTEGER, speech_gov INTEGER, speech_fetched TEXT,
  shuisho_word TEXT, shuisho_count INTEGER, shuisho_fetched TEXT, vote_fetched TEXT);
CREATE INDEX IF NOT EXISTS ix_bill_session ON bill(session DESC, number);
CREATE INDEX IF NOT EXISTS ix_bill_last ON bill(last_date DESC);
CREATE TABLE IF NOT EXISTS bill_speech(
  bill_id TEXT, speech_id TEXT, date TEXT, house TEXT, meeting TEXT, speaker TEXT, position TEXT,
  role TEXT, url TEXT, excerpt TEXT, PRIMARY KEY(bill_id, speech_id));
CREATE INDEX IF NOT EXISTS ix_bs_bill ON bill_speech(bill_id, date DESC);
CREATE TABLE IF NOT EXISTS bill_vote(
  bill_id TEXT, party TEXT, members INTEGER, yes INTEGER, no INTEGER, ord INTEGER, PRIMARY KEY(bill_id, party));
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
"""


def db():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    con.executescript(SCHEMA)
    return con


def get(url: str, timeout: int = 60, ua: dict | None = None) -> bytes:
    req = urllib.request.Request(url, headers=ua or UA)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return r.read()


def law_name(title: str) -> str:
    """件名から法律名を作る（「〜の一部を改正する法律案」→「〜」、「〜法律案」→「〜法律」）。"""
    t = re.sub(r"案$", "", title.strip())
    t = re.sub(r"(及び[^。]*?)?の一部を改正する法律$", "", t)
    return t


def status_of(r: dict) -> tuple[str, str]:
    """状態と、いちばん新しい日付。r はその議案の**いちばん新しい会期**の行。結果は会議録の言葉のまま使う。"""
    dates = [r[k] for k in ("submitted", "shu_committee_date", "shu_plenary_date", "san_committee_date", "san_plenary_date", "promulgated") if r.get(k)]
    last = max(dates) if dates else ""
    plen = (r.get("shu_plenary_result") or "") + " " + (r.get("san_plenary_result") or "")
    comm = (r.get("shu_committee_result") or "") + " " + (r.get("san_committee_result") or "")
    if r.get("promulgated") or r.get("law_url"):
        return "成立", last
    if "否決" in plen:
        return "否決", last
    if "撤" in (r.get("note") or ""):
        return "撤回", last
    if r["kind_code"] in ("yosan", "joyaku") and re.search(r"可決|承認|修正", r.get("san_plenary_result") or ""):
        return ("成立" if r["kind_code"] == "yosan" else "承認"), last
    if "継続審査" in plen + comm:
        return "継続審査", last
    if "未了" in comm:
        return "審議未了", last
    if re.search(r"可決|修正", r.get("shu_plenary_result") or "") and not r.get("san_plenary_result"):
        return "衆議院で可決", last
    if re.search(r"可決|修正", r.get("san_plenary_result") or "") and not r.get("shu_plenary_result"):
        return "参議院で可決", last
    if not (r.get("shu_committee") or r.get("san_committee")):
        return "委員会に付託されず", last
    return "結果の記載なし", last


SANGIIN_LIST = "https://www.sangiin.go.jp/japanese/joho1/kousei/gian/{n}/gian.htm"
CURL_UA = {"User-Agent": "curl/8.5.0"}   # 参議院のサイトは素の curl の UA でないと 502 を返すことがある


def wareki(d: str) -> str:
    """令和8年10月5日 → 2026-10-05（平成も）。空なら空。"""
    m = re.search(r"(令和|平成)(元|\d+)年(\d+)月(\d+)日", d or "")
    if not m:
        return ""
    y = 1 if m.group(2) == "元" else int(m.group(2))
    y += 2018 if m.group(1) == "令和" else 1988
    return f"{y:04d}-{int(m.group(3)):02d}-{int(m.group(4)):02d}"


def scrape_session(n: int) -> list[dict]:
    """gian.csv にまだ入っていない会期（いまの国会など）を、参議院の議案情報から直接読む。
    gian.csv と同じ列名の辞書を返すので、あとは同じ処理に流せる。"""
    try:
        t = get(SANGIIN_LIST.format(n=n), timeout=60, ua=CURL_UA).decode("utf-8", "ignore")
    except Exception:
        return []
    if f"第{n}回国会" not in t:
        return []
    out = []
    for href in dict.fromkeys(re.findall(r'href="(\./meisai/m\d+\.htm)"', t)):
        u = urllib.parse.urljoin(SANGIIN_LIST.format(n=n), href)
        try:
            h = get(u, timeout=60, ua=CURL_UA).decode("utf-8", "ignore")
        except Exception as e:
            print("  議案ページ 失敗", u, repr(e)[:80])
            continue
        time.sleep(0.7)
        # 表ごと（summary 属性）に <th>見出し</th><td>値</td> を読む
        tables = {}
        for m in re.finditer(r'<table[^>]*summary="([^"]*)"[^>]*>(.*?)</table>', h, re.S):
            cells = {}
            for c in re.finditer(r"<th[^>]*>(.*?)</th>\s*<td[^>]*>(.*?)</td>", m.group(2), re.S):
                k = re.sub(r"<[^>]+>|\s", "", c.group(1))
                v = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", c.group(2)).replace("&nbsp;", " ")).strip()
                cells[k] = v
            tables.setdefault(m.group(1), {}).update(cells)   # 同じ summary の表が2つある（件名の表と提出日の表）
        def tbl(word):
            return next((v for k, v in tables.items() if word in k), {})
        head = tbl("議案審議情報")
        sc, sp, hc, hp = tbl("参議院委員会"), tbl("参議院本会議"), tbl("衆議院委員会"), tbl("衆議院本会議")
        other = tbl("その他")
        def val(block, label):
            return block.get(label, "")
        pdf = {k: urllib.parse.urljoin(u, v) for v, k in re.findall(r'href="([^"]+\.pdf)"[^>]*>\s*([^<]{0,20})', h)}
        vote = re.search(r'href="([^"]*(?:vote|touhyoulist)[^"]*\.htm)"', h)
        out.append({
            "審議回次": str(n), "種類": val(head, "種別"), "提出回次": re.sub(r"\D", "", val(head, "提出回次")),
            "提出番号": re.sub(r"\D", "", val(head, "提出番号")), "件名": val(head, "件名"), "議案URL": u,
            "議案要旨": next((v for k, v in pdf.items() if "要旨" in k), ""), "提出法律案": next((v for k, v in pdf.items() if "提出" in k or "こちら" in k), ""),
            "議案審議情報一覧 - 提出日": wareki(val(head, "提出日")),
            "議案審議情報一覧 - 衆議院から受領／提出日": wareki(val(head, "衆議院から受領／提出日")),
            "議案審議情報一覧 - 先議区分": val(head, "先議区分"), "議案審議情報一覧 - 継続区分": val(head, "継続区分"),
            "議案審議情報一覧 - 発議者": val(head, "発議者"), "議案審議情報一覧 - 提出者": val(head, "提出者"),
            "議案審議情報一覧 - 提出者区分": val(head, "提出者区分"),
            "参議院委員会等経過情報 - 付託委員会等": val(sc, "付託委員会等"), "参議院委員会等経過情報 - 議決日": wareki(val(sc, "議決日")),
            "参議院委員会等経過情報 - 議決・継続結果": val(sc, "議決"),
            "参議院本会議経過情報 - 議決日": wareki(val(sp, "議決日")), "参議院本会議経過情報 - 議決": val(sp, "議決"),
            "参議院本会議経過情報 - 採決態様": val(sp, "採決態様"), "参議院本会議経過情報 - 投票結果": urllib.parse.urljoin(u, vote.group(1)) if vote else "",
            "衆議院委員会等経過情報 - 付託委員会等": val(hc, "付託委員会等"), "衆議院委員会等経過情報 - 議決日": wareki(val(hc, "議決日")),
            "衆議院委員会等経過情報 - 議決・継続結果": val(hc, "議決"),
            "衆議院本会議経過情報 - 議決日": wareki(val(hp, "議決日")), "衆議院本会議経過情報 - 議決": val(hp, "議決"),
            "衆議院本会議経過情報 - 採決態様": val(hp, "採決態様"),
            "その他の情報 - 公布年月日": wareki(val(other, "公布年月日")), "その他の情報 - 法律番号": re.sub(r"\D", "", val(other, "法律番号")),
            "成立法律": "", "備考": "",
        })
    return out


def load_csv(con, only_from: int) -> int:
    raw = get(GIAN_CSV, timeout=120).decode("utf-8")
    rows = list(csv.DictReader(io.StringIO(raw)))
    # 継続審査の議案は会期ごとに1行ずつある。提出回次・種類・番号で1つの議案にまとめ、
    # いちばん新しい会期の行をいまの状態として使う（通った会期の一覧は sessions に残す）
    groups: dict[tuple, list] = {}
    for x in rows:
        try:
            ses = int(x["審議回次"])
        except ValueError:
            continue
        code = KINDS.get(x["種類"])
        if not code or not x["提出番号"].isdigit():
            continue
        groups.setdefault((code, x["提出回次"], int(x["提出番号"])), []).append((ses, x))
    # gian.csv より新しい会期（いまの国会）は、参議院の議案情報から直接読む
    csv_max = max(s for lst in groups.values() for s, _ in lst)
    current = 0
    for ses in range(csv_max + 1, csv_max + 4):
        extra = scrape_session(ses)
        if not extra:
            break
        current = ses
        print(f"  第{ses}回国会（参議院の議案情報から直接）: {len(extra)}件")
        for x in extra:
            code = KINDS.get(x["種類"])
            if code and x["提出番号"].isdigit():
                groups.setdefault((code, x["提出回次"], int(x["提出番号"])), []).append((ses, x))
    con.execute("INSERT OR REPLACE INTO meta VALUES('bill_current_session', ?)", (str(current or ""),))
    n = 0
    for (code, sub, num), lst in groups.items():
        lst.sort(key=lambda t: t[0])
        ses, x = lst[-1]
        if ses < only_from:
            continue
        bid = f"{sub}-{code}-{num}"
        r = {
            "id": bid, "session": ses, "kind": x["種類"], "kind_code": code,
            "submit_session": int(x["提出回次"] or 0), "number": int(x["提出番号"] or 0),
            "title": x["件名"].strip(), "law_name": law_name(x["件名"]) if code in ("kaku", "shu", "san") else "",
            "submitter": (x["議案審議情報一覧 - 発議者"] or x["議案審議情報一覧 - 提出者"] or x["議案審議情報一覧 - 提出者区分"]).strip(),
            "submitted": x["議案審議情報一覧 - 提出日"] or x["議案審議情報一覧 - 衆議院から受領／提出日"],
            "sen_first": x["議案審議情報一覧 - 先議区分"],
            "shu_committee": x["衆議院委員会等経過情報 - 付託委員会等"], "shu_committee_date": x["衆議院委員会等経過情報 - 議決日"],
            "shu_committee_result": x["衆議院委員会等経過情報 - 議決・継続結果"],
            "shu_plenary_date": x["衆議院本会議経過情報 - 議決日"], "shu_plenary_result": x["衆議院本会議経過情報 - 議決"],
            "shu_plenary_mode": x["衆議院本会議経過情報 - 採決態様"],
            "san_committee": x["参議院委員会等経過情報 - 付託委員会等"], "san_committee_date": x["参議院委員会等経過情報 - 議決日"],
            "san_committee_result": x["参議院委員会等経過情報 - 議決・継続結果"],
            "san_plenary_date": x["参議院本会議経過情報 - 議決日"], "san_plenary_result": x["参議院本会議経過情報 - 議決"],
            "san_plenary_mode": x["参議院本会議経過情報 - 採決態様"], "san_vote_url": x["参議院本会議経過情報 - 投票結果"],
            "promulgated": x["その他の情報 - 公布年月日"], "law_number": x["その他の情報 - 法律番号"],
            "url": x["議案URL"], "summary_url": x["議案要旨"], "text_url": x["提出法律案"], "law_url": x["成立法律"],
            "note": (x["備考"] + " " + x["議案審議情報一覧 - 継続区分"]).strip(),
            "sessions": ",".join(str(t[0]) for t in lst),
        }
        r["status"], r["last_date"] = status_of(r)
        if ses == current and r["status"] in ("継続審査", "審議未了", "委員会に付託されず", "結果の記載なし"):
            # いまの国会でまだ結論が出ていないもの（会期の途中なので「未了」ではない）
            r["status"] = "委員会で審査中" if (r["shu_committee"] or r["san_committee"]) else "審議中"

        cols = list(r)
        # 取り込み済みのつなぎ（発言・質問主意書・賛否）は残し、議案の経過だけ上書きする
        con.execute(f"INSERT INTO bill({','.join(cols)}) VALUES({','.join('?' * len(cols))}) "
                    f"ON CONFLICT(id) DO UPDATE SET {','.join(f'{c}=excluded.{c}' for c in cols if c != 'id')}",
                    [r[c] for c in cols])
        n += 1
    con.execute("INSERT OR REPLACE INTO meta VALUES('bill_csv_at', ?)", (datetime.now().isoformat(timespec="seconds"),))
    con.commit()
    return n


def excerpt(text: str, title: str, width: int = 90) -> str:
    t = re.sub(r"\s+", " ", text)
    i = t.find(title)
    if i < 0:
        return t[:width * 2]
    s = max(0, i - width)
    return ("…" if s else "") + t[s:i + len(title) + width] + "…"


def link_speeches(con, b: dict) -> None:
    # 同じ件名の法案は会期をまたいで何度も出る（例: 政治資金規正法の一部を改正する法律案）。
    # その議案が出されてから、最後の動きの30日後までの発言に限る
    start = b["submitted"] or ""
    end = b["last_date"] or ""
    params = {"any": b["title"], "recordPacking": "json", "maximumRecords": 100}
    if start:
        end_d = (date.fromisoformat(end) if end else date.fromisoformat(start) + timedelta(days=200)) + timedelta(days=30)
        params.update({"from": start, "until": min(end_d, date.today()).isoformat()})
    q = urllib.parse.urlencode(params)
    d = json.loads(get(KOKKAI + q, timeout=90))
    total = int(d.get("numberOfRecords") or 0)
    con.execute("DELETE FROM bill_speech WHERE bill_id=?", (b["id"],))
    nq = ng = 0
    kept = 0
    for s in d.get("speechRecord") or []:
        pos = (s.get("speakerPosition") or "").strip()
        if s.get("speaker") in ("会議録情報",) or CHAIR.search(pos):
            continue
        body = s.get("speech") or ""
        if "審査の経過" in body[:200]:
            role = "report"          # 委員長報告（本会議）。質疑にも答弁にも数えない
        else:
            role = "gov" if GOV.search(pos) else "q"
        nq += role == "q"
        ng += role == "gov"
        if kept >= 40:
            continue
        con.execute("INSERT OR REPLACE INTO bill_speech VALUES(?,?,?,?,?,?,?,?,?,?)",
                    (b["id"], s["speechID"], s["date"], s["nameOfHouse"], s["nameOfMeeting"], s["speaker"], pos, role,
                     s["speechURL"], excerpt(body, b["title"])))
        kept += 1
    con.execute("UPDATE bill SET speech_count=?, speech_q=?, speech_gov=?, speech_fetched=? WHERE id=?",
                (total, nq, ng, date.today().isoformat(), b["id"]))


def shuisho_candidates(name: str) -> list[str]:
    c = [name]
    core = re.sub(r"(等)?に関する(特別措置)?法律$|法$|法律$", "", name)
    if core and core != name:
        c.append(core)
    m = re.match(r"(.+?)(等の|の|に関する)", core or name)
    if m and len(m.group(1)) >= 3:
        c.append(m.group(1))
    out = []
    for w in c:
        if w and w not in out and len(w) >= 3:
            out.append(w)
    return out[:3]


def link_shuisho(con, b: dict) -> None:
    best = None
    for w in shuisho_candidates(b["law_name"]):
        h = get(KSHUISHO + urllib.parse.quote(w), timeout=30).decode("utf-8", "ignore")
        m = re.search(r"<title>[^<]*?([\d,]+)件", h)
        n = int(m.group(1).replace(",", "")) if m else 0
        time.sleep(0.5)
        if n:
            best = (w, n)
            break          # 具体的な語から順に試し、最初に件数のあった語を採る
    con.execute("UPDATE bill SET shuisho_word=?, shuisho_count=?, shuisho_fetched=? WHERE id=?",
                (best[0] if best else None, best[1] if best else 0, date.today().isoformat(), b["id"]))


# 2023年ごろの頁は <caption class="party">会派(N名)<br>賛成票 N 反対票 N</caption>、2026年の頁は <h4>会派( N名)</h4><dt>賛成票…</dt>。
# タグを | に置き換えてから、どちらの形でも拾う
VOTE_PARTY = re.compile(r"\|\s*([^|]+?)\(\s*(\d+)名\)[\s|]*賛成票\s*(\d+)\s*反対票\s*(\d+)")


def link_vote(con, b: dict) -> None:
    con.execute("DELETE FROM bill_vote WHERE bill_id=?", (b["id"],))
    if b["san_vote_url"]:
        # 参議院のサイトは素の curl の UA でないと 502 を返すことがある（kshuisho で実測）
        t = get(b["san_vote_url"], timeout=60, ua={"User-Agent": "curl/8.5.0"}).decode("utf-8", "ignore")
        s = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " | ", t.replace("\u3000", " ")))
        for i, m in enumerate(VOTE_PARTY.finditer(s)):
            con.execute("INSERT OR REPLACE INTO bill_vote VALUES(?,?,?,?,?,?)",
                        (b["id"], m.group(1).strip(" |"), int(m.group(2)), int(m.group(3)), int(m.group(4)), i))
    con.execute("UPDATE bill SET vote_fetched=? WHERE id=?", (date.today().isoformat(), b["id"]))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--only-csv", action="store_true")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--from", dest="frm", type=int, default=FIRST_SESSION)
    a = ap.parse_args()
    con = db()
    con.row_factory = sqlite3.Row
    print("議案:", load_csv(con, a.frm), "件")
    if a.only_csv:
        return
    latest = con.execute("SELECT MAX(session) FROM bill").fetchone()[0]
    stale = (date.today() - timedelta(days=7)).isoformat()
    # 発言: 古い会期は1回だけ、新しい2会期は7日ごとに取り直す（審議中は発言が増える）
    todo = [dict(r) for r in con.execute(
        "SELECT * FROM bill WHERE speech_fetched IS NULL OR (session>=? AND speech_fetched<?) ORDER BY session DESC, number",
        (latest - 1, stale))]
    if a.limit:
        todo = todo[:a.limit]
    for i, b in enumerate(todo, 1):
        try:
            link_speeches(con, b)
        except Exception as e:      # 1件の失敗で全体を止めない。次の回に取り直す
            print("  発言 失敗", b["id"], repr(e)[:120])
        if i % 20 == 0:
            con.commit()
            print(f"  発言 {i}/{len(todo)}")
        time.sleep(1.0)
    con.commit()
    law = [dict(r) for r in con.execute(
        "SELECT * FROM bill WHERE kind_code IN ('kaku','shu','san') AND (shuisho_fetched IS NULL OR (session>=? AND shuisho_fetched<?)) ORDER BY session DESC, number",
        (latest - 1, stale))]
    if a.limit:
        law = law[:a.limit]
    for i, b in enumerate(law, 1):
        try:
            link_shuisho(con, b)
        except Exception as e:
            print("  主意書 失敗", b["id"], repr(e)[:120])
        if i % 50 == 0:
            con.commit()
            print(f"  主意書 {i}/{len(law)}")
    con.commit()
    votes = [dict(r) for r in con.execute("SELECT * FROM bill WHERE vote_fetched IS NULL AND san_vote_url<>''")]
    if a.limit:
        votes = votes[:a.limit]
    for b in votes:
        try:
            link_vote(con, b)
        except Exception as e:
            print("  賛否 失敗", b["id"], repr(e)[:120])
        time.sleep(1.0)
    con.execute("INSERT OR REPLACE INTO meta VALUES('bill_linked_at', ?)", (datetime.now().isoformat(timespec="seconds"),))
    con.commit()
    print("発言", len(todo), "件・主意書", len(law), "件・賛否", len(votes), "件を処理")


if __name__ == "__main__":
    main()
