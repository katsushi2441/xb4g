#!/usr/bin/env python3
"""kappstore で売る2つの配布物を作る（毎回同じ中身になるように、ここでファイルを決める）。

  outputs/giin-<版>.zip            Kurage 議員発言ログ（議員・ことがら・法案。国会トラッカーは入れない）
  outputs/kokkai-tracker-<版>.zip  Kurage 国会トラッカー（テーマごとの全国の質疑と答弁・質疑カード）

**同じ中身を別の名前で2つ売らない。** 共通なのは画面の土台（lib/db・text・ui）と会議録APIの部品（ndl・classify）だけ。
当社の公開先だけで動かしているもの（選挙区・X・動画・AI考察・質問主意書との連携）は、どちらにも入れない。

  /usr/bin/python3 scripts/make_store_zips.py
"""
import json
import os
import re
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "outputs")
GIIN_VER = "1.1.0"
TRACKER_VER = "1.0.0"

COMMON = ["lib/db.php", "lib/text.php", "lib/ui.php", "scripts/ndl.py", "scripts/classify.py"]

GIIN = COMMON + [
    "index.php", "sitemap.php", "giin_mcp.php", ".htaccess", "giin_config.php.example", "ogp.png",
    "lib/themes.php", "lib/trackers.php", "lib/bills.php", "lib/senkyoku.php",
    "data/themes.json", "data/links.json", "data/stats.json",
    "scripts/build_roster.py", "scripts/romaji.py", "scripts/fetch_speeches.py", "scripts/fetch_news.py",
    "scripts/fetch_bills.py", "scripts/fetch_committees.py", "scripts/build_theme_index.py",
    "scripts/build_uniq_terms.py", "scripts/make_uniq_cards.py",
    "scripts/make_ogp.py", "scripts/make_ogp_all.py", "scripts/make_ogp_trackers.py",
    "scripts/deploy.py", "scripts/giin_jobs.py",
]

TRACKER = COMMON + [
    "lib/trackers.php", "lib/tracker_pages.php", "lib/qa.php",
    "fonts/NotoSansJP-Black-sub.otf", "fonts/NotoSansJP-Bold-sub.otf", "fonts/NotoSansJP-Regular-sub.otf",
    "scripts/fetch_tracker.py", "scripts/make_ogp_all.py", "scripts/make_ogp_trackers.py",
    "scripts/make_card_fonts.py", "scripts/tracker_jobs.py",
]

# 単体の見本に入れるテーマ。当社の公開先だけの欄（動画・ことがら・次に見る）は外す
SAMPLE_KEYS = ["naimitsu-shussan", "otc-ruiji-yaku", "futoko"]
DROP_KEYS = {"video", "theme", "next", "seo_volume"}

TRACKER_HTACCESS = """# PHP 8 で動かす（レンタルサーバーの既定が古いときは、そのサーバーの書き方に合わせる）
AddCharset UTF-8 .php

<IfModule mod_rewrite.c>
RewriteEngine On
# 置いた場所に合わせる（例: /kokkai/ に置くなら RewriteBase /kokkai/）
RewriteBase /
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^ index.php [L]
</IfModule>

# SQLite・スクリプト・フォントは配らない
<FilesMatch "\\.(sqlite|sqlite-journal|json|py|log|otf)$">
  Require all denied
</FilesMatch>
"""

TRACKER_CONFIG = """<?php
// tracker_config.php として置く（配布物の既定値を上書きする）
define('G_BASE', '');                          // 置いたパス。ドメインの直下なら ''、/kokkai/ なら '/kokkai'
define('G_SITE', '国会トラッカー');            // サイト名
define('G_HOST', 'https://example.com');       // 公開URLのスキームとホスト
define('G_GA4', '');                           // Google アナリティクスの測定ID（空なら計測タグを出さない）
"""


def giin_readme() -> str:
    """リポジトリの README から、国会トラッカーと当社だけの節（AI考察・この議員だけ…）を外す"""
    s = open(os.path.join(ROOT, "README.md"), encoding="utf-8").read()
    for head in ("## 国会トラッカー", "## 活動の要約"):
        s = re.sub(r"\n" + re.escape(head) + r".*?(?=\n## )", "\n", s, flags=re.S)
    s = "\n".join(l for l in s.split("\n") if not re.search(r"fetch_tracker|trackers\.json|build_insight|国会トラッカー", l))
    s += ("\n\n## 国会トラッカーについて\n\nテーマごとに全国の国会議員の質疑と政府の答弁を追う「国会トラッカー」は、"
          "別の商品（Kurage 国会トラッカー）です。この配布物には入っていません。`lib/trackers.php` は画面の部品として残してあり、"
          "`data/trackers.json` が空（`[]`）のあいだは何も表示しません。\n")
    return s


def write_zip(path: str, files: list, extra: dict) -> None:
    os.makedirs(OUT, exist_ok=True)
    top = os.path.splitext(os.path.basename(path))[0]
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        for rel in files:
            z.write(os.path.join(ROOT, rel), f"{top}/{rel}")
        for rel, body in extra.items():
            z.writestr(f"{top}/{rel}", body)
    n = len(files) + len(extra)
    print(f"{path}  {n}ファイル  {os.path.getsize(path) / 1024:.0f}KB")


def main():
    for rel in set(GIIN + TRACKER):
        if not os.path.exists(os.path.join(ROOT, rel)):
            raise SystemExit(f"無い: {rel}")

    write_zip(os.path.join(OUT, f"giin-{GIIN_VER}.zip"), GIIN, {
        "README.md": giin_readme(),
        "data/trackers.json": "[]\n",
        "LICENSE": MIT,
    })

    allt = json.load(open(os.path.join(ROOT, "data", "trackers.json"), encoding="utf-8"))
    sample = [{k: v for k, v in t.items() if k not in DROP_KEYS} for t in allt if t["key"] in SAMPLE_KEYS]
    if len(sample) != len(SAMPLE_KEYS):
        raise SystemExit("見本のテーマが trackers.json に見つからない")
    write_zip(os.path.join(OUT, f"kokkai-tracker-{TRACKER_VER}.zip"), TRACKER, {
        "index.php": open(os.path.join(ROOT, "tracker_index.php"), encoding="utf-8").read(),
        ".htaccess": TRACKER_HTACCESS,
        "tracker_config.php.example": TRACKER_CONFIG,
        "data/trackers.json": json.dumps(sample, ensure_ascii=False, indent=1) + "\n",
        "README.md": open(os.path.join(ROOT, "docs", "README_tracker.md"), encoding="utf-8").read(),
        "LICENSE": MIT,
    })


MIT = """MIT License

Copyright (c) 2026 Exbridge Inc.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
"""

if __name__ == "__main__":
    main()
