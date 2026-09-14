#!/usr/bin/env python3
"""OGP 1200×630。kjishin/scripts/make_ogp.py の作りにそろえる。
  /usr/bin/python3 scripts/make_ogp.py
"""
import os
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "ogp.png")
MASCOT = "/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png"
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FM = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

img = Image.new("RGB", (W, H), "#ffffff")
dr = ImageDraw.Draw(img, "RGBA")
dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255))
dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))

mascot = None
if os.path.exists(MASCOT):
    mascot = Image.open(MASCOT).convert("RGBA")
    mh = 300
    mascot = mascot.resize((int(mascot.width * mh / mascot.height), mh))
cx = 520 if mascot else W // 2

f_badge = ImageFont.truetype(FM, 26)
f_h = ImageFont.truetype(FB, 58)
f_h2 = ImageFont.truetype(FB, 46)
f_s = ImageFont.truetype(FR, 27)
f_brand = ImageFont.truetype(FM, 29)

badge = "衆院 愛知1〜16区・比例東海／参院 愛知県選挙区"
bw = dr.textlength(badge, font=f_badge) + 40
dr.rounded_rectangle([cx - bw / 2, 92, cx + bw / 2, 140], radius=24, fill="#e6f4f2", outline="#bfe3de")
dr.text((cx, 116), badge, font=f_badge, fill="#0a726b", anchor="mm")

dr.text((cx, 218), "その議員は国会で、", font=f_h, fill="#12202f", anchor="mm")
dr.text((cx, 296), "何と言いましたか。", font=f_h2, fill="#0a9a8f", anchor="mm")

dr.text((cx, 370), "愛知の有権者が選んだ45人の発言を、日付と会議名で引く。", font=f_s, fill="#5d6b7a", anchor="mm")
dr.text((cx, 410), "要約しません。抜粋と会議録へのリンクだけ。", font=f_s, fill="#5d6b7a", anchor="mm")

dr.rounded_rectangle([cx - 240, 468, cx + 240, 528], radius=16, fill="#0a9a8f")
dr.text((cx, 498), "愛知の国会議員 発言ログ", font=f_brand, fill="#ffffff", anchor="mm")

if mascot:
    img.paste(mascot, (W - mascot.width - 40, H - mascot.height - 30), mascot)
dr.text((40, H - 40), "xb4g.com/giin/", font=ImageFont.truetype(FR, 22), fill="#5d6b7a", anchor="lm")

img.save(OUT, optimize=True)
print(OUT, img.size)
