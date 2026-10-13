#!/usr/bin/env python3
"""kappstore の看板画像 1200×630。
kjishin/scripts/make_ogp.py の正典にそろえる:
ライトテーマ・薄緑の円・ピル型帯・見出し2段・説明2行・ティール製品名帯・
右下にKurageマスコット300px・左下に公開URL。
  GIIN_MASCOT=... /usr/bin/python3 scripts/make_store_image.py           # 議員発言ログ
  GIIN_MASCOT=... /usr/bin/python3 scripts/make_store_image.py tracker   # Kurage 国会トラッカー
"""
import sys
import os
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TRACKER = len(sys.argv) > 1 and sys.argv[1] == "tracker"
OUT = os.path.join(ROOT, "outputs", "kappstore_tracker.png" if TRACKER else "kappstore.png")
MASCOT = os.environ.get("GIIN_MASCOT", "")
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FM = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

os.makedirs(os.path.dirname(OUT), exist_ok=True)
img = Image.new("RGB", (W, H), "#ffffff")
dr = ImageDraw.Draw(img, "RGBA")
dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255))
dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))

mascot = None
if MASCOT and os.path.exists(MASCOT):
    mascot = Image.open(MASCOT).convert("RGBA")
    mascot = mascot.resize((int(mascot.width * 300 / mascot.height), 300))
cx = 520 if mascot else W // 2

f_badge = ImageFont.truetype(FM, 26)
f_h = ImageFont.truetype(FB, 58)
f_h2 = ImageFont.truetype(FB, 46)
f_s = ImageFont.truetype(FR, 27)
f_brand = ImageFont.truetype(FM, 29)

T = {'badge': ('国会会議録から・PHPとSQLiteだけ・LLM不使用', '国会会議録から・全国の議員・PHPとSQLiteだけ'), 'h1': ('その議員は国会で、', 'そのテーマは国会で、'), 'h2': ('何と言いましたか。', 'どこまで来ましたか。'), 's1': ('いつ・どの会議で・何を質問したかを、会議録リンクつきで引く。', 'だれが質問し、政府が何と答えたかを日付順に。'), 's2': ('要約しません。県を変えれば、あなたの地元の議員で作れます。', '質問と答弁を1枚のカードにして、Xに貼れます。'), 'brand': ('議員発言ログ', '国会トラッカー'), 'url': ('xb4g.com/giin/', 'xb4g.com/giin/tracker')}
I = 1 if TRACKER else 0
badge = T["badge"][I]
bw = dr.textlength(badge, font=f_badge) + 40
dr.rounded_rectangle([cx - bw / 2, 92, cx + bw / 2, 140], radius=24, fill="#e6f4f2", outline="#bfe3de")
dr.text((cx, 116), badge, font=f_badge, fill="#0a726b", anchor="mm")

dr.text((cx, 218), T["h1"][I], font=f_h, fill="#12202f", anchor="mm")
dr.text((cx, 296), T["h2"][I], font=f_h2, fill="#0a9a8f", anchor="mm")

dr.text((cx, 370), T["s1"][I], font=f_s, fill="#5d6b7a", anchor="mm")
dr.text((cx, 410), T["s2"][I], font=f_s, fill="#5d6b7a", anchor="mm")

dr.rounded_rectangle([cx - 250, 468, cx + 250, 528], radius=16, fill="#0a9a8f")
dr.text((cx, 498), T["brand"][I], font=f_brand, fill="#ffffff", anchor="mm")

if mascot:
    img.paste(mascot, (W - mascot.width - 40, H - mascot.height - 30), mascot)
dr.text((40, H - 40), T["url"][I], font=ImageFont.truetype(FR, 22), fill="#5d6b7a", anchor="lm")

img.save(OUT, optimize=True)
print(OUT, img.size)
