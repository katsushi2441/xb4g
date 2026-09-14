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

SCHEMA = """
CREATE TABLE IF NOT EXISTS giin(
  id INTEGER PRIMARY KEY, house TEXT, display TEXT, name TEXT, kana TEXT,
  district TEXT, wins TEXT, kaiha TEXT, party TEXT, profile TEXT, photo TEXT,
  slug TEXT, plain TEXT,
  n_speech INTEGER DEFAULT 0, n_q INTEGER DEFAULT 0, n_gov INTEGER DEFAULT 0,
  n_chair INTEGER DEFAULT 0, first_date TEXT, last_date TEXT);
CREATE TABLE IF NOT EXISTS speech(
  speech_id TEXT PRIMARY KEY, giin_id INTEGER, date TEXT, session INTEGER,
  house TEXT, meeting TEXT, issue TEXT, speech_order INTEGER,
  speaker TEXT, kaiha_at TEXT, position TEXT, role TEXT,
  body TEXT, speech_url TEXT, meeting_url TEXT, kind TEXT);
CREATE INDEX IF NOT EXISTS ix_sp_kind ON speech(giin_id, kind, date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_giin ON speech(giin_id, date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_date ON speech(date DESC);
CREATE INDEX IF NOT EXISTS ix_sp_meet ON speech(meeting);
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
"""


def db():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    # **レンタルサーバーでは WAL を使わない。** -wal / -shm を作るので、
    # 書き込み権限が無いディレクトリだと読むだけで readonly エラーになる。
    try:
        con.execute("PRAGMA journal_mode=DELETE")
    except sqlite3.Error:
        pass
    con.executescript(SCHEMA)
    return con


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--from", dest="frm", default="2023-01-01")
    ap.add_argument("--since-last", action="store_true")
    a = ap.parse_args()

    os.makedirs(os.path.dirname(DB), exist_ok=True)
    con = db()
    roster = json.load(open(ROSTER, encoding="utf-8"))

    for r in roster:
        con.execute(
            "INSERT INTO giin(id,house,display,name,kana,district,wins,kaiha,party,profile,photo,slug,plain)"
            " VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)"
            " ON CONFLICT(id) DO UPDATE SET house=excluded.house,display=excluded.display,"
            " name=excluded.name,kana=excluded.kana,district=excluded.district,wins=excluded.wins,"
            " kaiha=excluded.kaiha,party=excluded.party,profile=excluded.profile,photo=excluded.photo,"
            " slug=excluded.slug,plain=excluded.plain",
            (r["id"], r["house"], r["display"], r["name"], r.get("kana", ""), r["district"],
             r.get("wins", ""), r["kaiha"], r["party"], r.get("profile", ""), r.get("photo", ""),
             r.get("slug", ""), r["display"].replace("\u3000", "")))
    con.commit()

    total = 0
    for r in roster:
        frm = a.frm
        if a.since_last:
            last = con.execute("SELECT last_date FROM giin WHERE id=?", (r["id"],)).fetchone()[0]
            if last:
                frm = last
        n = 0
        try:
            for s in ndl.speeches(r["name"], frm=frm):
                # 同姓同名の取り違えを避ける。会派が一致しない発言は入れない
                con.execute(
                    "INSERT OR REPLACE INTO speech(speech_id,giin_id,date,session,house,meeting,"
                    "issue,speech_order,speaker,kaiha_at,position,role,body,speech_url,meeting_url,kind)"
                    " VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    (s.get("speechID"), r["id"], s.get("date"), s.get("session"),
                     s.get("nameOfHouse"), s.get("nameOfMeeting"), s.get("issue"),
                     s.get("speechOrder"), s.get("speaker"), s.get("speakerGroup"),
                     s.get("speakerPosition"), s.get("speakerRole"),
                     s.get("speech"), s.get("speechURL"), s.get("meetingURL"),
                     kind_of(s.get("speech") or "")))
                n += 1
        except Exception as e:
            print(f"  ! {r['name']} 取得中に失敗: {e}", file=sys.stderr)
        con.commit()
        row = con.execute(
            "SELECT COUNT(*), MIN(date), MAX(date) FROM speech WHERE giin_id=?", (r["id"],)).fetchone()
        con.execute("UPDATE giin SET n_speech=?, first_date=?, last_date=? WHERE id=?",
                    (row[0], row[1], row[2], r["id"]))
        con.commit()
        total += n
        print(f"  {r['district']:<8} {r['name']:<10} +{n:>4}件 (計{row[0]:>5})", flush=True)

    con.execute("""UPDATE giin SET
        n_q     = (SELECT COUNT(*) FROM speech s WHERE s.giin_id=giin.id AND s.kind='q'),
        n_gov   = (SELECT COUNT(*) FROM speech s WHERE s.giin_id=giin.id AND s.kind='gov'),
        n_chair = (SELECT COUNT(*) FROM speech s WHERE s.giin_id=giin.id AND s.kind='chair')""")
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('updated_at',datetime('now','localtime'))")
    con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES('range_from',?)", (a.frm,))
    con.commit()
    print(f"\n新規/更新 {total}件  DB={DB}  {os.path.getsize(DB)/1e6:.1f}MB")


if __name__ == "__main__":
    main()
