<?php
/**
 * 外部の新着（Kurage動画・VWork Blog・AI OSS技術解説）を取り、
 * content/cache/ にJSONで保存する。表示はキャッシュだけを読むので、
 * 取得元が落ちてもページは壊れない。
 */

define('XB4G_CACHE', __DIR__ . '/../content/cache');
define('XB4G_CACHE_TTL', 1800);   // 30分

function xb4g_http($url, $timeout = 20)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'xb4g-feeds/1.0',
    ));
    $b = curl_exec($ch);
    curl_close($ch);
    return $b === false ? '' : $b;
}

/** キャッシュを読む（無ければ空配列） */
function xb4g_cache_get($name)
{
    $f = XB4G_CACHE . '/' . $name . '.json';
    if (!is_file($f)) return array();
    $j = json_decode(file_get_contents($f), true);
    return is_array($j) ? $j : array();
}

function xb4g_cache_put($name, $data)
{
    if (!is_dir(XB4G_CACHE)) @mkdir(XB4G_CACHE, 0777, true);
    $f = XB4G_CACHE . '/' . $name . '.json';
    @file_put_contents($f, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @chmod($f, 0666);
}

function xb4g_cache_fresh($name)
{
    $f = XB4G_CACHE . '/' . $name . '.json';
    return is_file($f) && (time() - filemtime($f)) < XB4G_CACHE_TTL;
}

/** Kurage動画の新着（サムネつき） */
function xb4g_fetch_videos($limit = 16)
{
    $h = xb4g_http('https://kurage.exbridge.jp/kuragev.php');
    if ($h === '') return array();
    if (!preg_match_all('~<a[^>]+kuragev\.php\?id=([a-f0-9]+)[^>]*>(.*?)</a>~s', $h, $m, PREG_SET_ORDER)) return array();
    $out = array(); $seen = array();
    foreach ($m as $x) {
        $id = $x[1];
        if (isset($seen[$id])) continue;
        $seen[$id] = 1;
        $t = trim(preg_replace('~\s+~u', ' ', strip_tags($x[2])));
        if ($t === '') continue;
        $out[] = array(
            'id'    => $id,
            'title' => $t,
            'url'   => 'https://kurage.exbridge.jp/kuragev.php?id=' . $id,
            'thumb' => 'https://kurage.exbridge.jp/kuragev.php?proxy=thumbnail&job_id=' . $id,
        );
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** 一覧ページから「日付つきリンク」を拾う（VWork Blog / AI OSS技術解説 共通） */
function xb4g_fetch_posts($indexUrl, $base, $limit = 6)
{
    $h = xb4g_http($indexUrl);
    if ($h === '') return array();
    if (!preg_match_all('~<a[^>]+href="([^"]*?(\d{4})-(\d{2})-(\d{2})[^"]*?\.html)"[^>]*>(.*?)</a>~s', $h, $m, PREG_SET_ORDER)) return array();
    $out = array(); $seen = array();
    foreach ($m as $x) {
        $href = $x[1];
        if (isset($seen[$href])) continue;
        $seen[$href] = 1;
        $t = trim(preg_replace('~\s+~u', ' ', strip_tags($x[5])));
        if ($t === '' || mb_strlen($t) < 8) continue;
        // 一覧の末尾に付く「 08-28」のような日付表記は落とす
        $t = preg_replace('~\s*\d{2}-\d{2}$~u', '', $t);
        $url = (strpos($href, 'http') === 0) ? $href : rtrim($base, '/') . '/' . ltrim($href, '/');
        $out[] = array('title' => $t, 'url' => $url, 'date' => $x[2] . '-' . $x[3] . '-' . $x[4]);
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** 全部取り直してキャッシュへ（cron/手動用） */
function xb4g_refresh_all()
{
    $r = array();
    $v = xb4g_fetch_videos();
    if ($v) { xb4g_cache_put('videos', $v); $r['videos'] = count($v); }
    $b = xb4g_fetch_posts('https://katsushi2441.github.io/vwork/blog/', 'https://katsushi2441.github.io/vwork/blog/');
    if ($b) { xb4g_cache_put('blog', $b); $r['blog'] = count($b); }
    $a = xb4g_fetch_posts('https://katsushi2441.github.io/vwork/articles/', 'https://katsushi2441.github.io/vwork/articles/');
    if ($a) { xb4g_cache_put('articles', $a); $r['articles'] = count($a); }
    return $r;
}

/** 表示側から呼ぶ: キャッシュが古ければ裏で更新を試み、必ず配列を返す */
function xb4g_feed($name)
{
    if (!xb4g_cache_fresh($name)) {
        if ($name === 'videos')        { $d = xb4g_fetch_videos();  if ($d) xb4g_cache_put('videos', $d); }
        elseif ($name === 'blog')      { $d = xb4g_fetch_posts('https://katsushi2441.github.io/vwork/blog/', 'https://katsushi2441.github.io/vwork/blog/'); if ($d) xb4g_cache_put('blog', $d); }
        elseif ($name === 'articles')  { $d = xb4g_fetch_posts('https://katsushi2441.github.io/vwork/articles/', 'https://katsushi2441.github.io/vwork/articles/'); if ($d) xb4g_cache_put('articles', $d); }
        // 取得できなければ古いキャッシュをそのまま使う
    }
    return xb4g_cache_get($name);
}
