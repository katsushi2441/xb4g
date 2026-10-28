#!/usr/bin/env python3
"""いま燃えている本人の投稿（2026-10-27 ユーザー「GMOの熊谷代表や石井めぐみ参議院議員などのトレンドのアカウントの投稿はメールに入れて」）。

  /usr/bin/python3 scripts/enjo_watch.py      → いま当たる投稿（メールに入る行と同じ）

探し方:
- Yahoo!リアルタイム検索（ログイン不要）で「炎上」「批判殺到」などを引き、引用・返信されている投稿のIDを数える。
- 2回以上引用・返信されている投稿を fxtwitter で開き、
  「返信＋引用がいいねより多い、または引用がいいねの2割以上（燃えている形）」「表示10万以上」「3日以内」「本人の投稿（返信ではない）」だけ残す。
- 国会議員の本人なら、発言ログの議員ページを添える。
報道機関・まとめアカウントの投稿は、燃えている本人ではないので落とす（data/x_news_accounts.json と名前の語）。
一般の人（学生など）は返信先にしない。国会議員の本人か、フォロワー1万以上の人だけ。
結果は30分に1回だけ取り直す（監視は15分おき）。取れなくてもメールは止めない。

一度メールで知らせた投稿は二度出さない（2026-10-28 ユーザー「本人の投稿に対して1回作って1回通知すればいい。他の炎上をまた探して」）。
知らせた投稿は outputs/kokkai_x_watch/enjo_sent.json に残し、次からは外して、ほかの燃えている投稿を出す。
返信候補はその投稿について1回だけ作る（x_reply_draft.one の作り置き）。
"""
import json, os, re, sqlite3, sys, time, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
DB = HERE + '/../data/giin.sqlite'
CACHE = HERE + '/../outputs/kokkai_x_watch/enjo.json'
SENT = HERE + '/../outputs/kokkai_x_watch/enjo_sent.json'
# 知らせた投稿を外すと候補が減るので、語を広げてほかの炎上も拾う
QUERIES = ('炎上', '炎上中', '大炎上', '批判殺到', '謝罪 炎上', '社長 炎上', '議員 炎上', '物議', '物議を醸', '批判の声', '苦言', '反発 投稿', '釈明', '撤回 謝罪')
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
        # 燃えている形：返信＋引用がいいねより多い、または引用がいいねの2割以上（引用は批判に使われやすい。熊谷代表の投稿は引用が4割）
        burning = rep + qts > likes or (qts >= 50 and qts >= 0.2 * likes)
        if not h or t.get('replying_to') or age > 3 * 86400 or views < 100000 or not burning:
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


def find_cached(max_age=1800):
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


def _sent():
    try:
        return json.load(open(SENT, encoding='utf-8'))
    except Exception:
        return {}


def pending(limit=5):
    """まだ知らせていない燃えている投稿（燃え方の大きい順）"""
    sent = _sent()
    return [r for r in find_cached() if r['url'].rsplit('/', 1)[-1] not in sent][:limit]


def mark_sent(items):
    """メールが送れたあとに呼ぶ。知らせた投稿は二度出さない（4日たったものは記録から消す＝3日以内の投稿しか拾わないため）"""
    if not items:
        return
    sent = {k: v for k, v in _sent().items() if time.time() - v < 4 * 86400}
    for r in items:
        sent[r['url'].rsplit('/', 1)[-1]] = time.time()
    os.makedirs(os.path.dirname(SENT), exist_ok=True)
    json.dump(sent, open(SENT, 'w', encoding='utf-8'))


def prebuild(items):
    """返信候補をその投稿について1回だけ作る（作り置きは x_reply_draft.one のキャッシュ。メールのリンクを押すとすぐ出る）"""
    done = set()
    try:
        import x_reply_draft as D
    except Exception:  # noqa: BLE001
        return done
    for r in items:
        try:
            if D.one(r['url']).get('html'):
                done.add(r['url'])
        except Exception:  # noqa: BLE001  作れなくてもメールは送る（押せばその場で作る）
            pass
    return done


def enjo_lines(items=None, reply_page=None, ready=()):
    """メールに入れる行。items は pending() の結果（まだ知らせていないものだけ）。取れなかったときも1行だけ書いて、メールは止めない。"""
    try:
        items = pending() if items is None else items
    except Exception as e:  # noqa: BLE001
        return ["", f"（いま燃えている投稿は取れませんでした: {type(e).__name__}）"]
    L = ["", "■いま燃えている本人の投稿（まだ知らせていないものだけ・返信と引用が多い・表示10万以上・3日以内）"]
    if not items:
        return L + ["・新しく燃えている投稿は、いまは見当たりません"]
    for r in items:
        ago = int((time.time() - r['created']) / 3600)
        L += [f"・{r['name']}（@{r['handle']}・フォロワー{r['followers']:,}）{ago}時間前",
              f"  返信{r['replies']:,}・引用{r['quotes']:,}・いいね{r['likes']:,}・表示{r['views']:,}",
              f"  {r['text'][:140]}",
              f"  元の投稿: {r['url']}"]
        if reply_page:
            L.append(f"  返信候補{'（作成済み・開くとすぐ出ます）' if r['url'] in ready else 'を作る（開くと作り始めます）'}: {reply_page}?url={urllib.parse.quote(r['url'], safe='')}")
        if r.get('giin_slug'):
            L.append(f"  議員ページ（国会議員 発言ログ）: https://xb4g.com/giin/{r['giin_slug']}")
        L.append("")
    return L


if __name__ == '__main__':
    print('\n'.join(enjo_lines(pending())))
