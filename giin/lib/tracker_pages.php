<?php
/**
 * 国会トラッカーの画面（一覧とテーマ1つのページ）。
 *
 * 議員発言ログ（index.php）と、Kurage 国会トラッカー（tracker_index.php）の両方から使う。
 * 議員発言ログにしかない部品（ことがら・法案・右の列）は、あるときだけ使う。
 */
declare(strict_types=1);


/** トラッカーの一覧。ことがら一覧と違い、**全国の発言**を追っているものだけ。 */
function g_page_trackers(): void
{
    $n = count(g_trackers());
    // 例に出すテーマ: 当社の公開先は検索で狙う3つ、単体の設置では trackers.json の先頭3つ
    $ex = G_APP === 'giin' ? '年収の壁、不登校、消費税減税'
        : implode('、', array_slice(array_map(fn($t) => (string)($t['short'] ?? $t['name']), g_trackers()), 0, 3));
    $desc = $ex . 'など' . $n . 'のテーマについて、全国の国会議員の質疑と政府の答弁を国会会議録から集め、'
          . 'だれが質問し、政府が何と答えたかを日付順に並べています。テーマの名前や言葉で国会答弁を探せます。';
    // 一覧は「国会答弁 検索」260・「国会 答弁」2,400・「国会 質疑」480 の受け皿にする（2026-10-13）。
    // 以前の「国会議事録から、法案のいまを追う」は検索されない言い方だった。
    g_head('国会答弁の検索｜' . $n . 'テーマの質疑と政府答弁を探す', $desc, '/tracker', ['jsonld' => g_jsonld([
        g_crumbs([['ホーム', '/'], ['国会答弁の検索', '/tracker']])]), 'nosuffix' => true]);
    $rows = []; $sq = 0; $sg = 0;
    foreach (g_trackers() as $t) {
        $st = g_tracker_stats($t['key']);
        $rows[] = [$t, $st];
        if ($st) { $sq += (int)$st['q']; $sg += (int)$st['gov']; }
    }
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › 国会答弁の検索</nav>'
       . '<p class="kick">Diet Tracker</p><h1>国会答弁の検索</h1><p class="lead" style="margin-bottom:0">' . g_e($desc) . '</p>'
       . '<div class="kv">'
       . '<div class="c"><b>' . count($rows) . '</b><span>テーマ</span></div>'
       . '<div class="c"><b>' . number_format($sq) . '</b><span>全国の議員の質疑</span></div>'
       . '<div class="c"><b>' . number_format($sg) . '</b><span>政府の答弁</span></div>'
       . '</div>'
       . '<p style="margin:14px 0 0"><label for="tq" style="font-weight:700">テーマを探す</label><br>'
       . '<input id="tq" type="search" placeholder="例：年収の壁、不登校、介護、防災" autocomplete="off" '
       . 'style="width:100%;max-width:520px;box-sizing:border-box;font-size:16px;padding:10px 12px;border:1px solid #c9d3dc;border-radius:8px;margin-top:6px">'
       . '<br><span class="note" id="tqn"></span></p></section>';
    // 読んだ人が自分で広められるように（2026-10-23 ユーザー指摘「コピーボタン、X共有ボタンは？」）
    echo g_share_bar('Kurage 国会トラッカー｜' . $n . 'のテーマで、全国の国会議員の質疑' . number_format($sq) . '件と政府の答弁' . number_format($sg) . '件を追う', 'tracker', 'trackers');
    echo '<div class="grid" id="tgrid">';
    foreach ($rows as [$t, $st]) {
        $hay = $t['name'] . ' ' . ($t['short'] ?? '') . ' ' . ($t['seo_word'] ?? '') . ' ' . implode(' ', (array)($t['words'] ?? []));
        echo '<a class="card" data-q="' . g_e(mb_strtolower($hay)) . '" href="' . g_url('tracker/' . $t['key']) . '">'
           . '<div class="nm">' . g_e($t['name']) . '</div>'
           . '<div class="n">' . ($st ? '質疑 ' . number_format((int)$st['q']) . '件・答弁 ' . number_format((int)$st['gov']) . '件<br><span class="note">'
                                  . g_e(g_date($st['last'])) . 'まで</span>' : '準備中') . '</div></a>';
    }
    echo '</div>';
    echo '<script>(function(){var q=document.getElementById("tq"),n=document.getElementById("tqn"),cs=[].slice.call(document.querySelectorAll("#tgrid .card"));'
       . 'function f(){var v=q.value.trim().toLowerCase().split(/[\\s　、,]+/).filter(Boolean),k=0;'
       . 'cs.forEach(function(c){var ok=v.every(function(w){return c.getAttribute("data-q").indexOf(w)>=0;});c.style.display=ok?"":"none";if(ok)k++;});'
       . 'n.textContent=v.length?k+"件のテーマ":"";}q.addEventListener("input",f);})();</script>';
    g_foot();
}

/** ひとつのことがらの全国トラッカー。
 *  **愛知の46人の発言だけでは「法整備はどこまで来たか」が分からない**ので、
 *  この画面だけは会議録の全発言（質疑と政府の答弁）を集めて出す。
 *  要約はしない。政府の答弁も質疑も、語を含む文をそのまま抜いて会議録へつなぐ。 */
function g_page_tracker(string $key, int $page): void
{
    $t = g_tracker($key);
    $st = $t ? g_tracker_stats($key) : [];
    if (!$t || !$st) { http_response_code(404); g_head('見つかりません', '', '/tracker', ['noindex' => true]);
        echo '<h1>そのトラッカーはありません</h1>'; g_foot(); return; }
    $words = $t['words'];
    $theme = !empty($t['theme']) && function_exists('g_theme') ? g_theme($t['theme']) : null;   // ことがらは議員発言ログだけ
    $kind = (string)($_GET['k'] ?? 'all');
    if (!in_array($kind, ['all', 'q', 'gov', 'ref'], true)) { $kind = 'all'; }
    $years = g_tracker_years($key);
    $qs = g_tracker_speakers($key, 'q');
    $govs = g_tracker_speakers($key, 'gov');
    $latestGov = g_tracker_list($key, 'gov', 3);
    $latestQ = g_tracker_list($key, 'q', 3);
    $updated = g_meta('tracker_' . $key . '_at');
    // **題名の先頭は、実際に検索されている語にする。**
    // 「国会トラッカー」は月間0、「◯◯は国会でどこまで来たか」も誰も検索しない。
    // trackers.json の seo_word（キーワードプランナー実測・2026-09）を先に出す。
    // 例: 不登校74,000／年収の壁110,000／取適法60,500。
    // 90日で /tracker/ 個別ページは表示0だった（2026-09-25 GSC実測）。
    $w = $t['seo_word'] ?? $t['short'] ?? $t['name'];
    // 題名は「◯◯の国会答弁」で統一（2026-10-13）。
    // 「◯◯とは」「いつから」を先頭にした seo_title（2026-09-30）は、年収の壁110,000・不登校74,000 のような大きな語で
    // ニュースや省庁と競って100位にも入らず、トラッカーは90日で表示30回だった。
    // ニュースと競わない「◯◯ 国会」「◯◯ 答弁」側を取る（「国会 答弁」2,400・「国会 質疑」480）。
    $title = $w . 'の国会答弁｜だれが質問し、政府は何と答えたか';
    $desc = $t['name'] . 'について、全国の国会議員の質疑' . number_format((int)$st['q']) . '件と政府の答弁'
          . number_format((int)$st['gov']) . '件を国会会議録から集めました（' . g_date($st['first']) . '〜'
          . g_date($st['last']) . '）。だれが質問し、政府が何と答えたかを日付と会議録リンクで並べています。';
    $faq = g_tracker_faq($t, $st, $qs, $years, $latestGov);
    $ld = g_jsonld([
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], $faq)],
        g_crumbs([['ホーム', '/'], ['国会答弁の検索', '/tracker'], [$t['name'], '/tracker/' . $key]]),
        ['@type' => 'Article', '@id' => g_abs('tracker/' . $key), 'url' => g_abs('tracker/' . $key),
         'headline' => $title, 'description' => $desc, 'inLanguage' => 'ja',
         'keywords' => implode(', ', array_slice((array)($t['words'] ?? []), 0, 6)),
         'dateModified' => substr($updated, 0, 10),
         'about' => ['@type' => 'Thing', 'name' => $t['name']],
         'isPartOf' => ['@type' => 'WebSite', 'name' => G_SITE, 'url' => g_abs('')]],
    ]);
    g_head($title, $desc, '/tracker/' . $key, ['jsonld' => $ld, 'image' => g_abs(function_exists('g_tracker_png') ? 'tracker/' . $key . '.png' : 'img/og/tracker-' . $key . '.png'), 'nosuffix' => true]);

    // 右の列（CM枠と災害系デモ）。相談先を出す notice 付きのページ（自殺対策など）には出さない
    $side = empty($t['notice']);
    if ($side) { echo g_side_open('giin-tracker'); } else { $GLOBALS['g_noside'] = true; }   // 相談先を先に出すページは1列のまま
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('tracker') . '">国会トラッカー</a> › ' . g_e($t['name']) . '</nav>';
    echo '<p class="kick">Diet Tracker</p><h1>' . g_e($w . 'の国会答弁と質疑') . '</h1>'
       . '<p class="lead">' . g_e($t['lead']) . '</p>';
    // 自殺など、読む人の安全に関わることがらは、数字より先に相談先を出す（trackers.json の notice）
    if (!empty($t['notice'])) {
        $nl = is_array($t['links'] ?? null) && $t['links'] ? $t['links'][0] : null;
        echo '<div class="panel" style="border-color:#0a9a8f"><p style="margin:0">' . g_e($t['notice'])
           . ($nl ? '　<a href="' . g_e($nl['url']) . '" rel="noopener" target="_blank">' . g_e($nl['label']) . '</a>' : '') . '</p></div>';
    }
    echo '<div class="kv">'
       . '<div class="c"><b>' . number_format((int)$st['q']) . '</b><span>議員の質疑</span></div>'
       . '<div class="c"><b>' . number_format((int)$st['gov']) . '</b><span>政府の答弁</span></div>'
       . '<div class="c"><b>' . (int)$st['speakers'] . '</b><span>取り上げた<br>議員</span></div>'
       . '<div class="c"><b>' . g_e(substr((string)$st['last'], 0, 4)) . '</b><span>最新 ' . g_e(substr((string)$st['last'], 5)) . '<br>（最初 ' . g_e(substr((string)$st['first'], 0, 7)) . '）</span></div>'
       . '</div></section>';
    // 共有とコピー（2026-10-23）
    echo g_share_bar($w . 'の国会答弁｜質疑' . number_format((int)$st['q']) . '件・答弁' . number_format((int)$st['gov']) . '件・' . (int)$st['speakers'] . '人（Kurage 国会トラッカー）', 'tracker/' . $key, 'tracker-' . $key);
    // 答えを先に一文で（AI検索・強調スニペットが引用しやすい形）
    echo '<p class="answer" style="font-size:15.5px;line-height:1.9;margin:14px 0 6px"><strong>' . g_e($faq[0][0]) . '</strong> ' . g_e($faq[0][1]) . '</p>';
    // 当社が数えた要点（会議録の写しではない、このページだけの中身。2026-10-09）
    if ($pts = g_tracker_points($t, $st, $years, $qs, $latestGov)) {
        echo '<div class="panel"><h2 style="margin-top:0" data-en="Key facts">数字で見る要点</h2><ul>';
        foreach ($pts as $p) { echo '<li>' . g_e($p) . '</li>'; }
        echo '</ul></div>';
    }
    echo '<p class="note">集め方：「' . g_e(implode('」「', $words)) . '」を含む発言を、国立国会図書館の国会会議録検索システムから'
       . g_e(g_date($t['from'])) . '以降ぶん機械的に集めたものです' . (G_APP === 'giin' ? '（愛知の46人に限りません）' : '') . '。'
       . '立場（質疑・答弁）は発言の冒頭の話者表記から機械的に分けています。要約も賛否の判定もしていません。'
       . ($updated !== '' ? '最終取得 ' . g_e(substr($updated, 0, 16)) . '。' : '') . '</p>';

    // 省庁の新しい発表（Kurage 省庁トラッカー・2026-10-23）。kpayload の shocho-fetch.py が data/shocho_links.json に書く
    $__sl = @json_decode((string)@file_get_contents(__DIR__ . '/../data/shocho_links.json'), true);
    if (!empty($__sl['links'][$t['key']])) {
        echo '<section class="card" style="margin:16px 0;padding:14px 16px"><h2 style="margin:0 0 8px;font-size:18px">省庁の新しい発表</h2><ul style="margin:0;padding-left:18px">';
        foreach ($__sl['links'][$t['key']] as $__x) {
            echo '<li style="margin:4px 0"><a href="' . g_e($__x['page']) . '#ref=tracker-' . g_e($t['key']) . '-shocho">' . g_e($__x['title']) . '</a>'
               . ' <span class="note">' . g_e($__x['ministry']) . '・' . g_e(g_date((string)$__x['date'])) . '</span></li>';
        }
        echo '</ul><p class="note" style="margin:8px 0 0">各府省の新着情報を毎日取り込み、この話題に関係する発表を並べています（<a href="https://exbridge.jp/shocho/#ref=tracker-' . g_e($t['key']) . '-shocho">Kurage 省庁トラッカー</a>）。</p></section>';
    }
    echo g_sns_line('tracker-' . $t['key']);   // AI-SNS顧問への入口（2026-10-22）
    echo g_tracker_next($t);
    // この話題の語を件名に含む法案（審議の経過と、その法案に触れた質疑へ）
    if (function_exists('g_bills_for_words') && ($rb = g_bills_for_words((array)$words, 5))) {   // 法案は議員発言ログだけ
        echo '<h2 data-en="Bills">この話題に関係する法案</h2><div class="panel"><ul>';
        foreach ($rb as $b) {
            echo '<li><a href="' . g_url('bill/' . $b['id']) . '">' . g_e($b['title']) . '</a> '
               . g_bill_status_pill((string)$b['status']) . ' <span class="note">' . g_e(g_date($b['last_date'])) . '</span></li>';
        }
        echo '</ul></div>';
    }
    if ($side) { echo g_cm_sp('giin-tracker'); }   // スマホだけ: ここにCM枠（広い画面では右の列の上に出る）
    echo g_tracker_explain($t);

    // 合意点マップ（賛否を集めて、割れ方と一致点を出す）。いまは消費税減税だけ用意している
    if ($key === 'shohizei-genzei') {
        echo '<section class="box"><h2 data-en="Consensus">この論点は、どこで割れているか</h2>'
           . '<p>この会議録の発言と、ニュースに寄せられた反応から論点を立て、賛否を集めて'
           . '<b>意見グループ</b>と<b>どのグループから見ても賛成が高い点</b>を出しています。'
           . '要約でも賛否の判定でもなく、押された票から計算したものです。</p>'
           . '<p class="more"><a class="btn o" href="https://kurage.exbridge.jp/kconsensus.php/t/shohizei/?ref=giin-tracker" '
           . 'target="_blank" rel="noopener">合意点マップで見る</a></p></section>';
    }

    // ---- 政府の最新の答え ----
    if ($latestGov) {
        echo '<h2 data-en="Government">いま、政府は何と答えているか</h2>'
           . '<p>大臣・副大臣・政務官・政府参考人としての<b>直近の答弁</b>です。語を含む文をそのまま抜いています。'
           . '答弁は質問への答えなので、「この回の会議録」で質問とあわせて読んでください。</p>';
        foreach ($latestGov as $s) { g_tracker_speech($s, $words); }
    }
    if ($latestQ) {
        echo '<h2 data-en="Latest">直近の質疑</h2>';
        foreach ($latestQ as $s) { g_tracker_speech($s, $words); }
    }

    // ---- よくある質問（会議録のデータから機械的に作る） ----
    echo '<h2 data-en="FAQ">よくある質問</h2><div class="panel">';
    foreach ($faq as $x) { echo '<details style="margin:0 0 8px"><summary style="cursor:pointer;font-weight:700">' . g_e($x[0]) . '</summary><p style="margin:8px 0 4px">' . g_e($x[1]) . '</p></details>'; }
    echo '</div>';

    // ---- 論点（人が書いた枠。数字は入れない） ----
    if (!empty($t['questions'])) {
        echo '<h2>会議録に繰り返し出てくる論点</h2><div class="panel"><ul>';
        foreach ($t['questions'] as $qq) { echo '<li>' . g_e($qq) . '</li>'; }
        echo '</ul><p class="note">当サイトが会議録を読んで整理した問いです。どの立場が正しいかは判定していません。</p></div>';
    }

    // ---- 年ごと ----
    if ($years) {
        $max = max(array_map(fn($r) => (int)$r['n'], $years));
        $lastY = (int)$years[count($years) - 1]['y'];
        echo '<h2>年ごとの件数</h2><div class="scroll"><table><tr><th>年</th><th class="n">質疑</th><th class="n">答弁</th><th></th></tr>';
        foreach ($years as $r) {
            echo '<tr><td>' . g_e($r['y']) . '年'
               . ((int)$r['y'] === $lastY ? ' <span class="note">' . g_e(substr((string)$st['last'], 5, 2)) . '月まで</span>' : '')
               . '</td><td class="n">' . (int)$r['q'] . '</td><td class="n">' . (int)$r['gov'] . '</td>'
               . '<td><div class="bar"><i style="width:' . round((int)$r['n'] / max(1, $max) * 100) . '%"></i></div></td></tr>';
        }
        echo '</table></div><p class="note">最後の年は年の途中までの数字なので、前の年とそのまま比べられません。'
           . '国会の会期や委員会の開かれ方でも件数は変わります。</p>';
    }

    // ---- だれが取り上げているか ----
    if ($qs) {
        echo '<h2 data-en="Who">だれが国会で取り上げているか</h2>'
           . '<p><b>「日数」で並べています。</b>同じ日の質疑で何度言っても1日です。別の日、別の委員会で繰り返し'
           . '持ち出しているなら、続けて取り組んでいる印になります。名前は会議録の表記のままです。</p>'
           . '<div class="scroll"><table><tr><th>議員</th><th>会派（会議録の表記）</th><th class="n">日数</th><th class="n">件数</th>'
           . '<th class="n">会議の種類</th><th>最後に触れた日</th></tr>';
        foreach ($qs as $r) {
            // 名前 → この議員がこのテーマで何をどれだけ質問したか（カードつき）。愛知の46人は議員ページも
            echo '<tr><td>' . (function_exists('g_tm_id')
                    ? '<a href="' . g_url('tracker/' . $key . '/m/' . g_tm_id((string)$r['speaker'])) . '" rel="nofollow">' . g_e($r['speaker']) . '</a>'
                    : g_e($r['speaker']))
               . (!empty($r['slug']) ? ' <a class="pill" href="' . g_url($r['slug']) . '">愛知</a>'
                  : (!empty($r['gslug']) ? ' <a class="pill gray" href="' . g_url($r['gslug']) . '">議員ページ</a>' : ''))
               . '<br><span class="note">' . g_e($r['house']) . '</span></td>'
               . '<td><span class="note">' . g_e($r['kaiha'] ?? '') . '</span></td>'
               . '<td class="n"><b>' . (int)$r['days'] . '</b></td><td class="n">' . (int)$r['n'] . '</td>'
               . '<td class="n">' . (int)$r['meetings'] . '</td><td class="note">' . g_e($r['last']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    $refs = g_tracker_speakers($key, 'ref');
    if ($refs) {
        echo '<h2>参考人として意見を述べた人</h2><p>委員会に呼ばれて意見を述べた<b>議員ではない人</b>です（会議録の表記のまま）。'
           . '現場の医療機関や研究者の声はここに出ます。</p>'
           . '<div class="scroll"><table><tr><th>名前</th><th>肩書（会議録の表記）</th><th class="n">日数</th><th class="n">件数</th><th>日付</th></tr>';
        foreach ($refs as $r) {
            echo '<tr><td>' . g_e($r['speaker']) . '</td><td><span class="note">' . g_e($r['position'] ?? '') . '</span></td>'
               . '<td class="n">' . (int)$r['days'] . '</td><td class="n">' . (int)$r['n'] . '</td><td class="note">' . g_e($r['last']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    if ($govs) {
        echo '<h2>答えた側</h2><p>大臣・副大臣・政務官・政府参考人として<b>答えた側</b>です。質問する側とは立場が違うので別に出しています。</p>'
           . '<div class="scroll"><table><tr><th>名前</th><th>役職（最後の答弁時）</th><th class="n">日数</th><th class="n">件数</th></tr>';
        foreach ($govs as $r) {
            echo '<tr><td>' . g_e($r['speaker']) . '</td><td><span class="note">' . g_e($r['position'] ?? '') . '</span></td>'
               . '<td class="n">' . (int)$r['days'] . '</td><td class="n">' . (int)$r['n'] . '</td></tr>';
        }
        echo '</table></div>';
    }

    // 議員発言ログ（愛知の46人）と一緒に置いたときだけ。国会トラッカー単体では名簿を持たない
    if (G_APP === 'giin') {
        // ---- 愛知の議員は ----
        $aichi = array_values(array_filter($qs, fn($r) => !empty($r['slug'])));
        echo '<h2>愛知の議員は</h2><div class="panel">';
        if ($aichi) {
            echo '<p>愛知の有権者の一票が当落に効く46人のうち、' . g_e($t['short'] ?? $t['name']) . 'を国会で取り上げているのは次の議員です。</p><ul>';
            foreach ($aichi as $r) {
                echo '<li><a href="' . g_url($r['slug']) . '">' . g_e($r['plain']) . '</a>（' . g_e($r['house']) . '）　'
                   . (int)$r['n'] . '件・' . (int)$r['days'] . '日、最後は' . g_e(g_date($r['last']))
                   . ($theme ? '　<a href="' . g_url('theme/' . $theme['slug']) . '?g=' . $r['slug'] . '">発言を読む</a>' : '') . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p>愛知の46人には、このことがらの質疑がまだありません。</p>';
        }
        if ($theme) {
            echo '<p class="note">愛知の46人の発言は<a href="' . g_url('theme/' . $theme['slug']) . '">ことがら「'
               . g_e($theme['name']) . '」</a>にまとめています（こちらは' . g_e(g_meta('range_from')) . '以降）。</p>';
        }
        echo '</div>';
    }
    // 公的な案内。**当サイトで一覧を作り直さない。** 国が事業者一覧・相談窓口・制度説明を出しているものはそこへ渡す
    if (!empty($t['links'])) {
        echo '<h2>制度そのものを調べるなら</h2><div class="panel"><ul>';
        foreach ($t['links'] as $lk) {
            echo '<li><a href="' . g_e($lk['url']) . '" rel="noopener" target="_blank">' . g_e($lk['label']) . '</a></li>';
        }
        echo '</ul><p class="note">事業者の一覧や相談窓口は国が公表しているものが最新です。当サイトは会議録の側だけを扱います。</p></div>';
    }
    if ($theme) {
        echo g_stats_html(g_stats($theme['slug'], $key), 'このことがらに関わる、国や自治体が公表している数字です。当サイトが公表資料から書き写したもので、要約も推計もしていません。');
    }

    // ---- 発言のあゆみ ----
    $per = 20; $off = ($page - 1) * $per;
    $total = g_tracker_count($key, $kind === 'all' ? '' : $kind);
    $base = g_url('tracker/' . $key);
    echo '<h2 data-en="Timeline">発言のあゆみ</h2><p class="lead">';
    foreach (['all' => '全部', 'q' => '質疑', 'gov' => '答弁', 'ref' => '参考人'] as $k => $label) {
        $c = g_tracker_count($key, $k === 'all' ? '' : $k);
        if ($c === 0 && $k !== 'all') { continue; }
        echo '<a class="pill' . ($k === $kind ? '' : ' gray') . '" href="' . g_e($base . '?k=' . $k) . '#ayumi">' . $label . ' ' . number_format($c) . '</a> ';
    }
    echo '<span class="note">新しい順</span></p><div id="ayumi"></div>';
    foreach (g_tracker_list($key, $kind === 'all' ? '' : $kind, $per, $off) as $s) { g_tracker_speech($s, $words); }
    g_pager($page, $total, $per, $base . '?k=' . $kind);

    echo '<h2>この数字の読み方</h2>'
       . '<div class="panel note">数えているのは会議録に語が出てきた発言の数で、賛成・反対は判定していません。'
       . '質疑ができるのはその日その委員会で質問に立った議員だけなので、<b>件数の差はそのまま熱心さの差ではありません。</b>'
       . '会議録は国会の会議から数日〜数週間後に公開されるため、直近の審議はまだ載っていないことがあります。'
       . '<br><a href="' . g_url('about') . '">この道具の作り方</a></div>';

    // このことがらを歌にした PV（Kurage 動画ページの再生窓）。**会議録とは別物**なので一番下に置き、断りを添える
    $v = is_array($t['video'] ?? null) ? $t['video'] : null;
    if ($v && preg_match('/^[a-z0-9]{8,32}$/', (string)($v['job_id'] ?? ''))) {
        $kv = 'https://kurage.exbridge.jp/kuragev.php?id=' . $v['job_id'];
        echo '<h2>この問題を歌にしました</h2><div class="panel">'
           . '<p><b>' . g_e($v['title'] ?? '') . '</b></p>'
           . '<div style="position:relative;width:100%;max-width:960px;aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden">'
           . '<iframe src="' . g_e($kv) . '&embed=1" title="' . g_e($v['title'] ?? '') . '" loading="lazy" allowfullscreen '
           . 'style="position:absolute;inset:0;width:100%;height:100%;border:0"></iframe></div>'
           . '<p class="note">' . g_e($v['note'] ?? '') . '　<a href="' . g_e($kv) . '" target="_blank" rel="noopener">歌詞つきの動画ページで見る</a></p></div>';
    }
    if ($side) { echo g_side_close(); }
    g_foot();
}
