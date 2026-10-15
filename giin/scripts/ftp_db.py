#!/usr/bin/env python3
"""SQLite をサーバーへ送る（deploy.py --db と giin_jobs.py の配布で共用）。

**本物の名前へ直接 STOR しない。** 352MB で2分弱かかる（2026-10-15 実測112秒）。その間、画面は
書きかけのファイルを開くので、壊れたDBを読んだり件数が0に見えたりする。全国の議員を足すと倍近くになる。
いったん giin.sqlite.uploading に送り、大きさが合うのを確かめてから RNFR/RNTO で入れ替える
（同じディレクトリ内の名前の付け替えなので、画面からは古いか新しいかのどちらかしか見えない）。
"""
import ftplib
import os
import sqlite3


def snapshot(local: str) -> str:
    """**書き込み中のDBをそのまま送らない。** 全国の発言の取り込み・X監視・トラッカーが同じファイルに書くので、
    コピーの途中でコミットが入ると壊れたファイルが届く。SQLite のバックアップ機能で、ある時点の一貫した写しを作って送る"""
    snap = local + ".upload"
    if os.path.exists(snap):
        os.remove(snap)
    src = sqlite3.connect(local, timeout=120)
    src.execute("PRAGMA busy_timeout=120000")
    dst = sqlite3.connect(snap)
    src.backup(dst)
    dst.execute("PRAGMA journal_mode=DELETE")
    dst.close()
    src.close()
    return snap


def upload_db(f: ftplib.FTP, local: str, remote_dir: str, name: str = "giin.sqlite") -> int:
    """local の一貫した写しを remote_dir/name へ入れ替えで置く。送った大きさを返す。"""
    tmp = name + ".uploading"
    f.cwd(remote_dir)
    snap = snapshot(local)
    try:
        with open(snap, "rb") as fp:
            f.storbinary("STOR " + tmp, fp, blocksize=262144)
        size = os.path.getsize(snap)
    finally:
        os.remove(snap)
    try:
        f.voidcmd("TYPE I")
        got = f.size(tmp)
    except ftplib.all_errors:
        got = None
    if got is not None and got != size:
        try:
            f.delete(tmp)
        except ftplib.all_errors:
            pass
        raise RuntimeError(f"送った大きさが合わない（手元 {size} / サーバー {got}）。入れ替えずに止めた")
    f.rename(tmp, name)   # 既にある name は置き換わる
    try:
        f.sendcmd("SITE CHMOD 666 " + name)
    except ftplib.all_errors:
        pass
    return size
