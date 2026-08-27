<?php
header('Content-Type: application/xml; charset=UTF-8');
$lastmod = date('Y-m-d', @filemtime(__DIR__ . '/content/settings/site.json') ?: time());
foreach (glob(__DIR__ . '/content/sites/*.json') as $f) {
    $t = @filemtime($f);
    if ($t && date('Y-m-d', $t) > $lastmod) $lastmod = date('Y-m-d', $t);
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://xb4g.com/</loc>
    <lastmod><?= $lastmod ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
