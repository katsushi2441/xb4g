<?php
// xb4g.com — 第4世代エクスブリッジ リンク集
// コンテンツは content/ 配下のJSON(Decap CMSで編集)を読んで描画する。
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
$h = fn($t) => htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');
$title = 'エクスブリッジ 第4世代 | 全サイト・全プロダクトのリンク集';
$desc = '中国オフショア開発、ITベンダー、ネット通販を経てAI事業へ。第4世代の株式会社エクスブリッジが運営する全サイト・AIプロダクト・お店・メディアのリンク集です。';
// AI検索(AEO/GEO)と検索結果の両方で読まれる想定のQ&A。表示とJSON-LDで同じ内容を使う。
$FAQ = [
  ['株式会社エクスブリッジはどんな会社ですか？', '愛知県名古屋市のAIシステム開発会社です。中国オフショア開発から創業し、基幹業務パッケージを開発するITベンダー、ネット通販事業を経て、現在は第4世代としてAI事業を行っています。AIプロダクトの開発・提供、バイブコーディングによる受託開発、SaaSからオープンソースへの置き換え支援が主な事業です。'],
  ['xb4g.com は何のサイトですか？', '株式会社エクスブリッジが運営する全サイト・全AIプロダクトのリンク集です。コーポレートサイト、SaaS→OSS対応表、AIでできること一覧、業種別ITソリューション、OSSカタログ、Kurage App Store、各AIプロダクト、ブログ・メディアまでを1ページにまとめています。'],
  ['エクスブリッジに相談するにはどうすればいいですか？', '無料相談の窓口(https://exbridge.jp/contact.php)からご相談いただけます。相談は無料で、Zoomでの打ち合わせにも対応しています。AI活用、SaaSの費用削減、オープンソースへの置き換え、業務システムの開発が主な相談内容です。'],
  ['エクスブリッジの製品はどこで買えますか？', 'Kurage App Store(https://kappstore.exbridge.jp/)で買い切りの業務システムとオープンソース導入キットを販売しています。導入手順書はBrainでも販売しています。月額課金ではなく買い切りが基本方針です。'],
  ['第4世代とはどういう意味ですか？', 'エクスブリッジの事業の変遷を4つの世代で表しています。第1世代は中国オフショア開発、第2世代は基幹業務パッケージを自社開発するITベンダー、第3世代はネット通販、そして第4世代が現在のAI事業です。'],
];
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
    [
      '@type' => 'Organization', '@id' => 'https://exbridge.jp/#org',
      'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/',
      'logo' => 'https://xb4g.com/images/logo-mark-64.png',
      'description' => '名古屋のAIシステム開発会社。中国オフショア開発、基幹パッケージのITベンダー、ネット通販を経て、第4世代としてAI事業を行う。',
      'address' => ['@type' => 'PostalAddress', 'addressLocality' => '名古屋市', 'addressRegion' => '愛知県', 'addressCountry' => 'JP'],
      'sameAs' => array_values(array_map(fn($s) => $s['url'], array_filter($sites, fn($s) => ($s['category'] ?? '') === 'sns'))),
    ],
    [
      '@type' => 'WebSite', '@id' => 'https://xb4g.com/#website',
      'url' => 'https://xb4g.com/', 'name' => $title, 'inLanguage' => 'ja',
      'publisher' => ['@id' => 'https://exbridge.jp/#org'],
    ],
    [
      '@type' => 'ItemList', '@id' => 'https://xb4g.com/#sites',
      'name' => '株式会社エクスブリッジの全サイト・全プロダクト',
      'numberOfItems' => count($sites),
      'itemListElement' => array_values(array_map(fn($i, $s) => [
        '@type' => 'ListItem', 'position' => $i + 1,
        'name' => $s['title'], 'url' => $s['url'], 'description' => $s['desc'] ?? '',
      ], array_keys($sites), $sites)),
    ],
    [
      '@type' => 'FAQPage', '@id' => 'https://xb4g.com/#faq',
      'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question', 'name' => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]],
      ], $FAQ),
    ],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<style>
:root{--bg:#0b1020;--panel:#121a33;--panel2:#0f1730;--line:#26335e;--tx:#e8ecf8;--mut:#9aa7cc;--ac:#5b8cff;--ac2:#39d5c9}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--tx);font-family:"Hiragino Kaku Gothic ProN","Noto Sans JP",Meiryo,sans-serif;line-height:1.7}
a{color:inherit;text-decoration:none}
.wrap{max-width:1080px;margin:0 auto;padding:0 20px}
header{padding:18px 0;border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:16px}
.brand img{width:32px;height:32px;border-radius:7px;display:block}
.hero{padding:56px 0 40px;background:radial-gradient(ellipse 80% 60% at 50% -10%,#1b2a5e 0%,transparent 60%)}
.gen-badge{display:inline-block;background:linear-gradient(90deg,var(--ac),var(--ac2));color:#08122b;font-weight:700;font-size:13px;padding:4px 14px;border-radius:99px;margin-bottom:14px}
h1{font-size:34px;line-height:1.3;margin-bottom:14px}
.lead{color:var(--mut);max-width:760px;font-size:15px}
.timeline{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:34px 0 0}
.tl{background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:16px 14px;position:relative}
.tl .era{font-size:12px;color:var(--mut)}
.tl .t{font-weight:700;margin:2px 0 6px}
.tl .d{font-size:12.5px;color:var(--mut)}
.tl.now{border-color:var(--ac);box-shadow:0 0 24px rgba(91,140,255,.18)}
.tl.now .era{color:var(--ac2)}
section{padding:34px 0 6px}
h2{font-size:20px;margin-bottom:16px;padding-left:12px;border-left:4px solid var(--ac)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:12px;margin-bottom:14px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px;display:block;transition:.15s}
.card:hover{border-color:var(--ac);transform:translateY(-2px)}
.card .t{font-weight:700;margin-bottom:4px;display:flex;align-items:baseline;gap:8px}
.card .u{font-size:11.5px;color:var(--ac2);word-break:break-all}
.card .d{font-size:13px;color:var(--mut);margin-top:6px}
.faq{display:grid;gap:10px;margin-bottom:14px}
.qa{background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:16px}
.qa .q{font-weight:700;margin-bottom:6px}
.qa .q::before{content:"Q. ";color:var(--ac2)}
.qa .a{font-size:13.5px;color:var(--mut)}
footer{margin-top:44px;padding:26px 0 40px;border-top:1px solid var(--line);color:var(--mut);font-size:13px}
footer a{color:var(--ac2)}
@media(max-width:760px){.timeline{grid-template-columns:repeat(2,1fr)}h1{font-size:26px}}
</style>
</head>
<body>
<header><div class="wrap"><a class="brand" href="/"><img src="/images/logo-mark-64.png" alt="" width="32" height="32"><span>株式会社エクスブリッジ</span></a></div></header>
<div class="hero"><div class="wrap">
  <span class="gen-badge">EXBRIDGE — 4th Generation</span>
  <h1><?= $h($S['hero_title'] ?? 'エクスブリッジ 第4世代') ?></h1>
  <p class="lead"><?= $h($S['hero_lead'] ?? '') ?></p>
  <div class="timeline">
  <?php $gens = $S['generations'] ?? []; $last = count($gens) - 1;
  foreach ($gens as $i => $g): ?>
    <div class="tl<?= $i === $last ? ' now' : '' ?>"><div class="era"><?= $h($g['era']) ?></div><div class="t"><?= $h($g['title']) ?></div><div class="d"><?= $h($g['desc']) ?></div></div>
  <?php endforeach; ?>
  </div>
</div></div>
<main class="wrap">
<?php foreach ($cats as $c): $list = $byCat[$c['key']] ?? []; if (!$list) continue; ?>
<section id="<?= $h($c['key']) ?>">
  <h2><?= $h($c['label']) ?></h2>
  <div class="grid">
  <?php foreach ($list as $s): ?>
    <a class="card" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener">
      <div class="t"><?= $h($s['title']) ?></div>
      <div class="u"><?= $h(preg_replace('~^https?://~', '', rtrim($s['url'], '/'))) ?></div>
      <div class="d"><?= $h($s['desc']) ?></div>
    </a>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<section id="faq">
  <h2>よくある質問</h2>
  <div class="faq">
  <?php foreach ($FAQ as $f): ?>
    <div class="qa"><div class="q"><?= $h($f[0]) ?></div><div class="a"><?= $h($f[1]) ?></div></div>
  <?php endforeach; ?>
  </div>
</section>
</main>
<footer><div class="wrap">
  © 株式会社エクスブリッジ ｜ <a href="https://exbridge.jp/" target="_blank" rel="noopener">会社概要</a> ｜ <a href="https://exbridge.jp/contact.php" target="_blank" rel="noopener">無料相談</a><br>
  このページは <a href="https://kurage.exbridge.jp/oss/decap-cms/" target="_blank" rel="noopener">Decap CMS</a> で運用しています(当社の導入キットと同じ構成)。
</div></footer>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body>
</html>
