#!/usr/bin/env python3
"""kdeck から呼ばれる Kurage 省庁トラッカー（exbridge.jp/shocho/）の日次更新（2026-10-23）。

1. kpayload/scripts/shocho-fetch.py  各府省の RSS を取り込み、国会トラッカーと結び付ける
2. kpayload/scripts/build-shocho.ts  exbridge_jp/shocho/ のページを作る
3. kpayload/scripts/deploy.sh shocho ページとカード画像を送る（変わったものだけ・1接続）
4. giin の data/shocho_links.json を送る（国会トラッカーの「省庁の新しい発表」欄）

どこかで落ちたら items=0 で返す（前の日のページはそのまま残る）。
"""
import datetime
import json
import os
import subprocess

KP = "/home/kojima/work/kpayload"
GIIN = "/home/kojima/work/xb4g/giin"
PY = "/usr/bin/python3"
NODE_BIN = "/home/kojima/.nvm/versions/node/v20.20.2/bin"


def _env() -> dict:
    env = dict(os.environ)
    env["PATH"] = NODE_BIN + ":" + env.get("PATH", "/usr/bin:/bin")
    path = "/home/kojima/work/aixec/.env"
    if os.path.isfile(path):
        for line in open(path, encoding="utf-8", errors="ignore"):
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                env.setdefault(k.strip(), v.strip().strip('"').strip("'"))
    return env


def _run(cmd: list, cwd: str, env: dict, timeout: int) -> tuple[bool, list]:
    r = subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True, timeout=timeout)
    tail = ((r.stdout or "") + (r.stderr or "")).strip().splitlines()[-4:]
    return r.returncode == 0, tail


def run_shocho_update_job(**_) -> dict:
    started = datetime.datetime.now().isoformat(timespec="seconds")
    env = _env()
    steps = []
    for name, cmd, cwd, to in [
        ("fetch", [PY, "scripts/shocho-fetch.py"], KP, 900),
        ("build", [NODE_BIN + "/npx", "tsx", "scripts/build-shocho.ts"], KP, 900),
        ("deploy", ["bash", "scripts/deploy.sh", "shocho"], KP, 2400),
        ("giin", [PY, "scripts/deploy.py", "--only", "data/shocho_links.json"], GIIN, 600),
    ]:
        ok, tail = _run(cmd, cwd, env, to)
        steps.append({"step": name, "ok": ok, "tail": tail})
        if not ok:
            break
    ok = all(s["ok"] for s in steps) and len(steps) == 4
    n = 0
    try:
        n = len(json.load(open(os.path.join(KP, "data", "shocho", "items.json"))))
    except Exception:  # noqa: BLE001
        pass
    return {"ok": ok, "items": 1 if ok else 0, "started": started,
            "finished": datetime.datetime.now().isoformat(timespec="seconds"), "total_items": n, "steps": steps}


if __name__ == "__main__":
    print(json.dumps(run_shocho_update_job(), ensure_ascii=False, indent=1))
