<?php
/**
 * 国会トラッカーのカード画像2種と、議員×テーマのページ。
 *
 *  /tracker/<key>.png              テーマの画像。年ごとの質疑の件数を棒グラフで入れる（件数が毎日変わるので1日で描き直す）
 *  /tracker/<key>/m/<id>           その議員がこのテーマで何をどれだけ質問したか（id は名前の crc32。名前は会議録の表記）
 *  /tracker/<key>/m/<id>.png       そのカード（議員名・回数・年ごとの棒・直近の一言）
 *
 * **なぜ作ったか。** 質疑1件のカードをXで返信に貼ると、議員本人に喜ばれた（2026-10-14 伊藤たかえ議員・内密出産）。
 * 1件より「あなたはこのテーマをこれだけ続けて取り上げてきた」のほうが、本人にも読む人にも伝わる。
 * 数字は会議録から数えたものだけ。順位や評価は付けない（件数の差は熱心さの差ではない）。
 */
declare(strict_types=1);

require_once __DIR__ . '/card.php';

/** 会議録の表記の名前 → URL の id（8桁の16進） */
function g_tm_id(string $speaker): string { return sprintf('%08x', crc32($speaker)); }

/** id → そのテーマで質疑のある議員の名前（無ければ null） */
function g_tm_speaker(string $key, string $id): ?string
{
    foreach (g_all("SELECT DISTINCT speaker FROM tracker_speech WHERE tracker=? AND kind='q'", [$key]) as $r) {
        if (g_tm_id((string)$r['speaker']) === $id) { return (string)$r['speaker']; }
    }
    return null;
}

/** 年ごとの件数を、最初の年から最後の年まで0で埋めて返す。[[年, 件数], ...] */
function g_tm_years(array $byYear, string $from, string $to): array
{
    $out = [];
    for ($y = (int)$from; $y <= (int)$to; $y++) { $out[] = [(string)$y, (int)($byYear[(string)$y] ?? 0)]; }
    // 棒が多すぎると読めないので、最後の16年まで
    return array_slice($out, -16);
}

/* ---------------- テーマの画像（推移の棒グラフ入り） ---------------- */

function g_tracker_png(string $key): void
{
    $t = g_tracker($key);
    $f = g_card_cache_dir('tracker') . '/' . preg_replace('/[^0-9a-z-]/', '', $key) . '.png';
    g_card_serve($f, function () use ($t, $key) {
        $st = $t ? g_tracker_stats($key) : [];
        return $st ? g_tracker_draw($t, $st) : null;
    }, g_abs('img/og/tracker-' . $key . '.png'), 86400);
}

function g_tracker_draw(array $t, array $st): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $by = [];
    foreach (g_tracker_years($t['key']) as $r) { $by[(string)$r['y']] = (int)$r['q']; }
    $rows = g_tm_years($by, substr((string)$st['first'], 0, 4), substr((string)$st['last'], 0, 4));
    g_card_header($im, $C, '国会トラッカー', '国会会議録から（' . g_date((string)$st['first']) . '〜' . g_date((string)$st['last']) . '）');
    $w = (string)($t['short'] ?? $t['name']);
    $title = $w . 'は、国会でどこまで来たか。';
    g_card_center($im, $title, $FB, g_card_fit($title, $FB, 46, 1060), 600, 150, $C['ink']);
    $sub = '質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件・取り上げた議員 ' . (int)$st['speakers'] . '人';
    g_card_center($im, $sub, $FM, g_card_fit($sub, $FM, 26, 1000), 600, 200, $C['tealD']);
    imagettftext($im, 17, 0, 56, 248, $C['gray'], $FR, '年ごとの議員の質疑の件数（最後の年は途中まで）');
    g_card_bars($im, $C, $rows, 56, 256, 1088, 300, $C['teal'], $C['tealD']);
    g_card_footer($im, $C, 'tracker/' . $t['key'], '件数の差は熱心さの差ではありません');
    return g_card_png($im);
}

/* ---------------- 議員×テーマ ---------------- */

function g_tm_data(string $key, string $speaker): array
{
    $rows = g_all("SELECT * FROM tracker_speech WHERE tracker=? AND speaker=? AND kind='q' ORDER BY date DESC, speech_order DESC",
                  [$key, $speaker]);
    $by = []; $days = []; $meet = [];
    foreach ($rows as $r) {
        $y = substr((string)$r['date'], 0, 4);
        $by[$y] = ($by[$y] ?? 0) + 1;
        $days[(string)$r['date']] = 1;
        $meet[$r['house'] . $r['meeting']] = 1;
    }
    return ['rows' => $rows, 'by' => $by, 'days' => count($days), 'meetings' => count($meet),
            'first' => $rows ? (string)end($rows)['date'] : '', 'last' => $rows ? (string)$rows[0]['date'] : ''];
}

function g_tm_png(string $key, string $id): void
{
    $t = g_tracker($key);
    $f = g_card_cache_dir('tm') . '/' . preg_replace('/[^0-9a-z-]/', '', $key) . '_' . preg_replace('/[^0-9a-f]/', '', $id) . '.png';
    g_card_serve($f, function () use ($t, $key, $id) {
        $sp = $t ? g_tm_speaker($key, $id) : null;
        return $sp ? g_tm_draw($t, $sp, g_tm_data($key, $sp)) : null;
    }, g_abs('img/og/tracker-' . $key . '.png'), 86400);
}

function g_tm_draw(array $t, string $speaker, array $d): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $last = $d['rows'][0];
    $party = g_party_short((string)($last['kaiha_at'] ?? ''));
    g_card_header($im, $C, '国会トラッカー｜' . $t['name'], (string)$last['house']);
    $name = $speaker . ' 議員' . ($party !== 'その他' ? '（' . $party . '）' : '');
    imagettftext($im, g_card_fit($name, $FB, 40, 1080), 0, 56, 140, $C['ink'], $FB, $name);
    $w = (string)($t['short'] ?? $t['name']);
    imagettftext($im, g_card_fit($w . 'を国会で取り上げた記録', $FM, 24, 1080), 0, 56, 184, $C['tealD'], $FM, $w . 'を国会で取り上げた記録');

    // 左: 数字
    $box = function (int $y, string $num, string $unit, string $label) use ($im, $C, $FB, $FM, $FR) {
        imagettftext($im, 46, 0, 60, $y, $C['ink'], $FB, $num);
        $nw = g_card_textw($num, $FB, 46);
        imagettftext($im, 22, 0, 66 + $nw, $y, $C['ink'], $FM, $unit);
        imagettftext($im, 16, 0, 62, $y + 28, $C['gray'], $FR, $label);
    };
    $box(262, (string)$d['days'], '日', '質疑で取り上げた日（' . count($d['rows']) . '件）');
    $box(358, substr($d['first'], 0, 4), '年から', '最初は' . g_date($d['first']));
    // 右: 年ごとの棒（その議員の分）
    $rows = g_tm_years($d['by'], substr($d['first'], 0, 4), substr($d['last'], 0, 4));
    g_card_bars($im, $C, $rows, 420, 200, 724, 210, $C['teal'], $C['tealD']);

    // 下: 直近の一言
    $q = g_qa_quote_q((string)$last['body'], (array)$t['words'], 110);
    imagefilledrectangle($im, 56, 430, 1144, 560, $C['mint2']);
    imagefilledrectangle($im, 56, 430, 62, 560, $C['teal']);
    imagettftext($im, 16, 0, 80, 458, $C['gray'], $FR, '直近の質問（' . g_date((string)$last['date']) . '　' . $last['meeting'] . '）');
    $ly = 494;
    foreach (g_card_wrap('「' . $q . '」', $FR, 21, 1040, 2) as $ln) {
        g_card_line($im, $ln, $FR, 21, 80, $ly, $C['ink'], $C['hi'], (array)$t['words']);
        $ly += 34;
    }
    g_card_footer($im, $C, 'tracker/' . $t['key'], '国会会議録から（要約していません）');
    return g_card_png($im);
}

function g_page_tm(string $key, string $id): void
{
    $t = g_tracker($key);
    $sp = $t ? g_tm_speaker($key, $id) : null;
    if (!$sp) {
        http_response_code(404); g_head('見つかりません', '', '/tracker/' . $key, ['noindex' => true]);
        echo '<h1>見つかりません</h1><p><a href="' . g_url('tracker/' . $key) . '">国会トラッカーへ</a></p>'; g_foot(); return;
    }
    $d = g_tm_data($key, $sp);
    $last = $d['rows'][0];
    $party = g_party_short((string)($last['kaiha_at'] ?? ''));
    $w = (string)($t['seo_word'] ?? $t['short'] ?? $t['name']);
    $path = 'tracker/' . $key . '/m/' . $id;
    $label = $sp . '議員と' . $w . '｜国会で取り上げた記録';
    g_head($label, $sp . '議員が国会で' . $w . 'を取り上げた質疑' . count($d['rows']) . '件（' . $d['days'] . '日）を、会議録から日付順に並べています。',
           '/' . $path, ['noindex' => true, 'nosuffix' => true, 'image' => g_abs($path . '.png')]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('tracker') . '">国会トラッカー</a> › '
       . '<a href="' . g_url('tracker/' . $key) . '">' . g_e($t['name']) . '</a> › ' . g_e($sp) . '議員</nav>'
       . '<p class="kick">Diet Tracker</p><h1>' . g_e($sp . '議員と' . ($t['short'] ?? $t['name'])) . '</h1>'
       . '<p class="lead">' . g_e(($party !== 'その他' ? $party . '・' : '') . $last['house']) . '。'
       . g_e(g_date($d['first'])) . 'から' . g_e(g_date($d['last'])) . 'まで、' . (int)$d['days'] . '日・'
       . (int)$d['meetings'] . 'つの会議で、質疑' . count($d['rows']) . '件。</p></section>';
    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url($path . '.png')) . '" alt="' . g_e($sp) . '議員の記録のカード" '
       . 'width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    g_share_buttons($label, $path, 'tm');

    echo '<h2>質疑の一覧</h2><p class="note">新しい順。各質疑の「質問と答弁をカードで見る」から、質問とその直後の政府の答弁を1枚にしたカードを開けます。</p>';
    foreach ($d['rows'] as $r) { g_tracker_speech($r + ['slug' => null, 'plain' => null], (array)$t['words']); }
    echo '<p class="note">数えているのは「' . g_e(implode('」「', (array)$t['words'])) . '」を含む、議員としての質疑です。'
       . '質疑ができるのはその日その委員会で質問に立った議員だけなので、件数の差は熱心さの差ではありません。</p>'
       . '<p class="more"><a class="btn o" href="' . g_url('tracker/' . $key) . '">' . g_e($w) . 'の国会答弁をすべて見る</a></p>';
    g_foot();
}
