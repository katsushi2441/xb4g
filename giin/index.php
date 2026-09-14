<?php
/**
 * 愛知の国会議員 発言ログ — https://xb4g.com/giin/
 *
 * **この道具がしないこと**：要約・論評・賛否の判定・議員の序列づけ。
 * 出すのは「いつ・どの会議で・何と言ったかの機械的な抜粋」と「会議録へのリンク」だけ。
 * ここを崩すと、こちらの誤りが議員の立場を毀損する経路になる。
 *
 * **収録の線引き**：愛知の有権者だけが投票用紙に書ける候補。
 *   衆議院 愛知1〜16区 ／ 衆議院 比例東海ブロック ／ 参議院 愛知県選挙区 ＝ 45人。
 * 党派で選ばない。恣意的な取捨選択が無いので「なぜこの人が入っているのか」に一言で答えられる。
 */
declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/themes.php';
require __DIR__ . '/lib/ui.php';

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
if (strpos($path, G_BASE) === 0) { $path = substr($path, strlen(G_BASE)); }
$path = '/' . trim($path, '/');
$page = max(1, (int)($_GET['p'] ?? 1));

try {
    g_route($path, $page);
} catch (Throwable $e) {
    http_response_code(500);
    g_head('準備中', '', $path, ['noindex' => true]);
    echo '<h1>準備中です</h1><div class="panel">' . g_e($e->getMessage()) . '</div>';
    g_foot();
}

function g_route(string $path, int $page): void
{
    if (preg_match('#^/g/(\d+)$#', $path, $m))      { g_page_giin((int)$m[1], $page); return; }
    if (preg_match('#^/t/([a-z0-9-]+)$#', $path, $m)){ g_page_theme($m[1], $page); return; }
    if (preg_match('#^/mt/(\d+)$#', $path, $m))      { g_page_meeting((int)$m[1], $page); return; }
    switch ($path) {
        case '/':        g_page_top(); return;
        case '/search':  g_page_search($page); return;
        case '/giin':    g_page_list(); return;
        case '/themes':  g_page_themes(); return;
        case '/compare': g_page_compare(); return;
        case '/about':   g_page_about(); return;
    }
    http_response_code(404);
    g_head('ページが見つかりません', '', $path, ['noindex' => true]);
    echo '<h1>ページが見つかりません</h1><p><a href="' . g_url('') . '">トップへ</a></p>';
    g_foot();
}

// ---------------------------------------------------------------- 部品

function g_pager(int $page, int $total, int $per, string $base): void
{
    $last = (int)ceil($total / $per);
    if ($last <= 1) { return; }
    $sep = strpos($base, '?') === false ? '?' : '&';
    echo '<div class="pager">';
    if ($page > 1) { echo '<a href="' . g_e($base . $sep . 'p=' . ($page - 1)) . '">前へ</a>'; }
    $from = max(1, $page - 2); $to = min($last, $page + 2);
    if ($from > 1) { echo '<a href="' . g_e($base . $sep . 'p=1') . '">1</a><span>…</span>'; }
    for ($i = $from; $i <= $to; $i++) {
        echo $i === $page ? '<span class="now">' . $i . '</span>'
                          : '<a href="' . g_e($base . $sep . 'p=' . $i) . '">' . $i . '</a>';
    }
    if ($to < $last) { echo '<span>…</span><a href="' . g_e($base . $sep . 'p=' . $last) . '">' . $last . '</a>'; }
    if ($page < $last) { echo '<a href="' . g_e($base . $sep . 'p=' . ($page + 1)) . '">次へ</a>'; }
    echo '</div>';
}

/** 発言1件のカード。**抜粋と一次情報リンクだけ。** */
function g_speech(array $s, string $kw = '', bool $show_name = true): void
{
    $txt = $kw !== '' ? g_kwic($s['body'], $kw) : g_excerpt($s['body']);
    echo '<div class="sp"><div class="m">'
       . '<b>' . g_e(g_date($s['date'])) . '</b>'
       . '<span class="pill gray">' . g_e($s['house']) . ' ' . g_e($s['meeting']) . '</span>'
       . ($s['issue'] ? '<span class="note">' . g_e($s['issue']) . '</span>' : '')
       . ($show_name && !empty($s['display'])
          ? '<a href="' . g_url('g/' . $s['giin_id']) . '">' . g_e($s['display']) . '</a>'
            . '<span class="pill">' . g_e($s['party']) . '</span>' : '')
       . (!empty($s['kind']) && $s['kind'] !== 'q'
          ? '<span class="pill gray">' . g_e(g_kind_label($s['kind'])) . '</span>' : '')
       . ($s['position'] ? '<span class="note">' . g_e($s['position']) . '</span>' : '')
       . '</div>'
       . '<p class="t">' . g_mark(g_e($txt), $kw) . '</p>'
       . '<div class="lk"><a href="' . g_e($s['speech_url']) . '" rel="nofollow noopener" target="_blank">'
       . 'この発言を会議録で読む</a>'
       . ($s['meeting_url'] ? '　<a href="' . g_e($s['meeting_url']) . '" rel="nofollow noopener" target="_blank">'
          . 'この回の会議録</a>' : '')
       . '</div></div>';
}

// ---------------------------------------------------------------- トップ

function g_page_top(): void
{
    $n  = (int)g_val("SELECT COUNT(*) FROM speech WHERE kind='q'");
    $ng = (int)g_val('SELECT COUNT(*) FROM giin');
    g_head('', '愛知の有権者が選んだ国会議員45人（衆議院 愛知1〜16区・比例東海・参議院 愛知県選挙区）が、'
        . '国会で何を質問したかを ' . number_format($n) . '件の質疑から引けます。要約はしません。', '/');
    echo '<h1>愛知の国会議員が、国会で何を話したか</h1>'
       . '<p class="lead">衆議院 愛知1〜16区・比例東海ブロック・参議院 愛知県選挙区の'
       . '<b>' . $ng . '人</b>について、'
       . '<b>' . number_format($n) . '件</b>の質疑を、日付と会議名と一次情報リンクつきで並べています。'
       . '<br>党派では選んでいません。要約も論評もしません。</p>'
       . '<div class="panel note"><b>件数の読み方</b>：数えているのは'
       . '<b>議員として質問・討論した発言</b>だけです。委員長としての議事整理'
       . '（「次に、○○君。」）や、大臣としての答弁は別に数えています。'
       . '混ぜると、委員長を務めた議員の件数が跳ね上がって「よく質問している人」に'
       . '見えてしまうためです。各ページで切り替えられます。</div>';

    echo '<h2>ことがらから探す</h2><div class="grid">';
    foreach (g_themes() as $t) {
        $args = [];
        $w = g_words_where($t['words'], $args);
        $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind='q' AND $w", $args);
        echo '<a class="card" href="' . g_url('t/' . $t['slug']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="n">' . number_format($c) . '件</div></a>';
    }
    echo '</div>';

    echo '<h2>最近の発言</h2>';
    foreach (g_all('SELECT s.*,g.display,g.party FROM speech s JOIN giin g ON g.id=s.giin_id'
                 . " WHERE s.kind='q' ORDER BY s.date DESC, s.speech_order DESC LIMIT 10") as $s) { g_speech($s); }
    echo '<p><a href="' . g_url('search') . '">もっと探す</a></p>';

    echo '<h2>議員から探す</h2>';
    g_giin_grid();
    echo '<p class="note">' . g_e(g_meta('range_from')) . ' 以降の発言を収録しています。'
       . '発言が0件の議員は、この期間に会議録へ発言が載っていない方です。</p>';
    g_foot();
}

function g_giin_grid(): void
{
    $rows = g_all('SELECT * FROM giin ORDER BY party, n_speech DESC');
    $by = [];
    foreach ($rows as $r) { $by[$r['party']][] = $r; }
    uasort($by, fn($a, $b) => count($b) <=> count($a));
    foreach ($by as $party => $list) {
        echo '<h3 style="font-size:15px;margin:18px 0 8px">' . g_e($party)
           . ' <span class="note">' . count($list) . '人</span></h3><div class="grid">';
        foreach ($list as $g) { echo g_card($g); }
        echo '</div>';
    }
}

// ---------------------------------------------------------------- 議員ページ

function g_page_giin(int $id, int $page): void
{
    $g = g_one('SELECT * FROM giin WHERE id=?', [$id]);
    if (!$g) { http_response_code(404); g_head('見つかりません', '', '/g/' . $id, ['noindex' => true]);
        echo '<h1>その議員は収録していません</h1>'; g_foot(); return; }

    $ku = g_ku($g['district']);
    $kind = g_kind();
    $counts = ['q' => (int)$g['n_q'], 'gov' => (int)$g['n_gov'], 'chair' => (int)$g['n_chair']];
    $desc = $g['display'] . '（' . $g['house'] . ' ' . $ku . '・' . $g['party'] . '）が国会で行った質疑'
          . number_format((int)$g['n_q']) . '件を、日付と会議名と会議録リンクつきで並べています。';
    g_head($g['display'] . ' の国会発言', $desc, '/g/' . $id);

    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › '
       . '<a href="' . g_url('giin') . '">議員一覧</a> › ' . g_e($g['display']) . '</nav>';
    echo '<h1>' . g_e($g['display']) . '</h1>'
       . '<p class="lead">' . g_e($g['house']) . '　' . g_e($ku)
       . '　<span class="pill">' . g_e($g['party']) . '</span>'
       . '　<span class="note">会派: ' . g_e($g['kaiha']) . '</span>'
       . ($g['wins'] ? '　<span class="note">当選' . g_e($g['wins']) . '回</span>' : '')
       . '</p>';

    echo '<div class="panel">'
       . '<table><tr><th>議員として質問・討論した発言</th><td class="n"><b>'
       . number_format($counts['q']) . '</b>件</td></tr>'
       . '<tr><th>大臣・副大臣・政務官としての答弁</th><td class="n">'
       . number_format($counts['gov']) . '件</td></tr>'
       . '<tr><th>委員長・議長としての議事整理</th><td class="n">'
       . number_format($counts['chair']) . '件</td></tr></table>'
       . '<p class="note">「議事整理」は「次に、○○君。」のような進行の発言です。'
       . 'これを質疑と混ぜて数えると、委員長を務めた議員ほど件数が多く見えてしまうので分けています。</p>'
       . '</div>';

    if ((int)$g['n_speech'] === 0) {
        echo '<div class="panel"><p>この期間（' . g_e(g_meta('range_from')) . ' 以降）に、'
           . '国会会議録へ発言が載っていません。</p>'
           . '<p class="note">発言していないことを示すものではありません。'
           . '会議録の公開が追いついていない場合もあります。</p></div>';
        if ($g['profile']) {
            echo '<p><a href="' . g_e($g['profile']) . '" rel="nofollow noopener" target="_blank">'
               . '議院の公式プロフィール</a></p>';
        }
        g_foot(); return;
    }

    // 出た会議の内訳（いま見ている立場だけで数える）
    $mt = g_all('SELECT meeting, COUNT(*) c FROM speech WHERE giin_id=? AND kind=?'
              . ' GROUP BY meeting ORDER BY c DESC LIMIT 12', [$id, $kind]);
    $max = $mt ? (int)$mt[0]['c'] : 1;
    echo '<h2>よく出ている会議</h2>';
    g_kind_tabs($kind, $counts, g_url('g/' . $id));
    if (!$mt) { echo '<div class="panel">この立場の発言はありません。</div>'; }
    else { echo '<div class="scroll"><table><tr><th>会議</th><th class="n">件数</th><th></th></tr>';
    foreach ($mt as $r) {
        echo '<tr><td>' . g_e($r['meeting']) . '</td><td class="n">' . number_format((int)$r['c']) . '</td>'
           . '<td><div class="bar"><i style="width:' . round((int)$r['c'] / $max * 100) . '%"></i></div></td></tr>';
    }
    echo '</table></div>'; }

    // よく触れていることがら（質疑のなかだけで数える）
    $th = [];
    foreach (g_themes() as $t) {
        $args = [$id, $kind];
        $w = g_words_where($t['words'], $args);
        $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.giin_id=? AND s.kind=? AND $w", $args);
        if ($c > 0) { $th[] = [$t, $c]; }
    }
    usort($th, fn($a, $b) => $b[1] <=> $a[1]);
    if ($th) {
        echo '<h2>よく触れていることがら</h2><div class="grid">';
        foreach (array_slice($th, 0, 8) as [$t, $c]) {
            echo '<a class="card" href="' . g_url('t/' . $t['slug']) . '?g=' . $id . '&k=' . $kind . '">'
               . '<div class="nm">' . g_e($t['name']) . '</div>'
               . '<div class="n">' . number_format($c) . '件</div></a>';
        }
        echo '</div><p class="note">語がその発言に出てきた回数です。賛成・反対の判定はしていません。</p>';
    }

    // 発言一覧
    $per = 20; $off = ($page - 1) * $per;
    $total = $counts[$kind];
    echo '<h2>' . g_e(g_kind_label($kind)) . '（' . number_format($total) . '件）</h2>';
    foreach (g_all('SELECT * FROM speech WHERE giin_id=? AND kind=? ORDER BY date DESC, speech_order DESC'
                 . ' LIMIT ? OFFSET ?', [$id, $kind, $per, $off]) as $s) { g_speech($s, '', false); }
    g_pager($page, $total, $per, g_url('g/' . $id) . '?k=' . $kind);

    if ($g['profile']) {
        echo '<p style="margin-top:20px"><a href="' . g_e($g['profile']) . '" rel="nofollow noopener" '
           . 'target="_blank">議院の公式プロフィール</a></p>';
    }
    g_foot();
}

// ---------------------------------------------------------------- テーマページ

function g_page_theme(string $slug, int $page): void
{
    $t = g_theme($slug);
    if (!$t) { http_response_code(404); g_head('見つかりません', '', '/t/' . $slug, ['noindex' => true]);
        echo '<h1>そのことがらは登録されていません</h1>'; g_foot(); return; }

    $only = (int)($_GET['g'] ?? 0);
    $kind = g_kind();
    $args = [];
    $w = g_words_where($t['words'], $args);
    $extra = ' AND s.kind=?';
    $kArgs = $args; $kArgs[] = $kind;
    if ($only) { $extra .= ' AND s.giin_id=?'; $kArgs[] = $only; }
    $total = (int)g_val("SELECT COUNT(*) FROM speech s WHERE $w$extra", $kArgs);

    // 立場ごとの件数（タブ用）
    $counts = [];
    foreach (array_keys(G_KINDS) as $k) {
        $a = $args; $a[] = $k; $e = ' AND s.kind=?';
        if ($only) { $e .= ' AND s.giin_id=?'; $a[] = $only; }
        $counts[$k] = (int)g_val("SELECT COUNT(*) FROM speech s WHERE $w$e", $a);
    }

    $who = $only ? g_one('SELECT * FROM giin WHERE id=?', [$only]) : null;
    $title = $t['name'] . 'について、愛知の国会議員は何と言ったか' . ($who ? '（' . $who['display'] . '）' : '');
    g_head($title, $t['lead'] . '愛知の有権者が選んだ国会議員45人の発言から、'
        . $t['name'] . 'に触れた' . number_format($total) . '件を集めました。', '/t/' . $slug);

    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › '
       . '<a href="' . g_url('themes') . '">ことがら一覧</a> › ' . g_e($t['name']) . '</nav>';
    echo '<h1>' . g_e($t['name']) . '</h1><p class="lead">' . g_e($t['lead'])
       . '　該当 <b>' . number_format($total) . '件</b>'
       . ($who ? '（' . g_e($who['display']) . 'のみ　<a href="' . g_url('t/' . $slug) . '">全員に戻す</a>）' : '')
       . '</p>'
       . '<p class="note">拾っている語：' . g_e(implode('、', $t['words']))
       . '。語が出てきた発言を機械的に集めたもので、賛成・反対の判定はしていません。</p>';
    g_kind_tabs($kind, $counts, g_url('t/' . $slug) . ($only ? '?g=' . $only : ''));

    // 議員別の件数
    if (!$only) {
        $gArgs = $args; $gArgs[] = $kind;
        $rows = g_all("SELECT g.id,g.display,g.party,g.district,g.house,COUNT(*) c
                       FROM speech s JOIN giin g ON g.id=s.giin_id WHERE $w AND s.kind=?
                       GROUP BY g.id ORDER BY c DESC", $gArgs);
        if ($rows) {
            $max = (int)$rows[0]['c'];
            echo '<h2>だれが何件ふれたか</h2><div class="scroll"><table>'
               . '<tr><th>議員</th><th>選挙区</th><th>会派</th><th class="n">件数</th><th></th></tr>';
            foreach ($rows as $r) {
                echo '<tr><td><a href="' . g_url('t/' . $slug) . '?g=' . $r['id'] . '&k=' . $kind . '">'
                   . g_e($r['display']) . '</a></td>'
                   . '<td>' . g_e(g_ku($r['district'])) . '</td>'
                   . '<td><span class="pill">' . g_e($r['party']) . '</span></td>'
                   . '<td class="n">' . number_format((int)$r['c']) . '</td>'
                   . '<td><div class="bar"><i style="width:' . round((int)$r['c'] / $max * 100) . '%"></i></div></td></tr>';
            }
            echo '</table></div><p class="note">件数は発言の回数であって、'
               . '熱心さや正しさを表すものではありません。長い質疑は発言が細かく分かれます。</p>';
        }
    }

    $per = 20; $off = ($page - 1) * $per;
    $lArgs = $kArgs; $lArgs[] = $per; $lArgs[] = $off;
    echo '<h2>' . g_e(g_kind_label($kind)) . '</h2>';
    foreach (g_all("SELECT s.*,g.display,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
                    WHERE $w$extra ORDER BY s.date DESC LIMIT ? OFFSET ?", $lArgs) as $s) {
        g_speech($s, $t['words'][0]);
    }
    g_pager($page, $total, $per,
            g_url('t/' . $slug) . '?k=' . $kind . ($only ? '&g=' . $only : ''));
    g_foot();
}

// ---------------------------------------------------------------- 検索・一覧・くらべる

function g_page_search(int $page): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    $party = trim((string)($_GET['party'] ?? ''));
    $per = 20; $off = ($page - 1) * $per;

    $kind = g_kind();
    $where = ['s.kind = ?']; $args = [$kind];
    if ($q !== '')     { $where[] = 's.body LIKE ?'; $args[] = '%' . $q . '%'; }
    if ($party !== '') { $where[] = 'g.party = ?';   $args[] = $party; }
    $w = implode(' AND ', $where);

    $total = $q === '' && $party === '' ? 0
        : (int)g_val("SELECT COUNT(*) FROM speech s JOIN giin g ON g.id=s.giin_id WHERE $w", $args);

    g_head($q !== '' ? '「' . $q . '」の発言' : '発言をさがす',
        $q !== '' ? '愛知の国会議員45人の発言から「' . $q . '」を含むものを ' . number_format($total) . '件見つけました。'
                  : '愛知の国会議員45人の国会発言を、ことばで検索できます。', '/search',
        ['q' => $q, 'noindex' => $q === '']);

    echo '<h1>' . ($q !== '' ? '「' . g_e($q) . '」をふくむ発言' : '発言をさがす') . '</h1>';

    // 会派でしぼる
    echo '<p class="lead">';
    $ps = g_all('SELECT party, COUNT(*) c FROM giin GROUP BY party ORDER BY c DESC');
    $base = g_url('search') . '?q=' . rawurlencode($q) . '&k=' . $kind;
    echo '<a class="pill' . ($party === '' ? '' : ' gray') . '" href="' . g_e($base) . '">すべて</a> ';
    foreach ($ps as $p) {
        echo '<a class="pill' . ($party === $p['party'] ? '' : ' gray') . '" href="'
           . g_e($base . '&party=' . rawurlencode($p['party'])) . '">' . g_e($p['party']) . '</a> ';
    }
    echo '</p>';

    if ($q === '' && $party === '') {
        echo '<div class="panel"><p>上の窓にことばを入れてください。'
           . '例：<a href="' . g_url('search') . '?q=' . rawurlencode('南海トラフ') . '">南海トラフ</a>、'
           . '<a href="' . g_url('search') . '?q=' . rawurlencode('年収の壁') . '">年収の壁</a>、'
           . '<a href="' . g_url('search') . '?q=' . rawurlencode('リニア') . '">リニア</a></p>'
           . '<p class="note">よく調べられることがらは<a href="' . g_url('themes') . '">ことがら一覧</a>'
           . 'にまとめてあります。</p></div>';
        g_foot(); return;
    }

    $counts = [];
    foreach (array_keys(G_KINDS) as $k) {
        $a = [$k]; $ws = ['s.kind = ?'];
        if ($q !== '')     { $ws[] = 's.body LIKE ?'; $a[] = '%' . $q . '%'; }
        if ($party !== '') { $ws[] = 'g.party = ?';   $a[] = $party; }
        $counts[$k] = (int)g_val('SELECT COUNT(*) FROM speech s JOIN giin g ON g.id=s.giin_id WHERE '
                                 . implode(' AND ', $ws), $a);
    }
    g_kind_tabs($kind, $counts, g_url('search') . '?q=' . rawurlencode($q)
                . ($party !== '' ? '&party=' . rawurlencode($party) : ''));
    echo '<p class="lead">該当 <b>' . number_format($total) . '件</b></p>';
    $lArgs = $args; $lArgs[] = $per; $lArgs[] = $off;
    foreach (g_all("SELECT s.*,g.display,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
                    WHERE $w ORDER BY s.date DESC LIMIT ? OFFSET ?", $lArgs) as $s) {
        g_speech($s, $q);
    }
    if ($total === 0) {
        echo '<div class="panel"><p>見つかりませんでした。ことばを短くすると見つかることがあります。</p></div>';
    }
    g_pager($page, $total, $per, $base . ($party !== '' ? '&party=' . rawurlencode($party) : ''));
    g_foot();
}

function g_page_list(): void
{
    $n = (int)g_val('SELECT COUNT(*) FROM giin');
    g_head('議員一覧', '愛知の有権者が選んだ国会議員' . $n . '人（衆議院 愛知1〜16区・比例東海・'
        . '参議院 愛知県選挙区）の一覧です。', '/giin');
    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › 議員一覧</nav>';
    echo '<h1>愛知の国会議員 ' . $n . '人</h1>'
       . '<p class="lead">衆議院 愛知1〜16区、衆議院 比例東海ブロック、参議院 愛知県選挙区。'
       . '党派では選んでいません。</p>';
    g_giin_grid();

    echo '<h2>選挙区でみる</h2><div class="scroll"><table>'
       . '<tr><th>選挙区</th><th>議員</th><th>会派</th><th class="n">質疑</th>'
       . '<th class="n">答弁</th><th class="n">議事整理</th></tr>';
    foreach (g_all("SELECT * FROM giin ORDER BY
                    CASE WHEN district LIKE '愛知%' AND district GLOB '愛知[0-9]*' THEN 1
                         WHEN district LIKE '%東海%' THEN 2 ELSE 3 END,
                    CAST(REPLACE(district,'愛知','') AS INTEGER), display") as $g) {
        echo '<tr><td>' . g_e(g_ku($g['district'])) . '</td>'
           . '<td><a href="' . g_url('g/' . $g['id']) . '">' . g_e($g['display']) . '</a></td>'
           . '<td><span class="pill">' . g_e($g['party']) . '</span></td>'
           . '<td class="n">' . number_format((int)$g['n_q']) . '</td>'
           . '<td class="n">' . number_format((int)$g['n_gov']) . '</td>'
           . '<td class="n">' . number_format((int)$g['n_chair']) . '</td></tr>';
    }
    echo '</table></div>';
    g_foot();
}

function g_page_themes(): void
{
    g_head('ことがら一覧', '年収の壁・南海トラフ・自動車産業など、愛知の国会議員が'
        . '国会で触れたことがらから発言を引けます。', '/themes');
    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › ことがら一覧</nav>';
    echo '<h1>ことがらから探す</h1>'
       . '<p class="lead">それぞれ、決めた語が発言に出てきたものを機械的に集めています。'
       . '賛成・反対の判定はしていません。</p><div class="grid">';
    foreach (g_themes() as $t) {
        $args = []; $w = g_words_where($t['words'], $args);
        $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind='q' AND $w", $args);
        echo '<a class="card" href="' . g_url('t/' . $t['slug']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="mt">' . g_e($t['lead']) . '</div>'
           . '<div class="n">' . number_format($c) . '件</div></a>';
    }
    echo '</div>';
    g_foot();
}

function g_page_compare(): void
{
    g_head('ことがら × 議員', '愛知の国会議員45人が、どのことがらに何件ふれたかの一覧です。', '/compare');
    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › ことがら × 議員</nav>';
    echo '<h1>ことがら × 議員</h1>'
       . '<p class="lead">縦が議員、横がことがら。数字は、その語が出てきた<b>質疑</b>の件数です。'
       . '委員長としての議事整理と、大臣としての答弁は数えていません。</p>'
       . '<p class="note">件数は発言の回数であって、熱心さや正しさを表すものではありません。'
       . '長い質疑ほど発言が細かく分かれて件数が増えます。会議録に載る前の発言は数えられません。</p>';

    $gs = g_all('SELECT * FROM giin WHERE n_q>0 ORDER BY party, n_q DESC');
    $ts = g_themes();
    echo '<div class="scroll"><table><tr><th>議員</th><th>会派</th>';
    foreach ($ts as $t) { echo '<th class="n">' . g_e($t['name']) . '</th>'; }
    echo '</tr>';
    foreach ($gs as $g) {
        echo '<tr><td><a href="' . g_url('g/' . $g['id']) . '">' . g_e($g['display']) . '</a></td>'
           . '<td><span class="pill">' . g_e($g['party']) . '</span></td>';
        foreach ($ts as $t) {
            $args = [$g['id']]; $w = g_words_where($t['words'], $args);
            $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.giin_id=? AND s.kind='q' AND $w", $args);
            echo '<td class="n">' . ($c ? '<a href="' . g_url('t/' . $t['slug']) . '?g=' . $g['id'] . '">'
                . number_format($c) . '</a>' : '<span class="note">-</span>') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
    g_foot();
}

function g_page_meeting(int $id, int $page): void
{
    g_page_top();
}

function g_page_about(): void
{
    g_head('このサイトについて', '収録の範囲・出典・しないことを書いています。', '/about');
    echo '<nav class="crumb"><a href="' . g_url('') . '">トップ</a> › このサイトについて</nav>';
    echo '<h1>このサイトについて</h1><div class="panel">'
       . '<h2 style="margin-top:0">何をするサイトか</h2>'
       . '<p>愛知の国会議員が、国会でいつ・どの会議で・何と言ったかを引くための道具です。'
       . '発言の抜粋と、会議録へのリンクを並べています。</p>'

       . '<h2>しないこと</h2><ul>'
       . '<li><b>要約しません。</b>出すのは機械的な抜粋だけです</li>'
       . '<li><b>論評しません。</b>良し悪しの評価は書きません</li>'
       . '<li><b>賛成・反対を判定しません。</b>語が出てきた回数を数えているだけです</li>'
       . '<li><b>議員に順位をつけません。</b>件数の多寡は、熱心さでも正しさでもありません</li>'
       . '<li><b>特定の政党・候補者を支援しません。</b>選挙運動のためのサイトではありません</li></ul>'

       . '<h2>件数の数え方</h2>'
       . '<p>会議録の発言には、議員としての質疑のほかに、'
       . '<b>委員長としての議事整理</b>（「次に、○○君。」「本日はこれにて散会いたします」）と、'
       . '<b>大臣・副大臣・政務官としての答弁</b>が混ざっています。'
       . 'これを分けずに数えると、委員長を務めた議員の件数が跳ね上がって'
       . '「よく質問している人」に見えてしまいます。</p>'
       . '<p>実際、ある議員は収録期間の発言601件のうち、議員としての質疑は5件で、'
       . '596件は委員長としての議事整理でした。そこで3つに分けて数え、'
       . '<b>既定では「議員としての質疑」だけ</b>を表示しています。'
       . '各ページのタブで切り替えられます。</p>'
       . '<p class="note">分け方は、発言の冒頭の話者表記（「○○○委員長」「○副大臣（○○君）」など）で'
       . '機械的に判定しています。AIは使っていません。</p>'

       . '<h2>だれを収録しているか</h2>'
       . '<p><b>愛知の有権者だけが投票用紙に書ける候補</b>を収録しています。</p><ul>'
       . '<li>衆議院 愛知1区〜16区</li>'
       . '<li>衆議院 比例代表 東海ブロック</li>'
       . '<li>参議院 愛知県選挙区</li></ul>'
       . '<p>参議院の比例代表は全国共通なので入れていません。'
       . 'この線引きなら党派や住所で人を選り分ける必要がなく、'
       . '「なぜこの人が入っていて、あの人が入っていないのか」に一言で答えられます。</p>'
       . '<p class="note">現職のみを収録しています。選挙の候補者は扱いません。</p>'

       . '<h2>出典</h2><ul>'
       . '<li>発言・会議名・日付：<a href="https://kokkai.ndl.go.jp/" rel="nofollow noopener" '
       . 'target="_blank">国立国会図書館 国会会議録検索システム</a></li>'
       . '<li>衆議院議員の名簿：<a href="https://www.shugiin.go.jp/" rel="nofollow noopener" '
       . 'target="_blank">衆議院 会派別議員一覧</a></li>'
       . '<li>参議院議員の名簿：<a href="https://github.com/smartnews-smri/house-of-councillors" '
       . 'rel="nofollow noopener" target="_blank">smartnews-smri/house-of-councillors</a>（MIT）</li></ul>'
       . '<p>発言の全文は、必ず会議録でご確認ください。このサイトの抜粋は'
       . '冒頭を機械的に切り出したもので、発言の趣旨を代表するとは限りません。</p>'

       . '<h2>間違いを見つけたら</h2>'
       . '<p>収録の誤り、人物の取り違え、掲載をやめてほしいというご連絡は '
       . '<a href="mailto:info@exbridge.jp">info@exbridge.jp</a> までお願いします。'
       . '確認のうえ、速やかに直すか取り下げます。</p>'

       . '<h2>運営</h2>'
       . '<p>株式会社エクスブリッジ（名古屋市瑞穂区）。'
       . '第4世代のテーマとして、政治・社会の課題に対応する情報技術をAIを活用して提供しています。'
       . '<a href="https://xb4g.com/">xb4g.com</a></p>'
       . '</div>';
    g_foot();
}
