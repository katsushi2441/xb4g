#!/usr/bin/env python3
"""全国トラッカー: ひとつのことがらについて、**愛知の45人に限らず**国会の全発言を会議録から集める。

**なぜ要るか。** この道具は「愛知の有権者の一票が当落に効く議員」の発言だけを持っている。
議員立法で法整備が進んでいることがら（内密出産など）は、どこの議員が質問し、政府が何と
答えてきたかを全国で追わないと「法整備はどこまで来たか」が分からない。
そこで data/trackers.json に書いたことがらだけ、語で会議録 API を引き、
表 tracker_speech に別に持つ（speech 表には混ぜない。あちらは45人の発言の数え方が
崩れるため）。

  /usr/bin/python3 scripts/fetch_tracker.py            # 全部のトラッカー
  /usr/bin/python3 scripts/fetch_tracker.py --key naimitsu-shussan

- 語ごとに any=語 で引き、speechID で重ねる（同じ発言に複数の語があっても1行）。
- 立場（q/gov/chair）は classify.py と同じ規則で本文の冒頭から機械的に決める。
  参考人・公述人・証人は議員ではないので kind=ref に分け、「取り上げた議員」に数えない。
- 愛知の45人の発言は giin.id を持たせ、画面からその議員のページへ渡せるようにする。
- 要約はしない。本文はそのまま持ち、画面が語のまわりを抜粋する。
"""
import argparse, json, os, sqlite3, sys, time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "scripts"))
import ndl                      # noqa: E402
from classify import kind_of    # noqa: E402

DB = os.path.join(ROOT, "data", "giin.sqlite")
CONF = os.path.join(ROOT, "data", "trackers.json")

SCHEMA = """
CREATE TABLE IF NOT EXISTS tracker_speech(
  tracker TEXT, speech_id TEXT, giin_id INTEGER, date TEXT, session INTEGER,
  house TEXT, meeting TEXT, issue TEXT, speech_order INTEGER,
  speaker TEXT, kaiha_at TEXT, position TEXT, role TEXT,
  body TEXT, speech_url TEXT, meeting_url TEXT, kind TEXT, words TEXT,
  PRIMARY KEY(tracker, speech_id));
CREATE INDEX IF NOT EXISTS ix_ts_date ON tracker_speech(tracker, kind, date DESC);
"""


def fetch_word(word, frm):
    """語を含む発言を全部返す（100件ずつ）。"""
    start = 1
    while True:
        d = ndl.call("speech", any=word, maximumRecords=100, startRecord=start, **{"from": frm})
        recs = d.get("speechRecord") or []
        if not recs:
            return
        for r in recs:
            yield r
        nxt = d.get("nextRecordPosition")
        if not nxt:
            return
        start = nxt


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--key", default="")
    a = ap.parse_args()
    trackers = json.load(open(CONF, encoding="utf-8"))
    if a.key:
        trackers = [t for t in trackers if t["key"] == a.key]
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    con.executescript(SCHEMA)
    # 愛知の45人: 会議録の話者表記（姓名つづき）→ giin.id
    names = {}
    for gid, name, plain in con.execute("SELECT id, name, plain FROM giin"):
        for n in (name, plain):
            if n:
                names[n.replace("　", "").replace(" ", "")] = gid
    for t in trackers:
        t0 = time.time()
        seen = {}
        for w in t["words"]:
            n = 0
            for r in fetch_word(w, t["from"]):
                sid = r["speechID"]
                if sid in seen:
                    seen[sid]["words"].add(w)
                else:
                    r["words"] = {w}
                    seen[sid] = r
                n += 1
            print(f"  {t['key']}: 「{w}」 {n}件")
        # 語が本文に実在するものだけ（any= はカナ・表記ゆれを拾うことがある）
        rows = []
        for sid, r in seen.items():
            body = r.get("speech") or ""
            hit = [w for w in t["words"] if w in body]
            if not hit:
                continue
            sp = (r.get("speaker") or "").replace("　", "").replace(" ", "")
            # 参考人・公述人・証人は議員ではないので、質疑（q）と分けて持つ（kind=ref）
            kind = kind_of(body)
            head = body[:30]
            # 「政府参考人」は classify の規則で先に gov になる。q と判定された「参考人」だけを ref にする
            if kind == "q" and ((r.get("speakerRole") or "") in ("参考人", "公述人", "証人") or
                                any(w in head for w in ("参考人", "公述人", "証人"))):
                kind = "ref"
            rows.append((t["key"], sid, names.get(sp, 0), r.get("date"), r.get("session"),
                         r.get("nameOfHouse"), r.get("nameOfMeeting"), r.get("issue"),
                         r.get("speechOrder"), r.get("speaker"), r.get("speakerGroup"),
                         r.get("speakerPosition"), r.get("speakerRole"), body,
                         r.get("speechURL"), r.get("meetingURL"), kind, "、".join(hit)))
        con.execute("DELETE FROM tracker_speech WHERE tracker=?", (t["key"],))
        con.executemany("INSERT OR REPLACE INTO tracker_speech VALUES (" + ",".join("?" * 18) + ")", rows)
        con.execute("INSERT OR REPLACE INTO meta(k,v) VALUES(?, datetime('now','localtime'))",
                    ("tracker_" + t["key"] + "_at",))
        con.commit()
        kinds = dict(con.execute("SELECT kind, COUNT(*) FROM tracker_speech WHERE tracker=? GROUP BY kind",
                                 (t["key"],)).fetchall())
        aichi = con.execute("SELECT COUNT(*) FROM tracker_speech WHERE tracker=? AND giin_id>0", (t["key"],)).fetchone()[0]
        print(f"  {t['key']}: {len(rows)}件 {kinds} 愛知の議員 {aichi}件 ({time.time()-t0:.0f}秒)")


if __name__ == "__main__":
    main()
