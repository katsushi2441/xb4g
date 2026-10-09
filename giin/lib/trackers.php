<?php
/** 全国トラッカー。**愛知の46人に限らず**、ひとつのことがらについて国会の全発言を追う。
 *
 *  表 tracker_speech は scripts/fetch_tracker.py が会議録 API から作る（speech 表には混ぜない。
 *  あちらは46人の発言を数える表なので、混ぜると件数の意味が変わる）。
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

/** その議員（愛知の46人）がこのトラッカーに何件・何日あるか。議員ページの導線用。 */
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

/** 全国の発言1件の描画。話者は会議録の表記のまま。愛知の46人なら議員ページへ渡す。 */
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

/**
 * ことがらの「読む前に知っておくこと」。trackers.json の explain。
 *
 * 論点（questions）は**会議録から整理した問い**なので、そこへ説明を混ぜない。
 * 制度の作りそのものを説明したいときは、こちらへ書く。
 */
function g_tracker_explain(array $t): string
{
    $e = $t['explain'] ?? null;
    if (!is_array($e) || empty($e['items'])) { return ''; }
    $h = '<h2 data-en="Basics">' . g_e($e['title'] ?? '読む前に') . '</h2>';
    if (!empty($e['lead'])) { $h .= '<p>' . g_e($e['lead']) . '</p>'; }
    $h .= '<div class="panel"><dl class="explain">';
    foreach ($e['items'] as $it) {
        $h .= '<dt>' . g_e($it['label']) . '</dt><dd>' . g_e($it['body']) . '</dd>';
    }
    $h .= '</dl></div>';
    if (!empty($e['note'])) { $h .= '<p class="note">' . g_e($e['note']) . '</p>'; }
    return $h;
}

/**
 * トラッカーの「次に見る」。X などから来た人の96%が1ページで帰っていた（2026-09-28 実測：69人中2ページ目は3人）ので、
 * ページの上のほうに、同じことがらの続きを3種類だけ置く。
 *  1) 同じ語で質問主意書と政府答弁書を探す（kshuisho。件数があるものだけ。scripts/build_kshuisho_links.py）
 *  2) 同じテーマの別のトラッカー（最大3）
 *  3) テーマに合う自社のシステム、または trackers.json の next
 */
function g_tracker_next(array $t): string
{
    static $kl = null;
    if ($kl === null) {
        $f = __DIR__ . '/../data/kshuisho_links.json';
        $kl = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    $ref = 'giin-tracker-' . $t['key'];
    $items = [];
    foreach ((array)($t['next'] ?? []) as $n) {
        if (!empty($n['url']) && !empty($n['label'])) { $items[] = [$n['label'], $n['url'], true]; }
    }
    // テーマ全体に自社システムを当てると外れる（公立病院の経営に制度ナビ、など）。テーマで決めるのは防災だけにし、
    // それ以外は trackers.json の next で1本ずつ当てる
    $sys = [
        'nankai-bosai' => ['住所で「いま逃げた方がいい？」を聞く（Kurage 防災AIチャット）', 'https://kurage.exbridge.jp/kbousai.php/'],
    ];
    if (isset($sys[$t['theme'] ?? '']) && !$items) {
        [$l, $u] = $sys[$t['theme']];
        $items[] = [$l, $u . '?ref=' . rawurlencode($ref), true];
    }
    if (isset($kl[$t['key']])) {
        $k = $kl[$t['key']];
        $items[] = ['「' . $k['word'] . '」の質問主意書と政府答弁書（' . (int)$k['count'] . '件）',
                    'https://kurage.exbridge.jp/kshuisho.php/search?q=' . rawurlencode($k['word']) . '&ref=' . rawurlencode($ref), true];
    }
    $n = 0;
    // 相談先を先に出すことがら（notice あり）には、関係の薄い同テーマのトラッカーを並べない
    foreach (empty($t['notice']) ? g_trackers_for_theme((string)($t['theme'] ?? '')) : [] as $o) {
        if ($o['key'] === $t['key'] || $n >= 3) { continue; }
        $items[] = [($o['seo_word'] ?? $o['short'] ?? $o['name']) . 'は国会でどう議論されたか', g_url('tracker/' . $o['key']), false];
        $n++;
    }
    if (!$items) { return ''; }
    $h = '<div class="panel"><p style="margin:0 0 6px"><b>次に見る</b></p><ul style="margin:0">';
    foreach ($items as [$label, $url, $ext]) {
        $h .= '<li><a href="' . g_e($url) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . g_e($label) . '</a></li>';
    }
    return $h . '</ul></div>';
}

/**
 * AI検索（AEO/GEO）と強調スニペット向けの「問いと答え」。**すべて会議録のデータから機械的に作る**（2026-10-03）。
 * 要約や賛否は書かない。数字・日付・話者・発言の一文だけ。FAQPage の JSON-LD と、画面のよくある質問の両方に使う。
 */
/** 会派名を党の短い名前に寄せる。「自由民主党」は「民主党」を含むので、自民を先に見る（2026-10-09） */
function g_party_short(string $k): string
{
    $rules = [['自由民主', '自民'], ['立憲民主', '立憲民主'], ['国民民主', '国民民主'], ['公明', '公明'], ['維新', '維新'],
              ['共産', '共産'], ['れいわ', 'れいわ'], ['参政', '参政'], ['チームみらい', 'チームみらい'], ['社会民主', '社民'],
              ['民主党', '旧民主・民進'], ['民進', '旧民主・民進'], ['希望の党', '希望'], ['みんなの党', 'みんな'], ['中道改革', '中道改革連合'], ['有志', '有志の会']];
    foreach ($rules as [$needle, $name]) { if (mb_strpos($k, $needle) !== false) { return $name; } }
    return $k !== '' ? $k : 'その他';
}

/** 党（会派）ごとの質疑の件数。多い順 */
function g_tracker_parties(string $key): array
{
    if (!g_tracker_ready()) { return []; }
    $by = [];
    foreach (g_all("SELECT kaiha_at k, COUNT(*) n FROM tracker_speech WHERE tracker=? AND kind='q' GROUP BY kaiha_at", [$key]) as $r) {
        $p = g_party_short((string)$r['k']);
        $by[$p] = ($by[$p] ?? 0) + (int)$r['n'];
    }
    arsort($by);
    return $by;
}

/** 当社が会議録から数えた要点（ページの冒頭に出す。引用の写しではない、このページだけの中身。2026-10-09） */
function g_tracker_points(array $t, array $st, array $years, array $qs, array $latestGov): array
{
    $n = $t['short'] ?? $t['name'];
    $out = [];
    $qy = array_values(array_filter($years, fn($y) => (int)$y['q'] > 0));
    if ($qy) {
        $peak = $qy[0];
        foreach ($qy as $y) { if ((int)$y['q'] > (int)$peak['q']) { $peak = $y; } }
        $last = end($qy);
        $s = '質疑がいちばん多かったのは' . $peak['y'] . '年の' . number_format((int)$peak['q']) . '件です。';
        if ($last['y'] !== $peak['y']) { $s .= '直近の' . $last['y'] . '年は' . number_format((int)$last['q']) . '件です。'; }
        $out[] = $s;
    }
    $parties = g_tracker_parties((string)$t['key']);
    $tot = array_sum($parties);
    if ($tot > 0) {
        $top = array_slice($parties, 0, 3, true);
        $out[] = '党（会派）ごとの質疑は、' . implode('、', array_map(fn($p, $c) => $p . ' ' . number_format($c) . '件（' . round($c * 100 / $tot) . '%）',
                 array_keys($top), array_values($top))) . 'の順に多くなっています。';
    }
    if ($qs) {
        $q = $qs[0];
        $out[] = 'もっとも多くの日に取り上げた議員は、' . $q['speaker'] . '（' . g_party_short((string)($q['kaiha'] ?? '')) . '・' . (int)$q['days'] . '日）です。';
    }
    if ($latestGov) {
        $g = $latestGov[0];
        $pos = preg_replace('/（.*$/u', '', (string)($g['position'] ?? ''));
        $out[] = '直近の政府答弁は、' . g_date($g['date']) . 'の' . $g['house'] . ' ' . $g['meeting'] . 'で、' . $g['speaker'] . ($pos !== '' ? '（' . $pos . '）' : '') . 'が答えたものです。';
    }
    return $out;
}

function g_tracker_faq(array $t, array $st, array $qs, array $years, array $latestGov): array
{
    $n = $t['short'] ?? $t['name'];
    $out = [];
    $out[] = [$n . 'は国会でどれくらい議論されていますか？',
        g_date($st['first']) . 'から' . g_date($st['last']) . 'までに、国会会議録には' . $n . 'にふれた議員の質疑が'
        . number_format((int)$st['q']) . '件、政府の答弁が' . number_format((int)$st['gov']) . '件あります。取り上げた議員は'
        . (int)$st['speakers'] . '人です（「' . implode('」「', array_slice($t['words'], 0, 4)) . '」を含む発言を数えたもの）。'];
    $pt = g_tracker_parties((string)($t['key'] ?? ''));
    if ($pt && array_sum($pt) > 0) {
        $tot = array_sum($pt); $top3 = array_slice($pt, 0, 3, true);
        $out[] = [$n . 'を国会で多く取り上げているのは、どの党ですか？',
            '議員の質疑' . number_format($tot) . '件を党（会派）ごとに数えると、' . implode('、', array_map(fn($p, $c) => $p . ' ' . number_format($c) . '件',
            array_keys($top3), array_values($top3))) . 'の順です（発言時の会派で数えたもの）。'];
    }
    if ($qs) {
        $top = array_slice($qs, 0, 3);
        $out[] = [$n . 'を国会で多く取り上げた議員はだれですか？',
            '取り上げた日数が多い順に、' . implode('、', array_map(fn($r) => $r['speaker'] . '（' . (int)$r['days'] . '日・最新 ' . g_date($r['last']) . '）', $top))
            . 'です。'];
    }
    if ($latestGov) {
        $g = $latestGov[0];
        $ex = g_tracker_excerpt((string)$g['body'], $t['words'], 1);
        $out[] = ['政府は' . $n . 'について最近なんと答えていますか？',
            g_date($g['date']) . 'の' . $g['house'] . ' ' . $g['meeting'] . 'で、' . $g['speaker']
            . ($g['position'] ? '（' . $g['position'] . '）' : '') . 'が答弁しています。'
            . ($ex ? '答弁の一文：「' . $ex[0] . '」' : '') . '前後の文脈は会議録で確かめてください。'];
    }
    if (count($years) >= 2) {
        $peak = $years[0];
        foreach ($years as $y) { if ((int)$y['n'] > (int)$peak['n']) { $peak = $y; } }
        $out[] = [$n . 'が国会で最も多く議論されたのはいつですか？',
            '件数が最も多かったのは' . $peak['y'] . '年で、質疑' . (int)$peak['q'] . '件・答弁' . (int)$peak['gov'] . '件でした。'];
    }
    return $out;
}
