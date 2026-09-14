#!/usr/bin/env python3
"""国会会議録検索システム API の薄いラッパ。

- 利用登録は不要（公式に「APIの利用には、手続き等は必要ありません。」）
- レート制限の数値は明示されていないが「多重リクエストは避け、取得し終えてから
  数秒空ける」と要請があるので、**直列＋待ち時間**で叩く。並列にしない。
- 検索条件部は全体 2000 バイトまで。
"""
import json, time, urllib.parse, urllib.request

BASE = "https://kokkai.ndl.go.jp/api/"
UA = {"User-Agent": "xb4g-giin/1.0 (+https://xb4g.com/giin/)"}
WAIT = 3.0          # 1リクエストごとの待ち（秒）


def call(kind, **params):
    params.setdefault("recordPacking", "json")
    url = BASE + kind + "?" + urllib.parse.urlencode(params)
    req = urllib.request.Request(url, headers=UA)
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=120) as r:
                d = json.loads(r.read())
            time.sleep(WAIT)
            return d
        except Exception as e:
            if attempt == 2:
                raise
            time.sleep(10 * (attempt + 1))


def count(speaker, frm=None, until=None):
    q = {"speaker": speaker, "maximumRecords": 1}
    if frm:
        q["from"] = frm
    if until:
        q["until"] = until
    return call("speech", **q).get("numberOfRecords", 0)


def speeches(speaker, frm=None, until=None, limit=None):
    """発言を順に返す。100件ずつページングする。"""
    start, got = 1, 0
    while True:
        q = {"speaker": speaker, "maximumRecords": 100, "startRecord": start}
        if frm:
            q["from"] = frm
        if until:
            q["until"] = until
        d = call("speech", **q)
        recs = d.get("speechRecord") or []
        if not recs:
            return
        for r in recs:
            yield r
            got += 1
            if limit and got >= limit:
                return
        nxt = d.get("nextRecordPosition")
        if not nxt:
            return
        start = nxt
