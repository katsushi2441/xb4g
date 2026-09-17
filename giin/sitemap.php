<?php
/** sitemap.xml。議員ページとことがらページを全部出す（SEOの入口はことがら側）。 */
declare(strict_types=1);
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/themes.php';
require __DIR__ . '/lib/trackers.php';
require __DIR__ . '/lib/ui.php';
header('Content-Type: application/xml; charset=UTF-8');

$last = substr(g_meta('updated_at'), 0, 10) ?: date('Y-m-d');
$urls = [
    ['', '1.0', 'daily'],
    ['list', '0.9', 'weekly'],
    ['theme', '0.9', 'weekly'],
    ['party', '0.9', 'weekly'],
    ['news', '0.8', 'daily'],
    ['ai', '0.9', 'weekly'],
    ['compare', '0.7', 'weekly'],
    ['about', '0.5', 'monthly'],
];
foreach (g_themes() as $t) { $urls[] = ['theme/' . $t['slug'], '0.9', 'weekly']; }
foreach (g_trackers() as $t) { $urls[] = ['tracker/' . $t['key'], '0.9', 'daily']; }
foreach (g_parties() as $p) { $urls[] = ['party/' . g_party_slug($p['party']), '0.8', 'weekly']; }
foreach (g_all('SELECT slug FROM giin ORDER BY slug') as $g) { $urls[] = [$g['slug'], '0.8', 'weekly']; }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$p, $pri, $freq]) {
    echo '  <url><loc>' . g_e(g_abs($p)) . '</loc>'
       . '<lastmod>' . $last . '</lastmod>'
       . '<changefreq>' . $freq . '</changefreq>'
       . '<priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
