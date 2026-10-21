#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""議員の公式サイトに載せる「国会活動の投稿の下書き」を、会議録と質問主意書から自動で組む見本ページ。

**なぜ作ったか（2026-10-15）。** 議員の公式サイトを見ると、事務所が委員会の質疑や質問主意書の報告を手で書いて載せている。
会議録はPDFにしてまとめて載せ、載るのは質疑の1〜2か月後（蓮舫議員）、2026年の質疑6回のうち活動報告にあるのは1回
（伊藤孝恵議員）。国会会議録が公開されたら下書きができていて、事務所は確かめて公開を押すだけ、という形を見せる。

  /usr/bin/python3 scripts/make_hp_draft_demo.py --member 伊藤孝恵 --from 2026-01-01 [--deploy]

- 中身は会議録と答弁書から**そのまま抜き出す**。要約・言い換えはしない（事務所が自分の言葉を足す前提）。
- 答弁した官僚（政府参考人）は名簿に無いので、会議ごとに国会会議録検索システムから全文を取り直して、質問と答弁を組にする。
- 置き場所は proto.exbridge.jp/giin-hp-<token>/（推測されにくいURL・noindex。商品名が決まるまで proto の一覧に載せない）。
"""
from __future__ import annotations

import argparse
import datetime
import html
import json
import os
import re
import secrets
import sqlite3
import sys
import urllib.parse
import zlib

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import ndl  # noqa: E402

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
KS = "/home/kojima/work/kshuisho/php/kshuisho_data/kshuisho.sqlite"
OUT = os.path.join(ROOT, "outputs", "hp_draft_demo")
XB = "https://xb4g.com/giin"
GREET = re.compile(r"^(?:[^。]{0,14}(?:ありがとうございま(?:す|した)|お願いいたします|お願いします|よろしくお願いします)。\s*)+")
e = html.escape


def clean(body: str) -> str:
    t = re.sub(r"^○\S+\s*", "", body or "")
    t = re.sub(r"[\r\n　 ]+", " ", t).strip()
    return GREET.sub("", t).strip()


def cut(t: str, n: int) -> str:
    """n字までで、句点で終わるところで切る（文の途中で切らない）"""
    if len(t) <= n:
        return t
    i = t.rfind("。", 0, n)
    return t[: i + 1] if i >= n // 3 else t[:n] + "…"


def meeting(issue_id: str) -> list[dict]:
    out, start = [], 1
    while True:
        d = ndl.call("speech", issueID=issue_id, maximumRecords=100, startRecord=start)
        out += d.get("speechRecord") or []
        if not d.get("nextRecordPosition"):
            return out
        start = d["nextRecordPosition"]


def pairs(sp: list[dict], name: str, k: int = 3) -> list[tuple[dict, dict | None]]:
    """その議員の質問（120字以上）と、直後の答弁（委員長の進行と本人の発言を飛ばす）を組にする"""
    res = []
    for i, s in enumerate(sp):
        if s.get("speaker") != name or len(clean(s.get("speech"))) < 120:
            continue
        # 討論は質問ではない。次に話すのは別の議員の討論で、答弁ではない（2026-10-21 窪田議員の本会議の反対討論に、
        # 梅村議員の賛成討論を「答弁」として組んでいた）
        if re.search(r"討論(いた|を行い|をいた)", clean(s.get("speech"))[:200]):
            continue
        ans = None
        for t in sp[i + 1:i + 4]:
            pos = t.get("speakerPosition") or ""
            if t.get("speaker") == name:
                break
            if "委員長" in pos or "議長" in pos or len(clean(t.get("speech"))) < 60:
                continue
            # 答えるのは政府側（大臣・副大臣・政務官・政府参考人など）か参考人だけ。肩書の無い発言は議員なので組まない
            if re.search(r"大臣|政務官|政府参考人|政府特別補佐人|長官|総裁|参考人|副長官|局長|会計検査院", pos):
                ans = t
            break
        if ans:
            res.append((s, ans))
        if len(res) >= k:
            break
    return res


def tags_for(text: str, trackers: list[dict]) -> list[str]:
    out = []
    for t in trackers:
        for w in t.get("words") or []:
            if all(p in text for p in re.split(r"[\s　]+", w) if p):
                out.append(t.get("short") or t["name"])
                break
    return list(dict.fromkeys(out))[:4]


def jdate(d: str) -> str:
    y, m, dd = d[:10].split("-")
    return f"{int(m)}月{int(dd)}日"


def build(member: str, frm: str, display: str | None = None) -> str:
    con = sqlite3.connect(DB, timeout=60)
    con.row_factory = sqlite3.Row
    g = con.execute("SELECT * FROM giin_all WHERE name=?", (member,)).fetchone()
    if not g:
        raise SystemExit(f"名簿にいない: {member}")
    trackers = json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))
    meets = con.execute("""SELECT date, house, meeting, MIN(speech_id) sid, meeting_url FROM speech_all
                           WHERE giin_id=? AND kind='q' AND date>=? GROUP BY meeting_url ORDER BY date DESC""",
                        (g["id"], frm)).fetchall()
    drafts = []
    for m in meets:
        sp = meeting(m["sid"].split("_")[0])
        ps = pairs(sp, member)
        if not ps:
            continue
        text = " ".join(clean(q.get("speech")) for q, _ in ps)
        tg = tags_for(" ".join(clean(s.get("speech")) for s in sp if s.get("speaker") == member), trackers)
        # アイキャッチ（OGP画像）：その日の質疑が国会トラッカーにあれば質疑カード。テーマは本人が長く取り組んでいる順
        card = None
        for r in con.execute("""SELECT t.tracker, t.speech_id FROM tracker_speech t
                                JOIN (SELECT tracker, COUNT(DISTINCT date) d, COUNT(*) n FROM tracker_speech
                                      WHERE giin_id=? AND kind='q' GROUP BY tracker) w ON w.tracker=t.tracker
                                WHERE t.giin_id=? AND t.kind='q' AND t.meeting_url=?
                                ORDER BY w.d DESC, w.n DESC, t.speech_order LIMIT 1""", (g["id"], g["id"], m["meeting_url"])):
            card = dict(img=f"{XB}/tracker/{r['tracker']}/{r['speech_id']}.png", page=f"{XB}/tracker/{r['tracker']}/{r['speech_id']}",
                        label="質疑カード（質問と答弁）")
        if not card:
            card = dict(img=f"{XB}/{g['slug']}.png", page=f"{XB}/{g['slug']}", label="議員カード（国会での発言の記録）")
        drafts.append(dict(kind="会議録", date=m["date"], house=m["house"], meeting=m["meeting"], url=m["meeting_url"],
                           pairs=ps, tags=tg, text=text, card=card))
    # 質問主意書（答弁書が届いたもの）
    shu = []
    if os.path.exists(KS):
        k = sqlite3.connect(KS)
        k.row_factory = sqlite3.Row
        nm = member[:2] + "%" + member[2:]
        for r in k.execute("""SELECT * FROM q WHERE REPLACE(REPLACE(submitter,' ',''),'　','')=? AND a_text<>''
                              ORDER BY submit_date DESC LIMIT 2""", (member,)):
            shu.append(r)
    tid = "%08x" % zlib.crc32(member.encode())
    tmap = {t["key"]: t for t in trackers}
    coll = [dict(img=f"{XB}/tracker/{r['tracker']}/m/{tid}.png", page=f"{XB}/tracker/{r['tracker']}/m/{tid}",
                 name=(tmap.get(r["tracker"]) or {}).get("short") or r["tracker"], days=r["d"])
            for r in con.execute("""SELECT tracker, COUNT(DISTINCT date) d FROM tracker_speech WHERE giin_id=? AND kind='q'
                                    GROUP BY tracker ORDER BY d DESC, COUNT(*) DESC LIMIT 6""", (g["id"],))]
    return page(g, drafts, shu, frm, display or g["plain"], coll)


CSS = """
:root{--ink:#12202f;--sub:#5d6b7a;--line:#e3e9ec;--teal:#0a9a8f;--tealD:#0a726b;--mint:#e6f4f2;--bg:#f6f9fa;--warn:#fff4e8}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:"Noto Sans JP",system-ui,sans-serif;line-height:1.8}
header{background:#fff;border-bottom:1px solid var(--line)}header .in{max-width:880px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:10px}
header a{color:var(--ink);text-decoration:none;font-weight:800}header img{object-fit:contain}
main{max-width:880px;margin:0 auto;padding:18px 16px 48px}h1{font-size:clamp(21px,4.4vw,28px);line-height:1.45;margin:8px 0 10px}
h2{font-size:19px;margin:30px 0 8px}.lead{font-size:15.5px}.note{color:var(--sub);font-size:13.5px}
.flow{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:14px 0}
.flow div{background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font-size:14px}.flow b{display:block;color:var(--tealD)}
.draft{background:#fff;border:1px solid var(--line);border-radius:14px;margin:16px 0;overflow:hidden;box-shadow:0 1px 4px rgba(18,32,47,.05)}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;background:var(--warn);padding:8px 14px;font-size:13px;color:#8a5a12}
.bar .btns span{display:inline-block;border-radius:999px;padding:3px 12px;margin-left:6px;font-weight:800;background:#fff;border:1px solid #e9c48f}
.bar .btns span.pub{background:var(--teal);color:#fff;border-color:var(--teal)}
.body{padding:12px 16px 14px}.body h3{font-size:18px;margin:2px 0 6px;line-height:1.5}
.tags span{display:inline-block;background:var(--mint);color:var(--tealD);border-radius:999px;padding:1px 10px;font-size:12.5px;margin:0 6px 6px 0;font-weight:700}
.q,.a{border-left:4px solid var(--teal);background:#f3faf9;border-radius:8px;padding:8px 12px;margin:10px 0;font-size:14.5px}
.a{border-left-color:#1f3a5f;background:#f2f5f9}.who{font-weight:800;font-size:13px;color:var(--sub);display:block}
.x{background:#f7f7f7;border:1px dashed #c9d3dc;border-radius:10px;padding:8px 12px;font-size:14px;margin-top:10px;white-space:pre-wrap}
.src a{color:var(--tealD)}
.xb{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 4px}.bx,.bc{display:inline-block;border-radius:999px;padding:7px 16px;font-weight:800;font-size:14px;text-decoration:none;cursor:pointer;font-family:inherit}
.bx{background:#0f1419;color:#fff;border:0}.bc{background:#fff;color:var(--tealD);border:2px solid var(--teal)}
.eye{margin:10px 0}.eye img,.coll img{width:100%;height:auto;display:block;border-radius:10px;border:1px solid var(--line)}
.eye figcaption{font-size:12.5px;color:var(--sub);margin-top:4px}
.coll{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.coll a{color:var(--ink);text-decoration:none;font-size:13.5px;font-weight:700}
.coll span{display:block;margin-top:4px}footer{max-width:880px;margin:0 auto;padding:18px 16px 40px;color:var(--sub);font-size:13px}
"""


def xbox(text: str, url: str) -> str:
    """Xの告知文と、Xに投稿・コピーのボタン。見本では公式サイトの記事がまだ無いので、URLはカードのページで代える"""
    intent = "https://x.com/intent/post?" + urllib.parse.urlencode({"text": text, "url": url})
    return (f'<div class="x"><b>Xの告知文の下書き</b>\n{e(text)}\n{e(url)}'
            f'<div class="xb"><a class="bx" href="{e(intent)}" target="_blank" rel="noopener">Xに投稿</a>'
            f'<button class="bc" type="button" data-copy="{e(text + chr(10) + url)}" onclick="cp(this)">コピー</button></div>'
            f'<span class="note">本番では、URLは公式サイトのこの記事になります（見本ではカードのページで代えています）。</span></div>')


def page(g, drafts, shu, frm, who, coll) -> str:
    now = datetime.datetime.now().strftime("%Y年%-m月%-d日")
    parts = []
    for d in drafts:
        title = f"{jdate(d['date'])}　{d['meeting']}で質問しました"
        body = ""
        for q, a in d["pairs"]:
            qa = cut(clean(q.get("speech")), 260)
            aa = cut(clean(a.get("speech")), 260)
            body += (f'<div class="q"><span class="who">質問　{e(who)}</span>「{e(qa)}」</div>'
                     f'<div class="a"><span class="who">答弁　{e("　".join(x for x in ((a.get("speakerPosition") or "").strip(), a.get("speaker") or "") if x))}</span>「{e(aa)}」</div>')
        x = (f"{jdate(d['date'])}の{d['house']}{d['meeting']}で質問しました。"
             + (f"{'・'.join(d['tags'])}について、" if d["tags"] else "")
             + "政府とのやりとりを会議録から載せています。")
        xurl = d["card"]["page"]
        parts.append(f'''<article class="draft"><div class="bar"><span>下書き（事務所の確認待ち）・会議録の公開を受けて自動で作成</span>
<span class="btns"><span>直す</span><span class="pub">公開する</span></span></div><div class="body">
<h3>{e(title)}</h3><div class="tags">{"".join(f"<span>{e(t)}</span>" for t in d["tags"])}</div>
<figure class="eye"><img src="{e(d["card"]["img"])}" alt="{e(d["card"]["label"])}" width="1200" height="630" loading="lazy">
<figcaption>アイキャッチ（OGP画像）：{e(d["card"]["label"])}。この記事を公式サイトからXでシェアすると、この画像が出ます。</figcaption></figure>
<p class="note">ここに事務所のコメントを書き足せます（例：この質問で伝えたかったこと）。</p>{body}
<p class="src note">出典：<a href="{e(d["url"])}" rel="nofollow noopener" target="_blank">国会会議録（{e(d["house"])} {e(d["meeting"])} {e(jdate(d["date"]))}）</a>　抜粋はそのままで、要約していません。</p>
{xbox(x, xurl)}</div></article>''')
    for r in shu:
        title = f"質問主意書「{r['title']}」と政府の答弁書"
        q = cut(re.sub(r"\s+", " ", r["q_text"]), 240)
        a = cut(re.sub(r"\s+", " ", r["a_text"]), 300)
        x = f"{jdate(r['submit_date'])}に提出した質問主意書「{r['title']}」に、{jdate(r['answer_date'])}、政府から答弁書が届きました。質問と答弁を並べて載せています。"
        xurl = f"https://kurage.exbridge.jp/kshuisho.php/q/{r['id']}"
        parts.append(f'''<article class="draft"><div class="bar"><span>下書き（事務所の確認待ち）・答弁書の受領を受けて自動で作成</span>
<span class="btns"><span>直す</span><span class="pub">公開する</span></span></div><div class="body">
<h3>{e(title)}</h3><p class="note">提出 {e(jdate(r["submit_date"]))}　答弁書の受領 {e(jdate(r["answer_date"]))}</p>
<div class="q"><span class="who">質問主意書（冒頭）</span>「{e(q)}」</div><div class="a"><span class="who">政府の答弁書（冒頭）</span>「{e(a)}」</div>
<p class="src note">出典：<a href="{e(r["q_url"])}" rel="nofollow noopener" target="_blank">質問主意書</a>・<a href="{e(r["a_url"] or "")}" rel="nofollow noopener" target="_blank">答弁書</a>（参議院）</p>
{xbox(x, xurl)}</div></article>''')
    n = len(drafts)
    return f'''<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>国会活動の下書き 見本｜{e(who)}議員</title><meta name="robots" content="noindex,nofollow">
<meta name="description" content="国会会議録や答弁書が公開されたら、議員の公式サイトに載せる投稿の下書きを自動で作る見本です。">
<style>{CSS}</style></head><body>
<header><div class="in"><a href="./"><img src="https://exbridge.jp/images/logo-mark-128.png" width="36" height="36" alt="株式会社エクスブリッジ"></a>
<a href="./">国会活動の下書き（見本）</a></div></header><main>
<p class="note">見本・{e(now)}時点</p>
<h1>{e(who)}議員の国会活動を、公式サイトの投稿の下書きにしました</h1>
<p class="lead">国会会議録に質疑が載ったら、その夜のうちに、公式サイトに載せる投稿の下書きができている。事務所の方は中身を確かめて「公開する」を押すだけ、という仕組みの見本です。
下の下書きは、{e(who)}議員の{e(frm[:4])}年{e(jdate(frm))}以降の質疑{n}回と、質問主意書から、実際に自動で組んだものです。</p>
<div class="flow"><div><b>1. 会議録の公開</b>質疑の1〜2週間後、国会会議録に載る</div><div><b>2. 下書きを自動で作成</b>質問と答弁を会議録からそのまま抜き出し、取り上げたテーマと、Xでシェアしたときに出る画像（質疑カード）を付ける</div>
<div><b>3. 事務所が確認</b>コメントを書き足し、「公開する」を押す（公式サイトとXの告知文）</div></div>
<p class="note">質問と答弁は会議録・答弁書のまま抜き出していて、要約や言い換えはしていません。勝手に公開はせず、必ず事務所の確認を通します。
公式サイトが WordPress なら、投稿の下書きとして直接入れられます。</p>
<h2>下書き（{n + len(shu)}件）</h2>
{"".join(parts)}
<h2>公式サイトに貼れるカード（取り上げてきたテーマ）</h2>
<p class="note">国会トラッカーで、{e(who)}議員がそのテーマを国会で何日・いつから取り上げてきたかを1枚にしたカードです。
政策紹介のページや、テーマごとの記事のアイキャッチに使えます。数字は会議録が増えるたびに自動で新しくなります。</p>
<div class="coll">{"".join(f'<a href="{e(c["page"])}" target="_blank" rel="noopener"><img src="{e(c["img"])}" alt="{e(c["name"])}のカード" width="1200" height="630" loading="lazy"><span>{e(c["name"])}（{c["days"]}日）</span></a>' for c in coll)}</div>
</main><script>function cp(b){{var t=b.getAttribute("data-copy");function ok(){{b.textContent="コピーしました"}}
if(navigator.clipboard&&window.isSecureContext){{navigator.clipboard.writeText(t).then(ok)}}else{{var a=document.createElement("textarea");a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");a.remove();ok()}}}}</script><footer>作成：<a href="https://exbridge.jp/#ref=giin-hp-demo">株式会社エクスブリッジ</a>（名古屋のAIシステム開発会社）。
会議録の出典は国立国会図書館 国会会議録検索システム、質問主意書は参議院・衆議院の公開ページです。</footer></body></html>'''


def deploy(token: str, body: str) -> str:
    import ftplib
    import io
    env = {}
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        m = re.match(r"(FTP_[A-Z]+)=(.*)", ln.strip())
        if m:
            env[m.group(1)] = m.group(2).strip().strip('"').strip("'")
    d = f"/web/proto_exbridge_jp/giin-hp-{token}"
    f = ftplib.FTP_TLS(env["FTP_HOST"], timeout=60)
    f.login(env["FTP_USER"], env["FTP_PASS"])
    f.prot_p()
    try:
        f.cwd(d)
    except ftplib.error_perm:
        f.mkd(d)
        f.cwd(d)
    f.storbinary("STOR index.html", io.BytesIO(body.encode("utf-8")))
    f.quit()
    return f"https://proto.exbridge.jp/giin-hp-{token}/"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--member", required=True, help="名簿の名前（空白なし。例: 伊藤孝恵）")
    ap.add_argument("--from", dest="frm", default="2026-01-01")
    ap.add_argument("--display", help="ページに出す呼び名（本人が公式サイトで使う表記。例: 伊藤たかえ）")
    ap.add_argument("--deploy", action="store_true")
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)
    tk = os.path.join(OUT, "token.json")
    tokens = json.load(open(tk, encoding="utf-8")) if os.path.exists(tk) else {}
    token = tokens.setdefault(a.member, secrets.token_hex(6))
    json.dump(tokens, open(tk, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    body = build(a.member, a.frm, a.display)
    path = os.path.join(OUT, f"{token}.html")
    open(path, "w", encoding="utf-8").write(body)
    print(path)
    if a.deploy:
        print(deploy(token, body))
    return 0


if __name__ == "__main__":
    sys.exit(main())
