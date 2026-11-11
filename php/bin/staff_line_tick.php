<?php
declare(strict_types=1);
// Webからの起動は禁止。ロリポップのcronで毎分呼び出す。
$cronCgi = PHP_SAPI === 'cgi-fcgi' && !isset($_SERVER['REQUEST_METHOD']) && !isset($_SERVER['REMOTE_ADDR']);
if (PHP_SAPI !== 'cli' && !$cronCgi) { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/app.php';
try { sl_tick(); } catch (HttpError $e) { exit($e->status === 409 ? 0 : 1); }
catch (Throwable $e) { fwrite(STDERR, "スタッフLINE処理でエラー。管理画面とサーバーログを確認してください。\n"); exit(1); }
