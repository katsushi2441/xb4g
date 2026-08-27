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
<script type="application/ld+json"><?= json_encode([
  '@context' => 'https://schema.org', '@type' => 'Organization',
  'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/',
  'logo' => 'https://xb4g.com/images/logo-mark-64.png',
  'sameAs' => array_values(array_map(fn($s) => $s['url'], array_filter($sites, fn($s) => ($s['category'] ?? '') === 'sns'))),
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
</main>
<footer><div class="wrap">
  © 株式会社エクスブリッジ ｜ <a href="https://exbridge.jp/" target="_blank" rel="noopener">会社概要</a> ｜ <a href="https://exbridge.jp/contact.php" target="_blank" rel="noopener">無料相談</a><br>
  このページは <a href="https://kurage.exbridge.jp/oss/decap-cms/" target="_blank" rel="noopener">Decap CMS</a> で運用しています(当社の導入キットと同じ構成)。
</div></footer>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body>
</html>
