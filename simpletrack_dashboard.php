<?php
/**
 * SimpleTrack — 管理画面。
 *
 * simpletrack.php から ?dashboard=1 で読み込まれる。単体では動かない。
 *
 * 認証はパスワード1つ。設定していなければ鍵を掛けずにそのまま開く。
 * 外部の認証基盤には依存させない（依存させると、
 * 解析を見たいだけなのに他システムのログインが要る状態になる）。
 */
if (!defined('ST_PASSWORD_HASH')) { exit; }

session_name('st_admin');
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$err = '';
if (isset($_POST['logout'])) { $_SESSION = array(); session_destroy(); header('Location: ?dashboard=1'); exit; }
if (isset($_POST['pw'])) {
    if (ST_PASSWORD_HASH !== '' && password_verify((string)$_POST['pw'], ST_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['st_ok'] = true;
        header('Location: ?dashboard=1' . (isset($_POST['range']) ? '&range=' . rawurlencode((string)$_POST['range']) : ''));
        exit;
    }
    $err = 'パスワードが違います。';
    sleep(1);   // 総当たりを鈍らせる
}

header('X-Robots-Tag: noindex, nofollow');

// パスワードを設定していなければ、そのまま開く。
// 見られて困る内容が無いなら、鍵を掛けないほうが手間が減る。
// 商品として配るときは設定させる（買った人のサイトでは事情が違う）。
if (ST_PASSWORD_HASH === '') { $_SESSION['st_ok'] = true; }

if (empty($_SESSION['st_ok'])) {
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo st_h(ST_TITLE); ?></title><?php st_styles(); ?></head><body>
    <div class="login">
      <h1><?php echo st_h(ST_TITLE); ?></h1>
      <?php if ($err !== ''): ?><p class="err"><?php echo st_h($err); ?></p><?php endif; ?>
      <form method="post">
        <label for="pw">パスワード</label>
        <input type="password" id="pw" name="pw" autofocus required autocomplete="current-password">
        <button type="submit">開く</button>
      </form>
    </div></body></html><?php
    exit;
}

/* ---- 期間 ---- */
$ranges = array('1d' => '直近24時間', '7d' => '直近7日', '30d' => '直近30日',
                '90d' => '直近3か月', 'all' => 'すべて');
$range  = isset($_GET['range']) && isset($ranges[$_GET['range']]) ? $_GET['range'] : '30d';
$days   = array('1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90);
// 24時間だけは日の境目ではなく「今から24時間前」で切る。
// 日付で切ると、朝に開いたときの対象が数時間ぶんしか無くなる。
$since  = null;
if ($range === '1d')            { $since = strtotime('-24 hours'); }
elseif (isset($days[$range]))   { $since = strtotime('-' . ($days[$range] - 1) . ' days 00:00:00'); }

$a = st_aggregate($since);

/* 前の同じ長さの期間と比べる。単独の数字だけでは増えたか減ったか分からない。 */
$prev = null;
if ($since !== null) {
    $len = $days[$range] * 86400;
    $all = st_aggregate($since - $len);
    $prev = array_sum($all['per_day']) - array_sum($a['per_day']);
}

$pv       = array_sum($a['per_day']);
$visitors = count($a['visitors']);
$ai_total = array_sum($a['ai']);

function st_delta($now, $before) {
    if ($before === null) { return ''; }
    if ($before == 0) { return $now > 0 ? '<span class="up">新規</span>' : ''; }
    $r = round(($now - $before) / $before * 100);
    if ($r == 0) { return '<span class="flat">±0%</span>'; }
    return $r > 0 ? '<span class="up">+' . $r . '%</span>' : '<span class="down">' . $r . '%</span>';
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo st_h(ST_TITLE); ?></title><?php st_styles(); ?></head><body>
<header class="top"><div class="wrap">
  <b><?php echo st_h(ST_TITLE); ?></b>
  <nav>
    <?php foreach ($ranges as $k => $label): ?>
      <a class="<?php echo $range === $k ? 'on' : ''; ?>" href="?dashboard=1&range=<?php echo st_h($k); ?>"><?php echo st_h($label); ?></a>
    <?php endforeach; ?>
    <?php if (ST_PASSWORD_HASH !== ''): ?>
      <form method="post" style="display:inline"><button name="logout" value="1" class="out">閉じる</button></form>
    <?php endif; ?>
  </nav>
</div></header>

<main class="wrap">

<section class="cards">
  <div class="card"><small>ページビュー（人）</small><b><?php echo number_format($pv); ?></b>
    <?php echo st_delta($pv, $prev); ?></div>
  <div class="card"><small>訪問した人</small><b><?php echo number_format($visitors); ?></b></div>
  <div class="card"><small>訪問回数</small><b><?php echo number_format($a['sessions']); ?></b>
    <span class="flat">30分あくと別の訪問</span></div>
  <div class="card ai"><small>AIに読まれた回数</small><b><?php echo number_format($ai_total); ?></b>
    <?php if ($ai_total === 0): ?><span class="flat">記録なし</span><?php endif; ?></div>
  <div class="card"><small>検索エンジンの巡回</small><b><?php echo number_format(array_sum($a['search'])); ?></b></div>
</section>

<section class="box">
  <h2>日ごとの推移</h2>
  <p class="legend"><i class="k-human"></i>人　<i class="k-bot"></i>ボット（AI・検索エンジンなど）</p>
  <?php echo st_svg_line($a['per_day'], $a['bot_per_day']); ?>
</section>

<section class="box hi">
  <h2>AIはこのサイトを読んでいるか</h2>
  <?php if ($ai_total === 0): ?>
    <p class="none">AIクローラーの訪問は記録されていません。</p>
    <p class="warn"><b>JSタグだけで計測している場合、これは正常です。</b>
      クローラーはJavaScriptを実行しないため、タグ方式では原理的に拾えません。
      測るには、PHPページの先頭で <code>require_once 'simpletrack.php';</code> を読み込んでください。</p>
  <?php else: ?>
    <div class="two">
      <div><h3>どのAIが来たか</h3><?php echo st_bars($a['ai']); ?></div>
      <div><h3>どのページが読まれたか</h3><?php echo st_bars($a['ai_pages']); ?></div>
    </div>
    <table><tr><th>AI</th><th>回数</th><th>最後に来た日時</th></tr>
      <?php foreach ($a['ai'] as $name => $n): ?>
      <tr><td><?php echo st_h($name); ?></td><td class="num"><?php echo number_format($n); ?></td>
          <td><?php echo st_h(isset($a['last_ai'][$name]) ? $a['last_ai'][$name] : '—'); ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</section>

<section class="two">
  <div class="box"><h2>よく見られたページ</h2>
    <?php if (!$a['pages']): ?><p class="none">データがありません。</p><?php else: ?>
    <table><tr><th>ページ</th><th>PV</th><th>人数</th></tr>
      <?php $i = 0; foreach ($a['pages'] as $p => $d): if ($i++ >= 15) { break; } ?>
      <tr><td><?php echo st_h($d['title'] !== '' ? $d['title'] : $p); ?>
              <?php if ($d['title'] !== ''): ?><br><span class="sub"><?php echo st_h($p); ?></span><?php endif; ?></td>
          <td class="num"><?php echo number_format($d['pv']); ?></td>
          <td class="num"><?php echo number_format(count($d['v'])); ?></td></tr>
      <?php endforeach; ?>
    </table><?php endif; ?>
  </div>
  <div class="box"><h2>外から見つけてもらった流入</h2>
    <?php echo st_bars($a['refs'], 15); ?>
    <p class="legend" style="margin-top:10px">
      このほかに 参照元なし（ブックマーク・直接入力・アプリ内） <b><?php echo number_format($a['direct']); ?></b> 回。
      数えているのは<b>訪問の入口だけ</b>で、サイト内の移動 <?php echo number_format($a['internal']); ?> 回は含みません。</p>
    <p class="warn" style="font-size:11.5px">
      <b>Search Console とは一致しません。</b>Google はこちらへドメインしか渡さないため、
      自然検索・Discover・ニュース・広告の区別がつきません。「Google（検索・その他）」は
      その全部の合計です。自然検索だけを知りたいときは Search Console の数字が正です。</p>
  </div>
</section>

<section class="two">
  <div class="box"><h2>使われた端末</h2><?php echo st_bars($a['devices'], 5); ?></div>
  <div class="box"><h2>検索エンジンの巡回</h2><?php echo st_bars($a['search'], 8); ?></div>
</section>

<?php if ($a['clicks']): ?>
<section class="box"><h2>外部リンクのクリック</h2>
  <table><tr><th>遷移先</th><th>内容</th><th>人のクリック</th><th>総数</th><th>最後のクリック</th></tr>
    <?php $i = 0; foreach ($a['clicks'] as $c): if ($i++ >= 20) { break; } ?>
    <tr><td><?php echo st_h($c['to']); ?></td><td><?php echo st_h($c['what']); ?></td>
        <td class="num"><?php echo number_format($c['human']); ?></td>
        <td class="num"><?php echo number_format($c['n']); ?></td><td><?php echo st_h($c['last']); ?></td></tr>
    <?php endforeach; ?>
  </table>
</section>
<?php endif; ?>

<?php if ($a['bots']): ?>
<section class="box"><h2>その他のボット</h2><?php echo st_bars($a['bots'], 10); ?></section>
<?php endif; ?>

<p class="foot">対象期間 <?php echo st_h($ranges[$range]); ?>　／　読み取った行数 <?php echo number_format($a['lines']); ?>　／
  IPは保存していません（訪問者の区別にはハッシュを使用）</p>
</main>
</body></html>
<?php

/** 画面のCSS。外部を読まないので、ここに全部書く。 */
function st_styles() {
    echo '<style>
:root{--ink:#17242c;--muted:#6b7f8b;--line:#d9e7ec;--bg:#f6fbfc;--acc:#0a726b;--acc2:#12a99f;--gold:#c98a1e}
@media(prefers-color-scheme:dark){:root{--ink:#e9f2f3;--muted:#9db0b8;--line:#22383e;--bg:#0d1a20;--acc:#2bd4c6;--acc2:#12a99f}}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--ink);
  background:var(--bg);line-height:1.8;font-size:14px}
.wrap{max-width:1100px;margin:0 auto;padding:0 18px}
.top{background:rgba(255,255,255,.86);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;
  backdrop-filter:blur(10px)}
@media(prefers-color-scheme:dark){.top{background:rgba(13,26,32,.9)}}
.top .wrap{display:flex;align-items:center;gap:14px;padding:12px 18px;flex-wrap:wrap}
.top b{font-size:16px;font-weight:900}
.top nav{margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.top nav a{font-size:12.5px;font-weight:700;color:var(--muted);text-decoration:none;padding:5px 11px;
  border:1px solid var(--line);border-radius:999px}
.top nav a.on{background:var(--acc);color:#fff;border-color:var(--acc)}
.out{font:inherit;font-size:12.5px;font-weight:700;color:var(--muted);background:none;cursor:pointer;
  border:1px solid var(--line);border-radius:999px;padding:5px 11px}
main{padding:20px 0 60px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:16px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:14px 16px}
@media(prefers-color-scheme:dark){.card,.box{background:#12242a}}
.card.ai{border-color:var(--acc2)}
.card small{display:block;color:var(--muted);font-size:12px}
.card b{display:block;font-size:26px;line-height:1.3;font-weight:900}
.up{color:#1a8f5a;font-size:12px;font-weight:800}
.down{color:#c0392b;font-size:12px;font-weight:800}
.flat{color:var(--muted);font-size:12px;font-weight:700}
.box{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px}
.box.hi{border-color:var(--acc2);border-width:1.5px}
.box h2{font-size:16px;font-weight:900;margin-bottom:10px}
.box h3{font-size:13px;font-weight:800;color:var(--muted);margin:10px 0 6px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:760px){.two{grid-template-columns:1fr}}
.chart{width:100%;height:220px;display:block}
.chart .grid{stroke:var(--line);stroke-width:1}
.chart .ax{fill:var(--muted);font-size:10px}
.chart .ln{fill:none;stroke-width:2;stroke-linejoin:round}
.chart .ln.human{stroke:var(--acc2)}
.chart .ln.bot{stroke:var(--gold);stroke-dasharray:4 3}
.legend{font-size:12px;color:var(--muted);margin-bottom:6px}
.legend i{display:inline-block;width:14px;height:3px;vertical-align:middle;margin-right:4px}
.legend i.k-human{background:var(--acc2)}
.legend i.k-bot{background:var(--gold)}
.bars{display:flex;flex-direction:column;gap:7px}
.bar{display:grid;grid-template-columns:minmax(0,1fr) 92px 52px;align-items:center;gap:9px;font-size:12.5px}
.bl{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--ink)}
.bt{background:var(--bg);border-radius:999px;height:8px;overflow:hidden}
.bt i{display:block;height:100%;background:var(--acc2);border-radius:999px}
.bn{text-align:right;font-weight:800;font-variant-numeric:tabular-nums}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:12.5px}
th,td{border-bottom:1px solid var(--line);padding:7px 6px;text-align:left;vertical-align:top}
th{color:var(--muted);font-weight:700;font-size:11.5px;white-space:nowrap}
td.num{text-align:right;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.sub{color:var(--muted);font-size:11px;word-break:break-all}
.none{color:var(--muted);font-size:13px;padding:6px 0}
.warn{background:#fbf2db;border:1px solid #ecd9a8;border-radius:10px;padding:12px 14px;font-size:12.5px;
  margin-top:10px;color:#6b5216}
@media(prefers-color-scheme:dark){.warn{background:#241b08;border-color:#4c3c17;color:#f2c766}}
code{background:var(--bg);border:1px solid var(--line);border-radius:4px;padding:1px 5px;font-size:12px}
.foot{color:var(--muted);font-size:11.5px;text-align:center;padding:10px 0}
.login{max-width:340px;margin:12vh auto;background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px}
.login h1{font-size:19px;font-weight:900;margin-bottom:14px}
.login label{display:block;font-size:12.5px;font-weight:700;margin-bottom:5px}
.login input{width:100%;font:inherit;padding:10px 12px;border:1.5px solid var(--line);border-radius:9px;
  background:var(--bg);color:inherit}
.login button{width:100%;margin-top:14px;font:inherit;font-weight:800;padding:11px;border:0;border-radius:999px;
  background:var(--acc);color:#fff;cursor:pointer}
.err{background:#fdecea;border:1px solid #f5c6c2;color:#a3261b;border-radius:9px;padding:10px 12px;
  font-size:12.5px;margin-bottom:12px}
</style>';
}
