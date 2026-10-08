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
            mailed = notify_status(f"失敗（{name}）", steps, err[-600:])
            return {"ok": False, "items": 0, "failed": name, "stderr": err[-600:], "steps": steps, "mailed": mailed}
    # 公開ページが今回の回に置き換わったか（title の時分）
    token = open(os.path.join(OUT, ".token")).read().strip()
    day = datetime.date.today().isoformat()
    stamp = sorted(f for f in os.listdir(os.path.join(OUT, day)) if f.startswith("reply-"))[-1][6:-5]
    html = urllib.request.urlopen(f"https://proto.exbridge.jp/xreply-{token}/", timeout=30).read().decode("utf-8", "ignore")
    ok = f"X 返信候補 {day} {stamp}" in html
    n = len(re.findall(r"<article>", html))
    if ok:
        mailed = notify_mail(day, stamp, f"https://proto.exbridge.jp/xreply-{token}/", steps)
    else:
        mailed = notify_status("失敗（公開ページが今回の時刻に置き換わっていない）", steps)
    return {"ok": ok, "items": 1 if ok else 0, "stamp": stamp, "cards": n, "mailed": mailed, "steps": steps}


MAIL_TO = "katsushi2441@gmail.com"


def _send(subject, body):
    """送信は katsushi2441@gmail.com の Gmail SMTP（aixec/.env の GMAIL_APP_PASSWORD）"""
    import smtplib
    from email.mime.text import MIMEText
    from email.header import Header
    pw = ""
    for ln in open("/home/kojima/work/aixec/.env", encoding="utf-8"):
        if ln.startswith("GMAIL_APP_PASSWORD="):
            pw = ln.split("=", 1)[1].strip().strip("\"'").replace(" ", "")
    if not pw:
        return "skip (no app password)"
    msg = MIMEText(body, "plain", "utf-8")
    msg["Subject"] = Header(subject, "utf-8")
    msg["From"] = MAIL_TO
    msg["To"] = MAIL_TO
    try:
        with smtplib.SMTP_SSL("smtp.gmail.com", 465, timeout=30) as sm:
            sm.login(MAIL_TO, pw)
            sm.sendmail(MAIL_TO, [MAIL_TO], msg.as_string())
        return "sent"
    except Exception as e:  # noqa: BLE001
        return f"error {type(e).__name__}"


def _steps_text(steps):
    return [f"・{st['step']}: {'OK' if st['code'] == 0 else '失敗 code=' + str(st['code'])}　{' / '.join(st.get('tail') or [])[:300]}"
            for st in steps]


def notify_status(what, steps, err=""):
    """失敗した回もメールで知らせる。黙って止まると、届かないことでしか気づけない（2026-10-09 ユーザー指示）"""
    now = datetime.datetime.now().strftime("%H:%M")
    body = [f"X 返信候補 {now} の回は{what}でした。", ""] + _steps_text(steps)
    if err:
        body += ["", "エラーの末尾:", err]
    body += ["", "このメールは kdeck の giin-x-reply-candidates から自動で送っています。"]
    return _send(f"X返信候補 {now} {what}", "\n".join(body))


def notify_mail(day, stamp, page_url, steps=None):
    """毎回メールで送る。候補があれば候補と文案と「文入りで返信画面を開く」リンク（2026-10-02 ユーザー指示）。
    0件の回も「0件」と、集めた投稿数などの内訳を送る（2026-10-09 ユーザー指示。送らないと止まったのか0件なのか分からない）。"""
    p = os.path.join(OUT, day, f"reply-{stamp}.json")
    cards = json.load(open(p, encoding="utf-8")) if os.path.exists(p) else []
    if not cards:
        body = [f"X 返信候補 {day} {stamp[:2]}:{stamp[2:]} ／ 0件", "",
                "今回は返信候補がありませんでした（処理は最後まで動いています）。内訳:"] + _steps_text(steps or []) + [
                "", f"一覧ページ: {page_url}", "", "このメールは kdeck の giin-x-reply-candidates から自動で送っています。"]
        r = _send(f"X返信候補 {stamp[:2]}:{stamp[2:]} 0件", "\n".join(body))
        return f"{r} 0"
    lines = [f"X 返信候補 {day} {stamp[:2]}:{stamp[2:]} ／ {len(cards)}件（表示の多い順）", "", f"一覧ページ: {page_url}", ""]
    for i, c in enumerate(cards, 1):
        lines += [f"■{i}. {'【議員】' if c.get('politician') else ''}{c.get('name')} @{c.get('screen_name')}（表示{(c.get('views') or 0):,}）",
                  f"元の投稿: {c.get('url')}", f"内容: {c.get('text')}", f"リンク先: {c.get('label')}", ""]
        for j, (d, u) in enumerate(zip(c.get("drafts") or [], c.get("intents") or []), 1):
            lines += [f"［案{j}］", d, f"→ 文入りで返信画面を開く: {u}", ""]
        lines.append("")
    lines.append("投稿は人が確かめてから行ってください。このメールは kdeck の giin-x-reply-candidates から自動で送っています。")
    r = _send(f"X返信候補 {stamp[:2]}:{stamp[2:]} {len(cards)}件", "\n".join(lines))
    return f"{r} {len(cards)}"


if __name__ == "__main__":
    print(json.dumps(run_x_reply_job(), ensure_ascii=False, indent=1))
