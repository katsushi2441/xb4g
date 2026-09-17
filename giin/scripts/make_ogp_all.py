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
# 右下に置くキャラ画像。無ければ中央寄せで作る（配布先には同梱しない）
MASCOT = os.environ.get("GIIN_MASCOT", "")
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

    # AIの特集。**ことがらのカードとは別に作る**（見せる数字が「件数」ではなく
    # 「何日・いくつの会議で」なので、同じ型に流し込むと内容と合わない）
    ai_q = con.execute("""SELECT COUNT(*) FROM speech s
        JOIN speech_theme st ON st.speech_id=s.speech_id AND st.theme='ai'
        WHERE s.kind='q'""").fetchone()[0]
    ai_g = con.execute("""SELECT COUNT(DISTINCT s.giin_id) FROM speech s
        JOIN speech_theme st ON st.speech_id=s.speech_id AND st.theme='ai'
        WHERE s.kind='q'""").fetchone()[0]
    card(os.path.join(OUT, "ai-tokushu.png"),
         "愛知の国会議員45人の質疑から",
         "AIを国会で、",
         "だれが論じているか。",
         f"AIに触れた質疑 {ai_q:,}件、{ai_g}人。何日・いくつの会議で持ち出したかで並べます。",
         "件数より「続けて取り上げたか」を見ます。",
         "愛知の国会議員 発言ログ",
         "xb4g.com/giin/ai")
    n += 1

    # 全国トラッカー。**愛知の45人ではなく全国の発言**なので、帯の文言を変える
    trackers = json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))
    for t in trackers:
        r = con.execute("""SELECT COUNT(*), SUM(kind='q'), SUM(kind='gov'),
                                  COUNT(DISTINCT CASE WHEN kind='q' THEN speaker END), MAX(date)
                           FROM tracker_speech WHERE tracker=?""", (t["key"],)).fetchone() \
            if con.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='tracker_speech'").fetchone()[0] else (0, 0, 0, 0, "")
        card(os.path.join(OUT, f"tracker-{t['key']}.png"),
             "国会会議録から、全国の発言を機械的に集めました",
             t["name"] + "は、",
             "国会でどこまで来たか。",
             f"質疑 {int(r[1] or 0):,}件・答弁 {int(r[2] or 0):,}件・議員 {int(r[3] or 0)}人（{r[4] or ''}まで）",
             "だれが質問し、政府が何と答えたか。日付と会議録リンク。",
             "国会トラッカー",
             f"xb4g.com/giin/tracker/{t['key']}")
        n += 1

    # 会派ごと
    slugmap = {'自民':'jimin','国民民主':'kokumin','立憲':'rikken','公明':'komei','維新':'ishin',
               '共産':'kyosan','参政':'sansei','チームみらい':'mirai','中道改革連合':'chudo',
               '無所属':'mushozoku'}
    n_all = con.execute("SELECT COUNT(*) FROM giin").fetchone()[0]
    q_all = con.execute("SELECT SUM(n_q) FROM giin").fetchone()[0] or 1
    for r in con.execute("SELECT party, COUNT(*) n, SUM(n_q) q FROM giin GROUP BY party"):
        sl = slugmap.get(r["party"], "p" + __import__("hashlib").md5(
            r["party"].encode()).hexdigest()[:6])
        pn = round(r["n"] / n_all * 100)
        pq = round(r["q"] / q_all * 100)
        card(os.path.join(OUT, f"party-{sl}.png"),
             f"愛知の国会議員{n_all}人のうち {r['party']} は{r['n']}人",
             f"人数は{pn}%、",
             f"質疑は{pq}%。",
             "件数の差は熱心さではなく、与党か野党かという立場で決まります。",
             "このサイトは件数を数えるだけで、良し悪しの判定はしません。",
             "愛知の国会議員 発言ログ", f"xb4g.com/giin/party/{sl}")
        n += 1
    card(os.path.join(OUT, "party.png"), "会派ごとの人数と質疑・答弁・議事整理",
         "人数の割合と、", "質疑の割合のずれ。",
         "ずれは立場の差です。与党は答弁と議事整理に回ります。",
         "同じ立場の議員どうしで比べてください。",
         "愛知の国会議員 発言ログ", "xb4g.com/giin/party")
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
