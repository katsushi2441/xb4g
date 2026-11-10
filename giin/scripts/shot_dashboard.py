#!/usr/bin/env python3
"""議員の国会活動ダッシュボードを 320/360/390/1280px で撮って outputs/ に置く。横はみ出しは scrollWidth で実測。
  /usr/bin/python3 scripts/shot_dashboard.py https://xb4g.com/giin/ito-takae/dashboard
（空のプロファイルで開く。browser_agent/chrome-profile は使わない）"""
import os, sys
from playwright.sync_api import sync_playwright
url = sys.argv[1]
out = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "outputs")
os.makedirs(out, exist_ok=True)
bad = 0
with sync_playwright() as p:
    b = p.chromium.launch()
    for w in (320, 360, 390, 1280):
        pg = b.new_page(viewport={"width": w, "height": 900}, device_scale_factor=1)
        pg.goto(url, wait_until="networkidle", timeout=60000)
        sw = pg.evaluate("document.documentElement.scrollWidth")
        f = os.path.join(out, f"dashboard_{w}.png")
        if w in (390, 1280):
            pg.screenshot(path=f, full_page=True)
        bad += sw > w
        print(w, "scrollWidth", sw, "→", f if w in (390, 1280) else "", "(はみ出し)" if sw > w else "")
    b.close()
sys.exit(1 if bad else 0)
