<?php
/**
 * Kurage 政策の流れトラッカー（/flow・/flow/<分野>・/flow/<分野>/p/<予算事業ID>）。2026-11-05
 *
 * 政策形成（会議）→予算→採択→実施（支出先）→評価→国会の質疑 を、公表資料だけでつないで並べる。
 * データは kseisakuflow（/home/kojima/work/kseisakuflow）の build_flow.py が data/flow/<分野>.json に書く。
 *
 * **このページがしないこと**: 利益相反の有無の判断、事業・組織・人の評価、推測。
 * 組織名や氏名が同じものは「同じ名前が両方に出る」と並べるだけ。すべての数字と名前に出典を付ける。
 */
declare(strict_types=1);

const G_FLOW_NAME = 'Kurage 政策の流れトラッカー';
// 契約を集める型の分野（政府広報など）の画面
if (is_file(__DIR__ . '/flow_koho.php')) { require_once __DIR__ . '/flow_koho.php'; }
// 採択先ごとの成果（公開モデル・論文・ベンチマーク）
if (is_file(__DIR__ . '/flow_outcomes.php')) { require_once __DIR__ . '/flow_outcomes.php'; }

function g_flow_404(string $slug): void
{
    http_response_code(404); g_head('見つかりません', '', '/flow/' . $slug, ['noindex' => true]);
    echo '<h1>見つかりません</h1><p><a href="' . g_url('flow/' . $slug) . '">一覧へ</a></p>'; g_foot();
}

function g_flow_index(): array
{
    static $ix = null;
    if ($ix === null) {
        $ix = @json_decode((string)@file_get_contents(__DIR__ . '/../data/flow/index.json'), true) ?: ['fields' => []];
    }
    return $ix;
}

function g_flow_load(string $slug): ?array
{
    static $c = [];
    if (!preg_match('/^[a-z0-9-]{1,40}$/', $slug)) { return null; }
    if (!array_key_exists($slug, $c)) {
        $f = __DIR__ . '/../data/flow/' . $slug . '.json';
        $c[$slug] = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    }
    return $c[$slug];
}

/** 国会トラッカーのキー → その話題を含む分野（相互リンク用） */
function g_flow_for_tracker(string $key): array
{
    return array_values(array_filter(g_flow_index()['fields'] ?? [], fn($f) => in_array($key, (array)($f['trackers'] ?? []), true)));
}

/** 国会トラッカーの各ページに出す「政策の流れ」への枠 */
function g_flow_tracker_box(string $key, string $ref): string
{
    $fs = g_flow_for_tracker($key);
    if (!$fs) { return ''; }
    $h = '';
    foreach ($fs as $f) {
        $s = $f['summary'];
        $line = ($f['type'] ?? '') === 'contracts'
            ? g_e($s['exec_year']) . '年度に執行された広報・周知・啓発などの契約' . number_format((int)$s['contracts']) . '件（' . g_flow_yen($s['sum']) . '）を、府省・契約先・事業ごとに、入札者数・落札率と成果目標の記載と並べています。'
            : (g_flow_plans_mode($f)
                ? '国会での議論の先にある、事業の根拠になった計画・予算・支出先・目標と実績・点検を、公表資料だけでつないで並べています。関連事業' . (int)$s['projects'] . '件（うち国会の質疑とつながる事業' . (int)($s['with_kokkai'] ?? 0) . '件）。'
                : '国会での議論の先にある、会議での政策形成・予算・採択・評価を、公表資料だけでつないで並べています。AI関係の会議' . (int)$s['councils'] . '・関連事業' . (int)$s['projects'] . '件・公募の採択' . (int)$s['award_rows'] . '件。');
        $h .= '<section class="panel" style="margin:16px 0;border-left:4px solid #3949ab;background:#f5f6fc"><p style="margin:0 0 4px;font-weight:900;color:#283593">'
            . g_e(G_FLOW_NAME) . '｜' . g_e($f['name']) . '</p>'
            . '<p style="margin:0 0 8px">' . $line . '</p>'
            . '<a class="btn" style="background:#3949ab;border-color:#3949ab" href="' . g_url('flow/' . $f['slug']) . '#ref=' . g_e($ref) . '">' . g_e($f['name']) . 'の流れを見る</a></section>';
    }
    return $h;
}

/** 分野のカード画像（scripts/make_ogp_flow.py が作る）。無ければ入口のカード */
/** 分野ごとの画面の言い回し（fields/<分野>.json の ui。無ければAI政策の言い回し） */
function g_flow_ui(?array $d, string $k, string $def = ''): string
{
    $ui = (array)($d['field']['ui'] ?? $d['ui'] ?? []);
    return (string)($ui[$k] ?? $def);
}

/** 会議資料ではなく、行政事業レビューの「関係する計画・通知等」を政策形成の段にする分野か */
function g_flow_plans_mode(?array $d): bool
{
    return g_flow_ui($d, 'policy', 'councils') === 'plans';
}

function g_flow_og(string $slug): string
{
    return g_abs(is_file(__DIR__ . '/../img/og/flow-' . $slug . '.png') ? 'img/og/flow-' . $slug . '.png' : 'img/og/flow.png');
}

function g_flow_yen($n): string
{
    if ($n === null || $n === '') { return '—'; }
    $n = (float)$n;
    if (abs($n) >= 1e8) { return number_format($n / 1e8, 1) . '億円'; }
    if (abs($n) >= 1e4) { return number_format($n / 1e4, 0) . '万円'; }
    return number_format($n) . '円';
}

function g_flow_src(string $title, string $url, string $date = '', string $extra = ''): string
{
    return '<p class="note fsrc">出典：' . ($url !== '' ? '<a href="' . g_e($url) . '" rel="noopener" target="_blank">' . g_e($title) . '</a>' : g_e($title))
         . ($extra !== '' ? '　' . g_e($extra) : '') . ($date !== '' ? '（取得 ' . g_e($date) . '）' : '') . '</p>';
}

function g_flow_rs_src(array $p, string $prefix = '1-2_'): string
{
    $s = $p['source'] ?? [];
    if ($prefix !== '1-2_') { $s = ['title' => str_replace('1-2_RS', substr($prefix, 0, 3) . 'RS', (string)($s['title'] ?? '')), 'url' => $s['url'] ?? '']; }
    return g_flow_src((string)($s['title'] ?? '行政事業レビュー見える化サイト'), (string)($s['url'] ?? 'https://rssystem.go.jp/'), '2026-11-05', '予算事業ID ' . $p['id']);
}

function g_flow_css(): string
{
    // 国会トラッカー（ティール）と見分けがつくよう、このページの色は藍（カード img/og/flow-*.png と同じ系統）にそろえる
    return '<style>'
         . ':root{--teal:#3949ab;--teal-d:#283593;--teal-l:#5c6bc0;--mint:#9fa8da;--mint-l:#c5cae9;--mint-xl:#e8eaf6;--sh2:0 10px 26px rgba(57,73,171,.16)}'
         . '.hero{background:linear-gradient(135deg,#f0f1fa 0%,#dde1f5 48%,#bcc4ec 100%)!important}'
         . 'h2::after{background:linear-gradient(90deg,#3949ab,#f59e0b)!important}'
         . '.fstages{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:16px 0;counter-reset:fs}'
         . '.fstage{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px;box-shadow:var(--sh);min-width:0}'
         . '.fstage .h{font-weight:900;color:var(--teal-d);font-size:14px}.fstage .h::before{counter-increment:fs;content:counter(fs) ". "}'
         . '.fstage b{display:block;font-size:24px;line-height:1.3;margin:4px 0 2px;color:var(--navy);overflow-wrap:anywhere}'
         . '.fstage span{display:block;font-size:13px;color:var(--gray);line-height:1.6}'
         . '.fsrc{margin:6px 0 0;font-size:12.5px;overflow-wrap:anywhere}'
         . '.fdl{display:grid;grid-template-columns:minmax(6.5em,max-content) 1fr;gap:4px 12px;margin:0}.fdl dt{font-weight:700;color:var(--gray)}.fdl dd{margin:0;min-width:0;overflow-wrap:anywhere}'
         . '.fyear{border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin:8px 0;background:#fff}'
         . '.fyear .y{font-weight:900;margin:0 0 4px}'
         . '.flist{list-style:none;padding:0;margin:0}.flist li{border-top:1px solid var(--line);padding:9px 0;overflow-wrap:anywhere}.flist li:first-child{border-top:0}'
         . '.fchain{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}.fchain span{font-size:12.5px;border-radius:999px;padding:2px 10px;background:#eef2f4;color:#6b7785}.fchain span.on{background:var(--mint-xl);color:var(--teal-d);font-weight:700}'
         . '.fproj{display:block;border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:10px 0;background:#fff;text-decoration:none;color:inherit;box-shadow:var(--sh)}'
         . '.fproj:hover{border-color:var(--teal)}.fproj .nm{font-weight:900;line-height:1.6;overflow-wrap:anywhere}.fproj .mt{font-size:13px;color:var(--gray)}'
         . 'details.fd{border:1px solid var(--line);border-radius:12px;padding:8px 12px;margin:8px 0;background:#fff}details.fd summary{cursor:pointer;font-weight:700}'
         . 'blockquote.fq{margin:6px 0;padding:8px 12px;border-left:3px solid var(--mint);background:#f7fbfa;font-size:14px;overflow-wrap:anywhere}'
         . '.fwarn{background:#fff8e6;border:1px solid #f0d58a;border-radius:12px;padding:10px 14px;font-size:14px}'
         . '#fq{width:100%;max-width:520px;box-sizing:border-box;font-size:16px;padding:10px 12px;border:1px solid #c9d3dc;border-radius:8px}'
         . '</style>';
}

/** 事業1件が、どの段までつながっているか（公表資料で確かめられた段だけ色を付ける） */
function g_flow_chain(array $p, bool $plans = false): string
{
    $rv = $p['review'] ?? null;
    $steps = [
        [$plans ? '計画' : '会議', $plans ? !empty($p['policy_on']) : !empty($p['council_refs'])], ['予算', !empty($p['budget'])], ['採択', !empty($p['programs'])],
        ['支出先', !empty($p['payees'])], ['評価', ($rv && ($rv['self_result'] || $rv['ext_opinion'])) || !empty($p['evaluations'])], ['国会', !empty($p['kokkai'])],
    ];
    $h = '<div class="fchain" aria-label="公表資料でつながった段">';
    foreach ($steps as [$l, $on]) { $h .= '<span class="' . ($on ? 'on' : '') . '">' . ($on ? '✓ ' : '') . $l . '</span>'; }
    return $h . '</div>';
}

function g_flow_budget_of(array $p, string $year): ?array
{
    foreach ($p['budget'] as $b) { if ($b['year'] === $year) { return $b; } }
    return null;
}

function g_flow_proj_card(string $slug, array $p): string
{
    $b25 = g_flow_budget_of($p, '2025'); $b24 = g_flow_budget_of($p, '2024');
    return '<a class="fproj" href="' . g_url('flow/' . $slug . '/p/' . $p['id']) . '"><div class="nm">' . g_e($p['name']) . '</div>'
         . '<div class="mt">' . g_e($p['ministry']) . ($p['bureau'] ? '　' . g_e($p['bureau']) : '')
         . '　2025年度当初 ' . g_flow_yen($b25['initial'] ?? null) . '・2024年度執行 ' . g_flow_yen($b24['exec'] ?? null) . '</div>'
         . g_flow_chain($p, g_flow_plans_mode(g_flow_load($slug))) . '</a>';
}

// ---------------------------------------------------------------- /flow
function g_page_flow_index(): void
{
    $fs = g_flow_index()['fields'] ?? [];
    $desc = '政策がどの会議で形づくられ、どれだけの予算がつき、だれに採択され、どう評価されたかを、行政事業レビュー・会議資料・採択結果・国会会議録などの公表資料だけでつないで並べます。';
    g_head(G_FLOW_NAME . '｜会議・予算・採択・評価を公表資料でつなぐ', $desc, '/flow', ['nosuffix' => true, 'image' => g_abs('img/og/flow.png'), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow']])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › ' . g_e(G_FLOW_NAME) . '</nav>'
       . '<p class="kick">Policy Flow Tracker</p><h1>' . g_e(G_FLOW_NAME) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】政策の会議・予算・採択・評価を、公表資料だけでつないで並べる', 'flow', 'flow');
    echo '<h2 data-en="Fields">分野</h2>';
    foreach ($fs as $f) {
        $s = $f['summary'];
        if (($f['type'] ?? '') === 'contracts') {
            echo '<a class="fproj" href="' . g_url('flow/' . $f['slug']) . '"><div class="nm">' . g_e($f['title']) . '</div><div class="mt">' . g_e($f['lead']) . '</div>'
               . '<div class="mt" style="margin-top:6px">' . g_e($s['exec_year']) . '年度執行の契約 ' . number_format((int)$s['contracts']) . '件・' . g_flow_yen($s['sum']) . '・契約先 ' . number_format((int)$s['payees']) . '　更新 ' . g_e($f['generated_at']) . '</div></a>';
            continue;
        }
        if (g_flow_plans_mode($f)) {
            echo '<a class="fproj" href="' . g_url('flow/' . $f['slug']) . '"><div class="nm">' . g_e($f['title']) . '</div><div class="mt">' . g_e($f['lead']) . '</div>'
               . '<div class="mt" style="margin-top:6px">関連事業 ' . (int)$s['projects'] . '件（事業名に語を含む ' . (int)$s['projects_name'] . '件）・計画とつながる事業 ' . (int)($s['with_plans'] ?? 0) . '件・国会の質疑とつながる事業 ' . (int)($s['with_kokkai'] ?? 0) . '件　更新 ' . g_e($f['generated_at']) . '</div></a>';
            continue;
        }
        echo '<a class="fproj" href="' . g_url('flow/' . $f['slug']) . '"><div class="nm">' . g_e($f['title']) . '</div>'
           . '<div class="mt">' . g_e($f['lead']) . '</div>'
           . '<div class="mt" style="margin-top:6px">会議 ' . (int)$s['councils'] . '・関連事業 ' . (int)$s['projects'] . '件・公募 ' . (int)$s['programs'] . '件（採択 ' . (int)$s['award_rows'] . '件）・評価 ' . (int)$s['evaluations'] . '件　更新 ' . g_e($f['generated_at']) . '</div></a>';
    }
    echo '<div class="panel"><p style="margin:0">国会トラッカーは「国会で何が議論されたか」を並べています。こちらは、その先の「政策がどのように実行され、どのような成果を生んだか」を、公表資料でたどれるようにする試みです。'
       . 'AI政策から始め、分野を順に広げています。</p></div>';
    g_foot();
}

// ---------------------------------------------------------------- /flow/<分野>
function g_page_flow(string $slug): void
{
    $d = g_flow_load($slug);
    if (!$d) { http_response_code(404); g_head('見つかりません', '', '/flow', ['noindex' => true]); echo '<h1>その分野はまだありません</h1><p><a href="' . g_url('flow') . '">' . G_FLOW_NAME . 'へ</a></p>'; g_foot(); return; }
    if (($d['type'] ?? '') === 'contracts' && function_exists('g_page_flow_koho')) { g_page_flow_koho($slug, $d); return; }
    $f = $d['field']; $s = $d['summary'];
    $title = $f['title'];
    $plans = g_flow_plans_mode($d);
    $topic = g_flow_ui($d, 'topic', 'AI');
    $desc = $plans
        ? $topic . 'に関する行政事業レビューの事業' . $s['projects'] . '件について、根拠になった計画・予算・支出先・目標と実績・点検と、国会の質疑を、公表資料だけでつないで並べます。'
        : 'AI政策の会議' . $s['councils'] . '（構成員' . $s['members'] . '人・開催' . $s['meetings'] . '回）、行政事業レビューのAI関連事業' . $s['projects'] . '件、NEDOなどの公募' . $s['programs'] . '件（採択' . $s['award_rows'] . '件）、評価、国会の質疑を、公表資料だけでつないで並べます。';
    g_head($title, $desc, '/flow/' . $slug, ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug]]),
        ['@type' => 'Dataset', 'name' => $title, 'description' => $desc, 'url' => g_abs('flow/' . $slug), 'inLanguage' => 'ja',
         'dateModified' => $d['generated_at'], 'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ'],
         'isBasedOn' => $plans ? ['https://rssystem.go.jp/download-csv/2025', 'https://kokkai.ndl.go.jp/'] : ['https://rssystem.go.jp/download-csv/2025', 'https://www8.cao.go.jp/cstp/ai/', 'https://www.nedo.go.jp/', 'https://kokkai.ndl.go.jp/']]])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow') . '">' . g_e(G_FLOW_NAME) . '</a> › ' . g_e($f['name']) . '</nav>'
       . '<p class="kick">Policy Flow Tracker</p><h1>' . g_e($title) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($f['lead']) . '</p></section>';
    echo g_share_bar($plans
        ? '【' . G_FLOW_NAME . '】' . $f['name'] . 'の予算' . $s['projects'] . '事業の計画・支出先・点検と国会の質疑を、公表資料だけでつないで並べる'
        : '【' . G_FLOW_NAME . '】AI政策の会議・予算' . $s['projects'] . '事業・公募の採択' . $s['award_rows'] . '件・評価を、公表資料だけでつないで並べる', 'flow/' . $slug, 'flow-' . $slug);

    // ---- 段ごとの件数と金額
    if ($plans) {
        echo '<h2 data-en="Flow">流れの全体</h2><div class="fstages">'
           . '<div class="fstage"><div class="h">政策の根拠（計画）</div><b>' . (int)($s['with_plans'] ?? 0) . '事業</b><span>行政事業レビューに「関係する計画・通知等」の記載がある事業</span></div>'
           . '<div class="fstage"><div class="h">予算</div><b>' . (int)$s['projects_name'] . '事業</b><span>事業名に語を含む事業の2025年度当初予算の合計 ' . g_flow_yen($s['initial_2025_name']) . '（概要にだけ語を含む事業は別に ' . (int)$s['projects_body'] . '事業）</span></div>'
           . '<div class="fstage"><div class="h">実施（支出先）</div><b>' . g_flow_yen($s['exec_2024_name']) . '</b><span>事業名に語を含む事業の、2024年度の執行額の合計。支出先の記載（全事業） ' . number_format((int)$s['payee_rows']) . '件</span></div>'
           . '<div class="fstage"><div class="h">成果評価</div><b>' . (int)$s['with_review'] . '事業</b><span>行政事業レビューの点検の記載あり（うち外部有識者の所見 ' . (int)$s['with_ext_review'] . '事業）</span></div>'
           . '<div class="fstage"><div class="h">国会の質疑</div><b>' . (int)($s['with_kokkai'] ?? 0) . '事業</b><span>事業や制度の名前で国会会議録を引き、質疑がつながった事業</span></div>'
           . '</div>';
        echo '<div class="fwarn">金額は行政事業レビュー見える化サイト（RSシステム）のCSVの値（円）を億円に換算したものです。'
           . '関連事業は、事業名または事業の目的・現状・概要に「' . g_e($d['words_label']) . '」のいずれかの語を含む事業を機械的に拾っています（2025年度のシートに無い事業は2024年度のシートから）。'
           . '概要にだけ語を含む事業（' . (int)$s['projects_body'] . '事業）には、情報システムの整備や中小企業の補助金のように、セキュリティが目的の一部にすぎない大きな事業も含まれるため、上の金額は事業名に語を含む事業だけで数えています（概要にだけ語を含む事業も足した2024年度の執行額は ' . g_flow_yen($s['exec_2024']) . '）。'
           . '国会の質疑は、事業や制度の名前（例：能動的サイバー防御、NOTICE、サイバーセキュリティお助け隊）で引いたもので、その事業そのものを取り上げた質疑とは限りません。</div>';
    } else {
    echo '<h2 data-en="Flow">流れの全体</h2><div class="fstages">'
       . '<div class="fstage"><div class="h">政策形成（会議）</div><b>' . (int)$s['councils'] . '会議</b><span>構成員 ' . (int)$s['members'] . '人・開催 ' . (int)$s['meetings'] . '回・提言や取りまとめ ' . (int)$s['outputs'] . '本</span></div>'
       . '<div class="fstage"><div class="h">予算</div><b>' . (int)$s['projects'] . '事業</b><span>2025年度シートの' . (int)$s['projects_2025'] . '事業の当初予算の合計 ' . g_flow_yen($s['initial_2025']) . '（うち事業名に語を含む ' . (int)$s['projects_name'] . '事業 ' . g_flow_yen($s['initial_2025_name']) . '）</span></div>'
       . '<div class="fstage"><div class="h">採択</div><b>' . (int)$s['programs'] . '公募</b><span>公表された採択先 ' . (int)$s['award_rows'] . '件（NEDO・JST・NICT）' . (!empty($s['outcomes']) ? '。<a href="#outcomes">採択先の成果</a>：公開モデル ' . (int)$s['outcomes']['hf_models'] . '件' : '') . '</span></div>'
       . '<div class="fstage"><div class="h">実施（支出先）</div><b>' . g_flow_yen($s['exec_2024']) . '</b><span>2025年度シートの' . (int)$s['projects_2025'] . '事業の、2024年度の執行額の合計。支出先の記載 ' . number_format((int)$s['payee_rows']) . '件</span></div>'
       . '<div class="fstage"><div class="h">成果評価</div><b>' . (int)$s['with_review'] . '事業</b><span>行政事業レビューの点検の記載あり（うち外部有識者の所見 ' . (int)$s['with_ext_review'] . '事業）。NEDO・経済産業省などの評価 ' . (int)$s['evaluations'] . '件</span></div>'
       . '</div>';
    echo '<div class="fwarn">金額は行政事業レビュー見える化サイト（RSシステム）のCSVの値（円）を億円に換算したものです。'
       . '関連事業は、事業名または事業の目的・現状・概要に「' . g_e($d['words_label']) . '」のいずれかの語を含む事業を機械的に拾っています（2025年度のシートに無い事業は2024年度のシートから）。'
       . '概要にだけ語を含む事業（' . (int)$s['projects_body'] . '事業）には、AI以外が主な目的の事業も含まれます。'
       . '2024年度の執行額の合計（' . g_flow_yen($s['exec_2024']) . '）のうち、事業名に語を含む事業は ' . g_flow_yen($s['exec_2024_name']) . 'です。'
       . 'このうち最も大きい「ポスト５Ｇ情報通信システム基盤強化研究開発事業（AI基盤モデル及び先端半導体関連技術開発事業）」は、事業名のとおり先端半導体の開発も含む基金への交付です。'
       . (isset($s['supp_2024']) ? '<br><b>執行額が当初予算より大きい理由</b>：2024年度の執行額（' . g_flow_yen($s['exec_2024']) . '）は、2024年度の当初予算（' . g_flow_yen($s['initial_2024']) . '）に、補正予算（' . g_flow_yen($s['supp_2024']) . '）と前年度からの繰越し（' . g_flow_yen($s['carried_2024'])
          . '）を加えた歳出予算現額（' . g_flow_yen($s['current_2024']) . '）を使った額です（行政事業レビューの「予算・執行サマリ」の合計の行の値で、支出先の行は足していません）。上の「2025年度の当初予算」とは年度が違い、補正予算も含みません。2024年度の執行額の大きい3事業は '
          . implode('、', array_map(fn($t) => '「' . g_e($t['name']) . '」' . g_flow_yen($t['exec']) . '（うち補正 ' . g_flow_yen($t['supp']) . '）', (array)($s['top_exec_2024'] ?? []))) . ' です（RSの記載では、いずれも2024年度の当初予算は0円）。' : '')
       . '</div>';
    }
    echo g_flow_src('行政事業レビュー見える化サイト（RSシステム） CSVダウンロード 2025年度・2024年度（1-2 事業概要等・2-1 予算・執行サマリ・5-1 支出先）を加工して作成', 'https://rssystem.go.jp/download-csv/2025', '2026-11-05');

    // ---- つながりが見える事業
    echo $plans
        ? '<h2 data-en="Linked">計画から国会の質疑までつながった事業</h2><p class="note">公表資料で確かめられた段に ✓ を付けています。計画は、行政事業レビューにその事業が書いている「関係する計画・通知等」（サイバーセキュリティ戦略など）です。</p>'
        : '<h2 data-en="Linked">会議から評価までつながった事業</h2><p class="note">公表資料で確かめられた段に ✓ を付けています。会議は、内閣府のAI関係の会議の資料・議事要旨・提言に、事業名（または関連語）が出るものです。</p>';
    $shown = 0;
    foreach ($d['projects'] as $p) {
        if ((int)$p['stages'] < 4) { continue; }
        echo g_flow_proj_card($slug, $p); $shown++;
        if ($shown >= 12) { break; }
    }

    // ---- 会議（会議資料を集めていない分野では出さない）
    if (!empty($d['councils'])) {
    echo '<h2 id="councils" data-en="Councils">政策形成：AIに関する会議と構成員</h2>';
    foreach ($d['councils'] as $c) {
        $r = $c['rosters'] ? $c['rosters'][count($c['rosters']) - 1] : null;
        $mts = $c['meetings'] ?? [];
        echo '<section class="panel" id="c-' . g_e($c['id']) . '"><h3 style="margin-top:0">' . g_e($c['name']) . '</h3>'
           . '<dl class="fdl"><dt>設置の根拠</dt><dd>' . g_e(is_array($c['basis'] ?? null) ? (string)($c['basis']['text'] ?? $c['basis']['title'] ?? '') : (string)($c['basis'] ?? '未取得')) . '</dd>'
           . '<dt>開催</dt><dd>' . count($mts) . '回' . ($mts ? '（' . g_e((string)$mts[0]['date']) . '〜' . g_e((string)$mts[count($mts) - 1]['date']) . '）' : '') . '</dd></dl>';
        if ($r) {
            echo '<details class="fd"><summary>構成員 ' . count($r['members']) . '人（' . g_e((string)($r['as_of'] ?? '')) . ' 時点の名簿）</summary><ul class="flist">';
            foreach ($r['members'] as $m) {
                echo '<li><b>' . g_e($m['name']) . '</b>' . (!empty($m['role']) ? ' <span class="pill">' . g_e((string)$m['role']) . '</span>' : '') . '<br><span class="note">' . g_e((string)$m['affiliation']) . '</span></li>';
            }
            echo '</ul>' . g_flow_src((string)($r['source_title'] ?? '名簿'), (string)($r['source_url'] ?? ''), (string)($d['councils_meta']['fetched_at'] ?? '')) . '</details>';
            if (count($c['rosters']) > 1) { echo '<p class="note">名簿は ' . count($c['rosters']) . ' 版あります（改選・追加）。ここには最新の版を出しています。</p>'; }
        }
        if (!empty($c['outputs'])) {
            echo '<details class="fd"><summary>提言・取りまとめ ' . count($c['outputs']) . '本</summary><ul class="flist">';
            foreach ($c['outputs'] as $o) {
                echo '<li><a href="' . g_e((string)$o['url']) . '" rel="noopener" target="_blank">' . g_e($o['title']) . '</a> <span class="note">' . g_e((string)$o['date']) . '</span>';
                foreach (array_slice((array)($o['quotes'] ?? []), 0, 3) as $q) {
                    echo '<blockquote class="fq">' . g_e((string)$q['text']) . '<br><span class="note">' . g_e($o['title']) . ' ' . (int)$q['page'] . 'ページ（PDFのページ）</span></blockquote>';
                }
                echo '</li>';
            }
            echo '</ul></details>';
        }
        echo g_flow_src($c['name'] . '（内閣府）', (string)$c['url'], (string)($d['councils_meta']['fetched_at'] ?? '')) . '</section>';
    }

    }
    // ---- 計画（行政事業レビューの「関係する計画・通知等」を、計画ごとに束ねる）
    if ($plans) {
        $by = [];
        foreach ($d['projects'] as $p) { foreach ((array)$p['plans'] as $pl) { $k = (string)preg_replace('/[「」『』\s　]/u', '', (string)$pl['title']); $by[$k] = $by[$k] ?? ['title' => trim((string)preg_replace('/[「」『』]/u', '', (string)$pl['title'])), 'url' => (string)$pl['url'], 'ps' => []]; $by[$k]['ps'][$p['id']] = $p; } }
        uasort($by, fn($a, $b) => count($b['ps']) <=> count($a['ps']));
        echo '<h2 id="plans" data-en="Plans">政策の根拠：事業が挙げている計画・通知</h2><p class="note">行政事業レビューで、各事業が「関係する計画・通知等」に書いているものを、挙げている事業の多い順に並べています（表記は各事業の記載どおり）。</p>';
        foreach (array_slice($by, 0, 20, true) as $t => $v) {
            echo '<details class="fd"><summary>' . g_e($v['title']) . '　<span class="note">' . count($v['ps']) . '事業</span></summary>'
               . ($v['url'] !== '' ? '<p class="note" style="overflow-wrap:anywhere"><a href="' . g_e($v['url']) . '" rel="noopener" target="_blank">' . g_e($v['url']) . '</a></p>' : '') . '<ul class="flist">';
            foreach ($v['ps'] as $p) { echo '<li><a href="' . g_url('flow/' . $slug . '/p/' . $p['id']) . '">' . g_e($p['name']) . '</a> <span class="note">' . g_e($p['ministry']) . '</span></li>'; }
            echo '</ul></details>';
        }
        echo g_flow_src('行政事業レビュー見える化サイト（RSシステム） CSVダウンロード 2025年度・2024年度（1-3 関係する計画・通知等）を加工して作成', 'https://rssystem.go.jp/download-csv/2025', '2026-11-05');
    }
    // ---- 採択（公募を集めていない分野では出さない）
    if (!empty($d['programs'])) {
    echo '<h2 id="awards" data-en="Awards">採択：公募と採択先</h2><p class="note">採択先は各機関の公表資料の表記どおりです。採択先ごとの金額は公表資料に見当たらず「未取得」です。</p>';
    foreach ($d['programs'] as $g) {
        $pid = $d['linked_programs'][$g['id']] ?? null;
        $aw = (array)($g['awards'] ?? []);
        echo '<details class="fd" id="g-' . g_e($g['id']) . '"><summary>' . g_e($g['name']) . '　<span class="note">' . g_e((string)$g['agency']) . '・採択 ' . g_e((string)($g['award_date'] ?? '')) . '・' . count($aw) . '件</span></summary>';
        echo '<dl class="fdl" style="margin-top:8px"><dt>公募期間</dt><dd>' . g_e((string)($g['call_period'] ?? '未取得')) . '</dd>'
           . '<dt>応募</dt><dd>' . g_e((string)($g['applications'] ?? '未取得')) . '</dd>'
           . '<dt>予算の事業</dt><dd>' . ($pid ? '<a href="' . g_url('flow/' . $slug . '/p/' . $pid) . '">予算事業ID ' . g_e((string)$pid) . '</a>' : g_e($d['unlinked_programs_note'])) . '</dd></dl>';
        echo '<ul class="flist">';
        foreach ($aw as $a) {
            $org = (string)($a['org'] ?? '');
            $listed = (array)($a['orgs_listed'] ?? []);
            echo '<li><b>' . g_e(str_starts_with($org, '未取得') && $listed ? implode('・', $listed) : $org) . '</b>'
               . (!empty($a['co_orgs']) ? '<br><span class="note">共同：' . g_e(implode('・', (array)$a['co_orgs'])) . '</span>' : '')
               . (!empty($a['theme']) ? '<br><span class="note">' . g_e((string)$a['theme']) . '</span>' : '') . '</li>';
        }
        echo '</ul>' . g_flow_src((string)($g['source_title'] ?? ''), (string)($g['source_url'] ?? ''), (string)($d['awards_meta']['fetched_at'] ?? '')) . '</details>';
    }

    }
    // ---- 採択先の成果
    if (function_exists('g_flow_oc_section') && !empty($d['outcomes'])) { echo g_flow_oc_css() . g_flow_oc_section($slug, $d); }

 if (!empty($d['councils']) || !empty($d['programs'])) {
    // ---- 重なり
    echo '<h2 id="overlap" data-en="Same names">会議の構成員の所属と、支出先・採択先に同じ組織名が出る例</h2>';
    echo '<div class="panel"><p style="margin:0">会議の構成員名簿にある所属の組織名と、AI関連事業の支出先（行政事業レビュー）・公募の採択先（NEDO・JST・NICTの公表資料）の組織名を、法人の種類を表す語（株式会社・国立大学法人など）と空白を除いて機械的に突き合わせ、同じ組織名が両方に出るものを並べています。'
       . '<b>組織名が同じことを示すだけで、構成員本人が事業や審査にかかわったことや、利益相反があることを示すものではありません。</b>利益相反の管理について公表されている定めは<a href="#coi">下の節</a>にあります。</p></div>';
    echo '<p><b>' . count($d['overlaps']) . '組織</b>で、同じ組織名が両方に出ます。</p>';
    foreach ($d['overlaps'] as $o) {
        $nm = $o['payees'][0]['payee'] ?? ($o['awards'][0]['org'] ?? $o['key']);
        echo '<details class="fd"><summary>' . g_e($nm) . '　<span class="note">支出先 ' . count(array_unique(array_column($o['payees'], 'project_id'))) . '事業・採択 ' . count($o['awards']) . '件</span></summary>';
        echo '<p style="margin:8px 0 2px;font-weight:700">会議の構成員名簿</p><ul class="flist">';
        // 個人名は出さない（2026-11-05 ユーザー了承）。会議ごとに、この組織名を所属に含む構成員の人数だけ示す。
        $byc = [];
        foreach ($o['members'] as $m) { $k = (string)$m['council']; if (!isset($byc[$k])) { $byc[$k] = ['n' => 0, 'title' => (string)$m['source_title'], 'url' => (string)$m['source_url']]; } $byc[$k]['n']++; }
        foreach ($byc as $cn => $v) {
            echo '<li>' . g_e($cn) . '：構成員 ' . (int)$v['n'] . '人の所属にこの組織名が出ます' . g_flow_src($v['title'], $v['url']) . '</li>';
        }
        echo '</ul>';
        if ($o['payees']) {
            echo '<p style="margin:8px 0 2px;font-weight:700">AI関連事業の支出先（行政事業レビュー）</p><ul class="flist">';
            $seen = [];
            foreach ($o['payees'] as $py) {
                $k = $py['project_id'] . '|' . $py['block'];
                if (isset($seen[$k])) { continue; } $seen[$k] = 1;
                echo '<li><a href="' . g_url('flow/' . $slug . '/p/' . $py['project_id']) . '">' . g_e($py['project']) . '</a><br><span class="note">' . g_e($py['ministry']) . '　支出先「' . g_e($py['payee']) . '」 ブロック' . g_e($py['block']) . '　' . g_flow_yen($py['amount']) . '（' . g_e($py['sheet']) . '年度シート）</span></li>';
            }
            echo '</ul>';
        }
        if ($o['awards']) {
            echo '<p style="margin:8px 0 2px;font-weight:700">公募の採択先</p><ul class="flist">';
            foreach ($o['awards'] as $a) {
                echo '<li>' . g_e($a['program']) . '（採択 ' . g_e((string)$a['award_date']) . '）<br><span class="note">「' . g_e($a['org']) . '」' . ($a['theme'] ? '　' . g_e($a['theme']) : '') . '</span>' . g_flow_src((string)$a['source_title'], (string)$a['source_url']) . '</li>';
            }
            echo '</ul>';
        }
        echo '</details>';
    }
    if (!empty($d['person_overlaps'])) {
        echo '<h3>会議の構成員名簿と、採択審査委員会の委員一覧に同じ氏名が出る例</h3><p class="note">氏名と所属は各資料の記載のとおりです。審査で委員がどの提案を担当したかは公表されていません（NEDOの公募要領は「助成事業者の選定は非公開で行われ」と書いています）。</p>';
        foreach ($d['person_overlaps'] as $po) {
            $nm = $po['committees'][0]['name'];
            echo '<details class="fd"><summary>' . g_e($nm) . '　<span class="note">会議 ' . count($po['members']) . '・審査委員会 ' . count($po['committees']) . '件</span></summary><ul class="flist">';
            foreach ($po['members'] as $m) { echo '<li>' . g_e($m['council']) . ' 構成員（' . g_e($m['affiliation']) . '）' . g_flow_src((string)$m['source_title'], (string)$m['source_url']) . '</li>'; }
            foreach ($po['committees'] as $c) { echo '<li>' . g_e($c['program']) . ' 採択審査委員会 ' . g_e($c['role']) . '（' . g_e($c['affiliation_in_list']) . '）' . g_flow_src('採択審査委員会 委員一覧', (string)$c['list_url']) . '</li>'; }
            echo '</ul></details>';
        }
    }

    // ---- 利益相反の管理
    echo '<h2 id="coi" data-en="Conflict of interest">利益相反の管理について公表されている定め</h2>';
    echo '<h3>会議（内閣府）</h3><ul class="flist">';
    foreach ($d['councils'] as $c) {
        $ci = $c['coi'] ?? [];
        echo '<li><b>' . g_e($c['name']) . '</b>：' . g_e((string)($ci['status'] ?? '未取得'));
        foreach ((array)($ci['documents'] ?? []) as $doc) { echo '<blockquote class="fq">' . g_e((string)($doc['text'] ?? '')) . '</blockquote>' . g_flow_src((string)$doc['title'], (string)$doc['url']); }
        if (($ci['status'] ?? '') !== 'あり') {
            $chk = (array)($ci['checked'] ?? []);
            echo '<br><span class="note">構成員の利益相反・利害関係に関する定めは、確かめた公表資料（' . count($chk) . '件）の中に見当たりませんでした。運営の文書にあるのは会議・議事要旨・資料の公開に関する定めです。</span>';
            foreach (array_slice((array)($ci['related_rules'] ?? []), 0, 2) as $rr) { echo '<blockquote class="fq">' . g_e((string)$rr['text']) . '</blockquote>' . g_flow_src((string)$rr['title'], (string)$rr['url']); }
            if ($chk) { echo '<details class="fd"><summary>確かめた資料</summary><ul>'; foreach ($chk as $u) { echo '<li style="overflow-wrap:anywhere"><a href="' . g_e((string)$u) . '" rel="noopener" target="_blank">' . g_e((string)$u) . '</a></li>'; } echo '</ul></details>'; }
        }
        echo '</li>';
    }
    echo '</ul><h3>採択の審査と評価（NEDO・JST・NICT）</h3><ul class="flist">';
    $done = [];
    foreach ($d['programs'] as $g) {
        $ci = $g['coi'] ?? [];
        $docs = (array)($ci['documents'] ?? []);
        $k = $g['agency'] . '|' . ($docs[0]['title'] ?? $ci['status'] ?? '');
        if (isset($done[$k])) { continue; } $done[$k] = 1;
        echo '<li><b>' . g_e((string)$g['agency']) . '</b>（例：' . g_e($g['name']) . '）：' . g_e((string)($ci['status'] ?? '未取得'));
        foreach (array_slice($docs, 0, 1) as $doc) {
            echo '<blockquote class="fq">' . nl2br(g_e(mb_substr((string)($doc['text'] ?? ''), 0, 600))) . '</blockquote>' . g_flow_src((string)$doc['title'], (string)$doc['url']);
        }
        echo '</li>';
    }
    foreach ((array)($d['evaluation_rules'] ?? []) as $er) {
        if (empty($er['text'])) { continue; }
        echo '<li><b>NEDO の評価</b>：' . g_e((string)$er['title']) . '<blockquote class="fq">' . nl2br(g_e((string)$er['text'])) . '</blockquote>' . g_flow_src((string)$er['title'], (string)$er['url']) . '</li>';
    }
    echo '</ul>';
    }

    // ---- 国会
    echo '<h2 id="kokkai" data-en="Diet">国会の質疑（Kurage 国会トラッカー）</h2><div class="grid">';
    foreach ($d['trackers'] as $t) {
        echo '<a class="card" href="' . g_url('tracker/' . $t['key']) . '"><div class="nm">' . g_e($t['name']) . '</div><div class="n">会議録の発言 ' . number_format((int)$t['count']) . '件<br><span class="note">' . g_e((string)$t['from']) . '〜' . g_e((string)$t['to']) . '</span></div></a>';
    }
    echo '</div>' . g_flow_src('国立国会図書館 国会会議録検索システム（Kurage 国会トラッカーで収集）', 'https://kokkai.ndl.go.jp/');

    // ---- 全事業
    echo '<h2 id="projects" data-en="All projects">' . g_e($topic) . '関連事業の一覧（' . count($d['projects']) . '件）</h2>'
       . '<p><input id="fq" type="search" placeholder="' . g_e(g_flow_ui($d, 'search_ph', '事業名・府省で探す（例：生成AI、経済産業省）')) . '" autocomplete="off"><br><span class="note" id="fqn"></span></p><div id="fall">';
    foreach ($d['projects'] as $p) {
        echo '<div data-q="' . g_e(mb_strtolower($p['name'] . ' ' . $p['ministry'] . ' ' . $p['bureau'])) . '">' . g_flow_proj_card($slug, $p) . '</div>';
    }
    echo '</div><script>(function(){var q=document.getElementById("fq"),n=document.getElementById("fqn"),cs=[].slice.call(document.querySelectorAll("#fall>div"));'
       . 'function f(){var v=q.value.trim().toLowerCase().split(/[\\s　、,]+/).filter(Boolean),k=0;cs.forEach(function(c){var ok=v.every(function(w){return c.getAttribute("data-q").indexOf(w)>=0;});c.style.display=ok?"":"none";if(ok)k++;});n.textContent=v.length?k+"件":"";}'
       . 'q.addEventListener("input",f);})();</script>';
    g_flow_credit($d);
    g_foot();
}

function g_flow_credit(array $d): void
{
    echo '<h2 data-en="Sources">出典と作り方</h2><div class="panel"><ul style="margin:0;padding-left:18px">'
       . '<li>予算・執行・支出先・目標と実績・点検：出典 <a href="https://rssystem.go.jp/" rel="noopener" target="_blank">行政事業レビュー見える化サイト</a>（CSVダウンロード 2024年度・2025年度）のデータを加工して作成。公共データ利用規約（第1.0版）（PDL1.0）に準拠。取得 2026-11-05。</li>'
       . (empty($d['councils']) ? '<li>政策の根拠：行政事業レビューの「関係する計画・通知等」の記載（各事業が挙げたもの）。</li>' : '<li>会議の名簿・開催・資料・提言：内閣府 科学技術・イノベーション推進事務局の <a href="https://www8.cao.go.jp/cstp/ai/" rel="noopener" target="_blank">AI関係のページ</a> と e-Gov法令検索。取得 ' . g_e((string)($d['councils_meta']['fetched_at'] ?? '')) . '。</li>')
       . (empty($d['programs']) ? '' : '<li>採択・審査・評価：<a href="https://www.nedo.go.jp/" rel="noopener" target="_blank">NEDO</a>・<a href="https://www.meti.go.jp/" rel="noopener" target="_blank">経済産業省</a>・<a href="https://www.jst.go.jp/" rel="noopener" target="_blank">JST</a>・<a href="https://www.nict.go.jp/" rel="noopener" target="_blank">NICT</a>の公表資料から、法人名・テーマ名・日付・規程の文言を出所を明示して引用。取得 ' . g_e((string)($d['awards_meta']['fetched_at'] ?? '')) . '。</li>')
       . '<li>国会の発言：<a href="https://kokkai.ndl.go.jp/" rel="noopener" target="_blank">国立国会図書館 国会会議録検索システム</a>（検索用API）。抜粋は会議録の文をそのまま切り出したものです。</li>'
       . (empty($d['councils']) ? '<li>国会の質疑と事業のつながりは、事業や制度の名前（設定した語）で国会会議録を引いたもので、その事業そのものを取り上げた質疑とは限りません。</li>' : '<li>会議と事業のつながりは、会議の資料・議事要旨・提言のPDFの本文に、行政事業レビューの事業名（括弧の前まで・10字以上）か、設定した関連語（GENIACなど）が出るかで機械的に判定しています。採択と事業のつながりは、公募名や評価報告書の「METI予算要求名称」の記載によります。</li>')
       . '<li>要約・論評・評価はしていません。誤りに気づいたら <a href="https://exbridge.jp/contact.php#ref=flow-fix">お問い合わせ</a> からお知らせください。</li>'
       . '</ul></div>';
}

// ---------------------------------------------------------------- /flow/<分野>/p/<予算事業ID>
function g_page_flow_project(string $slug, string $id): void
{
    $d = g_flow_load($slug);
    if (($d['type'] ?? '') === 'contracts' && function_exists('g_page_flow_koho_project')) { g_page_flow_koho_project($slug, $id); return; }
    $p = null;
    foreach (($d['projects'] ?? []) as $x) { if ((string)$x['id'] === $id) { $p = $x; break; } }
    if (!$p) { http_response_code(404); g_head('見つかりません', '', '/flow/' . $slug, ['noindex' => true]); echo '<h1>その事業は見つかりません</h1><p><a href="' . g_url('flow/' . $slug) . '">一覧へ</a></p>'; g_foot(); return; }
    $f = $d['field'];
    $b25 = g_flow_budget_of($p, '2025'); $b24 = g_flow_budget_of($p, '2024');
    $title = $p['name'];
    $plans = g_flow_plans_mode($d);
    $desc = $p['ministry'] . 'の「' . $p['name'] . '」の予算・執行・支出先・点検' . ($p['programs'] ? '・公募の採択先' : '') . ($p['council_refs'] ? '・AIに関する会議の資料での言及' : '') . ($plans && $p['plans'] ? '・根拠の計画' : '') . ($p['kokkai'] ? '・国会の質疑' : '') . 'を、公表資料からつないで並べます。';
    if (mb_strlen($title) > 40) { $title = mb_substr($title, 0, 40) . '…'; }
    g_head($title . '｜予算・採択・評価の流れ', $desc, '/flow/' . $slug . '/p/' . $id, ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug], [$title, '/flow/' . $slug . '/p/' . $id]])])]);
    echo g_flow_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow') . '">' . g_e(G_FLOW_NAME) . '</a> › <a href="' . g_url('flow/' . $slug) . '">' . g_e($f['name']) . '</a> › 予算事業ID ' . g_e($id) . '</nav>'
       . '<p class="kick">' . g_e($p['ministry']) . ($p['bureau'] ? '　' . g_e($p['bureau']) : '') . '</p><h1 style="overflow-wrap:anywhere">' . g_e($p['name']) . '</h1>'
       . '<div class="kv"><div class="c"><b>' . g_flow_yen($b25['initial'] ?? null) . '</b><span>2025年度 当初予算</span></div>'
       . '<div class="c"><b>' . g_flow_yen($b24['current'] ?? null) . '</b><span>2024年度 歳出予算現額</span></div>'
       . '<div class="c"><b>' . g_flow_yen($b24['exec'] ?? null) . '</b><span>2024年度 執行額</span></div></div></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】' . $p['name'] . '（' . $p['ministry'] . '）の予算・支出先・評価の流れ', 'flow/' . $slug . '/p/' . $id, 'flow-' . $slug . '-p');
    echo g_flow_chain($p, $plans);
    echo '<p class="note">' . ($p['tier'] === 'name' ? '事業名' : '事業の目的・現状・概要') . 'に「' . g_e((string)$p['hit_word']) . '」を含むため、' . g_e(g_flow_ui($d, 'topic', 'AI')) . '関連事業として拾っています。</p>';

    echo '<h2 data-en="Outline">事業の概要</h2><div class="panel"><dl class="fdl">'
       . '<dt>目的</dt><dd>' . nl2br(g_e(str_replace('／', "\n", (string)$p['purpose']))) . '</dd>'
       . '<dt>概要</dt><dd>' . nl2br(g_e(str_replace('／', "\n", mb_substr((string)$p['overview'], 0, 900)))) . (mb_strlen((string)$p['overview']) > 900 ? '…' : '') . '</dd>'
       . '<dt>期間</dt><dd>' . g_e((string)$p['start']) . '年度〜' . g_e((string)$p['end']) . (preg_match('/^\d+$/', (string)$p['end']) ? '年度' : '') . '</dd>'
       . ($p['url'] ? '<dt>事業のページ</dt><dd><a href="' . g_e((string)$p['url']) . '" rel="noopener" target="_blank">' . g_e((string)$p['url']) . '</a></dd>' : '')
       . '</dl>' . g_flow_rs_src($p) . '</div>';

    // 1. 会議（計画を根拠にする分野では、行政事業レビューの「関係する計画・通知等」を開いて出す）
    if ($plans) {
        echo '<h2 data-en="Plans">1. 政策の根拠：関係する計画・通知</h2>';
        if ($p['plans']) {
            echo '<ul class="flist">';
            foreach ($p['plans'] as $pl) { echo '<li>' . ($pl['url'] ? '<a href="' . g_e($pl['url']) . '" rel="noopener" target="_blank">' . g_e($pl['title']) . '</a>' : g_e($pl['title'])) . '</li>'; }
            echo '</ul>' . g_flow_rs_src($p, '1-3_');
        } else {
            echo '<p>行政事業レビューに、この事業の「関係する計画・通知等」の記載はありません。</p>';
        }
    } else {
    echo '<h2 data-en="Councils">1. 政策形成：AIに関する会議での言及</h2>';
    if ($p['council_refs']) {
        echo '<ul class="flist">';
        foreach ($p['council_refs'] as $r) {
            echo '<li><b>' . g_e($r['council']) . (isset($r['meeting_no']) && $r['meeting_no'] !== null ? ' 第' . g_e((string)$r['meeting_no']) . '回' : '') . '</b> <span class="note">' . g_e((string)($r['date'] ?? '')) . '・' . g_e($r['kind']) . '</span><br>'
               . '<a href="' . g_e($r['url']) . '" rel="noopener" target="_blank">' . g_e($r['title']) . '</a> <span class="note">' . g_e(implode('・', array_map('strval', array_slice($r['pages'], 0, 8)))) . 'ページ（PDFのページ）に'
               . ($r['how'] === '事業名' ? '事業名' : '「' . g_e(implode('」「', $r['terms'])) . '」') . 'が出ます</span></li>';
        }
        echo '</ul>' . g_flow_src('内閣府 AI関係の会議の資料・議事要旨・提言（PDF本文の機械照合）', 'https://www8.cao.go.jp/cstp/ai/', (string)($d['councils_meta']['fetched_at'] ?? ''));
    } else {
        echo '<p>内閣府のAI関係の会議の資料・議事要旨・提言（取得したPDF）の本文に、この事業名は見つかりませんでした。</p>';
    }
    if ($p['plans']) {
        echo '<details class="fd"><summary>行政事業レビューに書かれた「関係する計画・通知等」（' . count($p['plans']) . '件）</summary><ul class="flist">';
        foreach ($p['plans'] as $pl) { echo '<li>' . ($pl['url'] ? '<a href="' . g_e($pl['url']) . '" rel="noopener" target="_blank">' . g_e($pl['title']) . '</a>' : g_e($pl['title'])) . '</li>'; }
        echo '</ul>' . g_flow_rs_src($p, '1-3_') . '</details>';
    }
    }

    // 2. 予算
    echo '<h2 data-en="Budget">2. 予算と執行</h2>';
    foreach ($p['budget'] as $b) {
        echo '<div class="fyear"><p class="y">' . g_e($b['year']) . '年度</p><dl class="fdl">'
           . '<dt>当初予算</dt><dd>' . g_flow_yen($b['initial']) . '</dd>'
           . ($b['supplementary'] ? '<dt>補正予算</dt><dd>' . g_flow_yen($b['supplementary']) . '</dd>' : '')
           . ($b['carried_in'] ? '<dt>前年度から繰越し</dt><dd>' . g_flow_yen($b['carried_in']) . '</dd>' : '')
           . '<dt>歳出予算現額</dt><dd>' . g_flow_yen($b['current']) . '</dd>'
           . '<dt>執行額</dt><dd>' . g_flow_yen($b['exec']) . ($b['rate'] !== '' && $b['rate'] !== null ? '（執行率 ' . g_e((string)round((float)$b['rate'] * 100, 1)) . '%）' : '') . '</dd></dl></div>';
    }
    if (!$p['budget']) { echo '<p>予算・執行のデータは未取得です。</p>'; }
    echo g_flow_rs_src($p, '2-1_');

    // 3. 採択（公募を集めていない分野では出さない。節の番号は詰める）
    $no = 3;
    if (!empty($d['programs'])) {
    $no = 4;
    echo '<h2 data-en="Awards">3. 採択：公募と採択先</h2>';
    if ($p['programs']) {
        echo '<p class="note">つながりの根拠：' . g_e((string)($p['programs_basis'] ?? '')) . '</p>';
        $pg = [];
        foreach ($d['programs'] as $g) { $pg[$g['id']] = $g; }
        foreach ($p['programs'] as $gid) {
            $g = $pg[$gid] ?? null; if (!$g) { continue; }
            echo '<details class="fd"><summary>' . g_e($g['name']) . '　<span class="note">採択 ' . g_e((string)$g['award_date']) . '・' . count((array)$g['awards']) . '件</span></summary><ul class="flist">';
            foreach ((array)$g['awards'] as $a) {
                $org = (string)($a['org'] ?? ''); $listed = (array)($a['orgs_listed'] ?? []);
                echo '<li><b>' . g_e(str_starts_with($org, '未取得') && $listed ? implode('・', $listed) : $org) . '</b>' . (!empty($a['co_orgs']) ? '<br><span class="note">共同：' . g_e(implode('・', (array)$a['co_orgs'])) . '</span>' : '') . (!empty($a['theme']) ? '<br><span class="note">' . g_e((string)$a['theme']) . '</span>' : '') . '</li>';
            }
            $rv = is_array($g['review'] ?? null) ? $g['review'] : [];
            $com = is_array($rv['committee'] ?? null) ? $rv['committee'] : [];
            echo '</ul>';
            if ($com) {
                echo '<p style="margin:8px 0 0"><b>採択審査委員会</b>：委員長 ' . g_e((string)($com['chair'] ?? '未取得')) . '、委員 ' . g_e(implode('、', (array)($com['members'] ?? []))) . '</p>'
                   . g_flow_src('採択審査委員会 委員一覧（所属は資料のとおり）', (string)($com['list_url'] ?? ''));
            }
            echo g_flow_src((string)$g['source_title'], (string)$g['source_url'], (string)($d['awards_meta']['fetched_at'] ?? '')) . '</details>';
        }
    } else {
        echo '<p>この事業につながる公募の採択結果は、集めた公表資料（NEDO・JST・NICTの' . count($d['programs']) . '公募）の中では確かめられていません。支出先は次の節のとおりです。</p>';
    }
    }

    if (function_exists('g_flow_oc_section') && !empty($d['outcomes']) && !empty($p['outcome_keys'])) {
        echo g_flow_oc_css() . str_replace('採択先の成果：', '3-2. 採択先の成果：', g_flow_oc_section($slug, $d, (array)$p['outcome_keys']));
    }

    // 4. 支出先
    echo '<h2 data-en="Payees">' . $no++ . '. 実施：支出先</h2>';
    if ($p['payees']) {
        echo '<p class="note">支出先 ' . count($p['payees']) . '件（ブロックごと）のうち、金額の大きい順に15件。ブロックはお金の流れの段（A＝最初の支出先、B以降はその先）です。</p><ul class="flist">';
        foreach (array_slice($p['payees'], 0, 15) as $py) {
            echo '<li><b>' . g_e($py['name']) . '</b>　' . g_flow_yen($py['amount']) . '<br><span class="note">ブロック' . g_e($py['block']) . ($py['block_name'] ? '「' . g_e($py['block_name']) . '」' : '') . ($py['corp_no'] ? '　法人番号 ' . g_e($py['corp_no']) : '') . ($py['role'] ? '　' . g_e(mb_substr($py['role'], 0, 80)) : '') . '</span></li>';
        }
        echo '</ul>';
    } else { echo '<p>支出先の記載は未取得です。</p>'; }
    echo g_flow_rs_src($p, '5-1_');

    // 5. 評価
    echo '<h2 data-en="Evaluation">' . $no++ . '. 成果評価</h2>';
    $rv = $p['review'];
    if ($rv) {
        echo '<div class="panel"><dl class="fdl">';
        foreach ([['self_result', '所管部局の点検結果'], ['self_direction', '改善の方向性'], ['ext_target', '外部有識者の点検'], ['ext_opinion', '外部有識者の所見'], ['team', '推進チームの所見'], ['team_detail', '所見の詳細'], ['reflect_detail', '所見を踏まえた改善点']] as [$k, $l]) {
            if (!empty($rv[$k])) { echo '<dt>' . $l . '</dt><dd>' . nl2br(g_e(str_replace('／', "\n", (string)$rv[$k]))) . ($k === 'ext_target' && $rv['ext_year'] ? '（' . g_e((string)$rv['ext_year']) . '年度）' : '') . '</dd>'; }
        }
        echo '</dl>' . g_flow_rs_src($p, '4-1_') . '</div>';
    }
    if ($p['outcomes']) {
        echo '<details class="fd"><summary>目標と実績（' . count($p['outcomes']) . '件）</summary><ul class="flist">';
        foreach ($p['outcomes'] as $o) {
            $tg = []; foreach ((array)$o['target'] as $y => $v) { $tg[] = $y . '年度 ' . $v; }
            $ac = []; foreach ((array)$o['actual'] as $y => $v) { $ac[] = $y . '年度 ' . $v; }
            echo '<li><span class="pill gray">' . g_e($o['kind']) . '</span> ' . g_e((string)$o['goal']) . '<br><span class="note">指標：' . g_e((string)$o['indicator']) . ($o['unit'] ? '（' . g_e((string)$o['unit']) . '）' : '')
               . '<br>目標値：' . g_e($tg ? implode('・', $tg) : '記載なし') . '<br>実績値：' . g_e($ac ? implode('・', $ac) : '記載なし') . ($o['qual'] ? '<br>' . g_e(mb_substr((string)$o['qual'], 0, 200)) : '') . '</span></li>';
        }
        echo '</ul>' . g_flow_rs_src($p, '3-1_') . '</details>';
    }
    if ($p['evaluations']) {
        echo '<p class="note">NEDO・経済産業省などの評価（つながりの根拠：' . g_e((string)($p['evaluations_basis'] ?? '')) . '）</p><ul class="flist">';
        foreach ($p['evaluations'] as $i) {
            $e = $d['evaluations'][$i] ?? null; if (!$e) { continue; }
            echo '<li><b>' . g_e((string)$e['project']) . '</b><br><span class="note">' . g_e((string)$e['agency']) . '・' . g_e((string)$e['type']) . '・' . g_e((string)$e['year']) . '</span>'
               . (!empty($e['score']) ? '<blockquote class="fq">' . g_e(mb_substr((string)$e['score'], 0, 500)) . '</blockquote>' : '')
               . (!empty($e['summary_quote']) ? '<blockquote class="fq">' . g_e(mb_substr((string)$e['summary_quote'], 0, 300)) . '</blockquote>' : '')
               . g_flow_src('評価報告書', (string)$e['url']) . '</li>';
        }
        echo '</ul>';
    }

    // 6. 国会
    echo '<h2 data-en="Diet">' . $no++ . '. 国会の質疑</h2>';
    if ($p['kokkai']) {
        echo '<p class="note">国会会議録で「' . g_e(implode('」「', (array)$p['kokkai_terms'])) . '」を含む発言（' . (int)$p['kokkai_total'] . '件）のうち新しい順。</p>';
        foreach (array_slice($p['kokkai'], 0, 12) as $s) {
            echo '<div class="sp"><div class="m"><b>' . g_e(g_date($s['date'])) . '</b><span class="pill gray">' . g_e($s['house'] . ' ' . $s['meeting']) . '</span>'
               . '<span>' . g_e($s['speaker']) . '</span>' . ($s['position'] ? '<span class="note">' . g_e($s['position']) . '</span>' : '') . '</div>'
               . '<div class="t">' . g_e($s['excerpt']) . '</div><div class="lk"><a href="' . g_e($s['url']) . '" rel="nofollow noopener" target="_blank">この発言を会議録で読む</a></div></div>';
        }
        echo g_flow_src('国立国会図書館 国会会議録検索システム', 'https://kokkai.ndl.go.jp/', (string)($p['kokkai'][0]['fetched'] ?? ''));
    } else {
        echo '<p>この事業の名前で引いた国会の発言は、まだ取り込んでいません。' . g_e($f['name']) . '全体の質疑は国会トラッカーにあります。</p>';
    }
    echo '<div class="grid">';
    foreach ($d['trackers'] as $t) { echo '<a class="card" href="' . g_url('tracker/' . $t['key']) . '"><div class="nm">' . g_e($t['name']) . '</div><div class="n">会議録の発言 ' . number_format((int)$t['count']) . '件</div></a>'; }
    echo '</div>';

    // 重なり
    $keys = (array)($p['overlap_keys'] ?? []);
    if ($keys) {
        echo '<h2 data-en="Same names">会議の構成員の所属と同じ組織名</h2><p class="note">この事業の支出先・採択先のうち、AI関係の会議の構成員名簿の所属と同じ組織名が出るもの。組織名が同じことを示すだけで、利益相反があることを示すものではありません。</p><ul class="flist">';
        foreach ($d['overlaps'] as $o) {
            if (!in_array($o['key'], $keys, true)) { continue; }
            $nm = $o['payees'][0]['payee'] ?? ($o['awards'][0]['org'] ?? $o['key']);
            echo '<li><b>' . g_e($nm) . '</b><br><span class="note">' . g_e(implode('、', array_unique(array_map(fn($m) => (string)$m['council'], $o['members'])))) . 'の構成員名簿の所属に出る組織名</span></li>';
        }
        echo '</ul><p><a href="' . g_url('flow/' . $slug) . '#overlap">組織名の重なりの全体と、利益相反の管理について公表されている定め</a></p>';
    }
    echo '<p class="more"><a class="btn o" href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . 'へ戻る</a></p>';
    g_flow_credit($d);
    g_foot();
}
