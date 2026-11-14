#!/usr/bin/env python3
"""fact/out/ を xb4g.com/fact/ へ置く（FTP は1接続）。/fact/ の外には書かない。"""
import ftplib, os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REMOTE = "/web/xb4g_com/fact"
for ln in open('/home/kojima/work/aixec/.env', encoding='utf-8'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
f = ftplib.FTP(os.environ['FTP_HOST'], timeout=120); f.login(os.environ['FTP_USER'], os.environ['FTP_PASS'])


def ensure(d):
    try:
        f.mkd(d)
    except ftplib.error_perm:
        pass


ensure(REMOTE)
n = 0
for p in sorted((ROOT / "out").rglob("*")):
    rel = p.relative_to(ROOT / "out").as_posix()
    if p.is_dir():
        ensure(f"{REMOTE}/{rel}"); continue
    with open(p, 'rb') as fh:
        f.storbinary(f"STOR {REMOTE}/{rel}", fh)
    n += 1
f.quit()
print("uploaded", n, "files →", REMOTE)
