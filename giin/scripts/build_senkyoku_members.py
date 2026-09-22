#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""全国の衆議院小選挙区の議員名簿（最低限: 氏名・かな・会派・当選回数・公式プロフィール）を
衆議院の会派別名簿から取り、data/giin.sqlite の senkyoku_member に入れる。
発言は取らない（発言ログは愛知45人のまま）。選挙区ページの「この選挙区の議員」欄に使う。

  /usr/bin/python3 scripts/build_senkyoku_members.py
"""
import os, re, sqlite3, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from build_roster import fetch_shugiin  # 会派別名簿を読む既存の関数（愛知の絞り込みはしない）
from build_senkyoku import PREFS, PREF_SLUG, SHORT

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
SHORT2SLUG = {SHORT[p]: PREF_SLUG[p] for p in PREFS}

def main():
    rows = fetch_shugiin()
    db = sqlite3.connect(DB)
    db.executescript("""
    DROP TABLE IF EXISTS senkyoku_member;
    CREATE TABLE senkyoku_member (key TEXT, display TEXT, kana TEXT, kaiha TEXT, wins TEXT, profile TEXT);
    CREATE INDEX senkyoku_member_key ON senkyoku_member(key);
    """)
    n = 0; skipped = set()
    for r in rows:
        m = re.match(r'^(.+?)(\d+)$', r["district"].strip())
        if not m:  # 比例（「（比）東海」など）は選挙区ページに載せない
            continue
        slug = SHORT2SLUG.get(m.group(1))
        if not slug:
            skipped.add(r["district"]); continue
        db.execute("INSERT INTO senkyoku_member VALUES (?,?,?,?,?,?)",
                   (f"{slug}-{int(m.group(2))}", r["display"], r["kana"], r["kaiha"], r["wins"], r["profile"]))
        n += 1
    db.commit()
    keys = db.execute("SELECT count(DISTINCT key) FROM senkyoku_member").fetchone()[0]
    print(f"→ senkyoku_member {n}人 / {keys}選挙区（未対応の選挙区表記: {sorted(skipped)[:10]}）")

if __name__ == "__main__":
    main()
