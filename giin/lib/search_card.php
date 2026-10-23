<?php
/**
 * 検索結果（/search?q=…）のカード画像。X に貼ったときに「何の言葉で、何件か」が一目で分かるように（2026-10-23）。
 * 題名だけのカードは1本あたり中央値1人、名前と数字のカードは6人（10月の実測）。検索のページは今まで共通の画像だった。
 *   /search.png?q=…&party=…&k=q
 */
declare(strict_types=1);
require_once __DIR__ . '/card.php';

function g_search_card_args(): array
{
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 40);
    $party = mb_substr(trim((string)($_GET['party'] ?? '')), 0, 20);
    $kind = (string)($_GET['k'] ?? 'q');
    if (!in_array($kind, ['q', 'gov', 'ref'], true)) { $kind = 'q'; }
    return [$q, $party, $kind];
}

function g_search_png(): void
{
    [$q, $party, $kind] = g_search_card_args();
    $f = g_card_cache_dir('search') . '/' . md5($q . '|' . $party . '|' . $kind) . '.png';
    g_card_serve($f, function () use ($q, $party, $kind) {
        return $q === '' ? null : g_search_draw($q, $party, $kind);
    }, g_abs('ogp.png'), 86400);
}

function g_search_draw(string $q, string $party, string $kind): ?string
{
    $GA = function_exists('g_gall') ? g_gall() : 'giin'; $SA = function_exists('g_sall') ? g_sall() : 'speech';
    $w = ['s.kind = ?', 's.body LIKE ?']; $a = [$kind, '%' . $q . '%'];
    if ($party !== '') { $w[] = 'g.party = ?'; $a[] = $party; }
    $where = implode(' AND ', $w);
    $total = (int)g_val("SELECT COUNT(*) FROM $SA s JOIN $GA g ON g.id=s.giin_id WHERE $where", $a);
    $people = (int)g_val("SELECT COUNT(DISTINCT s.giin_id) FROM $SA s JOIN $GA g ON g.id=s.giin_id WHERE $where", $a);
    $top = g_all("SELECT g.plain, COUNT(*) c FROM $SA s JOIN $GA g ON g.id=s.giin_id WHERE $where GROUP BY s.giin_id ORDER BY c DESC LIMIT 3", $a);
    $by = [];
    foreach (g_all("SELECT substr(s.date,1,4) y, COUNT(*) c FROM $SA s JOIN $GA g ON g.id=s.giin_id WHERE $where GROUP BY y", $a) as $r) { $by[(string)$r['y']] = (int)$r['c']; }
    $from = (string)g_meta('range_from');
    $y0 = substr($from !== '' ? $from : '2023', 0, 4);
    $rows = function_exists('g_tm_years') ? g_tm_years($by, $y0, date('Y')) : array_map(fn($y) => [(string)$y, $by[(string)$y] ?? 0], range((int)$y0, (int)date('Y')));

    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    g_card_header($im, $C, 'Kurage 国会議員 発言ログ', '国会会議録から（' . g_date($from) . '〜）');
    $title = '「' . $q . '」';
    g_card_center($im, $title, $FB, g_card_fit($title, $FB, 64, 1060), 600, 160, $C['ink']);
    $lab = ['q' => '国会での質問・討論', 'gov' => '大臣などの答弁', 'ref' => '委員長などの議事整理'][$kind];
    $sub = ($party !== '' ? $party . 'の議員の' : '') . $lab . ' ' . number_format($total) . '件・' . $people . '人';
    g_card_center($im, $sub, $FB, g_card_fit($sub, $FB, 38, 1060), 600, 218, $C['tealD']);
    if ($top) {
        $t = '多い順：' . implode('　', array_map(fn($r) => $r['plain'] . ' ' . (int)$r['c'] . '件', $top));
        g_card_center($im, $t, $FM, g_card_fit($t, $FM, 22, 1060), 600, 262, $C['ink']);
    }
    imagettftext($im, 16, 0, 56, 300, $C['gray'], $FR, '年ごとの件数（最後の年は途中まで）');
    g_card_bars($im, $C, $rows, 56, 308, 1088, 250, $C['teal'], $C['tealD']);
    g_card_footer($im, $C, 'search', '言葉をふくむ発言の件数で、熱心さの差ではありません');
    return g_card_png($im);
}
