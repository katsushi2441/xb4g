#!/usr/bin/env python3
"""X の返信候補（自社用）を kdeck から1時間ごとに作り直す入口。

  collect（直近40分の投稿）→ pick（jevlocal で分野）→ draft（codex gpt-6-sol で返信文・proto.exbridge.jp へ配置）
公開ページ（proto.exbridge.jp/xreply-<token>/）が今回の時刻に置き換わったのを確かめたときだけ items=1。
これは社内の作業用で、giin の配布物（giin_jobs.py）には入れない。
"""
from __future__ import annotations

import datetime
import json
import os
import re
import subprocess
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCRIPTS = os.path.join(ROOT, "scripts")
PY = "/usr/bin/python3"
OUT = os.path.join(ROOT, "outputs", "x_reply_pick")


def _run(args, timeout):
    r = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    return r.returncode, (r.stdout or "")[-2000:], (r.stderr or "")[-1500:]


def run_x_reply_job(within: int = 40, **_) -> dict:
    steps = []
    for name, args, to in [
        ("収集", [PY, os.path.join(SCRIPTS, "x_reply_pick.py"), "collect", "--within", str(within)], 1200),
        ("判定", [PY, os.path.join(SCRIPTS, "x_reply_pick.py"), "pick"], 900),
        ("返信文と配置", [PY, os.path.join(SCRIPTS, "x_reply_draft.py"), "--deploy"], 1800),
    ]:
        code, out, err = _run(args, to)
        steps.append({"step": name, "code": code, "tail": out.strip().splitlines()[-2:]})
        if code != 0:
            return {"ok": False, "items": 0, "failed": name, "stderr": err[-600:], "steps": steps}
    # 公開ページが今回の回に置き換わったか（title の時分）
    token = open(os.path.join(OUT, ".token")).read().strip()
    day = datetime.date.today().isoformat()
    stamp = sorted(f for f in os.listdir(os.path.join(OUT, day)) if f.startswith("reply-"))[-1][6:-5]
    html = urllib.request.urlopen(f"https://proto.exbridge.jp/xreply-{token}/", timeout=30).read().decode("utf-8", "ignore")
    ok = f"X 返信候補 {day} {stamp}" in html
    n = len(re.findall(r"<article>", html))
    mailed = notify_mail(day, stamp, f"https://proto.exbridge.jp/xreply-{token}/") if ok else "skip (page not updated)"
    return {"ok": ok, "items": 1 if ok else 0, "stamp": stamp, "cards": n, "mailed": mailed, "steps": steps}


MAIL_TO = "katsushi2441@gmail.com"


def notify_mail(day, stamp, page_url):
    """候補ができた回だけ、候補と文案と「文入りで返信画面を開く」リンクをメールで送る（2026-10-02 ユーザー指示）。
    0件の回（深夜など）は送らない。送信は katsushi2441@gmail.com の Gmail SMTP（aixec/.env の GMAIL_APP_PASSWORD）。"""
    import smtplib
    from email.mime.text import MIMEText
    from email.header import Header
    p = os.path.join(OUT, day, f"reply-{stamp}.json")
    if not os.path.exists(p):
        return "skip (no summary)"
    cards = json.load(open(p, encoding="utf-8"))
    if not cards:
        return "skip (0 cards)"
    pw = ""
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("GMAIL_APP_PASSWORD="):
            pw = ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
    if not pw:
        return "skip (no app password)"
    lines = [f"X 返信候補 {day} {stamp[:2]}:{stamp[2:]} ／ {len(cards)}件（表示の多い順）", "", f"一覧ページ: {page_url}", ""]
    for i, c in enumerate(cards, 1):
        lines += [f"■{i}. {'【議員】' if c.get('politician') else ''}{c.get('name')} @{c.get('screen_name')}（表示{(c.get('views') or 0):,}）",
                  f"元の投稿: {c.get('url')}", f"内容: {c.get('text')}", f"リンク先: {c.get('label')}", ""]
        for j, (d, u) in enumerate(zip(c.get("drafts") or [], c.get("intents") or []), 1):
            lines += [f"［案{j}］", d, f"→ 文入りで返信画面を開く: {u}", ""]
        lines.append("")
    lines.append("投稿は人が確かめてから行ってください。このメールは kdeck の giin-x-reply-candidates から自動で送っています。")
    msg = MIMEText("\n".join(lines), "plain", "utf-8")
    msg["Subject"] = Header(f"X返信候補 {stamp[:2]}:{stamp[2:]} {len(cards)}件", "utf-8")
    msg["From"] = MAIL_TO
    msg["To"] = MAIL_TO
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465, timeout=30) as sm:
            sm.login(MAIL_TO, pw)
            sm.sendmail(MAIL_TO, [MAIL_TO], msg.as_string())
        return f"sent {len(cards)}"
    except Exception as e:  # noqa: BLE001
        return f"error {type(e).__name__}"


if __name__ == "__main__":
    print(json.dumps(run_x_reply_job(), ensure_ascii=False, indent=1))
