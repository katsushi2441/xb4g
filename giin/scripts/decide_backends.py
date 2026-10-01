"""判断モデルの呼び出し口。3つとも TypeSafe Jev と同じ /v1/systemone の形で話すので、違うのは宛先とモデル名だけ。

  jevlocal … 当社の Qwen3.5-4B（4bit・GPU）。jevlocal.service :18370
  ollama   … Ollama 0.35 の nimble（Qwen3.5-9B 由来・Q8）。/mnt/data/ollama-0.35 を :18348 で起動
  laya     … Laya multilingual（CPU）。jevlocal/.venv-laya の laya-serve を :18382 で起動

宛先は環境変数で差し替えられる（DECIDE_<名前>_URL）。
"""
import json, os, urllib.request

BACKENDS = {
    "jevlocal": ("http://127.0.0.1:18370/v1/systemone", "jevlocal"),
    "ollama": ("http://127.0.0.1:18348/v1/systemone", "nimble"),
    "laya": ("http://127.0.0.1:18382/v1/systemone", "multilingual"),
}


class Backend:
    def __init__(self, name):
        url, model = BACKENDS[name]
        self.name = name
        self.url = os.environ.get(f"DECIDE_{name.upper()}_URL", url)
        self.model = os.environ.get(f"DECIDE_{name.upper()}_MODEL", model)

    def decide(self, state, questions, timeout=300):
        qs = {k: {"type": "choice", **v} for k, v in questions.items()}
        body = json.dumps({"model": self.model, "state": state, "questions": qs}).encode()
        req = urllib.request.Request(self.url, data=body, headers={"Content-Type": "application/json"})
        with urllib.request.urlopen(req, timeout=timeout) as r:
            d = json.loads(r.read())
        return {k: {"choice": a["choice"], "confidence": float(a.get("confidence") or 0)} for k, a in d["answers"].items()}


def get(name):
    return Backend(name)
