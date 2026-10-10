<?php
/**
 * 法案（議案）のページ。scripts/fetch_bills.py が作る表 bill / bill_speech / bill_vote を読む。
 *
 * 「いま審議中の法案を分かりやすく」ではなく、**その法案が国会でどう扱われ、どの質問や答弁とつながっているか**を
 * 事実と出典だけで並べる。要約も論評も、賛成・反対の立場もここでは書かない。
 */
declare(strict_types=1);

const G_BILL_KINDS = ['kaku' => '内閣提出', 'shu' => '衆法', 'san' => '参法', 'yosan' => '予算', 'joyaku' => '条約'];
const G_BILL_STATUS = ['委員会で審査中', '審議中', '成立', '承認', '継続審査', '衆議院で可決', '参議院で可決', '審議未了', '委員会に付託されず', '否決', '撤回'];

function g_bill_ready(): bool
{
    static $ok = null;
    if ($ok === null) { $ok = (bool)g_val("SELECT 1 FROM sqlite_master WHERE type='table' AND name='bill'"); }
    return $ok;
}

function g_bill(string $id): ?array { return g_one('SELECT * FROM bill WHERE id=?', [$id]); }

/** トラッカーの語は「政党交付金 返還」のように空白区切りの組み合わせなので、件名と突き合わせるのは各組のいちばん長い語 */
function g_bill_tokens(array $words): array
{
    $out = [];
    foreach ($words as $x) {
        $parts = preg_split('/[\s　]+/u', trim((string)$x)) ?: [];
        usort($parts, fn($p, $q) => mb_strlen($q) <=> mb_strlen($p));
        $t = $parts[0] ?? '';
        // 地名や一般語は件名に偶然入るので使わない（例: 「名古屋市 住民税」→ 愛知・名古屋アジア競技大会の法案に当たった）
        if (preg_match('/^(名古屋|名古屋市|愛知|愛知県|東京|東京都|大阪|日本|政府|国会|法律|改正|法案|制度|地方|引き下げ|引下げ|引き上げ|引上げ|処遇改善|人材確保|見直し|支援|対策)$/u', $t)) { continue; }
        if (mb_strlen($t) >= 3 && !in_array($t, $out, true)) { $out[] = $t; }
    }
    return $out;
}

/** 語（トラッカーの語など）を件名か法律名に含む法案。新しい順 */
function g_bills_for_words(array $words, int $limit = 6): array
{
    if (!g_bill_ready() || !$words) { return []; }
    $w = []; $a = [];
    foreach (array_slice(g_bill_tokens($words), 0, 8) as $x) {
        if (mb_strlen((string)$x) < 2) { continue; }
        $w[] = '(title LIKE ? OR law_name LIKE ?)'; $a[] = '%' . $x . '%'; $a[] = '%' . $x . '%';
    }
    if (!$w) { return []; }
    return g_all("SELECT id,title,kind_code,status,last_date,submitted FROM bill WHERE kind_code IN ('kaku','shu','san') AND ("
        . implode(' OR ', $w) . ') ORDER BY last_date DESC LIMIT ' . (int)$limit, $a);
}

/** この法案に関係する国会トラッカー（トラッカーの語が件名に入っているもの） */
function g_trackers_for_bill(array $b): array
{
    $out = [];
    foreach (g_trackers() as $t) {
        foreach (g_bill_tokens((array)($t['words'] ?? [])) as $w) {
            if (mb_strpos($b['title'], $w) !== false) { $out[] = $t; break; }
        }
    }
    return array_slice($out, 0, 4);
}

function g_bill_status_pill(string $s): string
{
    $cls = in_array($s, ['成立', '承認', '委員会で審査中', '審議中'], true) ? '' : ' gray';
    return '<span class="pill' . $cls . '">' . g_e($s) . '</span>';
}

/** 審議の経過を日付順の行にする */
function g_bill_timeline(array $b): array
{
    $ev = [];
    $add = function (?string $d, string $what) use (&$ev) { if ($d) { $ev[] = [$d, $what]; } };
    $who = preg_replace('/が(衆|参)議院に$|が国会に$/u', '', g_bill_submitter($b)) ?? '';
    $where = ['shu' => '（衆議院）', 'san' => '（参議院）'][$b['kind_code']] ?? '';
    $add($b['submitted'], '提出：' . $who . $where);
    $shuFirst = ($b['sen_first'] ?? '') !== '参先議';
    $houses = $shuFirst ? ['shu' => '衆議院', 'san' => '参議院'] : ['san' => '参議院', 'shu' => '衆議院'];
    foreach ($houses as $h => $hn) {
        if ($b[$h . '_committee']) {
            $add($b[$h . '_committee_date'], $hn . ' ' . $b[$h . '_committee'] . '：' . ($b[$h . '_committee_result'] ?: '審査'));
            if (!$b[$h . '_committee_date'] && $b[$h . '_committee_result']) {
                $ev[] = ['', $hn . ' ' . $b[$h . '_committee'] . '：' . $b[$h . '_committee_result']];
            }
        }
        if ($b[$h . '_plenary_result']) {
            $add($b[$h . '_plenary_date'], $hn . ' 本会議：' . $b[$h . '_plenary_result'] . ($b[$h . '_plenary_mode'] ? '（' . $b[$h . '_plenary_mode'] . '）' : ''));
        }
    }
    $add($b['promulgated'], '公布' . ($b['law_number'] ? '（法律第' . $b['law_number'] . '号）' : ''));
    usort($ev, fn($x, $y) => strcmp($x[0] ?: '9999', $y[0] ?: '9999'));
    return $ev;
}

function g_bill_sessions(array $b): string
{
    $s = array_filter(explode(',', (string)$b['sessions']));
    if (count($s) <= 1) { return '第' . (int)$b['session'] . '回国会'; }
    return '第' . reset($s) . '回〜第' . end($s) . '回国会（' . count($s) . '会期）';
}

function g_bill_submitter(array $b): string
{
    $sub = trim(preg_replace('/\s+/u', ' ', (string)$b['submitter']) ?? '');
    $sub = preg_replace('/君 外(\d+)名/u', '議員ほか$1名', $sub) ?? $sub;
    $sub = preg_replace('/君$/u', '議員', $sub) ?? $sub;
    switch ($b['kind_code']) {
        case 'shu': return ($sub ?: '衆議院議員') . 'が衆議院に';
        case 'san': return ($sub ?: '参議院議員') . 'が参議院に';
        default:    return '内閣が国会に';
    }
}

/** 状態を一文で（数字と日付だけ。評価はしない） */
function g_bill_answer(array $b): string
{
    $what = ['kaku' => '法律案（内閣提出）', 'shu' => '法律案（衆法）', 'san' => '法律案（参法）', 'yosan' => '予算', 'joyaku' => '条約'][$b['kind_code']] ?? '議案';
    $head = $b['title'] . 'は、' . ($b['submitted'] ? g_date($b['submitted']) . 'に' : '') . g_bill_submitter($b) . '提出した' . $what . 'です。'
          . (strpos((string)$b['sessions'], ',') !== false ? g_bill_sessions($b) . 'に出ています。' : '');
    switch ($b['status']) {
        case '成立':
            return $head . ($b['promulgated'] ? g_date($b['promulgated']) . 'に公布されました' . ($b['law_number'] ? '（法律第' . $b['law_number'] . '号）' : '') . '。' : '成立しました。');
        case '承認': return $head . '国会で承認されました。';
        case '継続審査': return $head . '最後の会期の終わりに継続審査になっています。';
        case '審議未了': return $head . '会期の終わりまでに議決されず、審議未了になりました。';
        case '委員会に付託されず': return $head . '委員会に付託されないまま会期が終わりました。';
        case '否決': return $head . '本会議で否決されました。';
        case '撤回': return $head . '提出者により撤回されました。';
        case '委員会で審査中': return $head . 'いまの国会（第' . (int)$b['session'] . '回）で、委員会に付託されて審査中です。';
        case '審議中': return $head . 'いまの国会（第' . (int)$b['session'] . '回）で審議中です。';
        default: return $head . '最新の状態は「' . $b['status'] . '」です。';
    }
}

function g_page_bills(int $page): void
{
    if (!g_bill_ready()) { http_response_code(404); g_head('準備中', '', '/bill', ['noindex' => true]); echo '<h1>準備中です</h1>'; g_foot(); return; }
    $st = (string)($_GET['s'] ?? ''); $kd = (string)($_GET['k'] ?? ''); $ses = (int)($_GET['n'] ?? 0); $q = trim((string)($_GET['q'] ?? ''));
    $w = ['1=1']; $a = [];
    if (in_array($st, G_BILL_STATUS, true)) { $w[] = 'status=?'; $a[] = $st; }
    if (isset(G_BILL_KINDS[$kd])) { $w[] = 'kind_code=?'; $a[] = $kd; }
    if ($ses > 0) { $w[] = "(','||sessions||',') LIKE ?"; $a[] = '%,' . $ses . ',%'; }
    if ($q !== '') { $w[] = '(title LIKE ? OR law_name LIKE ?)'; $a[] = '%' . $q . '%'; $a[] = '%' . $q . '%'; }
    $where = implode(' AND ', $w);
    $total = (int)g_val("SELECT COUNT(*) FROM bill WHERE $where", $a);
    $per = 40; $pages = max(1, (int)ceil($total / $per)); $page = min(max(1, $page), $pages);
    $rows = g_all("SELECT * FROM bill WHERE $where ORDER BY last_date DESC, id LIMIT $per OFFSET " . (($page - 1) * $per), $a);
    $all = (int)g_val('SELECT COUNT(*) FROM bill');
    $passed = (int)g_val("SELECT COUNT(*) FROM bill WHERE status IN ('成立','承認')");
    $sesRange = g_one('SELECT MIN(session) a, MAX(session) b FROM bill');
    $filtered = $st !== '' || $kd !== '' || $ses > 0 || $q !== '';
    $title = '法案の審議経過と国会での質疑｜第' . (int)$sesRange['a'] . '〜' . (int)$sesRange['b'] . '回国会の' . number_format($all) . '件';
    $desc = '国会に出された法案・予算・条約' . number_format($all) . '件について、提出から委員会・本会議・公布までの経過、'
          . '参議院の会派別の賛否、その法案に触れた国会の質疑と政府の答弁、関係する質問主意書を、公開データから機械的にまとめています。';
    g_head($title, $desc, '/bill', ['noindex' => $filtered || $page > 1, 'jsonld' => g_jsonld([g_crumbs([['ホーム', '/'], ['法案', '/bill']])])]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 法案</nav>'
       . '<p class="kick">Bills</p><h1>法案の審議経過と、国会での質疑</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p>'
       . '<div class="kv"><div class="c"><b>' . number_format($all) . '</b><span>法案・予算・条約</span></div>'
       . '<div class="c"><b>' . number_format($passed) . '</b><span>成立・承認</span></div>'
       . '<div class="c"><b>' . (int)$sesRange['a'] . '〜' . (int)$sesRange['b'] . '</b><span>国会の回次</span></div></div></section>';
    $nowN = (int)g_val("SELECT COUNT(*) FROM bill WHERE status IN ('委員会で審査中','審議中')");
    if ($nowN && !$filtered) {
        echo '<p class="answer" style="margin:14px 0 6px">いまの国会で審査中の法案は <a href="' . g_url('bill') . '?s=' . rawurlencode('委員会で審査中') . '"><b>' . $nowN . '件</b></a>。'
           . '法案ごとに、審査している委員会の委員と、国会へ声を届ける方法を載せています。</p>';
    }
    // 絞り込み
    $opt = fn(array $o, string $cur) => implode('', array_map(fn($k, $v) => '<option value="' . g_e((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . g_e($v) . '</option>', array_keys($o), $o));
    $sess = [];
    for ($i = (int)$sesRange['b']; $i >= (int)$sesRange['a']; $i--) { $sess[$i] = '第' . $i . '回'; }
    echo '<form class="panel" method="get" action="' . g_url('bill') . '" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">'
       . '<input type="search" name="q" value="' . g_e($q) . '" placeholder="件名で探す（例: 政治資金）" style="flex:1 1 220px;padding:8px;border:1px solid var(--line);border-radius:8px">'
       . '<select name="s"><option value="">状態すべて</option>' . $opt(array_combine(G_BILL_STATUS, G_BILL_STATUS), $st) . '</select>'
       . '<select name="k"><option value="">種類すべて</option>' . $opt(G_BILL_KINDS, $kd) . '</select>'
       . '<select name="n"><option value="0">会期すべて</option>' . $opt($sess, (string)$ses) . '</select>'
       . '<button class="btn" type="submit">絞り込む</button></form>';
    echo '<p class="note">' . number_format($total) . '件（最後の動きが新しい順）。</p>';
    foreach ($rows as $b) {
        echo '<div class="sp"><div class="m"><b>' . g_e(g_date($b['last_date'])) . '</b>'
           . g_bill_status_pill($b['status']) . '<span class="pill gray">' . g_e(G_BILL_KINDS[$b['kind_code']] ?? $b['kind']) . '</span>'
           . '<span class="note">' . g_e(g_bill_sessions($b)) . '</span>'
           . ((int)$b['speech_count'] ? '<span class="note">国会の発言 ' . number_format((int)$b['speech_count']) . '件</span>' : '')
           . '</div><p class="t"><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a></p></div>';
    }
    if ($pages > 1) {
        $qs = fn(int $p) => g_url('bill') . '?' . http_build_query(array_filter(['q' => $q, 's' => $st, 'k' => $kd, 'n' => $ses ?: null, 'page' => $p]));
        echo '<p class="more">' . ($page > 1 ? '<a class="btn o" href="' . g_e($qs($page - 1)) . '">前へ</a> ' : '')
           . g_e($page . ' / ' . $pages) . ($page < $pages ? ' <a class="btn o" href="' . g_e($qs($page + 1)) . '">次へ</a>' : '') . '</p>';
    }
    echo '<p class="note">出典：議案の一覧と経過は <a href="https://github.com/smartnews-smri/house-of-councillors" rel="noopener" target="_blank">smartnews-smri/house-of-councillors</a>（MIT・参議院の議案情報を整形したもの）、'
       . '発言は国立国会図書館の国会会議録検索システム、賛否は参議院の本会議投票結果。最終取得 ' . g_e(substr(g_meta('bill_csv_at'), 0, 10)) . '。</p>';
    g_foot();
}

function g_page_bill(string $id): void
{
    $b = g_bill_ready() ? g_bill($id) : null;
    if (!$b) { http_response_code(404); g_head('見つかりません', '', '/bill', ['noindex' => true]); echo '<h1>その法案はありません</h1><p><a href="' . g_url('bill') . '">法案の一覧へ</a></p>'; g_foot(); return; }
    $name = $b['law_name'] ?: $b['title'];
    $kind = G_BILL_KINDS[$b['kind_code']] ?? $b['kind'];
    $sp = g_all("SELECT * FROM bill_speech WHERE bill_id=? AND role<>'report' ORDER BY date DESC, speech_id DESC", [$id]);
    $gov = array_values(array_filter($sp, fn($s) => $s['role'] === 'gov'));
    $qs = array_values(array_filter($sp, fn($s) => $s['role'] === 'q'));
    $votes = g_all('SELECT * FROM bill_vote WHERE bill_id=? ORDER BY ord', [$id]);
    $trs = g_trackers_for_bill($b);
    $answer = g_bill_answer($b);
    $title = mb_strimwidth($name, 0, 60, '…', 'UTF-8') . '｜審議の経過と国会での質疑・答弁';
    $desc = mb_strimwidth($answer, 0, 120, '…', 'UTF-8');
    $faq = [['この法案はいまどうなっていますか？', $answer]];
    $last = $b['shu_committee'] ?: $b['san_committee'];
    if ($last) { $faq[] = ['どの委員会で審査されましたか？', trim(($b['shu_committee'] ? '衆議院は' . $b['shu_committee'] : '') . ($b['shu_committee'] && $b['san_committee'] ? '、' : '') . ($b['san_committee'] ? '参議院は' . $b['san_committee'] : '')) . 'です。']; }
    if ((int)$b['speech_count']) {
        $faq[] = ['国会でどれくらい議論されましたか？', '件名がそのまま出てくる国会の発言は、提出から最後の動きの30日後までに' . number_format((int)$b['speech_count']) . '件ありました（国会会議録検索システム）。このページでは、そのうち議員の質疑と政府の答弁を新しい順に載せています。'];
    }
    g_head($title, $desc, '/bill/' . $id, ['jsonld' => g_jsonld([
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)],
        g_crumbs([['ホーム', '/'], ['法案', '/bill'], [$name, '/bill/' . $id]])])]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('bill') . '">法案</a> › ' . g_e(mb_strimwidth($name, 0, 40, '…', 'UTF-8')) . '</nav>'
       . '<p class="kick">Bill · ' . g_e(g_bill_sessions($b)) . '</p><h1 style="font-size:clamp(20px,3.2vw,28px)">' . g_e($b['title']) . '</h1>'
       . '<p>' . g_bill_status_pill($b['status']) . ' <span class="pill gray">' . g_e($kind) . '</span>'
       . ($b['submitter'] ? ' <span class="pill gray">' . g_e(mb_strimwidth(preg_replace('/\s+/u', ' ', $b['submitter']) ?? '', 0, 40, '…', 'UTF-8')) . '</span>' : '') . '</p>'
       . '<div class="kv"><div class="c"><b>' . g_e(substr((string)$b['submitted'], 0, 4)) . '</b><span>提出 ' . g_e(substr((string)$b['submitted'], 5)) . '</span></div>'
       . '<div class="c"><b>' . g_e(substr((string)$b['last_date'], 0, 4)) . '</b><span>最後の動き ' . g_e(substr((string)$b['last_date'], 5)) . '</span></div>'
       . '<div class="c"><b>' . number_format(count($qs)) . '</b><span>議員の質疑<br>（載せた分）</span></div>'
       . '<div class="c"><b>' . number_format(count($gov)) . '</b><span>政府の答弁<br>（載せた分）</span></div></div></section>';
    echo '<p class="answer" style="font-size:15.5px;line-height:1.9;margin:14px 0 6px">' . g_e($answer) . '</p>';

    // この法案で変わること（scripts/build_bill_explain.py）。AIの要約であることと元の文書を、必ず一緒に出す
    $ex = !empty($b['explain']) ? (json_decode((string)$b['explain'], true) ?: []) : [];
    if ($ex) {
        $srcUrl = ($b['explain_source'] ?? '') === '要旨' ? $b['summary_url'] : $b['text_url'];
        $srcName = ($b['explain_source'] ?? '') === '要旨' ? '参議院の議案要旨' : '法律案の本文の「理由」';
        echo '<section class="panel" style="margin:14px 0"><h2 data-en="Summary" style="margin-top:0">この法案で変わること <span class="pill gray" style="vertical-align:middle">AIによる要約</span></h2><ul style="margin:6px 0 8px;padding-left:1.2em">';
        foreach ($ex as $line) { echo '<li style="margin:4px 0">' . g_e((string)$line) . '</li>'; }
        echo '</ul><p class="note" style="margin:0">' . g_e($srcName) . 'から、当社のサーバーの生成AI（gemma4）が作った要約です。'
           . '数字と日付は元の文と照らし合わせ、合わないものは載せていません。正確な内容は元の文書で確かめてください。'
           . ($srcUrl ? '　<a href="' . g_e($srcUrl) . '" rel="nofollow noopener" target="_blank">元の文書（PDF）</a>' : '') . '</p></section>';
    }

    echo '<h2 data-en="Timeline">審議の経過</h2><table style="table-layout:fixed"><tr><th style="width:9.6em">日付</th><th>できごと</th></tr>';
    foreach (g_bill_timeline($b) as [$d, $w]) { echo '<tr><td style="white-space:nowrap">' . g_e($d ? g_date($d) : '—') . '</td><td style="overflow-wrap:anywhere">' . g_e($w) . '</td></tr>'; }
    echo '</table>';
    if (strpos((string)$b['sessions'], ',') !== false) { echo '<p class="note">' . g_e(g_bill_sessions($b)) . 'に出ています。表はいちばん新しい会期の経過です。</p>'; }

    if ($votes) {
        $y = array_sum(array_column($votes, 'yes')); $n = array_sum(array_column($votes, 'no'));
        echo '<h2 data-en="Votes">参議院本会議の賛否（会派別）</h2><p>押しボタン式の採決の結果です。賛成 <b>' . $y . '</b>・反対 <b>' . $n . '</b>。</p>'
           . '<table style="table-layout:fixed"><tr><th>会派（所属議員数）</th><th style="width:4.2em;text-align:right">賛成</th><th style="width:4.2em;text-align:right">反対</th></tr>';
        foreach ($votes as $v) { echo '<tr><td style="overflow-wrap:anywhere">' . g_e($v['party']) . '（' . (int)$v['members'] . '）</td><td style="text-align:right">' . (int)$v['yes'] . '</td><td style="text-align:right">' . (int)$v['no'] . '</td></tr>'; }
        echo '</table>';
    } elseif ($b['san_plenary_mode'] || $b['shu_plenary_mode']) {
        echo '<h2 data-en="Votes">本会議の採決</h2><p>' . g_e(trim(($b['shu_plenary_mode'] ? '衆議院：' . $b['shu_plenary_mode'] . '　' : '') . ($b['san_plenary_mode'] ? '参議院：' . $b['san_plenary_mode'] : ''))) . '</p>';
    }

    $kw = mb_strlen($name) <= 30 ? $name : $b['title'];
    if ($gov) {
        echo '<h2 data-en="Government">政府の答弁</h2><p>件名を含む発言のうち、大臣・副大臣・政務官・政府参考人のものです。前後をそのまま抜いています。</p>';
        foreach (array_slice($gov, 0, 8) as $s) { g_bill_speech($s, $kw); }
    }
    if ($qs) {
        echo '<h2 data-en="Questions">議員の質疑・討論</h2>';
        foreach (array_slice($qs, 0, isset($_GET['all']) ? 40 : 10) as $s) { g_bill_speech($s, $kw); }
        if (count($qs) > 10 && !isset($_GET['all'])) { echo '<p class="more"><a class="btn o" href="' . g_url('bill/' . $id) . '?all=1">載せた質疑をすべて見る（' . count($qs) . '件）</a></p>'; }
    }
    if (!$sp) { echo '<p class="note">件名がそのまま出てくる発言は見つかりませんでした（委員会で審査されていない法案や、略称で呼ばれた発言は拾えていません）。</p>'; }

    echo g_bill_khouan($b);
    echo g_bill_voice($b, $trs);

    echo '<h2 data-en="Related">この法案につながるもの</h2><div class="panel"><ul>';
    if ((int)$b['shuisho_count'] && $b['shuisho_word']) {
        echo '<li>質問主意書と答弁書：「' . g_e($b['shuisho_word']) . '」を含むもの <b>' . (int)$b['shuisho_count'] . '件</b>　'
           . '<a href="https://kurage.exbridge.jp/kshuisho.php/search?q=' . rawurlencode($b['shuisho_word']) . '&amp;ref=giin-bill" rel="noopener" target="_blank">Kurage 質問主意書アシストで読む</a></li>';
    }
    foreach ($trs as $t) { echo '<li>国会トラッカー：<a href="' . g_url('tracker/' . $t['key']) . '">' . g_e($t['name']) . '</a></li>'; }
    echo '<li>同じ名前の法案を探す：<a href="' . g_url('bill') . '?q=' . rawurlencode(mb_strimwidth($name, 0, 20, '', 'UTF-8')) . '">法案の一覧で探す</a></li>';
    echo '</ul></div>';

    echo '<h2 data-en="Sources">一次情報</h2><div class="panel"><ul>';
    foreach ([['url', '参議院 議案情報（経過）'], ['summary_url', '議案要旨（PDF）'], ['text_url', '法律案の本文（PDF）'], ['law_url', '成立した法律（PDF）'], ['san_vote_url', '参議院 本会議投票結果']] as [$k, $label]) {
        if (!empty($b[$k])) { echo '<li><a href="' . g_e($b[$k]) . '" rel="nofollow noopener" target="_blank">' . g_e($label) . '</a></li>'; }
    }
    echo '</ul></div>';
    echo '<p class="note">集め方：議案の経過は smartnews-smri/house-of-councillors（MIT）、発言は国会会議録検索システムで件名をそのまま引き、提出から最後の動きの30日後までのものに限っています（委員長報告は除く）。'
       . '立場（質疑・答弁）は話者の肩書から機械的に分けています。要約も賛否の判断もしていません。最終取得 ' . g_e((string)$b['speech_fetched']) . '。</p>';
    g_foot();
}

function g_bill_speech(array $s, string $kw): void
{
    echo '<div class="sp"><div class="m"><b>' . g_e(g_date($s['date'])) . '</b>'
       . '<span class="pill gray">' . g_e($s['house'] . ' ' . $s['meeting']) . '</span><b>' . g_e($s['speaker']) . '</b>'
       . ($s['position'] ? '<span class="note">' . g_e($s['position']) . '</span>' : '')
       . ($s['role'] === 'gov' ? '<span class="pill gray">答弁</span>' : '')
       . '</div><p class="t">' . g_mark(g_e((string)$s['excerpt']), $kw) . '</p>'
       . '<div class="lk"><a href="' . g_e($s['url']) . '" rel="nofollow noopener" target="_blank">この発言を会議録で読む</a></div></div>';
}

/** 合意点マップ（kconsensus）を用意している話題。トラッカーの key → URL。意見が大きく割れている話題だけ */
const G_BILL_CONSENSUS = ['shohizei-genzei' => 'https://kurage.exbridge.jp/kconsensus.php/t/shohizei/'];

/**
 * 「この法案に声を届けるには」。いまの国会で審査中の法案だけに出す（委員名簿は「いまの」委員なので、過去の法案には使えない）。
 * 当社は意見を集めない。誰が審査していて、どこへどう届ければいいかを、国会の公開情報だけで示す。
 */
function g_bill_voice(array $b, array $trs): string
{
    if (!in_array($b['status'], ['委員会で審査中', '審議中'], true)) { return ''; }
    if (!(bool)g_val("SELECT 1 FROM sqlite_master WHERE type='table' AND name='committee_member'")) { return ''; }
    $asof = substr(g_meta('committee_at'), 0, 10);
    $h = '<h2 data-en="Your voice">この法案に声を届けるには</h2>'
       . '<p>法案は、付託された委員会で審査されてから本会議で採決されます。当社の Kurage 法案AIインタビューで寄せられた意見も、国会に届く公式の窓口ではありません。'
       . 'だれが審査しているかと、届ける方法を国会の公開情報から並べています。</p>';
    $aichi = [];
    foreach (['衆議院' => 'shu', '参議院' => 'san'] as $house => $k) {
        $comm = (string)$b[$k . '_committee'];
        if ($comm === '') { continue; }
        $mem = g_all('SELECT m.*, g.slug FROM committee_member m LEFT JOIN giin g ON g.id=m.giin_id WHERE m.house=? AND m.committee=? ORDER BY m.ord', [$house, $comm]);
        $h .= '<div class="panel"><p style="margin:0 0 6px"><b>' . g_e($house . ' ' . $comm) . '</b>';
        if (!$mem) {
            $h .= '<br><span class="note">この委員会の名簿は、まだ公開されていません。</span></p></div>';
            continue;
        }
        $kaiha = [];
        foreach ($mem as $m) { $kaiha[$m['kaiha']] = ($kaiha[$m['kaiha']] ?? 0) + 1; }
        arsort($kaiha);
        $lead = array_values(array_filter($mem, fn($m) => in_array($m['role'], ['委員長', '理事', '会長'], true)));
        $h .= '　<span class="note">委員 ' . count($mem) . '人（' . g_e($asof) . '時点）</span></p>'
            . '<p style="margin:0 0 6px">' . implode('、', array_map(fn($m) => g_e($m['role'] . ' ' . $m['name'] . '（' . $m['kaiha'] . '）'), array_slice($lead, 0, 12))) . '</p>'
            . '<p class="note" style="margin:0 0 6px">会派ごとの人数：' . g_e(implode('・', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($kaiha), $kaiha))) . '</p>'
            . '<details><summary style="cursor:pointer">委員の全員を見る</summary><p style="margin:6px 0 0">'
            . implode('、', array_map(function ($m) {
                  $n = g_e($m['name']) . '（' . g_e($m['kaiha']) . '）';
                  return $m['slug'] ? '<a href="' . g_url($m['slug']) . '">' . $n . '</a>' : $n;
              }, $mem))
            . '</p></details>'
            . '<p class="note" style="margin:6px 0 0"><a href="' . g_e($mem[0]['url']) . '" rel="nofollow noopener" target="_blank">' . g_e($house) . 'の委員名簿</a></p></div>';
        foreach ($mem as $m) { if ($m['slug']) { $aichi[] = $m; } }
    }
    if ($aichi) {
        $h .= '<p><b>愛知の議員で、この委員会の委員の人：</b>'
            . implode('、', array_map(fn($m) => '<a href="' . g_url($m['slug']) . '">' . g_e($m['name']) . '</a>（' . g_e($m['house'] . '・' . $m['role']) . '）', $aichi))
            . '。議員のページから、これまでの国会での発言と公式サイトを見られます。</p>';
    }
    $h .= '<div class="panel"><p style="margin:0 0 6px"><b>国会への請願</b></p>'
        . '<p style="margin:0 0 6px">国会に意見を届ける公式の方法が請願です。請願書は、議員の紹介を受けて、衆議院議長または参議院議長あてに出します。'
        . '同じ人が同じ会期に同じ趣旨の請願を重ねて出すことはできません。</p>'
        . '<p class="note" style="margin:0"><a href="https://www.shugiin.go.jp/internet/itdb_annai.nsf/html/statics/tetuzuki/seigan.htm" rel="nofollow noopener" target="_blank">衆議院：請願・陳情書・意見書の手続</a>　'
        . '<a href="https://www.sangiin.go.jp/japanese/annai/index.html" rel="nofollow noopener" target="_blank">参議院：請願・地方議会からの意見書の提出</a></p></div>';
    foreach ($trs as $t) {
        if (isset(G_BILL_CONSENSUS[$t['key']])) {
            $h .= '<p>この話題は意見が大きく割れています。賛否の割れ方と、どの立場からも賛成が多い点を、'
                . '<a href="' . g_e(G_BILL_CONSENSUS[$t['key']]) . '?ref=giin-bill" rel="noopener" target="_blank">合意点マップ</a>で見られます。</p>';
            break;
        }
    }
    return $h;
}

/**
 * Kurage 法案AIインタビュー（khouan）の枠を3つ。khouan に取り込んだ法案（いまの国会で審議中）だけに出す。
 * khouan の公開 API（読み取り専用）をサーバー側で読み、10分だけ手元に置く。khouan が落ちていても、このページは出す
 * （前に読めた内容があればそれを出し、無ければ枠ごと出さない）。当サイトは意見を集めない。集めるのは khouan。
 */
function g_khouan_summary(string $id): ?array
{
    $api = defined('G_KHOUAN_API') ? G_KHOUAN_API : 'https://kurage.exbridge.jp/khouan.php/api/bill/';
    $dir = __DIR__ . '/../data/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $dir = sys_get_temp_dir(); }
    $f = $dir . '/khouan_' . preg_replace('/[^0-9a-z-]/', '', $id) . '.json';
    $old = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $age = is_file($f) ? time() - (int)filemtime($f) : PHP_INT_MAX;
    if (is_array($old) && $age < (isset($old['_err']) ? 120 : 600)) {
        return isset($old['_none']) || isset($old['_err']) ? ($old['_last'] ?? null) : $old;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true, 'header' => "User-Agent: xb4g-giin\r\n"]]);
    $raw = @file_get_contents($api . rawurlencode($id) . '/summary', false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; } }
    $d = $raw !== false ? json_decode($raw, true) : null;
    if ($code === 200 && is_array($d) && isset($d['bill_id'])) {
        @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE));
        return $d;
    }
    if ($code === 404) {   // khouan に取り込んでいない法案
        @file_put_contents($f, json_encode(['_none' => 1]));
        return null;
    }
    // 落ちている・遅い: 前に読めた内容を出し、2分は聞きに行かない
    $last = is_array($old) ? (isset($old['_err']) || isset($old['_none']) ? ($old['_last'] ?? null) : $old) : null;
    @file_put_contents($f, json_encode(['_err' => 1, '_last' => $last], JSON_UNESCAPED_UNICODE));
    return $last;
}

function g_bill_khouan(array $b): string
{
    if (!in_array($b['status'], ['委員会で審査中', '審議中'], true)) { return ''; }
    $d = g_khouan_summary((string)$b['id']);
    if (!$d || empty($d['accepting'])) { return ''; }
    $ref = '#ref=giin-bill';
    $h = '<h2 data-en="AI Interview">この法案に意見を伝える（AIインタビュー）</h2><div class="panel">'
       . '<p style="margin:0 0 8px">AIが5問前後で、賛否・理由・暮らしへの影響・懸念・提案を聞き、答えに合わせて質問を重ねて「意見のまとめ」を作ります。'
       . 'まとめを確かめて同意したときだけ保存し、公開するかどうかも選べます。名前は聞きません。</p>'
       . '<p style="margin:0 0 6px"><a class="btn" href="' . g_e($d['interview_url'] . $ref) . '" rel="noopener">AIインタビューで意見を伝える（約5分）</a></p>'
       . '<p class="note" style="margin:0">当社の別のシステム <a href="' . g_e($d['bill_url'] . $ref) . '" rel="noopener">Kurage 法案AIインタビュー</a> が受け付けます。国会の公式の窓口ではありません。</p></div>';

    $h .= '<h2 data-en="Opinions">みんなの意見（話題）</h2><div class="panel">';
    $n = (int)($d['opinions'] ?? 0);
    if (!empty($d['topics'])) {
        $h .= '<p style="margin:0 0 6px">公開に同意された意見 <b>' . $n . '件</b> を、意味の近さで <b>' . count($d['topics']) . 'つ</b>の話題に分けました。話題の名前と要約はAIが付けたものです。件数は意見の数で、賛成・反対の数ではありません。</p><ul style="margin:0;padding-left:1.2em">';
        foreach ($d['topics'] as $t) {
            $h .= '<li style="margin:8px 0"><b>' . g_e($t['name']) . '</b> <span class="note">' . (int)$t['size'] . '件</span><br>' . g_e($t['summary']);
            foreach (array_slice($t['examples'] ?? [], 0, 1) as $e) {
                $h .= '<br><span class="note">例：「' . g_e(mb_strimwidth((string)$e['text'], 0, 90, '…', 'UTF-8')) . '」 <a href="' . g_e($e['url'] . $ref) . '" rel="noopener">まとめを読む</a></span>';
            }
            $h .= '</li>';
        }
        $h .= '</ul>';
    } elseif ($n > 0) {
        $h .= '<p style="margin:0 0 6px">公開に同意された意見は <b>' . $n . '件</b> です。5件集まると、話題に分けて表示します。</p><ul style="margin:0;padding-left:1.2em">';
        foreach (array_slice($d['recent'] ?? [], 0, 3) as $e) {
            $h .= '<li>「' . g_e(mb_strimwidth((string)$e['text'], 0, 90, '…', 'UTF-8')) . '」 <a href="' . g_e($e['url'] . $ref) . '" rel="noopener">まとめを読む</a></li>';
        }
        $h .= '</ul>';
    } else {
        $h .= '<p style="margin:0">まだ公開された意見はありません。AIインタビューで公開に同意された意見が集まると、ここに話題ごとに表示します。</p>';
    }
    $h .= '<p class="note" style="margin:6px 0 0"><a href="' . g_e($d['bill_url'] . $ref) . '" rel="noopener">意見の一覧と話題を見る</a></p></div>';

    $h .= '<h2 data-en="Consensus">どこで割れて、どこで一致しているか（合意形成AI）</h2><div class="panel">';
    if (!empty($d['kconsensus_url'])) {
        $h .= '<p style="margin:0 0 6px">意見の話題から、賛成か反対かを問える論点を立てました。論点に賛否を押すと、意見のグループと、立場が違っても一致する点が出ます。</p>'
            . '<p style="margin:0"><a class="btn" href="' . g_e($d['kconsensus_url'] . $ref) . '" rel="noopener">Kurage 合意形成AIで見る</a></p>';
    } else {
        $h .= '<p style="margin:0">意見が集まり、話題に分けられたら、話題から賛否を問える論点を立てて <a href="https://kurage.exbridge.jp/kconsensus.php/' . $ref . '" rel="noopener">Kurage 合意形成AI（合意点マップ）</a> に渡します。それまでは準備中です。</p>';
    }
    return $h . '</div>';
}
