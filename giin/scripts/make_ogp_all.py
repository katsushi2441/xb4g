#!/usr/bin/env python3
"""ページごとの OGP 画像を作る（1200×630）。

共通の1枚だけだと、X や Slack に貼ったときどのページも同じ絵になる。
議員ページとことがらページは**誰の・何の話か**が絵で分かるほうが開かれる。
作りは kjishin/scripts/make_ogp.py にそろえる（ライトテーマ・薄緑の円・
ピル型帯・見出し2段・ティール帯・右下にマスコット・左下に公開URL）。

  /usr/bin/python3 scripts/make_ogp_all.py
"""
import json, os, sqlite3, sys
from PIL import Image, ImageDraw, ImageFont

W, H = 1200, 630
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "img", "og")
DB = os.path.join(ROOT, "data", "giin.sqlite")
MASCOT = "/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png"
FB = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FM = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"
FR = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

_mascot = None
if os.path.exists(MASCOT):
    _mascot = Image.open(MASCOT).convert("RGBA")
    _mascot = _mascot.resize((int(_mascot.width * 300 / _mascot.height), 300))


def fit(dr, text, font_path, size, maxw):
    """入りきるまで字を小さくする。名前や見出しがはみ出すと台無しなので。"""
    while size > 22:
        f = ImageFont.truetype(font_path, size)
        if dr.textlength(text, font=f) <= maxw:
            return f
        size -= 3
    return ImageFont.truetype(font_path, size)


def card(path, badge, line1, line2, sub1, sub2, band, url="xb4g.com/giin/"):
    img = Image.new("RGB", (W, H), "#ffffff")
    dr = ImageDraw.Draw(img, "RGBA")
    dr.ellipse([-180, -240, 480, 380], fill=(230, 244, 242, 255))
    dr.ellipse([W - 460, H - 330, W + 220, H + 240], fill=(240, 246, 246, 255))
    cx = 520 if _mascot else W // 2
    maxw = 900 if _mascot else 1080

    f_badge = fit(dr, badge, FM, 26, maxw)
    bw = dr.textlength(badge, font=f_badge) + 40
    dr.rounded_rectangle([cx - bw / 2, 88, cx + bw / 2, 136], radius=24,
                         fill="#e6f4f2", outline="#bfe3de")
    dr.text((cx, 112), badge, font=f_badge, fill="#0a726b", anchor="mm")

    dr.text((cx, 210), line1, font=fit(dr, line1, FB, 60, maxw), fill="#12202f", anchor="mm")
    if line2:
        dr.text((cx, 292), line2, font=fit(dr, line2, FB, 50, maxw), fill="#0a9a8f", anchor="mm")

    y = 368
    for t in (sub1, sub2):
        if t:
            dr.text((cx, y), t, font=fit(dr, t, FR, 27, maxw), fill="#5d6b7a", anchor="mm")
            y += 40

    f_band = fit(dr, band, FM, 29, 460)
    bw2 = max(460, dr.textlength(band, font=f_band) + 60)
    dr.rounded_rectangle([cx - bw2 / 2, 470, cx + bw2 / 2, 530], radius=16, fill="#0a9a8f")
    dr.text((cx, 500), band, font=f_band, fill="#ffffff", anchor="mm")

    if _mascot:
        img.paste(_mascot, (W - _mascot.width - 40, H - _mascot.height - 30), _mascot)
    dr.text((40, H - 40), url, font=ImageFont.truetype(FR, 22), fill="#5d6b7a", anchor="lm")
    img.save(path, optimize=True)


def ku(d):
    if "東海" in d:
        return "比例東海ブロック"
    import re
    m = re.match(r"^愛知(\d+)$", d)
    return "愛知" + m.group(1) + "区" if m else d


def main():
    os.makedirs(OUT, exist_ok=True)
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    n = 0

    for g in con.execute("SELECT * FROM giin ORDER BY id"):
        q = int(g["n_q"] or 0)
        card(os.path.join(OUT, f"{g['slug']}.png"),
             f"{g['house']}　{ku(g['district'])}　{g['party']}",
             f"{g['plain']} は国会で",
             "何を質問したか。",
             f"議員としての質疑 {q:,}件を、日付と会議名で引けます。" if q
                 else "この期間、会議録に発言が載っていません。",
             "要約しません。抜粋と会議録へのリンクだけ。",
             "愛知の国会議員 発言ログ",
             f"xb4g.com/giin/{g['slug']}")
        n += 1

    themes = json.load(open(os.path.join(ROOT, "data", "themes.json"), encoding="utf-8"))
    for t in themes:
        ors = " OR ".join(["body LIKE ?"] * len(t["words"]))
        args = ["%" + w + "%" for w in t["words"]]
        c = con.execute(f"SELECT COUNT(*) FROM speech WHERE kind='q' AND ({ors})", args).fetchone()[0]
        card(os.path.join(OUT, f"theme-{t['slug']}.png"),
             "愛知の国会議員45人の質疑から",
             t["name"] + "について、",
             "だれが何と言ったか。",
             f"該当する質疑 {c:,}件。だれが何件ふれたかも出します。",
             "語で機械的に集めたもので、賛否の判定はしていません。",
             "愛知の国会議員 発言ログ",
             f"xb4g.com/giin/theme/{t['slug']}")
        n += 1

    tot = con.execute("SELECT COUNT(*) FROM giin").fetchone()[0]
    card(os.path.join(OUT, "list.png"), "衆院 愛知1〜16区・比例東海／参院 愛知県選挙区",
         f"愛知の国会議員 {tot}人を、", "ひとつの表で。",
         "質疑・答弁・議事整理を分けて数えています。",
         "党派では選んでいません。", "愛知の国会議員 発言ログ", "xb4g.com/giin/list")
    card(os.path.join(OUT, "theme.png"), "年収の壁・南海トラフ・自動車産業ほか",
         "ことがらから、", "国会の発言を引く。",
         f"{len(themes)}のことがらで、だれが何件ふれたかを並べます。",
         "要約も論評もしません。", "愛知の国会議員 発言ログ", "xb4g.com/giin/theme")
    n += 2

    print(f"{n}枚  → {OUT}")
    print(f"合計 {sum(os.path.getsize(os.path.join(OUT,f)) for f in os.listdir(OUT))/1e6:.1f}MB")


if __name__ == "__main__":
    main()
