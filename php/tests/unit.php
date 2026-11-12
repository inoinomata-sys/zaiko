<?php
// 計算が、Flask 版（Python）と同じ答えになるかを確かめる。使い方: php php/tests/unit.php
declare(strict_types=1);

define('BASE_DIR', dirname(__DIR__));
require BASE_DIR . '/src/util.php';
require BASE_DIR . '/src/schedule.php';
require BASE_DIR . '/src/shift.php';
require BASE_DIR . '/src/line.php';
require BASE_DIR . '/src/admin.php';

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
// LINE の署名の確認
$lineBody = '{"events":[]}';
$lineSig = base64_encode(hash_hmac('sha256', $lineBody, 'secret1', true));
check('LINE署名 正しい', line_signature_ok($lineBody, 'secret1', $lineSig), true);
check('LINE署名 本文が違う', line_signature_ok($lineBody . ' ', 'secret1', $lineSig), false);
check('LINE署名 シークレットが違う', line_signature_ok($lineBody, 'secret2', $lineSig), false);
check('LINE署名 ヘッダーなし', line_signature_ok($lineBody, 'secret1', ''), false);
check('LINE署名 シークレット未設定', line_signature_ok($lineBody, '', $lineSig), false);
// 発注書リンクの署名
$sg = line_order_sig(12, 2000000000, 'secretX');
check('リンク署名 同じ入力は同じ', line_order_sig(12, 2000000000, 'secretX'), $sg);
check('リンク署名 発注番号が違えば別', line_order_sig(13, 2000000000, 'secretX') !== $sg, true);
check('リンク署名 期限が違えば別', line_order_sig(12, 2000000001, 'secretX') !== $sg, true);
check('リンク署名 シークレットが違えば別', line_order_sig(12, 2000000000, 'secretY') !== $sg, true);
check('リンク署名 長さ', strlen($sg), 32);
check('UUID の形', (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', line_uuid4()), true);
// 管理者の鍵
$ac = admin_cookie_value('pw1', time() + 600);
check('管理者鍵 正しい', admin_cookie_ok('pw1', $ac), true);
check('管理者鍵 別の合言葉', admin_cookie_ok('pw2', $ac), false);
check('管理者鍵 期限切れ', admin_cookie_ok('pw1', admin_cookie_value('pw1', time() - 5)), false);
check('管理者鍵 合言葉が未設定なら開かない', admin_cookie_ok('', admin_cookie_value('', time() + 600)), false);
check('管理者鍵 次の画面は、自分のサイトの中だけ', admin_next('//evil.example.com'), '/settings');
check('管理者鍵 次の画面(正常)', admin_next('/settings/line'), '/settings/line');
$GLOBALS['CONFIG']['admin_pass'] = 'unit-line-registration';
$_COOKIE['zadmin'] = 'unit-settings-unlocked';
check('LINE登録 設定解除のみでは入室不可', line_registration_key_ok('entry', ''), false);
$entryKey = line_registration_key('entry', time()+60);
check('LINE登録 専用の入室印', line_registration_key_ok('entry', $entryKey), true);
check('LINE登録 入室印で変更できない', line_registration_key_ok('form', $entryKey), false);
check('LINE登録 期限切れ', line_registration_key_ok('entry', line_registration_key('entry', time()-1)), false);
$formKey = line_registration_key('form');
check('LINE登録 同じ管理者で変更可能', line_registration_key_ok('form', $formKey), true);
$_COOKIE['zadmin'] = 'other-browser';
check('LINE登録 別の管理者Cookieでは変更不可', line_registration_key_ok('form', $formKey), false);
$staffExample = ['home_store' => 'イノ', 'off_days' => '0,1'];
$workDay = ['dow' => 2];
$offDay = ['dow' => 0];
check('変更表示 通常勤務を補完しない', shift_calendar_entry($staffExample, $workDay, null, 'changes'), null);
check('変更表示 定休を補完しない', shift_calendar_entry($staffExample, $offDay, null, 'changes'), null);
check('全員表示 基本の店で出勤', shift_calendar_entry($staffExample, $workDay, null, 'all')['store'], 'イノ');
check('出勤表示 定休は載せない', shift_calendar_entry($staffExample, $offDay, null, 'all'), null);
check('出勤表示 勤務日に登録した休みを表示', shift_calendar_entry($staffExample, $workDay, ['store' => SHIFT_OFF], 'all'), ['store' => SHIFT_OFF]);
$customEntry = ['store' => 'リッチ', 'changed' => true, 'tf' => '', 'tt' => ''];
check('全員表示 個別変更が優先', shift_calendar_entry($staffExample, $offDay, $customEntry, 'all'), $customEntry);
check('休み表示 出勤変更は表示しない', shift_calendar_entry($staffExample, $workDay, $customEntry, 'changes'), null);
check('休み表示 個別の休みは表示する', shift_calendar_entry($staffExample, $workDay, ['store' => SHIFT_OFF], 'changes'), ['store' => SHIFT_OFF]);
check('時刻入力 1桁の時を2桁で表示', shift_time_input('9:00'), '09:00');
check('時刻入力 2桁の時刻', shift_time_input('17:00'), '17:00');
check('時刻入力 空欄を維持', shift_time_input(''), '');
check('時刻入力 不正値は表示しない', shift_time_input('25:00'), '');
$dailyRows = [['date'=>'2026-11-10','dow'=>2], ['date'=>'2026-11-11','dow'=>3]];
check('日別カレンダー 火水を独立した列に置く', shift_weeks($dailyRows), [[null, ...$dailyRows, null, null, null, null]]);
$differenceStaff = ['home_store'=>'イノ','off_days'=>'0,1','work_from'=>'9:00','work_to'=>'17:00'];
check('基本との差 通常の登録は変更でない', shift_difference($differenceStaff,['dow'=>2],['store'=>'イノ','tf'=>'09:00','tt'=>'17:00']), []);
check('基本との差 勤務日の休み', shift_difference($differenceStaff,['dow'=>2],['store'=>'休','tf'=>'','tt'=>'']), ['休み']);
check('基本との差 定休は変更でない', shift_difference($differenceStaff,['dow'=>0],['store'=>'休','tf'=>'','tt'=>'']), []);
check('基本との差 休日出勤', shift_difference($differenceStaff,['dow'=>0],['store'=>'イノ','tf'=>'','tt'=>'']), ['休日出勤']);
check('基本との差 ヘルプ', shift_difference($differenceStaff,['dow'=>2],['store'=>'リッチ','tf'=>'','tt'=>'']), ['ヘルプ']);
check('基本との差 時間変更', shift_difference($differenceStaff,['dow'=>2],['store'=>'イノ','tf'=>'10:00','tt'=>'17:00']), ['時間変更']);
$partStaff = ['home_store'=>'イノ','off_days'=>'0,1','work_from'=>'9:00','work_to'=>'17:00'];
$partEntry = ['store'=>SHIFT_OFF,'tf'=>'9:00','tt'=>'10:00','changed'=>false];
check('一部休み 朝の休み後は出勤', shift_remaining_hours($partStaff,$partEntry), '10:00〜17:00');
check('一部休み 中抜けは前後の勤務', shift_remaining_hours($partStaff,['tf'=>'12:00','tt'=>'13:00']), '09:00〜12:00／13:00〜17:00');
check('一部休み 午後は休み', shift_remaining_hours($partStaff,['tf'=>'15:00','tt'=>'']), '09:00〜15:00');
check('一部休み 出勤側に勤務店舗を残す', shift_calendar_entry($partStaff,['dow'=>6],$partEntry,'all')['store'], 'イノ');
check('一部休み 休み表示は元の時間', shift_calendar_entry($partStaff,['dow'=>6],$partEntry,'changes')['tt'], '10:00');
check('一部休み 基本勤務全部を休むと出勤なし', shift_calendar_entry($partStaff,['dow'=>6],['store'=>SHIFT_OFF,'tf'=>'9:00','tt'=>'17:00'],'all')['store'], SHIFT_OFF);
check('勤務表現 終了だけ', shift_work_note('', '14:00'), '14時まで出勤');
check('勤務表現 開始だけ', shift_work_note('13:00', ''), '13時から出勤');
check('勤務表現 両方', shift_work_note('09:30', '14:00'), '9時30分〜14時出勤');
check('勤務表現 指定なし', shift_work_note('', ''), '');
$customerStaff=['name'=>'スタッフ','off_days'=>'0,1','home_store'=>'イノ','work_from'=>'9:00','work_to'=>'17:00'];
check('お客様休み表 日曜の基本休みは省く', shift_customer_holiday($customerStaff,['dow'=>0],null), null);
check('お客様休み表 月曜の基本休み', shift_customer_holiday($customerStaff,['dow'=>1],null)['full'], true);
check('お客様休み表 日曜の個別休みは載せる', shift_customer_holiday($customerStaff,['dow'=>0],['store'=>'休','tf'=>'','tt'=>''])['full'], true);
check('お客様休み表 日曜の一部休みも載せる', shift_customer_holiday($customerStaff,['dow'=>0],['store'=>'休','tf'=>'9:00','tt'=>'10:00'])['note'], '9〜10時休み');
check('お客様休み表 休日出勤は休みに載せない', shift_customer_holiday($customerStaff,['dow'=>0],['store'=>'イノ','tf'=>'','tt'=>'']), null);
check('お客様休み表 一部休み', shift_customer_holiday($customerStaff,['dow'=>6],['store'=>'休','tf'=>'9:00','tt'=>'10:00'])['note'], '9〜10時休み');
check('お客様休み表 短縮勤務', shift_customer_holiday($customerStaff,['dow'=>6],['store'=>'イノ','tf'=>'','tt'=>'14:00'])['note'], '14時まで出勤');
check('お客様休み表 12か月分のテーマ', count(shift_customer_themes()), 12);
check('職種 スタイリスト', shift_staff_role('stylist'), 'stylist');
check('職種 アシスタント', shift_staff_role('assistant'), 'assistant');
check('職種 既存は未設定', shift_staff_role(''), '');
try { shift_staff_role('unknown'); check('職種 不正な値を拒否', false, true); } catch (HttpError $e) { check('職種 不正な値を拒否', $e->status, 400); }
$holidayStaff=['name'=>'テスト','home_store'=>'イノ','off_days'=>'0,1','holiday_off'=>1,'work_from'=>'9:00','work_to'=>'17:00'];
$holidayDay=['dow'=>2,'date'=>'2026-11-03','hol'=>'文化の日'];
check('祝日休み 週の勤務日でも休み', shift_is_default_off($holidayStaff,$holidayDay), true);
check('祝日休み 設定しなければ通常勤務', shift_is_default_off(array_replace($holidayStaff,['holiday_off'=>0]),$holidayDay), false);
check('祝日休み 振替休日も対象', shift_is_default_off($holidayStaff,['dow'=>1,'date'=>'2027-03-22']), true);
check('祝日休み 出勤表示から通常は除外', shift_calendar_entry($holidayStaff,$holidayDay,null,'all'), null);
check('祝日休み 個別出勤を優先', shift_calendar_entry($holidayStaff,$holidayDay,['store'=>'イノ','tf'=>'','tt'=>''],'all')['store'], 'イノ');
check('祝日休み 個別出勤は休日出勤', shift_difference($holidayStaff,$holidayDay,['store'=>'イノ','tf'=>'','tt'=>'']), ['休日出勤']);
check('祝日休み お客様の休み表に反映', shift_customer_holiday($holidayStaff,$holidayDay,null)['full'], true);
check('店休日 月曜は勤務曜日設定にかかわらず通常休み', shift_is_default_off(['off_days'=>''],['dow'=>1]), true);
check('店舗の店休日 曜日設定より優先', shift_is_default_off(['home_store'=>'イノ','off_days'=>''],['dow'=>4,'date'=>'2027-01-01','closed_stores'=>['イノ'=>true]]), true);
check('店舗の店休日 別店舗には影響しない', shift_is_default_off(['home_store'=>'リッチ','off_days'=>''],['dow'=>4,'date'=>'2027-01-01','closed_stores'=>['イノ'=>true]]), false);
if ($n < 500) {
    echo "NG 確認した件数が少なすぎます（{$n} 件）。テストが途中で止まっている可能性があります\n";
    exit(1);
}
echo "{$n} 件を確認、失敗 {$fail} 件\n";
exit($fail ? 1 : 0);
