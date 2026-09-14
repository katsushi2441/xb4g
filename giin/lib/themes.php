<?php
/** ことがら（テーマ）の定義。語は data/themes.json 側で管理し、コードを触らず増やせるようにする。 */
declare(strict_types=1);

function g_themes(): array
{
    static $t = null;
    if ($t === null) {
        $t = json_decode((string)@file_get_contents(__DIR__ . '/../data/themes.json'), true) ?: [];
    }
    return $t;
}

function g_theme(string $slug): ?array
{
    foreach (g_themes() as $t) { if ($t['slug'] === $slug) { return $t; } }
    return null;
}

/** ことがらの語で LIKE 条件を組む。 */
function g_words_where(array $words, array &$args): string
{
    $ors = [];
    foreach ($words as $w) { $ors[] = 's.body LIKE ?'; $args[] = '%' . $w . '%'; }
    return $ors ? '(' . implode(' OR ', $ors) . ')' : '1=0';
}

/** 発言の立場。**既定で見せるのは「議員としての質疑」だけ。**
 *  議事整理（委員長として「次に、○○君。」）や答弁まで混ぜて数えると、
 *  委員長を務めた議員の件数が跳ね上がって「よく質問している人」に見えてしまう。 */
const G_KINDS = [
    'q'     => ['質疑・質問',   '議員として質問・討論した発言'],
    'gov'   => ['答弁',         '大臣・副大臣・政務官として答えた発言'],
    'chair' => ['議事整理',     '委員長・議長として議事を進めた発言'],
];

function g_kind(): string
{
    $k = (string)($_GET['k'] ?? 'q');
    return isset(G_KINDS[$k]) ? $k : 'q';
}

function g_kind_label(string $k): string { return G_KINDS[$k][0] ?? $k; }

/** 立場の切り替えタブ。件数は呼び出し側が数えて渡す。 */
function g_kind_tabs(string $now, array $counts, string $base): void
{
    $sep = strpos($base, '?') === false ? '?' : '&';
    echo '<p class="lead">';
    foreach (G_KINDS as $k => [$label, $hint]) {
        $c = (int)($counts[$k] ?? 0);
        $cls = $k === $now ? 'pill' : 'pill gray';
        echo '<a class="' . $cls . '" title="' . g_e($hint) . '" href="'
           . g_e($base . $sep . 'k=' . $k) . '">' . g_e($label) . ' ' . number_format($c) . '</a> ';
    }
    echo '<span class="note">' . g_e(G_KINDS[$now][1]) . '</span></p>';
}

/** ことがらの「最近の動き」。国会の議案と省庁の報道発表だけ。
 *  **見出し・日付・発表元・リンクだけを出す。本文は持っていないし、要約もしない。** */
function g_news(string $theme = '', int $limit = 8, int $giin = 0): array
{
    $w = []; $a = [];
    if ($theme !== '') {
        $w[] = 'n.id IN (SELECT news_id FROM news_theme WHERE theme=?)'; $a[] = $theme;
    }
    if ($giin > 0) { $w[] = 'n.giin_id = ?'; $a[] = $giin; }
    $sql = 'SELECT n.* FROM news n' . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
         . ' ORDER BY n.date DESC LIMIT ' . (int)$limit;
    return g_all($sql, $a);
}

/** ことがらの動きが多い順。トップの「いま動いていることがら」用。 */
function g_hot_themes(int $days = 120, int $limit = 6): array
{
    $since = date('Y-m-d', strtotime("-{$days} days"));
    $rows = g_all('SELECT t.theme, COUNT(*) c, MAX(n.date) last FROM news_theme t'
                . ' JOIN news n ON n.id=t.news_id WHERE n.date >= ?'
                . ' GROUP BY t.theme ORDER BY c DESC, last DESC LIMIT ' . (int)$limit, [$since]);
    $out = [];
    foreach ($rows as $r) {
        $t = g_theme($r['theme']);
        if ($t) { $out[] = $t + ['n' => (int)$r['c'], 'last' => $r['last']]; }
    }
    return $out;
}

/** そのことがらで、国会でよく質問している議員の上位。 */
function g_theme_top(string $slug, int $limit = 3): array
{
    // **先に数えた表を読む。** 以前はここで LIKE の全走査をしていて、
    // トップに4つ出すだけで4秒かかっていた（2026-09-15 実測）。
    return g_all("SELECT g.id,g.plain,g.slug,g.party,g.district,tc.n c
                  FROM theme_count tc JOIN giin g ON g.id=tc.giin_id
                  WHERE tc.theme=? AND tc.kind='q' AND tc.giin_id>0 AND tc.n>0
                  ORDER BY tc.n DESC LIMIT ?", [$slug, $limit]);
}

/** 議員の公式リンク。**本人の公式サイトに載っているものだけ**を data/links.json に入れてある。
 *  検索結果から拾ったものは入れない（なりすましを本人として出さないため）。 */
function g_links(string $slug): array
{
    static $all = null;
    if ($all === null) {
        $all = json_decode((string)@file_get_contents(__DIR__ . '/../data/links.json'), true) ?: [];
    }
    return $all[$slug] ?? [];
}

/** 公式YouTubeの新着。チャンネルRSSから取り込んだもの（APIキー不使用）。 */
function g_videos(int $giin_id, int $limit = 6): array
{
    try {
        return g_all('SELECT * FROM video WHERE giin_id=? ORDER BY published DESC LIMIT ?',
                     [$giin_id, $limit]);
    } catch (PDOException $e) {
        return [];   // video 表がまだ無い設置でも画面を壊さない
    }
}

/** 公式Xの新着。Xの埋め込みが読んでいる公開ページから取り込んだもの（APIキー不使用）。
 *  既定では**本人の投稿だけ**。RTは本人の言葉ではないので混ぜない。 */
function g_xposts(int $giin_id, int $limit = 6, bool $with_rt = false): array
{
    $w = $with_rt ? '' : ' AND is_repost=0';
    try {
        return g_all("SELECT * FROM xpost WHERE giin_id=?$w ORDER BY posted DESC LIMIT ?",
                     [$giin_id, $limit]);
    } catch (PDOException $e) {
        return [];
    }
}

/** 公式リンクを入れ終えた人数。**「全員ぶんある」と誤解させないため、実数を画面に出す。** */
function g_links_count(): int
{
    $all = json_decode((string)@file_get_contents(__DIR__ . '/../data/links.json'), true) ?: [];
    return count($all);
}

/** ことがらの件数。**毎回 LIKE を走らせず、先に数えた表を読む。**
 *  giin_id=0 は全員ぶんの合計。表が無い設置では LIKE に落ちる。 */
function g_theme_n(string $theme, string $kind = 'q', int $giin_id = 0): int
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='theme_count'");
    }
    if ($has) {
        return (int)g_val('SELECT n FROM theme_count WHERE theme=? AND giin_id=? AND kind=?',
                          [$theme, $giin_id, $kind]);
    }
    $t = g_theme($theme);
    if (!$t) { return 0; }
    $a = [$kind]; $w = g_words_where($t['words'], $a);
    if ($giin_id > 0) { $a[] = $giin_id; return (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind=? AND $w AND s.giin_id=?", $a); }
    return (int)g_val("SELECT COUNT(*) FROM speech s WHERE s.kind=? AND $w", $a);
}

/** ことがらの発言を引くための WHERE 断片。対応表があれば索引つきの IN を使う。 */
function g_theme_where(string $theme, array &$args): string
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='speech_theme'");
    }
    if ($has) {
        $args[] = $theme;
        return 's.speech_id IN (SELECT speech_id FROM speech_theme WHERE theme=?)';
    }
    $t = g_theme($theme);
    return $t ? g_words_where($t['words'], $args) : '1=0';
}

/** 政党の一覧と内訳。**全党を同じ処理で出す。**特定の党だけのページは作らない。 */
function g_parties(): array
{
    return g_all("SELECT party, COUNT(*) n, SUM(n_q) q, SUM(n_gov) gov, SUM(n_chair) chair,
                         SUM(n_speech) total
                  FROM giin GROUP BY party ORDER BY q DESC");
}

function g_party(string $slug): ?array
{
    foreach (g_parties() as $p) {
        if (g_party_slug($p['party']) === $slug) { return $p; }
    }
    return null;
}

/** 政党名からURL用のslug。日本語をURLに入れないための対応表。 */
function g_party_slug(string $name): string
{
    $map = [
        '自民' => 'jimin', '国民民主' => 'kokumin', '立憲' => 'rikken',
        '公明' => 'komei', '維新' => 'ishin', '共産' => 'kyosan',
        '参政' => 'sansei', 'チームみらい' => 'mirai',
        '中道改革連合' => 'chudo', '無所属' => 'mushozoku',
        '社民' => 'shamin', '日本保守党' => 'hoshu', 'いのちの党' => 'inochi',
        '沖縄の風' => 'okinawa',
    ];
    return $map[$name] ?? 'p' . substr(md5($name), 0, 6);
}

/** その会派の議員が提出者になっている議案。 */
function g_party_gian(string $party, int $limit = 6): array
{
    try {
        return g_all("SELECT n.*, g.plain, g.slug FROM news n JOIN giin g ON g.id=n.giin_id
                      WHERE g.party=? ORDER BY n.date DESC LIMIT ?", [$party, $limit]);
    } catch (PDOException $e) { return []; }
}

/** その会派がよく質疑していることがらの、最近の動き。
 *  議案が無い会派でも「いま何が動いているか」が出るようにするため。 */
function g_party_news(string $party, int $themes = 3, int $limit = 6): array
{
    $ts = g_all("SELECT tc.theme, SUM(tc.n) n FROM theme_count tc JOIN giin g ON g.id=tc.giin_id
                 WHERE tc.kind='q' AND tc.giin_id>0 AND g.party=?
                 GROUP BY tc.theme ORDER BY n DESC LIMIT ?", [$party, $themes]);
    if (!$ts) { return [[], []]; }
    $slugs = array_column($ts, 'theme');
    $in = implode(',', array_fill(0, count($slugs), '?'));
    $a = $slugs; $a[] = $limit;
    $news = g_all("SELECT DISTINCT n.* FROM news n
                   JOIN news_theme nt ON nt.news_id = n.id
                   WHERE nt.theme IN ($in) ORDER BY n.date DESC LIMIT ?", $a);
    return [$news, $ts];
}

/** 「いまの時点でのAI考察」。scope は 'giin'（slug）か 'party'（会派名）。
 *  表が無い設置でも壊れないよう、無ければ空を返す。 */
function g_insight(string $scope, string $key): array
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='insight'");
    }
    if (!$has) { return []; }
    $r = g_one('SELECT body, model, built_at FROM insight WHERE scope=? AND key=?', [$scope, $key]);
    return $r ?: [];
}

/** AI考察の描画。**断り書きを本文と必ず一体で出す。**
 *  機械が書いた文であることと、件数・日付はAIに書かせていないことを、
 *  読む人がこの一箇所で分かるようにするため。 */
function g_insight_html(array $r, string $what, string $sources = ''): string
{
    if (!$r) { return ''; }
    $ps = '';
    foreach (preg_split('/\n\s*\n/u', trim($r['body'])) as $p) {
        $p = trim($p);
        if ($p !== '') { $ps .= '<p>' . nl2br(g_e($p)) . '</p>'; }
    }
    return '<h2>いまの時点でのAI考察</h2>'
        . '<div class="panel insight">' . $ps
        . '<p class="note">このページに出している' . g_e($sources)
        . 'だけを材料に、<b>機械が書いた下書き</b>です。'
        . g_e($what) . 'の見解ではありません。'
        . '賛成・反対の判定はしていません（会議録から機械判定できないためです）。'
        . '<b>件数や日付はAIに書かせていません。</b>数字はすべて集計から出しています。'
        . '<br>' . g_e($r['built_at']) . '時点　生成: ' . g_e($r['model'])
        . '　<a href="' . g_url('about') . '">作り方</a></p></div>';
}

/** 考察に使っているモデル名（/about の説明用）。無ければ空。 */
function g_insight_model(): string
{
    static $m = null;
    if ($m === null) {
        $m = (string)(g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='insight'")
            ? (g_val('SELECT model FROM insight LIMIT 1') ?: '') : '');
    }
    return $m;
}
