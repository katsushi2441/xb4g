#!/usr/bin/env python3
"""「この議員だけが国会で取り上げていること」のカード画像。

議員本人・事務所がXや公式サイトに貼れる1枚。**貼られた先から人が来る**のが狙いなので、
本人が貼って恥ずかしくない形にする。順位づけをしない・煽らない・出典を必ず入れる。

**件数の合計や順位は焼き込まない。** 毎日変わるうえ、多い少ないは立場で決まる。

  /usr/bin/python3 scripts/make_uniq_cards.py
"""
import os
import sqlite3
import sys

from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
OUT = os.path.join(ROOT, "img", "uniq")
W, H = 1200, 630
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FM = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"
TEAL, INK, GRAY = "#0a9a8f", "#12202f", "#5d6b7a"


def card(path, name, party, house, terms, host):
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img, "RGBA")
    dr.ellipse([-190, -250, 470, 370], fill=(230, 244, 242, 255))
    dr.ellipse([W - 300, H - 220, W + 260, H + 300], fill=(240, 246, 246, 255))

    f_badge = ImageFont.truetype(FM, 25)
    badge = "国会会議録より"
    bw = dr.textlength(badge, font=f_badge) + 38
    dr.rounded_rectangle([56, 52, 56 + bw, 98], radius=23, fill="#e6f4f2", outline="#bfe3de")
    dr.text((56 + bw / 2, 75), badge, font=f_badge, fill="#0a726b", anchor="mm")

    dr.text((56, 126), f"{name}議員だけが", font=ImageFont.truetype(FB, 52), fill=INK)
    dr.text((56, 192), "国会で取り上げていること", font=ImageFont.truetype(FB, 44), fill=TEAL)
    dr.text((56, 258), f"{house}　{party}", font=ImageFont.truetype(FR, 25), fill=GRAY)

    # 語を2列で置く。**長い語で折り返さないよう、幅に収まる数だけ出す。**
    f_t = ImageFont.truetype(FM, 30)
    f_n = ImageFont.truetype(FR, 23)
    y = 312
    for i, (term, n) in enumerate(terms[:6]):
        col = i % 2
        x = 56 + col * 560
        if col == 0 and i:
            y += 66
        if dr.textlength(term, font=f_t) > 400:
            continue
        dr.rounded_rectangle([x, y, x + 520, y + 54], radius=12, fill="#ffffff", outline="#e3e9ec")
        dr.text((x + 20, y + 27), term, font=f_t, fill=INK, anchor="lm")
        dr.text((x + 500, y + 28), f"{n}回", font=f_n, fill=TEAL, anchor="rm")

    dr.text((56, H - 74), "ほかの44人が一度も使っていない言葉です（多い少ないの比較ではありません）",
            font=ImageFont.truetype(FR, 21), fill=GRAY)
    dr.text((56, H - 40), host, font=ImageFont.truetype(FM, 22), fill=TEAL)
    img.save(path, optimize=True)


def main() -> int:
    if not os.path.isfile(DB):
        print("DBがありません", file=sys.stderr)
        return 1
    os.makedirs(OUT, exist_ok=True)
    con = sqlite3.connect(f"file:{DB}?mode=ro", uri=True)
    con.row_factory = sqlite3.Row
    host = os.environ.get("GIIN_CARD_HOST", "xb4g.com/giin/")
    n = 0
    for g in con.execute("SELECT id, plain, party, house, slug FROM giin ORDER BY id"):
        rows = con.execute("SELECT term, n FROM uniq_term WHERE giin_id=?"
                           " ORDER BY n DESC, term LIMIT 6", (g["id"],)).fetchall()
        if not rows:
            continue
        card(os.path.join(OUT, f"{g['slug']}.png"), g["plain"], g["party"], g["house"],
             [(r["term"], r["n"]) for r in rows], host + g["slug"])
        n += 1
    size = sum(os.path.getsize(os.path.join(OUT, f)) for f in os.listdir(OUT))
    print(f"{n}枚 → {OUT}（{size/1024/1024:.1f}MB）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
