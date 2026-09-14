<?php
/** sitemap.xml。議員ページとことがらページを全部出す（SEOの入口はことがら側）。 */
declare(strict_types=1);
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/themes.php';
require __DIR__ . '/lib/ui.php';
header('Content-Type: application/xml; charset=UTF-8');

$last = substr(g_meta('updated_at'), 0, 10) ?: date('Y-m-d');
$urls = [
    ['', '1.0', 'daily'],
    ['giin', '0.9', 'weekly'],
    ['themes', '0.9', 'weekly'],
    ['compare', '0.7', 'weekly'],
    ['about', '0.5', 'monthly'],
];
foreach (g_themes() as $t) { $urls[] = ['t/' . $t['slug'], '0.9', 'weekly']; }
foreach (g_all('SELECT id FROM giin ORDER BY id') as $g) { $urls[] = ['g/' . $g['id'], '0.8', 'weekly']; }

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$p, $pri, $freq]) {
    echo '  <url><loc>' . g_e(g_abs($p)) . '</loc>'
       . '<lastmod>' . $last . '</lastmod>'
       . '<changefreq>' . $freq . '</changefreq>'
       . '<priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
