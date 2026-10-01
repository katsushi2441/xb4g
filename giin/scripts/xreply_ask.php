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
$id = (string)($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    http_response_code(400);
    echo json_encode(['state' => 'error', 'message' => '受付番号がありません']);
    exit;
}
echo relay('GET', $BASE . '/' . $id, null, $TOKEN);
