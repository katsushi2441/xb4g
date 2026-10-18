#!/usr/bin/env python3
"""議員ページとカードに「質問主意書 N件」を出すため、kshuisho の SQLite から議員ごとの件数を拾って保存する。

  /usr/bin/python3 scripts/build_kshuisho_giin.py   → data/kshuisho_giin.json

kshuisho の提出者名は「吉田 はるみ」（空白あり）と「河村たかし」（古い回は空白なし）が混ざるので、空白を抜いて
giin_all の plain と突き合わせる。院は見ない（衆院から参院へ移った人は両方の院の分を足す）。
リンク先は件数のいちばん多い表記の /giin/<名前>。kshuisho の DB を入れ替えたら回す。
"""
import collections
import json
import os
import sqlite3

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
KS = '/home/kojima/work/kshuisho/php/kshuisho_data/kshuisho.sqlite'

g = sqlite3.connect(os.path.join(ROOT, 'data', 'giin.sqlite'))
k = sqlite3.connect(KS)
by = collections.defaultdict(list)
for row in k.execute('SELECT id, submitter, house, title, submit_date FROM q ORDER BY submit_date DESC, no DESC'):
    by[row[1].replace(' ', '').replace('　', '')].append(row)
out = {}
for slug, plain in g.execute('SELECT slug, plain FROM giin_all'):
    rows = by.get(plain.replace(' ', ''))
    if not rows:
        continue
    names = collections.Counter(r[1] for r in rows)
    years = collections.Counter(r[4][:4] for r in rows if r[4])
    out[slug] = {'name': names.most_common(1)[0][0], 'n': len(rows),
                 'first': min(r[4] for r in rows if r[4]), 'last': rows[0][4],
                 'years': dict(sorted(years.items())),
                 'recent': [{'id': r[0], 'title': r[3], 'date': r[4]} for r in rows[:3]]}
json.dump(out, open(os.path.join(ROOT, 'data', 'kshuisho_giin.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(len(out), '人 → data/kshuisho_giin.json')
