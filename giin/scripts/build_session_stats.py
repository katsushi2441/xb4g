#!/usr/bin/env python3
"""国会（回次）ごと・議員ごとの集計。/giin/<議員>/<回次> と /giin/kokkai/<回次> の材料。

  /usr/bin/python3 scripts/build_session_stats.py            → data/giin.sqlite の sess_* 表を作り直す
  /usr/bin/python3 scripts/build_session_stats.py --only 222 → その回だけ

数えるもの（どれも数えただけで、中身の良し悪しは見ていない）:
  - 質疑: speech_all の kind='q'（議員として質問・討論した発言）の件数・日数・会議の数
  - 答弁: kind='gov'（大臣・副大臣・政務官としての答弁）。大臣は質問できないので別に出す
  - 法案: その回に審議されていた法案（bill）の件名（新しく作る法律は法律名も）を、その議員の質疑の本文が含んでいれば「取り上げた」。
         「本法律案」のように件名を言わない発言は拾えないので、少なめに出る
         （fetch_bills.py が会議録 API の any=件名 で拾うのと同じ考え方を、手元の本文で全件に当てる）
  - 質問主意書: 質問主意書アシスト（kshuisho）の DB で、その回に出したもの。提出者名は空白を抜いて照合
  - 順位: 同じ院の議員の中での順位（同点は同じ順位）

会期（召集日・終了日・常会/臨時会/特別会）は衆議院の「国会会期一覧」から取る。
集計できるのは speech_all のある 2023 年（第211回）から。
"""
import collections
import datetime
import html
import json
import os
import re
import sqlite3
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
KS = "/home/kojima/work/kshuisho/php/kshuisho_data/kshuisho.sqlite"
KAIKI = "https://www.shugiin.go.jp/internet/itdb_annai.nsf/html/statics/shiryo/kaiki.htm"
FIRST = 211


def wareki(s):
    m = re.match(r"令和(\d+|元)年(\d+)月(\d+)日", s)
    if not m:
        return None
    y = 1 if m.group(1) == "元" else int(m.group(1))
    return datetime.date(2018 + y, int(m.group(2)), int(m.group(3))).isoformat()


def fetch_meta():
    b = urllib.request.urlopen(urllib.request.Request(KAIKI, headers={"User-Agent": "Mozilla/5.0"}), timeout=60).read()
    t = b.decode("shift_jis", "replace") if b"charset=Shift_JIS" in b[:2000] else b.decode("utf-8", "replace")
    t = re.sub(r"[\s|]+", "|", html.unescape(re.sub(r"<[^>]+>", "|", t)))
    out = {}
    for m in re.finditer(r"第(\d+)回（(常会|臨時会|特別会)）\|(令和[^|]+?日)\|(（?)(令和[^|]+?日)?", t):
        n = int(m.group(1))
        if n < FIRST:
            continue
        rest = t[m.end():m.end() + 40]
        close = wareki(m.group(5)) if m.group(5) else None
        out[n] = {"kind": m.group(2), "open": wareki(m.group(3)), "close": close,
                  "dissolved": bool(m.group(4)) or "解散" in rest[:12]}
    return out


def main():
    only = int(sys.argv[sys.argv.index("--only") + 1]) if "--only" in sys.argv else None
    con = sqlite3.connect(DB)
    con.executescript("""
    CREATE TABLE IF NOT EXISTS sess_meta(session INTEGER PRIMARY KEY, kind TEXT, open TEXT, close TEXT, dissolved INTEGER,
        n_law INTEGER, n_law_passed INTEGER, n_cab INTEGER, n_cab_passed INTEGER, n_members_q INTEGER, n_q INTEGER, built TEXT);
    CREATE TABLE IF NOT EXISTS sess_member(session INTEGER, giin_id INTEGER, house TEXT, n_q INTEGER, n_gov INTEGER, n_chair INTEGER,
        q_days INTEGER, q_meetings INTEGER, top_meetings TEXT, n_bills INTEGER, n_shuisho INTEGER, shuisho_evasive INTEGER,
        rank_q INTEGER, rank_bills INTEGER, rank_shuisho INTEGER, n_house INTEGER, PRIMARY KEY(session, giin_id));
    CREATE TABLE IF NOT EXISTS sess_bill_member(session INTEGER, bill_id TEXT, giin_id INTEGER, n INTEGER, first_date TEXT,
        PRIMARY KEY(session, bill_id, giin_id));
    CREATE INDEX IF NOT EXISTS ix_sbm_bill ON sess_bill_member(session, bill_id, n DESC);
    CREATE INDEX IF NOT EXISTS ix_sbm_giin ON sess_bill_member(giin_id, session);
    """)
    meta = fetch_meta()
    members = {r[0]: {"house": r[1], "plain": r[2]} for r in con.execute("SELECT id, house, plain FROM giin_all")}
    by_name = {v["plain"].replace(" ", ""): k for k, v in members.items()}
    ks = sqlite3.connect(KS) if os.path.exists(KS) else None
    sessions = sorted(s for s in meta if only is None or s == only)
    for S in sessions:
        m = meta[S]
        # その回に審議されていた法案: 提出された回が S 以前で、最後にいた回が S 以降（継続審査で持ち越されたものも含む）
        bills = con.execute("SELECT id, title, kind, status, session, submit_session FROM bill WHERE kind LIKE '法律案%' "
                            "AND submit_session <= ? AND session >= ?", (S, S)).fetchall()
        sub = [b for b in bills if b[5] == S]
        n_law = len(sub)
        # 成立はその回に出したものの最終の状態（継続審査で次の回に成立したものも数えない＝その回で成立したものだけにしたいが、
        # bill は最後の状態しか持たないので、出した回が S で最後の回も S のものの「成立」を数える）
        n_law_passed = sum(1 for b in sub if b[3] == "成立" and b[4] == S)
        n_cab = sum(1 for b in sub if b[2] == "法律案（内閣提出）")
        n_cab_passed = sum(1 for b in sub if b[2] == "法律案（内閣提出）" and b[3] == "成立" and b[4] == S)
        # 新しく作る法律（「一部を改正」でないもの）は、法律名（「〜法」）でも当てる（「国家情報会議設置法」と呼ぶ発言が多い）。
        # 改正法の法律名（「刑事訴訟法」）は別の話にも出るので使わない
        ln = {r[0]: r[1] for r in con.execute("SELECT id, law_name FROM bill")}
        titles = [(b[0], b[1], [b[1]] + ([ln[b[0]]] if "一部を改正" not in b[1] and ln.get(b[0]) and len(ln[b[0]]) >= 5 else []))
                  for b in bills if len(b[1]) >= 6]
        stat = collections.defaultdict(lambda: {"q": 0, "gov": 0, "chair": 0, "days": set(), "meet": collections.Counter()})
        bm = collections.defaultdict(lambda: [0, None])
        for gid, kind, date, meeting, house, body in con.execute(
                "SELECT giin_id, kind, date, meeting, house, body FROM speech_all WHERE session=? AND giin_id IS NOT NULL", (S,)):
            st = stat[gid]
            if kind in ("q", "gov", "chair"):
                st[kind] += 1
            if kind != "q":
                continue
            st["days"].add(date)
            st["meet"][f"{house} {meeting}"] += 1
            for bid, t, keys in titles:
                if any(k in body for k in keys):
                    x = bm[(bid, gid)]
                    x[0] += 1
                    x[1] = min(x[1] or date, date)
        sh = collections.Counter()
        she = collections.Counter()
        if ks:
            for submitter, ev in ks.execute("SELECT submitter, evasive_json FROM q WHERE session=?", (S,)):
                gid = by_name.get((submitter or "").replace(" ", "").replace("　", ""))
                if gid:
                    sh[gid] += 1
                    if ev and ev != "{}":
                        she[gid] += 1
        # 同じ件名の法案が2本ある（内閣提出と議員提出の「刑事訴訟法の一部を改正する法律案」など）ときは1本と数える
        tt = {b: t for b, t, _ in titles}
        nb = collections.Counter(g for g, _ in {(g, tt[b]) for (b, g) in bm})
        rows = []
        for gid in set(stat) | set(sh):
            if gid not in members:
                continue
            st = stat[gid]
            rows.append({"gid": gid, "house": members[gid]["house"], "q": st["q"], "gov": st["gov"], "chair": st["chair"],
                         "days": len(st["days"]), "meet": len(st["meet"]), "top": [k for k, _ in st["meet"].most_common(3)],
                         "bills": nb[gid], "sh": sh[gid], "she": she[gid]})
        def rank(key):
            out = {}
            for house in ("衆議院", "参議院"):
                xs = sorted((r for r in rows if r["house"] == house), key=lambda r: -r[key])
                prev, rk = None, 0
                for i, r in enumerate(xs, 1):
                    if r[key] != prev:
                        rk, prev = i, r[key]
                    out[r["gid"]] = rk if r[key] > 0 else None
            return out
        rq, rb, rs = rank("q"), rank("bills"), rank("sh")
        nh = collections.Counter(v["house"] for v in members.values())
        con.execute("DELETE FROM sess_member WHERE session=?", (S,))
        con.execute("DELETE FROM sess_bill_member WHERE session=?", (S,))
        con.executemany("INSERT INTO sess_member VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [(S, r["gid"], r["house"], r["q"], r["gov"], r["chair"], r["days"], r["meet"], json.dumps(r["top"], ensure_ascii=False),
                          r["bills"], r["sh"], r["she"], rq.get(r["gid"]), rb.get(r["gid"]), rs.get(r["gid"]), nh[r["house"]]) for r in rows])
        con.executemany("INSERT INTO sess_bill_member VALUES(?,?,?,?,?)", [(S, b, g, n, d) for (b, g), (n, d) in bm.items()])
        con.execute("INSERT OR REPLACE INTO sess_meta VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                    (S, m["kind"], m["open"], m["close"], int(m["dissolved"]), n_law, n_law_passed, n_cab, n_cab_passed,
                     sum(1 for r in rows if r["q"] > 0), sum(r["q"] for r in rows), datetime.datetime.now().isoformat(timespec="seconds")))
        con.commit()
        print(f"第{S}回（{m['kind']}）{m['open']}〜{m['close'] or ''} 法律案{n_law}（成立{n_law_passed}・内閣{n_cab}/成立{n_cab_passed}）"
              f" 議員{len(rows)} 質疑{sum(r['q'] for r in rows)} 法案×議員{len(bm)} 主意書{sum(sh.values())}")


if __name__ == "__main__":
    main()
