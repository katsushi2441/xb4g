<?php
/**
 * 選挙区ダッシュボード — 衆議院小選挙区289ごとに、公開データの数字を1ページに並べる。
 *
 * 表 senkyoku / senkyoku_stat は scripts/build_senkyoku.py が作る。ここは読むだけ。
 * **数字の線引き**は build 側の docstring と同じ：政令市の区や町丁で割れている選挙区では、
 * 市までしか無いデータは市全体の数を出し、画面に「市全体の数」と書く（partial）。
 * 無いものは 0 と書かず「収録なし」と書く。
 */
declare(strict_types=1);

/** 指標の定義。順番＝画面の順番。link は「この数字の元の画面」。 */
function g_sk_metrics(): array
{
    // [キー, 分類, ラベル, 単位, 出典（空なら上の行と同じ）, 調べるリンク, 表示の割り算（10なら小数1桁）, 'noratio'＝「真ん中の区の何倍」を出さない]
    return [
        // 人口（総務省 住民基本台帳。scripts/fetch_muni_stats.py）
        ['pop',              '人口', '人口（2026年1月）', '人', '総務省 住民基本台帳（2026年1月1日）', ''],
        ['pop65',            '人口', 'うち65歳以上', '人', '', ''],
        ['aging_rate',       '人口', '高齢化率（65歳以上の割合）', '%', '', '', 10, 'noratio'],
        ['births',           '人口', '出生数（2025年）', '人', '総務省 住民基本台帳（2025年の人口動態）', ''],
        ['pop_change',       '人口', '人口の増減（2025年）', '人', '', '', 1, 'noratio'],
        // 防災
        ['dosha',            '防災', '土砂災害警戒区域', '区域',   '国土数値情報（都道府県の指定）', 'https://kurage.exbridge.jp/khazard.php/'],
        ['dosha_red',        '防災', 'うち特別警戒区域（レッド）', '区域', '', ''],
        ['shelters',         '防災', '指定緊急避難場所', 'か所',  '国土地理院', 'https://kurage.exbridge.jp/krefuge.php/'],
        ['shelters_flood',   '防災', 'うち洪水に対応', 'か所', '', ''],
        ['shelters_landslide','防災', 'うち土砂災害に対応', 'か所', '', ''],
        ['shelters_tsunami', '防災', 'うち津波に対応', 'か所', '', ''],
        ['tsunami_inundated','防災', '津波の浸水想定の中にある避難場所', 'か所', '都道府県の津波浸水想定', 'https://kurage.exbridge.jp/ktsunami.php/'],
        ['riskarea',         '防災', '災害危険区域（建築基準法39条）', '区域', '国土数値情報', 'https://kurage.exbridge.jp/kriskarea.php/'],
        // 福祉・子育て
        ['houmon',           '福祉', '訪問介護', 'か所', '厚労省 介護サービス情報公表システム（2026年6月末）', 'https://kurage.exbridge.jp/kkaigo.php/'],
        ['houmon_gone',      '福祉', 'うち1年半で公表データから消えた訪問介護', 'か所', '2024年12月末→2026年6月末', ''],
        ['caremane',         '福祉', 'ケアマネ事業所（居宅介護支援）', 'か所', '', 'https://kurage.exbridge.jp/kkaigo.php/'],
        ['caremane_gone',    '福祉', 'うち1年半で消えたケアマネ事業所', 'か所', '', ''],
        ['houdei',           '福祉', '放課後等デイサービス', 'か所', 'WAM NET（2025年9月）', 'https://kurage.exbridge.jp/khoudei.php/'],
        ['houdei_gone',      '福祉', 'うち2021年11月以降に消えた放課後等デイ', 'か所', '', ''],
        ['jihatsu',          '福祉', '児童発達支援', 'か所', '', 'https://kurage.exbridge.jp/khoudei.php/'],
        ['ghome',            '福祉', '障害者グループホーム', 'か所', 'WAM NET', 'https://kurage.exbridge.jp/kghome.php/'],
        ['ghome_gone',       '福祉', 'うち2021年11月以降に消えたグループホーム', 'か所', '', ''],
        ['shuroA',           '福祉', '就労継続支援A型', 'か所', 'WAM NET', 'https://kurage.exbridge.jp/kshuro.php/'],
        ['shuroB',           '福祉', '就労継続支援B型', 'か所', '', 'https://kurage.exbridge.jp/kshuro.php/'],
        ['hoiku_waiting',    '子育て', '保育所の待機児童', '人', 'こども家庭庁 保育所等関連状況取りまとめ（2025年4月1日）', ''],
        ['hoiku_hidden',     '子育て', 'いわゆる隠れ待機児童（育休中・特定の園のみ希望など）', '人', '', ''],
        ['gakudo_waiting',   '子育て', '学童保育の待機児童', '人', 'こども家庭庁（2025年5月・指定都市/中核市等のみ）', 'https://kurage.exbridge.jp/kgakudo.php/'],
        // 農業
        ['seisan_ryokuchi',  '農業', '生産緑地の面積', 'ha', '国土交通省 都市計画現況調査（2024年3月31日）', '', 10],
    ];
}

/** 指標の値を表示用に（割り算・小数・増減の符号） */
function g_sk_fmt(string $k, $v): string
{
    $div = 1;
    foreach (g_sk_metrics() as $mt) { if ($mt[0] === $k) { $div = (int)($mt[6] ?? 1); break; } }
    $s = $div > 1 ? number_format((float)$v / $div, 1) : number_format((float)$v);
    return ($k === 'pop_change' && (float)$v > 0 ? '+' : '') . $s;
}

/** 「全国の真ん中の区の何倍」を出してよい指標か（率と増減は倍にしない） */
function g_sk_ratio_ok(string $k): bool
{
    foreach (g_sk_metrics() as $mt) { if ($mt[0] === $k) { return ($mt[7] ?? '') !== 'noratio'; } }
    return true;
}

/** 選挙区の表があるか。**選挙区は当社の公開先だけ**（ほかの製品のDBから作るので配布物に入らない） */
function g_sk_ready(): bool
{
    static $r = null;
    if ($r === null) { $r = (bool)g_val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='senkyoku'"); }
    return $r;
}

function g_sk_all(): array
{
    if (!g_sk_ready()) { return []; }
    return g_all('SELECT key, pref, pref_slug, no, name, area, voters, gap FROM senkyoku ORDER BY rowid');
}

function g_sk_one(string $key): ?array
{
    if (!g_sk_ready()) { return null; }
    return g_one('SELECT * FROM senkyoku WHERE key=?', [$key]);
}

function g_sk_stats(string $key): array
{
    $out = [];
    foreach (g_all('SELECT metric, value, partial, detail FROM senkyoku_stat WHERE key=?', [$key]) as $r) {
        $out[$r['metric']] = $r;
    }
    return $out;
}

/** 愛知N → aichi-N。比例・参院は null。 */
function g_sk_key_for_district(string $district): ?string
{
    return g_sk_ready() && preg_match('/^愛知(\d+)$/u', $district, $m) ? 'aichi-' . $m[1] : null;
}

function g_sk_pref_slugs(): array
{
    static $m = null;
    if ($m === null) {
        $m = [];
        foreach (g_all('SELECT DISTINCT pref, pref_slug FROM senkyoku ORDER BY rowid') as $r) { $m[$r['pref_slug']] = $r['pref']; }
    }
    return $m;
}

/** 一覧：47都道府県 × 選挙区。 */
function g_page_senkyoku_list(): void
{
    if (!g_sk_ready()) { http_response_code(404); g_head('見つかりません', '', '/senkyoku', ['noindex' => true]); echo '<h1>見つかりません</h1>'; g_foot(); return; }
    $rows = g_sk_all();
    $by = [];
    foreach ($rows as $r) { $by[$r['pref']][] = $r; }
    $built = g_meta('senkyoku_built');
    $desc = '衆議院の小選挙区289について、土砂災害警戒区域・避難場所・津波・訪問介護やケアマネ事業所の数と'
          . '公表データから消えた数・放課後等デイ・グループホーム・学童保育の待機児童を、国や県の公開データから'
          . '選挙区ごとに1ページに並べています。要約も論評もしません。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['選挙区ダッシュボード', '/senkyoku']]),
        ['@type' => 'CollectionPage', '@id' => g_abs('senkyoku'), 'url' => g_abs('senkyoku'),
         'name' => '選挙区ダッシュボード — 衆議院小選挙区289の公開データ',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head('選挙区ダッシュボード（衆議院小選挙区289）', $desc, '/senkyoku', ['jsonld' => $ld, 'image' => g_abs('img/og/senkyoku.png')]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 選挙区ダッシュボード</nav>';
    echo '<section class="hero p"><p class="kick">Districts &times; Open Data</p>'
       . '<h1>選挙区ダッシュボード</h1>'
       . '<p class="lead">衆議院の小選挙区<b>' . count($rows) . '</b>について、その区域にある'
       . '<b>土砂災害警戒区域・避難場所・津波の想定・訪問介護とケアマネ事業所・放課後等デイ・障害者グループホーム・学童保育の待機児童</b>を、'
       . '国と都道府県の公開データから1ページに並べました。数字はすべて出典の表から機械的に足したもので、要約も論評もしません。</p>'
       . '</section>';
    echo '<div class="panel"><p>区域は2022年（令和4年）の区割り改定後のものです。政令市の区や、町丁で分かれている市は、'
       . '区で数えられるデータ（事業所）は区で、市までしか無いデータ（避難場所・警戒区域など）は<b>市全体の数</b>で出し、そう書いています。'
       . ($built ? ' 集計 ' . g_e($built) . '。' : '') . '</p></div>';
    // 都道府県へ飛ぶ索引（スマホでは289枚のカードが縦に並ぶので、先頭から探せるように）
    echo '<div class="panel"><p>';
    foreach ($by as $pref => $list) { echo '<a href="#' . g_e($list[0]['pref_slug']) . '">' . g_e($pref) . '</a>　'; }
    echo '</p></div>';
    foreach ($by as $pref => $list) {
        $slug = $list[0]['pref_slug'];
        echo '<h2 id="' . g_e($slug) . '">' . g_e($pref) . '<small>（' . count($list) . '区）</small></h2><div class="grid">';
        foreach ($list as $r) {
            echo '<a class="card" href="' . g_url('senkyoku/' . $r['key']) . '"><b>' . g_e($r['name']) . '</b>'
               . '<span>' . g_e(mb_strimwidth($r['area'], 0, 60, '…')) . '</span>'
               . ($r['voters'] ? '<i>有権者 ' . number_format((int)$r['voters']) . '人</i>' : '') . '</a>';
        }
        echo '</div>';
    }
    echo g_sk_sources();
    g_foot();
}

function g_sk_sources(): string
{
    return '<div class="panel src"><h3>出典と線引き</h3><ul>'
         . '<li>区域・有権者数・一票の格差: 総務省の区割り（2022年改定）を Wikipedia「衆議院小選挙区制選挙区一覧」の表から取り、市区町村名は Geolonia の一覧で正規化</li>'
         . '<li>土砂災害警戒区域: 国土数値情報（都道府県の指定）／指定緊急避難場所: 国土地理院／津波浸水想定: 各都道府県／災害危険区域: 国土数値情報</li>'
         . '<li>訪問介護・ケアマネ事業所: 厚生労働省 介護サービス情報公表システム（2024年12月末・2026年6月末の2時点）／放課後等デイ・児童発達支援・グループホーム・就労継続支援: WAM NET（2021年11月〜2025年9月）／学童保育: こども家庭庁 令和7年調査（指定都市・中核市等のみ市単位）</li>'
         . '<li>「消えた」は公表データから番号が消えたことで、廃止とは限りません（休止・再編を含む）。空き状況・質は公表データに無いので出しません</li>'
         . '<li>無いものは 0 と書かず「収録なし」と書きます。その都道府県のデータを取り込んでいない場合も同じです</li>'
         . '</ul></div>';
}

/** 選挙区1ページ。 */
function g_page_senkyoku(string $key): void
{
    $d = g_sk_one($key);
    if (!$d) {
        http_response_code(404);
        g_head('見つかりません', '', '/senkyoku', ['noindex' => true]);
        echo '<h1>その選挙区は収録していません</h1><p><a href="' . g_url('senkyoku') . '">選挙区の一覧</a></p>'; g_foot(); return;
    }
    $st = g_sk_stats($key);
    $munis = json_decode($d['munis_json'] ?: '[]', true) ?: [];
    $names = [];
    foreach ($munis as $m) { $names[] = $m['name'] . ($m['wards'] ? '（' . implode('・', $m['wards']) . '）' : ''); }
    $n = function (string $k) use ($st) { return isset($st[$k]) ? number_format((int)$st[$k]['value']) : null; };

    $bits = [];
    if ($n('dosha') !== null) { $bits[] = '土砂災害警戒区域 ' . $n('dosha'); }
    if ($n('shelters') !== null) { $bits[] = '避難場所 ' . $n('shelters') . 'か所'; }
    if ($n('houmon') !== null) { $bits[] = '訪問介護 ' . $n('houmon') . 'か所（1年半で消えた ' . ($n('houmon_gone') ?? '0') . '）'; }
    if ($n('houdei') !== null) { $bits[] = '放課後等デイ ' . $n('houdei') . 'か所'; }
    $desc = $d['name'] . '（' . $d['area'] . '）の公開データ。' . implode('・', $bits) . '。国と県の公開データを機械的に足した数字で、要約も論評もしません。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], ['選挙区ダッシュボード', '/senkyoku'], [$d['name'], '/senkyoku/' . $key]]),
        ['@type' => 'Dataset', '@id' => g_abs('senkyoku/' . $key), 'url' => g_abs('senkyoku/' . $key),
         'name' => $d['name'] . 'の公開データ（防災・福祉・子育て）', 'description' => $desc, 'inLanguage' => 'ja',
         'spatialCoverage' => ['@type' => 'Place', 'name' => $d['pref'] . ' ' . $d['area']],
         'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://xb4g.com/'],
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    $og = file_exists(__DIR__ . '/../img/og/senkyoku/' . $key . '.png') ? g_abs('img/og/senkyoku/' . $key . '.png') : g_abs('img/og/senkyoku.png');
    g_head($d['name'] . 'の公開データ — 土砂災害・避難場所・訪問介護・放課後デイ', $desc, '/senkyoku/' . $key, ['jsonld' => $ld, 'image' => $og]);
    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('senkyoku') . '">選挙区ダッシュボード</a> › '
       . '<a href="' . g_url('senkyoku#' . $d['pref_slug']) . '">' . g_e($d['pref']) . '</a> › ' . g_e($d['name']) . '</nav>';

    // 見出し。有権者数と格差は総務省の表（Wikipedia経由）
    echo '<section class="hero p"><p class="kick">衆議院小選挙区 &middot; ' . g_e($d['pref']) . '</p>'
       . '<h1>' . g_e($d['name']) . '<small>' . g_e($d['area']) . '</small></h1>'
       . '<p class="lead">この選挙区の区域にある<b>防災・福祉・子育ての公開データ</b>を、国と都道府県の表から機械的に足しました。'
       . '要約も論評もしません。数字を押すと元の画面へ行けます。</p>'
       . '<div class="facts">'
       . ($d['voters'] ? '<div class="f"><b>' . number_format((int)$d['voters']) . '<i>人</i></b><span>有権者数（一票の格差 ' . g_e((string)$d['gap']) . '倍）</span></div>' : '')
       . ($n('dosha') !== null ? '<div class="f"><b>' . $n('dosha') . '<i>区域</i></b><span>土砂災害警戒区域' . (!empty($st['dosha']['partial']) ? '（市全体）' : '') . '</span></div>' : '')
       . ($n('shelters') !== null ? '<div class="f"><b>' . $n('shelters') . '<i>か所</i></b><span>指定緊急避難場所' . (!empty($st['shelters']['partial']) ? '（市全体）' : '') . '</span></div>' : '')
       . ($n('houmon') !== null ? '<div class="f"><b>' . $n('houmon') . '<i>か所</i></b><span>訪問介護（1年半で消えた ' . ($n('houmon_gone') ?? '0') . '）</span></div>' : '')
       . '</div></section>';

    // 議員（愛知だけ収録している）
    $gs = [];
    if (preg_match('/^aichi-(\d+)$/', $key, $m)) {
        $gs = g_all("SELECT slug, plain, party, house FROM giin WHERE district=? ORDER BY plain", ['愛知' . $m[1]]);
    }
    // 全国の名簿（衆議院の会派別名簿から。氏名・会派・当選回数・公式プロフィールだけ）。愛知は発言ログへ
    $ms = g_all('SELECT display, kana, kaiha, wins, profile FROM senkyoku_member WHERE key=? ORDER BY display', [$key]);
    if ($gs || $ms) {
        echo '<div class="panel"><h3>この選挙区の衆議院議員</h3><ul class="plain">';
        foreach ($gs as $g) { echo '<li><a href="' . g_url($g['slug']) . '">' . g_e($g['plain']) . '</a>（' . g_e($g['party']) . '）— 国会での質疑を日付と会議名で引けます</li>'; }
        if (!$gs) {
            foreach ($ms as $mm) {
                $nm = preg_replace('/\s+/u', ' ', $mm['display']);
                echo '<li>' . ($mm['profile'] ? '<a href="' . g_e($mm['profile']) . '" rel="noopener">' . g_e($nm) . '</a>' : g_e($nm))
                   . '（' . g_e($mm['kaiha']) . '・当選' . g_e($mm['wins']) . '回）</li>';
            }
        }
        echo '</ul><p class="src">衆議院の会派別議員名簿から。氏名・会派・当選回数・公式プロフィールへのリンクだけを出しています。</p></div>';
    }

    // **全国での位置を出す。** 289枚が同じ型の数字の表だと、Google は
    // 「クロール済み、しかし索引に登録されていない」と判定する（2026-09-24 実測）。
    // 同じ数字から、その選挙区にしか書けない事実（全国順位・中央値との比）を作る。
    $rk = [];
    foreach (g_all('SELECT metric, rank, total, median, ratio, top_key, top_value FROM senkyoku_rank WHERE key=?', [$key]) as $r) {
        $rk[$r['metric']] = $r;
    }
    // 上位・下位の指標を拾って、冒頭に1行で出す（ページごとに文が変わる）
    $hi = []; $lo = [];
    foreach (g_sk_metrics() as $mt) {
        $k = $mt[0];
        if (!isset($rk[$k]) || !isset($st[$k])) { continue; }
        $r = $rk[$k];
        if ((int)$r['total'] < 50) { continue; }
        $pct = (int)$r['rank'] / (int)$r['total'];
        if ($pct <= 0.10) { $hi[] = [$mt[2], (int)$r['rank'], (int)$r['total'], g_sk_fmt($k, $st[$k]['value']), $mt[3]]; }
        elseif ($pct >= 0.90) { $lo[] = [$mt[2], (int)$r['rank'], (int)$r['total'], g_sk_fmt($k, $st[$k]['value']), $mt[3]]; }
    }
    if ($hi || $lo) {
        echo '<div class="panel"><h3>全国の中でどこに位置するか</h3><ul class="plain">';
        foreach (array_slice($hi, 0, 4) as $x) {
            echo '<li><b>' . g_e($x[0]) . '</b> は ' . g_e($x[3]) . g_e($x[4])
               . ' で、収録' . number_format($x[2]) . '区のうち<b>' . number_format($x[1]) . '位</b>（多いほう）</li>';
        }
        foreach (array_slice($lo, 0, 3) as $x) {
            echo '<li><b>' . g_e($x[0]) . '</b> は ' . g_e($x[3]) . g_e($x[4])
               . ' で、収録' . number_format($x[2]) . '区のうち<b>' . number_format($x[1]) . '位</b>（少ないほう）</li>';
        }
        echo '</ul><p class="src">同じ指標を全国の選挙区で並べ替えただけの順位です。'
           . '数字は各表のとおりで、良い悪いの評価ではありません。'
           . '「市全体の数」と書いてある指標は、選挙区が市の一部でも市の数で並べています。</p></div>';
    }

    // 指標の表（分類ごと）
    $groups = [];
    foreach (g_sk_metrics() as $mt) { $groups[$mt[1]][] = $mt; }
    foreach ($groups as $gname => $mts) {
        echo '<h2>' . g_e($gname) . '</h2><div class="scroll"><table><tr><th>項目</th><th class="n">数</th><th>内訳（市区町村）</th><th>出典</th></tr>';
        foreach ($mts as [$k, , $label, $unit, $src, $link]) {
            $r = $st[$k] ?? null;
            if ($r === null) {
                echo '<tr><td>' . g_e($label) . '</td><td class="n">収録なし</td><td></td><td>' . g_e($src) . '</td></tr>'; continue;
            }
            $det = json_decode($r['detail'] ?: '[]', true) ?: [];
            $parts = [];
            foreach ($det as $x) {
                $parts[] = g_e($x[0]) . ' ' . g_sk_fmt($k, (int)$x[1]) . (isset($x[2]) && $x[2] !== '' && !is_int($x[2]) ? '（最大 ' . g_e((string)$x[2]) . '）' : '');
            }
            $val = g_sk_fmt($k, (int)$r['value']) . $unit;
            if ($link) { $val = '<a href="' . g_e($link . '?ref=senkyoku-' . $key) . '" target="_blank" rel="noopener">' . $val . '</a>'; }
            if (isset($rk[$k]) && (int)$rk[$k]['total'] >= 50) {
                $rr = $rk[$k];
                $val .= '<br><small>全国' . number_format((int)$rr['total']) . '区中 ' . number_format((int)$rr['rank']) . '位'
                     . ($rr['median'] !== null && (float)$rr['median'] > 0
                        ? '／中央値 ' . g_sk_fmt($k, (float)$rr['median']) . $unit : '') . '</small>';
            }
            $card = function_exists('g_skc_png') && !str_ends_with($k, '_gone') && !in_array($k, ['dosha_red', 'pop65'], true)
                ? '<br><a class="note" href="' . g_url('senkyoku/' . $key . '/' . $k) . '" rel="nofollow">カードにする（Xに貼れます）</a>' : '';
            echo '<tr><td>' . g_e($label) . ($r['partial'] ? '<br><small>市全体の数（選挙区は市の一部）</small>' : '') . $card . '</td>'
               . '<td class="n">' . $val . '</td><td><small>' . implode('、', $parts) . '</small></td><td><small>' . g_e($src) . '</small></td></tr>';
        }
        echo '</table></div>';
    }

    echo '<div class="panel"><h3>区域の市区町村</h3><p>' . g_e(implode('、', $names)) . '</p>'
       . ($d['note'] ? '<p class="src">' . g_e($d['note']) . '</p>' : '') . '</div>';
    echo g_sk_sources();

    // 隣の選挙区へ
    $sib = g_all('SELECT key, name FROM senkyoku WHERE pref_slug=? ORDER BY no', [$d['pref_slug']]);
    echo '<div class="panel"><h3>' . g_e($d['pref']) . 'の選挙区</h3><p>';
    foreach ($sib as $s) { echo '<a href="' . g_url('senkyoku/' . $s['key']) . '"' . ($s['key'] === $key ? ' class="on"' : '') . '>' . g_e($s['name']) . '</a> '; }
    echo '</p></div>';
    g_foot();
}
