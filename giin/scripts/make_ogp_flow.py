#!/usr/bin/env python3
"""Kurage 政策の流れトラッカーの OGP 画像（img/og/flow.png・flow-<分野>.png、1200×630）。2026-11-05

国会トラッカーのカード（make_ogp_all.card）と骨格（文字の位置・右下のマスコット・左下のURL）は同じにして、
色の系統だけ変える（ユーザー指示「国会トラッカーとの違いがはっきり分かるといい」）:
強調文字とボタンは藍（#3949ab）、背景の丸は淡い藍、差し色に琥珀（#f59e0b）。
見出しの上に「提言 → 予算 → 採択 → 評価」のチップを並べ、流れを追うものだと分かるようにする。
マスコットは make_ogp_all と同じく高さ300に縦横比のまま縮める（変形させない）。

  /usr/bin/python3 scripts/make_ogp_flow.py      # data/flow/index.json の分野ぶん＋入口
"""
import json, os, sys
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(ROOT, "scripts"))
from make_ogp_all import W, H, OUT, FB, FM, FR, fit, _mascot   # noqa: E402

INDIGO, INDIGO_D, INDIGO_L, INDIGO_XL = "#3949ab", "#283593", "#c5cae9", "#e8eaf6"
AMBER = "#f59e0b"
NAVY, GRAY = "#12202f", "#5d6b7a"
STEPS = ["提言", "予算", "採択", "評価"]


def card(path, line1, line2, sub1, sub2, url):
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img, "RGBA")
    dr.ellipse([-180, -240, 480, 380], fill=(232, 234, 246, 255))       # 淡い藍
    dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(241, 242, 250, 255))
    cx = 520 if _mascot else W // 2
    maxw = 900 if _mascot else 1080

    # 段のチップ（提言 → 予算 → 採択 → 評価）
    fc = ImageFont.truetype(FM, 26)
    fa = ImageFont.truetype(FB, 28)
    pad, gap, arrow = 22, 14, "→"
    ws = [dr.textlength(s, font=fc) + pad * 2 for s in STEPS]
    aw = dr.textlength(arrow, font=fa)
    total = sum(ws) + (aw + gap * 2) * (len(STEPS) - 1)
    x = cx - total / 2
    for i, (s, w) in enumerate(zip(STEPS, ws)):
        dr.rounded_rectangle([x, 88, x + w, 136], radius=24, fill=INDIGO_XL, outline=INDIGO_L, width=2)
        dr.text((x + w / 2, 112), s, font=fc, fill=INDIGO_D, anchor="mm")
        x += w
        if i < len(STEPS) - 1:
            dr.text((x + gap + aw / 2, 110), arrow, font=fa, fill=AMBER, anchor="mm")
            x += aw + gap * 2

    dr.text((cx, 210), line1, font=fit(dr, line1, FB, 60, maxw), fill=NAVY, anchor="mm")
    f2 = fit(dr, line2, FB, 50, maxw)
    dr.text((cx, 292), line2, font=f2, fill=INDIGO, anchor="mm")
    lw = dr.textlength(line2, font=f2)
    dr.rounded_rectangle([cx - lw / 2, 326, cx - lw / 2 + min(lw, 160), 332], radius=3, fill=AMBER)   # 琥珀の下線（差し色）

    y = 380
    for t in (sub1, sub2):
        if t:
            dr.text((cx, y), t, font=fit(dr, t, FR, 27, maxw), fill=GRAY, anchor="mm")
            y += 40

    band = "Kurage 政策の流れトラッカー"
    f_band = fit(dr, band, FM, 29, 460)
    bw2 = max(460, dr.textlength(band, font=f_band) + 60)
    dr.rounded_rectangle([cx - bw2 / 2, 480, cx + bw2 / 2, 540], radius=16, fill=INDIGO)
    dr.text((cx, 510), band, font=f_band, fill="#ffffff", anchor="mm")

    if _mascot:
        img.paste(_mascot, (W - _mascot.width - 40, H - _mascot.height - 30), _mascot)
    dr.text((40, H - 40), url, font=ImageFont.truetype(FR, 22), fill=GRAY, anchor="lm")
    img.save(path, optimize=True)


def main():
    ix = json.load(open(os.path.join(ROOT, "data", "flow", "index.json"), encoding="utf-8"))
    os.makedirs(OUT, exist_ok=True)
    made = []
    card(os.path.join(OUT, "flow.png"), "政策は、会議から評価まで", "どうつながったか。",
         "会議・予算・採択・評価を、公表資料だけでつないで並べます。", "数字と名前には、すべて出典を付けています。", "xb4g.com/giin/flow")
    made.append("flow.png")
    for f in ix["fields"]:
        s = f["summary"]
        sub1 = f.get("card_sub1") or (f"会議 {s['councils']}・関連事業 {s['projects']}件・公募 {s['programs']}件（採択 {s['award_rows']}件）・評価 {s['evaluations']}件")
        sub2 = f.get("card_sub2") or "行政事業レビュー・会議資料・採択結果を出典つきで。"
        card(os.path.join(OUT, f"flow-{f['slug']}.png"), f"{f['name']}は、", "会議から評価までどうつながったか。" if not f.get("card_line2") else f["card_line2"],
             sub1, sub2, f"xb4g.com/giin/flow/{f['slug']}")
        made.append(f"flow-{f['slug']}.png")
    print("作成:", ", ".join(made), "→", OUT)


if __name__ == "__main__":
    main()
