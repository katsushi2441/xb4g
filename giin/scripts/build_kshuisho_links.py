#!/usr/bin/env python3
"""国会トラッカーの各ページから「同じ語で質問主意書を探す」ために、kshuisho で件数のある語を選んで保存する。

  /usr/bin/python3 scripts/build_kshuisho_links.py   → data/kshuisho_links.json

trackers.json の words の先頭4語を kshuisho の検索にかけ、いちばん件数の多い語を採る。0件なら載せない
（押しても何も出ないリンクは置かない）。件数はゆっくりしか変わらないので、トラッカーを足したときに回せば足りる。
"""
import json
import os
import re
import subprocess
import urllib.parse

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BASE = 'https://kurage.exbridge.jp/kshuisho.php/search?q='
out = {}
for t in json.load(open(os.path.join(ROOT, 'data', 'trackers.json'), encoding='utf-8')):
    best = None
    for w in t['words'][:4]:
        h = subprocess.run(['curl', '-s', '-m', '20', BASE + urllib.parse.quote(w)], capture_output=True, text=True).stdout
        m = re.search(r'([\d,]+)\s*件', re.sub('<[^>]+>', ' ', h))
        n = int(m.group(1).replace(',', '')) if m else 0
        if n and (best is None or n > best[1]):
            best = (w, n)
    if best:
        out[t['key']] = {'word': best[0], 'count': best[1]}
    print(t['key'], best)
json.dump(out, open(os.path.join(ROOT, 'data', 'kshuisho_links.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(len(out), '件 → data/kshuisho_links.json')
