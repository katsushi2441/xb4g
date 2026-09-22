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
    return [
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
        ['gakudo_waiting',   '子育て', '学童保育の待機児童', '人', 'こども家庭庁（2025年5月・指定都市/中核市等のみ）', 'https://kurage.exbridge.jp/kgakudo.php/'],
    ];
}

function g_sk_all(): array
{
    return g_all('SELECT key, pref, pref_slug, no, name, area, voters, gap FROM senkyoku ORDER BY rowid');
}

function g_sk_one(string $key): ?array
{
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
    return preg_match('/^愛知(\d+)$/u', $district, $m) ? 'aichi-' . $m[1] : null;
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
    g_head('選挙区ダッシュボード（衆議院小選挙区289）', $desc, '/senkyoku', ['jsonld' => $ld]);
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
    g_head($d['name'] . 'の公開データ — 土砂災害・避難場所・訪問介護・放課後デイ', $desc, '/senkyoku/' . $key, ['jsonld' => $ld]);
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
    if ($gs) {
        echo '<div class="panel"><h3>この選挙区の国会議員（発言ログ）</h3><ul class="plain">';
        foreach ($gs as $g) { echo '<li><a href="' . g_url($g['slug']) . '">' . g_e($g['plain']) . '</a>（' . g_e($g['party']) . '）</li>'; }
        echo '</ul></div>';
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
                $parts[] = g_e($x[0]) . ' ' . number_format((int)$x[1]) . (isset($x[2]) && $x[2] !== '' && !is_int($x[2]) ? '（最大 ' . g_e((string)$x[2]) . '）' : '');
            }
            $val = number_format((int)$r['value']) . $unit;
            if ($link) { $val = '<a href="' . g_e($link . '?ref=senkyoku-' . $key) . '" target="_blank" rel="noopener">' . $val . '</a>'; }
            echo '<tr><td>' . g_e($label) . ($r['partial'] ? '<br><small>市全体の数（選挙区は市の一部）</small>' : '') . '</td>'
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
