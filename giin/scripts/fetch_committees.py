#!/usr/bin/env python3
"""衆参の委員会の委員名簿を取り込む（法案ページの「この法案に声を届けるには」で使う）。

  /usr/bin/python3 scripts/fetch_committees.py

- 衆議院: 委員名簿一覧（iinkai/list.htm）から各委員会（iin_j0010.htm など）を読む。Shift_JIS。
- 参議院: 委員会の一覧ページが無いので、名簿ページの番号（list/l0011〜l0120）を順に確かめる。
  会期の始めは特別委員会の名簿がまだ無いことがある（2026-10-06 時点で常任委員会17だけ）。
- 名簿は「いまの」委員なので、画面ではいまの国会で審査中の法案にだけ出す（過去の法案の審査時の委員とは違う）。
- 愛知の議員（表 giin）と氏名が一致した人には giin_id を付ける。
"""
from __future__ import annotations

import os
import re
import sqlite3
import time
import urllib.request
from datetime import datetime

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
UA = {"User-Agent": "curl/8.5.0"}
SHU_LIST = "https://www.shugiin.go.jp/internet/itdb_iinkai.nsf/html/iinkai/list.htm"
SHU_PAGE = "https://www.shugiin.go.jp/internet/itdb_iinkai.nsf/html/iinkai/{}"
SAN_PAGE = "https://www.sangiin.go.jp/japanese/joho1/kousei/konkokkai/current/list/l{:04d}.htm"

SCHEMA = """
CREATE TABLE IF NOT EXISTS committee_member(
  house TEXT, committee TEXT, ord INTEGER, role TEXT, name TEXT, kaiha TEXT, giin_id INTEGER, url TEXT,
  PRIMARY KEY(house, committee, ord));
CREATE INDEX IF NOT EXISTS ix_cm_comm ON committee_member(house, committee);
"""


def get(url: str, enc: str = "utf-8") -> str:
    with urllib.request.urlopen(urllib.request.Request(url, headers=UA), timeout=60) as r:
        return r.read().decode(enc, "ignore")


def plain(s: str) -> str:
    return re.sub(r"[\s　]|君$", "", re.sub(r"<[^>]+>", "", s or "")).replace("君", "")


def rows(html: str) -> list[list[str]]:
    out = []
    for tr in re.findall(r"<tr[^>]*>(.*?)</tr>", html, re.S | re.I):
        cells = [re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", c).replace("&nbsp;", " ")).strip()
                 for c in re.findall(r"<t[dh][^>]*>(.*?)</t[dh]>", tr, re.S | re.I)]
        if cells:
            out.append(cells)
    return out


def shugiin() -> dict[str, tuple[str, list]]:
    lst = get(SHU_LIST, "cp932")
    out = {}
    for href, name in re.findall(r'href="[^"]*?(iin_[a-z]\d+\.htm)"[^>]*title="([^"]+)"', lst, re.I):
        if "審査会" in name:
            continue
        h = get(SHU_PAGE.format(href), "cp932")
        mem = []
        for c in rows(h):
            if len(c) >= 4 and c[0] in ("委員長", "理事", "委員") and c[1]:
                mem.append((c[0], c[1].replace("君", "").strip(), c[3]))
        if mem:
            out[name.strip()] = (SHU_PAGE.format(href), mem)
        time.sleep(0.5)
    return out


def sangiin() -> dict[str, tuple[str, list]]:
    out = {}
    for i in range(11, 121):
        u = SAN_PAGE.format(i)
        try:
            h = get(u)
        except Exception:
            time.sleep(0.2)
            continue
        m = re.search(r"<title>\s*(.+?)委員名簿", h, re.S)
        if not m:
            continue
        mem = []
        for c in rows(h):
            if len(c) >= 3 and c[1] and re.search(r"[（(].+[)）]", c[2] or "") and c[1] != "氏名":
                role = c[0] if c[0] in ("委員長", "理事", "会長") else "委員"
                mem.append((role, c[1].replace("＜正字＞", "").strip(), c[2].strip("（）()")))
        if mem:
            out[m.group(1).strip()] = (u, mem)
        time.sleep(0.4)
    return out


def main():
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=10000")
    con.executescript(SCHEMA)
    aichi = {plain(r[1]): r[0] for r in con.execute("SELECT id, name FROM giin")}
    total = 0
    for house, fn in (("衆議院", shugiin), ("参議院", sangiin)):
        try:
            got = fn()
        except Exception as e:
            print(house, "失敗", repr(e)[:120])
            continue
        if not got:
            print(house, "名簿が取れなかった（前回の分を残す）")
            continue
        con.execute("DELETE FROM committee_member WHERE house=?", (house,))
        for comm, (url, mem) in got.items():
            for i, (role, name, kaiha) in enumerate(mem):
                con.execute("INSERT OR REPLACE INTO committee_member VALUES(?,?,?,?,?,?,?,?)",
                            (house, comm, i, role, name, kaiha, aichi.get(plain(name)), url))
                total += 1
        print(house, len(got), "委員会")
    con.execute("INSERT OR REPLACE INTO meta VALUES('committee_at', ?)", (datetime.now().isoformat(timespec="seconds"),))
    con.commit()
    print("委員", total, "人分")


if __name__ == "__main__":
    main()
