<?php
// xb4g.com — 第4世代エクスブリッジ ポータル（メディアサイト型）
require __DIR__ . '/lib/feeds.php';

$S = json_decode(@file_get_contents(__DIR__ . '/content/settings/site.json'), true) ?: [];
$sites = [];
foreach (glob(__DIR__ . '/content/sites/*.json') as $f) {
    $j = json_decode(file_get_contents($f), true);
    if ($j && !empty($j['url']) && ($j['published'] ?? true)) $sites[] = $j;
}
usort($sites, fn($a, $b) => [$a['category'] ?? '', $a['order'] ?? 99] <=> [$b['category'] ?? '', $b['order'] ?? 99]);
$byCat = [];
foreach ($sites as $s) $byCat[$s['category'] ?? 'other'][] = $s;
$cats = $S['categories'] ?? [];
$find = function ($url) use ($sites) {
    foreach ($sites as $s) if (rtrim($s['url'], '/') === rtrim($url, '/')) return $s;
    return null;
};
$h = fn($t) => htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');

$videos   = xb4g_feed('videos');
$blog     = xb4g_feed('blog');
$articles = xb4g_feed('articles');

// 大バナーで押し出す入口（4つの入口＋受託＋AI窓口）
$BANNERS = [
    ['url' => 'https://kurage.exbridge.jp/oss/',      'eyebrow' => 'OSSカタログ', 'title' => 'SaaSの月額を、オープンソースに置き換える', 'desc' => '業務システムの代替になるOSSを約2,800件、日本語で紹介しています。', 'tone' => 'teal'],
    ['url' => 'https://exbridge.jp/saas/',            'eyebrow' => 'SaaS→OSS対応表', 'title' => 'いま使っているSaaSの名前から探す', 'desc' => 'サービス名を入口に、置き換え候補と移行の勘所をまとめました。', 'tone' => 'blue'],
    ['url' => 'https://exbridge.jp/solution/',        'eyebrow' => '業種・業務別ソリューション', 'title' => 'あなたの業種で、何をやめられるか', 'desc' => '業種ごとに固定費の減らし方と、買い切りで置き換える道筋を示します。', 'tone' => 'violet'],
    ['url' => 'https://exbridge.jp/ai-system/',       'eyebrow' => 'AIでできること', 'title' => '経営課題から、AIの使い道を探す', 'desc' => '「何ができるか」ではなく「何が解決するか」から引ける一覧です。', 'tone' => 'amber'],
];
$SERVICES = [
    ['url' => 'https://kurage.exbridge.jp/vibe-prototype.html', 'title' => 'バイブプロトタイピング', 'desc' => '設計書から動くプロトタイプを最短1営業日で。', 'price' => '税込110,000円〜'],
    ['url' => 'https://kurage.exbridge.jp/vibe-oss.html',       'title' => 'バイブOSSカスタマイズ', 'desc' => 'OSSの日本語化・機能変更・サーバー構築を代行。', 'price' => '税込110,000円〜'],
    ['url' => 'https://kappstore.exbridge.jp/',                 'title' => 'Kurage App Store', 'desc' => '買い切りの業務システムと、OSS導入キットのお店。', 'price' => '買い切り'],
    ['url' => 'https://kurage.exbridge.jp/chat.php',            'title' => 'Kurage.AI 相談窓口', 'desc' => 'システム開発と代理店収益化のAIチャット窓口。', 'price' => '無料'],
];

$FAQ = [
  ['株式会社エクスブリッジはどんな会社ですか？', '愛知県名古屋市のAIシステム開発会社です。中国オフショア開発から創業し、基幹業務パッケージを開発するITベンダー、ネット通販事業を経て、現在は第4世代としてAI事業を行っています。AIプロダクトの開発・提供、バイブコーディングによる受託開発、SaaSからオープンソースへの置き換え支援が主な事業です。'],
  ['xb4g.com は何のサイトですか？', '株式会社エクスブリッジが運営する全サイト・全AIプロダクトのポータルです。最新の解説動画、技術記事、SaaS→OSS対応表、AIでできること一覧、業種別ソリューション、買い切り製品のお店までを1ページから辿れます。'],
  ['エクスブリッジに相談するにはどうすればいいですか？', '無料相談の窓口(https://exbridge.jp/contact.php)からご相談いただけます。相談は無料で、Zoomでの打ち合わせにも対応しています。AI活用、SaaSの費用削減、オープンソースへの置き換え、業務システムの開発が主な相談内容です。'],
  ['エクスブリッジの製品はどこで買えますか？', 'Kurage App Store(https://kappstore.exbridge.jp/)で買い切りの業務システムとオープンソース導入キットを販売しています。導入手順書はBrainでも販売しています。月額課金ではなく買い切りが基本方針です。'],
  ['第4世代とはどういう意味ですか？', 'エクスブリッジの事業の変遷を4つの世代で表しています。第1世代は中国オフショア開発、第2世代は基幹業務パッケージを自社開発するITベンダー、第3世代はネット通販、そして第4世代が現在のAI事業です。'],
];

$title = 'エクスブリッジ 第4世代 | AIプロダクトとオープンソースのポータル';
$desc  = '株式会社エクスブリッジ（名古屋）が運営する全サイトのポータル。Kurageの解説動画、AI・OSSの技術記事、SaaS→OSS対応表、業種別ITソリューション、買い切りの業務システムまでを1ページから。';
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($title) ?></title>
<meta name="description" content="<?= $h($desc) ?>">
<link rel="canonical" href="https://xb4g.com/">
<link rel="icon" href="/images/logo-mark-64.png">
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:url" content="https://xb4g.com/">
<meta property="og:type" content="website">
<meta property="og:image" content="https://xb4g.com/images/ogp.png">
<meta property="og:site_name" content="株式会社エクスブリッジ 第4世代">
<meta property="og:locale" content="ja_JP">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script type="application/ld+json"><?= json_encode([
  '@context' => 'https://schema.org',
  '@graph' => [
    ['@type' => 'Organization', '@id' => 'https://exbridge.jp/#org',
     'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/',
     'logo' => 'https://xb4g.com/images/logo-mark-64.png',
     'description' => '名古屋のAIシステム開発会社。中国オフショア開発、基幹パッケージのITベンダー、ネット通販を経て、第4世代としてAI事業を行う。',
     'address' => ['@type' => 'PostalAddress', 'addressLocality' => '名古屋市', 'addressRegion' => '愛知県', 'addressCountry' => 'JP'],
     'sameAs' => array_values(array_map(fn($s) => $s['url'], array_filter($sites, fn($s) => ($s['category'] ?? '') === 'sns')))],
    ['@type' => 'WebSite', '@id' => 'https://xb4g.com/#website',
     'url' => 'https://xb4g.com/', 'name' => $title, 'inLanguage' => 'ja',
     'publisher' => ['@id' => 'https://exbridge.jp/#org']],
    ['@type' => 'ItemList', '@id' => 'https://xb4g.com/#sites',
     'name' => '株式会社エクスブリッジの全サイト・全プロダクト',
     'numberOfItems' => count($sites),
     'itemListElement' => array_values(array_map(fn($i, $s) => [
        '@type' => 'ListItem', 'position' => $i + 1,
        'name' => $s['title'], 'url' => $s['url'], 'description' => $s['desc'] ?? '',
     ], array_keys($sites), $sites))],
    ['@type' => 'FAQPage', '@id' => 'https://xb4g.com/#faq',
     'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $FAQ)],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<style>
:root{--ink:#1d3038;--muted:#5f7078;--teal:#0a9a8f;--teal-d:#0a726b;--paper:#f3faf9;--line:#dcebe9;--card:#ffffff;--accent:#ff9f43;--blue:#2f7fd4;--violet:#7a5cd0}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.8}
a{color:var(--teal-d);text-decoration:none}
img{max-width:100%}
.topbar{position:sticky;top:0;z-index:20;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 22px;background:rgba(255,255,255,.94);border-bottom:1px solid var(--line);backdrop-filter:blur(8px)}
.brand{display:flex;align-items:center;gap:10px;color:var(--ink);font-weight:800}
.brand-logo{width:30px;height:30px;border-radius:7px;display:block}
.brand span{font-size:14.5px}
.navlinks{display:flex;align-items:center;gap:16px;font-size:13.5px;font-weight:700}
.navlinks a{color:var(--muted)}
.navlinks a:hover{color:var(--teal-d)}
.navlinks .cta{background:var(--teal);color:#fff;padding:7px 15px;border-radius:99px}
.wrap{max-width:1120px;margin:0 auto;padding:0 20px}
/* ヒーロー */
.hero{background:linear-gradient(180deg,#e8f7f5 0%,var(--paper) 100%);padding:34px 0 26px;border-bottom:1px solid var(--line)}
.hero-in{display:flex;align-items:center;gap:26px;flex-wrap:wrap}
.hero-txt{flex:1;min-width:290px}
.eyebrow{display:inline-block;background:var(--teal);color:#fff;font-size:12px;font-weight:800;padding:4px 12px;border-radius:99px;letter-spacing:.04em}
.hero h1{font-size:30px;line-height:1.4;margin:12px 0 10px}
.hero p{color:var(--muted);margin:0;font-size:15px}
.hero-mascot{width:132px;flex:none;filter:drop-shadow(0 14px 24px rgba(10,114,107,.18));animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-9px)}}
.gens{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}
.gen{background:#fff;border:1px solid var(--line);border-radius:99px;padding:5px 13px;font-size:12.5px;color:var(--muted)}
.gen b{color:var(--ink)}
.gen.now{border-color:var(--teal);background:#eafaf8}
.gen.now b{color:var(--teal-d)}
/* セクション */
section{padding:30px 0 6px}
.sec-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.sec-head h2{font-size:19px;margin:0;padding-left:11px;border-left:4px solid var(--teal)}
.sec-head .more{font-size:13px;font-weight:700}
/* 動画: 横スクロール */
.rail{display:flex;gap:14px;overflow-x:auto;padding:2px 2px 14px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
.rail::-webkit-scrollbar{height:8px}
.rail::-webkit-scrollbar-thumb{background:#c8ded9;border-radius:99px}
.vcard{flex:0 0 258px;scroll-snap-align:start;background:var(--card);border:1px solid var(--line);border-radius:13px;overflow:hidden;transition:.15s}
.vcard:hover{transform:translateY(-3px);box-shadow:0 10px 22px rgba(10,114,107,.13)}
.vthumb{position:relative;aspect-ratio:16/9;background:#dfeeeb;overflow:hidden}
.vthumb img{width:100%;height:100%;object-fit:cover;display:block}
.vplay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center}
.vplay i{width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.92);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px rgba(0,0,0,.22)}
.vplay i::after{content:"";border-left:13px solid var(--teal-d);border-top:8px solid transparent;border-bottom:8px solid transparent;margin-left:3px}
.vcard .t{padding:10px 12px 13px;font-size:13.5px;font-weight:700;line-height:1.5;color:var(--ink);display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
/* バナー */
.banners{display:grid;grid-template-columns:repeat(auto-fit,minmax(258px,1fr));gap:14px}
.banner{position:relative;overflow:hidden;border-radius:14px;padding:20px 18px 18px;color:#fff;min-height:158px;display:flex;flex-direction:column;justify-content:flex-end}
.banner.teal{background:linear-gradient(135deg,#0a9a8f,#0a726b)}
.banner.blue{background:linear-gradient(135deg,#3f8fe0,#2360a8)}
.banner.violet{background:linear-gradient(135deg,#8a6ce0,#5c3fb0)}
.banner.amber{background:linear-gradient(135deg,#ffa94d,#e07b1d)}
.banner .eb{font-size:11.5px;font-weight:800;letter-spacing:.05em;opacity:.92;margin-bottom:6px}
.banner h3{margin:0 0 6px;font-size:17.5px;line-height:1.45}
.banner p{margin:0;font-size:12.8px;opacity:.94;line-height:1.6}
.banner::after{content:"";position:absolute;right:-30px;top:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.13)}
.banner:hover{filter:brightness(1.06)}
/* 記事2カラム */
.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:820px){.cols{grid-template-columns:1fr}}
.post{display:flex;gap:11px;padding:11px 0;border-bottom:1px dashed var(--line)}
.post:last-child{border-bottom:none}
.post .d{flex:none;font-size:11.5px;color:var(--muted);width:64px;padding-top:2px;font-weight:700}
.post .t{font-size:14px;font-weight:700;color:var(--ink);line-height:1.6}
.post:hover .t{color:var(--teal-d)}
/* サービスカード */
.svc{display:grid;grid-template-columns:repeat(auto-fit,minmax(232px,1fr));gap:13px}
.scard{background:var(--card);border:1px solid var(--line);border-radius:13px;padding:16px;transition:.15s}
.scard:hover{border-color:var(--teal);transform:translateY(-2px)}
.scard .t{font-weight:800;font-size:15px;margin-bottom:5px;color:var(--ink)}
.scard .d{font-size:13px;color:var(--muted);line-height:1.65}
.scard .p{display:inline-block;margin-top:9px;background:#eafaf8;color:var(--teal-d);font-size:12px;font-weight:800;padding:3px 10px;border-radius:99px}
/* サイト一覧 */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(272px,1fr));gap:11px}
.card{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:13px 14px;transition:.15s}
/* つくっている人（開発者紹介）。個人サイトへの入口 */
.maker{display:flex;gap:16px;align-items:flex-start;background:var(--card);border:1px solid var(--line);
  border-radius:11px;padding:16px}
.maker-ico img{width:88px;height:88px;border-radius:12px;object-fit:cover;display:block}
.maker-txt{min-width:0}
.maker-txt .t{font-weight:800;font-size:15.5px;margin-bottom:4px}
.maker-txt p{font-size:13px;color:var(--muted);margin:0 0 10px}
.maker-links{display:flex;flex-wrap:wrap;gap:8px}
.maker-links a{border:1px solid var(--line);border-radius:999px;padding:5px 12px;font-size:12.5px;font-weight:700}
.maker-links a:hover{border-color:var(--teal)}
@media(max-width:560px){.maker{gap:12px;padding:13px}.maker-ico img{width:64px;height:64px}}
.card:hover{border-color:var(--teal);transform:translateY(-2px)}
.card .t{font-weight:800;font-size:14px;color:var(--ink);margin-bottom:3px}
.card .u{font-size:11px;color:var(--teal);word-break:break-all}
.card .d{font-size:12.5px;color:var(--muted);margin-top:5px;line-height:1.6}
.catbar{display:flex;align-items:center;gap:9px;margin:24px 0 12px}
.catbar h3{font-size:15px;margin:0;white-space:nowrap}
.catbar::after{content:"";flex:1;height:1px;background:var(--line)}
/* FAQ */
.faq{display:grid;gap:9px}
.qa{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:14px 16px}
.qa .q{font-weight:800;font-size:14px;margin-bottom:5px}
.qa .q::before{content:"Q. ";color:var(--teal)}
.qa .a{font-size:13px;color:var(--muted);line-height:1.75}
/* CTA */
.cta-box{margin:34px 0 10px;background:linear-gradient(135deg,#0a9a8f,#0a726b);border-radius:16px;padding:26px 24px;color:#fff;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.cta-box .txt{flex:1;min-width:250px}
.cta-box h3{margin:0 0 7px;font-size:21px}
.cta-box p{margin:0;font-size:13.5px;opacity:.95}
.cta-box .btn{background:#fff;color:var(--teal-d);font-weight:800;padding:12px 26px;border-radius:99px;font-size:15px;white-space:nowrap}
.cta-box .mascot{width:86px;flex:none}
footer{margin-top:36px;padding:24px 0 40px;border-top:1px solid var(--line);color:var(--muted);font-size:12.5px;text-align:center}
@media(max-width:640px){.hero h1{font-size:23px}.hero-mascot{width:104px;margin:0 auto}.navlinks a:not(.cta){display:none}}
</style>
</head>
<body>
<header class="topbar">
  <a class="brand" href="/"><img class="brand-logo" src="/images/logo-mark-64.png" alt="" width="30" height="30"><span>株式会社エクスブリッジ</span></a>
  <nav class="navlinks">
    <a href="https://kurage.exbridge.jp/oss/">OSS</a>
    <a href="https://exbridge.jp/saas/">SaaS対応表</a>
    <a href="https://exbridge.jp/solution/">ソリューション</a>
    <a href="https://kappstore.exbridge.jp/">お店</a>
    <a class="cta" href="https://exbridge.jp/contact.php">無料相談</a>
  </nav>
</header>

<div class="hero"><div class="wrap hero-in">
  <div class="hero-txt">
    <span class="eyebrow">EXBRIDGE — 4th Generation</span>
    <h1><?= $h($S['hero_title'] ?? 'エクスブリッジ 第4世代') ?></h1>
    <p><?= $h($S['hero_lead'] ?? '') ?></p>
    <div class="gens">
      <?php $gens = $S['generations'] ?? []; $last = count($gens) - 1;
      foreach ($gens as $i => $g): ?>
        <span class="gen<?= $i === $last ? ' now' : '' ?>"><?= $h($g['era']) ?> <b><?= $h($g['title']) ?></b></span>
      <?php endforeach; ?>
    </div>
  </div>
  <img class="hero-mascot" src="/images/kurage_mascot.png" alt="Kurageちゃん">
</div></div>

<main class="wrap">

<?php if ($videos): ?>
<section id="videos">
  <div class="sec-head">
    <h2>Kurageの解説動画 — 新着</h2>
    <a class="more" href="https://kurage.exbridge.jp/kuragev.php">すべて見る →</a>
  </div>
  <div class="rail">
    <?php foreach ($videos as $v): ?>
      <a class="vcard" href="<?= $h($v['url']) ?>" target="_blank" rel="noopener">
        <div class="vthumb">
          <img src="<?= $h($v['thumb']) ?>" alt="" loading="lazy">
          <span class="vplay"><i></i></span>
        </div>
        <div class="t"><?= $h($v['title']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section id="entrances">
  <div class="sec-head"><h2>4つの入口 — 探す</h2></div>
  <div class="banners">
    <?php foreach ($BANNERS as $b): ?>
      <a class="banner <?= $h($b['tone']) ?>" href="<?= $h($b['url']) ?>" target="_blank" rel="noopener">
        <div class="eb"><?= $h($b['eyebrow']) ?></div>
        <h3><?= $h($b['title']) ?></h3>
        <p><?= $h($b['desc']) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($blog || $articles): ?>
<section id="posts">
  <div class="cols">
    <?php if ($blog): ?>
    <div>
      <div class="sec-head">
        <h2>VWork Blog</h2>
        <a class="more" href="https://katsushi2441.github.io/vwork/blog/">一覧 →</a>
      </div>
      <?php foreach (array_slice($blog, 0, 6) as $p): ?>
        <a class="post" href="<?= $h($p['url']) ?>" target="_blank" rel="noopener">
          <span class="d"><?= $h(substr($p['date'], 5)) ?></span>
          <span class="t"><?= $h($p['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($articles): ?>
    <div>
      <div class="sec-head">
        <h2>AI・OSS技術解説</h2>
        <a class="more" href="https://katsushi2441.github.io/vwork/articles/">一覧 →</a>
      </div>
      <?php foreach (array_slice($articles, 0, 6) as $p): ?>
        <a class="post" href="<?= $h($p['url']) ?>" target="_blank" rel="noopener">
          <span class="d"><?= $h(substr($p['date'], 5)) ?></span>
          <span class="t"><?= $h($p['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section id="services">
  <div class="sec-head"><h2>つくる・買う・相談する</h2></div>
  <div class="svc">
    <?php foreach ($SERVICES as $s): ?>
      <a class="scard" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener">
        <div class="t"><?= $h($s['title']) ?></div>
        <div class="d"><?= $h($s['desc']) ?></div>
        <span class="p"><?= $h($s['price']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section id="maker">
  <div class="sec-head"><h2>つくっている人</h2>
    <a class="more" href="https://kurage.exbridge.jp/">個人サイトを見る →</a></div>
  <div class="maker">
    <a class="maker-ico" href="https://kurage.exbridge.jp/">
      <img src="https://kurage.exbridge.jp/images/bittensorman.webp" width="400" height="400"
           alt="BittensorMan のアイコン" loading="lazy">
    </a>
    <div class="maker-txt">
      <div class="t">BittensorMan（小嶋 篤）</div>
      <p>社会の課題を、AIとシステム開発技術で解決したい。名古屋で一人で、AI動画・業務システム・OSSカタログを作っています。
        1995年から業務システムの開発に従事し、2025年からAI事業へ。うまくいった数字も、外した数字も公開しています。</p>
      <div class="maker-links">
        <a href="https://kurage.exbridge.jp/">個人サイト</a>
        <a href="https://x.com/xb_bittensor">X @xb_bittensor</a>
        <a href="https://kurage.exbridge.jp/essay/">エッセイ</a>
        <a href="https://kurage.exbridge.jp/stack.php">開発環境</a>
        <a href="https://github.com/katsushi2441">GitHub</a>
      </div>
    </div>
  </div>
</section>

<section id="sites">
  <div class="sec-head"><h2>エクスブリッジの全サイト・全プロダクト</h2></div>
  <?php foreach ($cats as $c): $list = $byCat[$c['key']] ?? []; if (!$list) continue; ?>
    <div class="catbar"><h3><?= $h($c['label']) ?></h3></div>
    <div class="grid">
    <?php foreach ($list as $s): ?>
      <a class="card" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener">
        <div class="t"><?= $h($s['title']) ?></div>
        <div class="u"><?= $h(preg_replace('~^https?://~', '', rtrim($s['url'], '/'))) ?></div>
        <div class="d"><?= $h($s['desc']) ?></div>
      </a>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</section>

<div class="cta-box">
  <img class="mascot" src="/images/kurage_mascot.png" alt="">
  <div class="txt">
    <h3>まず、話してみませんか</h3>
    <p>AI活用・SaaSの費用削減・オープンソースへの置き換え。相談は無料で、Zoomでも対応します。売り込みはしません。</p>
  </div>
  <a class="btn" href="https://exbridge.jp/contact.php">無料で相談する</a>
</div>

<section id="faq">
  <div class="sec-head"><h2>よくある質問</h2></div>
  <div class="faq">
  <?php foreach ($FAQ as $f): ?>
    <div class="qa"><div class="q"><?= $h($f[0]) ?></div><div class="a"><?= $h($f[1]) ?></div></div>
  <?php endforeach; ?>
  </div>
</section>
</main>

<footer>
  © 株式会社エクスブリッジ ｜ <a href="https://exbridge.jp/">会社概要</a> ｜ <a href="https://exbridge.jp/contact.php">無料相談</a> ｜ <a href="https://kurage.exbridge.jp/">Kurage Project</a><br>
  このページは <a href="https://kurage.exbridge.jp/oss/decap-cms/">Decap CMS</a> で運用しています。
</footer>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body>
</html>
