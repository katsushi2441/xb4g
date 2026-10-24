#!/usr/bin/env python3
"""X の日本のトレンド（trends24.in の写し・毎時）を取り、国会の発言ログ・国会トラッカー・質問主意書と突き合わせる。
  /usr/bin/python3 scripts/trend_match.py            → いま当たる語と、付けられる当社ページ（発言ログの検索・トラッカー）
  /usr/bin/python3 scripts/trend_match.py --min 5    → 質疑が5件以上ある語だけ
トレンドの語に国会の質疑があれば、その語の検索ページ（件数入りのカード）を X の投稿に付けられる。
"""
import json, re, sqlite3, sys, urllib.request, html
HERE = __import__('os').path.dirname(__import__('os').path.abspath(__file__))
DB = HERE + '/../data/giin.sqlite'; TR = HERE + '/../data/trackers.json'
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

def match(min_n=3, use_cache=True):
    """いまのトレンド語のうち、国会の質疑かトラッカーに当たるものを [{word,n,people,url,trackers}] で返す。"""
    import urllib.parse
    db = sqlite3.connect(DB)
    trackers = json.load(open(TR)); trackers = trackers if isinstance(trackers, list) else trackers.get('trackers', trackers)
    out = []
    for w in (trends_cached() if use_cache else trends()):
        key = w.lstrip('#')
        if len(key) < 2 or (re.fullmatch(r'[A-Za-z0-9#_]+', key) and len(key) < 4): continue
        n, p = db.execute("select count(*),count(distinct giin_id) from speech_all where kind='q' and body like ?", ('%' + key + '%',)).fetchone()
        if n and re.fullmatch(r'[ァ-ヶー]+', key):
            pat = re.compile(r'(?<![ァ-ヶー])' + re.escape(key) + r'(?![ァ-ヶー])')
            rows = [(gid, body) for gid, body in db.execute("select giin_id, body from speech_all where kind='q' and body like ?", ('%' + key + '%',)) if pat.search(body)]
            n, p = len(rows), len({g for g, _ in rows})
        tr = [t['key'] for t in trackers if key in json.dumps(t, ensure_ascii=False)]
        if n >= min_n or tr:
            out.append({'word': w, 'n': n, 'people': p, 'url': 'https://xb4g.com/giin/search?q=' + urllib.parse.quote(key),
                        'trackers': ['https://xb4g.com/giin/tracker/' + k for k in tr]})
    return out

def main():
    db = sqlite3.connect(DB)
    trackers = json.load(open(TR)); trackers = trackers if isinstance(trackers, list) else trackers.get('trackers', trackers)
    ws = trends()
    print(f'トレンド {len(ws)} 語（直近）')
    hits = 0
    for w in ws:
        key = w.lstrip('#')
        if len(key) < 2 or re.fullmatch(r'[A-Za-z0-9#_]+', key) and len(key) < 4: continue
        n, p = db.execute("select count(*),count(distinct giin_id) from speech_all where kind='q' and body like ?", ('%' + key + '%',)).fetchone()
        # カタカナ語は長い語の一部（スーパーマーケットの「パーマー」など）に当たるので、前後がカタカナでない出現だけ数え直す
        if n and re.fullmatch(r'[ァ-ヶー]+', key):
            pat = re.compile(r'(?<![ァ-ヶー])' + re.escape(key) + r'(?![ァ-ヶー])')
            ids = set()
            for gid, body in db.execute("select giin_id, body from speech_all where kind='q' and body like ?", ('%' + key + '%',)):
                if pat.search(body): ids.add(gid); n2 = ids
            n, p = (len([1 for gid, body in db.execute("select giin_id, body from speech_all where kind='q' and body like ?", ('%' + key + '%',)) if pat.search(body)]), len(ids))
        tr = [t['key'] for t in trackers if key in json.dumps(t, ensure_ascii=False)]
        if n >= MIN or tr:
            hits += 1
            url = 'https://xb4g.com/giin/search?q=' + urllib.parse.quote(key)
            print(f'  {w:20s} 質疑 {n:4d}件・{p:3d}人  {url}' + (f'  トラッカー: ' + ', '.join(tr) if tr else ''))
    if not hits: print('  国会の質疑に当たる語はありませんでした')

if __name__ == '__main__':
    import urllib.parse
    main()


# ---------------------------------------------------------------------------
# 投稿文の下書き（2026-10-25 ユーザー「投稿文も生成されるよね？」）
# 手元の gemma4（0.3・think:false）で、トレンドの語を1行目に入れた X の投稿文を作る。語ごとに1日1回だけ作って保存する。
# 文字数（CJK=2・URL=23）が 280 を超えたら短くし直し、それでも駄目なら決まった型で組む（必ず何かは出す）。
# ---------------------------------------------------------------------------
OLLAMA = 'http://192.168.0.3:11434'
LLM = 'gemma4:12b-it-qat'
DRAFTS = HERE + '/../outputs/kokkai_x_watch/trend_drafts.json'

def xlen(t):
    return sum(1 if ord(c) < 0x1100 else 2 for c in t)

def facts(key):
    """投稿文の材料: 多い順の3人・最近の発言2つ（日付・名前・会派・120字）。"""
    db = sqlite3.connect(DB)
    top = db.execute("select g.plain, g.party, count(*) c from speech_all s join giin_all g on g.id=s.giin_id where s.kind='q' and s.body like ? group by g.id order by c desc limit 3", ('%' + key + '%',)).fetchall()
    recent = []
    for plain, party, date, body in db.execute("select g.plain, g.party, s.date, s.body from speech_all s join giin_all g on g.id=s.giin_id where s.kind='q' and s.body like ? order by s.date desc limit 2", ('%' + key + '%',)):
        i = body.find(key)
        recent.append((date, plain, party, re.sub(r'\s+', ' ', body[max(0, i - 60):i + 80])))
    return top, recent

def draft_llm(word, n, people, top, recent, url, limit=120):
    key = word.lstrip('#')
    mat = f"語: {key}\n国会の質問で触れた数: {n}件・{people}人\n多い順: " + "、".join(f"{a}（{b}）{c}件" for a, b, c in top) + "\n最近の発言:\n" + "\n".join(f"- {d} {a}（{b}）: {t}" for d, a, b, t in recent)
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
    top, recent = facts(key)
    text = ''
    for limit in (120, 95):
        try:
            t = draft_llm(hit['word'], hit['n'], hit['people'], top, recent, url, limit)
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
