#!/usr/bin/env python3
"""exbridge.jp/news/（ニュースと国会の記録カード）用に、報道機関の投稿だけを集めてカードを付ける。Codex を使わない。
  /usr/bin/python3 scripts/news_only.py [--within 90]
出力: outputs/x_reply_pick/<日付>/newsposts-<時分>.json（集めた投稿）・newsonly-<時分>.json（見出しとカード）
news_cards.py が reply-*.json と一緒に読み、同じ投稿・同じカードは1回だけ載せる。

2026-11-01 ユーザー指示「報道機関の分だけは今までの回数に戻して、むしろ増やす」。
定期の X 返信候補（x_reply_draft.py・Codex で文案を書く）を Codex の週の枠のために1日6回にした結果、
その結果から作る /news/ のニュースも1日6回分に減るので、報道機関の分だけをここで30分おきに作る。
/news/ に載るのは見出し・報道機関・カードだけ（返信の文案は載せない）なので Codex は要らない。
カードは x_reply_draft.make_item と同じ規則（分野の判定は jevlocal・トラッカーとシステムの当たり）。
Codex が関連ページを選ぶ付け替え（トラッカーもシステムも当たらない投稿）はしないので、その投稿は載らない。
表示は投稿から時間がたつほど増えるので、直近90分を集めて表示1,000以上を拾う（一度載ったものは news_cards が重ねない）。
"""
import argparse
import datetime
import json
import os
import re
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import x_reply_pick as X  # noqa: E402
import x_reply_draft as D  # noqa: E402

MIN_VIEWS = 1000   # 定期の返信候補（x_reply_pick pick の --min-views）と同じ下限


def collect(within):
    news = json.load(open(os.path.join(X.ROOT, "data", "x_news_accounts.json"), encoding="utf-8"))["handles"]
    since = int(time.time()) - within * 60
    seen = {}
    for i in range(0, len(news), 20):
        q = "(" + " OR ".join(f"from:{h}" for h in news[i:i + 20]) + f") since_time:{since} -filter:replies"
        for x in X.fx_search(q, pages=5, since_ts=since):
            if x.get("type") == "status" and x["id"] not in seen and (x.get("created_timestamp") or 0) >= since:
                s = X.slim(x)
                s["query"] = "from:報道一覧"
                seen[x["id"]] = s
        time.sleep(2)
    return sorted(seen.values(), key=lambda s: -(s.get("views") or 0))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--within", type=int, default=90)
    a = ap.parse_args()
    now = datetime.datetime.now()
    day, stamp = now.date().isoformat(), now.strftime("%H%M")
    os.makedirs(os.path.join(X.OUT, day), exist_ok=True)
    posts = collect(a.within)
    json.dump(posts, open(os.path.join(X.OUT, day, f"newsposts-{stamp}.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    big = [p for p in posts if (p.get("views") or 0) >= MIN_VIEWS and not re.search(D.SKIP, p.get("text") or "")]
    judged = {r["id"]: r for r in X.judge(X.decide_backends.get("jevlocal"), big)} if big else {}
    rows = []
    for p in big:
        r = judged[p["id"]]
        if r["field"] == "none":
            continue
        it = D.make_item(p, r["field"], day[5:7] + day[8:10], r)
        card = (it or {}).get("url") or ""
        if not card.startswith(("https://xb4g.com/", "https://exbridge.jp/", "https://kurage.exbridge.jp/")):
            continue   # 規則でカードが決まらない投稿は載せない（Codex の付け替えはしない）
        rows.append({"name": p.get("name"), "screen_name": p.get("screen_name"), "url": p.get("url"),
                     "text": re.sub(r"\s+", " ", p.get("text") or "")[:200], "card": card.split("\n")[0], "label": it.get("label")})
    out = os.path.join(X.OUT, day, f"newsonly-{stamp}.json")
    json.dump(rows, open(out, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
    print(f"報道の投稿 {len(posts)}件 → 表示{MIN_VIEWS:,}以上 {len(big)}件 → カードあり {len(rows)}件  {out}")


if __name__ == "__main__":
    main()
