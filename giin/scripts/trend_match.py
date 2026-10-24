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
