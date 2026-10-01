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
    return {"ok": ok, "items": 1 if ok else 0, "stamp": stamp, "cards": n, "steps": steps}


if __name__ == "__main__":
    print(json.dumps(run_x_reply_job(), ensure_ascii=False, indent=1))
