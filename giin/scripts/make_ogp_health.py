#!/usr/bin/env python3
"""Kurage 健康トラッカーの OGP 画像（img/og/health.png・health-<話題>.png・health-kenshin.png・health-food.png、1200×630）。2026-11-05

国会トラッカー・政策の流れトラッカーのカードと骨格（文字の位置・右下のマスコット・左下のURL）は同じにして、色の系統を緑にする。
見出しの上のチップで「何が見られるか」を示す。マスコットは make_ogp_all と同じく縦横比のまま（変形させない）。

  /usr/bin/python3 scripts/make_ogp_health.py      # data/health/index.json の話題ぶん＋入口・保健指導・料理
"""
import json, os, sys
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "scripts"))
from make_ogp_all import W, H, OUT, FB, FM, FR, fit, _mascot   # noqa: E402

GREEN, GREEN_D, GREEN_L, GREEN_XL = "#2e7d32", "#1b5e20", "#c8e6c9", "#e8f5e9"
AMBER = "#f59e0b"
NAVY, GRAY = "#12202f", "#5d6b7a"


def card(path, steps, line1, line2, sub1, sub2, url):
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img, "RGBA")
    dr.ellipse([-180, -240, 480, 380], fill=(232, 245, 233, 255))
    dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(241, 248, 241, 255))
    cx = 520 if _mascot else W // 2
    maxw = 900 if _mascot else 1080
    fc = ImageFont.truetype(FM, 26)
    pad, gap = 22, 14
    ws = [dr.textlength(s, font=fc) + pad * 2 for s in steps]
    total = sum(ws) + gap * (len(steps) - 1)
    x = cx - total / 2
    for s, w in zip(steps, ws):
        dr.rounded_rectangle([x, 88, x + w, 136], radius=24, fill=GREEN_XL, outline=GREEN_L, width=2)
        dr.text((x + w / 2, 112), s, font=fc, fill=GREEN_D, anchor="mm")
        x += w + gap
    dr.text((cx, 210), line1, font=fit(dr, line1, FB, 58, maxw), fill=NAVY, anchor="mm")
    f2 = fit(dr, line2, FB, 50, maxw)
    dr.text((cx, 292), line2, font=f2, fill=GREEN, anchor="mm")
    lw = dr.textlength(line2, font=f2)
    dr.rounded_rectangle([cx - lw / 2, 326, cx - lw / 2 + min(lw, 160), 332], radius=3, fill=AMBER)
    y = 380
    for t in (sub1, sub2):
        if t:
            dr.text((cx, y), t, font=fit(dr, t, FR, 27, maxw - 120), fill=GRAY, anchor="mm")   # 説明はマスコットにかからない幅
            y += 40
    band = "Kurage 健康トラッカー"
    f_band = fit(dr, band, FM, 29, 460)
    bw2 = max(460, dr.textlength(band, font=f_band) + 60)
    dr.rounded_rectangle([cx - bw2 / 2, 480, cx + bw2 / 2, 540], radius=16, fill=GREEN)
    dr.text((cx, 510), band, font=f_band, fill="#ffffff", anchor="mm")
    if _mascot:
        img.paste(_mascot, (W - _mascot.width - 40, H - _mascot.height - 30), _mascot)
    dr.text((40, H - 40), url, font=ImageFont.truetype(FR, 22), fill=GRAY, anchor="lm")
    img.save(path, optimize=True)


def pct(x):
    return f"{x * 100:.1f}%"


def main():
    ix = json.load(open(os.path.join(ROOT, "data", "health", "index.json"), encoding="utf-8"))
    os.makedirs(OUT, exist_ok=True)
    made = []
    card(os.path.join(OUT, "health.png"), ["食べ方", "睡眠", "運動", "健診の数値"], "食べ方・睡眠・健診の数値を、", "都道府県と地域ごとに。",
         "特定健診を受けた40〜74歳の公的データ（NDBオープンデータ）から。", "保健指導の実施率と、料理1人前の栄養も。", "xb4g.com/giin/health")
    made.append("health.png")
    for t in ix["topics"]:
        card(os.path.join(OUT, f"health-{t['slug']}.png"), ["全国", "男女と年齢", "都道府県", "地域"], t["name"], "都道府県と地域ごとに。",
             f"全国 {pct(t['all'])}（男 {pct(t['m'])}・女 {pct(t['f'])}）{t['year']}の特定健診", "NDBオープンデータ（厚生労働省）から、出典つきで。", f"xb4g.com/giin/health/{t['slug']}")
        made.append(f"health-{t['slug']}.png")
    card(os.path.join(OUT, "health-kenshin.png"), ["市町村", "健康保険組合", "2018〜2024年度"], "健診のあとの保健指導は、", "どこまで届いているか。",
         "特定保健指導の実施率を、市町村の国保と保険者ごとに。", "厚生労働省の保険者別の公表資料から。", "xb4g.com/giin/health/kenshin")
    made.append("health-kenshin.png")
    card(os.path.join(OUT, "health-food.png"), ["エネルギー", "たんぱく質", "食物繊維", "食塩"], "身近な料理1人前の栄養を、", "食品成分表で計算。",
         "具材ごとの量と、日本食品標準成分表（八訂）増補2023年の値から。", "具材と量の前提は、ページに書いています。", "xb4g.com/giin/health/food")
    made.append("health-food.png")
    print("作成:", len(made), "枚 →", OUT)


if __name__ == "__main__":
    main()
