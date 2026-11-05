<?php
/**
 * Kurage 健康トラッカー（/health・/health/<話題>・/health/kenshin・/health/kenshin/<保険者番号>・/health/food・/health/food/<料理>）。2026-11-05
 *
 * 食べ方・睡眠・運動・健診の数値（NDB オープンデータ）、健診のあとの保健指導（保険者別の実施状況）、
 * 料理1人前の栄養（日本食品標準成分表）を、公的データだけで並べる。
 * データは khealth（/home/kojima/work/khealth）の scripts/build_*.py が data/health/ に書く。
 *
 * **このページがしないこと**: 医療の助言、診断、因果の主張、保険者や自治体の順位づけの評価。
 * 地域の差は相関までしか言えない。すべての数字に出典を付ける。
 */
declare(strict_types=1);

const G_HEALTH_NAME = 'Kurage 健康トラッカー';

function g_health_json(string $name): ?array
{
    static $c = [];
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $name)) { return null; }
    if (!array_key_exists($name, $c)) {
        $f = __DIR__ . '/../data/health/' . $name . '.json';
        $c[$name] = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    }
    return $c[$name];
}

function g_health_pct($x, int $d = 1): string
{
    return $x === null ? '—' : number_format((float)$x * 100, $d) . '%';
}

function g_health_og(string $key): string
{
    return g_abs(is_file(__DIR__ . '/../img/og/health-' . $key . '.png') ? 'img/og/health-' . $key . '.png' : 'img/og/health.png');
}

function g_health_src(string $title, string $url, string $extra = ''): string
{
    return '<p class="note fsrc">出典：<a href="' . g_e($url) . '" rel="noopener" target="_blank">' . g_e($title) . '</a>' . ($extra !== '' ? '　' . g_e($extra) : '') . '</p>';
}

function g_health_css(): string
{
    // 国会トラッカー（ティール）・政策の流れ（藍）と見分けがつくよう、このページは緑にそろえる（カード img/og/health-*.png と同じ系統）
    return '<style>'
         . ':root{--teal:#2e7d32;--teal-d:#1b5e20;--teal-l:#43a047;--mint:#a5d6a7;--mint-l:#c8e6c9;--mint-xl:#e8f5e9;--sh2:0 10px 26px rgba(46,125,50,.16)}'
         . '.hero{background:linear-gradient(135deg,#f1f8f1 0%,#dcefdc 48%,#b9dfba 100%)!important}'
         . 'h2::after{background:linear-gradient(90deg,#2e7d32,#f59e0b)!important}'
         . '.hbig{font-size:44px;font-weight:900;line-height:1.1;color:var(--teal-d);margin:6px 0}'
         . '.hgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin:12px 0}'
         . '.htopic{display:block;border:1px solid var(--line);border-radius:14px;padding:12px 14px;background:#fff;text-decoration:none;color:inherit;box-shadow:var(--sh);min-width:0}'
         . '.htopic:hover{border-color:var(--teal)}.htopic .nm{font-weight:900;line-height:1.5;overflow-wrap:anywhere}.htopic .v{font-size:26px;font-weight:900;color:var(--teal-d)}.htopic .mt{font-size:13px;color:var(--gray);overflow-wrap:anywhere}'
         . '.hbar{display:grid;grid-template-columns:minmax(5.5em,max-content) 1fr minmax(3.6em,max-content);gap:6px 10px;align-items:center;margin:4px 0}'
         . '.hbar .l{font-size:14px;overflow-wrap:anywhere}.hbar .t{height:14px;background:#eef2ee;border-radius:7px;overflow:hidden}.hbar .t i{display:block;height:100%;background:var(--teal-l);border-radius:7px}'
         . '.hbar .t i.f{background:#ef6c00}.hbar .n{font-size:14px;font-weight:700;text-align:right}'
         . '.hbar.me .l{font-weight:900;color:var(--teal-d)}'
         . '.fsrc{margin:6px 0 0;font-size:12.5px;overflow-wrap:anywhere}'
         . '.fwarn{background:#fff8e6;border:1px solid #f0d58a;border-radius:12px;padding:10px 14px;font-size:14px}'
         . 'details.fd{border:1px solid var(--line);border-radius:12px;padding:8px 12px;margin:8px 0;background:#fff}details.fd summary{cursor:pointer;font-weight:700}'
         . '.flist{list-style:none;padding:0;margin:0}.flist li{border-top:1px solid var(--line);padding:9px 0;overflow-wrap:anywhere}.flist li:first-child{border-top:0}'
         . '.htab{width:100%;border-collapse:collapse;font-size:14px}.htab th,.htab td{border-bottom:1px solid var(--line);padding:6px 4px;text-align:right}.htab th:first-child,.htab td:first-child{text-align:left}'
         . '#hq{width:100%;max-width:520px;box-sizing:border-box;font-size:16px;padding:10px 12px;border:1px solid #c9d3dc;border-radius:8px}'
         . '.hleg{display:flex;gap:14px;font-size:13px;color:var(--gray);margin:4px 0 8px}.hleg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:4px;vertical-align:-1px}'
         . '</style>';
}

function g_health_bar(string $label, $v, float $max, string $cls = '', bool $me = false): string
{
    $w = ($v === null || $max <= 0) ? 0 : min(100, (float)$v / $max * 100);
    return '<div class="hbar' . ($me ? ' me' : '') . '"><span class="l">' . g_e($label) . '</span><span class="t"><i class="' . $cls . '" style="width:' . number_format($w, 1, '.', '') . '%"></i></span><span class="n">' . g_health_pct($v) . '</span></div>';
}

function g_health_disclaimer(): string
{
    return '<div class="fwarn">公的データの数字を並べたものです。医療の助言や診断ではありません。地域や年齢による差は、食べ方や体の数値が「一緒に多い・少ない」ことを示すだけで、どちらが原因かは言えません。'
         . '気になる数値がある方は、かかりつけ医や健診の機関に相談してください。</div>';
}

function g_health_crumb(array $items): string
{
    $h = '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a>';
    foreach ($items as [$l, $p]) { $h .= ' › ' . ($p !== '' ? '<a href="' . g_url($p) . '">' . g_e($l) . '</a>' : g_e($l)); }
    return $h . '</nav>';
}

// ---------------------------------------------------------------- /health
function g_page_health_index(): void
{
    $ix = g_health_json('index') ?? [];
    $ts = (array)($ix['topics'] ?? []);
    $desc = '食べ方・睡眠・運動と、健診のALT（肝臓）・腹囲・BMI・HbA1cを、都道府県と二次医療圏ごとに。健診のあとの保健指導の実施率を市町村と保険者ごとに。料理1人前の栄養も。すべて公的データと出典つきで並べます。';
    g_head(G_HEALTH_NAME . '｜食べ方・睡眠・健診の数値を地域ごとに', $desc, '/health', ['nosuffix' => true, 'image' => g_health_og('index'), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health']]),
        ['@type' => 'Dataset', 'name' => G_HEALTH_NAME, 'description' => $desc, 'url' => g_abs('health'), 'inLanguage' => 'ja',
         'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ'],
         'isBasedOn' => ['https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/0000177182.html', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/0000161103.html', 'https://www.mext.go.jp/a_menu/syokuhinseibun/mext_00001.html']]])]);
    echo g_health_css();
    echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, '']]) . '<p class="kick">Health Tracker</p><h1>' . g_e(G_HEALTH_NAME) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p></section>';
    echo g_share_bar('【' . G_HEALTH_NAME . '】食べ方・睡眠・運動と健診の数値を、都道府県と地域ごとに公的データで', 'health', 'health');
    $groups = [];
    foreach ($ts as $t) { $groups[$t['group']][] = $t; }
    foreach ($groups as $g => $list) {
        echo '<h2 data-en="Topics">' . g_e($g) . '</h2><div class="hgrid">';
        foreach ($list as $t) {
            echo '<a class="htopic" href="' . g_url('health/' . $t['slug']) . '"><div class="nm">' . g_e($t['name']) . '</div><div class="v">' . g_health_pct($t['all']) . '</div>'
               . '<div class="mt">全国（' . g_e($t['year']) . '・40〜74歳）　男 ' . g_health_pct($t['m']) . '・女 ' . g_health_pct($t['f']) . '<br>高い県：' . g_e(implode('・', (array)$t['top'])) . '</div></a>';
        }
        echo '</div>';
    }
    $k = g_health_json('kenshin');
    if ($k) {
        $t = $k['totals']['kokuho']['2024'] ?? null;
        echo '<h2 data-en="Check-ups">健診のあと</h2><div class="hgrid"><a class="htopic" href="' . g_url('health/kenshin') . '"><div class="nm">健診のあとの保健指導（市町村・会社の健康保険ごと）</div>'
           . '<div class="v">' . ($t ? g_health_pct($t[3] / max(1, $t[2])) : '—') . '</div><div class="mt">市町村国保で、保健指導の対象になった人のうち指導を終えた人（2024年度）。1,700を超える市町村と、会社の健康保険組合ごとに、2018年度からの推移を見られます。</div></a></div>';
    }
    $fd = g_health_json('food');
    if ($fd) {
        echo '<h2 data-en="Food">料理1人前の栄養</h2><div class="hgrid">';
        foreach ($fd['dishes'] as $d) {
            $tt = $d['total'];
            echo '<a class="htopic" href="' . g_url('health/food/' . $d['slug']) . '"><div class="nm">' . g_e($d['name']) . '</div><div class="mt">' . number_format((float)$tt['kcal']) . 'kcal・たんぱく質 ' . g_e((string)$tt['protein']) . 'g・食物繊維 ' . g_e((string)$tt['fiber']) . 'g・食塩 ' . g_e((string)$tt['salt']) . 'g</div></a>';
        }
        echo '</div>';
    }
    echo '<h2 data-en="Diet">国会では</h2><div class="grid"><a class="card" href="' . g_url('tracker/tokutei-kenshin') . '"><div class="nm">特定健診と生活習慣病の予防（Kurage 国会トラッカー）</div><div class="n">健診のあとの保健指導や生活習慣病の予防を、国会でだれが問い、政府がどう答えてきたか</div></a></div>';
    echo g_health_disclaimer();
    g_health_credit();
    g_foot();
}

function g_health_credit(): void
{
    echo '<h2 data-en="Sources">出典と作り方</h2><div class="panel"><ul style="margin:0;padding-left:18px">'
       . '<li>食べ方・睡眠・運動・健診の検査値：<a href="https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/0000177221_00017.html" rel="noopener" target="_blank">第11回NDBオープンデータ</a>（厚生労働省）の特定健診（2023年度）の「標準的な質問票」と「検査項目」の都道府県別・二次医療圏別・性年齢階級別の分布を加工して作成。対象は特定健診を受けた40〜74歳です。</li>'
       . '<li>割合は、その回答（検査値の区分）の人数を、その質問（検査）の回答者の合計で割ったものです。10人未満で伏せられた箇所は0として数えています（その地域には「概算」と書きます）。回答者100人未満の集計は割合を出していません。</li>'
       . '<li>健診のあとの保健指導：<a href="https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/0000161103.html" rel="noopener" target="_blank">特定健康診査・特定保健指導の実施状況（保険者別）</a>（厚生労働省、2018〜2024年度。2024年度は2026年10月29日の訂正後）。</li>'
       . '<li>料理の栄養：<a href="https://www.mext.go.jp/a_menu/syokuhinseibun/mext_00001.html" rel="noopener" target="_blank">日本食品標準成分表（八訂）増補2023年</a>（文部科学省）の100gあたりの値に、当社が置いた具材の量をかけて足したもの。</li>'
       . '<li>誤りに気づいたら <a href="https://exbridge.jp/contact.php#ref=health-fix">お問い合わせ</a> からお知らせください。</li>'
       . '</ul></div>';
}

// ---------------------------------------------------------------- /health/<話題>
function g_page_health_topic(string $slug): void
{
    $d = g_health_json($slug);
    if (!$d || empty($d['national'])) { http_response_code(404); g_head('見つかりません', '', '/health', ['noindex' => true]); echo '<h1>その話題はまだありません</h1><p><a href="' . g_url('health') . '">' . G_HEALTH_NAME . 'へ</a></p>'; g_foot(); return; }
    $n = $d['national'];
    $title = $d['name'] . 'の割合｜都道府県・地域・年齢別';
    $desc = $d['name'] . 'は全国で' . g_health_pct($n['all']) . '（男 ' . g_health_pct($n['m']) . '・女 ' . g_health_pct($n['f']) . '、' . $d['year'] . 'の特定健診・40〜74歳）。都道府県・二次医療圏・性別と年齢ごとの割合を、NDBオープンデータから出典つきで並べます。';
    $prefs = $d['prefs'];
    usort($prefs, fn($a, $b) => ($b['all'] ?? -1) <=> ($a['all'] ?? -1));
    $top = $prefs[0]; $bot = $prefs[count($prefs) - 1];
    $faq = [
        [$d['name'] . 'は全国でどれくらいですか？', $d['year'] . 'に特定健診を受けた40〜74歳のうち ' . g_health_pct($n['all']) . 'です。男性は ' . g_health_pct($n['m']) . '、女性は ' . g_health_pct($n['f']) . 'です（NDBオープンデータ）。'],
        [$d['name'] . 'の割合がいちばん高い都道府県は？', $top['pref'] . 'の ' . g_health_pct($top['all']) . 'です。いちばん低いのは' . $bot['pref'] . 'の ' . g_health_pct($bot['all']) . 'です。年齢の構成の違いは調整していません。'],
        ['この数字はどうやって数えていますか？', '特定健診の' . (str_contains((string)$d['question'], '区分') ? '検査値' : '質問票') . '（' . $d['question'] . '）で、該当する人の数を回答者の合計で割っています。'],
    ];
    g_head($title, $desc, '/health/' . $slug, ['nosuffix' => true, 'image' => g_health_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health'], [$d['short'], '/health/' . $slug]]),
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)],
        ['@type' => 'Dataset', 'name' => $title, 'description' => $desc, 'url' => g_abs('health/' . $slug), 'inLanguage' => 'ja', 'dateModified' => $d['generated_at'],
         'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ'], 'isBasedOn' => [$d['source']['url']]]])]);
    echo g_health_css();
    echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, 'health'], [$d['short'], '']]) . '<p class="kick">' . g_e($d['group']) . '</p><h1 style="overflow-wrap:anywhere">' . g_e($d['name']) . '</h1>'
       . '<div class="hbig">' . g_health_pct($n['all']) . '</div><p class="lead" style="margin:0">全国（' . g_e($d['year']) . 'の特定健診・40〜74歳）。男性 ' . g_health_pct($n['m']) . '・女性 ' . g_health_pct($n['f']) . '</p></section>';
    echo g_share_bar('【' . G_HEALTH_NAME . '】' . $d['name'] . 'は全国で' . g_health_pct($n['all']) . '。都道府県・地域・年齢ごとの割合', 'health/' . $slug, 'health-' . $slug);
    echo '<p>' . g_e($d['lead']) . '</p>';

    // 性別と年齢
    $mx = 0.0;
    foreach (array_merge((array)$n['age_m'], (array)$n['age_f']) as $v) { if ($v !== null) { $mx = max($mx, (float)$v); } }
    echo '<h2 data-en="By age">男女と年齢で見る</h2><div class="hleg"><span><i style="background:#43a047"></i>男性</span><span><i style="background:#ef6c00"></i>女性</span></div>';
    foreach ($d['ages'] as $i => $a) {
        echo g_health_bar('男 ' . $a, $n['age_m'][$i], $mx) . g_health_bar('女 ' . $a, $n['age_f'][$i], $mx, 'f');
    }
    echo g_health_src($d['source']['title'], $d['source']['url'], '性・年齢階級別分布');

    // 都道府県
    $mx = (float)($top['all'] ?? 0);
    echo '<h2 id="prefs" data-en="Prefectures">都道府県で見る</h2><p class="note">割合の高い順。年齢の構成の違い（高齢の人が多い県など）は調整していません。比べるときは、上の年齢別の差も合わせて見てください。</p>';
    foreach ($prefs as $p) { echo g_health_bar(($p['rank'] ?? '') . '. ' . $p['pref'], $p['all'], $mx); }
    echo g_health_src($d['source']['title'], $d['source']['url'], '都道府県別性年齢階級別分布');

    // 二次医療圏
    $by = [];
    foreach ($d['areas'] as $a) { $by[$a['pref']][] = $a; }
    echo '<h2 id="areas" data-en="Areas">地域（二次医療圏）で見る</h2><p class="note">二次医療圏は、入院医療を完結させる単位として都道府県が決めた地域です（全国で' . count($d['areas']) . '）。都道府県を開くと、地域ごとの割合が出ます。</p>';
    foreach ($prefs as $p) {
        $list = $by[$p['pref']] ?? [];
        if (!$list) { continue; }
        $amx = 0.0; foreach ($list as $a) { $amx = max($amx, (float)($a['all'] ?? 0)); }
        echo '<details class="fd"><summary>' . g_e($p['pref']) . '　<span class="note">' . count($list) . '地域・県全体 ' . g_health_pct($p['all']) . '</span></summary>';
        foreach ($list as $a) { echo g_health_bar($a['name'] . (!empty($a['approx']) ? '（概算）' : ''), $a['all'], max($amx, (float)$p['all'])); }
        echo '</details>';
    }
    echo g_health_src($d['source']['title'], $d['source']['url'], '二次医療圏別性年齢階級別分布');

    // ほかの話題
    $ix = g_health_json('index') ?? [];
    echo '<h2 data-en="More">ほかの話題</h2><div class="hgrid">';
    foreach ((array)($ix['topics'] ?? []) as $t) {
        if ($t['slug'] === $slug) { continue; }
        echo '<a class="htopic" href="' . g_url('health/' . $t['slug']) . '"><div class="nm">' . g_e($t['name']) . '</div><div class="mt">全国 ' . g_health_pct($t['all']) . '</div></a>';
    }
    echo '<a class="htopic" href="' . g_url('health/kenshin') . '"><div class="nm">健診のあとの保健指導（市町村・会社の健康保険ごと）</div></a>';
    echo '<a class="htopic" href="' . g_url('health/food') . '"><div class="nm">料理1人前の栄養</div></a></div>';
    echo '<div class="grid"><a class="card" href="' . g_url('tracker/tokutei-kenshin') . '"><div class="nm">国会では：特定健診と生活習慣病の予防</div><div class="n">Kurage 国会トラッカー</div></a></div>';

    echo '<h2 data-en="FAQ">よくある質問</h2>';
    foreach ($faq as [$q, $a]) { echo '<details class="fd"><summary>' . g_e($q) . '</summary><p style="margin:8px 0 0">' . g_e($a) . '</p></details>'; }
    echo g_health_disclaimer();
    g_health_credit();
    g_foot();
}

// ---------------------------------------------------------------- /health/kenshin
function g_health_kenshin_rate(?array $v, int $i): ?float
{
    if (!$v) { return null; }
    [$a, $b, $c, $dd] = $v;
    if ($i === 0) { return ($a && $b !== null) ? $b / $a : null; }
    return ($c && $dd !== null) ? $dd / $c : null;
}

function g_page_health_kenshin(string $sub = ''): void
{
    $k = g_health_json('kenshin');
    if (!$k) { g_flow_404('health'); return; }
    $kn = $k['kind_name'];
    $isList = $sub === 'kumiai';
    $title = $isList ? '会社の健康保険組合・共済組合の特定健診と保健指導の実施率' : '特定健診のあとの保健指導の実施率｜市町村国保・保険者ごと';
    $desc = $isList ? '健康保険組合・共済組合・国民健康保険組合ごとに、特定健診の受診率と特定保健指導の実施率を2018〜2024年度で並べます（厚生労働省の保険者別の公表資料）。'
                    : '健診で生活習慣の改善が必要とされた人のうち、特定保健指導を終えた人の割合を、1,700を超える市町村の国民健康保険と保険者ごとに。2018〜2024年度の推移を厚生労働省の公表資料から並べます。';
    $path = $isList ? 'health/kenshin/kumiai' : 'health/kenshin';
    g_head($title, $desc, '/' . $path, ['nosuffix' => true, 'image' => g_health_og('kenshin'), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health'], ['健診のあとの保健指導', '/health/kenshin']])])]);
    echo g_health_css();
    echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, 'health'], ['健診のあとの保健指導', $isList ? 'health/kenshin' : ''], ...($isList ? [['健康保険組合・共済組合', '']] : [])])
       . '<p class="kick">Check-ups</p><h1 style="overflow-wrap:anywhere">' . g_e($title) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p></section>';
    echo g_share_bar('【' . G_HEALTH_NAME . '】' . $title, $path, 'health-kenshin');
    echo '<div class="fwarn">受診率＝特定健診の受診者÷対象者、保健指導の実施率＝特定保健指導を終えた人÷保健指導の対象者です。'
       . '市町村国保（自営業・退職した人など）と会社の健康保険組合（会社員と家族）は対象の人が違うので、種類をまたいで比べないでください。保険者の取り組みの良し悪しを判定するものではありません。</div>';
    if (!$isList) {
        echo '<h2 data-en="By kind">保険者の種類ごと（2024年度）</h2><table class="htab"><tr><th>種類</th><th>受診率</th><th>保健指導</th></tr>';
        foreach ($kn as $kk => $nm) {
            $t = $k['totals'][$kk]['2024'] ?? null;
            echo '<tr><td>' . g_e($nm) . '</td><td>' . g_health_pct(g_health_kenshin_rate($t, 0)) . '</td><td>' . g_health_pct(g_health_kenshin_rate($t, 1)) . '</td></tr>';
        }
        echo '</table><p class="note">表の保険者の値を種類ごとに足して計算したもの。厚生労働省が公表する全国計とは、公表されていない値（-）の扱いで少し違うことがあります。</p>';
        echo '<h3>市町村国保の推移</h3><table class="htab"><tr><th>年度</th><th>受診率</th><th>保健指導</th></tr>';
        foreach ($k['years'] as $y) { $t = $k['totals']['kokuho'][$y] ?? null; echo '<tr><td>' . g_e($y) . '</td><td>' . g_health_pct(g_health_kenshin_rate($t, 0)) . '</td><td>' . g_health_pct(g_health_kenshin_rate($t, 1)) . '</td></tr>'; }
        echo '</table>' . g_health_src('特定健康診査・特定保健指導の実施状況（保険者別）（厚生労働省）', 'https://www.mhlw.go.jp/stf/seisakunitsuite/bunya/0000161103.html', '2018〜2024年度');

        echo '<h2 id="kokuho" data-en="Municipalities">市町村国保（都道府県を開く）</h2><p><input id="hq" type="search" placeholder="市町村の名前で探す（例：名古屋市、鹿児島市）" autocomplete="off"><br><span class="note" id="hqn"></span></p><div id="hall">';
        $by = [];
        foreach ($k['insurers'] as $e) { if ($e['kind'] === 'kokuho' && isset($e['years']['2024'])) { $by[$e['pref']][] = $e; } }
        foreach ($k['prefs'] as $p) {
            $list = $by[$p] ?? []; if (!$list) { continue; }
            $t = $k['totals']['kokuho|' . $p]['2024'] ?? null;
            echo '<details class="fd hp"><summary>' . g_e($p) . '　<span class="note">' . count($list) . '市町村・受診率 ' . g_health_pct(g_health_kenshin_rate($t, 0)) . '・保健指導 ' . g_health_pct(g_health_kenshin_rate($t, 1)) . '</span></summary><ul class="flist">';
            foreach ($list as $e) {
                $v = $e['years']['2024'];
                echo '<li data-q="' . g_e(mb_strtolower($e['short'] . ' ' . $p)) . '"><a href="' . g_url('health/kenshin/' . $e['no']) . '">' . g_e($e['short']) . '</a> <span class="note">受診率 ' . g_health_pct(g_health_kenshin_rate($v, 0)) . '・保健指導 ' . g_health_pct(g_health_kenshin_rate($v, 1)) . '</span></li>';
            }
            echo '</ul></details>';
        }
        echo '</div><p class="more"><a class="btn o" href="' . g_url('health/kenshin/kumiai') . '">会社の健康保険組合・共済組合の一覧へ</a></p>';
    } else {
        echo '<p><input id="hq" type="search" placeholder="名前で探す（例：トヨタ、日立、教職員）" autocomplete="off"><br><span class="note" id="hqn"></span></p><div id="hall">';
        foreach (['kenpo', 'kyosai', 'kokuho-kumiai'] as $kk) {
            $list = array_values(array_filter($k['insurers'], fn($e) => $e['kind'] === $kk && isset($e['years']['2024'])));
            echo '<h2 data-en="List">' . g_e($kn[$kk]) . '（' . count($list) . '）</h2><ul class="flist">';
            foreach ($list as $e) {
                $v = $e['years']['2024'];
                echo '<li data-q="' . g_e(mb_strtolower($e['name'] . ' ' . $e['pref'])) . '"><a href="' . g_url('health/kenshin/' . $e['no']) . '">' . g_e($e['name']) . '</a> <span class="note">' . g_e($e['pref']) . ' 受診率 ' . g_health_pct(g_health_kenshin_rate($v, 0)) . '・保健指導 ' . g_health_pct(g_health_kenshin_rate($v, 1)) . '</span></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '<script>(function(){var q=document.getElementById("hq");if(!q)return;var n=document.getElementById("hqn"),ls=[].slice.call(document.querySelectorAll("#hall li[data-q]")),ds=[].slice.call(document.querySelectorAll("#hall details"));'
       . 'function f(){var v=q.value.trim().toLowerCase(),k=0;ls.forEach(function(l){var ok=!v||l.getAttribute("data-q").indexOf(v)>=0;l.style.display=ok?"":"none";if(ok)k++;});'
       . 'ds.forEach(function(d){d.open=!!v&&d.querySelector("li[data-q]:not([style*=none])")!==null;});n.textContent=v?k+"件":"";}q.addEventListener("input",f);})();</script>';
    echo '<div class="grid"><a class="card" href="' . g_url('tracker/tokutei-kenshin') . '"><div class="nm">国会では：特定健診と生活習慣病の予防</div><div class="n">Kurage 国会トラッカー</div></a>'
       . '<a class="card" href="' . g_url('health') . '"><div class="nm">' . g_e(G_HEALTH_NAME) . '</div><div class="n">食べ方・睡眠・健診の数値を地域ごとに</div></a></div>';
    g_health_credit();
    g_foot();
}

function g_page_health_insurer(string $no): void
{
    $k = g_health_json('kenshin');
    $e = null;
    foreach (($k['insurers'] ?? []) as $x) { if ($x['no'] === $no) { $e = $x; break; } }
    if (!$e) { http_response_code(404); g_head('見つかりません', '', '/health/kenshin', ['noindex' => true]); echo '<h1>その保険者は見つかりません</h1><p><a href="' . g_url('health/kenshin') . '">一覧へ</a></p>'; g_foot(); return; }
    $v24 = $e['years']['2024'] ?? null;
    $nm = $e['kind'] === 'kokuho' ? $e['short'] . 'の国民健康保険' : $e['name'];
    $title = $nm . 'の特定健診の受診率と保健指導の実施率';
    $desc = $nm . '（' . ($e['pref'] !== '' ? $e['pref'] . '・' : '') . $k['kind_name'][$e['kind']] . '）の特定健診の受診率は' . g_health_pct(g_health_kenshin_rate($v24, 0)) . '、特定保健指導の実施率は' . g_health_pct(g_health_kenshin_rate($v24, 1)) . '（2024年度）。2018年度からの推移を厚生労働省の公表資料から並べます。';
    g_head($title, $desc, '/health/kenshin/' . $no, ['nosuffix' => true, 'image' => g_health_og('kenshin'), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health'], ['健診のあとの保健指導', '/health/kenshin'], [$nm, '/health/kenshin/' . $no]])])]);
    echo g_health_css();
    echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, 'health'], ['健診のあとの保健指導', 'health/kenshin'], [$nm, '']])
       . '<p class="kick">' . g_e(($e['pref'] !== '' ? $e['pref'] . '・' : '') . $k['kind_name'][$e['kind']]) . '</p><h1 style="overflow-wrap:anywhere">' . g_e($nm) . '</h1>'
       . '<div class="kv"><div class="c"><b>' . g_health_pct(g_health_kenshin_rate($v24, 0)) . '</b><span>特定健診の受診率（2024年度）</span></div>'
       . '<div class="c"><b>' . g_health_pct(g_health_kenshin_rate($v24, 1)) . '</b><span>特定保健指導の実施率（2024年度）</span></div></div></section>';
    echo g_share_bar('【' . G_HEALTH_NAME . '】' . $title . '（2018〜2024年度）', 'health/kenshin/' . $no, 'health-kenshin-p');
    echo '<h2 data-en="Trend">年度ごとの推移</h2><table class="htab"><tr><th>年度</th><th>対象者</th><th>受診率</th><th>保健指導の対象</th><th>実施率</th></tr>';
    foreach ($k['years'] as $y) {
        $v = $e['years'][$y] ?? null;
        echo '<tr><td>' . g_e($y) . '</td><td>' . ($v && $v[0] !== null ? number_format($v[0]) : '—') . '</td><td>' . g_health_pct(g_health_kenshin_rate($v, 0)) . '</td><td>' . ($v && $v[2] !== null ? number_format($v[2]) : '—') . '</td><td>' . g_health_pct(g_health_kenshin_rate($v, 1)) . '</td></tr>';
    }
    echo '</table><p class="note">「—」は公表されていない年度・値です。</p>' . g_health_src('特定健康診査・特定保健指導の実施状況（保険者別）（厚生労働省）', (string)$k['sources']['2024'], '保険者番号 ' . $no . '。2024年度は2026年10月29日の訂正後');
    if ($e['kind'] === 'kokuho' && $e['pref'] !== '') {
        $t = $k['totals']['kokuho|' . $e['pref']]['2024'] ?? null; $tn = $k['totals']['kokuho']['2024'] ?? null;
        echo '<h2 data-en="Compare">同じ種類の中で</h2><table class="htab"><tr><th></th><th>受診率</th><th>保健指導</th></tr>'
           . '<tr><td>' . g_e($e['short']) . '</td><td>' . g_health_pct(g_health_kenshin_rate($v24, 0)) . '</td><td>' . g_health_pct(g_health_kenshin_rate($v24, 1)) . '</td></tr>'
           . '<tr><td>' . g_e($e['pref']) . 'の市町村国保</td><td>' . g_health_pct(g_health_kenshin_rate($t, 0)) . '</td><td>' . g_health_pct(g_health_kenshin_rate($t, 1)) . '</td></tr>'
           . '<tr><td>全国の市町村国保</td><td>' . g_health_pct(g_health_kenshin_rate($tn, 0)) . '</td><td>' . g_health_pct(g_health_kenshin_rate($tn, 1)) . '</td></tr></table>';
        echo '<p><a href="' . g_url('health/alt') . '#areas">' . g_e($e['pref']) . 'の健診の数値（ALT・腹囲など）を地域ごとに見る</a></p>';
    }
    echo '<div class="fwarn">保健指導の実施率は、健診で生活習慣の改善が必要とされた人のうち、特定保健指導を終えた人の割合です。対象の人数が少ない保険者では、年によって大きく動きます。保険者の取り組みの良し悪しを判定するものではありません。</div>';
    echo '<p class="more"><a class="btn o" href="' . g_url('health/kenshin') . '">保険者の一覧へ戻る</a></p>';
    g_health_credit();
    g_foot();
}

// ---------------------------------------------------------------- /health/food
function g_page_health_food(string $slug = ''): void
{
    $fd = g_health_json('food');
    if (!$fd) { g_flow_404('health'); return; }
    if ($slug === '') {
        $title = '料理1人前の栄養｜日本食品標準成分表で計算';
        $desc = 'おでんとおにぎり、ミルフィーユ鍋、切り干し大根の酢の物など、身近な料理1人前のエネルギー・たんぱく質・脂質・炭水化物・食物繊維・食塩を、日本食品標準成分表（八訂）増補2023年で計算して並べます。';
        g_head($title, $desc, '/health/food', ['nosuffix' => true, 'image' => g_health_og('food'), 'jsonld' => g_jsonld([g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health'], ['料理の栄養', '/health/food']])])]);
        echo g_health_css();
        echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, 'health'], ['料理の栄養', '']]) . '<p class="kick">Food</p><h1>' . g_e($title) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p></section>';
        echo g_share_bar('【' . G_HEALTH_NAME . '】' . $title, 'health/food', 'health-food');
        echo '<div class="hgrid">';
        foreach ($fd['dishes'] as $d) {
            $t = $d['total'];
            echo '<a class="htopic" href="' . g_url('health/food/' . $d['slug']) . '"><div class="nm">' . g_e($d['name']) . '</div><div class="v">' . number_format((float)$t['kcal']) . '<span style="font-size:15px">kcal</span></div><div class="mt">たんぱく質 ' . g_e((string)$t['protein']) . 'g・脂質 ' . g_e((string)$t['fat']) . 'g・炭水化物 ' . g_e((string)$t['carb']) . 'g・食物繊維 ' . g_e((string)$t['fiber']) . 'g・食塩 ' . g_e((string)$t['salt']) . 'g</div></a>';
        }
        echo '</div><p class="note">具材と量は当社が置いた前提です（料理のページに書いています）。市販品や店の料理の実際の栄養とは違います。</p>';
        g_health_credit(); g_foot(); return;
    }
    $d = null; foreach ($fd['dishes'] as $x) { if ($x['slug'] === $slug) { $d = $x; break; } }
    if (!$d) { g_flow_404('health/food'); return; }
    $t = $d['total'];
    $title = $d['name'] . 'の栄養｜カロリー・たんぱく質・食物繊維・食塩';
    $desc = $d['name'] . 'は約' . number_format((float)$t['kcal']) . 'kcal、たんぱく質' . $t['protein'] . 'g、脂質' . $t['fat'] . 'g、炭水化物' . $t['carb'] . 'g、食物繊維' . $t['fiber'] . 'g、食塩' . $t['salt'] . 'g。日本食品標準成分表（八訂）増補2023年で具材ごとに計算しました。';
    g_head($title, $desc, '/health/food/' . $slug, ['nosuffix' => true, 'image' => g_health_og('food'), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_HEALTH_NAME, '/health'], ['料理の栄養', '/health/food'], [$d['short'], '/health/food/' . $slug]]),
        ['@type' => 'Recipe', 'name' => $d['name'], 'recipeIngredient' => array_map(fn($i) => $i['label'] . ' ' . $i['g'] . 'g', $d['items']),
         'nutrition' => ['@type' => 'NutritionInformation', 'calories' => round((float)$t['kcal']) . ' kcal', 'proteinContent' => $t['protein'] . ' g', 'fatContent' => $t['fat'] . ' g',
                         'carbohydrateContent' => $t['carb'] . ' g', 'fiberContent' => $t['fiber'] . ' g', 'sodiumContent' => round((float)$t['salt'] / 2.54 * 1000) . ' mg']]])]);
    echo g_health_css();
    echo '<section class="hero p">' . g_health_crumb([[G_HEALTH_NAME, 'health'], ['料理の栄養', 'health/food'], [$d['short'], '']]) . '<p class="kick">Food</p><h1 style="overflow-wrap:anywhere">' . g_e($d['name']) . '</h1>'
       . '<div class="kv"><div class="c"><b>' . number_format((float)$t['kcal']) . 'kcal</b><span>エネルギー</span></div><div class="c"><b>' . g_e((string)$t['protein']) . 'g</b><span>たんぱく質</span></div>'
       . '<div class="c"><b>' . g_e((string)$t['fiber']) . 'g</b><span>食物繊維</span></div><div class="c"><b>' . g_e((string)$t['salt']) . 'g</b><span>食塩</span></div></div></section>';
    echo g_share_bar('【' . G_HEALTH_NAME . '】' . $d['name'] . 'は約' . number_format((float)$t['kcal']) . 'kcal・たんぱく質' . $t['protein'] . 'g・食物繊維' . $t['fiber'] . 'g・食塩' . $t['salt'] . 'g', 'health/food/' . $slug, 'health-food-' . $slug);
    echo '<h2 data-en="Nutrition">1人前の栄養</h2><table class="htab"><tr><th>成分</th><th>量</th></tr>'
       . '<tr><td>エネルギー</td><td>' . number_format((float)$t['kcal']) . ' kcal</td></tr><tr><td>たんぱく質</td><td>' . $t['protein'] . ' g</td></tr><tr><td>脂質</td><td>' . $t['fat'] . ' g</td></tr>'
       . '<tr><td>炭水化物（利用可能炭水化物）</td><td>' . $t['carb'] . ' g</td></tr><tr><td>食物繊維</td><td>' . $t['fiber'] . ' g</td></tr><tr><td>食塩相当量</td><td>' . $t['salt'] . ' g</td></tr></table>';
    echo '<h2 data-en="Ingredients">具材と量（当社の前提）</h2><table class="htab"><tr><th>具材</th><th>量</th><th>kcal</th><th>たんぱく質</th><th>食塩</th></tr>';
    foreach ($d['items'] as $i) { echo '<tr><td>' . g_e($i['label']) . '<br><span class="note">成分表 ' . g_e($i['no']) . ' ' . g_e($i['food']) . '</span></td><td>' . g_e((string)$i['g']) . 'g</td><td>' . number_format((float)$i['kcal']) . '</td><td>' . g_e((string)$i['protein']) . 'g</td><td>' . g_e((string)$i['salt']) . 'g</td></tr>'; }
    echo '</table><p class="note">' . g_e($d['note']) . '</p>' . g_health_src($fd['source']['title'], $fd['source']['url'], '100gあたりの成分値×量÷100');
    echo '<div class="fwarn">具材と量は当社が置いた前提で、市販品や店の料理の実際の栄養とは違います。商品の栄養成分表示があれば、そちらを見てください。医療の助言ではありません。</div>';
    echo '<h2 data-en="More">ほかの料理と、食べ方のデータ</h2><div class="hgrid">';
    foreach ($fd['dishes'] as $x) { if ($x['slug'] !== $slug) { echo '<a class="htopic" href="' . g_url('health/food/' . $x['slug']) . '"><div class="nm">' . g_e($x['name']) . '</div><div class="mt">' . number_format((float)$x['total']['kcal']) . 'kcal</div></a>'; } }
    foreach (['yashoku', 'amai-nomimono', 'hayagui'] as $s) { $tp = g_health_json($s); if ($tp) { echo '<a class="htopic" href="' . g_url('health/' . $s) . '"><div class="nm">' . g_e($tp['name']) . '</div><div class="mt">全国 ' . g_health_pct($tp['national']['all']) . '（都道府県・地域ごと）</div></a>'; } }
    echo '</div>';
    g_health_credit();
    g_foot();
}
