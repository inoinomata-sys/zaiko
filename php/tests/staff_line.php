<?php
declare(strict_types=1);
// Default: isolated in-memory SQLite. STAFF_LINE_TEST_MYSQL=1 uses only CI's test DB.
// No real LINE request is ever made: each send is injected and inspected.
date_default_timezone_set('Asia/Tokyo');
define('BASE_DIR', dirname(__DIR__));
$GLOBALS['CONFIG'] = ['db_name' => 'staff_line_test', 'staff_line' => ['delivery_enabled' => false]];
$mysql = getenv('STAFF_LINE_TEST_MYSQL') === '1';
if ($mysql) {
    if (getenv('CI') !== 'true') { exit("MySQL tests are restricted to CI\n"); }
    $GLOBALS['CONFIG'] = require BASE_DIR . '/config.php';
    $testHost = (string)($GLOBALS['CONFIG']['db_host'] ?? '');
    if ($testHost !== '127.0.0.1' && $testHost !== 'localhost;unix_socket=/private/tmp/ino-sl-01a1/mysql.sock') { exit("MySQL tests refuse remote databases\n"); }
    $GLOBALS['CONFIG']['staff_line'] = ['delivery_enabled' => false];
    require BASE_DIR . '/src/db.php';
} else {
    $GLOBALS['test_pdo'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    function db(): PDO { return $GLOBALS['test_pdo']; }
    function test_sql(string $sql): string {
        $sql = preg_replace('/\b(?:INT|BIGINT) AUTO_INCREMENT PRIMARY KEY/', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/,?\s*KEY idx_\w+ \([^)]*\)/', '', $sql);
        $sql = preg_replace('/(?: ENGINE=InnoDB)? DEFAULT CHARSET=utf8mb4/', '', $sql);
        $sql = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql);
        $sql = str_replace("CONCAT('superseded:', id)", "('superseded:' || id)", $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE snapshot = VALUES(snapshot), job_id = VALUES(job_id)', 'ON CONFLICT(stream, group_id, work_date) DO UPDATE SET snapshot = excluded.snapshot, job_id = excluded.job_id', $sql);
        $sql = str_replace('ON DUPLICATE KEY UPDATE active = IF(VALUES(event_timestamp) > event_timestamp, VALUES(active), active), event_timestamp = GREATEST(event_timestamp, VALUES(event_timestamp))', 'ON CONFLICT(group_id) DO UPDATE SET active = CASE WHEN excluded.event_timestamp > event_timestamp THEN excluded.active ELSE active END, event_timestamp = MAX(event_timestamp, excluded.event_timestamp)', $sql);
        return $sql;
    }
    function q(string $sql, array $params = []): PDOStatement { $st = db()->prepare(test_sql($sql)); $st->execute(array_map(static fn($v) => is_bool($v) ? (int)$v : $v, $params)); return $st; }
    function run(string $sql, array $params = []): int { return q($sql, $params)->rowCount(); }
    function rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
    function row(string $sql, array $params = []): ?array { return q($sql, $params)->fetch() ?: null; }
    function val(string $sql, array $params = []): mixed {
        if (str_contains($sql, 'information_schema.') || str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) { return 1; }
        return q($sql, $params)->fetchColumn();
    }
    function transaction(callable $fn): mixed { db()->beginTransaction(); try { $r = $fn(); db()->commit(); return $r; } catch (Throwable $e) { db()->rollBack(); throw $e; } }
}
require BASE_DIR . '/src/util.php';
require BASE_DIR . '/src/shift.php';
require BASE_DIR . '/src/schedule.php';
require BASE_DIR . '/src/line.php';
require BASE_DIR . '/src/admin.php';
require BASE_DIR . '/src/staff_line.php';
if (!$mysql) {
    run("CREATE TABLE shift_staff (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, home_store TEXT, sort_order INT DEFAULT 0, is_active INT DEFAULT 1, off_days TEXT DEFAULT '', work_from TEXT DEFAULT '', work_to TEXT DEFAULT '', role TEXT DEFAULT '', holiday_off INT DEFAULT 0)");
    run('CREATE TABLE shift_entries (staff_id INT, work_date TEXT, store TEXT, time_from TEXT, time_to TEXT, PRIMARY KEY(staff_id,work_date))');
}
ensure_shift_tables();
ensure_staff_line_tables();
if ($mysql) {
    // Dedicated CI database only, and isolated fixture IDs.
    run('DELETE FROM shift_entries WHERE staff_id = 99001');
    run('DELETE FROM shift_staff WHERE id = 99001');
    run('DELETE FROM schedule_rules WHERE id IN (99001,99002)');
    foreach (['staff_line_jobs','staff_line_baselines','staff_line_visibility','staff_line_attempts','staff_line_audit','staff_line_groups'] as $table) { run("DELETE FROM {$table}"); }
}
run("UPDATE staff_line_settings SET enabled = 0, mode = 'approval', group_id = '', include_monday = 0, notify_changes = 1, revision = 1");
$n = 0;
function expect(bool $ok, string $label): void { global $n; $n++; if (!$ok) { throw new RuntimeException("FAIL: {$label}"); } echo "OK {$label}\n"; }
function at(string $time): DateTimeImmutable { return new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo')); }
function clock_at(string $time): void { $GLOBALS['staff_line_test_clock'] = at($time); }
expect(file_get_contents(BASE_DIR . '/src/staff_line.mysql.sql') === file_get_contents(BASE_DIR . '/sql/staff_line.mysql.sql'), 'runtime and review schemas stay identical');
$GLOBALS['CONFIG']['admin_pass'] = 'test-admin-only';
$_COOKIE['zadmin'] = admin_cookie_value('test-admin-only', time()+3600);
$_POST = ['csrf'=>hash_hmac('sha256','staff-line-form|'.$_COOKIE['zadmin'],'test-admin-only')];
sl_admin(); expect(true, 'existing admin unlock and CSRF allow operation without extra password');
$_POST['csrf'] = 'wrong';
try { sl_admin(); expect(false,'invalid CSRF blocked'); } catch (HttpError $e) { expect($e->status === 403,'invalid CSRF blocked'); }
$_POST['csrf'] = hash_hmac('sha256','staff-line-form|other-cookie','test-admin-only');
try { sl_admin(); expect(false,'CSRF from another admin session blocked'); } catch (HttpError $e) { expect($e->status === 403,'CSRF from another admin session blocked'); }
expect(!admin_cookie_ok('test-admin-only', admin_cookie_value('test-admin-only',time()-1)), 'expired admin unlock rejected');
$_POST = [];
$group = 'C' . str_repeat('a', 32);
run('INSERT INTO staff_line_groups (group_id, active, event_timestamp) VALUES (?,1,1)', [$group]);
run("INSERT INTO shift_staff (id,name,home_store,off_days,work_from,work_to) VALUES (99001,'テスト花子','イノ','0','9:00','18:00')");
run("INSERT INTO shift_entries (staff_id,work_date,store) VALUES (99001,'2026-11-14','イノ')");
run("INSERT INTO schedule_rules (id,title,store,kind,once_date) VALUES (99001,'店舗清掃','イノ','once','2026-11-14')");
expect(sl_snapshot('2026-11-14', 'store') === [] && sl_snapshot('2026-11-14', 'shift') === [], 'new records are private by default');
run("INSERT INTO staff_line_visibility (kind,entity_id,is_public) VALUES ('staff',99001,1),('rule',99001,1)");
$store = sl_snapshot('2026-11-14', 'store'); $shift = sl_snapshot('2026-11-14', 'shift');
expect(isset($store['rule:99001']) && !isset($store['staff:99001']), 'store schedules exclude staff shifts');
expect($shift === [], 'standalone shift stream remains disabled');
run("UPDATE shift_entries SET store='休',time_from='10:00',time_to='13:00' WHERE staff_id=99001 AND work_date='2026-11-14'");
expect(!isset(sl_snapshot('2026-11-14')['exception:99001']), 'work exceptions private until explicitly allowed');
run("INSERT INTO staff_line_visibility (kind,entity_id,is_public) VALUES ('exception',99001,1)");
$exception = sl_snapshot('2026-11-14')['exception:99001'] ?? [];
expect(str_contains($exception['label'] ?? '', '休み') && str_contains($exception['value'] ?? '', '10:00〜13:00だけ休み'), 'normally working staff partial absence shared with hours');
run("UPDATE shift_entries SET store='リッチ',time_from='11:00',time_to='16:00' WHERE staff_id=99001 AND work_date='2026-11-14'");
$exception = sl_snapshot('2026-11-14')['exception:99001'] ?? [];
expect(str_contains($exception['label'] ?? '', 'ヘルプ') && str_contains($exception['value'] ?? '', 'イノ→リッチ／11:00〜16:00'), 'help destination and individual hours shared');
run("INSERT INTO shift_entries (staff_id,work_date,store,time_from,time_to) VALUES (99001,'2026-11-15','イノ','12:00','17:00')");
$exception = sl_snapshot('2026-11-15')['exception:99001'] ?? [];
expect(str_contains($exception['label'] ?? '', '通常休み→出勤') && str_contains($exception['value'] ?? '', '12:00〜17:00'), 'normally off staff exceptional attendance shared');
run("UPDATE shift_entries SET store='休' WHERE staff_id=99001 AND work_date='2026-11-15'");
expect(!isset(sl_snapshot('2026-11-15')['exception:99001']), 'normal weekly rest is not shared');
run("UPDATE shift_entries SET store='イノ',time_from=NULL,time_to=NULL WHERE staff_id=99001 AND work_date='2026-11-14'");
expect(!isset(sl_snapshot('2026-11-14')['exception:99001']), 'ordinary attendance is not shared');
run("UPDATE shift_entries SET store='リッチ' WHERE staff_id=99001 AND work_date='2026-11-14'");
$exception = sl_snapshot('2026-11-14')['exception:99001'] ?? [];
expect(str_contains($exception['value'] ?? '', '9:00〜18:00'), 'help uses basic hours when unspecified');
run("UPDATE staff_line_visibility SET is_public=0 WHERE kind='exception'");
expect(!isset(sl_snapshot('2026-11-14')['exception:99001']), 'revoking staff sharing excludes current content');
run("DELETE FROM shift_entries WHERE staff_id=99001 AND work_date='2026-11-15'");
run("UPDATE shift_entries SET store='イノ',time_from=NULL,time_to=NULL WHERE staff_id=99001 AND work_date='2026-11-14'");

$_GET['stream'] = 'shift';
try { sl_stream(); expect(false, 'shift sharing settings rejected'); } catch (HttpError $e) { expect($e->status === 400, 'shift sharing settings rejected'); }
$_GET = [];
foreach (['2026-11-14 20:00','2026-11-15 20:00','2026-11-16 19:59'] as $t) { expect(!sl_daily_due(at($t)), "no regular delivery {$t}"); }
foreach (['2026-11-16 20:00','2026-11-17 20:00','2026-11-18 20:00','2026-11-19 20:00','2026-11-20 20:00'] as $t) { expect(sl_daily_due(at($t)), "regular delivery {$t}"); }
expect(sl_daily_due(new DateTimeImmutable('2026-11-16 11:00:00+00:00')), 'UTC converted to JST');
expect(sl_dates(at('2026-11-13'), false, fn($d) => true) === ['2026-11-14','2026-11-15','2026-11-16'], 'Friday includes registered Sunday and Monday');
expect(sl_dates(at('2026-11-16'), true, fn($d) => true) === ['2026-11-17'], 'Monday evening sends Tuesday');
expect(sl_dates(at('2026-11-13'), true, fn($d) => false) === ['2026-11-14'], 'unregistered weekend days excluded');
expect(sl_dates(at('2027-01-29'), true, fn($d) => true) === ['2027-01-30','2027-01-31','2027-02-01'], 'month boundary');
expect(sl_result(200, []) === 'accepted' && sl_result(409,['x-line-accepted-request-id'=>'abc']) === 'accepted', 'accepted response and duplicate acknowledgement');
expect(sl_result(409,[]) === 'failed' && sl_result(401,[]) === 'failed' && sl_result(429,[]) === 'failed', 'permanent failures do not auto retry');
expect(sl_result(0,[]) === 'retry' && sl_result(503,[]) === 'retry', 'transient failures retry');
$body = '{"events":[]}'; $sig = base64_encode(hash_hmac('sha256',$body,'secret',true));
expect(line_signature_ok($body,'secret',$sig), 'valid webhook signature');
$webGroup = 'C' . str_repeat('b',32);
sl_record_groups([['type'=>'join','timestamp'=>200,'source'=>['type'=>'group','groupId'=>$webGroup]], ['type'=>'message','timestamp'=>300,'source'=>['type'=>'user','userId'=>'U'.str_repeat('a',32)]]]);
expect((int)val('SELECT active FROM staff_line_groups WHERE group_id = ?',[$webGroup]) === 1, 'signed group event registers a candidate');
sl_record_groups([['type'=>'leave','timestamp'=>400,'source'=>['type'=>'group','groupId'=>$webGroup]], ['type'=>'join','timestamp'=>200,'source'=>['type'=>'group','groupId'=>$webGroup]]]);
expect((int)val('SELECT active FROM staff_line_groups WHERE group_id = ?',[$webGroup]) === 0, 'old webhook replay cannot reactivate a left group');
expect((int)val('SELECT COUNT(*) FROM staff_line_groups') === 2, 'personal webhook events are ignored');
if ($mysql) {
    sl_lock(function () {
        $c = $GLOBALS['CONFIG'];
        $other = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass']);
        $key = 'staff-line-' . substr(hash('sha256', $c['db_name']), 0, 32);
        $st = $other->prepare('SELECT GET_LOCK(?,0)'); $st->execute([$key]);
        expect((int)$st->fetchColumn() === 0, 'a second MySQL worker cannot acquire the send lock');
    });
}

expect(!line_signature_ok($body.' ','secret',$sig) && !line_signature_ok($body,'',$sig), 'modified body and missing secret rejected');
$parts = sl_messages(str_repeat('😀',2500));
expect(count($parts) === 2 && implode('',array_column($parts,'text')) === str_repeat('😀',2500), 'UTF-16 aware message split');
try { sl_messages(str_repeat('あ',23000)); expect(false,'oversized message rejected'); } catch (LengthException) { expect(true,'oversized message rejected'); }
$deleted = sl_text(['2026-11-14'=>[]], ['2026-11-14'=>['private'=>['label'=>'非公開の氏名','value'=>'私的な予定']]]);
expect(!str_contains($deleted,'非公開の氏名') && !str_contains($deleted,'私的な予定') && str_contains($deleted,'削除'), 'removed or private content never repeated');
run("UPDATE staff_line_settings SET enabled = 1, mode = 'approval', group_id = ? WHERE stream = 'store'", [$group]);
clock_at('2026-11-13 19:30:00');
$calls = [];
$transport = function($payload,$key) use (&$calls) { $calls[] = [$payload,$key]; return [200,[]]; };
sl_tick($transport, sl_clock());
expect(count($calls) === 0, 'approval mode never sends without approval');
$j = row("SELECT * FROM staff_line_jobs WHERE stream = 'store'");
expect($j['state'] === 'pending' && str_contains($j['body'],'店舗清掃') && !str_contains($j['body'],'花子'), 'separate store preview queued');
expect(str_starts_with($j['body'], "こんばんは🌙\n\n本日も一日お疲れ様でございました。\n\n明日以降の店舗スケジュールをお知らせいたします📅\n\n")
    && str_ends_with($j['body'], "\n\nいつもありがとうございます"), 'daily message includes the requested greeting and closing once per schedule');
run("UPDATE staff_line_jobs SET body = '旧文面', state = 'ready' WHERE id = ?", [$j['id']]);
$updated = sl_refresh(row('SELECT * FROM staff_line_jobs WHERE id = ?', [$j['id']]), sl_settings('store'));
expect($updated['body'] === $j['body'] && $updated['state'] === 'pending', 'unsent older wording is refreshed for review');
run("UPDATE staff_line_jobs SET state = 'ready' WHERE id = ?",[$j['id']]);
sl_tick($transport, sl_clock());
expect(count($calls) === 0, 'early approval waits until 20:00 JST');
clock_at('2026-11-13 20:00:00');
run("UPDATE schedule_rules SET title = '店舗清掃の変更' WHERE id = 99001");
sl_tick($transport, sl_clock());
expect(count($calls) === 0 && val('SELECT state FROM staff_line_jobs WHERE id = ?',[$j['id']]) === 'pending', 'changed approved content requires approval again');
run("UPDATE staff_line_jobs SET state = 'ready' WHERE id = ?",[$j['id']]);
sl_tick($transport, sl_clock()); sl_tick($transport, sl_clock());
expect(count($calls) === 1, 'one send across repeated ticks');
expect((int)val('SELECT COUNT(*) FROM staff_line_baselines') === 1, 'successful baseline stored');
run("UPDATE schedule_rules SET title = '店舗清掃の再変更' WHERE id = 99001");
run("UPDATE staff_line_settings SET mode = 'auto' WHERE stream = 'store'");
clock_at('2026-11-13 20:05:00');
$failCalls = [];
$fail = function($payload,$key) use (&$failCalls) { $failCalls[] = [$payload,$key]; return [503,[]]; };
sl_tick($fail, sl_clock());
expect(count($failCalls) === 1, 'change notification tried');
sl_tick($fail, sl_clock());
expect(count($failCalls) === 1, 'exponential backoff respected');
clock_at('2026-11-13 20:07:00');
sl_tick($fail, sl_clock());
expect(count($failCalls) === 2 && $failCalls[0] === $failCalls[1], 'retry freezes both recipient payload and UUID');
$duplicate = fn($p,$k) => [409,['x-line-accepted-request-id'=>'first']];
clock_at('2026-11-13 20:10:00'); sl_tick($duplicate, sl_clock());
expect((int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE state = 'accepted'") === 2, 'duplicate acknowledged as accepted');
run('DELETE FROM schedule_rules WHERE id = 99001');
sl_tick($transport, sl_clock());
expect(count($calls) === 2 && str_contains($calls[1][0],'削除') && !str_contains($calls[1][0],'清掃'), 'deleted source produces safe deletion notification');
run("INSERT IGNORE INTO staff_line_settings (stream,enabled,mode,group_id) VALUES ('shift',1,'auto',?)",[$group]);
run("UPDATE staff_line_settings SET enabled=1,mode='auto',group_id=? WHERE stream='shift'",[$group]);
run("INSERT INTO staff_line_jobs (slot,kind,stream,group_id,snapshot,before_snapshot,body,content_hash,retry_key,state,expires_at,scheduled_at,created_at) SELECT 'legacy-shift','daily','shift',group_id,snapshot,before_snapshot,body,content_hash,?,'ready',expires_at,scheduled_at,created_at FROM staff_line_jobs ORDER BY id LIMIT 1",[line_uuid4()]);
$beforeShift = count($calls); sl_tick($transport, sl_clock());
expect(count($calls) === $beforeShift && (int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE stream = 'shift' AND state <> 'cancelled'") === 0, 'legacy shift setting cannot generate any LINE jobs');
expect((int)val("SELECT enabled FROM staff_line_settings WHERE stream = 'shift'") === 0, 'legacy shift sharing automatically disabled');
run("INSERT INTO schedule_rules (id,title,store,kind,once_date) VALUES (99001,'公開確認','イノ','once','2026-11-14')");
clock_at('2026-11-13 20:15:00'); sl_tick($fail, sl_clock());
expect((int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE state = 'retry'") === 1, 'failed store change queued');
run("DELETE FROM staff_line_visibility WHERE kind = 'rule'");
clock_at('2026-11-13 20:20:00'); sl_tick($transport, sl_clock());
expect((int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE state = 'uncertain'") === 1, 'privacy change stops retry of previously attempted payload');
run('UPDATE staff_line_groups SET active = 0');
run("INSERT INTO schedule_rules (id,title,store,kind,once_date) VALUES (99002,'連絡','イノ','once','2026-11-14')");
run("INSERT INTO staff_line_visibility (kind,entity_id,is_public) VALUES ('rule',99002,1)");
$beforeCalls = count($calls); sl_tick($transport, sl_clock());
expect(count($calls) === $beforeCalls, 'bot leaving group stops transmission');
run('UPDATE staff_line_groups SET active = 1 WHERE group_id = ?', [$group]);
$beforeCalls = count($calls); sl_tick(null, sl_clock());
expect(count($calls) === $beforeCalls && (int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE state = 'ready'") > 0, 'server delivery gate prevents real HTTP');
clock_at('2026-11-15 08:55:00'); sl_tick($transport, sl_clock());
expect((int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE slot LIKE 'daily:%2026-11-15%'") === 0, 'Sunday creates no daily jobs');
expect((int)val("SELECT COUNT(*) FROM staff_line_jobs WHERE state = 'expired'") > 0, 'unapproved jobs expire at end of day');
echo "PASS {$n} checks (" . ($mysql ? 'MySQL' : 'SQLite isolated adapter') . ")\n";
