#!/usr/bin/env python3
"""Kurage 国会トラッカーを毎日更新するジョブ（cron でも rqdb4ai でも呼べる）。

  1. 会議録から、テーマごとの発言を取り込む（増分。語や開始日を変えたテーマだけ全部取り直す）
  2. テーマごとの OGP 画像を作り直す（件数が毎日変わるので）
  3. SQLite と画像をサーバーへ送る（同じ機械に置いている設置では送らない）
  4. 公開ページを実際に読み、件数が反映されたか確かめる

**公開側で確認できたときだけ成功。** 手元のデータを更新しただけでは公開サイトは古いまま。

設定は .tracker_env（KEY=VALUE、配布物には入れない）か環境変数で:
  TRACKER_PUBLIC_URL  公開URL（例: https://example.com/kokkai/）。空なら確認を飛ばす
  TRACKER_REMOTE_DIR  FTPで置く先（例: /web/example_com/kokkai）。空なら送らない
  FTP_HOST / FTP_USER / FTP_PASS

  /usr/bin/python3 scripts/tracker_jobs.py
"""
from __future__ import annotations

import datetime
import json
import os
import re
import sqlite3
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
_envf = os.path.join(ROOT, ".tracker_env")
if os.path.isfile(_envf):
    for _l in open(_envf, encoding="utf-8"):
        _l = _l.strip()
        if _l and not _l.startswith("#") and "=" in _l:
            _k, _v = _l.split("=", 1)
            os.environ.setdefault(_k.strip(), _v.strip())
SCRIPTS = os.path.join(ROOT, "scripts")
DB = os.path.join(ROOT, "data", "giin.sqlite")
PUBLIC = os.environ.get("TRACKER_PUBLIC_URL", "")
REMOTE = os.environ.get("TRACKER_REMOTE_DIR", "")
PY = sys.executable or "/usr/bin/python3"


def _run(args, timeout=3600):
    r = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    return r.returncode, (r.stdout or "")[-3000:], (r.stderr or "")[-2000:]


def _upload() -> tuple[bool, str]:
    if not REMOTE:
        return True, "送らない（TRACKER_REMOTE_DIR が空）"
    import ftplib
    host, user, pw = os.environ.get("FTP_HOST"), os.environ.get("FTP_USER"), os.environ.get("FTP_PASS")
    if not (host and user and pw):
        return False, "FTP_HOST / FTP_USER / FTP_PASS が無い"
    try:
        f = ftplib.FTP(host, timeout=300)
        f.login(user, pw)
        f.cwd(REMOTE.rstrip("/") + "/data")
        with open(DB, "rb") as fp:
            f.storbinary("STOR giin.sqlite", fp, blocksize=65536)
        f.cwd(REMOTE.rstrip("/") + "/img/og")
        og = os.path.join(ROOT, "img", "og")
        for fn in sorted(os.listdir(og)):
            if fn.startswith("tracker-") and fn.endswith(".png"):
                with open(os.path.join(og, fn), "rb") as fp:
                    f.storbinary("STOR " + fn, fp, blocksize=65536)
        f.quit()
        return True, ""
    except Exception as e:  # noqa: BLE001
        return False, str(e)[:200]


def _verify(expect_q: int) -> tuple[bool, str]:
    if not PUBLIC:
        return True, "確認先が未設定（TRACKER_PUBLIC_URL が空）"
    try:
        with urllib.request.urlopen(urllib.request.Request(PUBLIC.rstrip("/") + "/tracker",
                                    headers={"User-Agent": "kokkai-tracker"}), timeout=30) as r:
            html = r.read().decode("utf-8", "replace")
    except Exception as e:  # noqa: BLE001
        return False, f"取得できず: {e}"
    m = re.search(r"<b>([\d,]+)</b><span>全国の議員の質疑", html)
    if not m:
        return False, "件数の表記を見つけられない"
    shown = int(m.group(1).replace(",", ""))
    return (shown == expect_q), f"公開側 {shown}件 / 手元 {expect_q}件"


def update_tracker_job(**_) -> dict:
    started = datetime.datetime.now().isoformat(timespec="seconds")
    steps = []
    for name, args in [("会議録の取り込み", [PY, os.path.join(SCRIPTS, "fetch_tracker.py")]),
                       ("OGP画像", [PY, os.path.join(SCRIPTS, "make_ogp_trackers.py")])]:
        code, out, err = _run(args)
        steps.append({"step": name, "code": code, "tail": out.strip().splitlines()[-3:]})
        if code != 0:
            return {"ok": False, "items": 0, "failed": name, "stderr": err[-400:], "at": started}
    con = sqlite3.connect(DB)
    q = con.execute("SELECT COUNT(*) FROM tracker_speech WHERE kind='q'").fetchone()[0]
    con.close()
    sent, smsg = _upload()
    ok, vmsg = _verify(q) if sent else (False, "送れなかったので確認しない")
    return {"ok": sent and ok, "items": 1 if sent and ok else 0, "q_total": q,
            "upload": smsg, "verify": vmsg, "steps": steps, "at": started}


if __name__ == "__main__":
    print(json.dumps(update_tracker_job(), ensure_ascii=False, indent=1))
