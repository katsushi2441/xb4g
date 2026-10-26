#!/usr/bin/env python3
"""X の日本のトレンド（trends24.in の写し・毎時）を取り、国会の発言ログ・国会トラッカー・質問主意書と突き合わせる。
  /usr/bin/python3 scripts/trend_match.py            → いま当たる語と、付けられる当社ページ（発言ログの検索・トラッカー）
  /usr/bin/python3 scripts/trend_match.py --min 5    → 質疑が5件以上ある語だけ
トレンドの語に国会の質疑があれば、その語の検索ページ（件数入りのカード）を X の投稿に付けられる。
"""
import json, re, sqlite3, sys, urllib.request, html
HERE = __import__('os').path.dirname(__import__('os').path.abspath(__file__))
DB = HERE + '/../data/giin.sqlite'; TR = HERE + '/../data/trackers.json'
OLLAMA = 'http://192.168.0.3:11434'   # 手元の gemma4（0.3・think:false）
LLM = 'gemma4:12b-it-qat'
MIN = int(sys.argv[sys.argv.index('--min') + 1]) if '--min' in sys.argv else 3

def trends():
    req = urllib.request.Request('https://trends24.in/japan/', headers={'User-Agent': 'Mozilla/5.0'})
    s = urllib.request.urlopen(req, timeout=30).read().decode('utf-8', 'ignore')
    # 最初の時刻の塊（直近1時間）だけ
    block = s.split('class="trend-card"')[1] if 'class="trend-card"' in s else s
    words = re.findall(r'<a href="https://(?:twitter|x)\.com/search\?q=[^"]+"[^>]*>([^<]+)', block)
    out = []
    for w in words:
        w = html.unescape(w).strip()
        if w and w not in out: out.append(w)
    return out[:50]

CACHE = HERE + '/../outputs/kokkai_x_watch/trends.json'

def trends_cached(max_age=3600):
    """トレンドは1時間に1回だけ取りに行く（監視は15分おきなので、毎回は取らない）。"""
    import os, time
    try:
        c = json.load(open(CACHE, encoding='utf-8'))
        if time.time() - c.get('at', 0) < max_age and c.get('words'):
            return c['words']
    except Exception:
        pass
    ws = trends()
    os.makedirs(os.path.dirname(CACHE), exist_ok=True)
    json.dump({'at': time.time(), 'words': ws}, open(CACHE, 'w', encoding='utf-8'), ensure_ascii=False)
    return ws

# 人の名前・作品名を落とす（2026-10-27 ユーザー「トレンドキーワードが人の名前ばっかりっておかしくない？」）
# 夜のトレンドはアニメ・芸能・ドラマの人名が大半で、「久保先生」「吉田さん」が会議録の別人の発言に3件当たって
# メールに並んでいた。一方「新幹線予約の個人情報17万件流出」のような本当に当てたい話題は、文のままでは会議録に無く落ちていた。
HONORIFIC = re.compile(r'(先生|さん|くん|君|ちゃん|様|さま|氏|キャスター|選手|監督|誕生日|生誕祭|卒業)$')
TOPICS = HERE + '/../outputs/kokkai_x_watch/trend_topics.json'

def topics(words):
    """トレンドの語を手元の gemma4 で「政治・行政・社会の話題か」に分け、話題なら会議録を引く語（空白区切りは全部含む）を出す。
    {語: [検索語,...]}（話題でない語は入らない）。トレンドの取り直しごとに1回だけ聞いて保存する。gemma が使えないときは {}（何も出さない）。"""
    import hashlib, os
    sig = hashlib.md5('\n'.join(words).encode()).hexdigest()
    try:
        c = json.load(open(TOPICS, encoding='utf-8'))
        if c.get('sig') == sig:
            return c['topics']
    except Exception:
        pass
    prompt = ("次は X（旧Twitter）の日本のトレンドの語です。国会で議論されうる政治・行政・経済・社会の話題（事件・事故・制度・料金・個人情報の流出・災害・選挙・税・物価など）だけを選んでください。"
              "人の名前・あだ名・キャラクター・アニメ・ドラマ・番組・芸能・アイドル・スポーツの試合・ゲーム・商品名・企業の宣伝・キャンペーン・投資の勧誘や銘柄の推奨・ハッシュタグ遊びは選ばないでください。"
              "選んだ語ごとに、国会の会議録を検索する短い語を1〜2個つけてください（名詞だけ。2語を全部含む発言を探すときは半角空白で区切る。例: 「新幹線予約の個人情報17万件流出」→「個人情報 流出」）。"
              "迷ったら選ばないでください。\n\n" + "\n".join(words))
    schema = {"type": "object", "properties": {"topics": {"type": "array", "items": {"type": "object", "properties": {
        "word": {"type": "string"}, "queries": {"type": "array", "items": {"type": "string"}}}, "required": ["word", "queries"]}}}, "required": ["topics"]}
    try:
        b = json.dumps({"model": LLM, "prompt": prompt, "stream": False, "think": False, "format": schema,
                        "options": {"temperature": 0, "num_predict": 800}}).encode()
        r = json.load(urllib.request.urlopen(urllib.request.Request(OLLAMA + "/api/generate", data=b, headers={"Content-Type": "application/json"}), timeout=180))
        got = json.loads(r.get("response") or "{}").get("topics", [])
    except Exception as e:  # 取れなければ何も出さない（人名を並べるよりよい）。次の回に聞き直す
        print(f"trend topics: gemma4 に聞けませんでした: {type(e).__name__}: {e}", file=sys.stderr)
        return {}
    out = {}
    for t in got:
        w = t.get('word', '')
        if w not in words or HONORIFIC.search(w.lstrip('#')):
            continue
        qs = [re.sub(r'\s+', ' ', q).strip() for q in t.get('queries', []) if q and len(q.strip()) >= 2]
        if qs:
            out[w] = qs[:2]
    os.makedirs(os.path.dirname(TOPICS), exist_ok=True)
    json.dump({'sig': sig, 'topics': out}, open(TOPICS, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    return out

def count(db, q):
    """検索語（空白区切りは全部含む）に当たる質疑の件数と人数。カタカナ1語は長い語の一部に当たらないよう数え直す。"""
    parts = q.split(' ')
    where = ' and '.join(['body like ?'] * len(parts)); args = ['%' + x + '%' for x in parts]
    n, p = db.execute(f"select count(*),count(distinct giin_id) from speech_all where kind='q' and {where}", args).fetchone()
    if n and len(parts) == 1 and re.fullmatch(r'[ァ-ヶー]+', q):
        pat = re.compile(r'(?<![ァ-ヶー])' + re.escape(q) + r'(?![ァ-ヶー])')
        rows = [(gid, body) for gid, body in db.execute("select giin_id, body from speech_all where kind='q' and body like ?", args) if pat.search(body)]
        n, p = len(rows), len({g for g, _ in rows})
    return n, p

def match(min_n=3, use_cache=True):
    """いまのトレンド語のうち、政治・社会の話題で、国会の質疑かトラッカーに当たるものを [{word,query,n,people,url,trackers}] で返す。"""
    import urllib.parse
    db = sqlite3.connect(DB)
    trackers = json.load(open(TR)); trackers = trackers if isinstance(trackers, list) else trackers.get('trackers', trackers)
    words = trends_cached() if use_cache else trends()
    out = []
    for w, qs in topics(words).items():
        best = None
        for q in qs:
            toks = q.split(' ')
            tr = [t for t in trackers if all(x in ' '.join(t.get('words', [])) + t.get('name', '') for x in toks)]
            if tr:
                # トラッカーがあればリンクはトラッカー（件数もトラッカーのページと同じ数を出す）
                t0 = tr[0]
                n, p = db.execute("select count(*),count(distinct speaker) from tracker_speech where tracker=? and kind='q'", (t0['key'],)).fetchone()
                cand = {'word': w, 'query': q, 'n': n, 'people': p, 'url': 'https://xb4g.com/giin/tracker/' + t0['key'],
                        'label': '国会トラッカー「' + t0.get('name', t0['key']) + '」', 'trackers': ['https://xb4g.com/giin/tracker/' + t['key'] for t in tr]}
            elif len(toks) == 1:
                # 発言ログの検索ページは語をそのまま1つの語として探す（空白で区切った2語の「全部含む」はできない）ので、1語のときだけ
                n, p = count(db, q)
                if n < min_n:
                    continue
                cand = {'word': w, 'query': q, 'n': n, 'people': p, 'url': 'https://xb4g.com/giin/search?q=' + urllib.parse.quote(q),
                        'label': '発言ログの検索「' + q + '」', 'trackers': []}
            else:
                continue
            if not best or (bool(cand['trackers']), cand['n']) > (bool(best['trackers']), best['n']):
                best = cand
        if best:
            out.append(best)
    return out

def main():
    hits = match(MIN, use_cache=False)
    print(f'政治・社会の話題で当たった語 {len(hits)}')
    for h in hits:
        print(f"{h['word']}（{h['query']}）: 質疑{h['n']}件・{h['people']}人 {h['url']}" + (' ' + ' '.join(h['trackers']) if h['trackers'] else ''))

if __name__ == '__main__':
    import urllib.parse
    main()


# ---------------------------------------------------------------------------
# 投稿文の下書き（2026-10-25 ユーザー「投稿文も生成されるよね？」）
# 手元の gemma4（0.3・think:false）で、トレンドの語を1行目に入れた X の投稿文を作る。語ごとに1日1回だけ作って保存する。
# 文字数（CJK=2・URL=23）が 280 を超えたら短くし直し、それでも駄目なら決まった型で組む（必ず何かは出す）。
# ---------------------------------------------------------------------------
DRAFTS = HERE + '/../outputs/kokkai_x_watch/trend_drafts.json'

def xlen(t):
    return sum(1 if ord(c) < 0x1100 else 2 for c in t)

def facts(key):
    """投稿文の材料: 多い順の3人・最近の発言2つ（日付・名前・会派・120字）。key は空白区切りなら全部含む発言。"""
    db = sqlite3.connect(DB)
    parts = key.split(' '); where = ' and '.join(['s.body like ?'] * len(parts)); args = ['%' + x + '%' for x in parts]
    top = db.execute(f"select g.plain, g.party, count(*) c from speech_all s join giin_all g on g.id=s.giin_id where s.kind='q' and {where} group by g.id order by c desc limit 3", args).fetchall()
    recent = []
    for plain, party, date, body in db.execute(f"select g.plain, g.party, s.date, s.body from speech_all s join giin_all g on g.id=s.giin_id where s.kind='q' and {where} order by s.date desc limit 2", args):
        i = max(0, body.find(parts[0]))
        recent.append((date, plain, party, re.sub(r'\s+', ' ', body[max(0, i - 60):i + 80])))
    return top, recent

def draft_llm(word, n, people, top, recent, url, limit=120, query=''):
    key = word.lstrip('#')
    mat = f"語: {key}\n会議録で探した語: {query or key}\n国会の質問で触れた数: {n}件・{people}人\n多い順: " + "、".join(f"{a}（{b}）{c}件" for a, b, c in top) + "\n最近の発言:\n" + "\n".join(f"- {d} {a}（{b}）: {t}" for d, a, b, t in recent)
    prompt = ("あなたは名古屋のシステム開発会社の広報です。X（旧Twitter）に投稿する日本語の文を1本だけ書いてください。\n"
              "条件: 1行目に必ず「" + key + "」の語をそのまま入れる。国会の会議録の事実（件数・議員の名前と日付・発言の要旨）だけを使い、材料に無いことは書かない。"
              "ほめ言葉・宣伝・絵文字・ハッシュタグ・URLは書かない。2〜3文、合計で全角" + str(limit) + "字以内（これより長いと使えない）。議員の名前は1〜2人まで。最後は見どころを一言。\n"
              "出力は投稿の本文だけ。前置きも説明も不要。\n\n材料:\n" + mat)
    b = json.dumps({"model": LLM, "prompt": prompt, "stream": False, "think": False, "options": {"temperature": 0.4, "num_predict": 400}}).encode()
    r = json.load(urllib.request.urlopen(urllib.request.Request(OLLAMA + "/api/generate", data=b, headers={"Content-Type": "application/json"}), timeout=180))
    t = (r.get("response") or "").strip().strip('「」"\'')
    t = re.sub(r'https?://\S+', '', t).strip()
    return t

def draft_template(word, n, people, top, recent, url):
    key = word.lstrip('#')
    who = "、".join(f"{a}議員（{b}）" for a, b, _ in top[:2]) or "複数の議員"
    body = f"「{key}」は国会でも取り上げられています。2023年以降の質問で{n}件・{people}人。多いのは{who}です。"
    if recent:
        d, a, b, _ = recent[0]
        body += f"直近は{d[:4]}年{int(d[5:7])}月の{a}議員の質疑です。"
    return body

def draft(hit):
    """投稿文（URL込み・280字以内）を返す。1日1回だけ作り、保存する。"""
    import os, time, datetime
    key = hit['word'].lstrip('#'); url = hit['url'] + '#ref=x-trend-' + datetime.date.today().strftime('%m%d')
    try:
        cache = json.load(open(DRAFTS, encoding='utf-8'))
    except Exception:
        cache = {}
    ck = key + '|' + datetime.date.today().isoformat()
    if ck in cache:
        return cache[ck]
    top, recent = facts(hit.get('query', key))
    text = ''
    for limit in (120, 95):
        try:
            t = draft_llm(hit['word'], hit['n'], hit['people'], top, recent, url, limit, hit.get('query', ''))
        except Exception:
            t = ''
        if t and key in t.split('\n')[0] and xlen(t) + 1 + 23 <= 280:
            text = t; break
    if not text:
        text = draft_template(hit['word'], hit['n'], hit['people'], top, recent, url)
        while xlen(text) + 24 > 280 and '。' in text[:-1]:
            text = text[:text.rstrip('。').rfind('。') + 1]
    post = text.rstrip() + '\n' + url
    cache[ck] = post
    os.makedirs(os.path.dirname(DRAFTS), exist_ok=True)
    json.dump(cache, open(DRAFTS, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    return post
