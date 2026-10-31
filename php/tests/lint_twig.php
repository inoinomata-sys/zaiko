<?php
// すべての画面（Twig）の書き方に誤りがないかを確かめる。使い方: php php/tests/lint_twig.php
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/app.php';

$twig = twig();
$bad = 0;
$files = glob(BASE_DIR . '/templates/*.twig') ?: [];
if (count($files) < 10) {   // 画面が見つからないまま成功にしない
    echo "NG 画面(Twig)が " . count($files) . " 個しか見つかりません\n";
    exit(1);
}
foreach ($files as $f) {
    $name = basename($f);
    try {
        $twig->load($name);
        echo "OK  {$name}\n";
    } catch (Throwable $e) {
        $bad++;
        echo "NG  {$name}: " . $e->getMessage() . "\n";
    }
}
exit($bad ? 1 : 0);
