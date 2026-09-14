<?php
/** /giin/ サブパス配置をローカルで再現する。
 *  php -S 127.0.0.1:18997 -t . tests/router.php  */
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (strpos($p, '/giin/data/') === 0) { http_response_code(403); echo 'denied'; return true; }
if (strpos($p, '/giin') !== 0) { http_response_code(404); echo 'outside'; return true; }
$rel = substr($p, strlen('/giin')) ?: '/';
if ($rel === '/sitemap.xml') { require __DIR__ . '/../sitemap.php'; return true; }
$f = __DIR__ . '/..' . $rel;
if ($rel !== '/' && is_file($f)) { return false; }
require __DIR__ . '/../index.php';
return true;
