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
        if (!$b) { return null; }
        $votes = g_all('SELECT * FROM bill_vote WHERE bill_id=? ORDER BY ord', [$id]);
        // 参議院の会派ごとの賛否があれば賛否カード、無ければ「いまここ」カード（審議中・委員会で審査中・継続審査など）
        return $votes ? g_bill_draw($b, $votes) : g_bill_progress_draw($b);
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

/**
 * 法案の「いまここ」カード。提出 → 先の院の委員会 → 本会議 → 後の院の委員会 → 本会議 → 成立 の6段を1本の線にし、
 * いまの段に印を付ける。会期の終わりで止まった法案（審議未了・付託されず・否決・撤回）は、止まった段に「ここで終了」と出す。
 * 段の日付と結果は議案情報のまま。賛否の良し悪しは書かない。
 */
function g_bill_stages(array $b): array
{
    $first = g_bill_shu_first($b) ? ['shu', '衆議院'] : ['san', '参議院'];
    $second = $first[0] === 'shu' ? ['san', '参議院'] : ['shu', '衆議院'];
    $fin = in_array($b['kind_code'], ['joyaku'], true) ? '承認' : '成立';
    $st = [['提出', '', (string)$b['submitted'], $b['submitted'] ? '提出' : '']];
    foreach ([$first, $second] as [$h, $hn]) {
        $cm = (string)$b[$h . '_committee'];
        $st[] = [$hn, '委員会', (string)$b[$h . '_committee_date'], (string)$b[$h . '_committee_result'], $cm];
        $st[] = [$hn, '本会議', (string)$b[$h . '_plenary_date'], (string)$b[$h . '_plenary_result']];
    }
    $done = in_array($b['status'], ['成立', '承認'], true);
    $st[] = [$fin, '', (string)$b['promulgated'], $done ? $fin : ''];
    return $st;
}

function g_bill_progress_draw(array $b): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $X = 56; $IW = 1088;
    g_card_header($im, $C, '法案のいまここ｜第' . (int)$b['session'] . '回国会', 'いまの状態：' . $b['status']);
    $y = 120;
    foreach (g_card_wrap((string)$b['title'], $FB, 28, $IW, 2) as $ln) { imagettftext($im, 28, 0, $X, $y, $C['ink'], $FB, $ln); $y += 42; }
    $sub = ($b['submitted'] ? g_date((string)$b['submitted']) . 'に' : '') . g_bill_submitter($b) . '提出';
    imagettftext($im, g_card_fit($sub, $FR, 20, $IW), 0, $X, $y + 4, $C['gray'], $FR, $sub);

    $st = g_bill_stages($b);
    // いまの段: 結果の入っていない最初の段（成立・承認なら最後）。止まった法案はその段で「ここで終了」
    $ended = in_array($b['status'], ['審議未了', '委員会に付託されず', '否決', '撤回'], true);
    $cur = count($st) - 1;
    foreach ($st as $i => $s) { if (($s[3] ?? '') === '' || ($s[3] ?? '') === '未了') { $cur = $i; break; } }
    // 委員会の名前が決まっていて結果が無い＝付託されて審査中。名前も無い＝付託前
    $n = count($st);
    $lx0 = $X + 60; $lx1 = $X + $IW - 60; $ly = 380;
    $px = fn(int $i) => (int)round($lx0 + ($lx1 - $lx0) * $i / ($n - 1));
    imagefilledrectangle($im, $lx0, $ly - 4, $lx1, $ly + 4, $C['abs']);
    imagefilledrectangle($im, $lx0, $ly - 4, $px($cur), $ly + 4, $ended ? $C['grayL'] : $C['teal']);
    foreach ($st as $i => $s) {
        $x = $px($i);
        $isDone = $i < $cur || ($i === $cur && in_array($b['status'], ['成立', '承認'], true));
        if ($i === $cur && !in_array($b['status'], ['成立', '承認'], true)) {
            imagefilledellipse($im, $x, $ly, 50, 50, $ended ? $C['grayL'] : $C['hi']);
            imagefilledellipse($im, $x, $ly, 26, 26, $C['white']);
            $tag = $ended ? 'ここで終了' : 'いまここ';
            $tw = g_card_textw($tag, $FB, 22);
            imagefilledrectangle($im, $x - (int)($tw / 2) - 14, $ly - 78, $x + (int)($tw / 2) + 14, $ly - 42, $ended ? $C['gray'] : $C['hi']);
            g_card_center($im, $tag, $FB, 22, $x, $ly - 51, $C['white']);
        } else {
            imagefilledellipse($im, $x, $ly, 30, 30, $isDone ? $C['teal'] : $C['abs']);
        }
        // 段の名前（2行）と、日付・結果
        g_card_center($im, $s[0], $FM, 19, $x, $ly + 48, $C['ink']);
        if ($s[1] !== '') { g_card_center($im, $s[1], $FM, 19, $x, $ly + 74, $C['ink']); }
        $note = '';
        if ($i === $cur && !$isDone) {
            if ($ended) { $note = $b['status']; }
            elseif ($s[1] === '委員会') { $note = !empty($s[4]) ? '審査中' : '付託前'; }
            else { $note = '審議中'; }
        } elseif ($s[3] !== '') {
            $note = ($s[2] ? g_date($s[2]) : '') . ($s[1] !== '' ? ' ' . $s[3] : '');
            $note = trim(preg_replace('/^\d{4}年/u', '', $note) ?? $note);
        }
        if ($note !== '') {
            $fs = g_card_fit($note, $FR, 16, (int)(($lx1 - $lx0) / ($n - 1)) - 8, 11);
            g_card_center($im, $note, $FR, $fs, $x, $ly + 102, $i === $cur && !$isDone ? ($ended ? $C['gray'] : $C['hi']) : $C['gray']);
        }
    }
    // 付託先の委員会（決まっていれば）
    $cm = '';
    foreach ($st as $s) { if (!empty($s[4]) && ($s[3] ?? '') === '') { $cm = $s[0] . ' ' . $s[4]; break; } }
    if ($cm !== '' && !$ended) {
        $t = 'いま審査している委員会：' . $cm;
        imagefilledrectangle($im, $X, 520, $X + $IW, 562, $C['mint2']);
        imagettftext($im, g_card_fit($t, $FM, 20, $IW - 40), 0, $X + 20, 549, $C['ink'], $FM, $t);
    }
    g_card_footer($im, $C, 'bill/' . $b['id'], '衆議院・参議院の議案情報から');
    return g_card_png($im);
}
