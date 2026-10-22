<?php
/**
 * 国会（回次）ごとの記録。2026-10-19。
 *   /<議員>/<回次>        その議員がその国会で、質疑・取り上げた法案・質問主意書をどれだけしたか（同じ院の中の順位つき）
 *   /<議員>/<回次>.png    そのカード
 *   /kokkai               国会の一覧
 *   /kokkai/<回次>        その国会の法案と、全議員の順位
 *   /kokkai/<回次>.png    そのカード
 * 材料は scripts/build_session_stats.py が作る sess_meta / sess_member / sess_bill_member。
 * 数えただけの数字で、質疑の中身の良し悪しは見ていない。ページに必ずそう書く。
 */
declare(strict_types=1);

function g_sess_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE name='sess_meta'"); }
    return $ok;
}

function g_sess_meta(int $s): ?array
{
    return g_sess_ready() ? g_one('SELECT * FROM sess_meta WHERE session=?', [$s]) : null;
}

/** 「第221回国会（特別会）」 */
function g_sess_label(array $m): string { return '第' . (int)$m['session'] . '回国会（' . $m['kind'] . '）'; }

/** 「2026年2月18日〜7月25日」。同じ年なら終わりの年を省く。会期中なら「〜（会期中）」 */
function g_sess_period(array $m): string
{
    $o = g_date((string)$m['open']);
    if (empty($m['close'])) { return $o . '〜（会期中）'; }
    $c = g_date((string)$m['close']);
    if (substr((string)$m['open'], 0, 4) === substr((string)$m['close'], 0, 4)) { $c = preg_replace('/^\d+年/u', '', $c); }
    return $o . '〜' . $c . (!empty($m['dissolved']) ? '（解散）' : '');
}

/** 順位の書き方。「参議院で 3位（247人中）」。0件は順位なし */
function g_sess_rank($rk, $n, string $house): string
{
    return $rk ? $house . 'で ' . (int)$rk . '位（' . (int)$n . '人中）' : $house . 'で 該当なし';
}

const G_SESS_NOTE = '数えたのは、国会会議録の発言と質問主意書の件数です。質疑の中身の良し悪しは見ていません。'
    . '大臣・副大臣・政務官のあいだは質問に立たない、与党は質問の時間が少ない、法案を担当する委員会にいないと質問の機会がない、などの事情で件数は大きく変わります。'
    . '「取り上げた法案」は、法案の件名（新しく作る法律は法律名も）を言った質疑を数えたもので、「本法律案」のように件名を言わない発言は入りません。';

function g_sess_member_row(string $slug, int $s): ?array
{
    $GA = g_gall();
    return g_one("SELECT g.*, m.n_q AS s_q, m.n_gov AS s_gov, m.n_chair AS s_chair, m.q_days, m.q_meetings, m.top_meetings, m.n_bills,
                         m.n_shuisho, m.shuisho_evasive, m.rank_q, m.rank_bills, m.rank_shuisho, m.n_house
                  FROM $GA g JOIN sess_member m ON m.giin_id=g.id WHERE g.slug=? AND m.session=?", [$slug, $s]);
}

/* ---------------- 議員×国会 ---------------- */

function g_page_member_session(string $slug, int $s): void
{
    $m = g_sess_meta($s);
    $g = $m ? g_sess_member_row($slug, $s) : null;
    if (!$g) {
        http_response_code(404); g_head('見つかりません', '', '/', ['noindex' => true]);
        echo '<h1>見つかりません</h1><p>この国会での記録がありません。<a href="' . g_url($slug) . '">議員のページへ</a></p>'; g_foot(); return;
    }
    $lab = g_sess_label($m);
    $path = $slug . '/' . $s;
    $title = $g['plain'] . '議員の' . $lab . '｜質疑' . number_format((int)$g['s_q']) . '回・法案' . (int)$g['n_bills'] . '本・質問主意書' . (int)$g['n_shuisho'] . '件';
    $desc = $g['plain'] . '（' . $g['house'] . '・' . $g['party'] . '）が' . $lab . '（' . g_sess_period($m) . '）で行った質疑での発言' . number_format((int)$g['s_q'])
          . '回（' . g_sess_rank($g['rank_q'], $g['n_house'], (string)$g['house']) . '）、取り上げた法案' . (int)$g['n_bills'] . '本、提出した質問主意書' . (int)$g['n_shuisho'] . '件を、国会会議録から数えています。';
    $ld = g_jsonld([g_crumbs([['ホーム', '/'], [$g['plain'], '/' . $slug], [$lab, '/' . $path]])]);
    g_head($title, $desc, '/' . $path, ['jsonld' => $ld, 'image' => g_abs($path . '.png') . '?v=' . g_sess_ver($g, $m), 'nosuffix' => true]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url($slug) . '">' . g_e($g['plain']) . '</a> › ' . g_e($lab) . '</nav>'
       . '<p class="kick">' . g_e(g_sess_period($m)) . '</p><h1>' . g_e($g['plain']) . '議員の' . g_e($lab) . '</h1>'
       . '<p class="lead">' . g_e($g['house'] . '・' . $g['party']) . '。この国会で、質疑・取り上げた法案・質問主意書がどれだけあったかを、同じ院の議員の中の順位とあわせて数えています。</p></section>';
    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url($path . '.png') . '?v=' . g_sess_ver($g, $m)) . '" alt="' . g_e($g['plain'] . '議員の' . $lab . 'の記録のカード') . '" width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    g_share_buttons($g['plain'] . '議員の' . $lab, $path, 'sess', (string)($g['x'] ?? '') ?: g_share_x((string)$g['plain']));
    $h = (string)$g['house'];
    echo '<div class="kv">'
       . '<div class="c"><b>' . number_format((int)$g['s_q']) . '</b><span>質疑での発言（' . g_e(g_sess_rank($g['rank_q'], $g['n_house'], $h)) . '）</span></div>'
       . '<div class="c"><b>' . (int)$g['q_days'] . '</b><span>質疑した日（' . (int)$g['q_meetings'] . 'つの会議）</span></div>'
       . '<div class="c"><b>' . (int)$g['n_bills'] . '</b><span>取り上げた法案（' . g_e(g_sess_rank($g['rank_bills'], $g['n_house'], $h)) . '）</span></div>'
       . '<div class="c"><b>' . (int)$g['n_shuisho'] . '</b><span>提出した質問主意書（' . g_e(g_sess_rank($g['rank_shuisho'], $g['n_house'], $h)) . '）</span></div>'
       . ((int)$g['s_gov'] > 0 ? '<div class="c"><b>' . number_format((int)$g['s_gov']) . '</b><span>大臣・副大臣・政務官としての答弁</span></div>' : '')
       . '</div>';
    $bills = g_all('SELECT b.id, b.title, b.status, b.kind, x.n, x.first_date FROM sess_bill_member x JOIN bill b ON b.id=x.bill_id
                    WHERE x.session=? AND x.giin_id=? ORDER BY x.n DESC, x.first_date', [$s, (int)$g['id']]);
    if ($bills) {
        echo '<h2>取り上げた法案</h2><div class="scroll"><table><tr><th>法案</th><th>質疑で触れた回数</th><th>結果</th></tr>';
        $seen = [];
        foreach ($bills as $b) {
            if (isset($seen[$b['title']])) { continue; }
            $seen[$b['title']] = 1;
            echo '<tr><td><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a><br><span class="note">' . g_e($b['kind']) . '・初めて触れた日 ' . g_e(g_date((string)$b['first_date'])) . '</span></td><td>' . (int)$b['n'] . '回</td><td>' . g_e($b['status']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    $tm = json_decode((string)$g['top_meetings'], true) ?: [];
    if ($tm) { echo '<h2>質疑の多かった会議</h2><p>' . g_e(implode('、', $tm)) . '</p>'; }
    if ((int)$g['n_shuisho'] > 0 && function_exists('g_giin_shuisho') && ($sh = g_giin_shuisho($slug))) {
        echo '<p><a class="btn o" href="' . g_e(g_giin_shuisho_url($sh, 'giin-sess-' . $s)) . '">' . g_e($g['plain']) . '議員の質問主意書の本文と答弁書を見る</a></p>';
    }
    // ほかの国会
    $others = g_all('SELECT m.session, s.kind, m.n_q, m.n_bills, m.n_shuisho FROM sess_member m JOIN sess_meta s ON s.session=m.session
                     WHERE m.giin_id=? ORDER BY m.session DESC', [(int)$g['id']]);
    if (count($others) > 1) {
        echo '<h2>ほかの国会</h2><div class="scroll"><table><tr><th>国会</th><th>質疑での発言</th><th>法案</th><th>質問主意書</th></tr>';
        foreach ($others as $o) {
            $cur = (int)$o['session'] === $s;
            echo '<tr><td>' . ($cur ? '<b>' : '<a href="' . g_url($slug . '/' . (int)$o['session']) . '">') . '第' . (int)$o['session'] . '回（' . g_e($o['kind']) . '）' . ($cur ? '</b>' : '</a>')
               . '</td><td>' . number_format((int)$o['n_q']) . '</td><td>' . (int)$o['n_bills'] . '</td><td>' . (int)$o['n_shuisho'] . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '<p class="more"><a class="btn o" href="' . g_url('kokkai/' . $s) . '">' . g_e($lab) . 'の全議員と法案を見る</a> <a class="btn o" href="' . g_url($slug) . '">' . g_e($g['plain']) . '議員のページへ</a></p>';
    echo '<p class="note">' . g_e(G_SESS_NOTE) . '「' . g_e($h) . 'の人数」は、いまの名簿の議員の数です。</p>';
    g_foot();
}

/** カードの版（数字が変われば別の画像） */
function g_sess_ver(array $g, array $m): string
{
    return 's1-' . (int)$g['s_q'] . '-' . (int)$g['n_bills'] . '-' . (int)$g['n_shuisho'] . '-' . (int)$g['rank_q'] . '-' . preg_replace('/[^0-9]/', '', (string)$m['built']);
}

function g_member_session_png(string $slug, int $s): void
{
    $m = g_sess_meta($s);
    $g = $m ? g_sess_member_row($slug, $s) : null;
    if (!$g) { header('Location: ' . g_abs('ogp.png'), true, 302); return; }
    $f = g_card_cache_dir('sess') . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '_' . $s . '_' . g_sess_ver($g, $m) . '.png';
    g_card_serve($f, fn() => g_member_session_draw($g, $m), g_abs('ogp.png'), 0);
}

function g_member_session_draw(array $g, array $m): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $s = (int)$m['session'];
    g_card_header($im, $C, '国会ごとの記録｜' . g_sess_label($m), g_sess_period($m));
    $name = $g['plain'] . ' 議員（' . $g['party'] . '・' . $g['house'] . '）';
    imagettftext($im, g_card_fit($name, $FB, 42, 1088), 0, 56, 142, $C['ink'], $FB, $name);
    $h = (string)$g['house'];
    $box = function (int $x, int $y, string $num, string $unit, string $label, string $rank) use ($im, $C, $FB, $FM, $FR) {
        imagefilledrectangle($im, $x, $y, $x + 528, $y + 150, $C['mint2']);
        imagefilledrectangle($im, $x, $y, $x + 6, $y + 150, $C['teal']);
        imagettftext($im, 17, 0, $x + 26, $y + 36, $C['gray'], $FM, $label);
        imagettftext($im, 50, 0, $x + 24, $y + 104, $C['tealD'], $FB, $num);
        imagettftext($im, 22, 0, $x + 30 + g_card_textw($num, $FB, 50), $y + 104, $C['tealD'], $FM, $unit);
        imagettftext($im, 17, 0, $x + 26, $y + 136, $C['ink'], $FR, $rank);
    };
    $box(56, 178, number_format((int)$g['s_q']), '回', '質疑での発言', g_sess_rank($g['rank_q'], $g['n_house'], $h));
    $box(616, 178, (string)(int)$g['n_bills'], '本', '取り上げた法案', g_sess_rank($g['rank_bills'], $g['n_house'], $h));
    $box(56, 344, (string)(int)$g['n_shuisho'], '件', '提出した質問主意書', g_sess_rank($g['rank_shuisho'], $g['n_house'], $h));
    if ((int)$g['s_gov'] > 0) {
        $box(616, 344, number_format((int)$g['s_gov']), '回', '大臣・副大臣・政務官としての答弁', '質疑とは別に数えています');
    } else {
        $box(616, 344, (string)(int)$g['q_days'], '日', '質疑した日', (int)$g['q_meetings'] . 'つの会議で');
    }
    // いちばん多く触れた法案
    $b = g_one('SELECT b.title, x.n FROM sess_bill_member x JOIN bill b ON b.id=x.bill_id WHERE x.session=? AND x.giin_id=? ORDER BY x.n DESC LIMIT 1', [$s, (int)$g['id']]);
    $line = $b ? 'いちばん多く触れた法案：' . $b['title'] . '（' . (int)$b['n'] . '回）' : '';
    if ($line === '') { $tm = json_decode((string)$g['top_meetings'], true) ?: []; $line = $tm ? '主な会議：' . implode('、', $tm) : ''; }
    if ($line !== '') {
        $ls = g_card_fit($line, $FM, 20, 1088, 14);
        while (g_card_textw($line, $FM, $ls) > 1088 && mb_strlen($line) > 8) { $line = mb_substr($line, 0, mb_strlen($line) - 2) . '…'; }
        imagettftext($im, $ls, 0, 56, 540, $C['ink'], $FM, $line);
    }
    g_card_footer($im, $C, $g['slug'] . '/' . $s, '国会会議録・質問主意書から（数えただけ）');
    return g_card_png($im);
}

/* ---------------- 国会ごと ---------------- */

function g_page_sessions(): void
{
    $rows = g_sess_ready() ? g_all('SELECT * FROM sess_meta ORDER BY session DESC') : [];
    g_head('国会ごとの法案と議員の記録', '2023年以降の国会（常会・臨時会・特別会）ごとに、法案の数と成立、議員ごとの質疑・取り上げた法案・質問主意書の数を並べています。', '/kokkai');
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 国会ごと</nav><h1>国会ごとの法案と議員の記録</h1>'
       . '<p class="lead">国会の回ごとに、法案の数と成立、全国の議員ごとの質疑・取り上げた法案・質問主意書の数を、会議録から数えています。</p></section>';
    echo '<div class="scroll"><table><tr><th>国会</th><th>会期</th><th>法律案（成立）</th><th>質疑した議員</th></tr>';
    foreach ($rows as $m) {
        echo '<tr><td><a href="' . g_url('kokkai/' . (int)$m['session']) . '">' . g_e(g_sess_label($m)) . '</a></td><td>' . g_e(g_sess_period($m)) . '</td><td>'
           . (int)$m['n_law'] . '（' . (int)$m['n_law_passed'] . '）</td><td>' . number_format((int)$m['n_members_q']) . '人</td></tr>';
    }
    echo '</table></div><p class="note">' . g_e(G_SESS_NOTE) . '</p>';
    g_foot();
}

function g_sess_top(int $s, string $house, string $col, int $n): array
{
    $GA = g_gall();
    return g_all("SELECT g.slug, g.plain, g.party, m.$col AS v FROM sess_member m JOIN $GA g ON g.id=m.giin_id
                  WHERE m.session=? AND m.house=? AND m.$col>0 ORDER BY m.$col DESC, g.plain LIMIT $n", [$s, $house]);
}

function g_page_session(int $s): void
{
    $m = g_sess_meta($s);
    if (!$m) { http_response_code(404); g_head('見つかりません', '', '/kokkai', ['noindex' => true]); echo '<h1>見つかりません</h1>'; g_foot(); return; }
    $lab = g_sess_label($m);
    $title = $lab . 'の法案と議員の記録｜法律案' . (int)$m['n_law'] . '本・成立' . (int)$m['n_law_passed'] . '本';
    $desc = $lab . '（' . g_sess_period($m) . '）に出された法律案' . (int)$m['n_law'] . '本（成立' . (int)$m['n_law_passed'] . '本、うち内閣提出' . (int)$m['n_cab'] . '本・成立' . (int)$m['n_cab_passed']
          . '本）と、議員ごとの質疑・取り上げた法案・質問主意書の数の順位を、国会会議録から数えています。';
    $ld = g_jsonld([g_crumbs([['ホーム', '/'], ['国会ごと', '/kokkai'], [$lab, '/kokkai/' . $s]])]);
    g_head($title, $desc, '/kokkai/' . $s, ['jsonld' => $ld, 'image' => g_abs('kokkai/' . $s . '.png') . '?v=' . preg_replace('/[^0-9]/', '', (string)$m['built']), 'nosuffix' => true]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('kokkai') . '">国会ごと</a> › ' . g_e($lab) . '</nav>'
       . '<p class="kick">' . g_e(g_sess_period($m)) . '</p><h1>' . g_e($lab) . 'の法案と議員の記録</h1></section>';
    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url('kokkai/' . $s . '.png')) . '" alt="' . g_e($lab) . 'のカード" width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    g_share_buttons($lab . 'の法案と議員の記録', 'kokkai/' . $s, 'kokkai');
    echo '<div class="kv"><div class="c"><b>' . (int)$m['n_law'] . '</b><span>この国会に出された法律案（成立 ' . (int)$m['n_law_passed'] . '）</span></div>'
       . '<div class="c"><b>' . (int)$m['n_cab'] . '</b><span>うち内閣提出（成立 ' . (int)$m['n_cab_passed'] . '）</span></div>'
       . '<div class="c"><b>' . number_format((int)$m['n_members_q']) . '</b><span>質疑した議員</span></div>'
       . '<div class="c"><b>' . number_format((int)$m['n_q']) . '</b><span>質疑での発言</span></div></div>';
    if (empty($m['close'])) { echo '<p class="note">会期中です。会議録は数日〜数週間遅れて公開されるので、数字はこれから増えます。</p>'; }
    // 法案（質疑で触れた議員の多い順）
    $bills = g_all('SELECT b.id, b.title, b.kind, b.status, COUNT(DISTINCT x.giin_id) AS members, SUM(x.n) AS n FROM bill b
                    LEFT JOIN sess_bill_member x ON x.bill_id=b.id AND x.session=?
                    WHERE b.kind LIKE \'法律案%\' AND b.submit_session<=? AND b.session>=? GROUP BY b.id ORDER BY members DESC, n DESC, b.id LIMIT 40', [$s, $s, $s]);
    echo '<h2>法案（質疑で触れた議員の多い順）</h2><div class="scroll"><table><tr><th>法案</th><th>触れた議員</th><th>結果</th></tr>';
    foreach ($bills as $b) {
        $top = g_all('SELECT g.slug, g.plain, x.n FROM sess_bill_member x JOIN ' . g_gall() . ' g ON g.id=x.giin_id WHERE x.session=? AND x.bill_id=? ORDER BY x.n DESC LIMIT 3', [$s, $b['id']]);
        $tops = implode('、', array_map(fn($t) => '<a href="' . g_url($t['slug'] . '/' . $s) . '">' . g_e($t['plain']) . '</a>' . (int)$t['n'] . '回', $top));
        echo '<tr><td><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a><br><span class="note">' . g_e($b['kind']) . ($tops ? '・多い議員: ' . $tops : '') . '</span></td><td>' . (int)$b['members'] . '人</td><td>' . g_e($b['status']) . '</td></tr>';
    }
    echo '</table></div>';
    foreach ([['n_q', '質疑での発言', '回'], ['n_bills', '取り上げた法案', '本'], ['n_shuisho', '提出した質問主意書', '件']] as [$col, $name, $u]) {
        echo '<h2>' . g_e($name) . 'の多い議員</h2><div class="grid2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(300px,100%),1fr));gap:14px">';
        foreach (['衆議院', '参議院'] as $house) {
            $rows = g_sess_top($s, $house, $col, 15);
            echo '<div><h3 style="margin:6px 0">' . $house . '</h3><table><tr><th>議員</th><th>' . g_e($name) . '</th></tr>';
            $prev = null; $rk = 0;
            foreach ($rows as $i => $r) {
                if ($r['v'] !== $prev) { $rk = $i + 1; $prev = $r['v']; }
                echo '<tr><td>' . $rk . '. <a href="' . g_url($r['slug'] . '/' . $s) . '">' . g_e($r['plain']) . '</a><span class="note">（' . g_e($r['party']) . '）</span></td><td>' . number_format((int)$r['v']) . $u . '</td></tr>';
            }
            if (!$rows) { echo '<tr><td colspan="2">まだありません</td></tr>'; }
            echo '</table></div>';
        }
        echo '</div>';
    }
    echo '<p class="note">' . g_e(G_SESS_NOTE) . '</p><p class="more"><a class="btn o" href="' . g_url('kokkai') . '">ほかの国会を見る</a></p>';
    g_foot();
}

function g_session_png(int $s): void
{
    $m = g_sess_meta($s);
    if (!$m) { header('Location: ' . g_abs('ogp.png'), true, 302); return; }
    $f = g_card_cache_dir('sess') . '/kokkai_' . $s . '_' . preg_replace('/[^0-9]/', '', (string)$m['built']) . '.png';
    g_card_serve($f, fn() => g_session_draw($m), g_abs('ogp.png'), 0);
}

function g_session_draw(array $m): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $s = (int)$m['session'];
    g_card_header($im, $C, '国会ごとの記録', g_sess_period($m));
    imagettftext($im, 44, 0, 56, 146, $C['ink'], $FB, g_sess_label($m));
    $big = function (int $x, string $num, string $unit, string $label) use ($im, $C, $FB, $FM, $FR) {
        imagettftext($im, 54, 0, $x, 246, $C['tealD'], $FB, $num);
        imagettftext($im, 22, 0, $x + 6 + g_card_textw($num, $FB, 54), 246, $C['tealD'], $FM, $unit);
        imagettftext($im, 17, 0, $x + 2, 278, $C['gray'], $FR, $label);
    };
    $big(56, (string)(int)$m['n_law'], '本', '出された法律案（成立 ' . (int)$m['n_law_passed'] . '）');
    $big(420, (string)(int)$m['n_cab'], '本', '内閣提出（成立 ' . (int)$m['n_cab_passed'] . '）');
    $big(760, number_format((int)$m['n_members_q']), '人', '質疑した議員');
    // 質疑での発言の多い議員（両院あわせて上位5人）
    $GA = g_gall();
    $top = g_all("SELECT g.plain, g.party, m.house, m.n_q FROM sess_member m JOIN $GA g ON g.id=m.giin_id WHERE m.session=? AND m.n_q>0 ORDER BY m.n_q DESC LIMIT 5", [$s]);
    imagettftext($im, 18, 0, 56, 330, $C['gray'], $FM, '質疑での発言の多い議員');
    $max = max(1, (int)($top[0]['n_q'] ?? 1));
    foreach ($top as $i => $t) {
        $y = 350 + $i * 40;
        $lab = $t['plain'] . '（' . $t['party'] . '・' . mb_substr((string)$t['house'], 0, 1) . '）';
        imagettftext($im, 18, 0, 56, $y + 25, $C['ink'], $FM, $lab);
        $w = (int)round(560 * (int)$t['n_q'] / $max);
        imagefilledrectangle($im, 440, $y + 6, 440 + $w, $y + 30, $i === 0 ? $C['tealD'] : $C['teal']);
        imagettftext($im, 17, 0, 450 + $w, $y + 26, $C['ink'], $FM, number_format((int)$t['n_q']) . '回');
    }
    if (!$top) { imagettftext($im, 20, 0, 56, 380, $C['gray'], $FR, '会議録が公開されたら数えます'); }
    g_card_footer($im, $C, 'kokkai/' . $s, '国会会議録・衆議院の議案情報から（数えただけ）');
    return g_card_png($im);
}

/** 議員ページに置く「国会ごとの記録」へのリンク（新しい順） */
function g_sess_links(int $gid, string $slug): string
{
    if (!g_sess_ready()) { return ''; }
    $rows = g_all('SELECT m.session, s.kind, m.n_q FROM sess_member m JOIN sess_meta s ON s.session=m.session WHERE m.giin_id=? ORDER BY m.session DESC', [$gid]);
    if (!$rows) { return ''; }
    $a = array_map(fn($r) => '<a class="pill" href="' . g_url($slug . '/' . (int)$r['session']) . '">第' . (int)$r['session'] . '回（' . g_e($r['kind']) . '）</a>', $rows);
    return '<p style="margin:12px 0 0;display:flex;flex-wrap:wrap;gap:6px;align-items:center"><span class="note">国会ごとの記録：</span>' . implode('', $a) . '</p>';
}
