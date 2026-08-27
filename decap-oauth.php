<?php
/**
 * Decap CMS 用 GitHub OAuth 中継（1ファイル・共有レンタルサーバー対応）
 *
 * GitHub Pages 等の静的サイトで Decap CMS (backend: github) を使うには、
 * OAuthのcode→token交換を行う小さなサーバーが1つ必要になる。
 * これはそれをPHP1枚でやるもの。Node/Netlifyは不要。
 *
 * 設置:
 *   1. GitHubで OAuth App を作成 (Settings > Developer settings > OAuth Apps)
 *      - Authorization callback URL: https://あなたのドメイン/decap-oauth.php?action=callback
 *   2. 同じフォルダに decap-oauth-config.php を作成:
 *        <?php
 *        define('DECAP_GH_CLIENT_ID', 'xxxx');
 *        define('DECAP_GH_CLIENT_SECRET', 'xxxx');
 *        // 許可するサイト(admin/を置いているオリジン)。カンマ区切りで複数可
 *        define('DECAP_ALLOWED_ORIGINS', 'https://example.github.io');
 *   3. Decap側 admin/config.yml:
 *        backend:
 *          name: github
 *          repo: owner/repo
 *          branch: main
 *          base_url: https://あなたのドメイン   # このPHPを置いたオリジン
 *          auth_endpoint: decap-oauth.php?action=auth
 *
 * プロトコル: Decapはポップアップ内で "authorizing:github" をpostMessageし、
 * 中継側は "authorization:github:success:{...}" を返す(下のJS参照)。
 */

$cfg = __DIR__ . '/decap-oauth-config.php';
if (!is_file($cfg)) { http_response_code(500); exit('decap-oauth-config.php がありません'); }
require $cfg;

session_start();
$action = isset($_GET['action']) ? $_GET['action'] : '';
// Decapはauth_endpointの後ろに '?provider=github&...' をさらに連結するため
// action の値が 'auth?provider=github' になる。?以降を捨てて正規化する。
$action = explode('?', $action)[0];

if ($action === 'auth') {
    $_SESSION['decap_state'] = bin2hex(random_bytes(16));
    $params = http_build_query(array(
        'client_id' => DECAP_GH_CLIENT_ID,
        'scope'     => 'repo,user',
        'state'     => $_SESSION['decap_state'],
    ));
    header('Location: https://github.com/login/oauth/authorize?' . $params);
    exit;
}

if ($action === 'callback') {
    $code  = isset($_GET['code']) ? $_GET['code'] : '';
    $state = isset($_GET['state']) ? $_GET['state'] : '';
    $ok = false; $payload = '';
    if ($code !== '' && isset($_SESSION['decap_state']) && hash_equals($_SESSION['decap_state'], $state)) {
        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'client_id' => DECAP_GH_CLIENT_ID,
                'client_secret' => DECAP_GH_CLIENT_SECRET,
                'code' => $code,
            )),
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        ));
        $res = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (!empty($res['access_token'])) {
            $ok = true;
            $payload = json_encode(array('token' => $res['access_token'], 'provider' => 'github'));
        }
    }
    $status  = $ok ? 'success' : 'error';
    $content = $ok ? $payload : json_encode(array('error' => 'OAuth token exchange failed'));
    $origins = array_map('trim', explode(',', DECAP_ALLOWED_ORIGINS));
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html><html><body><script>
(function () {
  var status = <?php echo json_encode($status); ?>;
  var content = <?php echo $content; ?>;
  var allowed = <?php echo json_encode($origins); ?>;
  function receive(e) {
    if (allowed.indexOf(e.origin) === -1) { return; }
    // Decapからの挨拶("authorizing:github")を受けてから結果を返す
    window.removeEventListener('message', receive, false);
    e.source.postMessage(
      'authorization:github:' + status + ':' + JSON.stringify(content),
      e.origin
    );
  }
  window.addEventListener('message', receive, false);
  if (window.opener) { window.opener.postMessage('authorizing:github', '*'); }
  document.body.appendChild(document.createTextNode('認証処理中です。このウィンドウは自動で閉じます…'));
})();
</script></body></html>
    <?php
    exit;
}

http_response_code(404);
echo 'usage: ?action=auth (Decapのauth_endpointに指定)';
