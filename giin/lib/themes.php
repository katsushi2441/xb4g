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
