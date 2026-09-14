#!/usr/bin/env python3
"""画面のファイル（PHP・CSS・画像）をサーバーへ配る。

DB（data/giin.sqlite）は毎日の更新ジョブ（giin_jobs.py）が別に送っているので、
ここでは既定で送らない。--db を付けたときだけ一緒に送る。

    python3 scripts/deploy.py            # PHPと画像だけ
    python3 scripts/deploy.py --db       # DBも
    python3 scripts/deploy.py --only img/og   # 一部だけ
"""
import argparse
import ftplib
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REMOTE = os.environ.get("GIIN_REMOTE_ROOT", "/web/xb4g_com/giin")

# 配るもの。giin_config.php は server 側の設定なので**送らない**（上書きすると壊れる）
TARGETS = ["index.php", "sitemap.php", "giin_mcp.php", "ogp.png", "lib", "img", "data/themes.json"]
SKIP_NAMES = {"giin_config.php", "giin_config.php.example", "giin.sqlite", ".DS_Store"}


def _load_env() -> None:
    path = "/home/kojima/work/aixec/.env"
    if not os.path.isfile(path):
        return
    for line in open(path, encoding="utf-8", errors="ignore"):
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


def _walk(rel: str):
    """配る相対パスを列挙する。"""
    full = os.path.join(ROOT, rel)
    if os.path.isfile(full):
        yield rel
        return
    for dirpath, dirnames, filenames in os.walk(full):
        dirnames[:] = [d for d in dirnames if d not in ("__pycache__", ".git")]
        for fn in sorted(filenames):
            if fn in SKIP_NAMES or fn.endswith(".pyc"):
                continue
            yield os.path.relpath(os.path.join(dirpath, fn), ROOT)


def _ensure(f: ftplib.FTP, remote_dir: str) -> None:
    """無い階層を作りながら降りる。"""
    f.cwd(REMOTE)
    for part in remote_dir.split("/"):
        if not part:
            continue
        try:
            f.cwd(part)
        except ftplib.error_perm:
            f.mkd(part)
            f.cwd(part)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", action="store_true", help="DBも一緒に送る")
    ap.add_argument("--only", nargs="*", help="この相対パスだけ送る")
    a = ap.parse_args()

    _load_env()
    host, user, pw = os.environ.get("FTP_HOST"), os.environ.get("FTP_USER"), os.environ.get("FTP_PASS")
    if not (host and user and pw):
        print("FTPの資格情報が環境にない（aixec/.env を読んでいるか）", file=sys.stderr)
        return 1

    targets = a.only if a.only else list(TARGETS)
    if a.db:
        targets.append("data/giin.sqlite")

    files = []
    for t in targets:
        if not os.path.exists(os.path.join(ROOT, t)):
            print(f"  無い: {t}", file=sys.stderr)
            continue
        files.extend(_walk(t))

    f = ftplib.FTP(host, timeout=300)
    f.login(user, pw)
    sent = 0
    total = 0
    for rel in files:
        local = os.path.join(ROOT, rel)
        remote_dir, name = os.path.split(rel)
        _ensure(f, remote_dir)
        with open(local, "rb") as fp:
            f.storbinary("STOR " + name, fp, blocksize=65536)
        size = os.path.getsize(local)
        sent += 1
        total += size
        print(f"  {rel}  {size/1024:.0f}KB")
    f.quit()
    print(f"\n{sent}個 / {total/1024/1024:.1f}MB を {REMOTE} へ配りました")
    return 0


if __name__ == "__main__":
    sys.exit(main())
