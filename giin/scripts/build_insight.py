#!/usr/bin/env python3
"""議員・会派の「現時点のAI考察」を作る。

**何に取り組み、何を発信しているか**を、手元にある材料だけから短くまとめる。
材料は「国会での質疑」「提出した議案」「関係することがらの最近の動き」
「本人の公式X・公式YouTube」の4つ。

守っていること（この道具の性格上、ここが本体）:

- **数字はAIに書かせない。** 件数や日付は画面がDBから出す。AIの文に数字が
  混ざっていたら採用しない。数え間違いを文章の形で配らないため。
- **人物の評価をさせない。** 「熱心」「優れた」のような語が出たら採用しない。
  党派を問わず同じ道具で同じように作るので、褒め貶しが入ると中立が壊れる。
- **賛否の判定をさせない。** 会議録から賛成・反対は機械判定していないので、
  書けばそれは推測になる。
- 採用しなかったときは**何も出さない**。無理に出すより空のほうが誠実である。

    python3 scripts/build_insight.py            # 全員＋全会派
    python3 scripts/build_insight.py --slug mizuno-koichi
    python3 scripts/build_insight.py --limit 3 --dry
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys
import subprocess
import tempfile
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, "data", "giin.sqlite")
OLLAMA = os.environ.get("GIIN_OLLAMA", "http://192.168.0.3:11434")
OLLAMA_MODEL = os.environ.get("GIIN_OLLAMA_MODEL", "gemma4:12b-it-qat")
CODEX_MODEL = os.environ.get("GIIN_CODEX_MODEL", "")  # 空なら codex の既定
CLAUDE_MODEL = os.environ.get("GIIN_CLAUDE_MODEL", "")
ENGINE = "codex"   # main() で上書きする
MODEL = ""         # 実際に使ったモデル名。DBに残して画面に出す

# 「書くだけの仕事」だと伝える前置き。CLIは放っておくと調べものを始める
HEAD = ("これは文章を書くだけの仕事です。コマンドやファイル操作は一切必要ありません。"
        "調べものもしないでください。渡した材料だけで書いてください。\n\n")
# 使用量の上限に当たったことを示す言い回し。当たったら次の道具へ移る
LIMIT_SIGNS = ("usage limit", "rate limit", "quota", "上限", "429")

# 出たら採用しない語。人物の評価・賛否の判定・煽りにあたるもの
BANNED = [
    "優れ", "素晴らし", "熱心", "積極的", "消極的", "怠", "立派", "有能", "無能",
    "評価でき", "期待でき", "支持すべき", "批判すべき", "貢献度", "リーダーシップ",
    "賛成", "反対", "推進派", "慎重派", "党利党略", "パフォーマンス",
    "第一人者", "看板", "目立", "力を入れて", "本腰",
]
# **弾きたいのは「AIが数えた数」であって、固有名詞の数字ではない。**
# 「Ｆ１日本グランプリ」「ウェブ３」まで弾くと、会議録どおりの語が書けなくなる。
# そこで、数字のうしろに数え方の語が付くもの（件・人・年・％…）だけを数の主張とみなす。
# 英字に続く数字（Ｆ１・Ｇ７）は製品名・大会名なので数の主張ではない。
# 「日」は「日本」を巻き込むので、うしろに「本」が来る場合は除く。
NUM_CLAIM = re.compile(
    r"(?<![A-Za-zＡ-Ｚａ-ｚ])[0-9０-９][0-9０-９,，.．]*\s*"
    r"(?:件|人|回|年|月|日(?!本)|%|％|位|番目|割|億|万|兆|円|議席|度目)")
DIGITS = re.compile(r"[0-9０-９]")

SYSTEM = (
    "あなたは国会会議録と公開情報から、議員が国会で何を取り上げ、"
    "本人の公式発信で何を伝えているかを、短く事実だけで書く編集者です。"
    "与えられた材料の外のことは一切書きません。"
)

RULES_BASE = """次のきまりを必ず守ってください。
1. 数字を一切書かない（件数・日付・年・回数・順位をすべて書かない）。数字は画面が別に出します。
2. 人物や会派をほめたり、けなしたりしない。「熱心」「優れた」「積極的」のような語を使わない。
3. 賛成・反対・推進派・慎重派といった立場の判定を書かない。材料に立場の判定は含まれていません。
4. 与えられた材料に無いことを書かない。推測・将来の見通し・提案を書かない。
5. 「〜を取り上げています」「〜について質疑しています」のように、観察できることだけを書く。
6. 見出しや箇条書きは使わない。"""

# **段落の役割を固定する。** これをしないと、省庁の報道発表を「本人の公式発信」として
# 書いてしまう（実際に起きた）。材料の取り違えは、文章のうまさでは防げない。
P_SPEECH = "第一段落は、国会で何を取り上げているかだけを書く（「国会でよく取り上げていることがら」と「最近の質疑の書き出し」からのみ）。"
P_GIAN = "提出者に名前がある議案があれば、その内容も第一段落に含める。"
P_OWN = "第二段落は、本人の公式Xと公式YouTubeで何を伝えているかだけを書く。ここには国会の話を混ぜない。"
P_NO_OWN = "**公式発信については一切書かない。**本人の発信の材料は渡していないので、書けば事実に反する。「公式発信では」という書き出しを使わない。"
P_AMBIENT = ("最後の段落で、関係することがらの最近の動きに一文か二文だけ触れてよい。"
             "ただしそれは国会に出された議案や省庁の発表であって、この人たちが出したものではない。"
             "「同じ分野では、国会や省庁で〜が動いています」のように、"
             "主語を国会や省庁にして書く。取り組みや実績として書かない。"
             "指示文の言い回しをそのまま写さない。")


def db():
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    con.execute("PRAGMA busy_timeout=5000")
    con.execute("""CREATE TABLE IF NOT EXISTS insight(
        scope TEXT, key TEXT, body TEXT, model TEXT, built_at TEXT,
        PRIMARY KEY(scope, key))""")
    return con


def _ask_ollama(prompt: str, temp: float) -> str:
    """手元の Ollama に聞く。gemma4 は思考型なので think は必ず false。"""
    req = urllib.request.Request(
        OLLAMA + "/api/chat",
        data=json.dumps({
            "model": OLLAMA_MODEL,
            "messages": [{"role": "system", "content": SYSTEM},
                         {"role": "user", "content": prompt}],
            "stream": False, "think": False,
            "options": {"temperature": temp, "num_predict": 900},
        }).encode(),
        headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as r:
        return json.loads(r.read())["message"]["content"].strip()


def _ask_codex(prompt: str, _temp: float) -> str:
    """Codex CLI に聞く。

    これは文章を書かせるだけの用なので、**コマンドを実行させない**。
    読み取り専用の砂箱で走らせ、プロンプトでも道具を使わないよう言う。
    セッションを残さない（--ephemeral）のは、会議録の断片がディスクに
    溜まり続けないようにするため。
    """
    with tempfile.TemporaryDirectory() as d:
        out = os.path.join(d, "out.txt")
        cmd = ["codex", "exec", "--ephemeral", "--skip-git-repo-check",
               "-s", "read-only", "-C", d, "-o", out]
        if CODEX_MODEL:
            cmd += ["-m", CODEX_MODEL]
        r = subprocess.run(cmd, input=SYSTEM + "\n\n" + HEAD + prompt,
                           capture_output=True, text=True, timeout=600)
        if os.path.isfile(out):
            got = open(out, encoding="utf-8").read().strip()
            if got:
                return got
        raise RuntimeError((r.stderr or r.stdout or "codexが何も返さなかった")[-200:])


def _ask_claude(prompt: str, _temp: float) -> str:
    """Claude Code CLI に聞く。道具は使わせない（文章を書くだけなので）。"""
    cmd = ["claude", "-p", "--allowed-tools", ""]
    if CLAUDE_MODEL:
        cmd += ["--model", CLAUDE_MODEL]
    r = subprocess.run(cmd, input=SYSTEM + "\n\n" + HEAD + prompt,
                       capture_output=True, text=True, timeout=600)
    out = (r.stdout or "").strip()
    if not out:
        raise RuntimeError((r.stderr or "claudeが何も返さなかった")[-200:])
    return out


ENGINES = {"codex": _ask_codex, "claude": _ask_claude, "ollama": _ask_ollama}
# 上限に当たったら下へ移る順。
# **codex は既定の鎖に入れない。** このアカウントで使えるモデルは gpt-6-astra だけで、
# 1件あたり2万トークン近くを52回使うため、対話用の枠を一度に食い潰す（実際に2回上限に達した）。
# 使いたいときは --engine codex と明示する。
CHAIN = ["claude", "ollama"]
_dead: set = set()


def _ask(prompt: str, temp: float = 0.2) -> str:
    """いま使える道具で書かせる。**上限に当たった道具は、その回のうちは二度と使わない。**
    38回続けて同じ上限エラーを踏んだので、1回踏んだら次へ移る。"""
    global ENGINE, MODEL
    order = [ENGINE] + [e for e in CHAIN if e != ENGINE] if ENGINE != "auto" else list(CHAIN)
    last = None
    for name in order:
        if name in _dead:
            continue
        try:
            out = ENGINES[name](prompt, temp)
        except Exception as e:  # noqa: BLE001
            msg = str(e)
            last = e
            if any(w in msg.lower() for w in LIMIT_SIGNS):
                print(f"    （{name} は使用量の上限。ここから先は使いません）")
                _dead.add(name)
                continue
            raise
        if name != ENGINE:
            print(f"    （{name} に切り替えました）")
            ENGINE, MODEL = name, _model_name(name)
        return out
    raise last or RuntimeError("使える生成先がない")


def _judge(text: str, has_own: bool = True):
    """採用してよいか。だめなら理由を返す。"""
    if not has_own and ("公式発信" in text or "公式ＳＮＳ" in text or "本人の発信" in text):
        return "本人の発信の材料が無いのに公式発信について書いている"
    if len(text) < 120:
        return "短すぎる"
    if len(text) > 700:
        return f"長すぎる（{len(text)}字）"
    m = NUM_CLAIM.search(text)
    if m:
        return f"数を主張している（{m.group()}）"
    if len(DIGITS.findall(text)) > 4:
        return "数字が多すぎる（" + "".join(DIGITS.findall(text))[:12] + "）"
    for w in BANNED:
        if w in text:
            return f"使わない語が入っている（{w}）"
    if "材料" in text or "与えられ" in text or "情報が不足" in text:
        return "材料そのものに言及している"
    return None


def _clean(text: str) -> str:
    text = re.sub(r"^```.*?\n|```$", "", text, flags=re.S).strip()
    text = re.sub(r"^(はい、?|承知しました。?|以下.*?です。?)\s*", "", text)
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def _facts_giin(con, g) -> str:
    gid = g["id"]
    themes = [r["theme"] for r in con.execute(
        "SELECT theme FROM theme_count WHERE giin_id=? AND kind='q' AND n>0"
        " ORDER BY n DESC LIMIT 6", (gid,))]
    names = {t["slug"]: t["name"] for t in json.load(
        open(os.path.join(ROOT, "data", "themes.json"), encoding="utf-8"))}
    tnames = [names.get(s, s) for s in themes]

    meets = [r["meeting"] for r in con.execute(
        "SELECT meeting, COUNT(*) c FROM speech WHERE giin_id=? AND kind='q'"
        " GROUP BY meeting ORDER BY c DESC LIMIT 5", (gid,))]

    sp = [re.sub(r"\s+", " ", (r["body"] or ""))[:160] for r in con.execute(
        "SELECT body FROM speech WHERE giin_id=? AND kind='q' AND length(body)>200"
        " ORDER BY date DESC LIMIT 8", (gid,))]

    gian = [r["title"] for r in con.execute(
        "SELECT title FROM news WHERE giin_id=? ORDER BY date DESC LIMIT 6", (gid,))]

    nw = [r["title"] for r in con.execute(
        "SELECT DISTINCT n.title FROM news n JOIN news_theme nt ON nt.news_id=n.id"
        " WHERE nt.theme IN (%s) ORDER BY n.date DESC LIMIT 6"
        % ",".join("?" * len(themes)), themes)] if themes else []

    xs = [re.sub(r"\s+", " ", (r["body"] or ""))[:140] for r in con.execute(
        "SELECT body FROM xpost WHERE giin_id=? AND is_repost=0"
        " ORDER BY posted DESC LIMIT 6", (gid,))]
    yt = [r["title"] for r in con.execute(
        "SELECT title FROM video WHERE giin_id=? ORDER BY published DESC LIMIT 6", (gid,))]

    P = [f"議員: {g['plain']}（{g['house']}・{g['district']}・{g['kaiha']}）"]
    if tnames: P.append("国会でよく取り上げていることがら（多い順）: " + "、".join(tnames))
    if meets: P.append("よく出ている会議（多い順）: " + "、".join(meets))
    if sp: P.append("最近の質疑の書き出し:\n" + "\n".join("・" + s for s in sp))
    if gian: P.append("提出者に名前がある議案:\n" + "\n".join("・" + s for s in gian))
    if nw: P.append("関係することがらの最近の動き（本人のものではない）:\n"
                    + "\n".join("・" + s for s in nw))
    if xs: P.append("本人の公式Xの最近の投稿:\n" + "\n".join("・" + s for s in xs))
    if yt: P.append("本人の公式YouTubeの最近の動画:\n" + "\n".join("・" + s for s in yt))
    return "\n\n".join(P), bool(sp or gian or xs or yt), bool(xs or yt)


def _facts_party(con, party: str):
    names = {t["slug"]: t["name"] for t in json.load(
        open(os.path.join(ROOT, "data", "themes.json"), encoding="utf-8"))}
    ids = [r["id"] for r in con.execute("SELECT id FROM giin WHERE party=?", (party,))]
    if not ids:
        return "", False, False
    q = ",".join("?" * len(ids))
    themes = [r["theme"] for r in con.execute(
        f"SELECT theme, SUM(n) s FROM theme_count WHERE kind='q' AND giin_id IN ({q})"
        " GROUP BY theme ORDER BY s DESC LIMIT 6", ids)]
    meets = [r["meeting"] for r in con.execute(
        f"SELECT meeting, COUNT(*) c FROM speech WHERE kind='q' AND giin_id IN ({q})"
        " GROUP BY meeting ORDER BY c DESC LIMIT 5", ids)]
    who = [r["plain"] for r in con.execute(
        f"SELECT plain FROM giin WHERE id IN ({q}) ORDER BY n_q DESC LIMIT 6", ids)]
    sp = [re.sub(r"\s+", " ", (r["body"] or ""))[:150] for r in con.execute(
        f"SELECT body FROM speech WHERE kind='q' AND giin_id IN ({q}) AND length(body)>200"
        " ORDER BY date DESC LIMIT 8", ids)]
    nw = [r["title"] for r in con.execute(
        "SELECT DISTINCT n.title FROM news n JOIN news_theme nt ON nt.news_id=n.id"
        " WHERE nt.theme IN (%s) ORDER BY n.date DESC LIMIT 6" % ",".join("?" * len(themes)),
        themes)] if themes else []

    P = [f"会派: {party}（愛知に関係する国会議員だけを収録している）"]
    if who: P.append("この会派で質疑の多い議員（多い順）: " + "、".join(who))
    if themes: P.append("よく取り上げていることがら（多い順）: "
                        + "、".join(names.get(s, s) for s in themes))
    if meets: P.append("よく出ている会議（多い順）: " + "、".join(meets))
    if sp: P.append("最近の質疑の書き出し:\n" + "\n".join("・" + s for s in sp))
    if nw: P.append("関係することがらの最近の動き（この会派が出したものではない）:\n"
                    + "\n".join("・" + s for s in nw))
    return "\n\n".join(P), bool(sp), False


def _make(facts: str, subject: str, has_own: bool):
    """3回だけ試す。だめなら採用しない（空を返す）。無理に出すより空のほうが誠実。"""
    parts = [P_SPEECH, P_GIAN, P_OWN if has_own else P_NO_OWN, P_AMBIENT]
    rules = (RULES_BASE + "\n7. 段落の役割は次のとおりに固定します。\n   "
             + "\n   ".join(parts) + "\n8. 全体で三百字から四百五十字。これより長く書かない。")
    ask = (f"{facts}\n\n---\n\n上の材料だけを使って、{subject}が"
           "国会で何に取り組み、何を伝えているかを書いてください。\n\n" + rules)
    for i in range(3):
        try:
            out = _clean(_ask(ask, 0.2 if i == 0 else 0.1))
        except Exception as e:  # noqa: BLE001
            return "", f"生成できず: {str(e)[:80]}"
        bad = _judge(out, has_own)
        if not bad:
            return out, ""
        ask += f"\n\n前回の文は採用できませんでした（理由: {bad}）。きまりを守って書き直してください。"
    return "", bad


def _model_name(engine: str) -> str:
    if engine == "ollama":
        return OLLAMA_MODEL
    if engine == "claude":
        return CLAUDE_MODEL or "Claude Code"
    return CODEX_MODEL or _codex_model()


def _codex_model() -> str:
    """codex の設定から既定モデル名を拾う。画面に「何で書いたか」を出すため。"""
    path = os.path.expanduser("~/.codex/config.toml")
    if os.path.isfile(path):
        for line in open(path, encoding="utf-8", errors="ignore"):
            m = re.match(r'\s*model\s*=\s*"([^"]+)"', line)
            if m:
                return m.group(1)
    return "codex"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--slug")
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--dry", action="store_true")
    ap.add_argument("--parties-only", action="store_true")
    ap.add_argument("--engine", choices=["codex", "claude", "ollama", "auto"], default="claude",
                    help="文章を書かせる先。既定は claude→ollama。"
                         "codex は枠を食うので明示したときだけ使う")
    ap.add_argument("--stale-only", action="store_true",
                    help="いまの生成先で作り直していないページだけをやる（中断からの続き）")
    a = ap.parse_args()

    global ENGINE, MODEL
    ENGINE = a.engine
    MODEL = _model_name(CHAIN[0] if ENGINE == "auto" else ENGINE)

    con = db()
    now = datetime.datetime.now().strftime("%Y-%m-%d")
    ok = skip = 0

    if not a.parties_only:
        sql = "SELECT * FROM giin WHERE n_q>0"
        args = []
        if a.slug:
            sql, args = "SELECT * FROM giin WHERE slug=?", [a.slug]
        rows = con.execute(sql + " ORDER BY n_q DESC", args).fetchall()
        if a.stale_only:
            done = {r[0] for r in con.execute(
                "SELECT key FROM insight WHERE scope='giin' AND model=?", (MODEL,))}
            rows = [g for g in rows if g["slug"] not in done]
        if a.limit:
            rows = rows[:a.limit]
        for g in rows:
            facts, has, own = _facts_giin(con, g)
            if not has:
                print(f"  - {g['plain']}: 材料が足りないので作らない")
                skip += 1
                continue
            body, why = _make(facts, f"{g['plain']}議員", own)
            if not body:
                print(f"  × {g['plain']}: {why}")
                skip += 1
                continue
            print(f"  ○ {g['plain']}  {len(body)}字")
            if a.dry:
                print("    " + body.replace("\n", "\n    "))
            else:
                con.execute("INSERT OR REPLACE INTO insight VALUES('giin',?,?,?,?)",
                            (g["slug"], body, MODEL, now))
                con.commit()
            ok += 1

    if not a.slug:
        kaihas = [r["party"] for r in con.execute(
            "SELECT party, COUNT(*) c FROM giin WHERE party<>'' GROUP BY party ORDER BY c DESC")]
        if a.stale_only:
            done = {r[0] for r in con.execute(
                "SELECT key FROM insight WHERE scope='party' AND model=?", (MODEL,))}
            kaihas = [k for k in kaihas if k not in done]
        if a.limit:
            kaihas = kaihas[:a.limit]
        for k in kaihas:
            facts, has, own = _facts_party(con, k)
            if not has:
                print(f"  - {k}: 材料が足りないので作らない")
                skip += 1
                continue
            body, why = _make(facts, f"愛知に関係する「{k}」の国会議員", own)
            if not body:
                print(f"  × {k}: {why}")
                skip += 1
                continue
            print(f"  ○ {k}  {len(body)}字")
            if a.dry:
                print("    " + body.replace("\n", "\n    "))
            else:
                con.execute("INSERT OR REPLACE INTO insight VALUES('party',?,?,?,?)",
                            (k, body, MODEL, now))
                con.commit()
            ok += 1

    print(f"\n作った {ok}件 / 作らなかった {skip}件  model={MODEL}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
