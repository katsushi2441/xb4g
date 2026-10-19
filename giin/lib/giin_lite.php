<?php
/**
 * 全国の国会議員の「軽い議員ページ」（2026-10-15〜）。
 *
 * **なぜ作ったか。** 議員の投稿に国会トラッカーのカードを付けて返信している（2026-10-14〜）。返信の相手は全国の議員だが、
 * 議員ページがあったのは愛知の46人だけで、ほかの議員には「その人の国会での記録」を1つのURLで渡せなかった。
 *
 * **くわしいページ（愛知の46人・g_page_giin）との違い。** 本人の発信（公式サイト・YouTube）、AIの考察、その人だけの語、
 * ことがらの集計は作らない（1人ずつ手で確かめる・ローカルAIを何万回も回すので711人には回らない）。
 * 出すのは、会議録から取った発言（2023年〜）・出た会議・国会トラッカーのことがら・議院の公式プロフィールだけ。
 *
 * 表は giin_all / speech_all（全国）。giin / speech は愛知だけのビューなので、ここでは使わない。
 */
declare(strict_types=1);

require_once __DIR__ . '/card.php';

function g_gall(): string
{
    static $t = null;
    if ($t === null) { $t = g_val("SELECT COUNT(*) FROM sqlite_master WHERE name='giin_all'") ? 'giin_all' : 'giin'; }
    return $t;
}

function g_sall(): string
{
    return g_gall() === 'giin_all' ? 'speech_all' : 'speech';
}

/** 「高知1」→「高知1区」、「（比）近畿」→「比例近畿ブロック」、参議院「比例」→「比例代表」 */
function g_ku_all(string $house, string $d): string
{
    if (preg_match('/^(\D+?)(\d+)$/u', $d, $m)) { return $m[1] . $m[2] . '区'; }
    if (preg_match('/^（比）(.+)$/u', $d, $m)) { return '比例' . $m[1] . 'ブロック'; }
    if ($house === '参議院') { return $d === '比例' ? '比例代表' : $d . '選挙区'; }
    return $d;
}

/** 衆議院の小選挙区なら選挙区ダッシュボードのキー（aichi-1 など） */
function g_sk_key_any(string $house, string $d): ?string
{
    if ($house !== '衆議院' || !preg_match('/^\D+\d+$/u', $d)) { return null; }
    if (!g_val("SELECT COUNT(*) FROM sqlite_master WHERE name='senkyoku'")) { return null; }
    $k = g_val('SELECT key FROM senkyoku WHERE name=?', [$d . '区']);
    return $k ? (string)$k : null;
}

function g_lite_years(int $id): array
{
    $by = [];
    foreach (g_all('SELECT substr(date,1,4) y, kind, COUNT(*) n FROM ' . g_sall() . ' WHERE giin_id=? GROUP BY y, kind', [$id]) as $r) {
        $by[$r['y']][$r['kind']] = (int)$r['n'];
    }
    return $by;
}

/** カード画像（1200×630）: 名前・院と選挙区・質疑と答弁の件数・年ごとの質疑・直近の質問 */
function g_giin_png(string $slug): void
{
    // **作り置きの名前に件数と最後の日付を入れる。** 名前だけで1日使い回していたら、発言を取り込む前に描いた
    // 「0件」の画像が出続けた（2026-10-15 蓮舫議員。ページは417件なのにカードは0件）。数字が変われば別の画像になる
    $g = g_one('SELECT * FROM ' . g_gall() . ' WHERE slug=?', [$slug]);
    if (!$g) { header('Location: ' . g_abs('ogp.png'), true, 302); return; }
    $ver = g_giin_card_ver($g);
    $f = g_card_cache_dir('giin') . '/' . preg_replace('/[^a-z0-9-]/', '', $slug) . '_' . $ver . '.png';
    g_card_serve($f, fn() => g_giin_draw($g), g_abs('ogp.png'), 0);
}

/** カードの版（件数と最後の日付）。作り置きの名前と、ページに埋める画像URLの ?v= に使う。
 *  URLが同じだと、ブラウザもXも前に取った画像（取り込み前の0件）を使い続ける（2026-10-15） */
function g_giin_card_ver(array $g): string
{
    // 答弁が質疑より多い議員（大臣・総理）は答弁を主にしたカード。版の名前も分ける（2026-10-15）
    // 質問主意書の件数も版に入れる（2026-10-18 カードに足した）
    $sh = g_giin_shuisho((string)$g['slug']);
    return (g_giin_is_gov($g) ? 'v7g-' : 'v7-') . (int)($sh['n'] ?? 0) . '-' . (int)$g['n_q'] . '-' . (int)$g['n_gov'] . '-' . (int)$g['n_chair'] . '-' . preg_replace('/[^0-9]/', '', (string)$g['last_date']);
}

/** 質問主意書の件数（kshuisho の SQLite から scripts/build_kshuisho_giin.py が拾ったもの）。無い議員は null */
function g_giin_shuisho(string $slug): ?array
{
    static $d = null;
    if ($d === null) {
        $f = __DIR__ . '/../data/kshuisho_giin.json';
        $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    return $d[$slug] ?? null;
}

/** kshuisho の提出者ページ */
function g_giin_shuisho_url(array $s, string $ref): string
{
    return 'https://kurage.exbridge.jp/kshuisho.php/giin/' . rawurlencode((string)$s['name']) . '#ref=' . $ref;
}

/** 答弁が質疑より多い議員（大臣・副大臣・政務官、総理）。カードは答弁を主に出す */
function g_giin_is_gov(array $g): bool
{
    return (int)$g['n_gov'] > (int)$g['n_q'];
}

function g_giin_draw(array $g): string
{
    $gov = g_giin_is_gov($g);
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    $ku = g_ku_all((string)$g['house'], (string)$g['district']);
    g_card_header($im, $C, '国会での発言｜' . $g['house'] . ' ' . $ku, '国会会議録から（' . g_date((string)g_meta('range_from')) . '〜）');
    $name = $g['plain'] . ' 議員（' . $g['party'] . '）';
    imagettftext($im, g_card_fit($name, $FB, 44, 1080), 0, 56, 146, $C['ink'], $FB, $name);
    // 数字は2つまでだったが、質問主意書を足すと3つになる（2026-10-18）。3つのときは字を小さくして詰める
    $items = [];
    if ($gov) {
        // 総理・大臣は質疑がほとんど無く、「質疑2件」を大きく出すと国会で話していないように見える（2026-10-15 高市総理のカード）
        $items[] = [(int)$g['n_gov'], '大臣・副大臣・政務官としての答弁'];
        $items[] = [(int)$g['n_q'], '議員として質問・討論した発言'];
    } else {
        $items[] = [(int)$g['n_q'], '議員として質問・討論した発言'];
        // 2つ目の数字は、答弁があれば答弁、なければ委員長・議長としての議事整理。0件を大きく出すと「発言が0件」に見える（2026-10-15）
        if ((int)$g['n_gov'] > 0) { $items[] = [(int)$g['n_gov'], '大臣・副大臣・政務官としての答弁']; }
        elseif ((int)$g['n_chair'] > 0) { $items[] = [(int)$g['n_chair'], '委員長・議長としての議事整理']; }
    }
    $sh = g_giin_shuisho((string)$g['slug']);
    if ($sh) { $items[] = [(int)$sh['n'], '提出した質問主意書（' . substr((string)$sh['first'], 0, 4) . '年〜）', true]; }
    $three = count($items) >= 3;
    foreach ($items as $i => $it) {
        $y = $three ? 216 + $i * 80 : 240 + $i * 96;
        $ns = $three ? 38 : 46; $num = number_format($it[0]);
        $col = !empty($it[2]) ? $C['tealD'] : $C['ink'];
        imagettftext($im, $ns, 0, 60, $y, $col, $FB, $num);
        imagettftext($im, $three ? 19 : 22, 0, 66 + g_card_textw($num, $FB, $ns), $y, $col, $FM, '件');
        imagettftext($im, $three ? 14 : 16, 0, 62, $y + ($three ? 24 : 28), $C['gray'], $FR, $it[1]);
    }
    $kd = $gov ? 'gov' : 'q';
    $by = g_lite_years((int)$g['id']);
    $rows = [];
    $y0 = (int)substr((string)g_meta('range_from') ?: '2023', 0, 4);
    for ($y = $y0; $y <= (int)date('Y'); $y++) { $rows[] = [(string)$y, (int)($by[(string)$y][$kd] ?? 0)]; }
    imagettftext($im, 16, 0, 440, 196, $C['gray'], $FR, ($gov ? '年ごとの答弁の件数' : '年ごとの質疑の件数') . '（最後の年は途中まで）');
    g_card_bars($im, $C, $rows, 440, 204, 704, 200, $C['teal'], $C['tealD']);
    // 締めのあいさつ（「ありがとうございました。」）を拾わないよう、中身のある長さの発言から選ぶ（2026-10-15 蓮舫議員のカード）
    $last = g_one('SELECT * FROM ' . g_sall() . " WHERE giin_id=? AND kind=? AND LENGTH(body)>=120 ORDER BY date DESC, speech_order DESC LIMIT 1", [(int)$g['id'], $kd]);
    if ($last) {
        $q = g_excerpt((string)$last['body']);
        $q = preg_replace('/^○\S+\s*/u', '', $q) ?? $q;
        // 冒頭のあいさつ（「ありがとうございます。」「御答弁ありがとうございました。」など）は落として本題から
        $q = preg_replace('/^(?:[^。]{0,12}(?:ありがとうございま(?:す|した)|お願いいたします|お願いします)。\s*)+/u', '', $q) ?? $q;
        imagefilledrectangle($im, 56, 430, 1144, 560, $C['mint2']);
        imagefilledrectangle($im, 56, 430, 62, 560, $C['teal']);
        imagettftext($im, 16, 0, 80, 458, $C['gray'], $FR, ($gov ? '直近の答弁（' : '直近の質問（') . g_date((string)$last['date']) . '　' . $last['house'] . ' ' . $last['meeting'] . '）');
        $ly = 494;
        foreach (g_card_wrap('「' . $q . '」', $FR, 21, 1040, 2) as $ln) {
            imagettftext($im, 21, 0, 80, $ly, $C['ink'], $FR, $ln);
            $ly += 34;
        }
    }
    g_card_footer($im, $C, $g['slug'], $sh ? '国会会議録・衆参の質問主意書から（要約していません）' : '国会会議録から（要約していません）');
    return g_card_png($im);
}

function g_page_giin_lite(int $id, int $page): void
{
    $GA = g_gall(); $SA = g_sall();
    $g = g_one("SELECT * FROM $GA WHERE id=?", [$id]);
    if (!$g) { http_response_code(404); g_head('見つかりません', '', '/', ['noindex' => true]); echo '<h1>見つかりません</h1>'; g_foot(); return; }
    $ku = g_ku_all((string)$g['house'], (string)$g['district']);
    $kind = g_kind();
    $counts = ['q' => (int)$g['n_q'], 'gov' => (int)$g['n_gov'], 'chair' => (int)$g['n_chair']];
    $from = (string)g_meta('range_from');
    $title = $g['plain'] . 'の国会発言（' . $g['house'] . ' ' . $ku . '）';
    $desc = $g['plain'] . '（' . $g['house'] . ' ' . $ku . '・' . $g['party'] . '）が' . g_date($from) . '以降に国会で行った質疑'
          . number_format($counts['q']) . '件・答弁' . number_format($counts['gov']) . '件を、日付・会議名・会議録リンクつきで並べています。要約や論評はしていません。';
    $ld = g_jsonld([
        g_crumbs([['ホーム', '/'], [$g['plain'], '/' . $g['slug']]]),
        ['@type' => 'ProfilePage', '@id' => g_abs($g['slug']), 'url' => g_abs($g['slug']), 'name' => $g['plain'] . 'の国会発言',
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')],
         'mainEntity' => array_filter(['@type' => 'Person', 'name' => $g['plain'], 'alternateName' => $g['kana'],
             'jobTitle' => $g['house'] . '議員', 'affiliation' => ['@type' => 'Organization', 'name' => $g['kaiha']],
             'url' => $g['profile'] ?: null])],
    ]);
    $cv = '?v=' . g_giin_card_ver($g);
    g_head($title, $desc, '/' . $g['slug'], ['jsonld' => $ld, 'image' => g_abs($g['slug'] . '.png') . $cv, 'nosuffix' => true]);

    echo '<nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › ' . g_e($g['plain']) . '</nav>';
    $skk = g_sk_key_any((string)$g['house'], (string)$g['district']);
    echo '<section class="hero p"><p class="kick">' . g_e($g['house']) . ' &middot; '
       . ($skk ? '<a href="' . g_url('senkyoku/' . $skk) . '">' . g_e($ku) . '（選挙区の公開データ）</a>' : g_e($ku)) . '</p>'
       . '<h1>' . g_e($g['plain']) . '<small>' . g_e($g['kana']) . '</small></h1>'
       . '<p class="lead" style="margin-bottom:0"><span class="pill">' . g_e($g['party']) . '</span>'
       . '　<span class="note">会派: ' . g_e($g['kaiha']) . '</span>'
       . ($g['wins'] ? '　<span class="note">当選' . g_e($g['wins']) . '回</span>' : '')
       . (empty($g['current']) ? '　<span class="pill gray">いまの議員名簿にはいません</span>' : '')
       . '</p>';
    // 件数の期間をすぐ下に書く。ページの一番下にしか無く、「高市早苗は質問・討論たった2回」と読まれた（2026-10-16 Xの引用）
    $kq = '<div class="c"><b>' . number_format($counts['q']) . '</b><span>議員として質問・討論した発言</span></div>';
    $kg = '<div class="c"><b>' . number_format($counts['gov']) . '</b><span>大臣・副大臣・政務官としての答弁</span></div>';
    $kc = '<div class="c"><b>' . number_format($counts['chair']) . '</b><span>委員長・議長としての議事整理</span></div>';
    $isgov = g_giin_is_gov($g);
    echo '<div class="kv">' . ($isgov ? $kg . $kq : $kq . $kg) . $kc . (($__s = g_giin_shuisho((string)$g['slug'])) ? '<a class="c" href="' . g_e(g_giin_shuisho_url($__s, 'giin-' . $g['slug'])) . '" style="text-decoration:none;color:inherit"><b>' . number_format((int)$__s['n']) . '</b><span>提出した質問主意書（' . g_e(substr((string)$__s['first'], 0, 4)) . '年〜）→ 本文と答弁書</span></a>' : '') . '</div>'
       . '<p class="note" style="margin:8px 0 0">件数は ' . g_e(g_date((string)g_meta('range_from'))) . '〜'
       . g_e(g_date((string)($g['last_date'] ?: g_meta('range_from')))) . ' の国会会議録から数えています。それより前の発言は入っていません。'
       . ($isgov ? 'この期間の多くを大臣・副大臣・政務官（総理大臣を含む）として務めているため、国会での発言の大半は政府の側の答弁で、議員としての質問・討論は少なくなります。' : '')
       . '</p></section>';
    if (function_exists('g_sess_links')) { echo g_sess_links((int)$g['id'], (string)$g['slug']); }   // 国会ごとの記録（2026-10-19）

    $links = [];
    if (!empty($g['profile'])) { $links[] = '<a href="' . g_e($g['profile']) . '" rel="nofollow noopener" target="_blank">議院の公式プロフィール</a>'; }
    // X は出さない。このサイトは「本人の公式サイトに載っているリンクだけ」を本人の発信として出す決まり（このサイトについて）。
    // giin_all.x は X監視の名簿（Wikidata・投稿の照合）由来で、公式サイトで確かめたものではない
    if ($links) { echo '<p>' . implode('　', $links) . '</p>'; }

    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url($g['slug'] . '.png') . $cv) . '" alt="' . g_e($g['plain']) . '議員の国会での発言のカード" '
       . 'width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    g_share_buttons($title, $g['slug'], 'giin');

    if ((int)$g['n_speech'] === 0) {
        echo '<div class="panel"><p>' . g_e(g_date($from)) . ' 以降、国会会議録にこの議員の発言が載っていません'
           . (empty($g['fetched_from']) ? '（いま取り込みの途中です）' : '') . '。</p>'
           . '<p class="note">発言していないことを示すものではありません。会議録の公開が追いついていない場合もあります。</p></div>';
        g_foot(); return;
    }

    // 国会トラッカー：この議員が取り上げたことがらの、国会全体の記録へ
    $trs = [];
    foreach ((G_HAS_TRACKER ? g_tracker_of_giin($id) : []) as $tr) {
        $st = g_tracker_stats($tr['key']);
        if ($st) { $trs[] = [$tr, $st]; }
    }
    if ($trs) {
        usort($trs, fn($a, $b) => $b[0]['days'] <=> $a[0]['days'] ?: $b[0]['n'] <=> $a[0]['n']);
        echo '<h2 data-en="Tracker">国会トラッカー：' . g_e($g['plain']) . '議員が取り上げたことがら</h2>'
           . '<div class="panel"><p>ことがらごとに、全国の国会議員の質疑と政府の答弁を会議録から集めています。'
           . 'ここは上の件数（' . g_e(g_date((string)g_meta('range_from'))) . '〜）と違い、ことがらごとに、それより前の会議録も含めています。'
           . '件数は' . g_e($g['plain']) . '議員の質疑の数と日数、その下が国会全体の規模です。カードを開くと、この議員の質疑だけを並べたページに行けます。</p><div class="grid">';
        foreach (array_slice($trs, 0, 12) as [$tr, $st]) {
            $tm = 'tracker/' . $tr['key'] . '/m/' . sprintf('%08x', crc32((string)$g['name']));
            echo '<a class="card" href="' . g_url($tm) . '">'
               . '<div class="nm">' . g_e($tr['name']) . '</div>'
               . '<div class="n">' . g_e($g['plain']) . '議員 ' . $tr['n'] . '件・' . $tr['days'] . '日（最後は' . g_e(g_date($tr['last'])) . '）'
               . '<br><span class="note">国会全体: 質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件・議員 ' . (int)$st['speakers'] . '人</span></div></a>';
        }
        echo '</div></div>';
    }

    $mt = g_all("SELECT house, meeting, COUNT(*) c FROM $SA WHERE giin_id=? AND kind=? GROUP BY house, meeting ORDER BY c DESC LIMIT 12", [$id, $kind]);
    echo '<h2>よく出ている会議</h2>';
    g_kind_tabs($kind, $counts, g_url($g['slug']));
    if (!$mt) { echo '<div class="panel">この立場の発言はありません。</div>'; }
    else {
        $max = (int)$mt[0]['c'];
        echo '<div class="scroll"><table><tr><th>会議</th><th class="n">件数</th><th></th></tr>';
        foreach ($mt as $r) {
            echo '<tr><td>' . g_e($r['house'] . ' ' . $r['meeting']) . '</td><td class="n">' . number_format((int)$r['c']) . '</td>'
               . '<td><div class="bar"><i style="width:' . round((int)$r['c'] / max(1, $max) * 100) . '%"></i></div></td></tr>';
        }
        echo '</table></div>';
    }

    $per = 20; $off = ($page - 1) * $per;
    $total = $counts[$kind];
    echo '<h2>' . g_e(g_kind_label($kind)) . '（' . number_format($total) . '件）</h2>';
    foreach (g_all("SELECT * FROM $SA WHERE giin_id=? AND kind=? ORDER BY date DESC, speech_order DESC LIMIT ? OFFSET ?",
                   [$id, $kind, $per, $off]) as $s) { g_speech($s, '', false); }
    g_pager($page, $total, $per, g_url($g['slug']) . '?k=' . $kind);
    echo '<p class="note" style="margin-top:16px">国会会議録検索システム（国立国会図書館）から、' . g_e(g_date($from))
       . '以降の発言を機械的に取り込んでいます。抜粋だけを載せ、要約や論評はしていません。'
       . '「議事整理」は「次に、○○君。」のような委員長・議長としての進行の発言で、質疑とは分けて数えています。</p>';
    g_foot();
}
