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
foreach ((g_tracker_ready() ? g_all('SELECT tracker, MAX(date) AS d FROM tracker_speech GROUP BY tracker') : []) as $r) { $trackerLast[$r['tracker']] = substr((string)$r['d'], 0, 10); }
$urls = [
    ['', '1.0', 'daily', $speechLast],
    ['list', '0.9', 'weekly', $speechLast],
    ['theme', '0.9', 'weekly', $speechLast],
    // トラッカーの一覧ページ。個別28本は下で足していたのにハブだけ漏れていて、
    // 2026-09-19 時点で tracker/* は1本もクロールされていなかった。
    ['party', '0.9', 'weekly', $speechLast],
    ['news', '0.8', 'daily', $newsLast],
    ['ai', '0.9', 'weekly', $fileLast],
    ['compare', '0.7', 'weekly', $speechLast],
    ['about', '0.5', 'monthly', $fileLast],
];
foreach (g_themes() as $t) { $urls[] = ['theme/' . $t['slug'], '0.9', 'weekly', $speechLast]; }
// トラッカーの lastmod は「最後の発言の日」「トラッカーを作った日（trackers.json の added）」「ページの作りを変えた日」の新しい方。
// 最後の発言の日だけだと、10/9 に作ったデジタル教科書が「6月9日」に見え、新しいページだと伝わらなかった（2026-10-09）。
// ページの作りを変えた日＝全トラッカーの冒頭に「数字で見る要点」を足した日（本当に中身が変わった日。毎回 now にはしない）
$trackerRevised = '2026-10-09';
if (is_file(__DIR__ . '/lib/tracker_pages.php') && g_trackers()) { $urls[] = ['tracker', '0.9', 'weekly', max(max($trackerLast ?: [$speechLast]), '2026-10-09')]; }
foreach ((is_file(__DIR__ . '/lib/tracker_pages.php') ? g_trackers() : []) as $t) { $urls[] = ['tracker/' . $t['key'], '0.9', 'weekly', max($trackerLast[$t['key']] ?? $speechLast, (string)($t['added'] ?? ''), $trackerRevised)]; }
if (g_sk_ready()) {
    $urls[] = ['senkyoku', '0.9', 'weekly', $speechLast];
    foreach (g_sk_all() as $s) { $urls[] = ['senkyoku/' . $s['key'], '0.8', 'weekly', $speechLast]; }
}
foreach (g_parties() as $p) { $urls[] = ['party/' . g_party_slug($p['party']), '0.8', 'weekly', $speechLast]; }
// 法案（scripts/fetch_bills.py）。lastmod は最後の動きの日（毎回 now にしない）
if ((bool)g_val("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bill'")) {
    $urls[] = ['bill', '0.9', 'weekly', (string)g_val('SELECT MAX(last_date) FROM bill')];
    foreach (g_all('SELECT id, last_date FROM bill ORDER BY id') as $b) { $urls[] = ['bill/' . $b['id'], '0.7', 'monthly', (string)$b['last_date']]; }
}
foreach (g_all('SELECT slug, last_date FROM giin ORDER BY slug') as $g) { $urls[] = [$g['slug'], '0.8', 'weekly', substr((string)($g['last_date'] ?: $speechLast), 0, 10)]; }
// 全国の議員の軽いページ（2026-10-15〜）。発言が1件以上ある人だけ（中身の無いページを索引に出さない）
if ((bool)g_val("SELECT 1 FROM sqlite_master WHERE name='giin_all'")) {
    foreach (g_all('SELECT slug, last_date FROM giin_all WHERE detail=0 AND n_speech>0 ORDER BY slug') as $g) { $urls[] = [$g['slug'], '0.6', 'weekly', substr((string)$g['last_date'], 0, 10)]; }
}

// 国会ごとの記録（2026-10-19）。lastmod は、閉じた国会は会期の終わりの日、会期中はその国会の最後の発言の日
if ((bool)g_val("SELECT 1 FROM sqlite_master WHERE name='sess_meta'")) {
    $sl = [];
    foreach (g_all('SELECT m.session, m.close, (SELECT MAX(date) FROM speech_all a WHERE a.session=m.session) AS last FROM sess_meta m') as $r) {
        $sl[(int)$r['session']] = (string)($r['close'] ?: $r['last'] ?: $speechLast);
    }
    $urls[] = ['kokkai', '0.8', 'weekly', $sl ? max($sl) : $speechLast];
    foreach ($sl as $sn => $lm) { $urls[] = ['kokkai/' . $sn, '0.8', 'weekly', $lm]; }
    // 中身のある組だけ（質疑か質問主意書が1件以上）
    foreach (g_all('SELECT g.slug, m.session FROM sess_member m JOIN giin_all g ON g.id=m.giin_id WHERE m.n_q>0 OR m.n_shuisho>0 ORDER BY g.slug, m.session') as $r) {
        $urls[] = [$r['slug'] . '/' . (int)$r['session'], '0.5', 'monthly', $sl[(int)$r['session']] ?? $speechLast];
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
   . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$p, $pri, $freq, $lm]) {
    echo '  <url><loc>' . g_e(g_abs($p)) . '</loc>'
       . ($lm !== '' ? '<lastmod>' . g_e($lm) . '</lastmod>' : '')
       . '<changefreq>' . $freq . '</changefreq>'
       . '<priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
