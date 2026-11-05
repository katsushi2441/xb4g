<?php
/**
 * 政策の流れトラッカーの「契約」型の分野（政府広報 /flow/koho）。2026-11-05
 * データは kseisakuflow の build_koho.py が data/flow/<分野>.json と data/flow/<分野>/{m,s,p}_<鍵>.json に書く。
 *
 * **入札者数・落札率の 0 と空欄は「記録なし」と出す**（0者・0% と書かない）。評価語・推測は書かない。
 */
declare(strict_types=1);

function g_fk_shard(string $slug, string $kind, string $key): ?array
{
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $slug) || !preg_match('/^[0-9A-Za-z]{1,20}$/', $key)) { return null; }
    $f = __DIR__ . '/../data/flow/' . $slug . '/' . $kind . '_' . $key . '.json';
    return is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
}

/** 検索用にそろえる（全角英数→半角・空白を除く・小文字）。intl が無いサーバーでも動くよう mb_convert_kana で */
function g_fk_norm(string $s): string { return mb_strtolower(preg_replace('/[\s　]+/u', '', mb_convert_kana($s, 'asKV'))); }

function g_fk_bid(string $v): string { return ($v === '' || (float)$v == 0.0) ? '記録なし' : g_e($v) . '者'; }
function g_fk_rate(string $v): string { return ($v === '' || (float)$v == 0.0) ? '記録なし' : g_e($v) . '%'; }
function g_fk_ex(array $d, string $y): string { return (string)($d['exec_year'][$y] ?? ''); }

function g_fk_src(array $d, string $y, string $prefix): string
{
    $name = (string)($d['sources'][$y][$prefix] ?? ('RS ' . $y));
    return g_flow_src('行政事業レビュー見える化サイト ' . $name . '（' . $y . '年度シート）', 'https://rssystem.go.jp/download-csv/' . $y, (string)$d['fetched']);
}

/** 契約1件 */
function g_fk_row(string $slug, array $c, bool $showPayee = true, bool $showMinistry = true): string
{
    $payee = g_e($c['py']);
    if ($showPayee && $c['k'] !== '') { $payee = '<a href="' . g_url('flow/' . $slug . '/s/' . $c['k']) . '">' . $payee . '</a>'; }
    return '<li><b>' . g_e($c['sm']) . '</b>　' . g_flow_yen($c['amt'])
         . '<br><span class="note">' . ($showPayee ? '契約先 ' . $payee . '　' : '')
         . ($showMinistry ? g_e($c['mn']) . '　' : '')
         . '<a href="' . g_url('flow/' . $slug . '/p/' . $c['pid']) . '">' . g_e($c['pj']) . '</a>'
         . '<br>' . g_e($c['ex']) . '年度執行　' . g_e($c['mt'] !== '' ? $c['mt'] : '契約方式の記載なし') . ($c['mt2'] !== '' ? '（' . g_e($c['mt2']) . '）' : '')
         . '　入札者数 ' . g_fk_bid((string)$c['bid']) . '　落札率 ' . g_fk_rate((string)$c['rate'])
         . (isset($c['tier']) && $c['tier'] === 'p' ? '　<span class="pill gray">事業名に「' . g_e((string)$c['hw']) . '」</span>' : '')
         . '</span></li>';
}

function g_fk_range(array $d): string
{
    return '<div class="fwarn"><b>データの範囲</b>：' . g_e($d['range_note']) . '<br>拾い方：契約概要または事業名に「' . g_e($d['words_label']) . '」のいずれかの語を含む契約です（補助金等の交付は除く）。'
         . '事業名にだけ語を含む契約には、広報以外の契約も含まれます（一覧では「事業名に〜」と印を付けています）。入札者数・落札率が 0 または空欄のものは「記録なし」と表示しています。</div>';
}

function g_page_flow_koho(string $slug, array $d): void
{
    $f = $d['field']; $y0 = $d['years'][0]; $y1 = $d['years'][1] ?? $y0;
    $s0 = $d['summary'][$y0]; $s1 = $d['summary'][$y1];
    $q = trim((string)($_GET['q'] ?? ''));
    $desc = g_fk_ex($d, $y0) . '年度に執行された国の広報・周知・啓発・イベントなどの契約' . number_format((int)$s0['n']) . '件（' . g_flow_yen($s0['sum']) . '）を、府省・契約先・事業ごとに。契約の方式・入札者数・落札率と、事業の成果目標と実績を行政事業レビューから並べます。';
    g_head($f['title'], $desc, '/flow/' . $slug, ['nosuffix' => true, 'image' => g_flow_og($slug), 'q' => $q, 'noindex' => $q !== '', 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug]])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow') . '">' . g_e(G_FLOW_NAME) . '</a> › ' . g_e($f['name']) . '</nav>'
       . '<p class="kick">Policy Flow Tracker</p><h1>' . g_e($f['title']) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($f['lead']) . '</p>'
       . '<div class="kv"><div class="c"><b>' . number_format((int)$s0['n']) . '</b><span>' . g_e($s0['exec_year']) . '年度執行の契約</span></div>'
       . '<div class="c"><b>' . g_flow_yen($s0['sum']) . '</b><span>その金額の合計</span></div>'
       . '<div class="c"><b>' . number_format((int)$s0['payees']) . '</b><span>契約先</span></div></div></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】政府広報｜' . $s0['exec_year'] . '年度の広報・周知・啓発などの契約' . number_format((int)$s0['n']) . '件・' . g_flow_yen($s0['sum']) . 'を、契約先・入札者数・落札率と成果目標で', 'flow/' . $slug, 'flow-' . $slug);
    echo g_fk_range($d);
    echo '<div class="fstages">'
       . '<div class="fstage"><div class="h">' . g_e($s0['exec_year']) . '年度執行</div><b>' . number_format((int)$s0['n']) . '件</b><span>' . g_flow_yen($s0['sum']) . '（契約概要に語を含む ' . number_format((int)$s0['n_s']) . '件・' . g_flow_yen($s0['sum_s']) . '）・' . (int)$s0['ministries'] . '府省等・' . number_format((int)$s0['projects']) . '事業</span></div>'
       . '<div class="fstage"><div class="h">' . g_e($s1['exec_year']) . '年度執行</div><b>' . number_format((int)$s1['n']) . '件</b><span>' . g_flow_yen($s1['sum']) . '（契約概要に語を含む ' . number_format((int)$s1['n_s']) . '件・' . g_flow_yen($s1['sum_s']) . '）・' . (int)$s1['ministries'] . '府省等・' . number_format((int)$s1['projects']) . '事業</span></div>'
       . '</div>' . g_fk_src($d, $y0, '5-1_');

    // 契約先を探す
    echo '<h2 id="payees" data-en="Payees">契約先を探す</h2><form action="' . g_url('flow/' . $slug) . '#payees" method="get"><input id="fq" type="search" name="q" value="' . g_e($q) . '" placeholder="会社名で探す（例：吉本興業、博報堂、電通）"> <button class="btn" style="padding:8px 16px">探す</button></form>';
    $list = $d['payees'];
    if ($q !== '') {
        $nq = g_fk_norm($q);
        $list = array_values(array_filter($list, function ($p) use ($nq) {
            foreach ($p['names'] as $n) { if (str_contains(g_fk_norm($n), $nq)) { return true; } }
            return $p['corp'] === $nq;
        }));
        echo '<p>「' . g_e($q) . '」を名前に含む契約先：' . count($list) . '件（法人番号でまとめています。名前の一部が同じ別の法人も出ます）</p>';
    } else {
        echo '<p class="note">' . g_e($s0['exec_year']) . '年度執行の金額の大きい順に60件。法人番号のある契約先だけにページがあります。</p>';
        $list = array_slice($list, 0, 60);
    }
    echo '<ul class="flist">';
    foreach (array_slice($list, 0, 200) as $p) {
        echo '<li><a href="' . g_url('flow/' . $slug . '/s/' . $p['k']) . '"><b>' . g_e($p['name']) . '</b></a>　<span class="note">法人番号 ' . g_e($p['corp']) . '</span><br><span class="note">'
           . g_e($s0['exec_year']) . '年度執行 ' . (int)$p['stats'][$y0]['n'] . '件・' . g_flow_yen($p['stats'][$y0]['sum']) . '　'
           . g_e($s1['exec_year']) . '年度執行 ' . (int)$p['stats'][$y1]['n'] . '件・' . g_flow_yen($p['stats'][$y1]['sum']) . '</span></li>';
    }
    echo '</ul>';

    // 府省ごと
    echo '<h2 id="ministries" data-en="Ministries">府省ごとの契約</h2><div class="grid">';
    foreach ($d['ministries'] as $m) {
        $a = $m['stats'][$y0]; $b = $m['stats'][$y1];
        echo '<a class="card" href="' . g_url('flow/' . $slug . '/m/' . $m['code']) . '"><div class="nm">' . g_e($m['name']) . '</div><div class="n">'
           . g_e($s0['exec_year']) . '年度 ' . (int)$a['n'] . '件・' . g_flow_yen($a['sum']) . '<br><span class="note">' . g_e($s1['exec_year']) . '年度 ' . (int)$b['n'] . '件・' . g_flow_yen($b['sum']) . '</span></div></a>';
    }
    echo '</div>';

    // 契約の方式
    echo '<h2 data-en="Methods">契約の方式（' . g_e($s0['exec_year']) . '年度執行）</h2><ul class="flist">';
    foreach ($d['methods'] as $m) { echo '<li><b>' . g_e($m['name']) . '</b>　' . number_format((int)$m['n']) . '件・' . g_flow_yen($m['sum']) . '</li>'; }
    echo '</ul>' . g_fk_src($d, $y0, '5-1_');

    // 金額の大きい契約
    echo '<h2 data-en="Largest">金額の大きい契約（' . g_e($s0['exec_year']) . '年度執行・30件）</h2><ul class="flist">';
    foreach ($d['top'] as $c) { echo g_fk_row($slug, $c); }
    echo '</ul>' . g_fk_src($d, $y0, '5-1_');

    // 事業と成果目標
    echo '<h2 id="projects" data-en="Outcomes">事業ごとの成果目標の記載</h2><p class="note">行政事業レビューの「効果発現経路 目標・実績」に、成果目標（アウトカム）の指標がいくつ書かれ、そのうち実績値か定性的な実績が書かれたものがいくつあるか。' . g_e($s0['exec_year']) . '年度執行の契約額の大きい事業から40件。</p><ul class="flist">';
    foreach (array_slice($d['projects'], 0, 40) as $p) {
        echo '<li><a href="' . g_url('flow/' . $slug . '/p/' . $p['id']) . '"><b>' . g_e($p['name']) . '</b></a><br><span class="note">' . g_e($p['mn']) . '　広報等の契約 ' . (int)$p['n'] . '件（' . g_e($s0['exec_year']) . '年度執行 ' . g_flow_yen($p['sum0']) . '）　成果目標の指標 '
           . (int)$p['n_outcome'] . '件（実績の記載 ' . (int)$p['n_outcome_actual'] . '件）</span></li>';
    }
    echo '</ul>' . g_fk_src($d, $y0, '3-1_');

    g_fk_kokkai($d);
    g_fk_credit($d);
    g_foot();
}

function g_fk_kokkai(array $d): void
{
    $tr = function_exists('g_tracker') ? array_filter(array_map(fn($k) => g_tracker($k), (array)$d['trackers'])) : [];
    if (!$tr) { return; }
    echo '<h2 data-en="Diet">国会の質疑（Kurage 国会トラッカー）</h2><div class="grid">';
    foreach ($tr as $t) {
        $st = function_exists('g_tracker_stats') ? g_tracker_stats($t['key']) : [];
        echo '<a class="card" href="' . g_url('tracker/' . $t['key']) . '#ref=flow-koho"><div class="nm">' . g_e($t['name']) . '</div><div class="n">'
           . ($st ? '質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件' : '') . '</div></a>';
    }
    echo '</div>';
}

function g_fk_credit(array $d): void
{
    echo '<h2 data-en="Sources">出典と作り方</h2><div class="panel"><ul style="margin:0;padding-left:18px">'
       . '<li>出典：<a href="https://rssystem.go.jp/" rel="noopener" target="_blank">行政事業レビュー見える化サイト</a>（CSVダウンロード 2024年度・2025年度の「5-1 支出先_支出情報」「3-1 効果発現経路_目標・実績」「4-1 点検・評価」「1-2 基本情報_事業概要等」「2-1 予算・執行_サマリ」）のデータを加工して作成。公共データ利用規約（第1.0版）（PDL1.0）に準拠。取得 ' . g_e((string)$d['fetched']) . '。</li>'
       . '<li>契約の行は、支出先の記載のうち「契約概要」と「金額」があるものです。同じ契約が複数の事業に分けて記載されている場合は、事業ごとに1件として数えています。</li>'
       . '<li>契約先は法人番号でまとめています。法人番号の無い記載（「その他」「個人Ａ」など）と国の機関には、契約先のページを作っていません。</li>'
       . '<li>要約・論評・評価はしていません。誤りに気づいたら <a href="https://exbridge.jp/contact.php#ref=flow-fix">お問い合わせ</a> からお知らせください。</li></ul></div>';
}

/** /flow/<分野>/m/<建制順> */
function g_page_flow_koho_ministry(string $slug, string $code): void
{
    $d = g_flow_load($slug); $m = $d ? g_fk_shard($slug, 'm', $code) : null;
    if (!$m) { g_flow_404($slug); return; }
    $f = $d['field']; $y0 = $d['years'][0];
    $a = $m['stats'][$y0];
    $title = $m['name'] . 'の広報・周知・啓発の契約｜契約先と金額';
    $desc = $m['name'] . 'が' . g_fk_ex($d, $y0) . '年度に執行した広報・周知・啓発・イベントなどの契約' . (int)$a['n'] . '件（' . g_flow_yen($a['sum']) . '）の契約先・金額・契約の方式・入札者数・落札率。行政事業レビューから。';
    g_head($title, $desc, '/flow/' . $slug . '/m/' . $code, ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug], [$m['name'], '/flow/' . $slug . '/m/' . $code]])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . '</a> › ' . g_e($m['name']) . '</nav>'
       . '<p class="kick">' . g_e($f['name']) . '</p><h1>' . g_e($m['name']) . 'の広報・周知・啓発などの契約</h1>'
       . '<div class="kv">';
    foreach ($d['years'] as $y) { echo '<div class="c"><b>' . (int)$m['stats'][$y]['n'] . '件</b><span>' . g_e(g_fk_ex($d, $y)) . '年度執行 ' . g_flow_yen($m['stats'][$y]['sum']) . '</span></div>'; }
    echo '</div></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】' . $m['name'] . 'の広報・周知・啓発の契約' . (int)$a['n'] . '件・' . g_flow_yen($a['sum']) . '（' . g_fk_ex($d, $y0) . '年度執行）の契約先と入札者数・落札率', 'flow/' . $slug . '/m/' . $code, 'flow-' . $slug . '-m');
    echo g_fk_range($d);
    foreach ($d['years'] as $y) {
        $cs = array_values(array_filter($m['contracts'], fn($c) => $c['y'] === $y));
        echo '<h2>' . g_e(g_fk_ex($d, $y)) . '年度執行（' . count($cs) . '件）</h2><ul class="flist">';
        foreach ($cs as $c) { echo g_fk_row($slug, $c, true, false); }
        echo '</ul>' . g_fk_src($d, $y, '5-1_');
    }
    echo '<p class="more"><a class="btn o" href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . 'へ戻る</a></p>';
    g_fk_credit($d);
    g_foot();
}

/** /flow/<分野>/s/<法人番号> */
function g_page_flow_koho_payee(string $slug, string $key): void
{
    $d = g_flow_load($slug); $p = $d ? g_fk_shard($slug, 's', $key) : null;
    if (!$p) { g_flow_404($slug); return; }
    $f = $d['field']; $y0 = $d['years'][0];
    $nAll = 0; $sAll = 0.0;
    foreach ($d['years'] as $y) { $nAll += (int)$p['stats'][$y]['n']; $sAll += (float)$p['stats'][$y]['sum']; }
    $title = $p['name'] . 'が国から受けた広報・周知の契約';
    $desc = $p['name'] . '（法人番号 ' . $p['corp'] . '）が国から受けた広報・周知・啓発・イベントなどの契約' . $nAll . '件（' . g_flow_yen($sAll) . '）と、そのほかの契約。府省・事業・金額・契約の方式・入札者数・落札率を行政事業レビューから。';
    g_head($title, $desc, '/flow/' . $slug . '/s/' . $key, ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug], [$p['name'], '/flow/' . $slug . '/s/' . $key]])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . '</a> › 契約先</nav>'
       . '<p class="kick">法人番号 ' . g_e($p['corp']) . '</p><h1>' . g_e($p['name']) . 'が国から受けた広報・周知の契約</h1>'
       . (count($p['names']) > 1 ? '<p class="note">記載の表記：' . g_e(implode('／', $p['names'])) . '</p>' : '')
       . '<div class="kv">';
    foreach ($d['years'] as $y) { echo '<div class="c"><b>' . (int)$p['stats'][$y]['n'] . '件</b><span>' . g_e(g_fk_ex($d, $y)) . '年度執行 ' . g_flow_yen($p['stats'][$y]['sum']) . '</span></div>'; }
    echo '</div></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】' . $p['name'] . 'が国から受けた広報・周知・啓発の契約' . $nAll . '件・' . g_flow_yen($sAll) . '（行政事業レビュー）', 'flow/' . $slug . '/s/' . $key, 'flow-' . $slug . '-s');
    echo g_fk_range($d);
    foreach ($d['years'] as $y) {
        $cs = array_values(array_filter($p['contracts'], fn($c) => $c['y'] === $y));
        if (!$cs) { continue; }
        echo '<h2>' . g_e(g_fk_ex($d, $y)) . '年度執行の広報等の契約（' . count($cs) . '件・' . g_flow_yen(array_sum(array_column($cs, 'amt'))) . '）</h2><ul class="flist">';
        foreach ($cs as $c) { echo g_fk_row($slug, $c, false, true); }
        echo '</ul>' . g_fk_src($d, $y, '5-1_');
    }
    $ot = (array)($p['others'] ?? []);
    if ($ot) {
        echo '<h2>広報等の語を含まない契約・補助金等の交付</h2><p class="note">同じ法人番号の、ほかの支出先の記載（契約概要と金額があるもの）。';
        foreach ($d['years'] as $y) {
            $os = $p['others_stats'][$y] ?? null;
            if ($os) { echo g_e(g_fk_ex($d, $y)) . '年度執行：契約 ' . (int)$os['n'] . '件・' . g_flow_yen($os['sum']) . '、補助金等の交付 ' . (int)$os['n_grant'] . '件・' . g_flow_yen($os['sum_grant']) . '。'; }
        }
        echo (count($ot) < (int)($p['others_total'] ?? 0) ? '金額の大きい' . count($ot) . '件を出しています。' : '') . '</p><ul class="flist">';
        foreach ($ot as $c) { echo g_fk_row($slug, $c, false, true) . (!empty($c['grant']) ? '' : ''); }
        echo '</ul>' . g_fk_src($d, $y0, '5-1_');
    }
    echo '<p class="note">法人番号でまとめています。名前の一部が同じ別の法人（グループ会社など）は別のページです。<a href="' . g_url('flow/' . $slug) . '?q=' . rawurlencode(mb_substr($p['name'], 0, 4)) . '#payees">名前で探す</a></p>';
    echo '<p class="more"><a class="btn o" href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . 'へ戻る</a></p>';
    g_fk_kokkai($d);
    g_fk_credit($d);
    g_foot();
}

/** /flow/<分野>/p/<予算事業ID>（契約型） */
function g_page_flow_koho_project(string $slug, string $id): void
{
    $d = g_flow_load($slug); $p = $d ? g_fk_shard($slug, 'p', $id) : null;
    if (!$p || empty($p['sheets'])) { g_flow_404($slug); return; }
    $f = $d['field']; $s0 = $p['sheets'][0];
    $title = mb_strlen($s0['name']) > 40 ? mb_substr($s0['name'], 0, 40) . '…' : $s0['name'];
    $desc = $s0['mn'] . 'の「' . $s0['name'] . '」の広報・周知の契約' . count($p['contracts']) . '件と、成果目標・実績・点検の記載。行政事業レビューから。';
    g_head($title . '｜広報の契約と成果目標', $desc, '/flow/' . $slug . '/p/' . $id, ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug], [$title, '/flow/' . $slug . '/p/' . $id]])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . '</a> › 予算事業ID ' . g_e($id) . '</nav>'
       . '<p class="kick">' . g_e($s0['mn']) . ($s0['bureau'] ? '　' . g_e($s0['bureau']) : '') . '</p><h1 style="overflow-wrap:anywhere">' . g_e($s0['name']) . '</h1></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】' . $s0['name'] . '（' . $s0['mn'] . '）の広報の契約と成果目標', 'flow/' . $slug . '/p/' . $id, 'flow-' . $slug . '-p');
    echo '<h2>事業の概要</h2><div class="panel"><dl class="fdl"><dt>目的</dt><dd>' . nl2br(g_e(str_replace('／', "\n", (string)$s0['purpose']))) . '</dd>'
       . '<dt>概要</dt><dd>' . nl2br(g_e(str_replace('／', "\n", (string)$s0['overview']))) . '</dd>'
       . ($s0['url'] ? '<dt>事業のページ</dt><dd><a href="' . g_e((string)$s0['url']) . '" rel="noopener" target="_blank">' . g_e((string)$s0['url']) . '</a></dd>' : '') . '</dl>'
       . g_fk_src($d, $s0['y'], '1-2_') . '</div>';
    if ($s0['budget']) {
        echo '<h2>予算と執行</h2>';
        foreach (array_slice($s0['budget'], 0, 3) as $b) {
            echo '<div class="fyear"><p class="y">' . g_e($b['year']) . '年度</p><dl class="fdl"><dt>当初予算</dt><dd>' . g_flow_yen($b['initial']) . '</dd><dt>歳出予算現額</dt><dd>' . g_flow_yen($b['current']) . '</dd><dt>執行額</dt><dd>' . g_flow_yen($b['exec']) . '</dd></dl></div>';
        }
        echo g_fk_src($d, $s0['y'], '2-1_');
    }
    echo '<h2>広報・周知などの契約（' . count($p['contracts']) . '件）</h2><ul class="flist">';
    foreach ($p['contracts'] as $c) { echo g_fk_row($slug, $c, true, false); }
    echo '</ul>' . g_fk_src($d, $s0['y'], '5-1_');
    foreach ($p['sheets'] as $sh) {
        echo '<h2>成果目標と実績（' . g_e($sh['y']) . '年度シート）</h2>';
        $oc = array_values(array_filter((array)$sh['outcomes'], fn($o) => $o['kind'] === 'アウトカム'));
        if (!$oc) { echo '<p>このシートの「効果発現経路 目標・実績」に、成果目標（アウトカム）の記載はありません。</p>'; }
        else {
            echo '<p class="note">成果目標の指標 ' . count($oc) . '件（実績の記載 ' . (int)$sh['n_outcome_actual'] . '件）</p><ul class="flist">';
            foreach ($oc as $o) {
                $tg = []; foreach ((array)$o['target'] as $yy => $v) { $tg[] = $yy . '年度 ' . $v; }
                $ac = []; foreach ((array)$o['actual'] as $yy => $v) { $ac[] = $yy . '年度 ' . $v; }
                echo '<li>' . g_e((string)$o['goal']) . '<br><span class="note">指標：' . g_e((string)$o['ind']) . ($o['unit'] ? '（' . g_e((string)$o['unit']) . '）' : '') . ($o['type'] ? '　' . g_e((string)$o['type']) : '')
                   . '<br>目標値：' . g_e($tg ? implode('・', $tg) : '記載なし') . '<br>実績値：' . g_e($ac ? implode('・', $ac) : '記載なし')
                   . ($o['qual'] ? '<br>定性的な実績：' . g_e(mb_substr((string)$o['qual'], 0, 300)) : '') . ($o['data'] ? '<br>根拠のデータ：' . g_e(mb_substr((string)$o['data'], 0, 120)) : '') . '</span></li>';
            }
            echo '</ul>';
        }
        $rv = $sh['review'] ?? null;
        if ($rv) {
            echo '<div class="panel"><dl class="fdl">';
            foreach ([['measure', '効果測定に関する評価'], ['self_result', '所管部局の点検結果'], ['ext_opinion', '外部有識者の所見'], ['team', '推進チームの所見'], ['team_detail', '所見の詳細']] as [$k, $l]) {
                if (!empty($rv[$k])) { echo '<dt>' . $l . '</dt><dd>' . nl2br(g_e(str_replace('／', "\n", (string)$rv[$k]))) . '</dd>'; }
            }
            echo '</dl>' . g_fk_src($d, $sh['y'], '4-1_') . '</div>';
        }
        echo g_fk_src($d, $sh['y'], '3-1_');
    }
    echo '<p class="more"><a class="btn o" href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . 'へ戻る</a></p>';
    g_fk_kokkai($d);
    g_fk_credit($d);
    g_foot();
}
