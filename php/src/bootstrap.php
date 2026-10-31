<?php
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');

$autoload = BASE_DIR . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('vendor が見つかりません。GitHub Actions のアップロードが終わっているか確認してください。');
}
require $autoload;

$GLOBALS['CONFIG'] = is_file(BASE_DIR . '/config.php') ? (require BASE_DIR . '/config.php') : [];

require __DIR__ . '/util.php';
require __DIR__ . '/db.php';
