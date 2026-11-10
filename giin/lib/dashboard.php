<?php
/** 議員ごとの「国会活動ダッシュボード」（2026-11-10）。/giin/<議員>/dashboard と、そのカード /giin/<議員>/dashboard.png
 *
 *  形は、デジタル庁「ダッシュボードデザインの実践ガイドブック」に沿った議員ダッシュボード
 *  （大事な数字を左上に・全体から詳細へ・棒は 0 から・色だけに頼らない）にそろえた。
 *  **数えるのは国会会議録にある事実だけ。**「政府が動いた」「制度・予算になった」は会議録から
 *  判定できないので、欄だけ用意して「未記入」で出す（事務所で確かめて書く欄）。勝手に埋めない。
 *  まず試しに出す議員だけ（G_DASH_SLUGS）。検索には出さない（noindex）。 */
declare(strict_types=1);

const G_DASH_SLUGS = ['ito-takae'];
/** 会議の種別と色（帯グラフ・明細の印で同じ並び・同じ色を使う） */
const G_DASH_KINDS = [
    '本会議'     => ['#C25F0A', '◆'],
    '予算委員会' => ['#1A5FA8', '●'],
    '常任委員会' => ['#2E7D46', '■'],
    '特別委員会' => ['#7A4FA0', '▲'],
    '調査会'     => ['#8A6D00', '★'],
    '公聴会'     => ['#595959', '▼'],
];

function g_dash_kind(string $meeting): string
{
    if (str_contains($meeting, '公聴会')) { return '公聴会'; }
    if ($meeting === '本会議') { return '本会議'; }
    if (str_contains($meeting, '予算委員会')) { return '予算委員会'; }
    if (str_contains($meeting, '調査会')) { return '調査会'; }
    if (str_contains($meeting, '特別委員会')) { return '特別委員会'; }
    return '常任委員会';
}

/** 画面とカードの両方で使う集計。無ければ null */
function g_dash_data(string $slug): ?array
{
    if (!in_array($slug, G_DASH_SLUGS, true)) { return null; }
    $GA = function_exists('g_gall') ? g_gall() : 'giin';
    $SP = $GA === 'giin_all' ? 'speech_all' : 'speech';
    $g = g_one("SELECT * FROM $GA WHERE slug=?", [$slug]);
    if (!$g) { return null; }
    $id = (int)$g['id'];
    // 1回＝同じ日の同じ会議。発言はその中の発言の数
    $sit = g_all("SELECT date, meeting, MAX(issue) AS issue, MAX(session) AS session, COUNT(*) AS n, SUM(LENGTH(body)) AS ch,
                         MAX(meeting_url) AS meeting_url, MIN(speech_order) AS o1
                  FROM $SP WHERE giin_id=? GROUP BY date, meeting ORDER BY date DESC, meeting", [$id]);
    if (!$sit) { return null; }
    $kinds = array_fill_keys(array_keys(G_DASH_KINDS), 0);
    $meet = []; $month = []; $sess = []; $nsp = 0; $ch = 0;
    foreach ($sit as &$s) {
        $s['kind'] = g_dash_kind((string)$s['meeting']);
        $kinds[$s['kind']]++;
        $m = (string)$s['meeting'];
        $meet[$m] = $meet[$m] ?? ['n' => 0, 'sp' => 0];
        $meet[$m]['n']++; $meet[$m]['sp'] += (int)$s['n'];
        $ym = substr((string)$s['date'], 0, 7);
        $month[$ym] = ($month[$ym] ?? 0) + 1;
        $k = (int)$s['session'];
        $sess[$k] = $sess[$k] ?? ['n' => 0, 'sp' => 0];
        $sess[$k]['n']++; $sess[$k]['sp'] += (int)$s['n'];
        $nsp += (int)$s['n']; $ch += (int)$s['ch'];
    }
    unset($s);
    uasort($meet, fn($a, $b) => $b['n'] <=> $a['n'] ?: $b['sp'] <=> $a['sp']);
    ksort($sess);
    // 月は最初の月から最後の月まで、0回の月も並べる（間を詰めると推移に見えない）
    $first = (string)end($sit)['date']; $last = (string)$sit[0]['date'];
    $months = [];
    for ($y = (int)substr($first, 0, 4), $mo = (int)substr($first, 5, 2); sprintf('%04d-%02d', $y, $mo) <= substr($last, 0, 7); ) {
        $key = sprintf('%04d-%02d', $y, $mo);
        $months[$key] = $month[$key] ?? 0;
        if (++$mo > 12) { $mo = 1; $y++; }
    }
    // 国会トラッカーの論点（質疑で触れた日数）。表が無い設置では空
    $topics = [];
    try {
        $topics = g_all('SELECT tracker, COUNT(*) AS n, COUNT(DISTINCT date) AS d, MIN(date) AS d1, MAX(date) AS d2
                         FROM tracker_speech WHERE giin_id=? GROUP BY tracker ORDER BY d DESC, n DESC, tracker', [$id]);
    } catch (Throwable $e) { }
    return ['g' => $g, 'sit' => $sit, 'kinds' => $kinds, 'meet' => $meet, 'months' => $months, 'sess' => $sess,
            'nsp' => $nsp, 'ch' => $ch, 'first' => $first, 'last' => $last, 'topics' => $topics,
            'plenary' => $kinds['本会議'], 'updated' => substr(g_meta('updated_at'), 0, 10)];
}

function g_dash_ver(array $D): string
{
    return 'd3-' . count($D['sit']) . '-' . $D['nsp'] . '-' . count($D['topics']) . '-' . preg_replace('/[^0-9]/', '', $D['last']);
}

function g_dash_ym(string $d): string { return (int)substr($d, 0, 4) . '年' . (int)substr($d, 5, 2) . '月'; }

function g_page_dashboard(string $slug): void
{
    $D = g_dash_data($slug);
    if (!$D) {
        http_response_code(404); g_head('見つかりません', '', '/', ['noindex' => true]);
        echo '<h1>見つかりません</h1><p><a href="' . g_url($slug) . '">議員のページへ</a></p>'; g_foot(); return;
    }
    $g = $D['g']; $id = (int)$g['id']; $name = (string)$g['plain'];
    $nsit = count($D['sit']); $path = $slug . '/dashboard';
    $period = g_dash_ym($D['first']) . '〜' . g_dash_ym($D['last']);
    $title = $name . '議員 国会活動ダッシュボード｜質疑' . $nsit . '回・本会議登壇' . $D['plenary'] . '回';
    $desc = $name . '議員（' . $g['house'] . '・' . $g['party'] . '）の国会での質疑を、国会会議録から数えたダッシュボードです。質疑' . $nsit . '回（発言' . number_format($D['nsp'])
          . '回）、本会議登壇' . $D['plenary'] . '回、取り上げた論点' . count($D['topics']) . 'テーマ。会議別・月別の推移と、1回ごとの明細を会議録へのリンク付きで載せています。対象期間 ' . $period . '。';
    $img = g_abs($path . '.png') . '?v=' . g_dash_ver($D);
    $ld = g_jsonld([g_crumbs([['ホーム', '/'], [$name, '/' . $slug], ['国会活動ダッシュボード', '/' . $path]])]);
    g_head($title, $desc, '/' . $path, ['jsonld' => $ld, 'image' => $img, 'nosuffix' => true, 'noindex' => true]);
    echo '<style>' . g_dash_css() . '</style><div class="db">';
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url($slug) . '">' . g_e($name) . '</a> › 国会活動ダッシュボード</nav>';
    echo '<div class="db-head"><div><h1>' . g_e($name) . '議員 国会活動ダッシュボード</h1>'
       . '<p class="db-sub">' . g_e($g['house'] . '・' . $g['district'] . '・' . $g['kaiha']) . '</p></div>'
       . '<dl class="db-meta"><div><dt>対象期間</dt><dd>' . g_e($period) . '</dd></div><div><dt>データ更新日</dt><dd>' . g_e(g_date($D['updated'])) . '</dd></div>'
       . '<div><dt>もとのデータ</dt><dd>国会会議録（国立国会図書館）</dd></div></dl></div>';
    // 共有は1行の帯で出す（g_share_buttons は AI-SNS顧問 の案内が付く。議員本人に見せるページなので付けない）
    echo g_share_bar('【Kurage 国会議員 発言ログ】' . $name . '議員の国会活動ダッシュボード｜質疑' . $nsit . '回・本会議登壇' . $D['plenary'] . '回を会議録から集計', $path, 'dash');

    // ---- 大事な数字（左上から）
    $nmeet = count($D['meet']);
    $topM = (string)array_key_first($D['meet']);
    echo '<div class="db-grid">';
    echo '<section class="db-kpi k1"><p class="l">国会質問等回数（累計）</p><p class="v">' . $nsit . '<small>回</small></p>'
       . '<p class="s">発言 ' . number_format($D['nsp']) . '回・約' . number_format((int)round($D['ch'] / 10000)) . '万字</p></section>';
    echo '<section class="db-kpi"><p class="l">本会議登壇</p><p class="v">' . $D['plenary'] . '<small>回</small></p><p class="s">代表質問・討論など</p></section>';
    echo '<section class="db-kpi"><p class="l">取り上げた論点</p><p class="v">' . count($D['topics']) . '<small>テーマ</small></p>'
       . '<p class="s">国会トラッカー ' . count(g_trackers()) . 'テーマのうち</p></section>';
    echo '<section class="db-kpi"><p class="l">質疑した会議</p><p class="v">' . $nmeet . '<small>種類</small></p><p class="s">最多は' . g_e($topM) . '</p></section>';

    // ---- 種別の内訳（積み上げ帯）
    echo '<section class="db-panel w12"><h2>種別内訳（全' . $nsit . '回）</h2><div class="db-stack" role="img" aria-label="会議の種別ごとの回数">';
    foreach ($D['kinds'] as $k => $n) {
        if ($n < 1) { continue; }
        echo '<span style="flex:' . $n . ' 1 0;background:' . G_DASH_KINDS[$k][0] . '" title="' . g_e($k . ' ' . $n . '回') . '">' . ($n * 100 / $nsit >= 9 ? g_e($k) . ' ' . $n : (string)$n) . '</span>';
    }
    echo '</div><ul class="db-legend">';
    foreach ($D['kinds'] as $k => $n) {
        if ($n < 1) { continue; }
        echo '<li><i style="color:' . G_DASH_KINDS[$k][0] . '">' . G_DASH_KINDS[$k][1] . '</i>' . g_e($k) . ' <b>' . $n . '回</b>（' . round($n * 100 / $nsit) . '%）</li>';
    }
    echo '</ul></section>';

    // ---- 会議別（横棒・0から）
    $max = max(array_map(fn($m) => $m['n'], $D['meet']));
    echo '<section class="db-panel w7"><h2>会議別 質問等回数</h2><div class="db-bars">';
    $i = 0;
    foreach ($D['meet'] as $m => $v) {
        echo '<div class="r"><span class="n">' . g_e($m) . '</span><span class="t"><i class="' . ($i++ === 0 ? 'top' : '') . '" style="width:' . round($v['n'] * 100 / $max, 1) . '%"></i></span>'
           . '<span class="c"><b>' . $v['n'] . '回</b><small>発言' . number_format($v['sp']) . '</small></span></div>';
    }
    echo '</div><p class="db-note">1回＝同じ日の同じ会議。棒は回数、右の小さい数字はその中の発言の数です。</p></section>';

    // ---- 国会（回次）別
    $rk = [];
    try { foreach (g_all('SELECT session, rank_q, n_house FROM sess_member WHERE giin_id=?', [$id]) as $r) { $rk[(int)$r['session']] = $r; } } catch (Throwable $e) { }
    $smax = max(array_map(fn($s) => $s['n'], $D['sess']));
    echo '<section class="db-panel w5"><h2>国会別 質問等回数</h2><div class="db-bars s">';
    foreach ($D['sess'] as $s => $v) {
        $has = isset($rk[$s]) && function_exists('g_page_member_session');
        echo '<div class="r"><span class="n">' . ($has ? '<a href="' . g_url($slug . '/' . $s) . '">第' . $s . '回</a>' : '第' . $s . '回') . '</span>'
           . '<span class="t"><i style="width:' . round($v['n'] * 100 / $smax, 1) . '%"></i></span>'
           . '<span class="c"><b>' . $v['n'] . '回</b><small>発言' . number_format($v['sp']) . '</small></span></div>';
    }
    echo '</div><p class="db-note">回次を押すと、その国会での法案・質問主意書・院内の順位が見られます。</p></section>';

    // ---- 月別の推移（縦棒・0から）
    $mmax = max(1, max($D['months']));
    echo '<section class="db-panel w12"><h2>月別 質問等回数の推移</h2><div class="db-months" role="img" aria-label="月ごとの質疑の回数">';
    foreach ($D['months'] as $ym => $n) {
        $mo = (int)substr($ym, 5, 2);
        echo '<div class="m' . ($mo === 1 ? ' y' : '') . '" title="' . g_e(g_dash_ym($ym . '-01') . ' ' . $n . '回') . '"><span class="b"><em>' . ($n ?: '') . '</em><i style="height:' . round($n * 100 / $mmax, 1) . '%"></i></span>'
           . '<span class="x">' . ($mo === 1 || $ym === array_key_first($D['months']) ? substr($ym, 0, 4) : '') . '</span></div>';
    }
    echo '</div><p class="db-note">いちばん多い月は ' . $mmax . '回。国会が開かれていない月は 0 回です。棒に触れると月と回数が出ます。</p></section>';

    // ---- 取り上げた論点 × 実現ステージ
    $T = [];
    foreach (g_trackers() as $t) { $T[$t['key']] = $t; }
    $rows = array_values(array_filter($D['topics'], fn($t) => isset($T[$t['tracker']])));
    if ($rows) {
        $top = array_slice($rows, 0, 9);
        echo '<section class="db-panel w12"><h2>取り上げた論点（上位' . count($top) . '）× 実現ステージ</h2>'
           . '<p class="db-note">STEP2 は会議録から数えた事実です。STEP1・STEP3・STEP4 は、事務所でご確認のうえ記入する欄です（会議録からは判定していません）。</p>'
           . '<div class="scroll"><table class="db-stage"><tr><th>論点</th><th>STEP1<br>声を受け止める</th><th>STEP2<br>国会で質す</th><th>STEP3<br>政府が動く</th><th>STEP4<br>制度・予算になる</th></tr>';
        foreach ($top as $t) {
            $tt = $T[$t['tracker']];
            echo '<tr><td><a href="' . g_url('tracker/' . $t['tracker']) . '">' . g_e($tt['name'] ?? $tt['short'] ?? $t['tracker']) . '</a></td><td class="e">未記入</td>'
               . '<td class="ok"><b>◎ ' . (int)$t['d'] . '日</b>・発言' . (int)$t['n'] . '回<br><small>' . g_e(g_dash_ym((string)$t['d1'])) . '〜' . g_e(g_dash_ym((string)$t['d2'])) . '</small></td>'
               . '<td class="e">未記入</td><td class="e">未記入</td></tr>';
        }
        echo '</table></div>';
        if (count($rows) > 9) {
            echo '<p class="db-chips"><span>ほかの論点</span>';
            foreach (array_slice($rows, 9) as $t) {
                $tt = $T[$t['tracker']];
                echo '<a href="' . g_url('tracker/' . $t['tracker']) . '">' . g_e($tt['short'] ?? $tt['name']) . ' ' . (int)$t['d'] . '日</a>';
            }
            echo '</p>';
        }
        echo '<p class="db-note">論点は「国会トラッカー」のことばが発言に出てきた日を数えています（2016年以降）。同じ日に複数の論点を取り上げた場合は、それぞれに数えます。</p></section>';
    }

    // ---- 明細
    $SP = (function_exists('g_gall') ? g_gall() : 'giin') === 'giin_all' ? 'speech_all' : 'speech';
    $ex = [];   // 日＋会議 → その日の最初のまとまった発言の出だしと、そのURL
    foreach (g_all("SELECT date, meeting, speech_url, LENGTH(body) AS len, SUBSTR(body, 1, 220) AS head FROM $SP WHERE giin_id=? ORDER BY date, speech_order", [$id]) as $r) {
        $k = $r['date'] . '|' . $r['meeting'];
        if (!isset($ex[$k]) || ($ex[$k]['len'] < 150 && (int)$r['len'] >= 150)) { $ex[$k] = ['len' => (int)$r['len'], 'head' => (string)$r['head'], 'url' => (string)$r['speech_url']]; }
    }
    $tp = [];
    try {
        foreach (g_all('SELECT DISTINCT date, meeting, tracker FROM tracker_speech WHERE giin_id=?', [$id]) as $r) { if (isset($T[$r['tracker']])) { $tp[$r['date'] . '|' . $r['meeting']][] = $r['tracker']; } }
    } catch (Throwable $e) { }
    echo '<section class="db-panel w12"><h2>質問等明細（全' . $nsit . '回）</h2><div class="scroll db-detail"><table><tr><th>日付</th><th>会議</th><th>内容</th><th>発言</th><th>会議録</th></tr>';
    foreach ($D['sit'] as $s) {
        $k = $s['date'] . '|' . $s['meeting'];
        $head = preg_replace('/\s+/u', ' ', preg_replace('/^○[^\s　]+[\s　]+/u', '', $ex[$k]['head'] ?? ''));
        $head = preg_replace('/^(国民民主党・新緑風会の)?' . preg_quote($name, '/') . 'です。\s*/u', '', (string)$head);
        if (mb_strlen($head) > 72) { $head = mb_substr($head, 0, 72) . '…'; }
        $chips = '';
        foreach (array_slice($tp[$k] ?? [], 0, 4) as $tk) { $chips .= '<a class="tp" href="' . g_url('tracker/' . $tk) . '">' . g_e($T[$tk]['short'] ?? $T[$tk]['name']) . '</a>'; }
        [$col, $sym] = G_DASH_KINDS[$s['kind']];
        echo '<tr><td class="d">' . g_e(str_replace('-', '/', (string)$s['date'])) . '</td>'
           . '<td class="mt"><i style="color:' . $col . '">' . $sym . '</i>' . g_e($s['meeting']) . '<small>第' . (int)$s['session'] . '回国会 ' . g_e($s['issue']) . '・' . g_e($s['kind']) . '</small></td>'
           . '<td>' . ($chips !== '' ? '<span class="tps">' . $chips . '</span>' : '') . '<span class="hd">' . g_e($head) . '</span></td>'
           . '<td class="d">' . (int)$s['n'] . '回</td>'
           . '<td class="d"><a href="' . g_e($ex[$k]['url'] ?? $s['meeting_url']) . '" target="_blank" rel="noopener">読む</a></td></tr>';
    }
    echo '</table></div><p class="db-note">「内容」は、その日の最初のまとまった発言の出だしです（要約していません）。色の付いたことばは国会トラッカーの論点です。</p></section>';

    // ---- 所属委員会・このページについて
    $cm = [];
    try { $cm = g_all('SELECT committee, role, url FROM committee_member WHERE giin_id=? ORDER BY CASE role WHEN "委員" THEN 1 ELSE 0 END, committee', [$id]); } catch (Throwable $e) { }
    echo '<section class="db-panel w5"><h2>所属委員会</h2>';
    if ($cm) {
        echo '<ul class="db-list">';
        foreach ($cm as $c) { echo '<li><b>' . g_e($c['committee']) . '</b><span>' . g_e($c['role']) . '</span></li>'; }
        echo '</ul><p class="db-note">' . g_e($g['house']) . 'の公表している委員名簿から。</p>';
    } else { echo '<p class="db-note">委員名簿に載っていません。</p>'; }
    echo '</section>';
    echo '<section class="db-panel w7"><h2>このダッシュボードについて</h2><ul class="db-about">'
       . '<li>数字はすべて、国会会議録の' . g_e($name) . '議員の発言から自動で数えています。質疑の中身の良し悪しは見ていません。</li>'
       . '<li>対象は ' . g_e($period) . '。会議録は公開まで数週間かかるため、直近の質疑はまだ入っていないことがあります。</li>'
       . '<li>並べ方は、デジタル庁「ダッシュボードデザインの実践ガイドブック」に沿っています（大事な数字を左上に・全体から詳細へ・棒は 0 から・色だけに頼らない）。</li>'
       . '<li>株式会社エクスブリッジが作成した試作です。議員事務所の公式ページではありません。</li></ul>'
       . '<p class="db-btns"><a class="btn o" href="' . g_url($slug) . '">' . g_e($name) . '議員の発言ログへ</a></p></section>';
    echo '</div></div>';
    g_foot();
}

function g_dash_css(): string
{
    return <<<'CSS'
.db{--o:#C25F0A;--b:#1A5FA8;--gr:#595959;--ln:#D8D8D8;--bg:#F5F5F2;--ink:#1A1A1A;color:var(--ink);background:var(--bg);margin:-22px -16px -56px;padding:18px 16px 40px;line-height:1.7}
.db .crumb{margin:0 0 10px}
.db-head{background:#fff;border:1px solid var(--ln);border-bottom:4px solid var(--o);border-radius:6px;padding:16px 20px;display:flex;gap:14px 28px;flex-wrap:wrap;align-items:flex-end;justify-content:space-between}
.db-head h1{font-size:25px;margin:0;line-height:1.4}
.db-sub{margin:4px 0 0;font-size:13.5px;color:var(--gr)}
.db-meta{display:flex;gap:6px 22px;flex-wrap:wrap;margin:0;font-size:12.5px}
.db-meta dt{color:var(--gr);font-size:11.5px}.db-meta dd{margin:0;font-weight:700}
.db>p{margin-left:2px}
.db-grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:14px;margin-top:14px}
.db-kpi,.db-panel{background:#fff;border:1px solid var(--ln);border-radius:6px;padding:14px 18px 16px}
.db-kpi{grid-column:span 3}
.db-kpi p{margin:0}
.db-kpi .l{font-size:13px;font-weight:700;color:var(--gr)}
.db-kpi .v{font-size:2.4rem;font-weight:900;color:var(--o);line-height:1.2;font-feature-settings:"tnum"}
.db-kpi .v small{font-size:1rem;margin-left:3px;color:var(--ink)}
.db-kpi .s{font-size:12.5px;color:var(--gr)}
.db-kpi.k1{border:2px solid var(--o)}.db-kpi.k1 .v{font-size:3rem}
.w12{grid-column:span 12}.w7{grid-column:span 7}.w5{grid-column:span 5}
.db h2{font-size:16.5px;font-weight:900;margin:0 0 12px;padding:0 0 0 10px;border-left:5px solid var(--o);line-height:1.4}
.db h2::after,.db h2::before{display:none}
.db-note{font-size:12px;color:var(--gr);margin:10px 0 0;line-height:1.7}
.db-stack{display:flex;height:40px;border-radius:4px;overflow:hidden;gap:2px}
.db-stack span{color:#fff;font-size:12.5px;font-weight:700;display:flex;align-items:center;justify-content:center;white-space:nowrap;overflow:hidden;min-width:14px}
.db-legend{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:4px 18px;font-size:13px}
.db-legend i{font-style:normal;margin-right:4px}
.db-bars .r{display:grid;grid-template-columns:minmax(0,15em) minmax(0,1fr) 7.2em;gap:4px 10px;align-items:center;padding:5px 0;border-bottom:1px solid #EFEFEC}
.db-bars.s .r{grid-template-columns:5em minmax(0,1fr) 7.2em}
.db-bars .r:last-child{border-bottom:0}
.db-bars .n{font-size:13px;font-weight:700;line-height:1.45}
.db-bars .t{display:block;height:18px;border-left:1px solid var(--gr)}
.db-bars .t i{display:block;height:100%;background:var(--b);min-width:2px}
.db-bars .t i.top{background:var(--o)}
.db-bars .c{font-size:13.5px;white-space:nowrap;font-feature-settings:"tnum"}
.db-bars .c small{color:var(--gr);font-size:11.5px;margin-left:6px}
.db-months{display:flex;align-items:flex-end;gap:2px;height:170px;border-bottom:1px solid var(--gr);margin-bottom:20px}
.db-months .m{flex:1 1 0;height:100%;position:relative;display:flex}
.db-months .m.y{border-left:1px dashed var(--ln)}
.db-months .b{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:stretch;padding-top:16px}
.db-months .b em{font-style:normal;font-size:10.5px;text-align:center;line-height:1.3;color:var(--ink);font-weight:700}
.db-months .b i{display:block;background:var(--b);border-radius:1px 1px 0 0}
.db-months .x{position:absolute;left:2px;bottom:-20px;font-size:11.5px;color:var(--gr);white-space:nowrap}
.db table{border-radius:4px;border-color:var(--ln);font-size:13px}
.db th{background:#F0F0EC;color:var(--ink);font-size:12px;line-height:1.5}
.db th,.db td{border-bottom-color:#E6E6E2;padding:8px 10px}
.db-stage{min-width:640px}
.db-stage th:not(:first-child),.db-stage td:not(:first-child){text-align:center;width:17%}
.db-stage td.e{color:#8a8a8a;font-size:12px;background:repeating-linear-gradient(135deg,#fafaf8,#fafaf8 6px,#f1f1ee 6px,#f1f1ee 12px)}
.db-stage td.ok{background:#EEF4FB;line-height:1.5}.db-stage td.ok b{color:var(--b);font-size:14px}.db-stage td.ok small{color:var(--gr)}
.db-chips{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 0;font-size:12px;align-items:center}
.db-chips span{font-weight:700;color:var(--gr)}
.db-chips a,.db .tp{display:inline-block;border:1px solid #B9CDE4;background:#EEF4FB;color:#12457c;border-radius:3px;padding:1px 7px;text-decoration:none;font-size:12px;line-height:1.6}
.db-detail{max-height:640px;overflow-y:auto;border:1px solid var(--ln);border-radius:4px}
.db-detail table{border:0;min-width:720px}
.db-detail th{position:sticky;top:0;z-index:1}
.db-detail td.d{white-space:nowrap;font-feature-settings:"tnum"}
.db-detail td.mt{min-width:11em;font-weight:700}.db-detail td.mt i{font-style:normal;margin-right:4px}
.db-detail td.mt small{display:block;font-weight:400;color:var(--gr);font-size:11.5px}
.db-detail .tps{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:3px}
.db-detail .hd{color:#333;font-size:12.5px}
.db-list{list-style:none;margin:0;padding:0}
.db-list li{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #EFEFEC;font-size:14px}
.db-list span{color:var(--o);font-weight:700;white-space:nowrap}
.db-about{margin:0;padding-left:1.2em;font-size:13px}.db-about li{margin:0 0 5px}
.db-btns{margin:12px 0 0}
@media(max-width:860px){.db-kpi{grid-column:span 6}.w7,.w5{grid-column:span 12}}
@media(max-width:640px){.db{padding:12px 12px 32px}.db-head{padding:12px 14px}.db-head h1{font-size:20px}.db-grid{gap:10px;margin-top:10px}.db-kpi,.db-panel{padding:12px 12px 14px}
.db-kpi .v{font-size:1.9rem}.db-kpi.k1 .v{font-size:2.2rem}.db-kpi .v small{font-size:.8rem}
.db-bars .r,.db-bars.s .r{grid-template-columns:minmax(0,1fr) 7em}.db-bars .n{grid-column:1/-1}
.db-months{gap:1px;height:140px}.db-months .b em{display:none}.db-stack span{font-size:11px}}
CSS;
}

/* ---------------- カード（画面と同じ並び：数字4つ → 会議別の横棒 → 国会別の縦棒） ---------------- */

function g_dashboard_png(string $slug): void
{
    $D = g_dash_data($slug);
    if (!$D) { header('Location: ' . g_abs('ogp.png'), true, 302); return; }
    $f = g_card_cache_dir('dash') . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '_' . g_dash_ver($D) . '.png';
    g_card_serve($f, fn() => g_dashboard_draw($D), g_abs('ogp.png'), 0);
}

function g_dashboard_draw(array $D): string
{
    [$FB, $FM, $FR] = g_card_fonts();
    $W = 1200; $H = 630;
    $im = imagecreatetruecolor($W, $H);
    imageantialias($im, true);
    $c = fn(string $hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $C = ['white' => $c('#ffffff'), 'bg' => $c('#F5F5F2'), 'ink' => $c('#1A1A1A'), 'gray' => $c('#595959'), 'line' => $c('#D8D8D8'), 'soft' => $c('#EFEFEC'),
          'o' => $c('#C25F0A'), 'b' => $c('#1A5FA8'), 'tealD' => $c('#0a726b')];
    imagefilledrectangle($im, 0, 0, $W, $H, $C['bg']);
    $g = $D['g']; $nsit = count($D['sit']);
    // 上の帯（白＋オレンジの下線）
    imagefilledrectangle($im, 0, 0, $W, 92, $C['white']);
    imagefilledrectangle($im, 0, 92, $W, 97, $C['o']);
    $x = g_card_mascot($im, 30, 10, 72) + 12;
    $t = $g['plain'] . '議員 国会活動ダッシュボード';
    imagettftext($im, g_card_fit($t, $FB, 34, 690), 0, $x, 62, $C['ink'], $FB, $t);
    $r1 = '対象期間 ' . g_dash_ym($D['first']) . '〜' . g_dash_ym($D['last']);
    $r2 = $g['house'] . '・' . $g['party'];
    imagettftext($im, 16, 0, $W - 36 - g_card_textw($r1, $FR, 16), 44, $C['gray'], $FR, $r1);
    imagettftext($im, 16, 0, $W - 36 - g_card_textw($r2, $FM, 16), 72, $C['gray'], $FM, $r2);
    $panel = function (int $x, int $y, int $w, int $h, bool $strong = false) use ($im, $C) {
        imagefilledrectangle($im, $x, $y, $x + $w, $y + $h, $strong ? $C['o'] : $C['line']);
        $b = $strong ? 3 : 1;
        imagefilledrectangle($im, $x + $b, $y + $b, $x + $w - $b, $y + $h - $b, $C['white']);
    };
    // 数字4つ
    $kp = [['国会質問等回数（累計）', (string)$nsit, '回', '発言 ' . number_format($D['nsp']) . '回'],
           ['本会議登壇', (string)$D['plenary'], '回', '代表質問・討論など'],
           ['取り上げた論点', (string)count($D['topics']), 'テーマ', '国会トラッカーから'],
           ['質疑した会議', (string)count($D['meet']), '種類', '委員会・調査会など']];
    $kx = 30; $kw = 276; $ky = 116; $kh = 150;
    foreach ($kp as $i => [$lab, $num, $unit, $sub]) {
        $px = $kx + $i * ($kw + 12);
        $panel($px, $ky, $kw, $kh, $i === 0);
        imagettftext($im, 16, 0, $px + 18, $ky + 34, $C['gray'], $FM, $lab);
        $ns = $i === 0 ? 60 : 52;
        imagettftext($im, $ns, 0, $px + 16, $ky + 106, $C['o'], $FB, $num);
        imagettftext($im, 20, 0, $px + 24 + g_card_textw($num, $FB, $ns), $ky + 106, $C['ink'], $FM, $unit);
        imagettftext($im, 14, 0, $px + 18, $ky + 136, $C['gray'], $FR, $sub);
    }
    $head = function (int $x, int $y, string $s) use ($im, $C, $FB) {
        imagefilledrectangle($im, $x, $y - 20, $x + 5, $y + 4, $C['o']);
        imagettftext($im, 18, 0, $x + 16, $y, $C['ink'], $FB, $s);
    };
    // 会議別の横棒（上位5）
    $py = 282; $ph = 280;
    $panel(30, $py, 660, $ph);
    $head(48, $py + 38, '会議別 質問等回数');
    $rows = array_slice($D['meet'], 0, 5, true);
    $max = max(array_map(fn($m) => $m['n'], $rows));
    $ry = $py + 62; $i = 0; $lx = 48; $lw = 286; $bx = $lx + $lw + 12; $bw = 250;
    imagefilledrectangle($im, $bx - 1, $ry - 2, $bx, $ry + 5 * 42 - 6, $C['gray']);
    foreach ($rows as $m => $v) {
        $y = $ry + $i * 42;
        $ms = (string)$m;
        $fs = g_card_fit($ms, $FM, 16, $lw, 12.5);
        while (g_card_textw($ms, $FM, $fs) > $lw && mb_strlen($ms) > 6) { $ms = mb_substr($ms, 0, mb_strlen($ms) - 2) . '…'; }
        imagettftext($im, $fs, 0, $lx, $y + 24, $C['ink'], $FM, $ms);
        $len = max(3, (int)round($bw * $v['n'] / $max));
        imagefilledrectangle($im, $bx + 1, $y + 4, $bx + $len, $y + 30, $i === 0 ? $C['o'] : $C['b']);
        imagettftext($im, 17, 0, $bx + $len + 10, $y + 25, $C['ink'], $FB, $v['n'] . '回');
        $i++;
    }
    // 国会別の縦棒
    $panel(702, $py, 468, $ph);
    $head(720, $py + 38, '国会（回次）別 質問等回数');
    $ss = array_slice($D['sess'], -8, null, true);
    $n = count($ss); $smax = max(array_map(fn($s) => $s['n'], $ss));
    $gx = 724; $gw = 424; $base = $py + $ph - 46; $top = $py + 86;
    $gap = 12; $cw = (int)floor(($gw - $gap * ($n - 1)) / $n);
    imagefilledrectangle($im, $gx, $base, $gx + $gw, $base + 1, $C['gray']);
    $i = 0;
    foreach ($ss as $s => $v) {
        $cx = $gx + $i * ($cw + $gap);
        $bh = max(2, (int)round(($base - $top) * $v['n'] / $smax));
        imagefilledrectangle($im, $cx, $base - $bh, $cx + $cw, $base - 1, $v['n'] === $smax ? $C['o'] : $C['b']);
        g_card_center($im, (string)$v['n'], $FB, 16, $cx + (int)($cw / 2), $base - $bh - 8, $C['ink']);
        g_card_center($im, $s . '回', $FR, 14, $cx + (int)($cw / 2), $base + 24, $C['gray']);
        $i++;
    }
    // 下の帯
    $bx = g_card_brand($im, $C, 30, $H - 10, 'Kurage 国会議員 発言ログ');
    $url = preg_replace('#^https?://#', '', g_abs($g['slug'] . '/dashboard'));
    imagettftext($im, 15, 0, $bx + 16, $H - 22, $C['gray'], $FR, $url);
    $note = '国会会議録から集計（数えただけ）';
    imagettftext($im, 15, 0, $W - 30 - g_card_textw($note, $FR, 15), $H - 22, $C['gray'], $FR, $note);
    return g_card_png($im);
}
