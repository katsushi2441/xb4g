#!/usr/bin/env python3
"""発言を「どの立場で話したか」で分類して speech.kind に入れる。

**なぜ要るか。** 会議録の発言には、議員としての質疑のほかに
  ・委員長としての議事整理（「次に、○○君。」「本日はこれにて散会いたします」）
  ・大臣・副大臣・政務官としての答弁
が混ざっている。分けずに件数を出すと、委員長を務めた議員や大臣経験者の
件数が跳ね上がり、「よく質問している人」と読めてしまう。実測で
**全体の約3割が120文字未満**、その多くが議事整理だった（2026-09-14）。

kind:
  q     議員としての質疑・討論・質問（既定で見せるのはこれ）
  gov   大臣・副大臣・政務官・政府参考人としての答弁
  chair 委員長・議長・主査としての議事整理
  other 上のどれでもない

  /usr/bin/python3 scripts/classify.py
"""
import os, re, sqlite3, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")

# 先に当たったものを採る。**「委員長」は「委員」より先に見る。**
RULES = [
    ("chair", ["委員長代理", "委員長", "主査代理", "主査", "副議長", "議長", "分科会長"]),
    ("gov",   ["国務大臣", "大臣政務官", "副大臣", "政府参考人", "政府特別補佐人",
               "大臣", "長官", "本部長", "副本部長"]),
    ("q",     ["委員", "分科員", "議員", "君", "公述人", "参考人"]),
]


def kind_of(body: str) -> str:
    m = re.match(r"^[○◯●]\s*([^\s　]{1,40})", body or "")
    head = m.group(1) if m else (body or "")[:24]
    for kind, words in RULES:
        for w in words:
            if w in head:
                return kind
    return "other"


def main():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    # 全国化（2026-10-15）から実体は speech_all / giin_all（giin・speech は愛知だけのビュー。ビューには書けない）
    has_all = con.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='speech_all'").fetchone()[0]
    SP, GI = ("speech_all", "giin_all") if has_all else ("speech", "giin")
    cols = [r[1] for r in con.execute(f"PRAGMA table_info({SP})")]
    if "kind" not in cols:
        con.execute(f"ALTER TABLE {SP} ADD COLUMN kind TEXT")
        con.execute(f"CREATE INDEX IF NOT EXISTS ix_sp_kind ON {SP}(giin_id, kind, date DESC)")
    n = 0
    for sid, body, kind in con.execute(f"SELECT speech_id, body, kind FROM {SP}").fetchall():
        k = kind_of(body)
        if k != kind:
            con.execute(f"UPDATE {SP} SET kind=? WHERE speech_id=?", (k, sid))
        n += 1
    # 議員ごとの内訳を持たせる（画面で毎回数えないため）
    for c in ("n_q", "n_gov", "n_chair"):
        if c not in [r[1] for r in con.execute(f"PRAGMA table_info({GI})")]:
            con.execute(f"ALTER TABLE {GI} ADD COLUMN {c} INTEGER DEFAULT 0")
    con.execute(f"""UPDATE {GI} SET
        n_q     = (SELECT COUNT(*) FROM {SP} s WHERE s.giin_id={GI}.id AND s.kind='q'),
        n_gov   = (SELECT COUNT(*) FROM {SP} s WHERE s.giin_id={GI}.id AND s.kind='gov'),
        n_chair = (SELECT COUNT(*) FROM {SP} s WHERE s.giin_id={GI}.id AND s.kind='chair')""")
    con.commit()
    print(f"{n}件を分類しました")
    for k, c in con.execute(f"SELECT kind, COUNT(*) FROM {SP} GROUP BY kind ORDER BY 2 DESC"):
        print(f"  {k:<6} {c:>6}件")


if __name__ == "__main__":
    main()
