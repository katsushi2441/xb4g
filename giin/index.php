<?php
/**
 * 愛知の国会議員 発言ログ — https://xb4g.com/giin/
 *
 * **この道具がしないこと**：要約・論評・賛否の判定・議員の序列づけ。
 * 出すのは「いつ・どの会議で・何と言ったかの機械的な抜粋」と「会議録へのリンク」だけ。
 * ここを崩すと、こちらの誤りが議員の立場を毀損する経路になる。
 *
 * **収録の線引き**：愛知の有権者の一票が当落に効く議員。
 *   衆議院 愛知1〜16区 ／ 衆議院 比例東海ブロック ／ 参議院 愛知県選挙区 ＝ 45人。
 * 党派で選ばない。恣意的な取捨選択が無いので「なぜこの人が入っているのか」に一言で答えられる。
 */
declare(strict_types=1);

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/text.php';
require __DIR__ . '/lib/themes.php';
require __DIR__ . '/lib/trackers.php';
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
    // AIを独立したことがらに切り出したので、元の複合ページから送る
    if ($path === '/theme/digital-ai') { g_redirect(g_url('theme/digital')); return; }

    // ---- いまのURL ----
    if (preg_match('#^/theme/([a-z0-9-]+)$#', $path, $m)) { g_page_theme($m[1], $page); return; }
    if (preg_match('#^/tracker/([a-z0-9-]+)$#', $path, $m)) { g_page_tracker($m[1], $page); return; }
    if (preg_match('#^/party/([a-z0-9]+)$#', $path, $m)) { g_page_party($m[1]); return; }
    switch ($path) {
        case '/':        g_page_top(); return;
        case '/search':  g_page_search($page); return;
        case '/list':    g_page_list(); return;
        case '/theme':   g_page_themes(); return;
        case '/party':   g_page_parties(); return;
        case '/news':    g_page_news($page); return;
        case '/compare': g_page_compare(); return;
        case '/about':   g_page_about(); return;
        case '/ai':      g_page_ai(); return;
        case '/tracker': g_page_trackers(); return;
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
    // ヒーロー。数字は表から出す（人数・質疑・トラッカー本数・収録開始年）
    $ntr = count(g_trackers());
    $from = (string)g_meta('range_from');
    echo '<section class="hero"><p class="kick">Aichi &times; Diet &middot; Speech Log</p>'
       . '<h1>愛知の国会議員が、<br>国会で何を話したか</h1>'
       . '<p class="lead">衆議院 愛知1〜16区・比例東海ブロック・参議院 愛知県選挙区の'
       . '<b>' . $ng . '人</b>について、'
       . '<b>' . number_format($n) . '件</b>の質疑を、日付と会議名と一次情報リンクつきで並べています。'
       . '党派では選んでいません。要約も論評もしません。</p>'
       . '<form class="q" action="' . g_url('search') . '"><input type="search" name="q" '
       . 'placeholder="議員名、またはことばで探す（例: 福田徹、年収の壁）"><button>探す</button></form>'
       . '<div class="facts">'
       . '<div class="f"><b>' . $ng . '<i>人</i></b><span>収録している国会議員</span></div>'
       . '<div class="f"><b>' . number_format($n) . '<i>件</i></b><span>議員としての質疑</span></div>'
       . '<div class="f"><b>' . $ntr . '<i>本</i></b><span>全国の会議録を追う国会トラッカー</span></div>'
       . '<div class="f"><b>' . g_e(substr($from, 0, 4)) . '<i>年〜</i></b><span>収録期間（' . g_e($from) . ' 以降）</span></div>'
       . '</div></section>'
       . '<div class="panel note"><b>件数の読み方</b>：数えているのは'
       . '<b>議員として質問・討論した発言</b>だけです。委員長としての議事整理'
       . '（「次に、○○君。」）や、大臣としての答弁は別に数えています。'
       . '混ぜると、委員長を務めた議員の件数が跳ね上がって「よく質問している人」に'
       . '見えてしまうためです。各ページで切り替えられます。</div>';

    $hot = g_hot_themes(150, 4);
    if ($hot) {
        echo '<h2 data-en="Now">いま動いていることがら</h2>'
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
        echo '<p class="more"><a class="btn o" href="' . g_url('news') . '">最近の動きをまとめて見る</a></p>';
    }

    // AIの特集への導線。愛知は自動車・半導体・工作機械の土地なので、
    // 「国会でAIがどう論じられているか」は地元の有権者に直接効く
    $ai_q = g_theme_n('ai');
    $ai_n = (int)g_val("SELECT COUNT(DISTINCT s.giin_id) FROM speech s
        JOIN speech_theme st ON st.speech_id=s.speech_id AND st.theme='ai' WHERE s.kind='q'");
    if ($ai_q > 0) {
        echo '<h2 data-en="Feature">特集：AIを国会でどう論じているか</h2>'
           . '<div class="panel"><p>AIに触れた質疑は<b>' . number_format($ai_q) . '件</b>、'
           . '持ち出した議員は<b>' . $ai_n . '人</b>です。'
           . 'いちばん多く一緒に語られているのは<b>学校と教育</b>で、'
           . '件数より<b>何日・いくつの会議で持ち出したか</b>で並べています。</p>'
           . '<p class="more"><a class="btn" href="' . g_url('ai') . '">愛知の国会議員はAIをどう論じているか</a></p></div>';
    }

    // 全国トラッカーへの導線。**愛知の45人に限らず**全国の発言を追っていることがら（1つの枠にまとめて出す）
    $trs = [];
    foreach (g_trackers() as $tr) {
        $st = g_tracker_stats($tr['key']);
        if ($st) { $trs[] = [$tr, $st]; }
    }
    if ($trs) {
        echo '<h2 data-en="Tracker">国会トラッカー：法整備はどこまで来たか</h2>'
           . '<div class="panel"><p>これらのことがらだけは、愛知の45人に限らず<b>全国の国会議員の質疑と政府の答弁</b>を会議録から集め、'
           . 'だれが取り上げ、政府が何と答えてきたかを日付順に並べています。</p><div class="grid">';
        foreach ($trs as [$tr, $st]) {
            echo '<a class="card" href="' . g_url('tracker/' . $tr['key']) . '">'
               . '<div class="nm">' . g_e($tr['name']) . '</div>'
               . '<div class="n">質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件・議員 ' . (int)$st['speakers'] . '人'
               . '<br><span class="note">' . g_e(g_date($st['last'])) . 'まで</span></div></a>';
        }
        echo '</div><p class="more"><a class="btn o" href="' . g_url('tracker') . '">国会トラッカーの一覧</a></p></div>';
    }

    echo '<h2 data-en="Topics">ことがらから探す</h2><div class="grid">';
    foreach (g_themes() as $t) {
        $c = g_theme_n($t['slug']);
        echo '<a class="card" href="' . g_url('theme/' . $t['slug']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="n">' . number_format($c) . '件</div></a>';
    }
    echo '</div>';

    echo '<h2 data-en="Latest">最近の発言</h2>';
    foreach (g_all('SELECT s.*,g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id'
                 . " WHERE s.kind='q' ORDER BY s.date DESC, s.speech_order DESC LIMIT 10") as $s) { g_speech($s); }
    echo '<p class="more"><a class="btn o" href="' . g_url('search') . '">もっと探す</a></p>';

    echo '<h2 data-en="Parties">会派から見る</h2>'
       . '<p class="note">人数の割合と質疑の割合のずれが分かります。'
       . '<b>ずれは熱心さではなく、与党か野党かという立場で決まります。</b></p><div class="grid">';
    $tot = (int)g_val('SELECT COUNT(*) FROM giin');
    $totq = (int)g_val('SELECT SUM(n_q) FROM giin');
    foreach (g_parties() as $p) {
        $pn = $tot ? (int)$p['n'] / $tot * 100 : 0;
        $pq = $totq ? (int)$p['q'] / $totq * 100 : 0;
        echo '<a class="card" href="' . g_url('party/' . g_party_slug($p['party'])) . '">'
           . '<div class="nm">' . g_e($p['party']) . '</div>'
           . '<div class="mt">' . (int)$p['n'] . '人（' . round($pn) . '%）</div>'
           . '<div class="n">質疑 ' . number_format((int)$p['q']) . '件（' . round($pq) . '%）</div></a>';
    }
    echo '</div><p class="more"><a class="btn o" href="' . g_url('party') . '">会派の一覧を見る</a></p>';

    echo '<h2 data-en="Members">議員から探す</h2>';
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
    // 固有の語があるなら、共有カードをOGPにする。SNSに貼られたとき、
    // 「この議員だけが取り上げていること」が最初に目に入るほうが読まれる。
    $ogimg = g_uniq_count((int)$g['id']) > 0
        ? g_abs('img/uniq/' . $g['slug'] . '.png')
        : g_abs('img/og/' . $g['slug'] . '.png');
    g_head($g['plain'] . 'の国会発言（' . $g['house'] . ' ' . $ku . '）', $desc,
           '/' . $g['slug'], ['jsonld' => $ld, 'image' => $ogimg]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('list') . '">議員一覧</a> › ' . g_e($g['plain']) . '</nav>';
    echo '<section class="hero p"><p class="kick">' . g_e($g['house']) . ' &middot; ' . g_e($ku) . '</p>'
       . '<h1>' . g_e($g['plain']) . '<small>' . g_e($g['kana']) . '</small></h1>'
       . '<p class="lead" style="margin-bottom:0"><span class="pill">' . g_e($g['party']) . '</span>'
       . '　<span class="note">会派: ' . g_e($g['kaiha']) . '</span>'
       . ($g['wins'] ? '　<span class="note">当選' . g_e($g['wins']) . '回</span>' : '')
       . '</p>'
       . '<div class="kv">'
       . '<div class="c"><b>' . number_format($counts['q']) . '</b><span>議員として質問・討論した発言</span></div>'
       . '<div class="c"><b>' . number_format($counts['gov']) . '</b><span>大臣・副大臣・政務官としての答弁</span></div>'
       . '<div class="c"><b>' . number_format($counts['chair']) . '</b><span>委員長・議長としての議事整理</span></div>'
       . '</div></section>';

    $L = g_links($g['slug']);
    if ($L) {
        echo '<h2 data-en="Official">本人の発信</h2>' . g_official($L);
    }

    echo '<div class="panel note">「議事整理」は「次に、○○君。」のような進行の発言です。'
       . 'これを質疑と混ぜて数えると、委員長を務めた議員ほど件数が多く見えてしまうので分けています。</div>';

    echo g_insight_html(g_insight('giin', $g['slug']), 'いま何に取り組んでいるか');

    // **この議員だけが国会で言っている語。** 件数の多い少ないは立場で決まるので
    // 順位はつけられないが、「この人だけが取り上げている」は立場と関係がない。
    $uq = g_uniq_terms((int)$g['id'], 8);
    if ($uq) {
        $all = g_uniq_count((int)$g['id']);
        echo '<h2>' . g_e($g['plain']) . '議員だけが国会で取り上げていること</h2>'
           . '<div class="panel uniq"><p>愛知の有権者の一票が当落に効く国会議員'
           . (int)g_val('SELECT COUNT(*) FROM giin') . '人のうち、'
           . '<b>この言葉を国会で使っているのは' . g_e($g['plain']) . '議員だけ</b>です。</p>'
           . '<div class="scroll"><table><tr><th>言葉</th><th class="n">回数</th><th class="n">日数</th></tr>';
        foreach ($uq as $t) {
            echo '<tr><td><a href="' . g_url('search') . '?q=' . rawurlencode($t['term'])
               . '&amp;g=' . g_e($g['slug']) . '">' . g_e($t['term']) . '</a></td>'
               . '<td class="n"><b>' . (int)$t['n'] . '</b></td>'
               . '<td class="n">' . (int)$t['days'] . '日</td></tr>';
        }
        echo '</table></div>';
        if ($all > count($uq)) {
            echo '<p class="note">ほかに' . ($all - count($uq)) . '語あります。</p>';
        }
        echo '<p class="note">45人の質疑' . number_format((int)g_val('SELECT SUM(n_q) FROM giin'))
           . '件を全部読み、<b>4回以上・2日以上にまたがって使われた語</b>のうち、'
           . 'ほかの44人が一度も使っていないものを機械的に拾いました。'
           . '多い少ないの比較ではありません。</p>';
        // 貼れる1枚。**議員本人・事務所が使えるように、保存先をそのまま示す。**
        $card = g_abs('img/uniq/' . $g['slug'] . '.png');
        $share = 'https://twitter.com/intent/tweet?text='
               . rawurlencode($g['plain'] . '議員だけが国会で取り上げていること') . '&url='
               . rawurlencode(g_abs($g['slug']));
        echo '<p><img src="' . g_e($card) . '" alt="'
           . g_e($g['plain']) . '議員だけが国会で取り上げていること" loading="lazy"'
           . ' style="width:100%;max-width:100%;border:1px solid #e3e9ec;border-radius:12px"></p>'
           . '<p class="note">この画像は自由にお使いいただけます（出典として'
           . g_e(G_HOST . G_BASE . '/' . $g['slug']) . 'を添えてください）。'
           . '　<a href="' . g_e($card) . '" download>画像を保存</a>'
           . '　<a href="' . g_e($share) . '" rel="nofollow noopener" target="_blank">Xで共有</a></p>'
           . '</div>';
    }

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
        $c = g_theme_n($t['slug'], $kind, $id);
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

    // **この議員が取り上げていることがらに全国トラッカーがあれば、そこへ渡す。**
    // 愛知の発言だけでは「法整備はどこまで来たか」が分からないので、全国の質疑と政府の答弁へ導く（1枠にカードでまとめる）
    $trs = [];
    foreach (g_tracker_of_giin($id) as $tr) {
        $st = g_tracker_stats($tr['key']);
        if ($st) { $trs[] = [$tr, $st]; }
    }
    if ($trs) {
        usort($trs, fn($a, $b) => $b[0]['days'] <=> $a[0]['days'] ?: $b[0]['n'] <=> $a[0]['n']);
        echo '<h2 data-en="Tracker">国会トラッカー：' . g_e($g['plain']) . '議員が取り上げていることがらは、国会全体でどこまで来たか</h2>'
           . '<div class="panel"><p>これらのことがらは、愛知の45人に限らず<b>全国の国会議員の質疑と政府の答弁</b>を会議録から集めています。'
           . '件数は' . g_e($g['plain']) . '議員の質疑の数と日数、その下が全国の規模です。</p><div class="grid">';
        foreach ($trs as [$tr, $st]) {
            echo '<a class="card" href="' . g_url('tracker/' . $tr['key']) . '">'
               . '<div class="nm">' . g_e($tr['name']) . '</div>'
               . '<div class="n">' . g_e($g['plain']) . '議員 ' . $tr['n'] . '件・' . $tr['days'] . '日（最後は' . g_e(g_date($tr['last'])) . '）'
               . '<br><span class="note">全国: 質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件・議員 ' . (int)$st['speakers'] . '人</span></div></a>';
        }
        echo '</div></div>';
    }

    // **その議員が扱っていることがらの、公開データ。**
    // 議員ごとに数字を選ぶと恣意が入るので、ことがら単位で持っているものを
    // 「よく取り上げていることがら」の上位から順に出す。
    $st = [];
    foreach (g_themes() as $t) {
        if (g_theme_n($t['slug'], 'q', $id) > 0) {
            foreach (g_stats($t['slug']) as $one) { $st[] = $one; }
        }
        if (count($st) >= 3) { break; }
    }
    echo g_stats_html(array_slice($st, 0, 3),
        g_e($g['plain']) . '議員が国会で取り上げていることがらについて、'
      . '国や自治体が公表している数字です。議員ごとに選んだものではなく、'
      . 'ことがらごとに登録しているものを出しています。');

    // **注目してほしい会議録。** こちらが重要だと選ぶと論評になるので、
    // 本人・事務所から教わったものを data/links.json に登録して出す。
    // 抜粋は会議録そのものから取り、要約はしない。
    $hl = g_highlights($g['slug']);
    if ($hl) {
        echo '<h2 data-en="Highlights">注目してほしい会議録</h2>'
           . '<p class="note">' . g_e($g['plain']) . '議員ご本人の発信をもとに登録したものです。'
           . '<b>当サイトが重要だと判断して選んだものではありません。</b>'
           . '抜粋は会議録そのままで、要約はしていません。</p>';
        foreach ($hl as $h) {
            echo '<div class="panel hl"><h3 style="margin-top:0">' . g_e($h['title']) . '</h3>'
               . '<p class="note">' . g_e($h['date']) . '　' . g_e($h['meeting']) . '</p>';
            // **発言ごとに、その中で最も特徴的な1文だけを抜く。** 同じ言葉を
            // 何度も出すと、同じ抜粋が並んで読みにくくなる（実際にそうなった）。
            $words = $h['words'] ?? [];
            $shown = [];
            foreach (($h['speeches'] ?? []) as $sid) {
                $e = g_kaigi_excerpt($sid, $words, 3);
                if (!$e || !$e['excerpts']) { continue; }
                foreach ($e['excerpts'] as $x) {
                    if (in_array($x, $shown, true)) { continue; }
                    $shown[] = $x;
                    echo '<blockquote class="kaigi">' . g_e($x)
                       . '<br><a class="note" href="' . g_e($e['speech_url'])
                       . '" rel="nofollow noopener" target="_blank">この発言を会議録で読む</a>'
                       . '</blockquote>';
                }
            }
            if (!empty($h['video'])) {
                echo '<p><a href="https://www.youtube.com/watch?v=' . g_e($h['video'])
                   . '" rel="nofollow noopener" target="_blank">'
                   . g_e($h['video_note'] ?: '本人の動画を見る') . '</a></p>';
            }
            echo '<p class="note">登録の根拠：' . g_e($h['source'] ?? '—')
               . '（' . g_e($h['checked_at'] ?? '') . '確認）</p></div>';
        }
    }

    // **質疑をした日と、その日の公式動画。** 会議録は読むもの、動画は聞くもので、
    // 同じ日の仕事を両側から辿れるのは、両方を持っているこの道具だけである。
    $sv = g_speech_videos($id);
    if ($sv) {
        $days = g_all("SELECT date, meeting, COUNT(*) n FROM speech
                       WHERE giin_id=? AND kind='q' GROUP BY date, meeting
                       ORDER BY date DESC", [$id]);
        echo '<h2>その日の質疑と、本人の動画</h2>'
           . '<p class="note">質疑をした日と、'
           . g_e($g['plain']) . '議員の公式YouTubeにある同じ日の動画を並べました。'
           . '会議録で読むか、動画で聞くかを選べます。'
           . '<b>動画の題名に日付が入っているものだけ</b>を機械的に対応づけています。</p>'
           . '<div class="scroll"><table>'
           . '<tr><th>日</th><th>会議</th><th class="n">質疑</th><th>本人の動画</th></tr>';
        foreach ($days as $d) {
            $k = substr($d['date'], 0, 10);
            $vs = $sv[$k] ?? [];
            echo '<tr><td>' . g_e($k) . '</td><td>' . g_e($d['meeting']) . '</td>'
               . '<td class="n">' . (int)$d['n'] . '件</td><td>';
            if ($vs) {
                foreach ($vs as $v) {
                    echo '<a href="https://www.youtube.com/watch?v=' . g_e($v['video_id']) . '"'
                       . ' rel="nofollow noopener" target="_blank">' . g_e($v['title']) . '</a><br>';
                }
            } else {
                echo '<span class="note">—</span>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
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
        echo '<h2 data-en="YouTube">公式YouTubeの新着</h2>'
           . '<p class="note">' . g_e($vs[0]['channel']) . ' の新しい動画です。'
           . 'サムネイルを押すまで YouTube を読み込みません。</p>'
           . g_video_grid($vs)
           . (!empty($L['youtube'])
              ? '<p style="margin-top:10px"><a href="' . g_e($L['youtube']) . '" rel="nofollow noopener" '
                . 'target="_blank">チャンネルをすべて見る</a></p>' : '');
    }

    // 公式Xの新着。**Xの埋め込みが読んでいる公開ページ**から取り込んだもの（APIキー不使用）。
    // 自分のログインで他人のタイムラインを取りに行くことはしない。
    $xps = g_xposts((int)$g['id'], 5);
    if ($xps || !empty($L['x'])) {
        $xu = !empty($L['x'])
            ? preg_replace('#^https?://(www\.)?twitter\.com/#', 'https://x.com/', $L['x']) : '';
        echo '<h2 data-en="X">公式Xの新着</h2>';
        if ($xps) {
            echo '<p class="note">本人の投稿だけを出しています（リポストは除いています）。'
               . '抜粋なので、全文はXでご確認ください。当サイトは要約していません。</p>';
            foreach ($xps as $p) { echo g_xpost($p); }
        } else {
            echo '<p class="note">まだ取り込めていません。</p>';
        }
        if ($xu) {
            echo '<p style="margin-top:8px"><a href="' . g_e($xu) . '" rel="nofollow noopener" '
               . 'target="_blank">Xですべて見る</a></p>';
        }
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
    $w = g_theme_where($slug, $args);
    $extra = ' AND s.kind=?';
    $kArgs = $args; $kArgs[] = $kind;
    if ($only) { $extra .= ' AND s.giin_id=?'; $kArgs[] = $only; }
    $total = (int)g_val("SELECT COUNT(*) FROM speech s WHERE $w$extra", $kArgs);

    // 立場ごとの件数（タブ用）。先に数えた表から読む
    $counts = [];
    foreach (array_keys(G_KINDS) as $k) {
        $counts[$k] = g_theme_n($slug, $k, $only);
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
    // このことがらに全国トラッカーがあれば、先に案内する（愛知の45人の発言だけでは法整備の進み方が分からない）
    foreach (g_trackers_for_theme($slug) as $tr) {
        $trSt = g_tracker_stats($tr['key']);
        if (!$trSt) { continue; }
        echo '<div class="panel"><p><b>国会トラッカー：</b>' . g_e($tr['name']) . 'は、愛知の45人に限らず'
           . '<b>全国の国会議員の発言</b>も追っています。質疑' . number_format((int)$trSt['q']) . '件・政府の答弁'
           . number_format((int)$trSt['gov']) . '件・取り上げた議員' . (int)$trSt['speakers'] . '人（'
           . g_e(g_date($trSt['last'])) . 'まで）。</p>'
           . '<p><a href="' . g_url('tracker/' . $tr['key']) . '">' . g_e($tr['name']) . 'は国会でどこまで来たか</a></p></div>';
    }
    echo g_stats_html(g_stats($slug),
        'このことがらに関わる、国や自治体が公表している数字です。'
      . '当サイトが公表資料から書き写したもので、要約も推計もしていません。');

    // このことがらの最近の動き（議案・省庁の報道発表）
    $nw = g_news($slug, 6);
    echo '<h2>このことがらの最近の動き</h2>'
       . '<p class="note">国会に出された議案と、省庁の報道発表です。'
       . '見出しと日付と発表元を出しています。中身はリンク先でご確認ください。</p>';
    if ($nw) {
        foreach ($nw as $n) { echo g_news_item($n); }
        echo '<p><a href="' . g_url('news') . '">ほかのことがらの動きも見る</a></p>';
    } else {
        // 0件でも節ごと消さない。「まだ無い」ことも情報なので、そう書く。
        echo '<div class="panel note">このことがらの新しい議案・報道発表はまだありません。'
           . '　<a href="' . g_url('news') . '">ほかのことがらの動きを見る</a></div>';
    }

    g_kind_tabs($kind, $counts, g_url('theme/' . $slug) . ($only ? '?g=' . $onlySlug : ''));

    // 議員別の件数
    if (!$only) {
        $rows = g_all("SELECT g.id,g.display,g.plain,g.slug,g.party,g.district,g.house,tc.n c
                       FROM theme_count tc JOIN giin g ON g.id=tc.giin_id
                       WHERE tc.theme=? AND tc.kind=? AND tc.giin_id>0 AND tc.n>0
                       ORDER BY tc.n DESC", [$slug, $kind]);
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

    // 議員名でも探せる。漢字・かな・一部・ローマ字slug（空白は無視）
    $nq = preg_replace('/[\s　]+/u', '', $q);
    $members = $nq === '' ? [] : g_all("SELECT plain, slug, party, house, district, n_q FROM giin
        WHERE REPLACE(REPLACE(plain,'　',''),' ','') LIKE ? OR REPLACE(REPLACE(display,'　',''),' ','') LIKE ?
           OR REPLACE(REPLACE(kana,'　',''),' ','') LIKE ? OR slug LIKE ?
        ORDER BY n_q DESC LIMIT 12", ['%' . $nq . '%', '%' . $nq . '%', '%' . $nq . '%', '%' . strtolower($nq) . '%']);

    g_head($q !== '' ? '「' . $q . '」の発言' : '議員名、またはことばで探す',
        $q !== '' ? '愛知の国会議員45人の発言から「' . $q . '」を含むものを ' . number_format($total) . '件見つけました。'
                  . ($members ? '名前に一致する議員は' . count($members) . '人です。' : '')
                  : '愛知の国会議員45人を名前で、国会発言をことばで検索できます。', '/search',
        ['q' => $q, 'noindex' => $q === '']);

    echo '<h1>' . ($q !== '' ? '「' . g_e($q) . '」の検索結果' : '議員名、またはことばで探す') . '</h1>';
    if ($members) {
        echo '<h2>名前に一致する議員</h2><div class="grid">';
        foreach ($members as $m) {
            echo '<a class="card" href="' . g_url($m['slug']) . '"><div class="nm">' . g_e($m['plain'])
               . ' <span class="pill">' . g_e($m['party']) . '</span></div>'
               . '<div class="n">' . g_e($m['house']) . ' ' . g_e(g_ku($m['district'])) . '　質疑 ' . number_format((int)$m['n_q']) . '件</div></a>';
        }
        echo '</div>';
    }

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
        echo '<div class="panel"><p>上の窓に<b>議員の名前</b>か<b>ことば</b>を入れてください。'
           . '例：<a href="' . g_url('search') . '?q=' . rawurlencode('福田徹') . '">福田徹</a>、'
           . '<a href="' . g_url('search') . '?q=' . rawurlencode('南海トラフ') . '">南海トラフ</a>、'
           . '<a href="' . g_url('search') . '?q=' . rawurlencode('年収の壁') . '">年収の壁</a>、'
           . '<a href="' . g_url('search') . '?q=' . rawurlencode('リニア') . '">リニア</a></p>'
           . '<p class="note">議員は<a href="' . g_url('list') . '">一覧</a>からも選べます。'
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
    echo '<h2>「' . g_e($q) . '」をふくむ発言</h2><p class="lead">該当 <b>' . number_format($total) . '件</b></p>';
    // 検索語が全国トラッカーの語なら、愛知の45人の外へも案内する
    $tr = g_tracker_for_query($q);
    if ($tr && g_tracker_stats($tr['key'])) {
        echo '<div class="panel note">「' . g_e($q) . '」は<a href="' . g_url('tracker/' . $tr['key']) . '">国会トラッカー：'
           . g_e($tr['name']) . '</a>で、愛知の45人に限らず全国の国会議員の質疑と政府の答弁を追っています。</div>';
    }
    $lArgs = $args; $lArgs[] = $per; $lArgs[] = $off;
    foreach (g_all("SELECT s.*,g.plain,g.slug,g.party FROM speech s JOIN giin g ON g.id=s.giin_id
                    WHERE $w ORDER BY s.date DESC LIMIT ? OFFSET ?", $lArgs) as $s) {
        g_speech($s, $q);
    }
    if ($total === 0) {
        echo '<div class="panel"><p>' . ($members ? 'このことばをふくむ発言はありません。上の議員名のほうをご覧ください。'
                                                : '見つかりませんでした。ことばを短くすると見つかることがあります。') . '</p></div>';
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
        $c = g_theme_n($t['slug']);
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
    // **1回の問い合わせで全部読む。** 以前はここで 45人×20ことがら＝900回
    // 走査していて3秒近くかかっていた（2026-09-15 実測）
    $cnt = [];
    foreach (g_all("SELECT theme, giin_id, n FROM theme_count WHERE kind='q' AND giin_id>0") as $r) {
        $cnt[$r['theme']][(int)$r['giin_id']] = (int)$r['n'];
    }
    echo '<div class="scroll"><table><tr><th>議員</th><th>会派</th>';
    foreach ($ts as $t) { echo '<th class="n">' . g_e($t['name']) . '</th>'; }
    echo '</tr>';
    foreach ($gs as $g) {
        echo '<tr><td><a href="' . g_url($g['slug']) . '">' . g_e($g['display']) . '</a></td>'
           . '<td><span class="pill">' . g_e($g['party']) . '</span></td>';
        foreach ($ts as $t) {
            $c = (int)($cnt[$t['slug']][$g['id']] ?? 0);
            echo '<td class="n">' . ($c ? '<a href="' . g_url('theme/' . $t['slug']) . '?g=' . $g['slug'] . '">'
                . number_format($c) . '</a>' : '<span class="note">-</span>') . '</td>';
        }
        echo '</tr>';
    }
    echo '</table></div>';
    g_foot();
}

/** AIの特集。**「何件言ったか」ではなく「何日・いくつの会議で持ち出したか」を主に見せる。**
 *  件数だけで並べると、一度の質疑で何度もAIと言った人が上に来てしまい、
 *  続けて取り組んでいる人が沈む。この道具が会派ページで書いているのと同じ理屈である。 */
function g_page_ai(): void
{
    $q      = g_ai_members('q');
    $gov    = g_ai_members('gov');
    $years  = g_ai_years('q');
    $topics = g_ai_topic_counts('q');
    $nq     = g_theme_n('ai', 'q');
    $totq   = (int)g_val("SELECT SUM(n_q) FROM giin");
    $pct    = $totq ? $nq / $totq * 100 : 0;
    $t      = g_theme('ai');

    $first = $years ? (int)$years[0]['y'] : 0;
    $lastY = $years ? (int)$years[count($years) - 1]['y'] : 0;
    $firstN = $years ? (int)$years[0]['n'] : 0;
    $lastN = $years ? (int)$years[count($years) - 1]['n'] : 0;

    $desc = '愛知の有権者の一票が当落に効く国会議員45人が、国会でAIに触れた質疑は'
          . number_format($nq) . '件です。'
          . 'いちばん多く語られている論点は「' . ($topics[0]['label'] ?? '') . '」で、'
          . 'だれが何日・いくつの会議で持ち出したかを並べています。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['AIをどう論じているか', '/ai']]),
        ['@type' => 'Article', '@id' => g_abs('ai'), 'url' => g_abs('ai'),
         'headline' => '愛知の国会議員はAIをどう論じているか',
         'description' => $desc, 'inLanguage' => 'ja',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head('愛知の国会議員はAIをどう論じているか', $desc, '/ai',
           ['jsonld' => $ld, 'image' => g_abs('img/og/ai-tokushu.png')]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › AIをどう論じているか</nav>';
    echo '<h1>愛知の国会議員はAIをどう論じているか</h1>'
       . '<p class="lead">愛知の有権者の一票が当落に効く国会議員' . (int)g_val('SELECT COUNT(*) FROM giin')
       . '人の質疑' . number_format($totq) . '件のうち、AIに触れたものは<b>'
       . number_format($nq) . '件</b>（' . number_format($pct, 1) . '%）です。'
       . 'ここでは<b>だれが何日・いくつの会議でAIを持ち出したか</b>を並べています。</p>';

    echo '<div class="kv">'
       . '<div class="c"><b>' . number_format($nq) . '</b><span>AIに触れた<br>質疑</span></div>'
       . '<div class="c"><b>' . count($q) . '</b><span>持ち出した<br>議員</span></div>'
       . '<div class="c"><b>' . ($topics[0]['n'] ?? 0) . '</b><span>最多の論点<br>'
       . g_e($topics[0]['label'] ?? '') . '</span></div>'
       . '<div class="c"><b>' . $lastN . '</b><span>' . $lastY . '年<br>（' . $first . '年は' . $firstN . '件）</span></div>'
       . '</div>';

    // ---- 伸び ----
    if ($years) {
        $max = max(array_map(fn($r) => (int)$r['n'], $years));
        $lastDate = (string)g_val("SELECT MAX(date) FROM speech s
            JOIN speech_theme st ON st.speech_id=s.speech_id AND st.theme='ai'");
        echo '<h2>AIに触れた質疑は増えている</h2>'
           . '<div class="scroll"><table><tr><th>年</th><th class="n">件数</th><th></th></tr>';
        foreach ($years as $r) {
            $partial = ((int)$r['y'] === $lastY);
            echo '<tr><td>' . g_e($r['y']) . '年'
               . ($partial ? ' <span class="note">' . g_e(substr($lastDate, 5, 2)) . '月まで</span>' : '')
               . '</td><td class="n">' . (int)$r['n'] . '</td>'
               . '<td><div class="bar"><i style="width:' . round((int)$r['n'] / $max * 100) . '%"></i></div></td></tr>';
        }
        echo '</table></div>'
           . '<p class="note">収録しているのは' . g_e(g_meta('range_from')) . '以降の会議録です。'
           . $lastY . '年は年の途中までの数字なので、前の年とそのまま比べられません。</p>';
    }

    // ---- 論点 ----
    if ($topics && $topics[0]['n'] > 0) {
        $max = (int)$topics[0]['n'];
        echo '<h2>AIは何と一緒に語られているか</h2>'
           . '<p>AIに触れた質疑' . number_format($nq) . '件を、一緒に出てくる言葉で分けたものです。'
           . 'ひとつの質疑が複数に入ります（AIと学校と規制を一度に話すことがあるためです）。</p>'
           . '<div class="scroll"><table><tr><th>論点</th><th class="n">件数</th><th></th></tr>';
        foreach ($topics as $r) {
            if (!$r['n']) { continue; }
            echo '<tr><td>' . g_e($r['label'])
               . '<br><span class="note">' . g_e(implode('・', $r['words'])) . '</span></td>'
               . '<td class="n">' . (int)$r['n'] . '</td>'
               . '<td><div class="bar"><i style="width:' . round((int)$r['n'] / $max * 100) . '%"></i></div></td></tr>';
        }
        echo '</table></div>';
    }

    // ---- だれが持ち出しているか ----
    if ($q) {
        echo '<h2>だれがAIを持ち出しているか</h2>'
           . '<p><b>「日数」で並べています。</b>同じ日の質疑で何度AIと言っても1日です。'
           . '別の日、別の委員会で繰り返し持ち出しているなら、続けて取り組んでいる印になります。</p>'
           . '<div class="scroll"><table>'
           . '<tr><th>議員</th><th>会派</th><th class="n">日数</th><th class="n">件数</th>'
           . '<th class="n">会議の種類</th><th>最後に触れた日</th></tr>';
        foreach ($q as $r) {
            echo '<tr><td><a href="' . g_url($r['slug']) . '">' . g_e($r['plain']) . '</a>'
               . '<br><span class="note">' . g_e($r['house']) . '</span></td>'
               . '<td><span class="pill">' . g_e($r['party']) . '</span></td>'
               . '<td class="n"><b>' . (int)$r['days'] . '</b></td>'
               . '<td class="n">' . (int)$r['n'] . '</td>'
               . '<td class="n">' . (int)$r['meetings'] . '</td>'
               . '<td class="note">' . g_e($r['last']) . '</td></tr>';
        }
        echo '</table></div>';
    }

    // ---- 答える側 ----
    if ($gov) {
        echo '<h2>AIについて答弁した議員</h2>'
           . '<p>大臣・副大臣・政務官として、AIに関する質問に<b>答えた側</b>です。'
           . '質問する側とは立場が違うので、上の表とは別に出しています。</p>'
           . '<div class="scroll"><table><tr><th>議員</th><th>会派</th>'
           . '<th class="n">日数</th><th class="n">件数</th></tr>';
        foreach ($gov as $r) {
            echo '<tr><td><a href="' . g_url($r['slug']) . '">' . g_e($r['plain']) . '</a></td>'
               . '<td><span class="pill">' . g_e($r['party']) . '</span></td>'
               . '<td class="n">' . (int)$r['days'] . '</td>'
               . '<td class="n">' . (int)$r['n'] . '</td></tr>';
        }
        echo '</table></div>';
    }

    // ---- 発言そのもの ----
    echo '<h2>発言そのものを読む</h2>'
       . '<p>ここに出した数字のもとになった発言は、'
       . '<a href="' . g_url('theme/ai') . '">AI（人工知能）のことがらのページ</a>から、'
       . '日付・会議名・会議録へのリンクつきで読めます。'
       . '拾っている語は' . g_e(implode('、', $t['words'] ?? [])) . 'です。</p>';

    $nw = g_news('ai', 6);
    if ($nw) {
        echo '<h2>AIに関する議案と報道発表</h2>';
        foreach ($nw as $n) { echo g_news_item($n, true); }
    }

    echo '<h2>この数字の読み方</h2>'
       . '<div class="panel note">質疑ができるのは、その日その委員会で質問に立った議員だけです。'
       . '大臣や委員長を務めている間は質問する側に回れないので、'
       . '<b>件数の差はそのまま熱心さの差ではありません。</b>'
       . 'また、ここで数えているのは会議録に語が出てきたかどうかで、'
       . 'AIに賛成か反対かは判定していません。'
       . '<br><a href="' . g_url('about') . '">この道具の作り方</a></div>';

    g_foot();
}

// ---------------------------------------------------------------- 国会トラッカー（全国）

/** トラッカーの一覧。ことがら一覧と違い、**全国の発言**を追っているものだけ。 */
function g_page_trackers(): void
{
    $desc = '議員立法で法整備が動いていることがらについて、愛知の45人に限らず全国の国会議員の質疑と政府の答弁を'
          . '会議録から機械的に集め、日付順に並べています。';
    g_head('国会トラッカー', $desc, '/tracker', ['jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], ['国会トラッカー', '/tracker']])])]);
    $rows = []; $sq = 0; $sg = 0;
    foreach (g_trackers() as $t) {
        $st = g_tracker_stats($t['key']);
        $rows[] = [$t, $st];
        if ($st) { $sq += (int)$st['q']; $sg += (int)$st['gov']; }
    }
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 国会トラッカー</nav>'
       . '<p class="kick">Diet Tracker</p><h1>国会トラッカー</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p>'
       . '<div class="kv">'
       . '<div class="c"><b>' . count($rows) . '</b><span>追っていることがら</span></div>'
       . '<div class="c"><b>' . number_format($sq) . '</b><span>全国の議員の質疑</span></div>'
       . '<div class="c"><b>' . number_format($sg) . '</b><span>政府の答弁</span></div>'
       . '</div></section><div class="grid">';
    foreach ($rows as [$t, $st]) {
        echo '<a class="card" href="' . g_url('tracker/' . $t['key']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="n">' . ($st ? '質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件<br><span class="note">'
                                  . g_e(g_date($st['last'])) . 'まで</span>' : '準備中') . '</div></a>';
    }
    echo '</div>';
    g_foot();
}

/** ひとつのことがらの全国トラッカー。
 *  **愛知の45人の発言だけでは「法整備はどこまで来たか」が分からない**ので、
 *  この画面だけは会議録の全発言（質疑と政府の答弁）を集めて出す。
 *  要約はしない。政府の答弁も質疑も、語を含む文をそのまま抜いて会議録へつなぐ。 */
function g_page_tracker(string $key, int $page): void
{
    $t = g_tracker($key);
    $st = $t ? g_tracker_stats($key) : [];
    if (!$t || !$st) { http_response_code(404); g_head('見つかりません', '', '/tracker', ['noindex' => true]);
        echo '<h1>そのトラッカーはありません</h1>'; g_foot(); return; }
    $words = $t['words'];
    $theme = !empty($t['theme']) ? g_theme($t['theme']) : null;
    $kind = (string)($_GET['k'] ?? 'all');
    if (!in_array($kind, ['all', 'q', 'gov', 'ref'], true)) { $kind = 'all'; }
    $years = g_tracker_years($key);
    $qs = g_tracker_speakers($key, 'q');
    $govs = g_tracker_speakers($key, 'gov');
    $latestGov = g_tracker_list($key, 'gov', 3);
    $latestQ = g_tracker_list($key, 'q', 3);
    $updated = g_meta('tracker_' . $key . '_at');
    $title = $t['name'] . 'は国会でどこまで来たか';
    $desc = $t['name'] . 'について、全国の国会議員の質疑' . number_format((int)$st['q']) . '件と政府の答弁'
          . number_format((int)$st['gov']) . '件を国会会議録から集めました（' . g_date($st['first']) . '〜'
          . g_date($st['last']) . '）。だれが質問し、政府が何と答えたかを日付と会議録リンクで並べています。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['国会トラッカー', '/tracker'], [$t['name'], '/tracker/' . $key]]),
        ['@type' => 'Article', '@id' => g_abs('tracker/' . $key), 'url' => g_abs('tracker/' . $key),
         'headline' => $title, 'description' => $desc, 'inLanguage' => 'ja',
         'dateModified' => substr($updated, 0, 10),
         'about' => ['@type' => 'Thing', 'name' => $t['name']],
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head($title, $desc, '/tracker/' . $key, ['jsonld' => $ld, 'image' => g_abs('img/og/tracker-' . $key . '.png')]);

    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('tracker') . '">国会トラッカー</a> › ' . g_e($t['name']) . '</nav>';
    echo '<p class="kick">Diet Tracker</p><h1>' . g_e($title) . '</h1><p class="lead">' . g_e($t['lead']) . '</p>';
    // 自殺など、読む人の安全に関わることがらは、数字より先に相談先を出す（trackers.json の notice）
    if (!empty($t['notice'])) {
        $nl = is_array($t['links'] ?? null) && $t['links'] ? $t['links'][0] : null;
        echo '<div class="panel" style="border-color:#0a9a8f"><p style="margin:0">' . g_e($t['notice'])
           . ($nl ? '　<a href="' . g_e($nl['url']) . '" rel="noopener" target="_blank">' . g_e($nl['label']) . '</a>' : '') . '</p></div>';
    }
    echo '<div class="kv">'
       . '<div class="c"><b>' . number_format((int)$st['q']) . '</b><span>議員の質疑</span></div>'
       . '<div class="c"><b>' . number_format((int)$st['gov']) . '</b><span>政府の答弁</span></div>'
       . '<div class="c"><b>' . (int)$st['speakers'] . '</b><span>取り上げた<br>議員</span></div>'
       . '<div class="c"><b>' . g_e(substr((string)$st['last'], 0, 4)) . '</b><span>最新 ' . g_e(substr((string)$st['last'], 5)) . '<br>（最初 ' . g_e(substr((string)$st['first'], 0, 7)) . '）</span></div>'
       . '</div></section>';
    echo '<p class="note">集め方：「' . g_e(implode('」「', $words)) . '」を含む発言を、国立国会図書館の国会会議録検索システムから'
       . g_e(g_date($t['from'])) . '以降ぶん機械的に集めたものです（愛知の45人に限りません）。'
       . '立場（質疑・答弁）は発言の冒頭の話者表記から機械的に分けています。要約も賛否の判定もしていません。'
       . ($updated !== '' ? '最終取得 ' . g_e(substr($updated, 0, 16)) . '。' : '') . '</p>';

    // ---- 政府の最新の答え ----
    if ($latestGov) {
        echo '<h2 data-en="Government">いま、政府は何と答えているか</h2>'
           . '<p>大臣・副大臣・政務官・政府参考人としての<b>直近の答弁</b>です。語を含む文をそのまま抜いています。'
           . '答弁は質問への答えなので、「この回の会議録」で質問とあわせて読んでください。</p>';
        foreach ($latestGov as $s) { g_tracker_speech($s, $words); }
    }
    if ($latestQ) {
        echo '<h2 data-en="Latest">直近の質疑</h2>';
        foreach ($latestQ as $s) { g_tracker_speech($s, $words); }
    }

    // ---- 論点（人が書いた枠。数字は入れない） ----
    if (!empty($t['questions'])) {
        echo '<h2>会議録に繰り返し出てくる論点</h2><div class="panel"><ul>';
        foreach ($t['questions'] as $qq) { echo '<li>' . g_e($qq) . '</li>'; }
        echo '</ul><p class="note">当サイトが会議録を読んで整理した問いです。どの立場が正しいかは判定していません。</p></div>';
    }

    // ---- 年ごと ----
    if ($years) {
        $max = max(array_map(fn($r) => (int)$r['n'], $years));
        $lastY = (int)$years[count($years) - 1]['y'];
        echo '<h2>年ごとの件数</h2><div class="scroll"><table><tr><th>年</th><th class="n">質疑</th><th class="n">答弁</th><th></th></tr>';
        foreach ($years as $r) {
            echo '<tr><td>' . g_e($r['y']) . '年'
               . ((int)$r['y'] === $lastY ? ' <span class="note">' . g_e(substr((string)$st['last'], 5, 2)) . '月まで</span>' : '')
               . '</td><td class="n">' . (int)$r['q'] . '</td><td class="n">' . (int)$r['gov'] . '</td>'
               . '<td><div class="bar"><i style="width:' . round((int)$r['n'] / max(1, $max) * 100) . '%"></i></div></td></tr>';
        }
        echo '</table></div><p class="note">最後の年は年の途中までの数字なので、前の年とそのまま比べられません。'
           . '国会の会期や委員会の開かれ方でも件数は変わります。</p>';
    }

    // ---- だれが取り上げているか ----
    if ($qs) {
        echo '<h2 data-en="Who">だれが国会で取り上げているか</h2>'
           . '<p><b>「日数」で並べています。</b>同じ日の質疑で何度言っても1日です。別の日、別の委員会で繰り返し'
           . '持ち出しているなら、続けて取り組んでいる印になります。名前は会議録の表記のままです。</p>'
           . '<div class="scroll"><table><tr><th>議員</th><th>会派（会議録の表記）</th><th class="n">日数</th><th class="n">件数</th>'
           . '<th class="n">会議の種類</th><th>最後に触れた日</th></tr>';
        foreach ($qs as $r) {
            echo '<tr><td>' . (!empty($r['slug'])
                    ? '<a href="' . g_url($r['slug']) . '">' . g_e($r['plain']) . '</a> <span class="pill">愛知</span>'
                    : g_e($r['speaker']))
               . '<br><span class="note">' . g_e($r['house']) . '</span></td>'
               . '<td><span class="note">' . g_e($r['kaiha'] ?? '') . '</span></td>'
               . '<td class="n"><b>' . (int)$r['days'] . '</b></td><td class="n">' . (int)$r['n'] . '</td>'
               . '<td class="n">' . (int)$r['meetings'] . '</td><td class="note">' . g_e($r['last']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    $refs = g_tracker_speakers($key, 'ref');
    if ($refs) {
        echo '<h2>参考人として意見を述べた人</h2><p>委員会に呼ばれて意見を述べた<b>議員ではない人</b>です（会議録の表記のまま）。'
           . '現場の医療機関や研究者の声はここに出ます。</p>'
           . '<div class="scroll"><table><tr><th>名前</th><th>肩書（会議録の表記）</th><th class="n">日数</th><th class="n">件数</th><th>日付</th></tr>';
        foreach ($refs as $r) {
            echo '<tr><td>' . g_e($r['speaker']) . '</td><td><span class="note">' . g_e($r['position'] ?? '') . '</span></td>'
               . '<td class="n">' . (int)$r['days'] . '</td><td class="n">' . (int)$r['n'] . '</td><td class="note">' . g_e($r['last']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    if ($govs) {
        echo '<h2>答えた側</h2><p>大臣・副大臣・政務官・政府参考人として<b>答えた側</b>です。質問する側とは立場が違うので別に出しています。</p>'
           . '<div class="scroll"><table><tr><th>名前</th><th>役職（最後の答弁時）</th><th class="n">日数</th><th class="n">件数</th></tr>';
        foreach ($govs as $r) {
            echo '<tr><td>' . g_e($r['speaker']) . '</td><td><span class="note">' . g_e($r['position'] ?? '') . '</span></td>'
               . '<td class="n">' . (int)$r['days'] . '</td><td class="n">' . (int)$r['n'] . '</td></tr>';
        }
        echo '</table></div>';
    }

    // ---- 愛知の議員は ----
    $aichi = array_values(array_filter($qs, fn($r) => !empty($r['slug'])));
    echo '<h2>愛知の議員は</h2><div class="panel">';
    if ($aichi) {
        echo '<p>愛知の有権者の一票が当落に効く45人のうち、' . g_e($t['short'] ?? $t['name']) . 'を国会で取り上げているのは次の議員です。</p><ul>';
        foreach ($aichi as $r) {
            echo '<li><a href="' . g_url($r['slug']) . '">' . g_e($r['plain']) . '</a>（' . g_e($r['house']) . '）　'
               . (int)$r['n'] . '件・' . (int)$r['days'] . '日、最後は' . g_e(g_date($r['last']))
               . ($theme ? '　<a href="' . g_url('theme/' . $theme['slug']) . '?g=' . $r['slug'] . '">発言を読む</a>' : '') . '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>愛知の45人には、このことがらの質疑がまだありません。</p>';
    }
    if ($theme) {
        echo '<p class="note">愛知の45人の発言は<a href="' . g_url('theme/' . $theme['slug']) . '">ことがら「'
           . g_e($theme['name']) . '」</a>にまとめています（こちらは' . g_e(g_meta('range_from')) . '以降）。</p>';
    }
    echo '</div>';
    // 公的な案内。**当サイトで一覧を作り直さない。** 国が事業者一覧・相談窓口・制度説明を出しているものはそこへ渡す
    if (!empty($t['links'])) {
        echo '<h2>制度そのものを調べるなら</h2><div class="panel"><ul>';
        foreach ($t['links'] as $lk) {
            echo '<li><a href="' . g_e($lk['url']) . '" rel="noopener" target="_blank">' . g_e($lk['label']) . '</a></li>';
        }
        echo '</ul><p class="note">事業者の一覧や相談窓口は国が公表しているものが最新です。当サイトは会議録の側だけを扱います。</p></div>';
    }
    if ($theme) {
        echo g_stats_html(g_stats($theme['slug']), 'このことがらに関わる、国や自治体が公表している数字です。当サイトが公表資料から書き写したもので、要約も推計もしていません。');
    }

    // ---- 発言のあゆみ ----
    $per = 20; $off = ($page - 1) * $per;
    $total = g_tracker_count($key, $kind === 'all' ? '' : $kind);
    $base = g_url('tracker/' . $key);
    echo '<h2 data-en="Timeline">発言のあゆみ</h2><p class="lead">';
    foreach (['all' => '全部', 'q' => '質疑', 'gov' => '答弁', 'ref' => '参考人'] as $k => $label) {
        $c = g_tracker_count($key, $k === 'all' ? '' : $k);
        if ($c === 0 && $k !== 'all') { continue; }
        echo '<a class="pill' . ($k === $kind ? '' : ' gray') . '" href="' . g_e($base . '?k=' . $k) . '#ayumi">' . $label . ' ' . number_format($c) . '</a> ';
    }
    echo '<span class="note">新しい順</span></p><div id="ayumi"></div>';
    foreach (g_tracker_list($key, $kind === 'all' ? '' : $kind, $per, $off) as $s) { g_tracker_speech($s, $words); }
    g_pager($page, $total, $per, $base . '?k=' . $kind);

    echo '<h2>この数字の読み方</h2>'
       . '<div class="panel note">数えているのは会議録に語が出てきた発言の数で、賛成・反対は判定していません。'
       . '質疑ができるのはその日その委員会で質問に立った議員だけなので、<b>件数の差はそのまま熱心さの差ではありません。</b>'
       . '会議録は国会の会議から数日〜数週間後に公開されるため、直近の審議はまだ載っていないことがあります。'
       . '<br><a href="' . g_url('about') . '">この道具の作り方</a></div>';

    // このことがらを歌にした PV（Kurage 動画ページの再生窓）。**会議録とは別物**なので一番下に置き、断りを添える
    $v = is_array($t['video'] ?? null) ? $t['video'] : null;
    if ($v && preg_match('/^[a-z0-9]{8,32}$/', (string)($v['job_id'] ?? ''))) {
        $kv = 'https://kurage.exbridge.jp/kuragev.php?id=' . $v['job_id'];
        echo '<h2>この問題を歌にしました</h2><div class="panel">'
           . '<p><b>' . g_e($v['title'] ?? '') . '</b></p>'
           . '<div style="position:relative;width:100%;max-width:960px;aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden">'
           . '<iframe src="' . g_e($kv) . '&embed=1" title="' . g_e($v['title'] ?? '') . '" loading="lazy" allowfullscreen '
           . 'style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>'
           . '<p class="note">' . g_e($v['note'] ?? '') . '　<a href="' . g_e($kv) . '" target="_blank" rel="noopener">歌詞つきの動画ページで見る</a></p></div>';
    }
    g_foot();
}

function g_page_about(): void
{
    $faq = [
        ['このサイトは何をするものですか？',
         '愛知の国会議員が、国会でいつ・どの会議で何を質問したかを引くための道具です。'
         . '発言の抜粋と、国会会議録へのリンクを並べています。要約や論評はしません。'],
        ['だれを収録していますか？',
         '愛知の有権者の一票が当落に効く議員を収録しています。衆議院 愛知1区〜16区、'
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
        ['なぜ議員によってページの中身に差があるのですか？',
         '発言の収録は45人全員が同じです。同じAPIから同じ処理で取り込み、同じ規則で'
         . '分類しています。差が出ているのは公式サイト・SNSのリンクで、これは人が1人ずつ'
         . '調べて確認する手作業のため、まだ全員ぶん揃っていません。'
         . '取引の有無で選んだものではありませんが、偏っているのは事実です。'
         . '質疑の件数が多い順に埋めていきます。'],
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
       . '<p><b>愛知の有権者の一票が当落に効く議員</b>を収録しています。</p><ul>'
       . '<li>衆議院 愛知1区〜16区</li>'
       . '<li>衆議院 比例代表 東海ブロック</li>'
       . '<li>参議院 愛知県選挙区</li></ul>'
       . '<p>参議院の比例代表は全国共通なので入れていません。'
       . 'この線引きなら党派や住所で人を選り分ける必要がなく、'
       . '「なぜこの人が入っていて、あの人が入っていないのか」に一言で答えられます。</p>'
       . '<p class="note">衆議院の比例代表は政党名で投票する仕組みなので、'
       . '比例東海の議員の名前を投票用紙に書くわけではありません。'
       . 'それでも愛知の有権者が投じた票が東海ブロックの議席配分に効くので、'
       . '「一票が当落に効く議員」として収録しています。</p>'
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

       . '<h2 id="insight">活動の要約の作り方</h2>'
       . '<p>議員と会派のページに出している要約は、そのページの材料'
       . '——国会での質疑、提出者に名前がある議案、本人の公式Xと公式YouTube——を'
       . 'AIに渡して書かせています。渡しているのは'
       . '<b>国会会議録と各省庁が公開している情報だけ</b>で、'
       . 'ここにしかない情報を渡してはいません。</p>'
       . '<p><b>件数・日付・割合はAIに書かせず、会議録の集計から出しています。</b>'
       . '文章のうまさで数え間違いが通ってしまうのを避けるためです。'
       . '人物の評価や賛否の判定が混ざった文も同じように弾いていて、'
       . 'どうしても条件を満たす文にならなかったページには要約を出していません。</p>'

       . '<h2 id="links">本人の発信について</h2>'
       . '<div class="alert"><b>いま、ここは全員ぶん揃っていません。</b><br>'
       . '公式サイト・X・YouTube のリンクが入っているのは、45人中 '
       . '<b>' . (int)g_links_count() . '人</b>です。'
       . '公式サイトのURLを人が1人ずつ調べて確認する作業なので、必ず誰かが最初になります。'
       . '最初の1人は、この機能を作るときの見本にした議員です。'
       . '<b>取引の有無で選んだのではありませんが、結果として偏っているのは事実なので、'
       . 'そのまま書いておきます。</b></div>'
       . '<p>埋める順番は<b>質疑の件数が多い順</b>にします。'
       . '恣意が入らないよう機械的に決めた順番で、党派や取引とは無関係です。'
       . '入っていない議員のページには、議院の公式プロフィールへのリンクだけが出ます。</p>'
       . '<p>議員ページに、公式サイト・X・YouTube などへのリンクを置いています。'
       . '<b>本人の公式サイトに掲載されているリンクだけ</b>を採り、検索結果から拾ったものは載せません。'
       . 'どこで確認したかもページに書いています。なりすましのアカウントを'
       . '本人のものとして出さないためです。見つからない方は空のままにしています。</p>'
       . '<p>公式YouTubeの新着は、チャンネルのRSSからタイトル・日付・サムネイルだけを取り込んでいます。'
       . '<b>サムネイルを押すまで YouTube を読み込みません。</b>再生は YouTube の公式埋め込みに任せており、'
       . '当サイトは動画の中身を持っていません。要約もしません。</p>'
       . '<p>公式Xの新着は、<b>Xの埋め込みウィジェットが読んでいるのと同じ公開ページ</b>から'
       . '取り込んでいます。開発者APIもキーも使っていません。'
       . '当サイトのログインで他人のタイムラインを取りに行くこともしていません。</p>'
       . '<p>持っているのは本文・日付・投稿IDだけで、画像も動画も持ちません。'
       . '出しているのは抜粋なので、全文はXでご確認ください。要約はしていません。'
       . '<b>リポストは本人の言葉ではないので、既定では出していません。</b></p>'

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
       . '<p><b>株式会社エクスブリッジ</b>（名古屋市瑞穂区）。名古屋のAIシステム開発会社です。'
       . '第4世代のテーマとして、政治・社会の課題に対応する情報技術をAIを活用して提供しています。'
       . '<a href="https://exbridge.jp/?ref=giin-about">exbridge.jp</a> ／ '
       . '<a href="https://xb4g.com/">xb4g.com</a></p>'

       . '<h2>お金の話（隠さずに書きます）</h2>'
       . '<p>この道具は当社の製品でもあります。同じものを'
       . '<a href="https://kappstore.exbridge.jp/app.php?id=dd868beae043026b&ref=giin-about">'
       . '買い切り55,000円（税込）</a>で販売していて、議員マスタを変えれば別の都道府県でも作れます。'
       . 'このサイトはその実物でもあります。</p>'
       . '<p>また当社は、'
       . '<a href="https://exbridge.jp/solution/seiji-dantai/?ref=giin-about">'
       . '政治団体・後援会・議員事務所のIT</a>を業務ごとに安くする仕事や、'
       . '<a href="https://exbridge.jp/ai-it-komon.html?ref=giin-about">AI-IT顧問契約</a>を'
       . 'しています。ただし<b>「政治活動の事務を安くする」範囲に限って</b>支援します。'
       . '特定の政党・候補者を支援することはありません。</p>'
       . '<p class="note">念のため書いておきます。'
       . '<b>収録する議員を党派で選んでいませんし、取引の有無でも選んでいません。</b>'
       . '収録の線引きは「愛知の有権者の一票が当落に効く議員」という機械的なもの一本で、'
       . '誰かと取引があってもなくても、<b>発言の件数も中身も変わりません</b>。'
       . 'もし「うちの発言を消してほしい」「もっと目立たせてほしい」と言われても、'
       . 'お金の有無にかかわらずお断りします（明らかな誤りの訂正は別です）。</p>'
       . '<p class="note">一方で、'
       . '<a href="#links">公式サイト・SNSのリンク</a>は手作業なので、'
       . 'いまは' . (int)g_links_count() . '人ぶんしか入っておらず、'
       . '<b>見た目の充実度に差が出ています</b>。'
       . 'これは取引で決めたものではありませんが、偏っているのは事実です。'
       . '質疑の件数が多い順に埋めていきます。</p>'
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

/** 数字の読み方。**どの政党のページにも同じ文章を出す。**
 *  国民民主のページにだけ有利な注記、自民のページにだけ不利な注記、を作らない。 */
function g_party_caveat(): string
{
    return '<div class="panel note"><b>この数字の読み方</b><br>'
        . '質疑の件数は、<b>与党か野党かでほとんど決まります。</b>'
        . '与党の議員は大臣・副大臣・政務官として答弁する側に回り、委員長として議事を'
        . '整理する側に回ります。質問する立場ではないので、質疑の件数は少なくなります。'
        . '実際、自民24人の発言3,693件の内訳は議事整理59%・答弁24%で、質疑は17%です'
        . '（24人のうち12人が答弁、6人が委員長として議事整理をしています）。'
        . '一方、野党の議員は発言のほぼ全部が質疑になります。'
        . '<br><b>つまり件数の差は、熱心さの差ではなく立場の差です。</b>'
        . 'このサイトは件数を数えるだけで、良し悪しの判定はしません。'
        . '数字を比べるときは、同じ立場の議員どうしで比べてください。</div>';
}

function g_page_parties(): void
{
    $ps = g_parties();
    $tot = array_sum(array_column($ps, 'n'));
    $totq = array_sum(array_column($ps, 'q'));
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['会派から見る', '/party']]),
        ['@type' => 'CollectionPage', '@id' => g_abs('party'), 'url' => g_abs('party'),
         'name' => '会派から見る — 愛知の国会議員' . $tot . '人',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head('会派から見る', '愛知の国会議員' . $tot . '人を会派ごとにまとめ、人数と質疑・答弁・'
        . '議事整理の件数を並べています。件数の差は立場の差なので、読み方も併せて書いています。',
        '/party', ['jsonld' => $ld, 'image' => g_abs('img/og/party.png')]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 会派から見る</nav>';
    echo '<h1>会派から見る</h1>'
       . '<p class="lead">愛知の国会議員' . $tot . '人を会派ごとにまとめました。'
       . '人数と、質疑・答弁・議事整理の件数です。</p>';
    echo g_party_caveat();

    echo '<div class="scroll"><table><tr><th>会派</th><th class="n">人数</th>'
       . '<th class="n">質疑</th><th class="n">1人あたり</th>'
       . '<th class="n">答弁</th><th class="n">議事整理</th>'
       . '<th>人数と質疑の割合</th></tr>';
    foreach ($ps as $p) {
        $sn = g_party_slug($p['party']);
        $pn = $tot ? (int)$p['n'] / $tot * 100 : 0;
        $pq = $totq ? (int)$p['q'] / $totq * 100 : 0;
        echo '<tr><td><a href="' . g_url('party/' . $sn) . '">' . g_e($p['party']) . '</a></td>'
           . '<td class="n">' . (int)$p['n'] . '</td>'
           . '<td class="n"><b>' . number_format((int)$p['q']) . '</b></td>'
           . '<td class="n">' . number_format((int)$p['q'] / max(1, (int)$p['n'])) . '</td>'
           . '<td class="n">' . number_format((int)$p['gov']) . '</td>'
           . '<td class="n">' . number_format((int)$p['chair']) . '</td>'
           . '<td><div class="share">'
           . '<i style="width:' . round($pn, 1) . '%;background:#8fa3b3">人 ' . round($pn) . '%</i>'
           . '<i style="width:' . round(100 - $pn, 1) . '%;background:#eef2f4"></i></div>'
           . '<div class="share">'
           . '<i style="width:' . round($pq, 1) . '%;background:#0a9a8f">質疑 ' . round($pq) . '%</i>'
           . '<i style="width:' . round(100 - $pq, 1) . '%;background:#e6f4f2"></i></div></td></tr>';
    }
    echo '</table></div>';
    echo '<p class="note">上段が45人に占める人数の割合、下段が全質疑に占める割合です。'
       . '両者がずれている会派ほど、与党・野党の立場の違いが効いています。</p>';
    g_foot();
}

function g_page_party(string $slug): void
{
    $p = g_party($slug);
    if (!$p) {
        http_response_code(404);
        g_head('見つかりません', '', '/party', ['noindex' => true]);
        echo '<h1>その会派は収録していません</h1>'; g_foot(); return;
    }
    $name = $p['party'];
    $tot = (int)g_val('SELECT COUNT(*) FROM giin');
    $totq = (int)g_val("SELECT SUM(n_q) FROM giin");
    $pn = $tot ? (int)$p['n'] / $tot * 100 : 0;
    $pq = $totq ? (int)$p['q'] / $totq * 100 : 0;

    $desc = $name . 'の愛知関係の国会議員' . (int)$p['n'] . '人（45人中'
          . round($pn) . '%）が、国会で行った質疑は' . number_format((int)$p['q']) . '件'
          . '（全体の' . round($pq) . '%）です。件数の差は立場の差なので、読み方も書いています。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['会派から見る', '/party'], [$name, '/party/' . $slug]]),
        ['@type' => 'CollectionPage', '@id' => g_abs('party/' . $slug),
         'url' => g_abs('party/' . $slug), 'name' => $name . 'の国会発言',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head($name . 'の国会発言（愛知関係' . (int)$p['n'] . '人）', $desc, '/party/' . $slug,
           ['jsonld' => $ld, 'image' => g_abs('img/og/party-' . $slug . '.png')]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('party') . '">会派から見る</a> › ' . g_e($name) . '</nav>';
    echo '<h1>' . g_e($name) . '</h1>'
       . '<p class="lead">愛知の有権者の一票が当落に効く国会議員' . $tot . '人のうち、'
       . g_e($name) . 'は<b>' . (int)$p['n'] . '人</b>です。</p>';

    echo '<div class="kv">'
       . '<div class="c"><b>' . (int)$p['n'] . '</b><span>人<br>45人中 ' . round($pn) . '%</span></div>'
       . '<div class="c"><b>' . number_format((int)$p['q']) . '</b><span>質疑<br>全体の ' . round($pq) . '%</span></div>'
       . '<div class="c"><b>' . number_format((int)$p['q'] / max(1, (int)$p['n'])) . '</b><span>1人あたり<br>の質疑</span></div>'
       . '<div class="c"><b>' . number_format((int)$p['gov']) . '</b><span>答弁<br>大臣・副大臣・政務官</span></div>'
       . '<div class="c"><b>' . number_format((int)$p['chair']) . '</b><span>議事整理<br>委員長・議長</span></div>'
       . '</div>';

    if (abs($pq - $pn) >= 5) {
        $up = $pq > $pn;
        echo '<div class="panel"><p><b>人数の割合は' . round($pn) . '%、質疑の割合は'
           . round($pq) . '%です。</b>'
           . ($up ? '人数の割に質疑が多いことになります。'
                  : '人数の割に質疑が少ないことになります。')
           . '<b>これは熱心さではなく立場で決まります。</b>下の「この数字の読み方」をご覧ください。</p></div>';
    }
    echo g_party_caveat();

    echo g_insight_html(g_insight('party', $name), 'この会派がいま取り組んでいること');

    // この会派の議員が提出した議案
    $gian = g_party_gian($name, 6);
    if ($gian) {
        echo '<h2>' . g_e($name) . 'の議員が提出した議案</h2>'
           . '<p class="note">提出者の筆頭に名前が出ている議案です。'
           . '「外○名」として名を連ねた分は数えていません。'
           . '<b>内閣が提出する法案（閣法）には議員の名前が出ない</b>ため、'
           . '与党の会派ではここが空になります。</p>';
        foreach ($gian as $n) { echo g_news_item($n); }
    }

    // よく質疑していることがらの最近の動き（議案が無い会派でも出る）
    [$pnews, $pth] = g_party_news($name, 3, 6);
    if ($pnews) {
        $labels = [];
        foreach ($pth as $r) { $t = g_theme($r['theme']); if ($t) { $labels[] = $t['name']; } }
        echo '<h2>よく質疑していることがらの、最近の動き</h2>'
           . '<p class="note">' . g_e($name) . 'の質疑が多い'
           . '「' . g_e(implode('」「', $labels)) . '」について、'
           . '国会に出された議案と省庁の報道発表を新しい順に出しています。'
           . '<b>この会派に関するニュースではありません。</b></p>';
        foreach ($pnews as $n) { echo g_news_item($n, true); }
        echo '<p class="more"><a class="btn o" href="' . g_url('news') . '">最近の動きをまとめて見る</a></p>';
    }

    echo '<h2>' . g_e($name) . 'の議員</h2><div class="grid">';
    foreach (g_all('SELECT * FROM giin WHERE party=? ORDER BY n_q DESC', [$name]) as $g) {
        echo g_card($g);
    }
    echo '</div>';

    // よく触れていることがら（先に数えた表から）
    $th = g_all("SELECT tc.theme, SUM(tc.n) n FROM theme_count tc JOIN giin g ON g.id=tc.giin_id
                 WHERE tc.kind='q' AND tc.giin_id>0 AND g.party=?
                 GROUP BY tc.theme ORDER BY n DESC LIMIT 8", [$name]);
    if ($th) {
        echo '<h2>よく触れていることがら</h2><div class="grid">';
        foreach ($th as $r) {
            $t = g_theme($r['theme']);
            if (!$t) { continue; }
            echo '<a class="card" href="' . g_url('theme/' . $t['slug']) . '">'
               . '<div class="nm">' . g_e($t['name']) . '</div>'
               . '<div class="n">' . number_format((int)$r['n']) . '件</div></a>';
        }
        echo '</div><p class="note">語がその発言に出てきた回数です。賛成・反対の判定はしていません。</p>';
    }

    echo '<p style="margin-top:20px"><a class="btn" href="' . g_url('party') . '">'
       . '他の会派と比べる</a></p>';
    g_foot();
}
