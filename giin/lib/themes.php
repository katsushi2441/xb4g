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
    $t = g_theme($slug);
    if (!$t) { return []; }
    $args = [];
    $w = g_words_where($t['words'], $args);
    $args[] = $limit;
    return g_all("SELECT g.id,g.plain,g.slug,g.party,g.district,COUNT(*) c
                  FROM speech s JOIN giin g ON g.id=s.giin_id
                  WHERE s.kind='q' AND $w GROUP BY g.id ORDER BY c DESC LIMIT ?", $args);
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
