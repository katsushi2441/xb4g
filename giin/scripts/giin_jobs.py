#!/usr/bin/env python3
"""愛知の国会議員 発言ログ を毎日更新する rqdb4ai ジョブ。

**なぜ要るか。** この道具は「議員が国会でいつ何を言ったか」を出す。
更新が止まった瞬間、出している内容が古いまま正しく見えてしまう。
出典を示す製品でそれをやると、根拠を示しているという前提ごと崩れる。

何をするか（この順でないと意味がない）:
  1. 国会会議録から、議員45人の新しい発言を取り込む（増分）
  2. 立場（質疑／答弁／議事整理）を分類し直す
  3. 議案と省庁の報道発表を取り込み、ことがらに割り当てる
  3.5 議員の公式YouTube・公式Xの新着（どちらもAPIキー不要）
  4. **SQLite を heteml へ送る。** ここまでやらないと公開サイトは古いまま
  5. 公開URLを実際に叩いて、更新が反映されたかを確かめる

戻り値は kdeck の契約に合わせる（controller.job_items が items を見る）:
  **公開サイトで更新が確認できたときだけ items=1。** 新しい発言が0件の日でも、
  「見張って配って確かめた」ので達成扱いにする。配布か確認で失敗したら items=0。
  enqueue できたことと、公開物が新しくなったことは別、という kdeck の決め事に従う。
"""
from __future__ import annotations

import datetime
import json
import os
import subprocess
import sys

# 設置先ごとに変わるものは環境変数で上書きできるようにする
# （配布物に特定サーバーのパスを焼き込まない）
ROOT = os.environ.get("GIIN_ROOT") or os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
# 設置先の設定は .giin_env があれば読む（配布物には入れない・この機械の値）
_envf = os.path.join(ROOT, ".giin_env")
if os.path.isfile(_envf):
    for _l in open(_envf, encoding="utf-8"):
        _l = _l.strip()
        if _l and not _l.startswith("#") and "=" in _l:
            _k, _v = _l.split("=", 1)
            os.environ.setdefault(_k.strip(), _v.strip())
SCRIPTS = os.path.join(ROOT, "scripts")
DB = os.path.join(ROOT, "data", "giin.sqlite")
REPORT = os.path.join(ROOT, "data", "update_report.json")
# FTPで配る先と、確認しにいく公開URL。FTPを使わない設置では GIIN_REMOTE_DIR を空にする
REMOTE_DIR = os.environ.get("GIIN_REMOTE_DIR", "")   # 例: /web/example_com/giin/data
PUBLIC = os.environ.get("GIIN_PUBLIC_URL", "")       # 例: https://example.com/giin/
PY = os.environ.get("GIIN_PYTHON", sys.executable or "/usr/bin/python3")


def _run(args, timeout=1800):
    r = subprocess.run(args, cwd=ROOT, capture_output=True, text=True, timeout=timeout)
    return r.returncode, (r.stdout or "")[-4000:], (r.stderr or "")[-2000:]


def _counts():
    import sqlite3
    con = sqlite3.connect(DB)
    con.execute("PRAGMA busy_timeout=5000")
    g = lambda q: con.execute(q).fetchone()[0]  # noqa: E731
    out = {
        "speech": g("SELECT COUNT(*) FROM speech"),
        "speech_q": g("SELECT COUNT(*) FROM speech WHERE kind='q'"),
        "last_speech": g("SELECT MAX(date) FROM speech"),
        "news": g("SELECT COUNT(*) FROM news"),
        "video": g("SELECT COUNT(*) FROM video") if g(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='video'") else 0,
        "xpost": g("SELECT COUNT(*) FROM xpost") if g(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='xpost'") else 0,
        "last_news": g("SELECT MAX(date) FROM news"),
    }
    con.close()
    return out


def _upload():
    """サーバーへ SQLite を送る。**送らないと公開サイトは古いまま。**
    同じ機械の中に置いている設置では GIIN_REMOTE_DIR を空にすれば飛ばす。"""
    import ftplib
    if not REMOTE_DIR:
        return True, "配布は不要（GIIN_REMOTE_DIR が空。同じ機械に置いている設置）"
    host, user, pw = (os.environ.get("FTP_HOST"), os.environ.get("FTP_USER"),
                      os.environ.get("FTP_PASS"))
    if not (host and user and pw):
        return False, "FTPの資格情報が環境にない（aixec/.env を読んでいるか）"
    try:
        f = ftplib.FTP(host, timeout=300)
        f.login(user, pw)
        f.cwd(REMOTE_DIR)
        with open(DB, "rb") as fp:
            f.storbinary("STOR giin.sqlite", fp, blocksize=65536)
        try:
            f.sendcmd("SITE CHMOD 666 giin.sqlite")
        except Exception:  # noqa: BLE001
            pass
        f.quit()
        return True, ""
    except Exception as e:  # noqa: BLE001
        return False, str(e)[:200]


def _verify(expect_speech: int):
    """公開ページを実際に読み、件数が反映されているか確かめる。"""
    if not PUBLIC:
        return True, "確認先が未設定（GIIN_PUBLIC_URL が空）"
    try:
        r = subprocess.run(["curl", "-s", "--max-time", "30", PUBLIC],
                           capture_output=True, text=True, timeout=45)
        html = r.stdout or ""
    except Exception as e:  # noqa: BLE001
        return False, f"取得できず: {e}"
    if "<b>" not in html:
        return False, "トップの内容が想定と違う"
    import re
    m = re.search(r"<b>([\d,]+)件</b>の質疑", html)
    if not m:
        return False, "件数の表記を見つけられない"
    shown = int(m.group(1).replace(",", ""))
    if shown != expect_speech:
        return False, f"公開側 {shown}件 / 手元 {expect_speech}件 で食い違う"
    return True, f"公開側も {shown}件"


def update_giin_job(days_news: int = 120, **_) -> dict:
    """kdeck から呼ばれる入口。"""
    started = datetime.datetime.now().isoformat(timespec="seconds")
    before = _counts()
    steps = []

    for name, args in [
        ("発言の増分", [PY, os.path.join(SCRIPTS, "fetch_speeches.py"), "--since-last"]),
        ("立場の分類", [PY, os.path.join(SCRIPTS, "classify.py")]),
        ("議案と報道発表", [PY, os.path.join(SCRIPTS, "fetch_news.py"), "--days", str(days_news)]),
        ("公式YouTubeの新着", [PY, os.path.join(SCRIPTS, "fetch_youtube.py")]),
        ("公式Xの新着", [PY, os.path.join(SCRIPTS, "fetch_x.py")]),
    ]:
        code, out, err = _run(args)
        steps.append({"step": name, "code": code, "tail": out.strip().splitlines()[-3:],
                      "err": err.strip().splitlines()[-3:] if code else []})
        if code != 0:
            report = {"at": started, "ok": False, "failed": name, "steps": steps}
            json.dump(report, open(REPORT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
            return {"ok": False, "items": 0, "failed": name, "stderr": err[-400:]}

    after = _counts()
    sent, msg = _upload()
    verified, vmsg = (False, "配布していないので確認しない")
    if sent:
        verified, vmsg = _verify(after["speech_q"])

    report = {"at": started, "before": before, "after": after,
              "uploaded": sent, "upload_msg": msg,
              "verified": verified, "verify_msg": vmsg, "steps": steps}
    json.dump(report, open(REPORT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    # **公開側で確認できたときだけ達成。** 増えた件数が0でも、見張って配って
    # 確かめたなら仕事はしている（でないと max_runs_per_day まで無駄に走る）。
    ok = sent and verified
    return {"ok": ok, "items": 1 if ok else 0,
            "new_speech": after["speech"] - before["speech"],
            "new_news": after["news"] - before["news"],
            "speech_total": after["speech"], "q_total": after["speech_q"],
            "last_speech": after["last_speech"], "last_news": after["last_news"],
            "uploaded": sent, "verified": verified, "verify": vmsg,
            "report": REPORT}


if __name__ == "__main__":
    print(json.dumps(update_giin_job(), ensure_ascii=False, indent=1))
