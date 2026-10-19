#!/usr/bin/env python3
"""愛知の国会議員の投稿に反応している人（返信・言及）から、愛知の経営者らしい人を探す。
  /usr/bin/python3 scripts/find_aichi_owners.py            # 愛知の議員24人ぶん
  /usr/bin/python3 scripts/find_aichi_owners.py 伊藤孝恵   # 一部の議員だけ
  /usr/bin/python3 scripts/find_aichi_owners.py "q:名古屋 社長 最低賃金"   # 語で引く（経営者の時事）
X本体はログインが要り、fxtwitter の検索は止まることがあるので、Yahoo!リアルタイム検索で
「@議員のハンドル」を引き、出てきた人の自己紹介を api.fxtwitter.com/<handle> で読む。
  /usr/bin/python3 scripts/find_aichi_owners.py --daily   # 返信候補の毎日の回（x_reply_jobs.py が1日1回呼ぶ）
結果: outputs/aichi_owners/scan.json（調べた人・追記）と owners.md（経営者らしい人）。
経営者らしい人は data/x_aichi_owners.json（返信候補の「愛知の経営者」枠で時系列を読む名簿）に足す。
名簿から外したい人は、名簿で "skip": true にする（次の回でも足し直さない）。"""
import json, os, re, sys, time, urllib.parse, urllib.request
ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'outputs', 'aichi_owners', 'scan.json')
MD = os.path.join(ROOT, 'outputs', 'aichi_owners', 'owners.md')
REG = os.path.join(ROOT, 'data', 'x_aichi_owners.json')
KX = os.path.join(ROOT, 'data', 'kokkai_x.json')
# 毎日の回で引く語（経営者が自己紹介や投稿に書く言葉と、経営者の時事）。2026-10-19 の実測で、議員への返信から
# 見つかる愛知の経営者は344人中ほぼ0、語で引くと552人中9人だった
DAILY_Q = ['名古屋 社長', '愛知 経営者', '名古屋 経営者', '中小企業 名古屋', '最低賃金 愛知', '名古屋 代表取締役',
           '三河 社長', '豊田 社長', '岡崎 社長', '名古屋 採用', '名古屋 補助金']
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'
OWNER = ('代表取締役', '取締役', '社長', '経営者', '経営', '創業', 'CEO', 'ceo', '代表', 'オーナー', '会長', '事業主', '院長', '税理士', '社労士', '行政書士', '中小企業')
AICHI = ('愛知', '名古屋', '豊田', '豊橋', '岡崎', '一宮', '春日井', '刈谷', '安城', '豊川', '西尾', '小牧', '稲沢', '瀬戸', '半田', '東海市', '大府', '知多', '尾張', '三河', '日進', '長久手', '北名古屋', '清須', '津島', '犬山', '江南', '蒲郡', '碧南', '知立', 'みよし', '常滑')
NG_DESC = r'応援|サポーター|党員|支持者|ファン|推し|[都道府県市区町村]会議員|企業内'   # 「〇〇議員応援サポーター会長」は経営者ではない
NG = ('xb_bittensor', '議員', '事務所', '後援会', '公式', 'bot', 'Bot', 'BOT', 'ニュース', '新聞', '放送')
os.makedirs(os.path.dirname(OUT), exist_ok=True)
res = json.load(open(OUT, encoding='utf-8')) if os.path.exists(OUT) else {}

def walk(o, acc):
    if isinstance(o, dict):
        if 'screenName' in o and ('text' in o or 'displayText' in o): acc.append(o)
        for v in o.values(): walk(v, acc)
    elif isinstance(o, list):
        for v in o: walk(v, acc)

def yahoo(q, b=1):
    u = 'https://search.yahoo.co.jp/realtime/search?' + urllib.parse.urlencode({'p': q, 'ei': 'UTF-8', 'b': b})
    h = urllib.request.urlopen(urllib.request.Request(u, headers={'User-Agent': UA}), timeout=40).read().decode('utf-8', 'replace')
    m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', h, re.S)
    if not m: return []
    acc = []; walk(json.loads(m.group(1)), acc); return acc

def profile(sn):
    r = urllib.request.urlopen(urllib.request.Request('https://api.fxtwitter.com/' + sn, headers={'User-Agent': UA}), timeout=30)
    u = json.load(r).get('user') or {}
    return {'name': u.get('name') or '', 'desc': u.get('description') or '', 'location': u.get('location') or '',
            'followers': u.get('followers') or 0, 'website': ((u.get('website') or {}).get('url') if isinstance(u.get('website'), dict) else u.get('website')) or ''}

members = [m for m in json.load(open(KX, encoding='utf-8')) if m.get('x') and (m.get('district') or '').startswith('愛知')]
DAILY = '--daily' in sys.argv
QUERIES = [a[2:] for a in sys.argv[1:] if a.startswith('q:')] + (DAILY_Q if DAILY else [])   # q:語 … 議員ではなく語で引く（経営者が話題にする時事）
NAMES = [a for a in sys.argv[1:] if not a.startswith(('q:', '--'))]
if NAMES: members = [m for m in members if any(a in m['name'] for a in NAMES)]
elif QUERIES: members = []
handles = {m['x'].lower() for m in json.load(open(KX, encoding='utf-8')) if m.get('x')}
for m in members:
    got = 0
    for b in (1, 11, 21):
        try: items = yahoo('@' + m['x'], b)
        except Exception as e: print('  取得失敗', m['name'], e); break
        if not items: break
        for t in items:
            sn = t.get('screenName') or ''
            if not sn or sn.lower() in handles: continue
            tid = str(t.get('id') or '')
            row = res.setdefault(sn, {'posts': [], 'giin': []})
            url = f'https://x.com/{sn}/status/{tid}'
            if url not in [p['url'] for p in row['posts']]:
                row['posts'].append({'url': url, 'text': (t.get('text') or t.get('displayText') or '')[:200], 'time': t.get('time') or ''})
            if m['name'] not in row['giin']: row['giin'].append(m['name']); got += 1
        time.sleep(1.5)
    print(f"{m['name']}（@{m['x']}）: {got}人", flush=True)

for q in QUERIES:
    got = 0
    for b in (1, 11, 21):
        try: items = yahoo(q, b)
        except Exception as e: print('  取得失敗', q, e); break
        if not items: break
        for t in items:
            sn = t.get('screenName') or ''
            if not sn or sn.lower() in handles: continue
            row = res.setdefault(sn, {'posts': [], 'giin': []})
            url = f"https://x.com/{sn}/status/{t.get('id')}"
            if url not in [p['url'] for p in row['posts']]:
                row['posts'].append({'url': url, 'text': (t.get('text') or '')[:200], 'time': t.get('time') or '', 'q': q}); got += 1
        time.sleep(1.5)
    print(f'{q}: {got}件', flush=True)

for sn, row in res.items():
    if 'desc' in row: continue
    try: row.update(profile(sn))
    except Exception as e: row.update({'name': '', 'desc': '', 'location': '', 'followers': 0, 'website': '', 'err': str(e)[:80]})
    time.sleep(1.0)
for sn, row in res.items():
    s = row.get('name', '') + ' ' + row.get('desc', '')
    row['owner'] = [w for w in OWNER if w in s]
    row['aichi'] = [w for w in AICHI if w in s + ' ' + row.get('location', '')]
    row['ng'] = any(w in row.get('name', '') for w in NG) or sn in NG or bool(re.search(NG_DESC, row.get('desc', '')[:80]))
json.dump(res, open(OUT, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)

hit = sorted([(sn, r) for sn, r in res.items() if r['owner'] and r['aichi'] and not r['ng']], key=lambda x: (-len(x[1]['giin']), -len(x[1]['posts'])))
with open(MD, 'w', encoding='utf-8') as f:
    f.write(f'# 愛知の議員に反応している、愛知の経営者らしい人（{len(hit)}人／調べた {len(res)}人）\n\n')
    for sn, r in hit:
        f.write(f"## {r['name']}（@{sn}）\n- 場所: {r['location']}／フォロワー {r['followers']}\n- 自己紹介: {r['desc'][:160]}\n- 反応した議員: {'・'.join(r['giin']) or 'なし（語で見つけた）'}\n")
        for p in r['posts'][:2]: f.write(f"- {p['url']}　{p['text'][:80]}\n")
        f.write('\n')
# 返信候補の名簿に足す（フォロワー50人以上・議員や首長ではない人）。外した人（skip）はそのまま
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import x_reply_pick as X  # noqa: E402
reg = json.load(open(REG, encoding='utf-8')) if os.path.exists(REG) else {}
added = []
for sn, r in hit:
    if sn in reg or (r.get('followers') or 0) < 50 or X.is_politician({'name': r['name'], 'description': r['desc']}):
        continue
    reg[sn] = {'name': r['name'], 'desc': r['desc'][:120], 'location': r['location'], 'followers': r['followers'],
               'found': time.strftime('%Y-%m-%d'), 'via': '・'.join(r['giin']) or (r['posts'][0].get('q') if r['posts'] else '')}
    added.append(sn)
json.dump(dict(sorted(reg.items())), open(REG, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
print(f'調べた {len(res)}人 → 愛知の経営者らしい人 {len(hit)}人 → {MD}')
print(f'返信候補の名簿 {REG}: {sum(1 for v in reg.values() if not v.get("skip"))}人（今回足した {len(added)}人: {" ".join(added)}）')
