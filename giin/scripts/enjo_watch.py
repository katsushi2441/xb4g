#!/usr/bin/env python3
"""いま燃えている本人の投稿（2026-10-27 ユーザー「GMOの熊谷代表や石井めぐみ参議院議員などのトレンドのアカウントの投稿はメールに入れて」）。

  /usr/bin/python3 scripts/enjo_watch.py      → いま当たる投稿（メールに入る行と同じ）

探し方:
- Yahoo!リアルタイム検索（ログイン不要）で「炎上」「批判殺到」などを引き、引用・返信されている投稿のIDを数える。
- 2回以上引用・返信されている投稿を fxtwitter で開き、
  「返信＋引用がいいねより多い（燃えている形）」「表示10万以上」「3日以内」「本人の投稿（返信ではない）」だけ残す。
- 国会議員の本人なら、発言ログの議員ページを添える。
報道機関・まとめアカウントの投稿は、燃えている本人ではないので落とす（data/x_news_accounts.json と名前の語）。
一般の人（学生など）は返信先にしない。国会議員の本人か、フォロワー1万以上の人だけ。
結果は1時間に1回だけ取り直す（監視は15分おき）。取れなくてもメールは止めない。
"""
import json, os, re, sqlite3, sys, time, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
DB = HERE + '/../data/giin.sqlite'
CACHE = HERE + '/../outputs/kokkai_x_watch/enjo.json'
QUERIES = ('炎上', '炎上中', '大炎上', '批判殺到', '謝罪 炎上', '社長 炎上', '議員 炎上')
UA = {'User-Agent': 'Mozilla/5.0'}
MIN_FOLLOWERS = 10000
MEDIA_WORDS = re.compile(r'ニュース|新聞|NEWS|News|news|速報|まとめ|公式|編集部|通信|放送|TV|テレビ|Yahoo|livedoor')


def _yahoo(q, b):
    u = 'https://search.yahoo.co.jp/realtime/search?p=%s&ei=UTF-8&b=%d' % (urllib.parse.quote(q), b)
    s = urllib.request.urlopen(urllib.request.Request(u, headers=UA), timeout=30).read().decode('utf-8', 'ignore')
    m = re.search(r'<script id="__NEXT_DATA__"[^>]*>(.*?)</script>', s, re.S)
    out = []

    def walk(x):
        if isinstance(x, dict):
            if 'screenName' in x and 'displayText' in x:
                out.append(x)
            for v in x.values():
                walk(v)
        elif isinstance(x, list):
            for v in x:
                walk(v)
    walk(json.loads(m.group(1)) if m else {})
    return out


def _fx(tid):
    d = json.load(urllib.request.urlopen(urllib.request.Request('https://api.fxtwitter.com/status/' + str(tid), headers=UA), timeout=20))
    return d.get('tweet') or {}


def _news_handles():
    try:
        d = json.load(open(HERE + '/../data/x_news_accounts.json', encoding='utf-8'))
        rows = d if isinstance(d, list) else d.get('handles', d.get('accounts', d.get('items', [])))
        return {str(r.get('handle') if isinstance(r, dict) else r).lstrip('@').lower() for r in rows}
    except Exception:
        return set()


def find():
    """[{name, handle, followers, likes, replies, quotes, views, created, text, url, refs, giin_slug}] を燃え方の大きい順に。"""
    refs, seen = {}, set()
    for q in QUERIES:
        for b in (1, 11, 21, 31):
            try:
                ps = _yahoo(q, b)
            except Exception:
                continue
            for p in ps:
                if p['id'] in seen:
                    continue
                seen.add(p['id'])
                qt = p.get('quotedTweet')
                if isinstance(qt, dict) and qt.get('id'):
                    refs[str(qt['id'])] = refs.get(str(qt['id']), 0) + 1
                if p.get('inReplyTo'):
                    refs[str(p['inReplyTo'])] = refs.get(str(p['inReplyTo']), 0) + 1
            time.sleep(0.4)
    news = _news_handles()
    db = sqlite3.connect(DB)
    giin = {str(x).lstrip('@').lower(): s for x, s in db.execute("select x, slug from giin_all where x is not null and x != ''")}
    out = []
    for tid, n in sorted(refs.items(), key=lambda x: -x[1]):
        if n < 2:
            break
        try:
            t = _fx(tid)
        except Exception:
            continue
        time.sleep(0.3)
        a = t.get('author') or {}
        h = (a.get('screen_name') or '').lower()
        likes, rep, qts, views = t.get('likes') or 0, t.get('replies') or 0, t.get('quotes') or 0, t.get('views') or 0
        age = time.time() - (t.get('created_timestamp') or 0)
        if not h or t.get('replying_to') or age > 3 * 86400 or views < 100000 or rep + qts <= likes:
            continue
        if h in news or MEDIA_WORDS.search(a.get('name') or ''):
            continue
        # 一般の人（学生など）は返信先にしない。国会議員の本人か、フォロワー1万以上の公の人だけ
        if h not in giin and (a.get('followers') or 0) < MIN_FOLLOWERS:
            continue
        out.append({'name': a.get('name'), 'handle': a.get('screen_name'), 'followers': a.get('followers') or 0,
                    'likes': likes, 'replies': rep, 'quotes': qts, 'views': views, 'created': t.get('created_timestamp') or 0,
                    'text': re.sub(r'\s+', ' ', t.get('text') or '')[:200], 'url': 'https://x.com/%s/status/%s' % (a.get('screen_name'), tid),
                    'refs': n, 'giin_slug': giin.get(h)})
    out.sort(key=lambda r: -(r['replies'] + r['quotes']))
    return out


def find_cached(max_age=3600):
    try:
        c = json.load(open(CACHE, encoding='utf-8'))
        if time.time() - c.get('at', 0) < max_age:
            return c['items']
    except Exception:
        pass
    items = find()
    os.makedirs(os.path.dirname(CACHE), exist_ok=True)
    json.dump({'at': time.time(), 'items': items}, open(CACHE, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    return items


def enjo_lines(items=None):
    """メールに入れる行。取れなかったときも1行だけ書いて、メールは止めない。"""
    try:
        items = find_cached() if items is None else items
    except Exception as e:  # noqa: BLE001
        return ["", f"（いま燃えている投稿は取れませんでした: {type(e).__name__}）"]
    L = ["", "■いま燃えている本人の投稿（「炎上」「批判殺到」で話題・返信と引用がいいねより多い・表示10万以上・3日以内）"]
    if not items:
        return L + ["・いまは見当たりません"]
    for r in items[:5]:
        ago = int((time.time() - r['created']) / 3600)
        L += [f"・{r['name']}（@{r['handle']}・フォロワー{r['followers']:,}）{ago}時間前",
              f"  返信{r['replies']:,}・引用{r['quotes']:,}・いいね{r['likes']:,}・表示{r['views']:,}",
              f"  {r['text'][:140]}",
              f"  元の投稿: {r['url']}"]
        if r.get('giin_slug'):
            L.append(f"  議員ページ（国会議員 発言ログ）: https://xb4g.com/giin/{r['giin_slug']}")
        L.append("")
    return L


if __name__ == '__main__':
    print('\n'.join(enjo_lines(find())))
