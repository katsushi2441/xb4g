<?php
/**
 * 地元の数字カード（選挙区×指標）。
 *
 *  /senkyoku/<key>/<metric>       その選挙区のその数字を1枚にしたページ（noindex）
 *  /senkyoku/<key>/<metric>.png   カード（数字・全国の順位・真ん中の区との比・消えた事業所・選挙区の衆議院議員）
 *
 * **なぜ作ったか。** 議員の投稿に返信するとき、相手の地元の数字が入ったカードが一番届く
 * （2026-10-14 小池正昭議員に、ご本人の地元の漁港の質疑カードを返した）。選挙区ダッシュボードの数字は
 * もう289区ぶんあるので、それを1枚ずつ貼れる形にする。
 * 数字は国と都道府県の公開データを足したもの。順位は並べ替えただけで、良い悪いの評価ではない。
 */
declare(strict_types=1);

require_once __DIR__ . '/card.php';

/** 指標 → [ラベル, 単位, 出典, リンク, 分類]。g_sk_metrics() から引く */
function g_skc_metric(string $metric): ?array
{
    foreach (g_sk_metrics() as [$k, $grp, $label, $unit, $src, $link]) {
        if ($k === $metric) { return ['label' => $label, 'unit' => $unit, 'src' => $src, 'link' => $link, 'group' => $grp]; }
    }
    return null;
}

/** 指標の「うち」（消えた事業所・特別警戒区域） */
function g_skc_sub(string $metric): ?string
{
    $m = ['houmon' => 'houmon_gone', 'caremane' => 'caremane_gone', 'houdei' => 'houdei_gone', 'ghome' => 'ghome_gone',
          'shuroA' => 'shuroA_gone', 'shuroB' => 'shuroB_gone', 'jihatsu' => 'jihatsu_gone', 'dosha' => 'dosha_red'];
    return $m[$metric] ?? null;
}

/** 出典の欄が空の指標は、同じ分類の上の行の出典を使う（g_sk_metrics の書き方に合わせる） */
function g_skc_src(string $metric): string
{
    $last = '';
    foreach (g_sk_metrics() as [$k, , , , $src]) {
        if ($src !== '' && strpos($src, '→') === false) { $last = $src; }
        if ($k === $metric) { return $src !== '' && strpos($src, '→') === false ? $src : $last; }
    }
    return '';
}

function g_skc_data(string $key, string $metric): ?array
{
    $d = g_sk_one($key);
    $mt = g_skc_metric($metric);
    if (!$d || !$mt) { return null; }
    $st = g_sk_stats($key);
    if (!isset($st[$metric])) { return null; }
    $rk = g_one('SELECT rank, total, median, ratio FROM senkyoku_rank WHERE key=? AND metric=?', [$key, $metric]);
    $sub = g_skc_sub($metric);
    $members = [];
    foreach (g_all('SELECT display, kaiha FROM senkyoku_member WHERE key=? ORDER BY display', [$key]) as $m) {
        $members[] = preg_replace('/\s+/u', '', (string)$m['display']) . '（' . g_party_short((string)$m['kaiha']) . '）';
    }
    return ['d' => $d, 'mt' => $mt, 'v' => (int)$st[$metric]['value'], 'partial' => (bool)$st[$metric]['partial'],
            'rk' => $rk, 'sub' => $sub && isset($st[$sub]) ? [$sub, (int)$st[$sub]['value'], g_skc_metric($sub)] : null,
            'members' => $members];
}

function g_skc_png(string $key, string $metric): void
{
    $f = g_card_cache_dir('senkyoku') . '/' . preg_replace('/[^a-z0-9-]/', '', $key) . '_' . preg_replace('/[^A-Za-z_]/', '', $metric) . '.png';
    g_card_serve($f, function () use ($key, $metric) {
        $x = g_skc_data($key, $metric);
        return $x ? g_skc_draw($key, $metric, $x) : null;
    }, g_abs('img/og/senkyoku.png'), 86400 * 7);
}

function g_skc_draw(string $key, string $metric, array $x): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $d = $x['d']; $mt = $x['mt']; $rk = $x['rk'];
    $X = 56;
    g_card_header($im, $C, '地元の数字｜' . $d['name'] . '（' . $d['area'] . '）',
                  $rk && (int)$rk['total'] >= 50 ? '全国' . (int)$rk['total'] . '区中 ' . (int)$rk['rank'] . '位（多い順）' : '');
    $label = $mt['label'] . ($x['partial'] ? '（市全体の数）' : '');
    imagettftext($im, g_card_fit($label, $FM, 30, 1080), 0, $X, 140, $C['tealD'], $FM, $label);

    // 大きな数字と単位
    $num = number_format($x['v']);
    imagettftext($im, 110, 0, $X - 4, 290, $C['ink'], $FB, $num);
    $nw = g_card_textw($num, $FB, 110);
    imagettftext($im, 36, 0, $X + $nw + 8, 290, $C['ink'], $FM, $mt['unit']);

    // 右: 全国の真ん中の区との比較
    $rx = 660;
    if ($rk && $rk['median'] !== null && (float)$rk['median'] > 0) {
        $ratio = $x['v'] / (float)$rk['median'];
        $rs = $ratio >= 0.95 && $ratio <= 1.05 ? '全国の真ん中の区とほぼ同じ'
            : '全国の真ん中の区の ' . ($ratio >= 10 ? number_format($ratio, 0) : number_format($ratio, 1)) . '倍';
        imagettftext($im, g_card_fit($rs, $FB, 32, 1144 - $rx), 0, $rx, 210, $C['ink'], $FB, $rs);
        $ms = '真ん中の区は ' . number_format((float)$rk['median']) . $mt['unit'];
        imagettftext($im, 20, 0, $rx, 250, $C['gray'], $FR, $ms);
    }
    if ($x['sub']) {
        [$sk, $sv, $smt] = $x['sub'];
        $pct = $x['v'] > 0 ? round($sv / $x['v'] * 100) : 0;
        $sl = preg_replace('/^うち/u', '', (string)($smt['label'] ?? ''));
        $s1 = 'うち' . $sl;
        $s2 = number_format($sv) . $mt['unit'] . ($sk !== 'dosha_red' ? '（いまの数の' . $pct . '%）' : '');
        imagettftext($im, g_card_fit($s1, $FR, 20, 1144 - $rx), 0, $rx, 296, $C['gray'], $FR, $s1);
        imagettftext($im, g_card_fit($s2, $FB, 30, 1144 - $rx), 0, $rx, 336, $C['hi'], $FB, $s2);
    }

    // 順位の線（左が多い・右が少ない）
    if ($rk && (int)$rk['total'] >= 50) {
        $ly = 400; $lx0 = $X; $lx1 = 1144;
        imagefilledrectangle($im, $lx0, $ly, $lx1, $ly + 8, $C['abs']);
        $px = (int)round($lx0 + ($lx1 - $lx0) * ((int)$rk['rank'] - 1) / max(1, (int)$rk['total'] - 1));
        imagefilledrectangle($im, $lx0, $ly, $px, $ly + 8, $C['teal']);
        imagefilledellipse($im, $px, $ly + 4, 26, 26, $C['tealD']);
        imagettftext($im, 16, 0, $lx0, $ly + 36, $C['gray'], $FR, '多い（1位）');
        $t = '少ない（' . (int)$rk['total'] . '位）';
        imagettftext($im, 16, 0, $lx1 - g_card_textw($t, $FR, 16), $ly + 36, $C['gray'], $FR, $t);
    }

    // 議員
    if ($x['members']) {
        $ms = 'この選挙区の衆議院議員：' . implode('・', $x['members']);
        imagefilledrectangle($im, $X, 470, 1144, 530, $C['mint2']);
        imagettftext($im, g_card_fit($ms, $FM, 22, 1050), 0, $X + 20, 509, $C['ink'], $FM, $ms);
    }
    g_card_footer($im, $C, 'senkyoku/' . $key, '出典：' . g_skc_src($metric));
    return g_card_png($im);
}

function g_page_skc(string $key, string $metric): void
{
    $x = g_skc_data($key, $metric);
    if (!$x) {
        http_response_code(404); g_head('見つかりません', '', '/senkyoku', ['noindex' => true]);
        echo '<h1>見つかりません</h1><p><a href="' . g_url('senkyoku') . '">選挙区ダッシュボードへ</a></p>'; g_foot(); return;
    }
    $d = $x['d']; $mt = $x['mt']; $rk = $x['rk'];
    $path = 'senkyoku/' . $key . '/' . $metric;
    $rank = $rk && (int)$rk['total'] >= 50 ? '全国' . (int)$rk['total'] . '区中' . (int)$rk['rank'] . '位' : '';
    $label = $d['name'] . '（' . $d['area'] . '）の' . $mt['label'] . ' ' . number_format($x['v']) . $mt['unit'] . ($rank ? '・' . $rank : '');
    g_head($label, $label . '。国と都道府県の公開データを足した数字です。', '/' . $path,
           ['noindex' => true, 'nosuffix' => true, 'image' => g_abs($path . '.png')]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('senkyoku') . '">選挙区ダッシュボード</a> › '
       . '<a href="' . g_url('senkyoku/' . $key) . '">' . g_e($d['name']) . '</a> › ' . g_e($mt['label']) . '</nav>'
       . '<p class="kick">地元の数字</p><h1>' . g_e($d['name'] . 'の' . $mt['label']) . '</h1>'
       . '<p class="lead">' . g_e($d['area']) . '。' . g_e(number_format($x['v']) . $mt['unit']) . ($rank ? '（' . g_e($rank) . '）' : '') . '。</p></section>';
    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url($path . '.png')) . '" alt="' . g_e($label) . '" '
       . 'width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    g_share_buttons($label, $path, 'sk');
    echo '<div class="panel"><p style="margin:0">数字は国と都道府県の公開データ（' . g_e(g_skc_src($metric)) . '）を、選挙区の区域にある市区町村で足したものです。'
       . ($x['partial'] ? '選挙区が市の一部だけのときは、市全体の数で数えています。' : '')
       . '順位は全国の選挙区で同じ指標を並べ替えただけで、良い悪いの評価ではありません。</p></div>';
    if ($mt['link']) {
        echo '<p class="more"><a class="btn o" href="' . g_e($mt['link'] . '#ref=senkyoku-card-' . $metric) . '" target="_blank" rel="noopener">'
           . g_e($mt['label']) . 'を住所や市区町村で調べる</a></p>';
    }
    // 同じ選挙区のほかのカード
    $st = g_sk_stats($key);
    echo '<h2>' . g_e($d['name']) . 'のほかの数字</h2><div class="panel"><ul>';
    foreach (g_sk_metrics() as [$k, , $l, $u]) {
        if ($k === $metric || !isset($st[$k]) || str_ends_with($k, '_gone') || $k === 'dosha_red') { continue; }
        echo '<li><a href="' . g_url('senkyoku/' . $key . '/' . $k) . '" rel="nofollow">' . g_e($l) . '</a> ' . number_format((int)$st[$k]['value']) . g_e($u) . '</li>';
    }
    echo '</ul></div><p class="more"><a class="btn o" href="' . g_url('senkyoku/' . $key) . '">' . g_e($d['name']) . 'の公開データをすべて見る</a></p>';
    g_foot();
}
