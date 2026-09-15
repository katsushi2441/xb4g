#!/usr/bin/env python3
"""検索からの流入を定点観測する。**待つだけにしないための道具。**

公開直後は「インデックスされたか」を見たくなるが、それは途中経過でしかない。
知りたいのは「議員名で検索した人が実際に来ているか」なので、
Search Console の実測（表示・クリック・順位・クエリ）を日付つきで貯める。

    python3 scripts/watch_search.py            # 記録して差分を出す
    python3 scripts/watch_search.py --show     # 貯めた記録を並べる

認証は ADC（gcloud auth application-default）。読み取りのみ。
"""
import argparse
import datetime
import json
import os
import subprocess
import sys
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "outputs", "search_watch.json")
SITE = os.environ.get("GIIN_GSC_SITE", "https://xb4g.com/")
PREFIX = os.environ.get("GIIN_GSC_PREFIX", "https://xb4g.com/giin/")
API = "https://searchconsole.googleapis.com/webmasters/v3"


def _auth():
    token = subprocess.run(
        ["gcloud", "auth", "application-default", "print-access-token"],
        capture_output=True, text=True, check=True).stdout.strip()
    adc = os.path.expanduser("~/.config/gcloud/application_default_credentials.json")
    qp = json.load(open(adc)).get("quota_project_id", "")
    return token, qp


def query(dims, days=28, rows=200):
    tok, qp = _auth()
    end = datetime.date.today() - datetime.timedelta(days=2)   # GSCは2日ほど遅れる
    start = end - datetime.timedelta(days=days)
    body = {"startDate": start.isoformat(), "endDate": end.isoformat(),
            "dimensions": dims, "rowLimit": rows,
            "dimensionFilterGroups": [{"filters": [
                {"dimension": "page", "operator": "contains", "expression": "/giin/"}]}]}
    u = f"{API}/sites/{urllib.parse.quote(SITE, safe='')}/searchAnalytics/query"
    req = urllib.request.Request(u, data=json.dumps(body).encode(),
                                 headers={"Authorization": "Bearer " + tok,
                                          "x-goog-user-project": qp,
                                          "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=60) as r:
        return json.load(r).get("rows", [])


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--show", action="store_true")
    ap.add_argument("--days", type=int, default=28)
    a = ap.parse_args()

    hist = json.load(open(OUT, encoding="utf-8")) if os.path.isfile(OUT) else []
    if a.show:
        for h in hist:
            print(f"  {h['date']}  表示{h['impr']:6,} クリック{h['clicks']:4} "
                  f"ページ{h['pages']:3} クエリ{h['queries']:3}")
        return 0

    pages = query(["page"], a.days)
    qs = query(["query"], a.days)
    rec = {
        "date": datetime.date.today().isoformat(),
        "days": a.days,
        "impr": int(sum(r["impressions"] for r in pages)),
        "clicks": int(sum(r["clicks"] for r in pages)),
        "pages": len(pages),
        "queries": len(qs),
        "top_pages": [{"url": r["keys"][0].replace(PREFIX, "/"),
                       "impr": int(r["impressions"]), "clicks": int(r["clicks"]),
                       "pos": round(r["position"], 1)}
                      for r in sorted(pages, key=lambda x: -x["impressions"])[:15]],
        "top_queries": [{"q": r["keys"][0], "impr": int(r["impressions"]),
                         "clicks": int(r["clicks"]), "pos": round(r["position"], 1)}
                        for r in sorted(qs, key=lambda x: -x["impressions"])[:20]],
    }
    prev = hist[-1] if hist else None
    hist = [h for h in hist if h["date"] != rec["date"]] + [rec]
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    json.dump(hist, open(OUT, "w", encoding="utf-8"), ensure_ascii=False, indent=1)

    print(f"{rec['date']}（直近{a.days}日）")
    print(f"  表示 {rec['impr']:,} / クリック {rec['clicks']} / "
          f"検索に出たページ {rec['pages']} / クエリ {rec['queries']}")
    if prev:
        print(f"  前回（{prev['date']}）比: 表示 {rec['impr'] - prev['impr']:+,} / "
              f"クリック {rec['clicks'] - prev['clicks']:+} / "
              f"ページ {rec['pages'] - prev['pages']:+}")
    if rec["top_queries"]:
        print("\n  拾われている語:")
        for q in rec["top_queries"][:10]:
            print(f"    {q['impr']:5,}表示 {q['clicks']:3}click {q['pos']:5.1f}位  {q['q'][:36]}")
    else:
        print("\n  まだ1件も検索結果に出ていません（sitemap は登録済み。クロール待ち）")
    if rec["top_pages"]:
        print("\n  入口になっているページ:")
        for p in rec["top_pages"][:8]:
            print(f"    {p['impr']:5,}表示 {p['clicks']:3}click {p['pos']:5.1f}位  {p['url'][:40]}")
    print(f"\n記録: {OUT}（{len(hist)}回ぶん）")
    return 0


if __name__ == "__main__":
    sys.exit(main())
