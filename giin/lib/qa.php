<?php
/**
 * 質疑1件のページとカード画像（/tracker/<key>/<発言ID> と同じパスの .png）。
 *
 * **なぜ作ったか。** トラッカーのURLをXの返信に貼ると、OGP画像が見られて評価された
 * （内密出産の質疑を伊藤たかえ議員が喜んでくれた・2026-10-14）。ことがら全体のカードより、
 * 「その議員が何を聞き、政府が何と答えたか」を1枚にしたほうが、本人にも読む人にも届く。
 *
 * 答弁は「質問の直後の政府側の発言」。トラッカーの語を含まない答弁は表に入っていないので、
 * そのときだけ会議録APIで直後の発言を最大3件読んで、手元に置く（1回読んだら二度と読まない）。
 * 委員長の指名（「○○局長。」）は飛ばす。先に議員の発言が来たら、答弁なしとして扱う。
 *
 * **要約しない。** 出すのは語を含む文の抜粋と、答弁の冒頭の文だけ（サイト全体の約束と同じ）。
 * 発言は3万件あるので、ページは noindex（検索の評価をトラッカーのページに集める）。
 */
declare(strict_types=1);

require_once __DIR__ . '/card.php';

function g_qa_cache_dir(): string { return g_card_cache_dir('qa'); }

/** 発言の立場（scripts/classify.py の kind_of と同じ規則） */
function g_qa_kind(string $body): string
{
    $head = preg_match('/^[○◯●]\s*([^\s　]{1,40})/u', $body, $m) ? $m[1] : mb_substr($body, 0, 24);
    $rules = [
        'chair' => ['委員長代理', '委員長', '主査代理', '主査', '副議長', '議長', '分科会長'],
        'gov'   => ['国務大臣', '大臣政務官', '副大臣', '政府参考人', '政府特別補佐人', '大臣', '長官', '本部長', '副本部長'],
        'q'     => ['委員', '分科員', '議員', '君', '公述人', '参考人'],
    ];
    foreach ($rules as $k => $ws) { foreach ($ws as $w) { if (mb_strpos($head, $w) !== false) { return $k; } } }
    return 'other';
}

/** 会議録APIで発言1件（発言ID = issueID_番号）。読めなければ null */
function g_qa_ndl(string $issue, int $no): ?array
{
    $url = 'https://kokkai.ndl.go.jp/api/speech?recordPacking=json&issueID=' . rawurlencode($issue) . '&speechNumber=' . $no;
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true, 'header' => "User-Agent: xb4g-giin\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $d = $raw !== false ? json_decode($raw, true) : null;
    $r = $d['speechRecord'][0] ?? null;
    if (!is_array($r)) { return null; }
    $body = (string)($r['speech'] ?? '');
    return ['speech_id' => $issue . '_' . sprintf('%03d', $no), 'speaker' => (string)($r['speaker'] ?? ''),
            'position' => (string)($r['speakerPosition'] ?? ''), 'body' => $body,
            'speech_url' => (string)($r['speechURL'] ?? ''), 'kind' => g_qa_kind($body), 'speech_order' => $no];
}

/** 質問の直後の政府答弁。表にあればそれ、無ければ会議録APIを読んで手元に置く */
function g_qa_answer(array $q): ?array
{
    $a = g_one("SELECT * FROM tracker_speech WHERE tracker=? AND meeting_url=? AND kind='gov'
                AND speech_order BETWEEN ? AND ? ORDER BY speech_order LIMIT 1",
               [$q['tracker'], $q['meeting_url'], (int)$q['speech_order'] + 1, (int)$q['speech_order'] + 3]);
    if ($a) {
        // 間に別の議員の質疑が挟まっていたら、それは別の質問への答弁
        $mid = (int)g_val("SELECT COUNT(*) FROM tracker_speech WHERE tracker=? AND meeting_url=? AND kind='q'
                           AND speech_order > ? AND speech_order < ?",
                          [$q['tracker'], $q['meeting_url'], (int)$q['speech_order'], (int)$a['speech_order']]);
        return $mid ? null : $a;
    }
    $f = g_qa_cache_dir() . '/ans_' . preg_replace('/[^0-9A-Za-z_]/', '', (string)$q['speech_id']) . '.json';
    if (is_file($f)) {
        $c = json_decode((string)@file_get_contents($f), true);
        if (is_array($c)) { return $c['none'] ?? false ? null : $c; }
    }
    if (!preg_match('/^(.+)_(\d+)$/', (string)$q['speech_id'], $m)) { return null; }
    // 会議録APIへの負荷を抑える: 同時に1つだけ（取れなければ今回は答弁なしで出し、手元には置かない）
    $lock = @fopen(g_qa_cache_dir() . '/ndl.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return null; }
    $found = null; $ok = true;
    for ($i = 1; $i <= 3; $i++) {
        $s = g_qa_ndl($m[1], (int)$m[2] + $i);
        if ($s === null) { $ok = $i > 1; break; }          // 会議の終わり（2件目以降）か、読めなかった
        if ($s['kind'] === 'chair') { continue; }           // 「○○局長。」の指名は飛ばす
        if ($s['kind'] === 'gov') { $found = $s; }
        break;
    }
    flock($lock, LOCK_UN); fclose($lock);
    if ($ok) { @file_put_contents($f, json_encode($found ?? ['none' => 1], JSON_UNESCAPED_UNICODE)); }
    return $found;
}

/** 発言の頭の「○氏名君」を落とし、空白を詰めて文に分ける */
function g_qa_sentences(string $body): array
{
    $body = preg_replace('/^[○◯●][^\s　]{1,40}[\s　]*/u', '', $body) ?? $body;
    $body = preg_replace('/\s+/u', '', $body) ?? $body;
    return preg_split('/(?<=。)/u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

/** 質問の抜粋: 語を含む文（最大 $max 字ぶん）。語が無ければ先頭から */
function g_qa_quote_q(string $body, array $words, int $max): string
{
    $words = g_term_parts($words);
    $ss = g_qa_sentences($body);
    $out = '';
    foreach ($ss as $i => $s) {
        foreach ($words as $w) {
            if (mb_strpos($s, $w) === false) { continue; }
            $out = $s;
            // 語を含む文が短ければ、続く文も足す
            for ($j = $i + 1; $j < count($ss) && mb_strlen($out) + mb_strlen($ss[$j]) <= $max; $j++) { $out .= $ss[$j]; }
            break 2;
        }
    }
    if ($out === '') { $out = implode('', $ss); }
    return mb_strlen($out) > $max ? mb_substr($out, 0, $max - 1) . '…' : $out;
}

/** 答弁の抜粋: 「お答えいたします」などの前置きを除いた冒頭。語を含む文があれば、そこから */
function g_qa_quote_a(string $body, array $words, int $max): string
{
    $words = g_term_parts($words);
    $ss = array_values(array_filter(g_qa_sentences($body),
        fn($s) => !preg_match('/^(お答え[^。]{0,10}ます|(ただいまの|今の)?御質問[^。]{0,24}|御指摘[^。]{0,12}ございます)。$/u', $s)));
    $start = 0;
    foreach ($ss as $i => $s) { foreach ($words as $w) { if (mb_strpos($s, $w) !== false) { $start = $i; break 2; } } }
    $out = implode('', array_slice($ss, $start));
    if ($out === '') { $out = implode('', $ss); }
    return mb_strlen($out) > $max ? mb_substr($out, 0, $max - 1) . '…' : $out;
}

/** 質問（$sid が答弁ならその質問）と答弁。見つからなければ null */
function g_qa_pair(string $key, string $sid): ?array
{
    if (!g_tracker_ready()) { return null; }
    $s = g_one("SELECT t.*, g.slug, g.plain FROM tracker_speech t LEFT JOIN giin g ON g.id=t.giin_id
                WHERE t.tracker=? AND t.speech_id=?", [$key, $sid]);
    if (!$s) { return null; }
    if ($s['kind'] === 'gov') {
        $q = g_one("SELECT t.*, g.slug, g.plain FROM tracker_speech t LEFT JOIN giin g ON g.id=t.giin_id
                    WHERE t.tracker=? AND t.meeting_url=? AND t.kind='q' AND t.speech_order BETWEEN ? AND ?
                    ORDER BY t.speech_order DESC LIMIT 1",
                   [$key, $s['meeting_url'], (int)$s['speech_order'] - 3, (int)$s['speech_order'] - 1]);
        return $q ? ['q' => $q, 'a' => g_qa_answer($q)] : ['q' => null, 'a' => $s];
    }
    if ($s['kind'] !== 'q') { return null; }
    $a = g_qa_answer($s);
    // **別の論点への答弁を組にしない。** 質問はよく「（ことがらにふれる）…一つ飛ばして、次に…」と
    // 話題を移してから終わり、直後の答弁は最後の話題への答えになる（小林さやか議員 2026-07-10 で実測）。
    // 答弁にも語があるか、質問の後ろ3割で語にふれているときだけ組にする
    $far = false;
    if ($a) {
        $words = g_term_parts((array)(g_tracker($key)['words'] ?? []));
        $inA = false; $last = -1;
        $qb = implode('', g_qa_sentences((string)$s['body']));
        foreach ($words as $w) {
            if (mb_strpos((string)$a['body'], $w) !== false) { $inA = true; }
            $p = mb_strrpos($qb, $w); if ($p !== false) { $last = max($last, $p); }
        }
        $far = !$inA && $last >= 0 && $last < mb_strlen($qb) * 0.7;
    }
    return ['q' => $s, 'a' => $far ? null : $a, 'a_far' => $far ? $a : null];
}

function g_qa_who_q(array $q): string
{
    $p = g_party_short((string)($q['kaiha_at'] ?? ''));
    return $q['speaker'] . ' 議員' . ($p !== 'その他' ? '（' . $p . '）' : '');
}

function g_qa_who_a(array $a): string
{
    $pos = trim((string)($a['position'] ?? ''));
    return trim(($pos !== '' ? $pos . '　' : '') . $a['speaker']);
}

/* ---------------- 画像 ---------------- */

/** カード画像（1200×630 PNG）。作った画像は手元に置き、2回目からはそれを返す */
function g_qa_png(string $key, string $sid): void
{
    $t = g_tracker($key);
    $f = g_qa_cache_dir() . '/card_' . preg_replace('/[^0-9a-z-]/', '', $key) . '_' . preg_replace('/[^0-9A-Za-z_]/', '', $sid) . '.png';
    if (!is_file($f)) {
        $pair = $t ? g_qa_pair($key, $sid) : null;
        if (!$pair || !function_exists('imagettftext')) {
            header('Location: ' . g_abs($t ? 'img/og/tracker-' . $key . '.png' : 'ogp.png'), true, 302);
            return;
        }
        $png = g_qa_draw($t, $pair['q'], $pair['a']);
        @file_put_contents($f, $png);
    } else {
        $png = (string)file_get_contents($f);
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo $png;
}

function g_qa_draw(array $t, ?array $q, ?array $a): string
{
    $W = 1200; $H = 630;
    [$FB, $FM, $FR] = g_card_fonts();
    [$im, $C] = g_card_canvas();
    ['white' => $white, 'ink' => $ink, 'gray' => $gray, 'teal' => $teal, 'tealD' => $tealD, 'mint' => $mint,
     'mint2' => $mint2, 'navy' => $navy, 'navyBg' => $navyBg, 'hi' => $hi] = $C;

    $words = g_term_parts((array)($t['words'] ?? []));
    $X = 56; $IW = $W - 2 * $X;

    // 上の帯: ことがら名と、いつ・どこで（重ならないよう共通の部品で描く）
    $src = $q ?? $a;
    g_card_header($im, $C, '国会トラッカー｜' . $t['name'], g_date((string)$src['date']) . '　' . $src['house'] . '　' . $src['meeting']);

    $y = 104;
    $block = function (string $label, int $labelBg, int $boxBg, string $who, string $quote, int $lines, float $qsize) use (&$y, $im, $X, $IW, $FB, $FM, $FR, $white, $ink, $hi, $words) {
        $wrapped = g_card_wrap('「' . $quote . '」', $FR, $qsize, $IW - 56, $lines);
        $lh = (int)round($qsize * 1.62);
        $h = 62 + $lh * count($wrapped) + 14;
        imagefilledrectangle($im, $X, $y, $X + $IW, $y + $h, $boxBg);
        imagefilledrectangle($im, $X, $y, $X + 8, $y + $h, $labelBg);
        imagefilledrectangle($im, $X + 28, $y + 16, $X + 28 + 74, $y + 50, $labelBg);
        imagettftext($im, 19, 0, $X + 46, $y + 41, $white, $FB, $label);
        $whoSize = g_card_fit($who, $FM, 24, $IW - 150);
        imagettftext($im, $whoSize, 0, $X + 118, $y + 43, $ink, $FM, $who);
        $ly = $y + 62 + (int)round($qsize);
        foreach ($wrapped as $ln) { g_card_line($im, $ln, $FR, $qsize, $X + 28, $ly, $ink, $hi, $words); $ly += $lh; }
        $y += $h + 16;
    };
    if ($q && $a) {
        $block('質問', $teal, $mint2, g_qa_who_q($q), g_qa_quote_q((string)$q['body'], $words, 160), 4, 24);
        $block('答弁', $navy, $navyBg, g_qa_who_a($a), g_qa_quote_a((string)$a['body'], $words, 120), 3, 24);
    } elseif ($q) {
        $block('質問', $teal, $mint2, g_qa_who_q($q), g_qa_quote_q((string)$q['body'], $words, 200), 6, 27);
    } else {
        $block('答弁', $navy, $navyBg, g_qa_who_a($a), g_qa_quote_a((string)$a['body'], $words, 200), 6, 27);
    }

    g_card_footer($im, $C, 'tracker/' . $t['key'], '国会会議録から抜粋（要約していません）');
    return g_card_png($im);
}

/* ---------------- ページ ---------------- */

function g_page_qa(string $key, string $sid): void
{
    $t = g_tracker($key);
    $pair = $t ? g_qa_pair($key, $sid) : null;
    if (!$pair) {
        http_response_code(404); g_head('見つかりません', '', '/tracker/' . $key, ['noindex' => true]);
        echo '<h1>この発言は見つかりません</h1><p><a href="' . g_url('tracker/' . $key) . '">国会トラッカーへ</a></p>'; g_foot(); return;
    }
    $q = $pair['q']; $a = $pair['a'];
    $main = $q ?? $a;
    $canon = 'tracker/' . $key . '/' . $main['speech_id'];
    $words = g_term_parts((array)$t['words']);
    $w = $t['seo_word'] ?? $t['name'];
    $title = $q ? $q['speaker'] . '議員の質問と政府の答弁｜' . $w . '（' . g_date((string)$q['date']) . '）'
                : $a['speaker'] . 'の答弁｜' . $w . '（' . g_date((string)$a['date']) . '）';
    $desc = $q ? g_qa_quote_q((string)$q['body'], $words, 110) : g_qa_quote_a((string)$a['body'], $words, 110);
    g_head($title, $desc, '/' . $canon, ['noindex' => true, 'nosuffix' => true,
        'image' => g_abs($canon . '.png')]);
    echo '<section class="hero p"><nav class="crumb"><a href="' . g_url('') . '">ホーム</a> › '
       . '<a href="' . g_url('tracker') . '">国会トラッカー</a> › '
       . '<a href="' . g_url('tracker/' . $key) . '">' . g_e($t['name']) . '</a> › 質疑1件</nav>'
       . '<p class="kick">Diet Tracker</p><h1>' . g_e($q ? $q['speaker'] . '議員の質問と、政府の答弁' : $a['speaker'] . 'の答弁') . '</h1>'
       . '<p class="lead">' . g_e(g_date((string)$main['date']) . '　' . $main['house'] . '　' . $main['meeting'] . ' ' . $main['issue'])
       . '。「' . g_e($t['name']) . '」の国会トラッカーから、この1件を取り出しました。</p></section>';

    echo '<div class="panel" style="padding:10px"><img src="' . g_e(g_url($canon . '.png')) . '" alt="質問と答弁のカード" '
       . 'width="1200" height="630" style="width:100%;height:auto;display:block;border-radius:8px"></div>';
    $label = ($q ? $q['speaker'] . '議員の質問と政府の答弁' : $a['speaker'] . 'の答弁')
           . '（' . $t['name'] . '・' . g_date((string)$main['date']) . '）';
    g_share_buttons($label, $canon, 'qa');

    foreach ([['質問', $q, 'q'], ['答弁', $a, 'a']] as [$label, $s, $k]) {
        echo '<h2>' . $label . '</h2>';
        if (!$s) {
            echo '<div class="panel"><p style="margin:0">' . ($k === 'a'
                ? (!empty($pair['a_far'])
                   ? 'この質問は「' . g_e($t['name']) . '」にふれたあと別の論点へ移っていて、直後の答弁（' . g_e(g_qa_who_a($pair['a_far'])) . '）は別の論点への答えでした。'
                     . '<a href="' . g_e((string)$pair['a_far']['speech_url']) . '" rel="nofollow noopener" target="_blank">その答弁を会議録で読む</a>'
                   : 'この質問の直後に政府の答弁は見つかりませんでした（別の議員の発言が続いたか、答弁を求めない発言です）。会議録で前後をお読みください。')
                : '対応する質問は、このことがらの語を含んでいなかったため、トラッカーには入っていません。') . '</p></div>';
            continue;
        }
        $who = $k === 'q' ? g_qa_who_q($s) : g_qa_who_a($s);
        if ($k === 'q') {
            $who = '<a href="' . g_url('tracker/' . $key . '/m/' . g_tm_id((string)$s['speaker'])) . '" rel="nofollow">' . g_e($who) . '</a>'
                 . (!empty($s['slug']) ? ' <a class="note" href="' . g_url($s['slug']) . '">議員ページ</a>' : '');
        } else { $who = g_e($who); }
        $txt = $k === 'q' ? g_qa_quote_q((string)$s['body'], $words, 400) : g_qa_quote_a((string)$s['body'], $words, 400);
        $kw = '';
        foreach ($words as $ww) { if (mb_strpos($txt, $ww) !== false) { $kw = $ww; break; } }
        echo '<div class="sp"><div class="m"><b>' . $who . '</b></div>'
           . '<p class="t">' . g_mark(g_e($txt), $kw) . '</p>'
           . '<div class="lk"><a href="' . g_e($s['speech_url']) . '" rel="nofollow noopener" target="_blank">この発言を会議録で読む</a></div></div>';
    }
    echo '<p class="note">抜粋は会議録の文をそのまま切り出したもので、要約していません。答弁は「質問の直後の政府側の発言」を機械的に取り出したもので、'
       . '一つの質問に複数の論点があるときは、別の論点への答弁であることがあります。全文は会議録でご確認ください。</p>';

    if ($q) {
        $more = g_all("SELECT speech_id, date, meeting, body FROM tracker_speech WHERE tracker=? AND speaker=? AND kind='q' AND speech_id<>?
                       ORDER BY date DESC, speech_order DESC LIMIT 8", [$key, $q['speaker'], $q['speech_id']]);
        if ($more) {
            echo '<h2>' . g_e($q['speaker']) . '議員の「' . g_e($t['name']) . '」のほかの質問</h2><div class="panel"><ul>';
            foreach ($more as $r) {
                echo '<li><a href="' . g_url('tracker/' . $key . '/' . $r['speech_id']) . '" rel="nofollow">'
                   . g_e(g_date((string)$r['date']) . '　' . $r['meeting']) . '</a>'
                   . '<br><span class="note">' . g_e(g_qa_quote_q((string)$r['body'], $words, 60)) . '</span></li>';
            }
            echo '</ul></div>';
        }
    }
    echo '<p class="more"><a class="btn o" href="' . g_url('tracker/' . $key) . '">' . g_e($w) . 'の国会答弁をすべて見る</a></p>';
    g_foot();
}
