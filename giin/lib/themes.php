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

/** AIがまとめた活動の要約。scope は 'giin'（slug）か 'party'（会派名）。
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

/** 要約の描画。**断りは一行だけ。** 作り方は /about にまとめてある。
 *  断り書きを並べた画面は読む気を削ぐので、ここには置かない。 */
function g_insight_html(array $r, string $heading): string
{
    if (!$r) { return ''; }
    $ps = '';
    foreach (preg_split('/\n\s*\n/u', trim($r['body'])) as $p) {
        $p = trim($p);
        if ($p !== '') { $ps .= '<p>' . nl2br(g_e($p)) . '</p>'; }
    }
    return '<h2>' . g_e($heading) . '</h2>'
        . '<div class="panel insight">' . $ps
        . '<p class="note">このページの発言と議案からAIが書いた要約です（'
        . g_e($r['built_at']) . '現在）。本人の見解ではありません。'
        . '<a href="' . g_url('about') . '#insight">作り方</a></p></div>';
}

/** AIの特集で使う「AIと一緒に語られている論点」。
 *  ことがら（themes.json）と違い、**AIに触れた発言の中だけ**を分けるための語。
 *  単独では広すぎる語（教育・規制・行政）でも、AIとの共起に絞れば意味を持つ。 */
function g_ai_topics(): array
{
    return [
        'kyoiku'  => ['教育・学校',     ['教育', '学校', '児童', '生徒', '学習指導']],
        'kisei'   => ['規制とルール',   ['規制', '法制', 'ガイドライン', 'ルール整備', '法案']],
        'gyosei'  => ['行政・自治体',   ['行政', '自治体', '窓口', '公務']],
        'koyo'    => ['仕事と雇用',     ['雇用', '労働', '失業', '働き方']],
        'kotsu'   => ['交通・自動運転', ['自動運転', 'ライドシェア', '交通']],
        'iryo'    => ['医療',           ['医療', '診療', '医師', '創薬']],
        'boei'    => ['防衛・安全保障', ['防衛', '安全保障', '自衛隊']],
        'energy'  => ['電力・半導体',   ['電力', 'データセンター', '半導体']],
        'chosaku' => ['著作権',         ['著作権', 'クリエイター', '無断学習']],
        'gizo'    => ['偽情報・詐欺',   ['偽情報', 'フェイク', 'ディープフェイク', '詐欺', 'なりすまし']],
        'chusho'  => ['中小企業',       ['中小企業', '小規模事業者']],
        'nogyo'   => ['農業',           ['農業', '農家', 'スマート農業']],
    ];
}

/** AIに触れた発言の中で、論点ごとの件数。多い順。
 *  ひとつの発言が複数の論点に入ることがある（AIと教育と規制を一度に話すため）。 */
function g_ai_topic_counts(string $kind = 'q'): array
{
    $out = [];
    foreach (g_ai_topics() as $key => [$label, $words]) {
        $w = implode(' OR ', array_fill(0, count($words), 's.body LIKE ?'));
        $a = array_map(fn($x) => '%' . $x . '%', $words);
        $a[] = $kind;
        $out[] = ['key' => $key, 'label' => $label, 'words' => $words,
            'n' => (int)g_val("SELECT COUNT(*) FROM speech s
                 JOIN speech_theme st ON st.speech_id = s.speech_id AND st.theme='ai'
                 WHERE ($w) AND s.kind=?", $a)];
    }
    usort($out, fn($x, $y) => $y['n'] <=> $x['n']);
    return $out;
}

/** AIを持ち出した議員。**件数ではなく「何日・いくつの会議で」を主に見る。**
 *  一度の質疑で何回AIと言ったかは熱心さではないが、
 *  別の日・別の委員会で繰り返し持ち出したなら、それは続けて取り組んでいる印である。 */
function g_ai_members(string $kind = 'q'): array
{
    return g_all("SELECT g.id, g.plain, g.slug, g.party, g.house, g.n_q,
                    COUNT(DISTINCT s.date) days, COUNT(*) n,
                    COUNT(DISTINCT s.meeting) meetings, MAX(s.date) last
                  FROM speech s
                  JOIN speech_theme st ON st.speech_id = s.speech_id AND st.theme='ai'
                  JOIN giin g ON g.id = s.giin_id
                  WHERE s.kind=? GROUP BY g.id
                  ORDER BY days DESC, n DESC", [$kind]);
}

/** 年ごとの件数。伸びているかどうかを見せるため。 */
function g_ai_years(string $kind = 'q'): array
{
    return g_all("SELECT substr(s.date,1,4) y, COUNT(*) n FROM speech s
                  JOIN speech_theme st ON st.speech_id = s.speech_id AND st.theme='ai'
                  WHERE s.kind=? GROUP BY y ORDER BY y", [$kind]);
}

/** この議員だけが国会で言っている語。**この道具にしか出せない数字。**
 *  本家の会議録検索は全文検索なので「誰だけが言っているか」を出せない。
 *  表が無い設置では空を返す（配布物がいきなり壊れない）。 */
function g_uniq_terms(int $giin_id, int $limit = 8): array
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='uniq_term'");
    }
    if (!$has) { return []; }
    return g_all('SELECT term, n, days FROM uniq_term WHERE giin_id=?'
               . ' ORDER BY n DESC, term LIMIT ?', [$giin_id, $limit]);
}

function g_uniq_count(int $giin_id): int
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='uniq_term'");
    }
    return $has ? (int)g_val('SELECT COUNT(*) FROM uniq_term WHERE giin_id=?', [$giin_id]) : 0;
}

/** 質疑をした日と、その日の公式動画。**会議録と動画の両方を持つ道具にしかできない。**
 *  表が無い設置では空を返す。 */
function g_speech_videos(int $giin_id): array
{
    static $has = null;
    if ($has === null) {
        $has = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='speech_video'");
    }
    if (!$has) { return []; }
    $rows = g_all("SELECT sv.date, v.video_id, v.title, v.thumb
                   FROM speech_video sv JOIN video v ON v.video_id = sv.video_id
                   WHERE sv.giin_id=? ORDER BY sv.date DESC, v.title", [$giin_id]);
    $by = [];
    foreach ($rows as $r) { $by[$r['date']][] = $r; }
    return $by;
}

/** 注目してほしい会議録。**こちらが「重要だ」と選ぶと論評になる。**
 *  公式サイトのURLと同じで、本人・事務所から教わったものを data/links.json に登録する。
 *  抜粋は会議録そのものから取り、要約はしない。 */
function g_highlights(string $slug): array
{
    $L = g_links($slug);
    return is_array($L['highlights'] ?? null) ? $L['highlights'] : [];
}

/** 会議録の発言を、指定の語のまわりで抜き出す。**要約しない。** */
function g_kaigi_excerpt(string $speech_id, array $words, int $max = 3): array
{
    $r = g_one('SELECT body, speech_url, date, meeting FROM speech WHERE speech_id=?', [$speech_id]);
    if (!$r) { return []; }
    $body = preg_replace('/\s+/u', ' ', (string)$r['body']);
    $out = [];
    // 文の区切りで切る。語の前後を字数で切ると文の途中から始まって読みにくい。
    $sentences = preg_split('/(?<=。)/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($words as $w) {
        foreach ($sentences as $sent) {
            if (mb_strpos($sent, $w, 0, 'UTF-8') !== false) {
                $t = trim($sent);
                if ($t !== '' && !in_array($t, $out, true)) { $out[] = $t; }
                break;
            }
        }
        if (count($out) >= $max) { break; }
    }
    $r['excerpts'] = $out;
    return $r;
}

/** ことがらに関連する公開統計。**数値はAIに書かせず、公表資料から人が書き写したもの。**
 *  議員ごとではなく、ことがらごとに持つ。45人全員に同じものが出るので中立が保てる。 */
function g_stats(string $theme): array
{
    static $all = null;
    if ($all === null) {
        $j = json_decode((string)@file_get_contents(__DIR__ . '/../data/stats.json'), true);
        $all = is_array($j['stats'] ?? null) ? $j['stats'] : [];
    }
    return array_values(array_filter($all, fn($s) => ($s['theme'] ?? '') === $theme));
}

/** 統計の描画。**出典と時点を必ず一緒に出す。** 数字だけを切り離して見せない。 */
function g_stats_html(array $list, string $lead = ''): string
{
    if (!$list) { return ''; }
    $h = '<h2>関連する公開データ</h2>';
    if ($lead !== '') { $h .= '<p class="note">' . g_e($lead) . '</p>'; }
    foreach ($list as $s) {
        $h .= '<div class="panel stat"><h3 style="margin-top:0">' . g_e($s['title']) . '</h3>'
            . '<p class="note">' . g_e($s['asof']) . '</p>'
            . '<div class="scroll"><table>';
        foreach ($s['rows'] as $r) {
            $h .= '<tr><td>' . g_e($r['label']) . '</td><td class="n"><b>' . g_e($r['value']) . '</b>'
                . (!empty($r['note']) ? '<br><span class="note">' . g_e($r['note']) . '</span>' : '')
                . '</td></tr>';
        }
        $h .= '</table></div>';
        if (!empty($s['caveat'])) {
            $h .= '<p class="note"><b>読むときの注意：</b>' . g_e($s['caveat']) . '</p>';
        }
        $h .= '<p class="note">出典：<a href="' . g_e($s['source_url'])
            . '" rel="nofollow noopener" target="_blank">' . g_e($s['source']) . '</a>'
            . '（' . g_e($s['checked_at']) . '確認）</p></div>';
    }
    return $h;
}
