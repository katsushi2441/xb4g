#!/usr/bin/env python3
"""発言とことがらの対応表を作る。

**なぜ要るか。** 画面で毎回 `body LIKE '%語%' OR ...` を走らせていたので、
トップが4.1秒、くらべるが2.9秒かかっていた（2026-09-15 実測）。
くらべるは 45人 × 20ことがら = 900回の全走査をしていた。

対応表を1本作れば、あとは索引の付いた JOIN と COUNT で済む。
ニュース側（news_theme）と同じ作りにそろえる。

語は data/themes.json にあるので、**ことがらを足したらこれを回し直す**。
日次ジョブに入れてあるので、放っておいても毎日作り直される。

  /usr/bin/python3 scripts/build_theme_index.py
"""
import json, os, sqlite3, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")

SCHEMA = """
CREATE TABLE IF NOT EXISTS speech_theme(
  speech_id TEXT, theme TEXT, PRIMARY KEY(speech_id, theme));
CREATE INDEX IF NOT EXISTS ix_st_theme ON speech_theme(theme);
CREATE TABLE IF NOT EXISTS theme_count(
  theme TEXT, giin_id INTEGER, kind TEXT, n INTEGER,
  PRIMARY KEY(theme, giin_id, kind));
CREATE INDEX IF NOT EXISTS ix_tc_theme ON theme_count(theme, kind, n DESC);
"""


def main():
    t0 = time.time()
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    con.executescript(SCHEMA)
    themes = json.load(open(os.path.join(ROOT, "data", "themes.json"), encoding="utf-8"))

    con.execute("DELETE FROM speech_theme")
    for t in themes:
        ors = " OR ".join(["body LIKE ?"] * len(t["words"]))
        args = ["%" + w + "%" for w in t["words"]]
        con.execute(f"INSERT OR IGNORE INTO speech_theme(speech_id, theme)"
                    f" SELECT speech_id, ? FROM speech WHERE {ors}", [t["slug"]] + args)
    con.commit()

    # 議員×ことがら×立場 の件数を先に数えておく（くらべる表と上位表示がこれを読む）
    con.execute("DELETE FROM theme_count")
    con.execute("""INSERT INTO theme_count(theme, giin_id, kind, n)
                   SELECT st.theme, s.giin_id, s.kind, COUNT(*)
                   FROM speech_theme st JOIN speech s ON s.speech_id = st.speech_id
                   GROUP BY st.theme, s.giin_id, s.kind""")
    # giin_id=0 は「全員ぶんの合計」として持つ（トップとことがら一覧が読む）
    con.execute("""INSERT INTO theme_count(theme, giin_id, kind, n)
                   SELECT theme, 0, kind, SUM(n) FROM theme_count
                   WHERE giin_id > 0 GROUP BY theme, kind""")
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('theme_index_at',datetime('now','localtime'))")
    con.commit()

    a = con.execute("SELECT COUNT(*) FROM speech_theme").fetchone()[0]
    b = con.execute("SELECT COUNT(*) FROM theme_count").fetchone()[0]
    print(f"  speech_theme {a}行 / theme_count {b}行  ({time.time()-t0:.1f}秒)")
    print("  ことがら別（質疑）:")
    for r in con.execute("SELECT theme, n FROM theme_count WHERE giin_id=0 AND kind='q'"
                         " ORDER BY n DESC LIMIT 6"):
        print(f"    {r[0]:<18} {r[1]:>5}件")


if __name__ == "__main__":
    main()
