<?php
/**
 * 質疑と、政府・国会側の動き（法案の提出・成立、会議のとりまとめ・閣議決定）の前後関係（2026-11-15）。
 *
 * 国政報告では「自分の質疑が政府を動かした」と話されることがある。それが本当かは記録からは決められないが、
 * **質疑が、法案の提出や政府の決定より前だったか後だったか**は日付で分かる。それを同じページに並べる。
 * ChatGPT などは1回の質問で数ページしか読まないので、トラッカーと法案と政策の流れに分かれていると比べてくれない。
 *
 * 書くのは日付と日数だけ。「後押しした」「関係ない」のような判定は書かない（国会ファクトと同じ決まり）。
 * 法案は「このテーマの質疑の中で会議録に法案名が出てきたもの」。1件の質疑がその法案について聞いたものとは限らない
 * （その質疑自体で法案名に触れているときだけ、そう書く）。
 */
declare(strict_types=1);

/** その法案が成立した日（後の院の本会議で可決した日）。成立していなければ '' */
function g_tl_passed(array $b): string
{
    if (($b['status'] ?? '') !== '成立') { return ''; }
    $d = [];
    foreach (['shu', 'san'] as $h) {
        $r = (string)($b[$h . '_plenary_result'] ?? '');
        if ($b[$h . '_plenary_date'] && (mb_strpos($r, '可決') !== false || mb_strpos($r, '成立') !== false)) { $d[] = (string)$b[$h . '_plenary_date']; }
    }
    return $d ? max($d) : '';
}

/**
 * このテーマの法案。**このトラッカーの質疑の中で、会議録で法案名が出てきたもの**だけ（bill_speech で突き合わせる）。
 * 件名に語が入るかだけで選ぶと、「子ども」で子ども・子育て支援法、「中小企業」で下請法のように別の法案が混ざった（2026-11-15 実測）。
 * 名前が出た質疑が3件以上の法案（多い順に5本）＋件名に語が入り、名前が1件でも出た法案。提出日の古い順
 */
function g_tl_bills(array $t, int $limit = 6): array
{
    static $c = [];
    $key = (string)$t['key'];
    if (isset($c[$key])) { return $c[$key]; }
    if (!function_exists('g_bills_for_words') || !g_bill_ready()) { return $c[$key] = []; }
    $n = [];
    foreach (g_all("SELECT b.bill_id id, COUNT(DISTINCT t.speech_id) n FROM tracker_speech t JOIN bill_speech b ON b.speech_id=t.speech_id
                    JOIN bill bl ON bl.id=b.bill_id WHERE t.tracker=? AND bl.kind_code IN ('kaku','shu','san') GROUP BY b.bill_id ORDER BY n DESC", [$key]) as $r) {
        $n[(string)$r['id']] = (int)$r['n'];
    }
    $ids = array_slice(array_keys(array_filter($n, fn($x) => $x >= 3)), 0, 5);
    foreach (array_column(g_bills_for_words((array)($t['words'] ?? []), 8), 'id') as $id) {
        if (($n[(string)$id] ?? 0) >= 1 && !in_array((string)$id, $ids, true)) { $ids[] = (string)$id; }
    }
    $out = [];
    foreach (array_slice($ids, 0, $limit) as $id) {
        $b = g_bill($id);
        if ($b && $b['submitted']) { $b['_passed'] = g_tl_passed($b); $b['_n'] = $n[$id] ?? 0; $out[] = $b; }
    }
    usort($out, fn($x, $y) => strcmp((string)$x['submitted'], (string)$y['submitted']));
    return $c[$key] = $out;
}

function g_tl_who(array $b): string
{
    return g_bill_submitter($b);
}

/**
 * 政府・国会側の動きを日付順に。要素は [日付, 文, リンク, 種類]。
 * 種類: bill-submit（法案の提出）, bill-pass（成立）, decision（会議のとりまとめ・閣議決定）
 */
function g_tl_events(array $t): array
{
    static $c = [];
    $key = (string)$t['key'];
    if (isset($c[$key])) { return $c[$key]; }
    $ev = [];
    foreach (g_tl_bills($t) as $b) {
        $u = g_url('bill/' . $b['id']);
        $ev[] = [(string)$b['submitted'], g_tl_who($b) . '「' . $b['title'] . '」を提出', $u, 'bill-submit'];
        if ($b['_passed']) { $ev[] = [$b['_passed'], '「' . $b['title'] . '」が成立', $u, 'bill-pass']; }
    }
    // 政策の流れトラッカーにある会議のとりまとめ・閣議決定（いまは AI政策だけ日付つきで持っている）
    if (function_exists('g_flow_for_tracker')) {
        foreach (g_flow_for_tracker($key) as $f) {
            $d = g_flow_load((string)$f['slug']);
            $seen = [];
            foreach ((array)($d['councils'] ?? []) as $co) {
                if (!is_array($co)) { continue; }
                foreach ((array)($co['outputs'] ?? []) as $o) {
                    $date = (string)($o['date'] ?? '');
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || isset($seen[$date . $o['title']])) { continue; }
                    $seen[$date . $o['title']] = 1;
                    $note = (string)($o['date_note'] ?? '');
                    $who = (string)($o['issuer'] ?? $co['name'] ?? '');
                    $txt = (mb_strpos($note, '閣議決定') !== false ? '閣議決定' : $who . 'が公表') . '：「' . $o['title'] . '」';
                    $ev[] = [$date, $txt, (string)($o['url'] ?? ''), 'decision'];
                }
            }
        }
    }
    usort($ev, fn($x, $y) => strcmp($x[0], $y[0]));
    return $c[$key] = $ev;
}

function g_tl_days(string $a, string $b): int
{
    return (int)round((strtotime($b) - strtotime($a)) / 86400);
}

function g_tl_rel(string $ev, string $q): string
{
    $n = g_tl_days($q, $ev);
    if ($n === 0) { return 'この質疑と同じ日'; }
    return $n < 0 ? 'この質疑の' . number_format(-$n) . '日前' : 'この質疑の' . number_format($n) . '日後';
}

function g_tl_li(array $e, string $extra = ''): string
{
    $txt = g_e($e[1]);
    if ($e[2] !== '') {
        $ext = strpos($e[2], 'http') === 0;
        $txt = '<a href="' . g_e($e[2]) . '"' . ($ext ? ' rel="nofollow noopener" target="_blank"' : '') . '>' . $txt . '</a>';
    }
    $mark = ['bill-submit' => '法案', 'bill-pass' => '成立', 'decision' => '政府', 'q' => '質疑'][$e[3]] ?? '';
    return '<li><b>' . g_e(g_date($e[0])) . '</b>　<span class="pill' . ($e[3] === 'q' ? ' gray' : '') . '">' . $mark . '</span> ' . $txt . $extra . '</li>';
}

const G_TL_NOTE = '日付は衆議院・参議院の議案情報と、政府の公表資料のものです。法案は、このテーマの質疑の中で、会議録に法案名が出てきたものです。'
    . '質疑と政府の動きの前後は日付で分かりますが、ある質疑が政府の判断を動かしたかどうかは、記録からは決められません。当サイトはその判定をしません。';

/** トラッカーのページ：このテーマの法案と政府の決定を日付順に */
function g_tl_box_tracker(array $t): string
{
    $ev = g_tl_events($t);
    if (!$ev) { return ''; }
    $h = '<h2 id="timeline">法案と政府の決定の時系列</h2>'
       . '<p class="note">「◯◯の質疑で政府を動かした」という話を確かめるときは、質疑の日付と、下の法案の提出・成立や政府の決定の日付を比べてください。'
       . '議員ごとのページでは、その議員の質疑を同じ時系列に並べています。</p><div class="panel"><ul class="tl">';
    foreach (array_slice($ev, -14) as $e) { $h .= g_tl_li($e); }
    return $h . '</ul><p class="note" style="margin:8px 0 0">' . G_TL_NOTE . '</p></div>';
}

/** 議員×テーマのページ：その議員の質疑を、法案・政府の決定と同じ時系列に */
function g_tl_box_tm(array $t, string $speaker, array $rows): string
{
    $ev = g_tl_events($t);
    if (!$ev || !$rows) { return ''; }
    $qdays = [];
    foreach ($rows as $r) { $qdays[(string)$r['date']][] = $r; }
    ksort($qdays);
    $h = '<h2 id="timeline">' . g_e($speaker) . '議員の質疑と、法案・政府の決定の前後</h2><div class="panel">';
    // 法案ごとに、質疑の日が提出の前か、審議中か、成立の後かを数える（日付だけ）
    $lines = '';
    foreach (g_tl_bills($t) as $b) {
        $sub = (string)$b['submitted']; $pas = (string)$b['_passed'];
        $pre = $mid = $post = 0;
        foreach (array_keys($qdays) as $d) {
            if ($d < $sub) { $pre++; } elseif ($pas === '' || $d <= $pas) { $mid++; } else { $post++; }
        }
        $lines .= '<li><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a>（' . g_e(g_date($sub)) . 'に' . g_e(g_tl_who($b)) . '提出'
            . ($pas ? '・' . g_e(g_date($pas)) . 'に成立' : '・' . g_e((string)$b['status'])) . '）<br>'
            . 'この議員の質疑の日：提出より前 <b>' . $pre . '日</b>／提出から' . ($pas ? '成立まで' : 'いままで') . ' <b>' . $mid . '日</b>'
            . ($pas ? '／成立より後 <b>' . $post . '日</b>' : '') . '</li>';
    }
    if ($lines !== '') {
        $h .= '<p style="margin:0 0 6px">' . g_e($speaker) . '議員がこのテーマで質疑した' . count($qdays) . '日を、このテーマの法案の提出日・成立日と比べました。</p><ul>' . $lines . '</ul>';
    }
    // 時系列（政府の動き＋この議員の質疑の日）。長くなりすぎないよう、質疑のあった期間の前後1年に絞る
    $first = (string)array_key_first($qdays); $last = (string)array_key_last($qdays);
    $lo = date('Y-m-d', strtotime($first . ' -1 year')); $hi = date('Y-m-d', strtotime($last . ' +1 year'));
    $all = array_values(array_filter($ev, fn($e) => $e[0] >= $lo && $e[0] <= $hi));
    foreach ($qdays as $d => $rs) {
        $r = $rs[0];
        $all[] = [$d, $speaker . '議員が質疑（' . $r['house'] . ' ' . $r['meeting'] . (count($rs) > 1 ? '・' . count($rs) . '回' : '') . '）',
                  g_url('tracker/' . $t['key'] . '/' . $r['speech_id']), 'q'];
    }
    usort($all, fn($x, $y) => strcmp($x[0], $y[0]) ?: strcmp($x[3] === 'q' ? '1' : '0', $y[3] === 'q' ? '1' : '0'));
    $h .= '<p style="margin:10px 0 6px;font-weight:700">時系列</p><ul class="tl">';
    foreach ($all as $e) { $h .= g_tl_li($e); }
    return $h . '</ul><p class="note" style="margin:8px 0 0">' . G_TL_NOTE . '</p></div>';
}

/** 質疑1件のページ：この質疑の時点で、法案と政府の決定はどこまで来ていたか */
function g_tl_box_qa(array $t, array $q): string
{
    $qd = (string)$q['date'];
    $direct = [];
    foreach (g_all('SELECT bill_id FROM bill_speech WHERE speech_id=?', [$q['speech_id']]) as $r) { $direct[(string)$r['bill_id']] = 1; }
    $bills = [];
    foreach (array_keys($direct) as $id) { if ($b = g_bill($id)) { $b['_passed'] = g_tl_passed($b); $b['_direct'] = 1; $bills[$id] = $b; } }
    foreach (g_tl_bills($t) as $b) {
        // 話題の法案は、この質疑の前後2年のものだけ（古い同名の法案を並べない）
        if (isset($bills[$b['id']]) || abs(g_tl_days($qd, (string)$b['submitted'])) > 730) { continue; }
        $bills[$b['id']] = $b;
    }
    $dec = array_values(array_filter(g_tl_events($t), fn($e) => $e[3] === 'decision'));
    if (!$bills && !$dec) { return ''; }
    $h = '<h2 id="timeline">この質疑の時点で、法案と政府の決定はどこまで来ていたか</h2><div class="panel"><ul class="tl">';
    foreach ($bills as $b) {
        $sub = (string)$b['submitted']; $pas = (string)$b['_passed'];
        $state = $sub > $qd ? 'この質疑の時点では、まだ提出されていません'
               : ($pas !== '' && $pas < $qd ? 'この質疑の時点で、すでに成立していました' : 'この質疑の時点で、すでに国会に提出されていました');
        $h .= '<li><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a>'
            . (!empty($b['_direct']) ? ' <span class="pill">この質疑で法案名に言及</span>' : '') . '<br>'
            . g_e(g_date($sub)) . 'に' . g_e(g_tl_who($b)) . '提出（' . g_e(g_tl_rel($sub, $qd)) . '）'
            . ($pas ? '・' . g_e(g_date($pas)) . 'に成立（' . g_e(g_tl_rel($pas, $qd)) . '）' : '・いまの状態：' . g_e((string)$b['status']))
            . '。<b>' . g_e($state) . '。</b></li>';
    }
    // 会議のとりまとめ・閣議決定は、この質疑の直前と直後の1件ずつ
    $before = null; $after = null;
    foreach ($dec as $e) { if ($e[0] <= $qd) { $before = $e; } elseif (!$after) { $after = $e; } }
    foreach ([$before, $after] as $e) { if ($e) { $h .= g_tl_li($e, '（' . g_e(g_tl_rel($e[0], $qd)) . '）'); } }
    return $h . '</ul><p class="note" style="margin:8px 0 0">' . G_TL_NOTE . '</p></div>';
}
