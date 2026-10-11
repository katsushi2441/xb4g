#!/usr/bin/env python3
"""X で AI・ハザードマップ・ブロードリスニングなどに触れている首長（知事・市長・町長・村長）を探す。

田川市長（@Uranojin0624）がブロードリスニングの告知をきっかけに当社をフォローした（2026-10-12）。
同じように、当社のシステムの話題を自分で発信している首長に、データを添えてコメントするための候補探し。

X の語検索はログインが要り、fxtwitter の /2/search も止まっているので、Yahoo!リアルタイム検索（直近1か月ほど）で
語を引き、投稿者のうち表示名かプロフィールに首長の肩書きがある人だけを残す。肩書きの確認は fxtwitter のプロフィール。

  /usr/bin/python3 scripts/find_shucho_x.py                # 既定の語
  /usr/bin/python3 scripts/find_shucho_x.py "市長 生成AI"  # 語を指定

出力: outputs/shucho_x_<日付>.json（首長ごとに、引っかかった投稿の一覧）
"""
import datetime
import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
from concurrent.futures import ThreadPoolExecutor

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36"

WORDS = [
    "ブロードリスニング", "広聴AI", "聴くAI",
    "市長 生成AI", "町長 生成AI", "知事 生成AI", "市長 AI活用", "町長 AI", "村長 AI", "市長 ChatGPT",
    "市長 ハザードマップ", "町長 ハザードマップ", "市長 避難所", "市長 防災 AI",
    "市長 DX", "町長 DX", "市長 チャットボット", "首長 AI",
    "市長 防災", "町長 防災", "知事 防災", "市長 台風", "市長 大雨", "市長 浸水", "市長 土砂災害",
    "市長 広聴", "市長 市民の声", "市長 オープンデータ", "知事 AI", "知事 DX",
]
# 肩書き。「前」「元」「候補」「を目指す」は首長ではないので外す
TITLE = re.compile(r"(都|道|府|県)知事|[^\s　]{1,6}(市長|町長|村長|区長)")
NOT_NOW = re.compile(r"(前|元|候補|目指|予定者|志望|立候補|秘書|職員|議員|市長選|町長選|ファン|応援|支持|推し)")


def yahoo(word):
    acc = []

    def walk(o):
        if isinstance(o, dict):
            if "screenName" in o and ("text" in o or "displayText" in o):
                acc.append(o)
            for v in o.values():
                walk(v)
        elif isinstance(o, list):
            for v in o:
                walk(v)
    for prm in [{"b": 1}, {"md": "h"}, {"b": 11}, {"b": 21}]:
        u = "https://search.yahoo.co.jp/realtime/search?" + urllib.parse.urlencode({"p": word, "ei": "UTF-8", **prm})
        h = subprocess.run(["curl", "-s", "-m", "30", "-A", UA, u], capture_output=True, text=True).stdout
        m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', h, re.S)
        if not m:
            break
        try:
            walk(json.loads(m.group(1)))
        except ValueError:
            break
        time.sleep(1)
    out = []
    for t in acc:
        out.append({"screen_name": t.get("screenName"), "name": t.get("name") or "",
                    "id": str(t.get("id") or ""), "text": re.sub(r"\tSTART\t|\tEND\t", "", t.get("text") or t.get("displayText") or ""),
                    "time": t.get("time"), "word": word})
    return out


def profile(sn):
    r = subprocess.run(["curl", "-s", "-m", "20", "-A", "Mozilla/5.0", f"https://api.fxtwitter.com/{sn}"],
                       capture_output=True, text=True).stdout
    try:
        u = json.loads(r).get("user") or {}
    except ValueError:
        return sn, None
    return sn, {"name": u.get("name") or "", "description": u.get("description") or "",
                "followers": u.get("followers"), "location": u.get("location") or ""}


def is_shucho(name, desc):
    for s in (name, desc):
        for m in TITLE.finditer(s or ""):
            head = (s or "")[max(0, m.start() - 4):m.end() + 2]
            # 「〇〇を横浜市長に」「〇〇市長へ」は応援や呼びかけで、本人ではない
            if NOT_NOW.search(head) or (s or "")[m.end():m.end() + 1] in ("に", "へ", "や", "選"):
                continue
            return m.group(0)
    return None


def main():
    words = sys.argv[1:] or WORDS
    posts = []
    for w in words:
        got = yahoo(w)
        print(f"{w}: {len(got)}件", flush=True)
        posts += got
    names = {}
    for p in posts:
        if p["screen_name"]:
            names.setdefault(p["screen_name"], p["name"])
    # 表示名で当たりが付く人は先に、それ以外もプロフィールで肩書きを確かめる（プロフィールにだけ書く首長もいる）
    with ThreadPoolExecutor(6) as ex:
        profs = dict(ex.map(profile, list(names)))
    found = {}
    for p in posts:
        sn = p["screen_name"]
        pr = profs.get(sn) or {"name": p["name"], "description": ""}
        title = is_shucho(pr["name"] or p["name"], pr["description"])
        if not title:
            continue
        f = found.setdefault(sn, {"screen_name": sn, "name": pr["name"] or p["name"], "title": title,
                                  "description": pr["description"], "followers": pr.get("followers"),
                                  "posts": {}})
        if p["id"] and p["id"] not in f["posts"]:
            f["posts"][p["id"]] = {"url": f"https://x.com/{sn}/status/{p['id']}", "word": p["word"],
                                   # Yahoo の time は入らないことがあるので、投稿IDの中の時刻を使う
                                   "time": datetime.datetime.fromtimestamp(((int(p["id"]) >> 22) + 1288834974657) / 1000).strftime("%Y-%m-%d %H:%M"),
                                   "text": re.sub(r"\tSTART\t|\tEND\t", "", p["text"])[:300]}
    res = sorted(found.values(), key=lambda f: -(f["followers"] or 0))
    for f in res:
        f["posts"] = sorted(f["posts"].values(), key=lambda x: x["time"], reverse=True)
    os.makedirs(os.path.join(ROOT, "outputs"), exist_ok=True)
    out = os.path.join(ROOT, "outputs", f"shucho_x_{datetime.date.today():%Y%m%d}.json")
    json.dump(res, open(out, "w"), ensure_ascii=False, indent=1)
    print(f"投稿 {len(posts)}件・投稿者 {len(names)}人・首長 {len(res)}人 → {out}")
    for f in res:
        print(f"- {f['name']} @{f['screen_name']}（{f['title']}・{f['followers']}人）")
        for p in f["posts"][:3]:
            print(f"    {p['time']} [{p['word']}] {p['url']}\n      {p['text'][:120]}")


if __name__ == "__main__":
    main()
