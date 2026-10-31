<?php
// 計算が、Flask 版（Python）と同じ答えになるかを確かめる。使い方: php php/tests/unit.php
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/src/util.php';

$v = json_decode((string)file_get_contents(__DIR__ . '/vectors.json'), true);
$fail = 0;
$n = 0;

function check(string $label, mixed $got, mixed $exp): void
{
    global $fail, $n;
    $n++;
    $ok = (is_float($exp) || is_float($got)) && $got !== null && $exp !== null
        ? abs((float)$got - (float)$exp) < 1e-9
        : $got === $exp;
    if (!$ok) {
        $fail++;
        echo "NG {$label}: got " . json_encode($got, JSON_UNESCAPED_UNICODE) . ' expected ' . json_encode($exp, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

foreach ($v['discount'] as $i => $c) {
    [$d, $p] = calc_discount_lines($c['pairs']);
    check("discount#{$i} disc", $d, $c['disc']);
    check("discount#{$i} pay", $p, $c['pay']);
}
foreach ($v['kind_rate'] as $c) {
    check("kind_rate {$c['kind']} {$c['tier']}", kind_rate($c['kind'], $c['tier']), (float)$c['rate']);
}
foreach ($v['campaign_tier'] as $c) {
    check("campaign_tier {$c['total']}", campaign_tier((int)$c['total']), $c['tier']);
}
foreach ($v['price_kind'] as $c) {
    check('price_kind ' . json_encode($c['name'], JSON_UNESCAPED_UNICODE), price_kind($c['category'], $c['name']), $c['kind']);
}
foreach ($v['num_fmt'] as $c) {
    check('num_fmt ' . json_encode($c['in']), num_fmt($c['in']), $c['out']);
}
foreach ($v['pct_fmt'] as $c) {
    check('pct_fmt ' . json_encode($c['in']), pct_fmt($c['in']), $c['out']);
}
foreach ($v['sama'] as $c) {
    check('sama ' . json_encode($c['in'], JSON_UNESCAPED_UNICODE), sama_fmt($c['in']), $c['out']);
}
foreach ($v['yen'] as $c) {
    check('yen ' . json_encode($c['in']), yen_fmt($c['in']), $c['out']);
}
foreach ($v['nfkc'] as $c) {
    check('nfkc ' . json_encode($c['in'], JSON_UNESCAPED_UNICODE), nfkc($c['in']), $c['out']);
    check('nfkc_basic ' . json_encode($c['in'], JSON_UNESCAPED_UNICODE), nfkc_basic($c['in']), $c['out']);
}
echo "{$n} 件を確認、失敗 {$fail} 件\n";
exit($fail ? 1 : 0);
