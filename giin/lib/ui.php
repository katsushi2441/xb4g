<?php
/** 画面の枠。xb4g.com のライトテーマ（白＋ティール #0a9a8f ＋濃紺 #12202f）に合わせる。
 *  2026-09-17 に team-mir.ai を手本に作り直した（ミントの帯・大きな数字・丸いボタン・影つきカード）。ダークにはしない。 */
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
                    'news', 'ai', 'tracker'];

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
       . '<link rel="preconnect" href="https://fonts.googleapis.com">'
       . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&display=swap" rel="stylesheet">'
       . '<style>' . g_css() . '</style></head><body>';
    // 見た目は team-mir.ai を手本にしたライトテーマ（白＋ミントの帯＋大きな数字）。色は xb4g のティール。
    $nav = [['list', '議員一覧'], ['theme', 'ことがら'], ['tracker', '国会トラッカー'], ['news', '最近の動き'],
            ['ai', 'AI特集'], ['compare', 'ことがら×議員'], ['about', 'このサイトについて']];
    $cur = trim($path, '/');
    $navh = '';
    foreach ($nav as [$p, $label]) {
        $on = $cur === $p || strpos($cur, $p . '/') === 0;
        $navh .= '<a href="' . g_url($p) . '"' . ($on ? ' class="on"' : '') . '>' . $label . '</a>';
    }
    echo '<header class="site"><div class="inner">'
       . '<a class="brand" href="' . g_url('') . '"><span class="mark" aria-hidden="true">議</span><span>' . g_e(G_SITE)
       . '<small>愛知の有権者が選んだ45人が、国会で何を話したか</small></span></a>'
       . '<form class="q" action="' . g_url('search') . '"><input type="search" name="q" '
       . 'value="' . g_e($x['q'] ?? '') . '" placeholder="議員名、またはことばで探す（例: 福田徹、年収の壁）">'
       . '<button>探す</button></form>'
       . '<nav class="gnav" aria-label="サイト内">' . $navh . '</nav>'
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
:root{--teal:#0a9a8f;--teal-d:#0a726b;--teal-l:#30bca7;--mint:#64d8c6;--mint-l:#bcecd3;--mint-xl:#e6f7f3;--navy:#12202f;--ink:#24384a;--gray:#5d6b7a;--line:#e3e9ec;--r:16px;--sh:0 2px 8px rgba(18,32,47,.07);--sh2:0 10px 26px rgba(10,154,143,.16)}
*{box-sizing:border-box;min-width:0}
html{-webkit-text-size-adjust:100%}
body{margin:0;font-family:"Noto Sans JP","Hiragino Kaku Gothic ProN","Hiragino Sans",system-ui,-apple-system,sans-serif;
color:var(--navy);background:#fff;line-height:1.85;font-size:15.5px;font-feature-settings:"palt";overflow-x:hidden}
a{color:var(--teal-d);text-decoration-thickness:1px;text-underline-offset:3px}
a:hover{color:var(--teal)}
img{max-width:100%;height:auto}
b,strong{font-weight:700}
header.site{position:sticky;top:0;z-index:30;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
header.site .inner,footer.site .inner{max-width:1080px;margin:0 auto;padding:12px 16px}
header.site .inner{display:flex;gap:10px 18px;align-items:center;flex-wrap:wrap;padding-bottom:0}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--navy);font-weight:900;font-size:17px;line-height:1.25}
.brand .mark{flex:none;width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,var(--teal-l),var(--teal-d));color:#fff;display:grid;place-items:center;font-size:18px;font-weight:900;box-shadow:var(--sh)}
.brand small{display:block;font-weight:400;font-size:11.5px;color:var(--gray);margin-top:1px}
.q{margin-left:auto;display:flex;gap:6px;flex:1 1 260px;max-width:420px}
.q input{flex:1;padding:10px 16px;font-size:16px;border:2px solid #cfe5e2;border-radius:999px;background:#fff;font-family:inherit;color:var(--navy)}
.q input:focus{outline:0;border-color:var(--teal)}
.q button{padding:10px 18px;border:0;border-radius:999px;background:var(--teal);color:#fff;font-weight:700;cursor:pointer;font-family:inherit;font-size:15px}
.q button:hover{background:var(--teal-d)}
.gnav{flex:1 0 100%;display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;padding:6px 0 8px}
.gnav::-webkit-scrollbar{display:none}
.gnav a{flex:none;font-size:13.5px;font-weight:700;color:var(--ink);text-decoration:none;padding:5px 13px;border-radius:999px;white-space:nowrap}
.gnav a:hover,.gnav a.on{background:var(--mint-xl);color:var(--teal-d)}
.wrap{max-width:1080px;margin:0 auto;padding:22px 16px 56px;overflow-wrap:anywhere}
h1{font-size:30px;font-weight:900;line-height:1.35;margin:0 0 10px}
h1 small{display:block;font-size:13px;font-weight:400;color:var(--gray);margin-top:4px}
h2{font-size:22px;font-weight:900;line-height:1.4;margin:52px 0 16px;padding:0;border:0}
h2[data-en]::before{content:attr(data-en);display:block;font-size:11.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--teal);margin-bottom:2px}
h2::after{content:"";display:block;width:40px;height:4px;border-radius:2px;background:linear-gradient(90deg,var(--teal),var(--mint));margin-top:8px}
h2+.note,h2+p.note{margin-top:-4px}
h3{font-size:17px;font-weight:700;line-height:1.5}
.lead{color:var(--ink);font-size:15.5px;margin:0 0 20px;line-height:1.9}
.crumb{font-size:12.5px;color:var(--gray);margin:0 0 14px}
.crumb a{color:var(--gray)}
.hero{position:relative;overflow:hidden;border-radius:28px;padding:44px 36px 36px;margin:2px 0 18px;
background:linear-gradient(135deg,#eaf9f5 0%,#c9f0e5 48%,#8fe3d3 100%);isolation:isolate}
.hero::before,.hero::after{content:"";position:absolute;border-radius:50%;z-index:-1}
.hero::before{width:460px;height:460px;right:-140px;top:-190px;background:rgba(255,255,255,.38)}
.hero::after{width:300px;height:300px;left:-110px;bottom:-170px;background:rgba(10,154,143,.10)}
.hero .kick,.kick{font-size:11.5px;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--teal-d);margin:0 0 10px}
.hero h1{font-size:38px;line-height:1.3;margin:0 0 14px;letter-spacing:.01em}
.hero .lead{max-width:720px;margin-bottom:22px}
.hero .crumb{margin-bottom:8px}
.hero .q{margin:0;max-width:560px;flex:none;width:100%}
.hero .q input{border-color:#fff;box-shadow:var(--sh);padding:13px 20px;font-size:16.5px}
.hero .q button{padding:13px 24px;font-size:16px;box-shadow:var(--sh)}
.hero.p{padding:32px 30px 28px;border-radius:24px;background:linear-gradient(135deg,#f2fbf8 0%,#dcf4ec 55%,#bfeee2 100%)}
.hero.p h1{font-size:32px}
.hero .pill{background:#fff;color:var(--teal-d)}
.hero .pill.gray{background:rgba(255,255,255,.7);color:var(--ink)}
.hero .note{color:var(--ink)}
.facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px 18px;margin:28px 0 0}
.facts .f b{display:block;font-size:40px;font-weight:900;line-height:1;letter-spacing:-.01em;color:var(--navy);font-feature-settings:"tnum"}
.facts .f b i{font-style:normal;font-size:15px;font-weight:700;margin-left:3px;color:var(--teal-d)}
.facts .f span{display:block;font-size:12.5px;color:var(--ink);margin-top:7px;font-weight:700}
.kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:12px;margin:18px 0}
.kv .c{background:var(--mint-xl);border:0;border-radius:var(--r);padding:18px 16px 16px;text-align:left}
.hero .kv{margin:24px 0 0}
.hero .kv .c{background:rgba(255,255,255,.72)}
.kv .c b{display:block;font-size:36px;font-weight:900;line-height:1;color:var(--navy);font-feature-settings:"tnum"}
.kv .c span{display:block;font-size:12.5px;color:var(--ink);margin-top:8px;font-weight:700;line-height:1.5}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:999px;background:var(--teal);color:#fff;font-weight:700;text-decoration:none;font-size:15px;box-shadow:var(--sh);border:2px solid var(--teal);line-height:1.4}
.btn:hover{background:var(--teal-d);border-color:var(--teal-d);color:#fff}
.btn::after{content:"›";font-size:19px;line-height:1;margin-top:-2px}
.btn.o{background:#fff;color:var(--navy);border-color:var(--navy);box-shadow:none}
.btn.o:hover{background:var(--mint-xl);color:var(--navy)}
.btn.s{padding:8px 16px;font-size:13.5px}
.more{margin:16px 0 0}
.panel{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:20px 22px;margin-bottom:16px;box-shadow:var(--sh)}
.panel>:first-child{margin-top:0}
.panel>:last-child{margin-bottom:0}
.panel.note{background:var(--mint-xl);border:0;box-shadow:none;color:var(--ink);font-size:14px;line-height:1.85}
.panel.insight{border-left:5px solid var(--teal)}
.panel.uniq{border-left:5px solid #2b4a8b}
.panel.hl{border-left:5px solid #b3621e}
.panel.stat{border-left:5px solid #4a6fa5}
blockquote.kaigi{margin:8px 0;padding:10px 14px;background:#f6fbf9;border-left:3px solid var(--mint);font-size:.93rem;line-height:1.85;border-radius:0 8px 8px 0}
.panel.insight p{line-height:1.9;margin:0 0 10px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(240px,100%),1fr));gap:14px}
.card{display:block;position:relative;background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:18px 34px 16px 18px;
text-decoration:none;color:inherit;box-shadow:var(--sh);transition:transform .15s,box-shadow .15s,border-color .15s}
.card::after{content:"›";position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:22px;font-weight:900;color:#a9dfd6;line-height:1}
.card:hover{transform:translateY(-2px);box-shadow:var(--sh2);border-color:var(--mint);color:inherit}
.card:hover::after{color:var(--teal)}
.card .nm{font-weight:900;font-size:16.5px;line-height:1.45}
.card .mt{font-size:12.5px;color:var(--gray);margin-top:4px}
.card .n{font-size:13.5px;color:var(--teal-d);font-weight:700;margin-top:8px;line-height:1.6}
.pill{display:inline-block;font-size:11.5px;font-weight:700;border-radius:999px;padding:3px 10px;
background:var(--mint-xl);color:var(--teal-d);border:0;white-space:nowrap;line-height:1.6}
.pill.gray{background:#eef2f4;color:var(--gray)}
a.pill{padding:6px 14px;font-size:13px;text-decoration:none}
a.pill:hover{background:var(--mint-l);color:var(--navy)}
.sp{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;margin-bottom:12px;box-shadow:var(--sh)}
.sp .m{font-size:12.5px;color:var(--gray);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.sp .m b{color:var(--teal-d);font-size:13.5px}
.sp .m .pill,.nw .m .pill,.xp .m .pill{white-space:normal;max-width:100%}
.sp .t{margin:8px 0 0;line-height:1.9}
.sp mark{background:#fff3b0;padding:0 1px}
.sp .lk{font-size:12.5px;margin-top:10px}
.nw{background:#fff;border:1px solid var(--line);border-left:4px solid var(--teal);border-radius:12px;padding:11px 14px;margin-bottom:8px}
.nw .m{font-size:12.5px;color:var(--gray);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.nw .m b{color:var(--teal-d)}
.nw .t{margin:5px 0 0;font-size:14.5px;line-height:1.65}
.nw .lk{font-size:12.5px;color:var(--gray);margin-top:6px}
.top3{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0;align-items:center}
.top3 a{font-size:13px;background:var(--mint-xl);border:0;border-radius:999px;padding:5px 13px;text-decoration:none;font-weight:700}
.top3 a:hover{background:var(--mint-l);color:var(--navy)}
.offl{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 8px}
.offl a{display:inline-flex;align-items:center;gap:6px;font-size:13.5px;background:#fff;border:1.5px solid #cfe5e2;
border-radius:999px;padding:7px 15px;text-decoration:none;color:var(--navy);font-weight:700}
.offl a:hover{border-color:var(--teal);background:var(--mint-xl)}
.offl a b{color:var(--teal-d);font-size:14px}
.vgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(260px,100%),1fr));gap:14px}
.vcard{background:#fff;border:1px solid var(--line);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh)}
.vcard iframe{width:100%;aspect-ratio:16/9;border:0;display:block}
.vthumb{display:block;width:100%;padding:0;border:0;background:#000;cursor:pointer;position:relative;line-height:0}
.vthumb img{width:100%;height:auto;aspect-ratio:16/9;object-fit:cover;display:block;opacity:.92}
.vthumb:hover img{opacity:1}
.vplay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
font-size:34px;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.6)}
.vmeta{padding:10px 12px;font-size:13.5px;line-height:1.6}
.vmeta a{display:block;margin-top:2px}
.xp{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:14px 16px;margin-bottom:10px;box-shadow:var(--sh)}
.xp .m{font-size:12.5px;color:var(--gray);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.xp .t{margin:6px 0 0;font-size:14.5px;line-height:1.8;white-space:pre-wrap}
.xp .lk{font-size:12.5px;margin-top:8px}
.maker{border-top:1px solid var(--line);margin-top:18px;padding-top:18px}
.mk-logo{display:inline-flex;align-items:center;gap:10px;text-decoration:none;color:var(--navy)}
.mk-logo img{border-radius:10px}
.mk-logo b{font-size:14px}
.mk-logo small{display:block;font-size:12px;color:var(--gray);font-weight:400}
.mk-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.mk-links a{font-size:13px;background:#fff;border:1.5px solid #cfe5e2;border-radius:999px;
padding:6px 14px;text-decoration:none;color:var(--navy);font-weight:700}
.mk-links a:hover{border-color:var(--teal)}
.mk-links a:first-child{background:var(--teal);border-color:var(--teal);color:#fff}
table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;background:#fff;border:1px solid var(--line);border-radius:12px;overflow:hidden}
th,td{border:0;border-bottom:1px solid var(--line);padding:9px 12px;text-align:left;vertical-align:top}
tr:last-child td{border-bottom:0}
th{background:#f3faf8;white-space:nowrap;font-weight:700;color:var(--ink)}
td.n,th.n{text-align:right;white-space:nowrap;font-feature-settings:"tnum"}
.bar{height:8px;background:var(--mint-xl);border-radius:4px;overflow:hidden;min-width:60px}
.bar i{display:block;height:100%;background:linear-gradient(90deg,var(--teal),var(--mint));border-radius:4px}
.pager{display:flex;gap:8px;flex-wrap:wrap;margin-top:20px}
.pager a,.pager span{padding:8px 14px;border:1.5px solid #cfe5e2;border-radius:999px;background:#fff;text-decoration:none;font-size:14px;font-weight:700;color:var(--navy)}
.pager a:hover{border-color:var(--teal)}
.pager .now{background:var(--teal);color:#fff;border-color:var(--teal)}
.note{font-size:13px;color:var(--gray)}
footer.site{background:linear-gradient(180deg,#fff 0%,#eef8f5 100%);border-top:1px solid var(--line);margin-top:56px}
footer.site .inner{padding:36px 16px 40px}
footer.site p{font-size:12.5px;color:var(--gray);margin:0 0 8px}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
.scroll table{min-width:520px}
/* 統計は「ラベル｜値」の2列しかない。520pxを強いると、スマホで値が画面の外に出て
   ラベルだけ見える状態になる（数字を見せる表なのに数字が見えない）。ここだけ外す。 */
.stat .scroll table{min-width:0}
/* 制度の説明（explain）。ラベルを見出しに、説明をぶら下げる。 */
.explain{margin:0}
.explain dt{font-weight:700;color:var(--ink);margin:0 0 .25em}
.explain dd{margin:0 0 1em;padding:0 0 0 .9em;border-left:3px solid var(--mint-xl)}
.explain dd:last-child{margin-bottom:0}
.stat .scroll table td:first-child{width:auto}
/* **数字だけ**を折り返さない。セル全体に nowrap を掛けると、
   ぶら下がる注記が枠を越え、さらに値側が幅を取ってラベルが「愛知/県」と割れる。 */
.stat .scroll table td.n{white-space:normal}
.stat .scroll table td.n b{white-space:nowrap}
.stat .scroll table td.n .note{display:block}
/* **列幅を中身に決めさせない。** 自動だと、注記の長い値側が幅を取り、
   「愛知県」が『愛知/県』と割れる。keep-all で短い語は割らず、
   収まらない長いラベルだけ break-word で折る（anywhere は keep-all を打ち消す）。 */
.stat .scroll table{table-layout:fixed}
.stat .scroll table td:first-child{width:42%;word-break:keep-all;overflow-wrap:break-word}
@media(max-width:640px){
header.site .inner{padding:10px 16px 0}
.brand{font-size:15px}.brand small{display:none}.brand .mark{width:34px;height:34px;font-size:16px}
.q{flex:1 1 100%;max-width:none;margin-left:0}
.wrap{padding-top:16px}
h1{font-size:24px}h2{font-size:19.5px;margin-top:40px}
.hero{padding:28px 20px 26px;border-radius:20px}.hero h1{font-size:27px}.hero.p{padding:22px 18px 22px}.hero.p h1{font-size:25px}
.hero::before{width:300px;height:300px;right:-120px;top:-150px}
.facts{gap:14px 12px;margin-top:22px}.facts .f b{font-size:31px}.kv .c b{font-size:29px}
.panel{padding:16px}.card{padding:15px 30px 14px 15px}
}
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
        'tiktok'    => ['TikTok', '♪'],
        'note'      => ['note', 'n'],
        'blog'      => ['ブログ', '✎'],
    ];
    $h = '<div class="offl">';
    foreach ($items as $k => [$label, $icon]) {
        if (empty($L[$k])) { continue; }
        $h .= '<a href="' . g_e($L[$k]) . '" rel="nofollow noopener" target="_blank">'
            . '<b>' . $icon . '</b>' . g_e($label) . '</a>';
    }
    $h .= '</div>';
    if (!empty($L['source'])) {
        $slabel = !empty($L['source_label']) ? $L['source_label'] : '本人の公式サイト';
        $h .= '<p class="note">これらは <a href="' . g_e($L['source']) . '" rel="nofollow noopener" '
            . 'target="_blank">' . g_e($slabel) . '</a>に掲載されているリンクです'
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
