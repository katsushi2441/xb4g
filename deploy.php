<?php
// GitHub webhook → リポジトリの content/ と images/uploads/ をこのサーバーへ同期する。
// Decapで編集(=GitHubにcommit)されると、GitHubがここをPOSTで叩き、数秒でサイトに反映される。
$cfg = __DIR__ . '/deploy-config.php'; // define('XB4G_WEBHOOK_SECRET', '...');
if (!is_file($cfg)) { http_response_code(500); exit('deploy-config.php missing'); }
require $cfg;

$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (!hash_equals('sha256=' . hash_hmac('sha256', $body, XB4G_WEBHOOK_SECRET), $sig)) {
    http_response_code(403); exit('bad signature');
}
if (($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '') !== 'push') { exit('ignored'); }

$zip = sys_get_temp_dir() . '/xb4g-' . bin2hex(random_bytes(6)) . '.zip';
$ch = curl_init('https://codeload.github.com/katsushi2441/xb4g/zip/refs/heads/main');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'xb4g-deploy']);
$data = curl_exec($ch); curl_close($ch);
if (!$data || strlen($data) < 200) { http_response_code(502); exit('download failed'); }
file_put_contents($zip, $data);

$za = new ZipArchive();
if ($za->open($zip) !== true) { unlink($zip); http_response_code(500); exit('zip open failed'); }
$n = 0; $fail = [];
for ($i = 0; $i < $za->numFiles; $i++) {
    $name = $za->getNameIndex($i);
    // xb4g-main/content/... と xb4g-main/images/uploads/... だけを取り出す(コード類はFTPデプロイ)
    if (!preg_match('~^[^/]+/((content|images/uploads)/.+)$~', $name, $m)) continue;
    if (substr($name, -1) === '/') continue;
    $rel = $m[1];
    if (strpos($rel, '..') !== false) continue;
    $dst = __DIR__ . '/' . $rel;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0777, true);
    if (@file_put_contents($dst, $za->getFromIndex($i)) === false) { $fail[] = $rel; continue; }
    $n++;
}
$za->close(); unlink($zip);
if ($fail) { http_response_code(500); echo "written: $n, FAILED: " . implode(',', array_slice($fail, 0, 5)); exit; }
echo "written: $n";
