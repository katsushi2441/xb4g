<?php
// X 返信候補ページの「URL を入れて1件作る」を、当社サーバーの共通中継（kaima/relay :18343 の /xreply）へ取り次ぐ。
// 合言葉は配置のときに x_reply_draft.py の deploy() が kaima/.env の RELAY_XREPLY_TOKEN から埋める（リポジトリには入れない）
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$TOKEN = '__TOKEN__';
$BASE = 'http://exbridge.ddns.net:18343/xreply';

function relay($method, $url, $body, $token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json']]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code === 0) {
        http_response_code(503);
        return json_encode(['state' => 'error', 'error' => '当社サーバーにつながりませんでした。時間をおいてもう一度', 'message' => '当社サーバーにつながりませんでした']);
    }
    http_response_code($code);
    return $res;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $url = trim((string)($_POST['url'] ?? ''));
    if (!preg_match('#^https://(x|twitter)\.com/[A-Za-z0-9_]+/status/\d+#', $url)) {
        http_response_code(400);
        echo json_encode(['error' => 'X の投稿の URL（https://x.com/…/status/数字）を入れてください']);
        exit;
    }
    echo relay('POST', $BASE, json_encode(['url' => $url]), $TOKEN);
    exit;
}
// URL を入れて作った候補は、ここ（同じ階層の mine.json）に残す。定時の更新で index.html が置き換わっても消えない。
// 新しい順に最大 MINE_MAX 件・MINE_DAYS 日まで
const MINE = __DIR__ . '/mine.json';
const MINE_MAX = 30;
const MINE_DAYS = 7;
function mine_load() {
    $a = @json_decode((string)@file_get_contents(MINE), true);
    return is_array($a) ? $a : [];
}
function mine_save($items) {
    $items = array_values(array_filter($items, function ($x) { return $x['t'] > time() - MINE_DAYS * 86400; }));
    $ok = @file_put_contents(MINE, json_encode(array_slice($items, 0, MINE_MAX), JSON_UNESCAPED_UNICODE), LOCK_EX);
    header('X-Mine-Save: ' . ($ok === false ? 'failed ' . (error_get_last()['message'] ?? '') : 'ok'));
}
if (isset($_GET['list'])) {
    echo json_encode(['items' => mine_load()], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['del'])) {
    $d = (string)$_GET['del'];
    mine_save(array_filter(mine_load(), function ($x) use ($d) { return $x['id'] !== $d; }));
    echo json_encode(['ok' => 1]);
    exit;
}
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    http_response_code(400);
    echo json_encode(['state' => 'error', 'message' => '受付番号がありません']);
    exit;
}
$res = relay('GET', $BASE . '/' . $id, null, $TOKEN);
$d = json_decode($res, true);
if (is_array($d) && ($d['state'] ?? '') === 'done' && !empty($d['html'])) {
    $items = mine_load();
    if (!in_array($id, array_column($items, 'id'), true)) {
        array_unshift($items, ['id' => $id, 't' => time(), 'html' => $d['html']]);
        mine_save($items);
    }
}
echo $res;
