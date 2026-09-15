<?php
/** 画面の枠。xb4g.com のライトテーマ（白＋ティール #0a9a8f ＋濃紺 #12202f）に合わせる。 */
declare(strict_types=1);

// 設置ごとの設定。giin_config.php があればそれを使い、無ければ既定値。
// **定数は「その行が実行されたとき」に定義される**ので、ここで先に読む。
if (is_file(__DIR__ . '/../giin_config.php')) { require __DIR__ . '/../giin_config.php'; }
if (!defined('G_BASE')) { define('G_BASE', '/giin'); }
if (!defined('G_SITE')) { define('G_SITE', '愛知の国会議員 発言ログ'); }
if (!defined('G_HOST')) { define('G_HOST', 'https://xb4g.com'); }
if (!defined('G_GA4'))  { define('G_GA4',  ''); }   // 計測は giin_config.php で入れる

/** トップ直下で予約している語。議員の slug がここに当たらないよう守る。
 *  **const は「その行が実行されたとき」に定義される**ので、ルーティングを
 *  呼ぶより前に読み込まれるこのファイルに置く（index.php の下に書くと
 *  Undefined constant になる。2026-09-14 に踏んだ）。 */
const G_RESERVED = ['list', 'theme', 'party', 'compare', 'about', 'search', 'sitemap.xml',
                    'robots.txt', 'ogp.png', 'data', 'lib', 'scripts', 'tests', 'g', 't', 'mt',
                    'news', 'ai'];

function g_url(string $p = ''): string { return G_BASE . '/' . ltrim($p, '/'); }
function g_abs(string $p = ''): string { return G_HOST . g_url($p); }

/** 移す先へ送る。**302を使う**（この作業場の決め事。301は使わない）。 */
function g_redirect(string $to): void
{
    header('Location: ' . $to, true, 302);
    echo '<a href="' . g_e($to) . '">移動しました</a>';
}

/** 構造化データ。**検索結果に出るパンくずは、URLではなくこれが決める。**
 *  URLはローマ字にしてコピペを壊さず、表示は日本語にする、という分担。 */
function g_jsonld(array $nodes): string
{
    return '<script type="application/ld+json">'
        . json_encode(['@context' => 'https://schema.org', '@graph' => $nodes],
                      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        . '</script>';
}

/** パンくず。[[表示名, パス], ...]（最後の1つは現在地） */
function g_crumbs(array $items): array
{
    $list = [];
    foreach ($items as $i => [$name, $path]) {
        $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name,
                   'item' => g_abs(ltrim($path, '/'))];
    }
    return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

function g_head(string $title, string $desc = '', string $path = '/', array $x = []): void
{
    $full = $title === '' ? G_SITE : $title . '｜' . G_SITE;
    $can = g_abs(ltrim($path, '/'));
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . g_e($full) . '</title>'
       . '<meta name="description" content="' . g_e($desc) . '">'
       . ($x['noindex'] ?? false ? '<meta name="robots" content="noindex">' : '')
       . '<link rel="canonical" href="' . g_e($can) . '">'
       . '<meta property="og:type" content="website">'
       . '<meta property="og:title" content="' . g_e($full) . '">'
       . '<meta property="og:description" content="' . g_e($desc) . '">'
       . '<meta property="og:url" content="' . g_e($can) . '">'
       . '<meta property="og:image" content="' . g_e($x['image'] ?? g_abs('ogp.png')) . '">'
       . '<meta property="og:image:width" content="1200">'
       . '<meta property="og:image:height" content="630">'
       . '<meta property="og:locale" content="ja_JP">'
       . '<meta name="twitter:card" content="summary_large_image">'
       . ($x['jsonld'] ?? '')
       // 計測は xb4g.com 本体と同じものを使う（GA4 と kurage の simpletrack）
       . (G_GA4 !== ''
          ? '<script async src="https://www.googletagmanager.com/gtag/js?id=' . G_GA4 . '"></script>'
            . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}'
            . 'gtag(\'js\',new Date());gtag(\'config\',\'' . G_GA4 . '\');</script>'
          : '')
       . '<style>' . g_css() . '</style></head><body>';
    echo '<header class="site"><div class="inner">'
       . '<a class="brand" href="' . g_url('') . '">' . g_e(G_SITE)
       . '<small>愛知の有権者が選んだ45人が、国会で何を話したか</small></a>'
       . '<form class="q" action="' . g_url('search') . '"><input type="search" name="q" '
       . 'value="' . g_e($x['q'] ?? '') . '" placeholder="ことばで探す（例: 年収の壁）">'
       . '<button>探す</button></form>'
       . '</div></header><main class="wrap">';
}

function g_foot(): void
{
    $u = g_meta('updated_at'); $f = g_meta('range_from');
    echo '</main><footer class="site"><div class="inner">'
       . '<p><b>出典</b>：国立国会図書館 国会会議録検索システム（発言本文・会議名・日付）／'
       . '衆議院 会派別議員一覧／参議院議員情報（smartnews-smri/house-of-councillors, MIT）。'
       . '本文の全文は各会議録でご確認ください。</p>'
       . '<p><b>このサイトについて</b>：要約・論評・賛否の判定は行いません。'
       . '機械的な抜粋と一次情報へのリンクだけを並べています。特定の政党・候補者を'
       . '支援する目的のサイトではありません。'
       . '収録の範囲は「愛知の有権者の一票が当落に効く議員」＝'
       . '衆議院 愛知1〜16区・比例東海ブロック・参議院 愛知県選挙区です。</p>'
       . '<p>収録期間 ' . g_e($f ?: '—') . ' 以降／最終更新 ' . g_e($u ?: '—')
       . '　<a href="' . g_url('about') . '">このサイトについて</a></p>'

       // 作っている会社。**議員ページの本文には商用の案内を挟まない。**
       // 収録している議員に売り込んでいるように見えると、この道具の中立が疑われる。
       // 出すのはフッターの一箇所だけにする。
       . '<div class="maker">'
       . '<a class="mk-logo" href="https://exbridge.jp/?ref=giin">'
       . '<img src="https://xb4g.com/images/logo-mark-64.png" alt="" width="40" height="40">'
       . '<span><b>株式会社エクスブリッジ</b><small>名古屋のAIシステム開発会社。'
       . 'この道具を作っています</small></span></a>'
       . '<div class="mk-links">'
       . '<a href="https://kappstore.exbridge.jp/app.php?id=dd868beae043026b&ref=giin">'
       . 'この道具を買い切りで（55,000円税込・MIT・MCP同梱）</a>'
       . '<a href="https://exbridge.jp/solution/seiji-dantai/?ref=giin">'
       . '政治団体・後援会・議員事務所のITを業務ごとに安くする</a>'
       . '<a href="https://exbridge.jp/ai-it-komon.html?ref=giin">AI-IT顧問契約</a>'
       . '<a href="https://kappstore.exbridge.jp/?ref=giin">Kurage App Store</a>'
       . '<a href="https://xb4g.com/">エクスブリッジ 第4世代</a>'
       . '</div>'
       . '<p class="note" style="margin:8px 0 0">'
       . '当社は「政治活動の事務を安くする」範囲に限って支援します。'
       . '特定の政党・候補者を支援することはありません。'
       . '<b>発言の収録は、収録対象や取引先に左右されません</b>'
       . '（45人全員を同じ処理で取り込み、同じ規則で分類しています）。'
       . 'ただし公式サイトやSNSのリンクは手作業で足しているため、'
       . '<b>いまは' . (int)g_links_count() . '人ぶんしか入っていません。</b>'
       . '<a href="' . g_url('about') . '#links">なぜそうなっているか</a></p>'
       . '</div>'
       . '</div></footer>'
       . '<script>(function(){var s=document.createElement("script");'
       . 's.src="https://kurage.exbridge.jp/simpletrack.php?url="+encodeURIComponent(location.href)'
       . '+"&ref="+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>'
       . '</body></html>';
}

function g_css(): string
{
    return <<<'CSS'
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;
color:#12202f;background:#f7f9fa;line-height:1.75;font-size:15px}
a{color:#0a726b}
header.site{background:#fff;border-bottom:1px solid #e3e9ec}
header.site .inner,footer.site .inner{max-width:1000px;margin:0 auto;padding:14px 16px}
header.site .inner{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.brand{font-weight:700;font-size:18px;text-decoration:none;color:#12202f;line-height:1.35}
.brand small{display:block;font-weight:400;font-size:12px;color:#5d6b7a}
.q{margin-left:auto;display:flex;gap:6px;flex:1 1 280px;max-width:420px;min-width:0}
.q input{flex:1;min-width:0;padding:9px 12px;font-size:16px;border:2px solid #cfdae4;border-radius:9px}
.q button{padding:9px 16px;border:0;border-radius:9px;background:#0a9a8f;color:#fff;font-weight:700;cursor:pointer}
.wrap{max-width:1000px;margin:0 auto;padding:20px 16px 40px}
h1{font-size:23px;margin:0 0 6px}
h2{font-size:18px;margin:28px 0 10px;padding-bottom:6px;border-bottom:2px solid #e3e9ec}
.lead{color:#5d6b7a;margin:0 0 18px}
.crumb{font-size:13px;color:#5d6b7a;margin-bottom:10px}
.panel{background:#fff;border:1px solid #e3e9ec;border-radius:12px;padding:16px;margin-bottom:14px}
.panel.insight{border-left:4px solid #0a9a8f}
.panel.uniq{border-left:4px solid #2b4a8b}
.panel.insight p{line-height:1.85;margin:0 0 10px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(240px,100%),1fr));gap:12px}
.card{display:block;background:#fff;border:1px solid #e3e9ec;border-radius:12px;padding:13px 14px;
text-decoration:none;color:inherit}
.card:hover{border-color:#0a9a8f}
.card .nm{font-weight:700;font-size:16px}
.card .mt{font-size:12px;color:#5d6b7a;margin-top:3px}
.card .n{font-size:13px;color:#0a726b;font-weight:700;margin-top:6px}
.pill{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:2px 9px;
background:#e6f4f2;color:#0a726b;border:1px solid #bfe3de;white-space:nowrap}
.pill.gray{background:#eef2f4;color:#5d6b7a;border-color:#dde4e8}
.sp{background:#fff;border:1px solid #e3e9ec;border-radius:12px;padding:14px;margin-bottom:10px}
.sp .m{font-size:12.5px;color:#5d6b7a;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.sp .t{margin:7px 0 0}
.sp mark{background:#fff3b0;padding:0 1px}
.sp .lk{font-size:12.5px;margin-top:8px}
.nw{background:#fff;border:1px solid #e3e9ec;border-left:3px solid #0a9a8f;border-radius:10px;padding:11px 13px;margin-bottom:8px}
.nw .m{font-size:12.5px;color:#5d6b7a;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.nw .t{margin:5px 0 0;font-size:14.5px;line-height:1.6}
.nw .lk{font-size:12.5px;color:#5d6b7a;margin-top:6px}
.top3{display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 0}
.top3 a{font-size:13px;background:#e6f4f2;border:1px solid #bfe3de;border-radius:999px;padding:3px 11px;text-decoration:none}
.offl{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 6px}
.offl a{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;background:#fff;border:1px solid #cfdae4;
border-radius:999px;padding:6px 14px;text-decoration:none;color:#12202f}
.offl a:hover{border-color:#0a9a8f}
.offl a b{color:#0a726b;font-size:14px}
.vgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:12px}
.vcard{background:#fff;border:1px solid #e3e9ec;border-radius:12px;overflow:hidden}
.vcard iframe{width:100%;aspect-ratio:16/9;border:0;display:block}
.vthumb{display:block;width:100%;padding:0;border:0;background:#000;cursor:pointer;position:relative;line-height:0}
.vthumb img{width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;display:block;opacity:.92}
.vthumb:hover img{opacity:1}
.vplay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
font-size:34px;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.6)}
.vmeta{padding:9px 11px;font-size:13.5px;line-height:1.6}
.vmeta a{display:block;margin-top:2px}
.xp{background:#fff;border:1px solid #e3e9ec;border-radius:12px;padding:12px 14px;margin-bottom:8px}
.xp .m{font-size:12.5px;color:#5d6b7a;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.xp .t{margin:6px 0 0;font-size:14.5px;line-height:1.75;white-space:pre-wrap}
.xp .lk{font-size:12.5px;margin-top:7px}
.maker{border-top:1px solid #e3e9ec;margin-top:14px;padding-top:14px}
.mk-logo{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:#12202f}
.mk-logo img{border-radius:8px}
.mk-logo b{font-size:14px}
.mk-logo small{display:block;font-size:12px;color:#5d6b7a;font-weight:400}
.mk-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.mk-links a{font-size:13px;background:#fff;border:1px solid #cfdae4;border-radius:999px;
padding:6px 13px;text-decoration:none;color:#12202f}
.mk-links a:hover{border-color:#0a9a8f}
.mk-links a:first-child{background:#e6f4f2;border-color:#bfe3de;color:#0a726b;font-weight:700}
table{width:100%;border-collapse:collapse;font-size:14px;background:#fff}
th,td{border:1px solid #e3e9ec;padding:8px 10px;text-align:left;vertical-align:top}
th{background:#f5f8f9;white-space:nowrap}
td.n,th.n{text-align:right;white-space:nowrap}
.bar{height:8px;background:#e6f4f2;border-radius:4px;overflow:hidden;min-width:60px}
.bar i{display:block;height:100%;background:#0a9a8f}
.pager{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.pager a,.pager span{padding:7px 12px;border:1px solid #cfdae4;border-radius:8px;background:#fff;
text-decoration:none;font-size:14px}
.pager .now{background:#0a9a8f;color:#fff;border-color:#0a9a8f;font-weight:700}
.note{font-size:13px;color:#5d6b7a}
footer.site{background:#fff;border-top:1px solid #e3e9ec;margin-top:30px}
footer.site p{font-size:12.5px;color:#5d6b7a;margin:0 0 8px}
.scroll{overflow-x:auto}
@media(max-width:640px){.brand{font-size:16px}.q{margin-left:0}h1{font-size:20px}}
CSS;
}

/** 議員の小さなカード */
function g_card(array $g): string
{
    return '<a class="card" href="' . g_url($g['slug']) . '">'
        . '<div class="nm">' . g_e($g['plain']) . '</div>'
        . '<div class="mt">' . g_e($g['house']) . ' ' . g_e(g_ku($g['district'])) . '</div>'
        . '<div class="mt"><span class="pill">' . g_e($g['party']) . '</span></div>'
        . '<div class="n">質疑 ' . number_format((int)$g['n_q']) . '件</div></a>';
}

/** 選挙区の表記を整える（（比）東海 → 比例東海） */
function g_ku(string $d): string
{
    if (strpos($d, '東海') !== false) { return '比例東海'; }
    return preg_match('/^愛知(\d+)$/u', $d, $m) ? '愛知' . $m[1] . '区' : $d;
}

/** 動きを1件出す。**見出しと日付と発表元とリンクだけ。** */
function g_news_item(array $n, bool $compact = false): string
{
    $badge = $n['source'] === 'gian' ? '議案' : g_e($n['publisher']);
    $cls = $n['source'] === 'gian' ? 'pill' : 'pill gray';
    $h = '<div class="nw"><div class="m">'
       . '<b>' . g_e(g_date($n['date'])) . '</b>'
       . '<span class="' . $cls . '">' . $badge . '</span>'
       . ($n['source'] === 'gian' && $n['kind'] ? '<span class="note">' . g_e($n['kind']) . '</span>' : '')
       . '</div>'
       . '<p class="t">' . ($n['url']
            ? '<a href="' . g_e($n['url']) . '" rel="nofollow noopener" target="_blank">'
              . g_e($n['title']) . '</a>'
            : g_e($n['title'])) . '</p>';
    if (!$compact && $n['source'] === 'gian' && $n['submitter']) {
        $h .= '<div class="lk">提出 ' . g_e($n['submitter']);
        if ($n['giin_id']) {
            $g = g_one('SELECT plain,slug FROM giin WHERE id=?', [(int)$n['giin_id']]);
            if ($g) { $h .= '　<a href="' . g_url($g['slug']) . '">' . g_e($g['plain']) . 'のページ</a>'; }
        }
        $h .= '</div>';
    }
    return $h . '</div>';
}

/** 公式リンクの並び。どこで確認したかも一緒に出す。 */
function g_official(array $L): string
{
    if (!$L) { return ''; }
    $items = [
        'site'      => ['公式サイト', '🔗'],
        'x'         => ['X', '𝕏'],
        'youtube'   => ['YouTube', '▶'],
        'instagram' => ['Instagram', '◎'],
        'facebook'  => ['Facebook', 'f'],
        'line'      => ['LINE', 'L'],
        'note'      => ['note', 'n'],
    ];
    $h = '<div class="offl">';
    foreach ($items as $k => [$label, $icon]) {
        if (empty($L[$k])) { continue; }
        $h .= '<a href="' . g_e($L[$k]) . '" rel="nofollow noopener" target="_blank">'
            . '<b>' . $icon . '</b>' . g_e($label) . '</a>';
    }
    $h .= '</div>';
    if (!empty($L['source'])) {
        $h .= '<p class="note">これらは <a href="' . g_e($L['source']) . '" rel="nofollow noopener" '
            . 'target="_blank">本人の公式サイト</a>に掲載されているリンクです'
            . (!empty($L['checked_at']) ? '（' . g_e($L['checked_at']) . ' 確認）' : '') . '。'
            . '検索結果から拾ったものは載せていません。</p>';
    }
    return $h;
}

/** YouTube新着。**サムネを押すまで YouTube は読み込まない**（勝手に通信させない）。
 *  再生は youtube-nocookie.com の公式埋め込みに任せる。 */
function g_video_grid(array $vs): string
{
    if (!$vs) { return ''; }
    $h = '<div class="vgrid">';
    foreach ($vs as $v) {
        $h .= '<div class="vcard" data-vid="' . g_e($v['video_id']) . '">'
            . '<button class="vthumb" type="button" aria-label="再生: ' . g_e($v['title']) . '">'
            . '<img src="' . g_e($v['thumb']) . '" alt="" loading="lazy" width="480" height="360">'
            . '<span class="vplay">▶</span></button>'
            . '<div class="vmeta"><span class="note">' . g_e(g_date($v['published'])) . '</span>'
            . '<a href="https://www.youtube.com/watch?v=' . g_e($v['video_id'])
            . '" rel="nofollow noopener" target="_blank">' . g_e($v['title']) . '</a></div></div>';
    }
    return $h . '</div>'
        . '<script>document.querySelectorAll(".vcard .vthumb").forEach(function(b){'
        . 'b.addEventListener("click",function(){var c=b.closest(".vcard"),i=document.createElement("iframe");'
        . 'i.src="https://www.youtube-nocookie.com/embed/"+c.dataset.vid+"?autoplay=1&rel=0";'
        . 'i.title="YouTube";i.allow="accelerometer;autoplay;clipboard-write;encrypted-media;picture-in-picture";'
        . 'i.allowFullscreen=true;i.loading="lazy";b.replaceWith(i)})});</script>';
}

/** Xの投稿1件。**抜粋とXへのリンクだけ。本文の全文はXで読んでもらう。** */
function g_xpost(array $p): string
{
    $u = 'https://x.com/' . rawurlencode($p['screen_name']) . '/status/' . rawurlencode($p['post_id']);
    $t = g_excerpt($p['body'], 220);
    return '<div class="xp"><div class="m"><b>' . g_e($p['posted']) . '</b>'
        . '<span class="pill gray">@' . g_e($p['screen_name']) . '</span>'
        . ((int)$p['is_repost'] ? '<span class="pill gray">リポスト</span>' : '')
        . '</div>'
        . '<p class="t">' . g_e($t) . '</p>'
        . '<div class="lk"><a href="' . g_e($u) . '" rel="nofollow noopener" target="_blank">'
        . 'Xで読む</a></div></div>';
}
