#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""選挙区ページの OGP 画像（1200×630）を289枚と、一覧用を1枚作る。絵柄は make_ogp_all.card() と同じ。

  GIIN_MASCOT=/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png \
  /usr/bin/python3 scripts/make_senkyoku_og.py
出力: img/og/senkyoku/<key>.png、img/og/senkyoku.png
"""
import os, sqlite3, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from make_ogp_all import card, ROOT, DB

OUT = os.path.join(ROOT, "img", "og", "senkyoku")


def main():
    os.makedirs(OUT, exist_ok=True)
    con = sqlite3.connect(DB); con.row_factory = sqlite3.Row
    st = {}
    for r in con.execute("SELECT key, metric, value FROM senkyoku_stat WHERE metric IN ('dosha','shelters','houmon','houmon_gone','houdei')"):
        st.setdefault(r['key'], {})[r['metric']] = int(r['value'])
    n = 0
    for d in con.execute("SELECT key, pref, name, area, voters FROM senkyoku ORDER BY rowid"):
        s = st.get(d['key'], {})
        bits = []
        if 'dosha' in s: bits.append(f"土砂災害警戒区域 {s['dosha']:,}")
        if 'shelters' in s: bits.append(f"避難場所 {s['shelters']:,}か所")
        line_a = "・".join(bits) if bits else "防災・福祉・子育ての公開データ"
        bits2 = []
        if 'houmon' in s: bits2.append(f"訪問介護 {s['houmon']:,}か所（1年半で消えた {s.get('houmon_gone', 0):,}）")
        if 'houdei' in s: bits2.append(f"放課後デイ {s['houdei']:,}")
        line_b = "・".join(bits2) if bits2 else ("有権者 " + f"{d['voters']:,}人" if d['voters'] else "")
        card(os.path.join(OUT, f"{d['key']}.png"),
             f"衆議院小選挙区　{d['pref']}",
             f"{d['name']}の公開データ",
             d['area'] if len(d['area']) <= 22 else d['area'][:21] + "…",
             line_a, line_b,
             "選挙区ダッシュボード",
             f"xb4g.com/giin/senkyoku/{d['key']}")
        n += 1
    card(os.path.join(ROOT, "img", "og", "senkyoku.png"),
         "衆議院小選挙区 289",
         "選挙区ダッシュボード",
         "土砂災害・避難場所・訪問介護・放課後デイ",
         "国と都道府県の公開データを、選挙区ごとに1ページに。",
         "要約も論評もしません。数字を押すと元の画面へ。",
         "選挙区ダッシュボード",
         "xb4g.com/giin/senkyoku")
    print(f"→ {n}枚 + 一覧1枚 → {OUT}")


if __name__ == "__main__":
    main()
