#!/usr/bin/env python3
"""「この議員だけが国会で言っている言葉」を事前計算する。

**この道具にしか出せない数字である。** 本家の国会会議録検索システムは全文検索なので、
「誰だけが言っているか」は出せない。45人ぶんの全文を手元に持っているから比べられる。

**順位をつけない。** 発言の件数は与党か野党かでほとんど決まる、と自分で書いている以上、
多い少ないで並べることはできない。だが「この人だけが取り上げている」は立場と関係がない。
実際、自民も立憲も参政も無所属も、全員に固有の語が出る。

拾う条件（どれも「たまたま1回言った」を外すためのもの）:
- その語を使ったのが**その議員ただ1人**
- **4回以上**言っている
- **2日以上**にまたがっている（同じ日の一度の質疑で連呼しただけのものを外す）

    python3 scripts/build_uniq_terms.py
    python3 scripts/build_uniq_terms.py --show mizuno-koichi
"""
import argparse
import collections
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")

TERM = re.compile(r"[一-龥]{4,10}|[ァ-ヴー]{5,12}")
# 語として意味を持たないもの。**ここを緩めると画面が「愛知七区」「サンキュー」で埋まる。**
ROLE = re.compile(r"(委員|分科員|大臣|参考人|政府|答弁|質問|質疑|理事|議員|先生|局長|次官|長官|審議官|"
                  r"議長|会長|部長|課長|室長|所長|総理|副大臣|政務官)")
NUM = re.compile(r"[〇一二三四五六七八九十百千万億兆]")
# 数詞を含んでも残す法令・制度の語（「第五条」「三十六協定」など、それ自体が固有名）
NUM_OK = re.compile(r"(法|条|項|号|協定|条約|計画|大綱|白書)$")
KU = re.compile(r"[一二三四五六七八九十]+区$")
MIN_TIMES = 4
MIN_DAYS = 2


def db():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout=5000")
    con.execute("""CREATE TABLE IF NOT EXISTS uniq_term(
        giin_id INTEGER, term TEXT, n INTEGER, days INTEGER,
        PRIMARY KEY(giin_id, term))""")
    con.execute("CREATE INDEX IF NOT EXISTS ix_uniq ON uniq_term(giin_id, n DESC)")
    return con


def keep(t: str, names: set) -> bool:
    if t in names or ROLE.search(t):
        return False
    if KU.search(t):
        return False
    if NUM.search(t) and not NUM_OK.search(t):
        return False
    return True


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--show")
    a = ap.parse_args()
    con = db()

    gi = [dict(x) for x in con.execute("SELECT id, plain, party, slug FROM giin")]
    names = set()
    for g in gi:
        for suf in ("", "君", "委員", "分科員", "議員"):
            names.add(g["plain"] + suf)
    by_id = {g["id"]: g for g in gi}

    if a.show:
        gid = [g["id"] for g in gi if g["slug"] == a.show]
        if not gid:
            print("その議員はいません", file=sys.stderr)
            return 1
        for r in con.execute("SELECT term,n,days FROM uniq_term WHERE giin_id=?"
                             " ORDER BY n DESC LIMIT 20", (gid[0],)):
            print(f"  {r['term']:14} {r['n']:3}回 / {r['days']}日")
        return 0

    who = collections.defaultdict(collections.Counter)      # term -> giin_id -> 回数
    days = collections.defaultdict(lambda: collections.defaultdict(set))
    for r in con.execute("SELECT giin_id, date, body FROM speech"
                         " WHERE kind='q' AND body IS NOT NULL"):
        for t in set(TERM.findall(r["body"])):
            if not keep(t, names):
                continue
            who[t][r["giin_id"]] += 1
            days[t][r["giin_id"]].add(r["date"][:10])

    con.execute("DELETE FROM uniq_term")
    kept = 0
    for t, cnt in who.items():
        if len(cnt) != 1:
            continue
        gid, n = next(iter(cnt.items()))
        d = len(days[t][gid])
        if n < MIN_TIMES or d < MIN_DAYS:
            continue
        con.execute("INSERT OR REPLACE INTO uniq_term VALUES(?,?,?,?)", (gid, t, n, d))
        kept += 1
    con.commit()

    have = con.execute("SELECT COUNT(DISTINCT giin_id) FROM uniq_term").fetchone()[0]
    print(f"語 {len(who):,} を調べ、{kept} 語を採用（{have}人）")
    print("\n議員ごと（上位5語）:")
    for g in gi:
        rows = con.execute("SELECT term,n,days FROM uniq_term WHERE giin_id=?"
                           " ORDER BY n DESC LIMIT 5", (g["id"],)).fetchall()
        if rows:
            print(f"  {g['plain']:8}（{g['party']:6}）"
                  + "／".join(f"{r['term']}{r['n']}回" for r in rows))
    return 0


if __name__ == "__main__":
    sys.exit(main())
