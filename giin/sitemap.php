<?php
/** sitemap.xml。議員ページとことがらページを全部出す（SEOの入口はことがら側）。 */
declare(strict_types=1);
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/themes.php';
require __DIR__ . '/lib/trackers.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/senkyoku.php';
header('Content-Type: application/xml; charset=UTF-8');

// lastmod はページごとに「中身が本当に変わった日」を入れる（2026-09-30）。
// 以前は日次ジョブの更新時刻（updated_at）を全URLに打っていて、423本すべてが毎日「今日」だった。
// 「いつも全部更新された」と言うサイトマップは Google に信用されない（kurage で 2026-09-22 に同じ罠）。
$speechLast = substr((string)(g_one('SELECT MAX(date) AS d FROM speech')['d'] ?? ''), 0, 10);
$fileLast = date('Y-m-d', (int)filemtime(__DIR__ . '/index.php'));
$newsLast = substr(g_meta('updated_at'), 0, 10) ?: $speechLast;
$trackerLast = [];
foreach (g_all('SELECT tracker, MAX(date) AS d FROM tracker_speech GROUP BY tracker') as $r) { $trackerLast[$r['tracker']] = substr((string)$r['d'], 0, 10); }
$urls = [
    ['', '1.0', 'daily', $speechLast],
    ['list', '0.9', 'weekly', $speechLast],
    ['theme', '0.9', 'weekly', $speechLast],
    // トラッカーの一覧ページ。個別28本は下で足していたのにハブだけ漏れていて、
    // 2026-09-19 時点で tracker/* は1本もクロールされていなかった。
    ['tracker', '0.9', 'weekly', max($trackerLast ?: [$speechLast])],
    ['senkyoku', '0.9', 'weekly', $speechLast],
    ['party', '0.9', 'weekly', $speechLast],
    ['news', '0.8', 'daily', $newsLast],
    ['ai', '0.9', 'weekly', $fileLast],
    ['compare', '0.7', 'weekly', $speechLast],
    ['about', '0.5', 'monthly', $fileLast],
];
foreach (g_themes() as $t) { $urls[] = ['theme/' . $t['slug'], '0.9', 'weekly', $speechLast]; }
foreach (g_trackers() as $t) { $urls[] = ['tracker/' . $t['key'], '0.9', 'weekly', $trackerLast[$t['key']] ?? $speechLast]; }
foreach (g_all('SELECT key FROM senkyoku ORDER BY rowid') as $s) { $urls[] = ['senkyoku/' . $s['key'], '0.8', 'weekly', $speechLast]; }
foreach (g_parties() as $p) { $urls[] = ['party/' . g_party_slug($p['party']), '0.8', 'weekly', $speechLast]; }
foreach (g_all('SELECT slug, last_date FROM giin ORDER BY slug') as $g) { $urls[] = [$g['slug'], '0.8', 'weekly', substr((string)($g['last_date'] ?: $speechLast), 0, 10)]; }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$p, $pri, $freq, $lm]) {
    echo '  <url><loc>' . g_e(g_abs($p)) . '</loc>'
       . ($lm !== '' ? '<lastmod>' . g_e($lm) . '</lastmod>' : '')
       . '<changefreq>' . $freq . '</changefreq>'
       . '<priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
