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

/** 検索語の周辺を切り出す（キーワード・イン・コンテキスト）。 */
function g_kwic(?string $body, string $kw, int $span = 60): string
{
    $t = preg_replace('/\s+/u', ' ', (string)$body) ?? '';
    $p = $kw === '' ? false : mb_strpos($t, $kw, 0, 'UTF-8');
    if ($p === false) { return g_excerpt($t, $span * 2); }
    $s = max(0, $p - $span);
    $cut = mb_substr($t, $s, $span * 2 + mb_strlen($kw, 'UTF-8'), 'UTF-8');
    return ($s > 0 ? '…' : '') . $cut . '…';
}

/** 検索語を太字にする（エスケープ後に行う）。 */
function g_mark(string $escaped, string $kw): string
{
    if ($kw === '') { return $escaped; }
    $k = g_e($kw);
    return str_replace($k, '<mark>' . $k . '</mark>', $escaped);
}

function g_date(?string $d): string
{
    if (!$d) { return ''; }
    [$y, $m, $dd] = array_pad(explode('-', $d), 3, '');
    return sprintf('%d年%d月%d日', (int)$y, (int)$m, (int)$dd);
}
