<?php
/**
 * 法案の賛否カード（/bill/<id>.png、1200×630）。法案のページの og:image にする。
 *
 * 参議院の本会議の押しボタン投票は、会派ごとの賛成・反対の人数が公表されている（表 bill_vote。scripts/fetch_bills.py）。
 * 法案がニュースになった日に「どの党がどう投票したか」を1枚で貼れるようにする（2026-10-14）。
 * 衆議院は会派ごとの人数を公表していないので、結果（可決・多数／全会一致）だけを出す。
 * 賛否の良し悪しは書かない。人数だけ。
 */
declare(strict_types=1);

require_once __DIR__ . '/card.php';

function g_bill_png(string $id): void
{
    $f = g_card_cache_dir('bill') . '/' . preg_replace('/[^0-9a-z-]/', '', $id) . '.png';
    g_card_serve($f, function () use ($id) {
        $b = g_bill_ready() ? g_bill($id) : null;
        return $b ? g_bill_draw($b, g_all('SELECT * FROM bill_vote WHERE bill_id=? ORDER BY ord', [$id])) : null;
    }, g_abs('ogp.png'), 86400);
}

function g_bill_draw(array $b, array $votes): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $X = 56; $IW = 1088;
    $right = $b['san_plenary_date'] ? '参議院 本会議 ' . g_date((string)$b['san_plenary_date']) : (string)$b['status'];
    g_card_header($im, $C, $votes ? '法案の賛否｜参議院の会派ごと' : '法案の審議の経過', $right);

    $y = 118;
    foreach (g_card_wrap((string)$b['title'], $FB, 28, $IW, 2) as $ln) { imagettftext($im, 28, 0, $X, $y, $C['ink'], $FB, $ln); $y += 42; }

    $res = fn(string $h) => trim((string)$b[$h . '_plenary_result'] . ($b[$h . '_plenary_mode'] ? '（' . $b[$h . '_plenary_mode'] . '）' : ''));
    $line = trim(($res('shu') !== '' ? '衆議院 ' . $res('shu') : '') . ($res('san') !== '' ? '　→　参議院 ' . $res('san') : ''), '　→');
    if ($line === '') { $line = 'いまの状態：' . $b['status']; }
    if ($votes) {
        $yes = array_sum(array_column($votes, 'yes')); $no = array_sum(array_column($votes, 'no'));
        $line .= '　賛成 ' . $yes . '・反対 ' . $no;
    }
    imagettftext($im, g_card_fit($line, $FM, 22, $IW), 0, $X, $y + 8, $C['tealD'], $FM, $line);

    $top = $y + 34; $bottom = 566;
    if (!$votes) {
        imagettftext($im, 20, 0, $X, $top + 40, $C['gray'], $FR, '参議院の会派ごとの賛否は、まだ公表されていないか、記名の投票ではありませんでした。');
    } else {
        $n = count($votes);
        $cols = $n > 6 ? 2 : 1;
        $per = (int)ceil($n / $cols);
        $rowH = (int)min(60, floor(($bottom - $top) / $per));
        $colW = (int)floor(($IW - ($cols - 1) * 36) / $cols);
        $nameW = $cols === 2 ? 170 : 260; $numW = $cols === 2 ? 185 : 170;
        foreach ($votes as $i => $v) {
            $cx = $X + intdiv($i, $per) * ($colW + 36);
            $cy = $top + ($i % $per) * $rowH;
            $name = g_party_short((string)$v['party']);
            if ($name === 'その他') { $name = (string)$v['party']; }
            imagettftext($im, g_card_fit($name, $FM, 18, $nameW - 8, 12), 0, $cx, $cy + 24, $C['ink'], $FM, $name);
            $bx = $cx + $nameW; $bw = $colW - $nameW - $numW;
            $m = max(1, (int)$v['members']);
            $wy = (int)round($bw * (int)$v['yes'] / $m); $wn = (int)round($bw * (int)$v['no'] / $m);
            imagefilledrectangle($im, $bx, $cy + 8, $bx + $bw, $cy + 30, $C['abs']);
            if ($wy > 0) { imagefilledrectangle($im, $bx, $cy + 8, $bx + $wy, $cy + 30, $C['yes']); }
            if ($wn > 0) { imagefilledrectangle($im, $bx + $wy, $cy + 8, $bx + $wy + $wn, $cy + 30, $C['no']); }
            $t = '賛' . (int)$v['yes'] . '・反' . (int)$v['no'] . '／' . (int)$v['members'];
            imagettftext($im, 16, 0, $bx + $bw + 10, $cy + 26, $C['gray'], $FR, $t);
        }
    }
    g_card_footer($im, $C, 'bill/' . $b['id'], $votes ? '参議院の投票結果から（緑＝賛成・赤＝反対・灰＝投票なし）' : '国会の議案情報から');
    return g_card_png($im);
}
