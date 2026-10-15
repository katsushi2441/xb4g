#!/usr/bin/env python3
"""国会会議録から、議員マスタ45人ぶんの発言を取り込む。

**本文は保持するが、サイトに出すのは冒頭の抜粋と会議録へのリンクだけ。**
検索のために本文が要る（＝情報解析）一方で、全文をそのまま見せる形にはしない。
要約も論評もしない。ここを崩すと、誤帰属で議員の立場を毀損する経路ができる。

  /usr/bin/python3 scripts/fetch_speeches.py            # 2023-01-01 以降を取り込む
  /usr/bin/python3 scripts/fetch_speeches.py --from 2026-01-01
  /usr/bin/python3 scripts/fetch_speeches.py --since-last  # 増分（日次ジョブ用）
"""
import argparse, json, os, sqlite3, sys, time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import ndl
from classify import kind_of

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
ROSTER = os.path.join(ROOT, "data", "roster.json")

# **全国化（2026-10-15）で表の名前を変えた。** 実体は giin_all（全国711人）と speech_all（その発言）。
# これまでの名前 giin / speech は「くわしいページの議員（detail=1＝愛知）」だけを見せるビューにした。
# 一覧・ことがら・会派・AI特集・検索・考察など**集計する画面とスクリプトは、ビューを読むので愛知のまま**動く。
# 全国の議員の個別ページ（軽いページ）とトラッカーの名前のリンクだけが giin_all / speech_all を読む。
# 書き込む側（このファイル・classify.py）は必ず *_all に書く（ビューには書けない）。
SCHEMA = """
CREATE TABLE IF NOT EXISTS giin_all(
  id INTEGER PRIMARY KEY, house TEXT, display TEXT, name TEXT, kana TEXT,
  district TEXT, wins TEXT, kaiha TEXT, party TEXT, profile TEXT, photo TEXT,
  slug TEXT, plain TEXT, detail INTEGER DEFAULT 1, current INTEGER DEFAULT 1, fetched_from TEXT, x TEXT,
  n_speech INTEGER DEFAULT 0, n_q INTEGER DEFAULT 0, n_gov INTEGER DEFAULT 0,
  n_chair INTEGER DEFAULT 0, first_date TEXT, last_date TEXT);
CREATE TABLE IF NOT EXISTS speech_all(
  speech_id TEXT PRIMARY KEY, giin_id INTEGER, date TEXT, session INTEGER,
  house TEXT, meeting TEXT, issue TEXT, speech_order INTEGER,
  speaker TEXT, kaiha_at TEXT, position TEXT, role TEXT,
  body TEXT, speech_url TEXT, meeting_url TEXT, kind TEXT);
CREATE INDEX IF NOT EXISTS ix_sp_kind ON speech_all(giin_id, kind, date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_giin ON speech_all(giin_id, date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_date ON speech_all(date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_meet ON speech_all(meeting);
CREATE INDEX IF NOT EXISTS ix_giin_slug ON giin_all(slug);
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE VIEW IF NOT EXISTS giin AS SELECT * FROM giin_all WHERE detail=1;
CREATE VIEW IF NOT EXISTS speech AS SELECT * FROM speech_all WHERE giin_id IN (SELECT id FROM giin_all WHERE detail=1);
"""


def _migrate(con):
    """全国化の前のDB（giin / speech が表）を、*_all の表＋ビューに作り替える。1回だけ"""
    kinds = dict(con.execute("SELECT name, type FROM sqlite_master WHERE name IN ('giin','speech','giin_all','speech_all')"))
    if kinds.get("giin") != "table":
        return
    cols = {r[1] for r in con.execute("PRAGMA table_info(giin)")}
    for c, ddl in (("detail", "INTEGER DEFAULT 1"), ("current", "INTEGER DEFAULT 1"), ("fetched_from", "TEXT")):
        if c not in cols:
            con.execute(f"ALTER TABLE giin ADD COLUMN {c} {ddl}")
    con.execute("ALTER TABLE giin RENAME TO giin_all")
    con.execute("ALTER TABLE speech RENAME TO speech_all")
    con.commit()
    print("  表を giin_all / speech_all に移し、giin / speech を愛知だけのビューにした")



def db():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=120000")
    # **レンタルサーバーでは WAL を使わない。** -wal / -shm を作るので、
    # 書き込み権限が無いディレクトリだと読むだけで readonly エラーになる。
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    _migrate(con)
    con.executescript(SCHEMA)
    if "x" not in {r[1] for r in con.execute("PRAGMA table_info(giin_all)")}:
        con.execute("ALTER TABLE giin_all ADD COLUMN x TEXT")
    return con


def _put(con, s, gid):
    con.execute(
        "INSERT OR REPLACE INTO speech_all(speech_id,giin_id,date,session,house,meeting,"
        "issue,speech_order,speaker,kaiha_at,position,role,body,speech_url,meeting_url,kind)"
        " VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        (s.get("speechID"), gid, s.get("date"), s.get("session"),
         s.get("nameOfHouse"), s.get("nameOfMeeting"), s.get("issue"),
         s.get("speechOrder"), s.get("speaker"), s.get("speakerGroup"),
         s.get("speakerPosition"), s.get("speakerRole"),
         s.get("speech"), s.get("speechURL"), s.get("meetingURL"),
         kind_of(s.get("speech") or "")))


class Who:
    """会議録の発言者名 → 名簿の議員。**名前が完全に一致したときだけ**（「梅村」で梅村聡と梅村みずほを混ぜない）。
    同姓同名（2026-10 鬼木誠＝衆・自民と参・立憲）は、発言時の会派、次に院で分ける。分けられなければ入れない"""

    def __init__(self, roster):
        self.by = {}
        for r in roster:
            self.by.setdefault(r["name"], []).append(r)

    def __call__(self, s):
        c = self.by.get((s.get("speaker") or "").replace("\u3000", "").replace(" ", ""))
        if not c:
            return None
        if len(c) == 1:
            return c[0]
        grp = s.get("speakerGroup") or ""
        hit = [r for r in c if grp and r["party"] != "無所属" and r["party"][:2] in grp]
        if len(hit) == 1:
            return hit[0]
        hit = [r for r in c if r["house"] == s.get("nameOfHouse")]
        return hit[0] if len(hit) == 1 else None


def _counts(con, ids=None):
    w = "" if ids is None else f" WHERE id IN ({','.join(str(int(i)) for i in ids)})"
    con.execute(f"""UPDATE giin_all SET
        n_speech=(SELECT COUNT(*) FROM speech_all s WHERE s.giin_id=giin_all.id),
        first_date=(SELECT MIN(date) FROM speech_all s WHERE s.giin_id=giin_all.id),
        last_date=(SELECT MAX(date) FROM speech_all s WHERE s.giin_id=giin_all.id),
        n_q     = (SELECT COUNT(*) FROM speech_all s WHERE s.giin_id=giin_all.id AND s.kind='q'),
        n_gov   = (SELECT COUNT(*) FROM speech_all s WHERE s.giin_id=giin_all.id AND s.kind='gov'),
        n_chair = (SELECT COUNT(*) FROM speech_all s WHERE s.giin_id=giin_all.id AND s.kind='chair'){w}""")


def backfill(con, roster, frm, limit=None, only=None):
    """名簿の議員のうち、まだ取っていない人の frm 以降の発言を、議員ごとに取る（初回だけ。数時間かかる）"""
    who = Who(roster)
    done = {r[0] for r in con.execute("SELECT id FROM giin_all WHERE fetched_from IS NOT NULL")}
    todo = [r for r in roster if (only and r["id"] in only) or (not only and r["id"] not in done)]
    if limit:
        todo = todo[:limit]
    total = 0
    for i, r in enumerate(todo, 1):
        n = 0
        try:
            for s in ndl.speeches(r["name"], frm=frm):
                g = who(s)
                if g and g["id"] == r["id"]:
                    _put(con, s, r["id"])
                    n += 1
        except Exception as e:  # noqa: BLE001
            print(f"  ! {r['name']} 取得中に失敗: {e}", file=sys.stderr)
            con.commit()
            continue
        con.execute("UPDATE giin_all SET fetched_from=? WHERE id=?", (frm, r["id"]))
        _counts(con, [r["id"]])
        con.commit()
        total += n
        print(f"  [{i}/{len(todo)}] {r['district']:<8} {r['name']:<10} +{n:>4}件", flush=True)
    return total


def incremental(con, roster, days=45):
    """**会議ごとに**新しい発言を取る（毎日用）。議員ごとに聞くと711人×3秒で36分かかる。
    会議録は会議の1〜2週間あとに公開され、日付順に出てこないので、直近 days 日の会議を毎回見直す。
    meeting_list（本文なし・発言者つき）で名簿の議員の未収録の発言がある会議だけを選び、その会議の本文を取る"""
    import datetime
    who = Who(roster)
    have = {r[0] for r in con.execute("SELECT speech_id FROM speech_all WHERE date>=?",
            ((datetime.date.today() - datetime.timedelta(days=days + 1)).isoformat(),))}
    frm = (datetime.date.today() - datetime.timedelta(days=days)).isoformat()
    want = []
    start = 1
    while True:
        d = ndl.call("meeting_list", **{"from": frm, "maximumRecords": 100, "startRecord": start})
        for m in d.get("meetingRecord") or []:
            for sp in m.get("speechRecord") or []:
                if sp["speechID"] in have:
                    continue
                # 本文が無いので会派では分けられない。名前が名簿にあれば、その会議を読む
                if (sp.get("speaker") or "").replace("\u3000", "") in who.by:
                    want.append(m["issueID"])
                    break
        nxt = d.get("nextRecordPosition")
        if not nxt:
            break
        start = nxt
    want = list(dict.fromkeys(want))
    total, ids = 0, set()
    for iid in want:
        start = 1
        while True:
            d = ndl.call("speech", issueID=iid, maximumRecords=100, startRecord=start)
            for s in d.get("speechRecord") or []:
                g = who(s)
                if g and s["speechID"] not in have:
                    _put(con, s, g["id"])
                    ids.add(g["id"])
                    total += 1
            nxt = d.get("nextRecordPosition")
            if not nxt:
                break
            start = nxt
        con.commit()
    if ids:
        _counts(con, ids)
    print(f"  直近{days}日の会議 {len(want)}回を読み、新しい発言 {total}件（議員 {len(ids)}人）")
    return total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="frm", default="2023-01-01")
    ap.add_argument("--since-last", action="store_true", help="毎日の増分（会議ごと）＋まだ取っていない議員の初回取得を少しずつ")
    ap.add_argument("--backfill", action="store_true", help="まだ取っていない議員を議員ごとに取る")
    ap.add_argument("--limit", type=int, help="--backfill で取る人数の上限")
    ap.add_argument("--only", type=int, nargs="*", help="発言を取る議員の id（取り直すとき）")
    a = ap.parse_args()

    os.makedirs(os.path.dirname(DB), exist_ok=True)
    con = db()
    roster = json.load(open(ROSTER, encoding="utf-8"))
    for r in roster:
        con.execute(
            "INSERT INTO giin_all(id,house,display,name,kana,district,wins,kaiha,party,profile,photo,slug,plain,detail,current)"
            " VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            " ON CONFLICT(id) DO UPDATE SET house=excluded.house,display=excluded.display,"
            " name=excluded.name,kana=excluded.kana,district=excluded.district,wins=excluded.wins,"
            " kaiha=excluded.kaiha,party=excluded.party,profile=excluded.profile,photo=excluded.photo,"
            " slug=excluded.slug,plain=excluded.plain,detail=excluded.detail,current=excluded.current",
            (r["id"], r["house"], r["display"], r["name"], r.get("kana", ""), r["district"],
             r.get("wins", ""), r["kaiha"], r["party"], r.get("profile", ""), r.get("photo", ""),
             r.get("slug", ""), r["display"].replace("\u3000", ""),
             1 if r.get("detail", r["id"] <= 46) else 0, 1 if r.get("current", True) else 0))
    # 公式Xのアカウント（data/kokkai_x.json。**本人と確かめたもの＝verified だけ**。X監視の名簿と同じもの）
    xp = os.path.join(ROOT, "data", "kokkai_x.json")
    if os.path.exists(xp):
        for x in json.load(open(xp, encoding="utf-8")):
            if x.get("x") and x.get("verified") is True:
                con.execute("UPDATE giin_all SET x=? WHERE house=? AND name=?", (x["x"], x["house"], x["name"]))
    # 全国化の前から取っていた愛知の46人は、取得済みの印を付ける
    con.execute("UPDATE giin_all SET fetched_from=? WHERE fetched_from IS NULL AND n_speech>0 AND detail=1", (a.frm,))
    con.commit()

    total = 0
    if a.since_last:
        total += incremental(con, roster)
    if a.backfill or a.only:
        total += backfill(con, roster, a.frm, a.limit, a.only)
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('updated_at',datetime('now','localtime'))")
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('range_from',?)", (a.frm,))
    con.commit()
    print(f"\n新規/更新 {total}件  DB={DB}  {os.path.getsize(DB)/1e6:.1f}MB")


if __name__ == "__main__":
    main()
