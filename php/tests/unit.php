<?php
// 計算が、Flask 版（Python）と同じ答えになるかを確かめる。使い方: php php/tests/unit.php
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/src/util.php';
require BASE_DIR . '/src/schedule.php';

// 正解データが読めない・空の時は、何も確かめないまま成功にせず、失敗にする
$raw = @file_get_contents(__DIR__ . '/vectors.json');
$v = is_string($raw) ? json_decode($raw, true) : null;
foreach (['discount', 'kind_rate', 'campaign_tier', 'price_kind', 'num_fmt', 'pct_fmt', 'sama', 'yen', 'nfkc'] as $k) {
    if (!is_array($v) || empty($v[$k]) || !is_array($v[$k])) {
        fwrite(STDERR, "NG vectors.json が読めないか、「{$k}」が空です。php/tools/gen_unit_vectors.py で作り直してください\n");
        exit(1);
    }
}
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
// ID・パスワードの確認
$cfg = ['user' => 'u1', 'pass' => 'p1'];
check('basic_auth 正しい', basic_auth_ok($cfg, 'u1', 'p1'), true);
check('basic_auth パスワード違い', basic_auth_ok($cfg, 'u1', 'x'), false);
check('basic_auth ID違い', basic_auth_ok($cfg, 'x', 'p1'), false);
check('basic_auth 空', basic_auth_ok($cfg, '', ''), false);
check('basic_auth 設定が空なら通さない', basic_auth_ok(['user' => '', 'pass' => ''], '', ''), false);
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('u1:p:1');
check('basic_auth 取り出し(コロン入りパスワード)', basic_auth_credentials(), ['u1', 'p:1']);
// ログインの印（Cookie）
$ck = login_cookie_value($cfg, time() + 3600);
check('cookie 正しい', login_cookie_ok($cfg, $ck), true);
check('cookie 期限切れ', login_cookie_ok($cfg, login_cookie_value($cfg, time() - 10)), false);
check('cookie 改ざん', login_cookie_ok($cfg, preg_replace('/^\d+/', (string)(time() + 999999), $ck)), false);
check('cookie 別のパスワード', login_cookie_ok(['user' => 'u1', 'pass' => 'zz'], $ck), false);
check('cookie 空', login_cookie_ok($cfg, ''), false);
// 予定のくり返し（2026-10-31 は土曜日）
$tzj = new DateTimeZone('Asia/Tokyo');
$dd = static fn(string $s) => new DateTimeImmutable($s, $tzj);
check('予定 毎週(土)', schedule_matches(['kind' => 'weekly', 'weekday' => 6], $dd('2026-10-31')), true);
check('予定 毎週(日)は土曜に出ない', schedule_matches(['kind' => 'weekly', 'weekday' => 0], $dd('2026-10-31')), false);
check('予定 第2水曜(10/14)', schedule_matches(['kind' => 'nth', 'weekday' => 3, 'nth' => 2], $dd('2026-10-14')), true);
check('予定 第2水曜(10/7は第1)', schedule_matches(['kind' => 'nth', 'weekday' => 3, 'nth' => 2], $dd('2026-10-07')), false);
check('予定 毎月25日', schedule_matches(['kind' => 'monthly', 'day_of_month' => 25], $dd('2026-11-25')), true);
check('予定 毎月31日は30日の月に出ない', schedule_matches(['kind' => 'monthly', 'day_of_month' => 31], $dd('2026-11-30')), false);
check('予定 1回だけ', schedule_matches(['kind' => 'once', 'once_date' => '2026-12-24'], $dd('2026-12-24')), true);
check('予定 1回だけ(別の日)', schedule_matches(['kind' => 'once', 'once_date' => '2026-12-24'], $dd('2026-12-25')), false);
if ($n < 500) {
    echo "NG 確認した件数が少なすぎます（{$n} 件）。テストが途中で止まっている可能性があります\n";
    exit(1);
}
echo "{$n} 件を確認、失敗 {$fail} 件\n";
exit($fail ? 1 : 0);
