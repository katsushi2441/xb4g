#!/usr/bin/env python3
"""質疑カード（/tracker/<key>/<発言ID>.png）をサーバーで描くための日本語フォントを作る。

Noto Sans CJK は1ウェイト20MBあり、毎回の配布に混ぜられないので、
JIS第1・第2水準＋英数記号だけに絞る（会議録の本文はほぼこの範囲。外れた字は豆腐になる）。
fonts/ は deploy.py の既定の配布物に入れない。作り直したときだけ送る:

  /usr/bin/python3 scripts/make_card_fonts.py
  /usr/bin/python3 scripts/deploy.py --only fonts
"""
import os
from fontTools.subset import Options, Subsetter
from fontTools.ttLib import TTCollection

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = "/usr/share/fonts/opentype/noto/NotoSansCJK-{}.ttc"


def chars() -> set:
    cs = set(chr(c) for c in range(0x20, 0x7f))
    for hi in range(0xa1, 0xff):            # JIS X 0208（EUC-JP の2バイト全域）
        for lo in range(0xa1, 0xff):
            try:
                cs.add(bytes([hi, lo]).decode("euc_jp"))
            except UnicodeDecodeError:
                pass
    cs.update("〜～・…‥―─①②③④⑤⑥⑦⑧⑨⑩“”‘’「」『』（）【】〔〕　")
    return cs


def main():
    os.makedirs(os.path.join(ROOT, "fonts"), exist_ok=True)
    text = "".join(sorted(chars()))
    for w in ("Bold", "Regular", "Black"):
        font = TTCollection(SRC.format(w)).fonts[0]    # 0 = JP
        opt = Options()
        opt.layout_features = ["*"]
        opt.name_IDs = ["*"]
        s = Subsetter(opt)
        s.populate(text=text)
        s.subset(font)
        out = os.path.join(ROOT, "fonts", f"NotoSansJP-{w}-sub.otf")
        font.save(out)
        print(out, os.path.getsize(out) // 1024, "KB")


if __name__ == "__main__":
    main()
