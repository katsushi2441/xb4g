<?php
/**
 * カード画像（1200×630 PNG）の共通部品。質疑カード・議員×テーマのカード・トラッカーの推移・法案の賛否で使う。
 *
 * サーバーの PHP（GD＋FreeType）で描き、1回描いたものは data/cache/<種類>/ に置いて使い回す。
 * フォントは fonts/（Noto Sans CJK を JIS第1・第2水準に絞ったもの。scripts/make_card_fonts.py）。
 * 見た目は make_ogp_all.py のカードにそろえる（白地・薄緑の円・ティール・濃紺）。
 */
declare(strict_types=1);

const G_CARD_FONT_DIR = __DIR__ . '/../fonts';

/** [Black, Bold, Regular] */
function g_card_fonts(): array
{
    return [G_CARD_FONT_DIR . '/NotoSansJP-Black-sub.otf', G_CARD_FONT_DIR . '/NotoSansJP-Bold-sub.otf',
            G_CARD_FONT_DIR . '/NotoSansJP-Regular-sub.otf'];
}

function g_card_cache_dir(string $kind): string
{
    $d = __DIR__ . '/../data/cache/' . preg_replace('/[^a-z0-9_-]/', '', $kind);
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    return is_dir($d) ? $d : sys_get_temp_dir();
}

/** 白地に薄緑の円2つ。[画像, 色の表] */
function g_card_canvas(int $W = 1200, int $H = 630): array
{
    $im = imagecreatetruecolor($W, $H);
    imageantialias($im, true);
    $c = fn(string $hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $C = ['white' => $c('#ffffff'), 'ink' => $c('#12202f'), 'gray' => $c('#5d6b7a'), 'grayL' => $c('#c9d3dc'),
          'teal' => $c('#0a9a8f'), 'tealD' => $c('#0a726b'), 'mint' => $c('#e6f4f2'), 'mint2' => $c('#f0f6f6'),
          'line' => $c('#bfe3de'), 'navy' => $c('#1f3a5f'), 'navyBg' => $c('#eef2f7'), 'hi' => $c('#c2410c'),
          'yes' => $c('#0a9a8f'), 'no' => $c('#d9534f'), 'abs' => $c('#e3e9ec')];
    imagefilledrectangle($im, 0, 0, $W, $H, $C['white']);
    imagefilledellipse($im, 150, 70, 660, 620, $C['mint']);
    imagefilledellipse($im, $W - 120, $H - 45, 680, 570, $C['mint2']);
    return [$im, $C];
}

/** 上の帯（左にピル型のラベル、右に小さな文字） */
function g_card_header($im, array $C, string $tag, string $right = ''): void
{
    [, $FM, $FR] = g_card_fonts();
    $X = 56; $W = imagesx($im);
    $ts = g_card_fit($tag, $FM, 20, 620);
    $b = imagettfbbox($ts, 0, $FM, $tag); $tw = $b[2] - $b[0];
    imagefilledrectangle($im, $X, 34, $X + $tw + 36, 76, $C['mint']);
    imagettftext($im, $ts, 0, $X + 18, 63, $C['tealD'], $FM, $tag);
    if ($right !== '') {
        $ws = g_card_fit($right, $FR, 18, $W - 2 * $X - $tw - 70);
        $b = imagettfbbox($ws, 0, $FR, $right);
        imagettftext($im, $ws, 0, $W - $X - ($b[2] - $b[0]), 62, $C['gray'], $FR, $right);
    }
}

/** 下の帯（左に公開URL、右に出典） */
function g_card_footer($im, array $C, string $path, string $note): void
{
    [, , $FR] = g_card_fonts();
    $X = 56; $W = imagesx($im); $H = imagesy($im);
    $url = preg_replace('#^https?://#', '', g_abs($path));
    imagettftext($im, 17, 0, $X, $H - 26, $C['gray'], $FR, $url);
    // 出典が長いと公開URLに重なる（2026-10-14 地元の数字カード）。空いている幅に収め、入らなければ字を小さく、それでも入らなければ切る
    $room = $W - 2 * $X - g_card_textw($url, $FR, 17) - 30;
    $size = g_card_fit($note, $FR, 17, $room, 13);
    while (g_card_textw($note, $FR, $size) > $room && mb_strlen($note) > 4) { $note = mb_substr($note, 0, mb_strlen($note) - 2) . '…'; }
    imagettftext($im, $size, 0, $W - $X - g_card_textw($note, $FR, $size), $H - 26, $C['gray'], $FR, $note);
}

/** 文字列の幅（送り幅。末尾の字の右の余白も含む） */
function g_card_textw(string $s, string $font, float $size): int
{
    if ($s === '') { return 0; }
    $b1 = imagettfbbox($size, 0, $font, $s . 'あ'); $b2 = imagettfbbox($size, 0, $font, 'あ');
    return $b1[2] - $b2[2];
}

/** 中央寄せで1行 */
function g_card_center($im, string $s, string $font, float $size, int $cx, int $y, int $col): void
{
    imagettftext($im, $size, 0, (int)round($cx - g_card_textw($s, $font, $size) / 2), $y, $col, $font, $s);
}

/**
 * 縦の棒グラフ。$rows = [[ラベル, 値], ...]。棒の上に値、下にラベル。最大の棒を強調色にする
 */
function g_card_bars($im, array $C, array $rows, int $x, int $y, int $w, int $h, int $col, int $colMax): void
{
    [, $FM, $FR] = g_card_fonts();
    $n = count($rows);
    if ($n === 0) { return; }
    $max = max(1, max(array_map(fn($r) => (int)$r[1], $rows)));
    $gap = $n > 12 ? 6 : 10;
    $bw = (int)floor(($w - $gap * ($n - 1)) / $n);
    $base = $y + $h - 30;          // ラベルの分を下に空ける
    $top = $y + 26;                // 値の分を上に空ける
    imageline($im, $x, $base, $x + $w, $base, $C['grayL']);
    $lab = $n > 10 ? 14 : 16;
    foreach ($rows as $i => [$label, $v]) {
        $v = (int)$v;
        $bx = $x + $i * ($bw + $gap);
        $bh = (int)round(($base - $top) * $v / $max);
        if ($v > 0) { imagefilledrectangle($im, $bx, $base - max(2, $bh), $bx + $bw, $base - 1, $v === $max ? $colMax : $col); }
        $vs = (string)$v;
        g_card_center($im, $vs, $FM, $n > 12 ? 13 : 15, $bx + (int)($bw / 2), $base - $bh - 8, $C['ink']);
        // ラベルは「2026」→ 狭ければ「'26」
        $ls = (string)$label;
        if (g_card_textw($ls, $FR, $lab) > $bw + 4 && preg_match('/^(\d{2})(\d{2})$/', $ls, $m)) { $ls = "'" . $m[2]; }
        g_card_center($im, $ls, $FR, $lab, $bx + (int)($bw / 2), $base + 22, $C['gray']);
    }
}

function g_card_png($im): string
{
    ob_start(); imagepng($im, null, 9); imagedestroy($im);
    return (string)ob_get_clean();
}

/** 手元に置いた画像を返す。無ければ $make() で描いて置く。$make が null を返したら $fallback へ302 */
function g_card_serve(string $file, callable $make, string $fallback, int $maxAge = 0): void
{
    $png = null;
    if (is_file($file) && ($maxAge === 0 || time() - (int)filemtime($file) < $maxAge)) {
        $png = (string)file_get_contents($file);
    } elseif (function_exists('imagettftext')) {
        $png = $make();
        if ($png !== null) { @file_put_contents($file, $png); }
    }
    if ($png === null) { header('Location: ' . $fallback, true, 302); return; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    echo $png;
}

/** 字単位で折り返す（日本語は単語の区切りが無い）。幅は行全体で測る（1字ずつの外接箱は送り幅より狭く、足すと短く出る）。
 *  行頭に句読点を置かない */
function g_card_wrap(string $text, string $font, float $size, int $maxw, int $maxLines): array
{
    $lines = []; $cur = '';
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($chars as $ch) {
        $b = imagettfbbox($size, 0, $font, $cur . $ch);
        if ($b[2] - $b[0] > $maxw && $cur !== '' && !preg_match('/^[、。）」』，．・ー…]$/u', $ch)) {
            $lines[] = $cur; $cur = '';
            if (count($lines) === $maxLines) {
                $last = $lines[$maxLines - 1];
                $lines[$maxLines - 1] = mb_substr($last, 0, mb_strlen($last) - 1) . '…';
                return $lines;
            }
        }
        $cur .= $ch;
    }
    if ($cur !== '') { $lines[] = $cur; }
    return $lines;
}

/** 1行を描く。語（$words）の部分だけ色を変える */
function g_card_line($im, string $line, string $font, float $size, int $x, int $y, int $col, int $hi, array $words): void
{
    $mask = array_fill(0, mb_strlen($line), false);
    foreach ($words as $w) {
        $off = 0;
        while (($p = mb_strpos($line, $w, $off)) !== false) {
            for ($i = 0; $i < mb_strlen($w); $i++) { $mask[$p + $i] = true; }
            $off = $p + 1;
        }
    }
    // 同じ色の続きはまとめて描く（字ごとに描くと字間が崩れる）
    $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $seg = ''; $segHi = null; $cx = $x;
    // 位置は「行頭からここまで」の送り幅で決める。外接箱は最後の字の右の余白を含まないので、
    // 区切りごとに足すと字が重なる。末尾に「あ」を付けて測り、「あ」の分を引くと送り幅になる
    $adv = function (string $str) use ($font, $size): int {
        if ($str === '') { return 0; }
        $b1 = imagettfbbox($size, 0, $font, $str . 'あ'); $b2 = imagettfbbox($size, 0, $font, 'あ');
        return $b1[2] - $b2[2];   // 原点からの右端どうしの差（左端は字の形で動くので使わない）
    };
    $done = '';
    $flush = function () use (&$seg, &$segHi, &$cx, &$done, $adv, $x, $im, $font, $size, $y, $col, $hi) {
        if ($seg === '') { return; }
        imagettftext($im, $size, 0, $x + $adv($done), $y, $segHi ? $hi : $col, $font, $seg);
        $done .= $seg;
        $seg = '';
    };
    foreach ($chars as $i => $ch) {
        if ($segHi !== null && $segHi !== $mask[$i]) { $flush(); }
        $segHi = $mask[$i]; $seg .= $ch;
    }
    $flush();
}

function g_card_fit(string $text, string $font, float $size, int $maxw, float $min = 14): float
{
    while ($size > $min) {
        $b = imagettfbbox($size, 0, $font, $text);
        if ($b[2] - $b[0] <= $maxw) { break; }
        $size -= 1;
    }
    return $size;
}

/**
 * 「Xで共有」と「タイトルとURLをコピー」。返信に貼るときは「何の話か」の1行とURLを一緒に貼りたい
 * （URLだけだと文を自分で書くことになる・2026-10-14）。$ref は計測の目印（#ref=x-share-<ref> / copy-<ref>）
 */
function g_share_buttons(string $label, string $path, string $ref): void
{
    $share = 'https://x.com/intent/post?text=' . rawurlencode($label) . '&url=' . rawurlencode(g_abs($path) . '#ref=x-share-' . $ref);
    $copy = $label . "\n" . g_abs($path) . '#ref=copy-' . $ref;
    echo '<p style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 4px">'
       . '<a class="btn" href="' . g_e($share) . '" target="_blank" rel="noopener">Xで共有</a>'
       . '<button class="btn o" type="button" data-copy="' . g_e($copy) . '" onclick="gCardCopy(this)">タイトルとURLをコピー</button></p>'
       . '<script>function gCardCopy(b){var t=b.getAttribute("data-copy");function ok(){b.textContent="コピーしました"}'
       . 'if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok)}else{var a=document.createElement("textarea");'
       . 'a.value=t;document.body.appendChild(a);a.select();document.execCommand("copy");a.remove();ok()}}</script>'
       . '<p class="note">コピーした1行とURLをXやSlackに貼ると、上のカードが表示されます。</p>';
}
