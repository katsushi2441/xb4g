<?php
/** 全国トラッカー。**愛知の45人に限らず**、ひとつのことがらについて国会の全発言を追う。
 *
 *  表 tracker_speech は scripts/fetch_tracker.py が会議録 API から作る（speech 表には混ぜない。
 *  あちらは45人の発言を数える表なので、混ぜると件数の意味が変わる）。
 *  定義は data/trackers.json。表が無い設置でも画面を壊さないよう、無ければ空を返す。
 *  要約はしない。抜粋は語のまわりの文をそのまま出す。 */
declare(strict_types=1);

function g_trackers(): array
{
    static $t = null;
    if ($t === null) {
        $t = json_decode((string)@file_get_contents(__DIR__ . '/../data/trackers.json'), true) ?: [];
    }
    return $t;
}

function g_tracker(string $key): ?array
{
    foreach (g_trackers() as $t) { if ($t['key'] === $key) { return $t; } }
    return null;
}

/** そのことがらに結びついたトラッカー（複数あることがある）。 */
function g_trackers_for_theme(string $theme): array
{
    return array_values(array_filter(g_trackers(), fn($t) => ($t['theme'] ?? '') === $theme));
}

function g_tracker_ready(): bool
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tracker_speech'");
    }
    return $has;
}

/** 件数のまとめ。全国の発言・質疑・答弁・最初と最後の日・質問した人の数。 */
function g_tracker_stats(string $key): array
{
    if (!g_tracker_ready()) { return []; }
    $r = g_one("SELECT COUNT(*) n,
                       SUM(kind='q') q, SUM(kind='gov') gov,
                       MIN(date) first, MAX(date) last,
                       COUNT(DISTINCT CASE WHEN kind='q' THEN speaker END) speakers,
                       COUNT(DISTINCT date) days,
                       SUM(giin_id>0) aichi
                FROM tracker_speech WHERE tracker=?", [$key]);
    return $r && (int)$r['n'] > 0 ? $r : [];
}

function g_tracker_years(string $key): array
{
    if (!g_tracker_ready()) { return []; }
    return g_all("SELECT substr(date,1,4) y, SUM(kind='q') q, SUM(kind='gov') gov, COUNT(*) n
                  FROM tracker_speech WHERE tracker=? GROUP BY y ORDER BY y", [$key]);
}

/** だれが取り上げているか。**件数ではなく日数を主に見る**（AIの特集と同じ考え方）。 */
function g_tracker_speakers(string $key, string $kind = 'q'): array
{
    if (!g_tracker_ready()) { return []; }
    return g_all("SELECT t.speaker, t.giin_id, g.slug, g.plain,
                         COUNT(DISTINCT t.date) days, COUNT(*) n,
                         COUNT(DISTINCT t.meeting) meetings, MAX(t.date) last, MIN(t.date) first,
                         (SELECT kaiha_at FROM tracker_speech x WHERE x.tracker=t.tracker AND x.speaker=t.speaker
                            AND x.kind=t.kind ORDER BY x.date DESC LIMIT 1) kaiha,
                         (SELECT position FROM tracker_speech x WHERE x.tracker=t.tracker AND x.speaker=t.speaker
                            AND x.kind=t.kind ORDER BY x.date DESC LIMIT 1) position,
                         (SELECT house FROM tracker_speech x WHERE x.tracker=t.tracker AND x.speaker=t.speaker
                            AND x.kind=t.kind ORDER BY x.date DESC LIMIT 1) house
                  FROM tracker_speech t LEFT JOIN giin g ON g.id=t.giin_id
                  WHERE t.tracker=? AND t.kind=? GROUP BY t.speaker
                  ORDER BY days DESC, n DESC, last DESC", [$key, $kind]);
}

function g_tracker_count(string $key, string $kind = ''): int
{
    if (!g_tracker_ready()) { return 0; }
    return $kind === ''
        ? (int)g_val('SELECT COUNT(*) FROM tracker_speech WHERE tracker=?', [$key])
        : (int)g_val('SELECT COUNT(*) FROM tracker_speech WHERE tracker=? AND kind=?', [$key, $kind]);
}

function g_tracker_list(string $key, string $kind, int $limit, int $offset = 0): array
{
    if (!g_tracker_ready()) { return []; }
    $w = $kind === '' ? '' : ' AND t.kind=?';
    $a = [$key]; if ($kind !== '') { $a[] = $kind; }
    $a[] = $limit; $a[] = $offset;
    return g_all("SELECT t.*, g.slug, g.plain FROM tracker_speech t LEFT JOIN giin g ON g.id=t.giin_id
                  WHERE t.tracker=?$w ORDER BY t.date DESC, t.speech_order DESC LIMIT ? OFFSET ?", $a);
}

/** その議員（愛知の45人）がこのトラッカーに何件・何日あるか。議員ページの導線用。 */
function g_tracker_of_giin(int $giin_id): array
{
    if (!g_tracker_ready() || $giin_id <= 0) { return []; }
    $out = [];
    foreach (g_all("SELECT tracker, COUNT(*) n, COUNT(DISTINCT date) days, MAX(date) last
                    FROM tracker_speech WHERE giin_id=? AND kind='q' GROUP BY tracker", [$giin_id]) as $r) {
        $t = g_tracker($r['tracker']);
        if ($t) { $out[] = $t + ['n' => (int)$r['n'], 'days' => (int)$r['days'], 'last' => $r['last']]; }
    }
    return $out;
}

/** 検索語がトラッカーの語に当たるか（検索結果からの導線用）。 */
function g_tracker_for_query(string $q): ?array
{
    $q = trim($q);
    if ($q === '') { return null; }
    foreach (g_trackers() as $t) {
        foreach ($t['words'] as $w) {
            if (mb_strpos($q, $w, 0, 'UTF-8') !== false || mb_strpos($w, $q, 0, 'UTF-8') !== false) { return $t; }
        }
    }
    return null;
}

/** 語を含む文をそのまま抜く。**要約しない。** 文の途中から始めない。 */
function g_tracker_excerpt(string $body, array $words, int $max = 2): array
{
    $body = preg_replace('/^[○◯●]\s*\S{2,20}(君|議員|大臣|委員長|参考人)?\s*/u', '', $body) ?? $body;
    $body = preg_replace('/\s+/u', ' ', $body) ?? $body;
    $sentences = preg_split('/(?<=。)/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($sentences as $sent) {
        foreach ($words as $w) {
            if (mb_strpos($sent, $w, 0, 'UTF-8') !== false) {
                $t = trim($sent);
                if (mb_strlen($t, 'UTF-8') > 220) { $t = mb_substr($t, 0, 220, 'UTF-8') . '…'; }
                if ($t !== '' && !in_array($t, $out, true)) { $out[] = $t; }
                break;
            }
        }
        if (count($out) >= $max) { break; }
    }
    return $out;
}

/** 全国の発言1件の描画。話者は会議録の表記のまま。愛知の45人なら議員ページへ渡す。 */
function g_tracker_speech(array $s, array $words): void
{
    $hit = array_values(array_filter($words, fn($w) => mb_strpos((string)$s['body'], $w, 0, 'UTF-8') !== false));
    $kw = $hit[0] ?? ($words[0] ?? '');
    $ex = g_tracker_excerpt((string)$s['body'], $hit ?: $words, 2);
    $txt = $ex ? implode(' ', $ex) : g_kwic($s['body'], $kw);
    $who = !empty($s['slug'])
        ? '<a href="' . g_url($s['slug']) . '">' . g_e($s['plain']) . '</a><span class="pill">愛知</span>'
        : '<b>' . g_e($s['speaker']) . '</b>';
    echo '<div class="sp"><div class="m">'
       . '<b>' . g_e(g_date($s['date'])) . '</b>'
       . '<span class="pill gray">' . g_e($s['house']) . ' ' . g_e($s['meeting']) . '</span>'
       . ($s['issue'] ? '<span class="note">' . g_e($s['issue']) . '</span>' : '')
       . $who
       . ($s['kaiha_at'] ? '<span class="note">' . g_e($s['kaiha_at']) . '</span>' : '')
       . ($s['position'] ? '<span class="note">' . g_e($s['position']) . '</span>' : '')
       . (['gov' => '<span class="pill gray">答弁</span>', 'chair' => '<span class="pill gray">議事整理</span>',
           'ref' => '<span class="pill gray">参考人</span>'][$s['kind']] ?? '')
       . '</div>'
       . '<p class="t">' . g_mark(g_e($txt), $kw) . '</p>'
       . '<div class="lk"><a href="' . g_e($s['speech_url']) . '" rel="nofollow noopener" target="_blank">'
       . 'この発言を会議録で読む</a>'
       . ($s['meeting_url'] ? '　<a href="' . g_e($s['meeting_url']) . '" rel="nofollow noopener" target="_blank">'
          . 'この回の会議録</a>' : '')
       . '</div></div>';
}
