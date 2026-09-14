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
    // ---- 古いURLは 302 で新URLへ送る（公開当日なので検索インデックスの積み上げは無い）----
    if (preg_match('#^/g/(\d+)$#', $path, $m)) {
        $g = g_one('SELECT slug FROM giin WHERE id=?', [(int)$m[1]]);
        if ($g) { g_redirect(g_url($g['slug'])); return; }
    }
    if (preg_match('#^/t/([a-z0-9-]+)$#', $path, $m)) { g_redirect(g_url('theme/' . $m[1])); return; }
    if ($path === '/giin')   { g_redirect(g_url('list')); return; }
    if ($path === '/themes') { g_redirect(g_url('theme')); return; }

    // ---- いまのURL ----
    if (preg_match('#^/theme/([a-z0-9-]+)$#', $path, $m)) { g_page_theme($m[1], $page); return; }
    switch ($path) {
        case '/':        g_page_top(); return;
        case '/search':  g_page_search($page); return;
        case '/list':    g_page_list(); return;
        case '/theme':   g_page_themes(); return;
        case '/news':    g_page_news($page); return;
        case '/compare': g_page_compare(); return;
        case '/about':   g_page_about(); return;
    }
    // 議員は /giin/<ローマ字> で引く。URLに名前が入っていないと、
    // 検索結果でも共有先でも「誰のページか」が伝わらない。
    if (preg_match('#^/([a-z0-9-]+)$#', $path, $m) && !in_array($m[1], G_RESERVED, true)) {
        $g = g_one('SELECT id FROM giin WHERE slug=?', [$m[1]]);
        if ($g) { g_page_giin((int)$g['id'], $page); return; }
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
       . ($show_name && !empty($s['plain'])
          ? '<a href="' . g_url($s['slug']) . '">' . g_e($s['plain']) . '</a>'
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
        . '国会で何を質問したかを ' . number_format($n) . '件の質疑から引けます。要約はしません。', '/',
        ['jsonld' => g_jsonld([
            ['@type' => 'WebSite', '@id' => g_abs(''), 'url' => g_abs(''), 'name' => G_SITE,
             'inLanguage' => 'ja',
             'description' => '衆議院 愛知1〜16区・比例東海ブロック・参議院 愛知県選挙区の'
                 . '国会議員45人が、国会でいつ・どの会議で何を質問したかを引ける道具。',
             'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ',
                             'url' => 'https://xb4g.com/'],
             'potentialAction' => ['@type' => 'SearchAction',
                 'target' => ['@type' => 'EntryPoint',
                              'urlTemplate' => g_abs('search') . '?q={search_term_string}'],
                 'query-input' => 'required name=search_term_string']],
            // AI検索に「これは何のデータか」を機械可読で渡す
            ['@type' => 'Dataset', '@id' => g_abs('') . '#dataset',
             'name' => '愛知の国会議員45人の国会発言',
             'description' => '衆議院 愛知1〜16区・比例東海ブロック・参議院 愛知県選挙区の'
                 . '国会議員45人について、国会会議録から取得した発言 '
                 . number_format((int)g_val('SELECT COUNT(*) FROM speech')) . '件。'
                 . '議員としての質疑・大臣としての答弁・委員長としての議事整理を分けて数えている。'
                 . '要約や論評は含まない。',
             'url' => g_abs(''), 'inLanguage' => 'ja',
             'license' => 'https://www.digital.go.jp/resources/open_data/public_data_license_v1.0',
             'temporalCoverage' => g_meta('range_from') . '/..',
             'spatialCoverage' => ['@type' => 'Place', 'name' => '愛知県'],
             'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ',
                           'url' => 'https://xb4g.com/'],
             'isBasedOn' => [
                 ['@type' => 'Dataset', 'name' => '国会会議録検索システム',
                  'url' => 'https://kokkai.ndl.go.jp/'],
                 ['@type' => 'Dataset', 'name' => '参議院議案情報（smartnews-smri／MIT）',
                  'url' => 'https://github.com/smartnews-smri/house-of-councillors'],
             ],
             'variableMeasured' => ['発言日', '会議名', '発言者', '会派', '発言の立場', '選挙区']],
        ])]);
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

    $hot = g_hot_themes(150, 4);
    if ($hot) {
        echo '<h2>いま動いていることがら</h2>'
           . '<p class="note">国会に出された議案と、省庁の報道発表から、'
           . 'ことがらの語で機械的に拾ったものです。当サイトが選んだ話題ではありません。</p>';
        foreach ($hot as $t) {
            echo '<div class="panel">'
               . '<h3 style="margin:0 0 6px;font-size:16px">'
               . '<a href="' . g_url('theme/' . $t['slug']) . '">' . g_e($t['name']) . '</a>'
               . ' <span class="note">最近の動き ' . $t['n'] . '件</span></h3>';
            foreach (g_news($t['slug'], 2) as $n) { echo g_news_item($n, true); }
            $top = g_theme_top($t['slug'], 3);
            if ($top) {
                echo '<div class="top3"><span class="note">このことがらを国会でよく質問しているのは</span>';
                foreach ($top as $g) {
                    echo '<a href="' . g_url($g['slug']) . '">' . g_e($g['plain'])
                       . '（' . g_e($g['party']) . '）' . number_format((int)$g['c']) . '件</a>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '<p><a href="' . g_url('news') . '">最近の動きをまとめて見る</a></p>';
    }

    echo '<h2>ことがらから探す</h2><div class="grid">';
    foreach (g_themes() as $t) {
        $args = [];
        $w = g_words_where($t['words'], $args);
        $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind='q' AND $w", $args);
        echo '<a class="card" href="' . g_url('theme/' . $t['slug']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="n">' . number_format($c) . '件</div></a>';
    }
    echo '</div>';

    echo '<h2>最近の発言</h2>';
    foreach (g_all('SELECT s.*,g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id'
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
    if (!$g) { http_response_code(404); g_head('見つかりません', '', '/list', ['noindex' => true]);
        echo '<h1>その議員は収録していません</h1>'; g_foot(); return; }

    $ku = g_ku($g['district']);
    $kind = g_kind();
    $counts = ['q' => (int)$g['n_q'], 'gov' => (int)$g['n_gov'], 'chair' => (int)$g['n_chair']];
    $desc = $g['plain'] . '（' . $g['house'] . ' ' . $ku . '・' . $g['party'] . '）が国会で行った質疑'
          . number_format((int)$g['n_q']) . '件を、日付・会議名・会議録リンクつきで並べています。'
          . '要約や論評はしていません。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['議員一覧', '/list'], [$g['plain'], '/' . $g['slug']]]),
        ['@type' => 'ProfilePage', '@id' => g_abs($g['slug']),
         'url' => g_abs($g['slug']), 'name' => $g['plain'] . 'の国会発言',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')],
         'mainEntity' => array_filter([
             '@type' => 'Person', 'name' => $g['plain'],
             'alternateName' => $g['kana'],
             'jobTitle' => $g['house'] . '議員',
             'affiliation' => ['@type' => 'Organization', 'name' => $g['kaiha']],
             'url' => $g['profile'] ?: null,
             'image' => $g['photo'] ?: null,
         ])],
    ]);
    g_head($g['plain'] . 'の国会発言（' . $g['house'] . ' ' . $ku . '）', $desc,
           '/' . $g['slug'], ['jsonld' => $ld, 'image' => g_abs('img/og/' . $g['slug'] . '.png')]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('list') . '">議員一覧</a> › ' . g_e($g['plain']) . '</nav>';
    echo '<h1>' . g_e($g['plain']) . '</h1>'
       . '<p class="lead">' . g_e($g['house']) . '　' . g_e($ku)
       . '　<span class="pill">' . g_e($g['party']) . '</span>'
       . '　<span class="note">' . g_e($g['kana']) . '</span>'
       . '　<span class="note">会派: ' . g_e($g['kaiha']) . '</span>'
       . ($g['wins'] ? '　<span class="note">当選' . g_e($g['wins']) . '回</span>' : '')
       . '</p>';

    $L = g_links($g['slug']);
    if ($L) {
        echo '<h2>本人の発信</h2>' . g_official($L);
    }

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
    g_kind_tabs($kind, $counts, g_url($g['slug']));
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
            echo '<a class="card" href="' . g_url('theme/' . $t['slug']) . '?g=' . $g['slug'] . '&k=' . $kind . '">'
               . '<div class="nm">' . g_e($t['name']) . '</div>'
               . '<div class="n">' . number_format($c) . '件</div></a>';
        }
        echo '</div><p class="note">語がその発言に出てきた回数です。賛成・反対の判定はしていません。</p>';
    }

    // この議員が提出者になっている議案
    $mine = g_news('', 10, $id);
    if ($mine) {
        echo '<h2>提出した議案</h2>'
           . '<p class="note">この議員が提出者・発議者として名前が出ている議案です'
           . '（出典: 参議院議案情報 / smartnews-smri, MIT）。</p>';
        foreach ($mine as $n) { echo g_news_item($n); }
    }

    // 公式YouTubeの新着（チャンネルRSSから取り込んだもの・APIキー不使用）
    $vs = g_videos((int)$g['id'], 6);
    if ($vs) {
        echo '<h2>公式YouTubeの新着</h2>'
           . '<p class="note">' . g_e($vs[0]['channel']) . ' の新しい動画です。'
           . 'サムネイルを押すまで YouTube を読み込みません。'
           . '当サイトは動画の中身を持っておらず、要約もしていません。</p>'
           . g_video_grid($vs)
           . (!empty($L['youtube'])
              ? '<p style="margin-top:10px"><a href="' . g_e($L['youtube']) . '" rel="nofollow noopener" '
                . 'target="_blank">チャンネルをすべて見る</a></p>' : '');
    }

    // Xは公式の埋め込みに任せる。**APIで取ってきて自前で並べない**
    // （他人のポストの読み取りは従量課金で、規約も厳しい。埋め込みなら本家がそのまま出す）
    if (!empty($L['x'])) {
        $xu = preg_replace('#^https?://(www\.)?twitter\.com/#', 'https://x.com/', $L['x']);
        echo '<h2>Xの新着</h2>'
           . '<p class="note">X の公式の埋め込みです。当サイトが投稿を保存・加工しているわけではありません。'
           . '読み込むと X に通信します。</p>'
           . '<div class="xtl"><a class="twitter-timeline" data-height="520" data-dnt="true" '
           . 'data-chrome="noheader nofooter transparent" href="' . g_e($xu) . '">'
           . g_e($g['plain']) . 'のポスト</a></div>'
           . '<script async src="https://platform.twitter.com/widgets.js" charset="utf-8"></script>'
           . '<p style="margin-top:8px"><a href="' . g_e($xu) . '" rel="nofollow noopener" target="_blank">'
           . 'Xで見る</a></p>';
    }

    // 発言一覧
    $per = 20; $off = ($page - 1) * $per;
    $total = $counts[$kind];
    echo '<h2>' . g_e(g_kind_label($kind)) . '（' . number_format($total) . '件）</h2>';
    foreach (g_all('SELECT * FROM speech WHERE giin_id=? AND kind=? ORDER BY date DESC, speech_order DESC'
                 . ' LIMIT ? OFFSET ?', [$id, $kind, $per, $off]) as $s) { g_speech($s, '', false); }
    g_pager($page, $total, $per, g_url($g['slug']) . '?k=' . $kind);

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
    if (!$t) { http_response_code(404); g_head('見つかりません', '', '/theme', ['noindex' => true]);
        echo '<h1>そのことがらは登録されていません</h1>'; g_foot(); return; }

    $onlySlug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['g'] ?? ''));
    $who  = $onlySlug !== '' ? g_one('SELECT * FROM giin WHERE slug=?', [$onlySlug]) : null;
    $only = $who ? (int)$who['id'] : 0;
    if (!$who) { $onlySlug = ''; }
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

    $title = $t['name'] . 'について、愛知の国会議員は何と言ったか' . ($who ? '（' . $who['plain'] . '）' : '');
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['ことがら一覧', '/theme'], [$t['name'], '/theme/' . $slug]]),
        ['@type' => 'CollectionPage', '@id' => g_abs('theme/' . $slug),
         'url' => g_abs('theme/' . $slug), 'name' => $title,
         'about' => ['@type' => 'Thing', 'name' => $t['name']],
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head($title, $t['lead'] . '愛知の有権者が選んだ国会議員45人の発言から、'
        . $t['name'] . 'に触れた' . number_format($total) . '件を集めました。',
        '/theme/' . $slug, ['jsonld' => $ld, 'image' => g_abs('img/og/theme-' . $slug . '.png')]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('theme') . '">ことがら一覧</a> › ' . g_e($t['name']) . '</nav>';
    echo '<h1>' . g_e($t['name']) . '</h1><p class="lead">' . g_e($t['lead'])
       . '　該当 <b>' . number_format($total) . '件</b>'
       . ($who ? '（' . g_e($who['plain']) . 'のみ　<a href="' . g_url('theme/' . $slug) . '">全員に戻す</a>）' : '')
       . '</p>'
       . '<p class="note">拾っている語：' . g_e(implode('、', $t['words']))
       . '。語が出てきた発言を機械的に集めたもので、賛成・反対の判定はしていません。</p>';
    // このことがらの最近の動き（議案・省庁の報道発表）
    $nw = g_news($slug, 6);
    if ($nw) {
        echo '<h2>このことがらの最近の動き</h2>'
           . '<p class="note">国会に出された議案と、省庁の報道発表です。'
           . '見出しと日付と発表元だけを出しています。中身は必ずリンク先でご確認ください。</p>';
        foreach ($nw as $n) { echo g_news_item($n); }
    }

    g_kind_tabs($kind, $counts, g_url('theme/' . $slug) . ($only ? '?g=' . $onlySlug : ''));

    // 議員別の件数
    if (!$only) {
        $gArgs = $args; $gArgs[] = $kind;
        $rows = g_all("SELECT g.id,g.display,g.plain,g.slug,g.party,g.district,g.house,COUNT(*) c
                       FROM speech s JOIN giin g ON g.id=s.giin_id WHERE $w AND s.kind=?
                       GROUP BY g.id ORDER BY c DESC", $gArgs);
        if ($rows) {
            $max = (int)$rows[0]['c'];
            echo '<h2>だれが何件ふれたか</h2><div class="scroll"><table>'
               . '<tr><th>議員</th><th>選挙区</th><th>会派</th><th class="n">件数</th><th></th></tr>';
            foreach ($rows as $r) {
                echo '<tr><td><a href="' . g_url('theme/' . $slug) . '?g=' . $r['slug'] . '&k=' . $kind . '">'
                   . g_e($r['plain']) . '</a></td>'
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
    foreach (g_all("SELECT s.*,g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
                    WHERE $w$extra ORDER BY s.date DESC LIMIT ? OFFSET ?", $lArgs) as $s) {
        g_speech($s, $t['words'][0]);
    }
    g_pager($page, $total, $per,
            g_url('theme/' . $slug) . '?k=' . $kind . ($only ? '&g=' . $onlySlug : ''));
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
           . '<p class="note">よく調べられることがらは<a href="' . g_url('theme') . '">ことがら一覧</a>'
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
    foreach (g_all("SELECT s.*,g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
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
    $gsAll = g_all('SELECT plain,slug FROM giin ORDER BY id');
    g_head('議員一覧', '愛知の有権者が選んだ国会議員' . $n . '人（衆議院 愛知1〜16区・比例東海・'
        . '参議院 愛知県選挙区）の一覧です。', '/list',
        ['image' => g_abs('img/og/list.png'), 'jsonld' => g_jsonld([
            g_crumbs([['ホーム', '/'], ['議員一覧', '/list']]),
            ['@type' => 'ItemList', 'name' => '愛知の国会議員 ' . $n . '人',
             'numberOfItems' => $n,
             'itemListElement' => array_map(
                 fn($i, $g) => ['@type' => 'ListItem', 'position' => $i + 1,
                                'name' => $g['plain'], 'url' => g_abs($g['slug'])],
                 array_keys($gsAll), $gsAll)],
        ])]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 議員一覧</nav>';
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
           . '<td><a href="' . g_url($g['slug']) . '">' . g_e($g['display']) . '</a></td>'
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
    $ts = g_themes();
    g_head('ことがら一覧', '年収の壁・南海トラフ・自動車産業など、愛知の国会議員が'
        . '国会で触れたことがらから発言を引けます。', '/theme',
        ['image' => g_abs('img/og/theme.png'), 'jsonld' => g_jsonld([
            g_crumbs([['ホーム', '/'], ['ことがら一覧', '/theme']]),
            ['@type' => 'ItemList', 'name' => 'ことがら一覧', 'numberOfItems' => count($ts),
             'itemListElement' => array_map(
                 fn($i, $t) => ['@type' => 'ListItem', 'position' => $i + 1,
                                'name' => $t['name'], 'url' => g_abs('theme/' . $t['slug'])],
                 array_keys($ts), $ts)],
        ])]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › ことがら一覧</nav>';
    echo '<h1>ことがらから探す</h1>'
       . '<p class="lead">それぞれ、決めた語が発言に出てきたものを機械的に集めています。'
       . '賛成・反対の判定はしていません。</p><div class="grid">';
    foreach (g_themes() as $t) {
        $args = []; $w = g_words_where($t['words'], $args);
        $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind='q' AND $w", $args);
        echo '<a class="card" href="' . g_url('theme/' . $t['slug']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="mt">' . g_e($t['lead']) . '</div>'
           . '<div class="n">' . number_format($c) . '件</div></a>';
    }
    echo '</div>';
    g_foot();
}

function g_page_compare(): void
{
    g_head('ことがら × 議員', '愛知の国会議員45人が、どのことがらに何件ふれたかの一覧です。', '/compare',
        ['jsonld' => g_jsonld([g_crumbs([['ホーム', '/'], ['ことがら × 議員', '/compare']])])]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › ことがら × 議員</nav>';
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
        echo '<tr><td><a href="' . g_url($g['slug']) . '">' . g_e($g['display']) . '</a></td>'
           . '<td><span class="pill">' . g_e($g['party']) . '</span></td>';
        foreach ($ts as $t) {
            $args = [$g['id']]; $w = g_words_where($t['words'], $args);
            $c = (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.giin_id=? AND s.kind='q' AND $w", $args);
            echo '<td class="n">' . ($c ? '<a href="' . g_url('theme/' . $t['slug']) . '?g=' . $g['slug'] . '">'
                . number_format($c) . '</a>' : '<span class="note">-</span>') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
    g_foot();
}

function g_page_about(): void
{
    $faq = [
        ['このサイトは何をするものですか？',
         '愛知の国会議員が、国会でいつ・どの会議で何を質問したかを引くための道具です。'
         . '発言の抜粋と、国会会議録へのリンクを並べています。要約や論評はしません。'],
        ['だれを収録していますか？',
         '愛知の有権者だけが投票用紙に書ける候補を収録しています。衆議院 愛知1区〜16区、'
         . '衆議院 比例代表 東海ブロック、参議院 愛知県選挙区の45人です。'
         . '参議院の比例代表は全国共通なので入れていません。党派では選んでいません。'],
        ['発言の件数はどう数えていますか？',
         '議員として質問・討論した発言だけを既定で数えています。委員長としての議事整理'
         . '（「次に、○○君。」）や、大臣・副大臣としての答弁は別に数えています。'
         . '混ぜると、委員長を務めた議員の件数が跳ね上がって「よく質問している人」に'
         . '見えてしまうためです。ある議員は収録期間の発言601件のうち、'
         . '議員としての質疑は5件で、596件は委員長としての議事整理でした。'],
        ['データの出典はどこですか？',
         '発言は国立国会図書館の国会会議録検索システム、衆議院議員の名簿は衆議院の'
         . '会派別議員一覧、参議院議員の名簿は smartnews-smri/house-of-councillors（MIT）、'
         . '議案は参議院の議案情報、報道発表は厚生労働省・国土交通省・総務省・内閣府・'
         . 'デジタル庁・文部科学省の各ホームページ（公共データ利用規約 PDL1.0）です。'],
        ['議員の公式SNSはどうやって集めていますか？',
         '本人の公式サイトに掲載されているリンクだけを採っています。検索結果から拾ったものは'
         . '載せません。なりすましのアカウントを本人のものとして出さないためです。'
         . 'どこで確認したかは各ページに書いています。'],
        ['AIは使っていますか？',
         '使っていません。発言の立場の分類も、ことがらへの割り当ても、'
         . '決めた語による機械的な判定です。要約も論評も生成していません。'],
        ['特定の政党を応援するサイトですか？',
         'いいえ。党派を問わず、愛知の有権者が投票できる国会議員を全員収録しています。'
         . '選挙運動を目的としたサイトではありません。'],
    ];
    g_head('このサイトについて', '収録の範囲・出典・しないことを書いています。', '/about',
        ['jsonld' => g_jsonld([
            g_crumbs([['ホーム', '/'], ['このサイトについて', '/about']]),
            ['@type' => 'FAQPage', 'mainEntity' => array_map(
                fn($q) => ['@type' => 'Question', 'name' => $q[0],
                           'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
        ])]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › このサイトについて</nav>';
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

       . '<h2>「最近の動き」について</h2>'
       . '<p>ことがらのページとトップに出している「最近の動き」は、'
       . '<b>国会に出された議案</b>と<b>省庁の報道発表</b>です。'
       . '見出しと日付と発表元とリンクだけを出し、本文は持っていません。要約もしません。</p>'
       . '<p><b>一般ニュースは載せていません。</b>報道各社のRSSは個人利用に限られており、'
       . '事業者が再配信することを許していないためです'
       . '（NHKは「個人の方の利用のためのみ」「商業目的での利用を含め再配信や再提供を許可するものではありません」と明記）。'
       . 'ここに出るのは、商用利用が明文で許されている一次情報だけです。</p>'
       . '<p class="note">ことがらへの割り当ては、ことがらごとに決めた語が見出しに出てきたかどうかで'
       . '機械的に行っています。AIは使っていません。当サイトが話題を選んでいるわけではありません。</p>'

       . '<h2>本人の発信について</h2>'
       . '<p>議員ページに、公式サイト・X・YouTube などへのリンクを置いています。'
       . '<b>本人の公式サイトに掲載されているリンクだけ</b>を採り、検索結果から拾ったものは載せません。'
       . 'どこで確認したかもページに書いています。なりすましのアカウントを'
       . '本人のものとして出さないためです。見つからない方は空のままにしています。</p>'
       . '<p>公式YouTubeの新着は、チャンネルのRSSからタイトル・日付・サムネイルだけを取り込んでいます。'
       . '<b>サムネイルを押すまで YouTube を読み込みません。</b>再生は YouTube の公式埋め込みに任せており、'
       . '当サイトは動画の中身を持っていません。要約もしません。</p>'
       . '<p>X は公式の埋め込みです。'
       . '<b>APIで投稿を取ってきて自前で並べることはしていません。</b>'
       . '本家がそのまま表示します（読み込むと X に通信します）。</p>'

       . '<h2>出典</h2><ul>'
       . '<li>議案：<a href="https://github.com/smartnews-smri/house-of-councillors" '
       . 'rel="nofollow noopener" target="_blank">参議院 議案情報（smartnews-smri／MIT）</a></li>'
       . '<li>報道発表：厚生労働省・国土交通省・総務省・内閣府・デジタル庁・文部科学省の'
       . '各ホームページ（公共データ利用規約 PDL1.0。出典を記載し、編集・加工はしていません）</li>'
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

       . '<h2>よくある質問</h2>'
       . implode('', array_map(
           fn($q) => '<h3 style="font-size:15px;margin:14px 0 4px">' . g_e($q[0]) . '</h3>'
                   . '<p>' . g_e($q[1]) . '</p>', $faq))

       . '<h2>運営</h2>'
       . '<p>株式会社エクスブリッジ（名古屋市瑞穂区）。'
       . '第4世代のテーマとして、政治・社会の課題に対応する情報技術をAIを活用して提供しています。'
       . '<a href="https://xb4g.com/">xb4g.com</a></p>'
       . '</div>';
    g_foot();
}

/** 最近の動き（議案・省庁の報道発表）の一覧 */
function g_page_news(int $page): void
{
    $per = 30; $off = ($page - 1) * $per;
    $src = (string)($_GET['s'] ?? '');
    $w = ['1=1']; $a = [];
    if ($src === 'gian' || $src === 'press') { $w[] = 'source = ?'; $a[] = $src; }
    $ws = implode(' AND ', $w);
    $total = (int)g_val("SELECT COUNT(*) FROM news WHERE $ws", $a);

    g_head('最近の動き', '国会に出された議案と、省庁の報道発表を、ことがらごとに並べています。'
        . '見出しと日付と発表元だけを出し、中身はリンク先でご確認いただきます。', '/news',
        ['jsonld' => g_jsonld([g_crumbs([['ホーム', '/'], ['最近の動き', '/news']])])]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 最近の動き</nav>';
    echo '<h1>最近の動き</h1>'
       . '<p class="lead">国会に出された議案と、省庁の報道発表です。'
       . '当サイトが選んだ話題ではなく、配信されているものをことがらの語で機械的に束ねています。</p>'
       . '<p class="note"><b>一般ニュースは載せていません。</b>報道各社のRSSは個人利用に限られており、'
       . '当社のような事業者が再配信することを許していないためです。'
       . 'ここに出るのは、商用利用が明文で許されている一次情報だけです。</p>';

    $base = g_url('news');
    echo '<p class="lead">'
       . '<a class="pill' . ($src === '' ? '' : ' gray') . '" href="' . g_e($base) . '">すべて '
       . number_format((int)g_val('SELECT COUNT(*) FROM news')) . '</a> '
       . '<a class="pill' . ($src === 'gian' ? '' : ' gray') . '" href="' . g_e($base . '?s=gian') . '">議案 '
       . number_format((int)g_val("SELECT COUNT(*) FROM news WHERE source='gian'")) . '</a> '
       . '<a class="pill' . ($src === 'press' ? '' : ' gray') . '" href="' . g_e($base . '?s=press') . '">省庁の報道発表 '
       . number_format((int)g_val("SELECT COUNT(*) FROM news WHERE source='press'")) . '</a>'
       . '</p>';

    $a2 = $a; $a2[] = $per; $a2[] = $off;
    foreach (g_all("SELECT * FROM news WHERE $ws ORDER BY date DESC LIMIT ? OFFSET ?", $a2) as $n) {
        echo g_news_item($n);
        // このニュースが当たったことがらと、そこでよく質問している議員
        $ths = g_all('SELECT theme FROM news_theme WHERE news_id=?', [$n['id']]);
        if ($ths) {
            echo '<div class="top3" style="margin:-4px 0 14px 13px">';
            foreach ($ths as $x) {
                $t = g_theme($x['theme']);
                if (!$t) { continue; }
                echo '<a href="' . g_url('theme/' . $t['slug']) . '">' . g_e($t['name']) . '</a>';
                foreach (g_theme_top($t['slug'], 2) as $g) {
                    echo '<a href="' . g_url($g['slug']) . '" style="background:#fff">'
                       . g_e($g['plain']) . ' ' . number_format((int)$g['c']) . '件</a>';
                }
            }
            echo '</div>';
        }
    }
    g_pager($page, $total, $per, $base . ($src !== '' ? '?s=' . $src : ''));

    echo '<div class="panel note" style="margin-top:20px"><b>出典</b><br>'
       . '・議案：参議院 議案情報（<a href="https://github.com/smartnews-smri/house-of-councillors" '
       . 'rel="nofollow noopener" target="_blank">smartnews-smri/house-of-councillors</a>／MIT）<br>'
       . '・報道発表：厚生労働省・国土交通省・総務省・内閣府・デジタル庁・文部科学省の各ホームページ'
       . '（公共データ利用規約 PDL1.0）。見出しとリンクをそのまま掲載しており、編集・加工はしていません。'
       . '</div>';
    g_foot();
}
