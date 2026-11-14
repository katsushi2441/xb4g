#!/usr/bin/env python3
"""Kurage 国会ファクト PV 用キャプチャ（1920×1080）。公開中の xb4g.com/fact/ を撮る。
  /usr/bin/python3 capture_all.py → caps/*.png"""
from pathlib import Path
from playwright.sync_api import sync_playwright
C = Path(__file__).resolve().parent / 'caps'
U = 'https://xb4g.com/fact/'
Q = U + 'nenshu-no-kabe-178man/'
G = U + 'genzei-ka-kyufu/'

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={'width': 1536, 'height': 864}, device_scale_factor=1.25, locale='ja-JP')

    def shot(url, name, h2=None, dy=140):
        pg.goto(url, wait_until='load'); pg.wait_for_timeout(2000)
        if h2:
            y = pg.evaluate("""t=>{const e=[...document.querySelectorAll('h2')].find(x=>x.textContent.includes(t));
                              return e?e.getBoundingClientRect().top+scrollY:null}""", h2)
            assert y is not None, (url, h2)
            pg.evaluate(f"scrollTo(0,{y - dy})"); pg.wait_for_timeout(400)
        pg.screenshot(path=str(C / name)); print('shot', name)

    shot(U, 'list.png')
    shot(Q, 'lead.png')
    shot(Q, 'record.png', '国会の記録')
    shot(G, 'record2.png', '国会の記録')
    shot(Q, 'open.png', 'まだ決まっていない')
    shot(Q, 'party.png', 'この話題を国会で取り上げた党')
    shot(Q, 'recent.png', '各党の最近の質疑')
    shot(U, 'rules.png', '決まり')
    b.close()
