<?php
/**
 * 本文の扱い。
 *
 * **要約しない・論評しない・賛否を判定しない。** ここを崩すと、AIの誤りが
 * 議員の立場を毀損する経路ができる。出すのは「機械的な抜粋」と「会議録へのリンク」だけ。
 */
declare(strict_types=1);

function g_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 発言の頭出し。冒頭の定型句（○氏名君）を落としてから、指定字数で切る。 */
function g_excerpt(?string $body, int $len = 140): string
{
    $t = (string)$body;
    $t = preg_replace('/^[○◯●]\s*\S{2,12}(君|議員|大臣|委員長|参考人)?\s*/u', '', $t) ?? $t;
    $t = preg_replace('/\s+/u', ' ', $t) ?? $t;
    $t = trim($t);
    return mb_strlen($t, 'UTF-8') > $len ? mb_substr($t, 0, $len, 'UTF-8') . '…' : $t;
}

/** 検索語の表記ゆれ。会議録は英字・数字が全角（「ＭＭＴ」「ＧＤＰ」「２０２５年」）なので、
 *  半角で入れた語は全角に直したものでも探す（逆も）。2026-10-31、半角「MMT」が0件だった件。 */
function g_q_forms(string $q): array
{
    if ($q === '') { return []; }
    return array_values(array_unique(array_filter([
        $q,
        mb_convert_kana($q, 'A', 'UTF-8'),   // 半角英数 → 全角
        mb_convert_kana($q, 'a', 'UTF-8'),   // 全角英数 → 半角
    ], fn($x) => $x !== '')));
}

/** `(col LIKE ? OR col LIKE ?)` を作り、引数を $args に足す。表記ゆれ（g_q_forms）ぶん並べる。 */
function g_like_or(string $col, string $q, array &$args): string
{
    $forms = g_q_forms($q);
    foreach ($forms as $f) { $args[] = '%' . $f . '%'; }
    return '(' . implode(' OR ', array_fill(0, count($forms), "$col LIKE ?")) . ')';
}

/** 検索語の周辺を切り出す（キーワード・イン・コンテキスト）。 */
function g_kwic(?string $body, string $kw, int $span = 60): string
{
    $t = preg_replace('/\s+/u', ' ', (string)$body) ?? '';
    $p = false; $hit = $kw;
    foreach (g_q_forms($kw) as $f) {   // 本文に出ている表記のほうで切り出す
        $p = mb_strpos($t, $f, 0, 'UTF-8');
        if ($p !== false) { $hit = $f; break; }
    }
    if ($p === false) { return g_excerpt($t, $span * 2); }
    $s = max(0, $p - $span);
    $cut = mb_substr($t, $s, $span * 2 + mb_strlen($hit, 'UTF-8'), 'UTF-8');
    return ($s > 0 ? '…' : '') . $cut . '…';
}

/** 検索語を太字にする（エスケープ後に行う）。 */
function g_mark(string $escaped, string $kw): string
{
    if ($kw === '') { return $escaped; }
    foreach (g_q_forms($kw) as $f) {
        $k = g_e($f);
        $escaped = str_replace($k, '<mark>' . $k . '</mark>', $escaped);
    }
    return $escaped;
}

function g_date(?string $d): string
{
    if (!$d) { return ''; }
    [$y, $m, $dd] = array_pad(explode('-', $d), 3, '');
    return sprintf('%d年%d月%d日', (int)$y, (int)$m, (int)$dd);
}
