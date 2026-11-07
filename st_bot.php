<?php
/**
 * ボット（検索・AIのクローラー）だけを、サーバー側で simpletrack に1件記録する（2026-11-07）。
 * 人は各ページの JavaScript の計測で記録されるので、ここでは数えない（二重に数えないため）。
 * JavaScript を動かさないボットは JS の計測では拾えず、「ChatGPT が読みに来ない」と誤診した（2026-11-07）。
 * ページの入り口（PHP）の先頭で include する。
 */
$__st_ua = strtolower((string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''));
if ($__st_ua === '' || preg_match('/bot|crawl|spider|slurp|chatgpt-user|perplexity|claude-web|bytespider|google-extended|facebookexternalhit|curl\/|python-|wget|headless|go-http-client/', $__st_ua)) {
    @include __DIR__ . '/simpletrack.php';
}
