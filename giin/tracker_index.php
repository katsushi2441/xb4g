<?php
/**
 * Kurage 国会トラッカー — テーマごとに、全国の国会議員の質疑と政府の答弁を会議録から集める。
 *
 * 配布物ではこのファイルが index.php になる（scripts/make_store_zips.py）。
 * 議員発言ログ（index.php）と同じ部品を使い、名簿・ことがら・法案は持たない。
 *
 * **しないこと**：要約・論評・賛否の判定・議員の序列づけ。
 * 出すのは「語を含む文の抜粋」と「会議録へのリンク」だけ。
 */
declare(strict_types=1);

define('G_APP', 'tracker');
define('G_HAS_TRACKER', true);
define('G_HAS_QA', true);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/ui.php';
require __DIR__ . '/lib/trackers.php';
require __DIR__ . '/lib/tracker_pages.php';
require __DIR__ . '/lib/qa.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (G_BASE !== '' && strpos($path, G_BASE) === 0) { $path = substr($path, strlen(G_BASE)); }
$path = '/' . trim($path, '/');
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    if ($path === '/') { g_redirect(g_url('tracker')); }
    elseif ($path === '/tracker') { g_page_trackers(); }
    elseif ($path === '/about') { gt_page_about(); }
    elseif ($path === '/sitemap.xml') { gt_sitemap(); }
    elseif (preg_match('#^/tracker/([a-z0-9-]+)$#', $path, $m)) { g_page_tracker($m[1], $page); }
    elseif (preg_match('#^/tracker/([a-z0-9-]+)/([0-9A-Za-z]+_\d+)\.png$#', $path, $m)) { g_qa_png($m[1], $m[2]); }
    elseif (preg_match('#^/tracker/([a-z0-9-]+)/([0-9A-Za-z]+_\d+)$#', $path, $m)) { g_page_qa($m[1], $m[2]); }
    else {
        http_response_code(404);
        g_head('見つかりません', '', $path, ['noindex' => true]);
        echo '<h1>見つかりません</h1><p><a href="' . g_url('tracker') . '">テーマの一覧へ</a></p>';
        g_foot();
    }
} catch (Throwable $e) {
    http_response_code(500);
    g_head('準備中', '', $path, ['noindex' => true]);
    echo '<h1>準備中です</h1><div class="panel">' . g_e($e->getMessage()) . '</div>';
    g_foot();
}

function gt_page_about(): void
{
    g_head('このサイトについて', 'テーマの語を含む国会の発言を、会議録から機械的に集めて並べています。要約・論評・賛否の判定はしません。', '/about');
    $n = count(g_trackers());
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › このサイトについて</nav>'
       . '<h1>このサイトについて</h1></section><div class="panel">'
       . '<h2 style="margin-top:0">何をしているか</h2>'
       . '<p>' . $n . 'のテーマそれぞれについて、決めた語を含む発言を国立国会図書館の国会会議録検索システムから集め、'
       . 'だれが質問し、政府が何と答えたかを日付順に並べています。議員の地域や党派では選んでいません。</p>'
       . '<h2>立場の分け方</h2>'
       . '<p>発言の冒頭の話者表記（「○○君」「○○国務大臣」「○○委員長」など）から、議員の質疑・政府の答弁・委員長の議事整理・参考人に機械的に分けています。'
       . '分けずに数えると、委員長や大臣を務めた議員の件数が跳ね上がり、「よく質問している人」に見えてしまうためです。</p>'
       . '<h2>質疑1件のカード</h2>'
       . '<p>発言ごとの「質問と答弁をカードで見る」から、質問とその直後の政府の答弁を1枚の画像にしたページを開けます。'
       . 'そのページのURLをXやSlackに貼ると、カードが表示されます。答弁は「質問の直後の政府側の発言」を機械的に取り出したもので、'
       . '別の論点への答えのときは組にしません。</p>'
       . '<h2>しないこと</h2><ul><li>要約しない（語を含む文をそのまま抜粋する）</li><li>論評しない</li><li>賛成・反対を判定しない</li>'
       . '<li>議員に順位をつけない（件数の差は熱心さの差ではありません）</li></ul>'
       . '<p class="note">会議録は会議から1〜2週間おくれて公開されるため、直近の審議はまだ載っていないことがあります。</p></div>';
    g_foot();
}

function gt_sitemap(): void
{
    header('Content-Type: application/xml; charset=UTF-8');
    $last = [];
    if (g_tracker_ready()) {
        foreach (g_all('SELECT tracker, MAX(date) AS d FROM tracker_speech GROUP BY tracker') as $r) { $last[$r['tracker']] = (string)$r['d']; }
    }
    $urls = [['tracker', $last ? max($last) : ''], ['about', '']];
    foreach (g_trackers() as $t) { $urls[] = ['tracker/' . $t['key'], max($last[$t['key']] ?? '', (string)($t['added'] ?? ''))]; }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as [$u, $d]) {
        echo '<url><loc>' . g_e(g_abs($u)) . '</loc>' . ($d !== '' ? '<lastmod>' . g_e(substr($d, 0, 10)) . '</lastmod>' : '') . '</url>';
    }
    echo '</urlset>';
}
