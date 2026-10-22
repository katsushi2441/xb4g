"""サーバーに溜まったカード画像のキャッシュ（data/cache/<種類>/）を消す。

カードの見た目（lib/card.php）を変えたときに使う。消したカードは、次に見られたときに描き直される。

    python3 scripts/purge_card_cache.py            # 全部の種類
    python3 scripts/purge_card_cache.py qa giin    # 種類を選んで
"""
import ftplib
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from deploy import REMOTE, _load_env  # noqa: E402


def main() -> int:
    _load_env()
    f = ftplib.FTP(os.environ["FTP_HOST"], timeout=300)
    f.login(os.environ["FTP_USER"], os.environ["FTP_PASS"])
    base = REMOTE + "/data/cache"
    kinds = sys.argv[1:] or [os.path.basename(p) for p in f.nlst(base) if not p.endswith((".", ".."))]
    total = 0
    for k in kinds:
        d = base + "/" + k
        try:
            names = [n for n in f.nlst(d) if not n.endswith(("/.", "/.."))]
        except ftplib.error_perm:
            continue
        for n in names:
            try:
                f.delete(n if n.startswith("/") else d + "/" + os.path.basename(n))
                total += 1
            except ftplib.error_perm:
                pass
        print(f"  {k}: {len(names)}個")
    f.quit()
    print(f"{total}個を消しました")
    return 0


if __name__ == "__main__":
    sys.exit(main())
