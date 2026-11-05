<?php
/**
 * Kurage 政策の流れトラッカー：採択先ごとの成果（公開モデル・論文・ベンチマーク・他研究での利用）。2026-11-05
 * データは kseisakuflow の scripts/fetch_outcomes.py → data/outcomes.json → build_flow.py が data/flow/<分野>.json の outcomes に入れる。
 *
 * **しないこと**: 評価語・順位付け・費用対効果の判断。スコアを自分で測らない。数字は API・公表資料の値を出典と取得日つきで並べるだけ。
 * 個人名は出さない（組織単位）。
 */
declare(strict_types=1);

function g_flow_oc_n($n): string { return $n === null ? '—' : number_format((int)$n); }

/** 節の説明（どの比較の軸の材料になるか） */
function g_flow_oc_intro(array $oc): string
{
    $s = $oc['summary'];
    return '<div class="panel"><p style="margin:0 0 6px">採択先が公開したモデル・データセット、論文、公表されたベンチマークの値を、採択先ごとに並べています。'
         . '資金配分と研究成果を比べる軸のうち、<b>技術性能</b>（公表されたベンチマークの値）と<b>技術的波及</b>（ダウンロード数、ほかの開発者がそのモデルを元に作ったモデルの数、論文と被引用）の材料になります。</p>'
         . '<p style="margin:0">採択先ごとの配分額は、集めた公表資料（NEDO の採択結果・経済産業省の評価資料・行政事業レビューの支出先）では確認できませんでした（行政事業レビューの支出先は NEDO の基金への交付の1行のみ）。'
         . 'そのため、配分額あたりの成果（<b>資源効率</b>）は比べられません。ここでは順位を付けず、数字は API や公表資料の値をそのまま、出典と取得日つきで並べます。</p></div>';
}

function g_flow_oc_stats(array $oc): string
{
    $s = $oc['summary'];
    return '<div class="fstages">'
         . '<div class="fstage"><div class="h">公開モデル</div><b>' . (int)$s['hf_models'] . '件</b><span>' . (int)$s['orgs_with_models'] . '採択先。Hugging Face の累計ダウンロード ' . g_flow_oc_n($s['downloads_all']) . '</span></div>'
         . '<div class="fstage"><div class="h">他の開発者による派生</div><b>' . g_flow_oc_n($s['derived']) . '件</b><span>公開モデルを元（base_model）に指定した Hugging Face 上のモデルの数の合計（1モデルあたり1,000件まで数えた）</span></div>'
         . '<div class="fstage"><div class="h">データセット</div><b>' . (int)$s['hf_datasets'] . '件</b><span>Hugging Face で公開された学習用・評価用データセット</span></div>'
         . '<div class="fstage"><div class="h">論文</div><b>' . (int)$s['orgs_with_grant_papers'] . '採択先</b><span>助成番号つき論文に著者の所属が出る採択先。機関のAI論文数は ' . (int)$s['orgs_with_inst'] . '採択先で取得</span></div>'
         . '<div class="fstage"><div class="h">ベンチマーク</div><b>' . (int)$s['orgs_with_bench'] . '採択先</b><span>公表資料にベンチマークの値があるもの</span></div>'
         . '<div class="fstage"><div class="h">配分額</div><b>確認できず</b><span>採択先ごとの金額は公表資料では確認できなかった</span></div>'
         . '</div>';
}

function g_flow_oc_card(string $slug, array $c, bool $open = false): string
{
    $h = $c['has'];
    $progs = [];
    foreach ($c['awards'] as $a) { $progs[$a['program_id']] = $a; }
    $sum = [];
    $sum[] = '公開モデル ' . (int)$h['models'];
    if ($h['datasets']) { $sum[] = 'データセット ' . (int)$h['datasets']; }
    $dl = array_sum(array_map(fn($r) => (int)($r['downloads_all'] ?? 0), $c['hf_models']));
    if ($dl) { $sum[] = '累計DL ' . number_format($dl); }
    $sum[] = '助成番号つき論文 ' . (int)$h['papers_grant'];
    if ($c['benchmarks']) { $sum[] = 'ベンチマーク ' . count($c['benchmarks']); }
    $q = mb_strtolower($c['name'] . ' ' . implode(' ', array_map(fn($a) => $a['theme'] . ' ' . $a['program_id'], $c['awards'])) . ' ' . implode(' ', array_map(fn($r) => $r['id'], $c['hf_models'])));
    $o = '<details class="fd foc" id="o-' . g_e(substr(md5($c['key']), 0, 10)) . '" data-q="' . g_e($q) . '"' . ($open ? ' open' : '') . '><summary><b>' . g_e($c['name']) . '</b><br><span class="note">' . g_e(implode('・', $sum)) . '</span></summary>';
    // 採択
    $o .= '<p class="foch">採択された公募</p><ul class="flist">';
    foreach ($c['awards'] as $a) {
        $o .= '<li>' . g_e((string)$a['program']) . ' <span class="note">（' . g_e((string)$a['agency']) . '・採択 ' . g_e((string)$a['award_date']) . ($a['role'] ? '・' . g_e($a['role']) : '') . '）</span><br><span class="note">' . g_e((string)$a['theme']) . '</span></li>';
    }
    $o .= '</ul>';
    // 中間評価の進捗表
    if (!empty($c['midterm'])) {
        $o .= '<p class="foch">経済産業省の中間評価報告書（案）の進捗表の「結果/状況」欄の記載</p><ul class="flist">';
        foreach ($c['midterm'] as $m) { $o .= '<li>「' . g_e((string)$m['status']) . '」' . ($m['end'] ? '　' . g_e((string)$m['end']) : '') . '<br><span class="note">' . g_e((string)$m['theme']) . '（PDF ' . (int)$m['page'] . 'ページ）</span></li>'; }
        $o .= '</ul>';
    }
    // 公開モデル
    $o .= '<p class="foch">公開モデル（Hugging Face）</p>';
    if ($c['hf_models']) {
        $o .= '<ul class="flist">';
        foreach ($c['hf_models'] as $r) {
            if (!empty($r['status'])) { $o .= '<li>' . g_e($r['id']) . '：' . g_e($r['status']) . '</li>'; continue; }
            $o .= '<li><a href="' . g_e($r['url']) . '" rel="noopener" target="_blank">' . g_e($r['id']) . '</a><br><span class="note">累計ダウンロード ' . g_flow_oc_n($r['downloads_all'] ?? null) . '（直近30日 ' . g_flow_oc_n($r['downloads_30d'] ?? null) . '）・いいね ' . g_flow_oc_n($r['likes'] ?? null)
               . '・公開 ' . g_e((string)($r['created'] ?? '')) . '・ライセンス ' . g_e((string)($r['license'] ?? '記載なし'))
               . '<br>このモデルを元にしたモデル ' . ((int)($r['derived'] ?? 0) >= 1000 ? '1,000件以上（API の上限まで数えた）' : g_flow_oc_n($r['derived'] ?? null) . '件（うち他のアカウント ' . g_flow_oc_n($r['derived_other_authors'] ?? null) . '件）')
               . '<br>根拠：' . g_e(($r['basis']['type'] ?? '') === 'meti' ? '経済産業省の成果公開ページに掲載（' . ($r['basis']['item'] ?? '') . '）' : (string)($r['basis']['note'] ?? '')) . '</span></li>';
        }
        $o .= '</ul>';
    } else {
        $o .= '<p class="note" style="margin:0">' . g_e((string)($c['hf_account_note'] ?: '経済産業省の成果公開ページと、確かめた公式発表に、この採択先の Hugging Face 上の公開モデルは載っていなかった（未取得）')) . '</p>';
    }
    if ($c['hf_datasets']) {
        $o .= '<p class="foch">公開データセット（Hugging Face）</p><ul class="flist">';
        foreach ($c['hf_datasets'] as $r) {
            if (!empty($r['status'])) { $o .= '<li>' . g_e($r['id']) . '：' . g_e($r['status']) . '</li>'; continue; }
            $o .= '<li><a href="' . g_e($r['url']) . '" rel="noopener" target="_blank">' . g_e($r['id']) . '</a><br><span class="note">累計ダウンロード ' . g_flow_oc_n($r['downloads_all'] ?? null) . '・いいね ' . g_flow_oc_n($r['likes'] ?? null) . '・公開 ' . g_e((string)($r['created'] ?? '')) . '・ライセンス ' . g_e((string)($r['license'] ?? '記載なし')) . '</span></li>';
        }
        $o .= '</ul>';
    }
    if ($c['other_outputs']) {
        $o .= '<p class="foch">経済産業省の成果公開ページにある、Hugging Face 以外の成果物</p><ul class="flist">';
        foreach ($c['other_outputs'] as $x) {
            $o .= '<li>' . g_e($x['name']) . ' <span class="note">（' . g_e($x['section']) . '）</span><br><span class="note">公開形態：' . g_e(mb_substr((string)$x['license'], 0, 80)) . '</span>'
                . ($x['links'] ? '<br><a href="' . g_e($x['links'][0]) . '" rel="noopener" target="_blank" style="overflow-wrap:anywhere">' . g_e(mb_substr($x['links'][0], 0, 70)) . '</a>' : '') . '</li>';
        }
        $o .= '</ul>';
    }
    if ($c['hf_models'] || $c['hf_datasets']) {
        $o .= '<p class="note fsrc">出典：Hugging Face Hub 公開API（ダウンロード数・いいね・公開日・ライセンス・base_model）。取得 ' . g_e((string)($c['hf_models'][0]['fetched'] ?? $c['hf_datasets'][0]['fetched'] ?? '')) . '。ダウンロード数は Hugging Face の集計で、利用者の数ではありません。</p>';
    }
    // ベンチマーク
    $o .= '<p class="foch">ベンチマーク（公表された値）</p>';
    if ($c['benchmarks']) {
        $o .= '<ul class="flist">';
        foreach ($c['benchmarks'] as $b) {
            if (($b['kind'] ?? '') === 'table') {
                $o .= '<li><b>' . g_e($b['model']) . '</b>：' . g_e(implode('・', array_map(fn($v) => $v[0] . ' ' . $v[1], $b['values']))) . '<br><span class="note">' . g_e((string)$b['as_of']) . ($b['note'] ? '。' . g_e($b['note']) : '') . '</span>' . g_flow_src((string)$b['source_title'], (string)$b['source_url']) . '</li>';
            } else {
                $o .= '<li><b>' . g_e($b['model']) . '</b><blockquote class="fq">' . g_e($b['text']) . '</blockquote>' . g_flow_src((string)$b['source_title'], (string)$b['source_url']) . '</li>';
            }
        }
        $o .= '</ul>';
    } else {
        $o .= '<p class="note" style="margin:0">未取得（出典と時点が確かな公表値が見つからなかった）</p>';
    }
    // 論文
    $o .= '<p class="foch">論文（OpenAlex）</p>';
    if ($c['papers_grant']) {
        $o .= '<p style="margin:0">助成番号つきの論文で著者の所属にこの採択先が出るもの <b>' . count($c['papers_grant']) . '本</b>（被引用の合計 ' . number_format(array_sum(array_map(fn($p) => (int)($p['cited'] ?? 0), $c['papers_grant']))) . '）</p><ul class="flist">';
        foreach (array_slice($c['papers_grant'], 0, 5) as $p) {
            $o .= '<li><a href="' . g_e((string)$p['url']) . '" rel="noopener" target="_blank">' . g_e((string)$p['title']) . '</a><br><span class="note">' . g_e((string)$p['date']) . '・被引用 ' . (int)$p['cited'] . '・助成番号 ' . g_e((string)$p['award_id']) . '</span></li>';
        }
        $o .= '</ul>';
    } else {
        $o .= '<p class="note" style="margin:0">助成番号つきの論文で、著者の所属にこの採択先が出るものは見つからなかった' . (empty($c['institution']['openalex']) ? '（OpenAlex の機関IDが見つからない採択先）' : '') . '</p>';
    }
    $pi = $c['papers_inst'] ?? null;
    if ($pi && $pi['count'] !== null) {
        $o .= '<p style="margin:6px 0 0">機関全体のAI分野の論文（' . g_e((string)$pi['since']) . '以降）：<b>' . number_format((int)$pi['count']) . '本</b>・被引用の合計 '
            . ($pi['cited_sum'] !== null ? number_format((int)$pi['cited_sum']) : number_format((int)$pi['cited_sum_partial']) . '（' . number_format((int)$pi['counted']) . '本分まで）') . '<br><span class="note">この採択の成果とは限りません（機関「' . g_e((string)($c['institution']['display_name'] ?? '')) . '」の所属が出る論文すべて）。</span></p>';
        if ($pi['top']) {
            $o .= '<ul class="flist">';
            foreach ($pi['top'] as $p) { $o .= '<li><a href="' . g_e((string)$p['url']) . '" rel="noopener" target="_blank">' . g_e((string)$p['title']) . '</a> <span class="note">' . g_e((string)$p['date']) . '・被引用 ' . (int)$p['cited'] . '</span></li>'; }
            $o .= '</ul>';
        }
    }
    if ($c['papers_grant'] || ($pi && $pi['count'] !== null)) {
        $o .= '<p class="note fsrc">出典：OpenAlex API（論文の謝辞から拾った助成番号・著者の所属・被引用数）、機関の対応は ROR API。取得 ' . g_e((string)($pi['fetched'] ?? '')) . '</p>';
    }
    // 配分額
    $o .= '<p class="foch">配分額</p><p style="margin:0">' . g_e((string)$c['amount']) . '</p>';
    return $o . '</details>';
}

/** 分野ページ・事業ページに出す「採択先の成果」の節 */
function g_flow_oc_section(string $slug, array $d, ?array $keys = null, string $h = 'h2'): string
{
    $oc = $d['outcomes'] ?? null;
    if (!$oc) { return ''; }
    $cards = $oc['cards'];
    if ($keys !== null) { $cards = array_values(array_filter($cards, fn($c) => in_array($c['key'], $keys, true))); }
    if (!$cards) { return ''; }
    $o = '<' . $h . ' id="outcomes" data-en="Outcomes">採択先の成果：公開モデル・論文・ベンチマーク</' . $h . '>' . g_flow_oc_intro($oc);
    if ($keys === null) { $o .= g_flow_oc_stats($oc); }
    $withAny = array_values(array_filter($cards, fn($c) => $c['has']['models'] || $c['has']['datasets'] || $c['has']['papers_grant'] || $c['benchmarks']));
    $o .= '<p>' . count($cards) . '採択先のうち、公開モデル・データセット・助成番号つき論文・ベンチマークのいずれかが確かめられたのは <b>' . count($withAny) . '採択先</b>です。上から、公開物の多い順（順位ではなく並べ方）。</p>';
    foreach (array_slice($withAny, 0, $keys === null ? 6 : 12) as $c) { $o .= g_flow_oc_card($slug, $c); }
    $o .= '<p class="more"><a class="btn" href="' . g_url('flow/' . $slug . '/outcomes') . '">採択先' . count($oc['cards']) . 'の成果をすべて見る</a></p>';
    return $o;
}

function g_flow_oc_css(): string
{
    return '<style>.foch{margin:10px 0 2px;font-weight:900;color:var(--teal-d);font-size:14px}details.foc summary{line-height:1.6}details.foc{overflow-wrap:anywhere}</style>';
}

// ---------------------------------------------------------------- /flow/<分野>/outcomes
function g_page_flow_outcomes(string $slug): void
{
    $d = g_flow_load($slug);
    if (!$d || empty($d['outcomes'])) { g_flow_404($slug); return; }
    $oc = $d['outcomes']; $s = $oc['summary']; $f = $d['field'];
    $title = 'AI政策の採択先の成果｜公開モデル・論文・ベンチマーク';
    $desc = 'NEDO・JST・NICT の公募の採択先' . (int)$s['orgs'] . 'について、Hugging Face の公開モデル' . (int)$s['hf_models'] . '件（累計ダウンロード ' . number_format((int)$s['downloads_all']) . '）・助成番号つきの論文・公表されたベンチマークの値を、出典つきで採択先ごとに並べます。';
    g_head($title, $desc, '/flow/' . $slug . '/outcomes', ['nosuffix' => true, 'image' => g_flow_og($slug), 'jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], [G_FLOW_NAME, '/flow'], [$f['name'], '/flow/' . $slug], ['採択先の成果', '/flow/' . $slug . '/outcomes']]),
        ['@type' => 'Dataset', 'name' => $title, 'description' => $desc, 'url' => g_abs('flow/' . $slug . '/outcomes'), 'inLanguage' => 'ja', 'dateModified' => $oc['generated_at'],
         'creator' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ'], 'isBasedOn' => ['https://geniac.meti.go.jp/', 'https://huggingface.co/', 'https://openalex.org/']]])]);
    echo g_flow_css() . g_flow_oc_css();
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › <a href="' . g_url('flow') . '">' . g_e(G_FLOW_NAME) . '</a> › <a href="' . g_url('flow/' . $slug) . '">' . g_e($f['name']) . '</a> › 採択先の成果</nav>'
       . '<p class="kick">Policy Flow Tracker</p><h1>' . g_e($title) . '</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p></section>';
    echo g_share_bar('【' . G_FLOW_NAME . '】AI政策の採択先' . (int)$s['orgs'] . 'の成果（公開モデル' . (int)$s['hf_models'] . '件・論文・ベンチマーク）を出典つきで並べる', 'flow/' . $slug . '/outcomes', 'flow-' . $slug . '-outcomes');
    echo g_flow_oc_intro($oc) . g_flow_oc_stats($oc);
    // 助成番号の論文の全体
    if (!empty($oc['grants'])) {
        echo '<h2 data-en="Papers by grant">助成番号つきの論文（OpenAlex）</h2><ul class="flist">';
        foreach ($oc['grants'] as $g) {
            echo '<li><b>' . g_e((string)($g['label'] ?? $g['award_id'])) . '</b><br><span class="note">謝辞に「' . g_e(str_replace('|', '」「', (string)$g['award_id'])) . '」がある論文 ' . (int)$g['works'] . '本（被引用の合計 ' . number_format((int)$g['cited_sum']) . '）。うち著者の所属に採択先が出る論文 ' . (int)$g['matched'] . '本'
               . (!empty($g['note']) ? '。' . g_e($g['note']) : '') . '</span>' . g_flow_src('番号の根拠', (string)($g['basis_url'] ?? '')) . '</li>';
        }
        echo '</ul>';
        foreach ((array)$oc['notes'] as $n) { echo '<p class="note">' . g_e($n) . '</p>'; }
    }
    echo '<h2 data-en="By awardee">採択先ごと（' . count($oc['cards']) . '）</h2><p><input id="fq" type="search" placeholder="採択先・テーマ・モデル名で探す（例：音声、PLaMo、GENIAC 第3期は geniac-3）" autocomplete="off"><br><span class="note" id="fqn"></span></p><div id="fall">';
    foreach ($oc['cards'] as $c) { echo g_flow_oc_card($slug, $c); }
    echo '</div><script>(function(){var q=document.getElementById("fq"),n=document.getElementById("fqn"),cs=[].slice.call(document.querySelectorAll("#fall>details"));'
       . 'function f(){var v=q.value.trim().toLowerCase().split(/[\\s　、,]+/).filter(Boolean),k=0;cs.forEach(function(c){var ok=v.every(function(w){return c.getAttribute("data-q").indexOf(w)>=0;});c.style.display=ok?"":"none";if(ok)k++;});n.textContent=v.length?k+"件":"";}'
       . 'q.addEventListener("input",f);})();</script>';
    echo '<h2 data-en="Sources">成果の出典と集め方</h2><div class="panel"><ul style="margin:0;padding-left:18px">';
    foreach ($oc['sources']['meti_pages'] as $p) { echo '<li><a href="' . g_e($p['url']) . '" rel="noopener" target="_blank">' . g_e($p['title']) . '</a>（成果物の一覧・公開リンク・「特徴」欄の記載）</li>'; }
    echo '<li><a href="' . g_e($oc['sources']['midterm']['url']) . '" rel="noopener" target="_blank">' . g_e($oc['sources']['midterm']['title']) . '</a></li>'
       . '<li>' . g_e($oc['sources']['hf_api']) . '。Hugging Face のアカウントは、経済産業省の成果公開ページか、採択先の公式発表に載っているものだけを使い、そのアカウントのモデルは「経済産業省のページに載っているもの」と「モデルカードに GENIAC の記載があるもの」に限っています。</li>'
       . '<li>' . g_e($oc['sources']['openalex']) . '。機関のAI論文は ' . g_e($oc['sources']['ai_subfields']) . '。</li>'
       . '<li>ベンチマークは、経済産業省の第1期の性能評価の表（画像から転記）と、採択先の発表、経済産業省の成果公開ページの「特徴」欄にある値（ベンチマーク名と数値の両方がある文だけ）です。当社はスコアを測っていません。</li>'
       . '<li>関連：<a href="https://exbridge.jp/academic/theme/seisei-ai-gyosei-dx.html#ref=flow-outcomes">Kurage 論文トラッカー「生成AIと行政DX」</a>・<a href="' . g_url('tracker/ai-hou') . '">国会トラッカー「AI法」</a></li>'
       . '<li>取得 ' . g_e((string)$oc['generated_at']) . '。誤りに気づいたら <a href="https://exbridge.jp/contact.php#ref=flow-fix">お問い合わせ</a> からお知らせください。</li></ul></div>';
    echo '<p class="more"><a class="btn o" href="' . g_url('flow/' . $slug) . '">' . g_e($f['title']) . 'へ戻る</a></p>';
    g_foot();
}
