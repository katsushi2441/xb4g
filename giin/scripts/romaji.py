#!/usr/bin/env python3
"""ひらがなの読みからヘボン式ローマ字の slug を作る。

URLに議員名を入れるために使う。**日本語のままURLに入れない**：
percent-encoding でコピペが壊れ、ログもリンクも読めなくなる。
検索結果に日本語で出したいのはURLではなくパンくずなので、
そちらは BreadcrumbList の構造化データで日本語にする。

長音は伸ばさない（こういち → koichi）。撥音・促音・拗音を扱う。
"""
import re

TWO = {
    "きゃ": "kya", "きゅ": "kyu", "きょ": "kyo", "しゃ": "sha", "しゅ": "shu", "しょ": "sho",
    "ちゃ": "cha", "ちゅ": "chu", "ちょ": "cho", "にゃ": "nya", "にゅ": "nyu", "にょ": "nyo",
    "ひゃ": "hya", "ひゅ": "hyu", "ひょ": "hyo", "みゃ": "mya", "みゅ": "myu", "みょ": "myo",
    "りゃ": "rya", "りゅ": "ryu", "りょ": "ryo", "ぎゃ": "gya", "ぎゅ": "gyu", "ぎょ": "gyo",
    "じゃ": "ja", "じゅ": "ju", "じょ": "jo", "ぢゃ": "ja", "ぢゅ": "ju", "ぢょ": "jo",
    "びゃ": "bya", "びゅ": "byu", "びょ": "byo", "ぴゃ": "pya", "ぴゅ": "pyu", "ぴょ": "pyo",
    "ふぁ": "fa", "ふぃ": "fi", "ふぇ": "fe", "ふぉ": "fo", "うぃ": "wi", "うぇ": "we",
}
ONE = {
    "あ": "a", "い": "i", "う": "u", "え": "e", "お": "o",
    "か": "ka", "き": "ki", "く": "ku", "け": "ke", "こ": "ko",
    "さ": "sa", "し": "shi", "す": "su", "せ": "se", "そ": "so",
    "た": "ta", "ち": "chi", "つ": "tsu", "て": "te", "と": "to",
    "な": "na", "に": "ni", "ぬ": "nu", "ね": "ne", "の": "no",
    "は": "ha", "ひ": "hi", "ふ": "fu", "へ": "he", "ほ": "ho",
    "ま": "ma", "み": "mi", "む": "mu", "め": "me", "も": "mo",
    "や": "ya", "ゆ": "yu", "よ": "yo",
    "ら": "ra", "り": "ri", "る": "ru", "れ": "re", "ろ": "ro",
    "わ": "wa", "を": "o", "ん": "n",
    "が": "ga", "ぎ": "gi", "ぐ": "gu", "げ": "ge", "ご": "go",
    "ざ": "za", "じ": "ji", "ず": "zu", "ぜ": "ze", "ぞ": "zo",
    "だ": "da", "ぢ": "ji", "づ": "zu", "で": "de", "ど": "do",
    "ば": "ba", "び": "bi", "ぶ": "bu", "べ": "be", "ぼ": "bo",
    "ぱ": "pa", "ぴ": "pi", "ぷ": "pu", "ぺ": "pe", "ぽ": "po",
    "ぁ": "a", "ぃ": "i", "ぅ": "u", "ぇ": "e", "ぉ": "o",
    "ゃ": "ya", "ゅ": "yu", "ょ": "yo", "ー": "",
}


def romaji(kana: str) -> str:
    s = re.sub(r"[\s　]+", "", kana)
    out, i = [], 0
    while i < len(s):
        if s[i] == "っ":
            # 促音は次の子音を重ねる
            nxt = romaji(s[i + 1:i + 3]) if i + 1 < len(s) else ""
            if nxt:
                out.append(nxt[0] if nxt[0] != "c" else "t")   # っち → tchi
            i += 1
            continue
        pair = s[i:i + 2]
        if pair in TWO:
            out.append(TWO[pair]); i += 2; continue
        ch = s[i]
        out.append(ONE.get(ch, "")); i += 1
    r = "".join(out)
    # 「ん」のあとの母音・yは区切る（しんいち → shin-ichi ではなく shinichi でよいが、
    # 読み違いが起きる場合だけハイフンを入れる）
    r = re.sub(r"n(?=[aiueoy])", "n", r)
    # 長音の連続を1つに（こういち→koichi、そういちろう→soichiro）
    r = re.sub(r"ou", "o", r)
    r = re.sub(r"uu", "u", r)
    r = re.sub(r"oo", "o", r)
    r = re.sub(r"ei", "ei", r)
    return r


def slug_of(kana: str) -> str:
    parts = re.split(r"[\s　]+", kana.strip())
    parts = [p for p in parts if p]
    return "-".join(romaji(p) for p in parts) or ""


if __name__ == "__main__":
    import json, os, sys
    ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    rows = json.load(open(os.path.join(ROOT, "data", "roster.json"), encoding="utf-8"))
    used = {}
    for r in rows:
        s = slug_of(r["kana"])
        if s in used:
            # 同姓同名は選挙区で分ける（いまのところ該当なし）
            s = s + "-" + re.sub(r"[^0-9]", "", r["district"]) or s
        used[s] = r["display"]
        r["slug"] = s
    json.dump(rows, open(os.path.join(ROOT, "data", "roster.json"), "w", encoding="utf-8"),
              ensure_ascii=False, indent=1)
    for r in rows:
        print(f"  {r['display']:<12} {r['kana']:<18} → {r['slug']}")
    print(f"\n重複: {len(rows) - len(set(x['slug'] for x in rows))}件")
