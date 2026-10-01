#!/usr/bin/env python3
"""X の返信先選びで、判断モデル（jevlocal・Nimble・Laya）を同じ設問で比べる。

設問は x_reply_pick.py の本番と同じ2問（分野13択・返信向き3択）。
正解は outputs/x_reply_pick/eval/labels.json（本文を読んで付けたもの）。
  返信向き … 全99件で採点（yes を当てた割合・yes と言ったうち本当に yes の割合も出す）
  分野     … 正解が yes の投稿だけで採点（返信しない投稿の分野は問わない）

使い方: /usr/bin/python3 scripts/x_reply_pick_eval.py jevlocal laya ollama
出力:   outputs/x_reply_pick/eval/result-<名前>.json と RESULTS.md
"""
import json, os, statistics, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import decide_backends  # noqa: E402
import x_reply_pick as X  # noqa: E402

EV = os.path.join(X.OUT, "eval")


def run(name):
    S = json.load(open(os.path.join(EV, "set.json"), encoding="utf-8"))
    be = decide_backends.get(name)
    rows = []
    for i, p in enumerate(S):
        t0 = time.time()
        a = None
        for _ in range(2):   # 失敗は1回だけ取り直し、それでもだめなら「失敗」として数える（正解扱いにしない）
            try:
                a = be.decide({"post": p["text"][:1500], "author": p.get("name") or ""}, {
                    "field": {"instructions": X.FIELD_Q, "criteria": X.FIELDS},
                    "fit": {"instructions": X.FIT_Q, "criteria": X.FIT},
                })
                break
            except Exception as e:  # noqa: BLE001
                err = f"{type(e).__name__}: {getattr(e, 'code', '')} {e.read()[:200] if hasattr(e, 'read') else e}"
        if a is None:
            rows.append({"id": p["id"], "field": "error", "field_conf": 0, "fit": "error", "fit_conf": 0,
                         "ms": round((time.time() - t0) * 1000), "error": err})
            continue
        rows.append({"id": p["id"], "field": a["field"]["choice"], "field_conf": a["field"]["confidence"],
                     "fit": a["fit"]["choice"], "fit_conf": a["fit"]["confidence"], "ms": round((time.time() - t0) * 1000)})
        print(f"\r{name} {i + 1}/{len(S)}", end="", flush=True)
    print()
    json.dump(rows, open(os.path.join(EV, f"result-{name}.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)


def score(name):
    gold = {l["id"]: l for l in json.load(open(os.path.join(EV, "labels.json"), encoding="utf-8"))["labels"]}
    R = json.load(open(os.path.join(EV, f"result-{name}.json"), encoding="utf-8"))
    fit_ok = sum(gold[r["id"]]["fit"] == r["fit"] for r in R)
    said = [r for r in R if r["fit"] == "yes"]
    tp = sum(gold[r["id"]]["fit"] == "yes" for r in said)
    gy = [r for r in R if gold[r["id"]]["fit"] == "yes"]
    field_ok = sum(gold[r["id"]]["field"] == r["field"] for r in gy)
    return {"name": name, "n": len(R), "fit_ok": fit_ok, "said_yes": len(said), "tp": tp, "gold_yes": len(gy),
            "field_ok": field_ok, "median_ms": int(statistics.median(r["ms"] for r in R)),
            "unknown": sum(r["fit"] == "unknown" for r in R)}


if __name__ == "__main__":
    names = sys.argv[1:] or ["jevlocal", "laya", "ollama"]
    for n in names:
        if not os.path.exists(os.path.join(EV, f"result-{n}.json")) or "--rerun" in os.environ.get("EVAL_FLAGS", ""):
            run(n)
    S = [score(n) for n in names if os.path.exists(os.path.join(EV, f"result-{n}.json"))]
    for s in S:
        print(s)
