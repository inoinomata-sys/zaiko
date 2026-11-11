<?php
declare(strict_types=1);

// スタッフ共有専用。発注用のLINE設定・連絡先とは混ぜない。
function sl_config(): array { return (array)($GLOBALS['CONFIG']['staff_line'] ?? []); }
function sl_json(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function sl_hash(mixed $v): string { return hash('sha256', sl_json($v)); }
function sl_now(): string { return sl_clock()->format('Y-m-d H:i:s'); }
function sl_clock(): DateTimeImmutable { return $GLOBALS['staff_line_test_clock'] ?? now_jst(); }

function ensure_staff_line_tables(): void
{
    static $done = false;
    if ($done) { return; }
    ensure_schedule_tables();
    ensure_shift_tables();
    $sql = file_get_contents(__DIR__ . '/staff_line.mysql.sql');
    if ($sql === false) { throw new RuntimeException('共有設定のDB定義がありません'); }
    foreach (explode(';', $sql) as $statement) {
        if (trim($statement) !== '') { run($statement); }
    }
    $done = true;
}

function sl_stream(): string
{
    $stream = (string)($_POST['stream'] ?? $_GET['stream'] ?? 'store');
    if ($stream !== 'store') { throw new HttpError(400); }
    return $stream;
}

function sl_settings(string $stream = 'store'): array
{
    if ($stream !== 'store') { throw new HttpError(400); }
    return row('SELECT * FROM staff_line_settings WHERE stream = ?', [$stream]) ?? [];
}

// 同じDB上の全ワーカー・管理操作を直列化。API応答前にプロセスが停止しても同じUUIDで再送する。
function sl_lock(callable $fn): mixed
{
    $key = 'staff-line-' . substr(hash('sha256', (string)($GLOBALS['CONFIG']['db_name'] ?? '')), 0, 32);
    if ((int)val('SELECT GET_LOCK(?, 0)', [$key]) !== 1) { throw new HttpError(409); }
    try { return $fn(); } finally { val('SELECT RELEASE_LOCK(?)', [$key]); }
}

function sl_admin(): void
{
    require_admin();
    // 既存の「3回間違えると解除」では外部送信を許可しない。専用の管理者パスワードを毎回確認する。
    $pass = (string)(sl_config()['admin_password'] ?? '');
    if ($pass === '' || !hash_equals($pass, (string)($_POST['staff_password'] ?? ''))) { throw new HttpError(403); }
    $cookie = (string)($_COOKIE['zadmin'] ?? '');
    $expected = hash_hmac('sha256', 'staff-line-form|' . $cookie, $pass);
    if (!hash_equals($expected, (string)($_POST['csrf'] ?? ''))) { throw new HttpError(403); }
}

function sl_daily_due(DateTimeImmutable $now): bool
{
    $now = $now->setTimezone(new DateTimeZone('Asia/Tokyo'));
    return in_array((int)$now->format('N'), [1,2,3,4,5], true) && $now->format('H:i') >= '20:00';
}

function sl_dates(DateTimeImmutable $now, bool $monday, callable $registered): array
{
    $now = $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->setTime(0, 0);
    $dates = [$now->modify('+1 day')->format('Y-m-d')];
    if ($now->format('N') === '5') {
        foreach ([2, 3] as $offset) {
            $date = $now->modify('+' . $offset . ' days')->format('Y-m-d');
            if ($registered($date)) { $dates[] = $date; }
        }
    }
    return $dates;
}

/** 明示的に共有を許可したスタッフ・予定だけ。顧客、電話、メモ、休暇理由は取得しない。 */
/** 基本の勤務と異なる個別登録だけを共有。理由・自由メモは含めない。 */
function sl_work_exception(array $staff, array $entry, DateTimeImmutable $date): ?array
{
    $off = in_array((int)$date->format('w'), shift_off_days($staff), true);
    $store = $entry['store'];
    if ($store === SHIFT_OFF) {
        if ($off) { return null; }
        $time = shift_time_label($entry['time_from'] ?? '', $entry['time_to'] ?? '', false);
        return ['label' => $staff['name'] . '（休み）', 'value' => $staff['home_store'] . '／' . ($time === '' ? '終日休み' : $time . 'だけ休み')];
    }
    if (!in_array($store, SHIFT_STORES, true)) { return null; }
    $help = $store !== $staff['home_store'];
    if (!$off && !$help) { return null; }
    $tf = (string)($entry['time_from'] ?? ''); $tt = (string)($entry['time_to'] ?? '');
    $time = shift_time_label($tf !== '' || $tt !== '' ? $tf : $staff['work_from'], $tf !== '' || $tt !== '' ? $tt : $staff['work_to'], false);
    $tags = [];
    if ($off) { $tags[] = '通常休み→出勤'; }
    if ($help) { $tags[] = 'ヘルプ'; }
    return ['label' => $staff['name'] . '（' . implode('・', $tags) . '）', 'value' => ($help ? $staff['home_store'] . '→' : '') . $store . ($time === '' ? '' : '／' . $time)];
}

function sl_snapshot(string $date, string $stream = 'store'): array
{
    $d = new DateTimeImmutable($date, new DateTimeZone('Asia/Tokyo'));
    $items = [];
    if ($stream !== 'store') { return []; }
    if ($stream === 'store') {
    foreach (rows("SELECT r.* FROM schedule_rules r JOIN staff_line_visibility v ON v.kind = 'rule' AND v.entity_id = r.id AND v.is_public = 1 WHERE r.is_active = 1 ORDER BY r.sort_order, r.id") as $r) {
        if (schedule_matches($r, $d)) { $items['rule:' . $r['id']] = ['label' => $r['title'], 'value' => $r['store'] === '' ? '両店舗' : $r['store']]; }
    }
    }
    foreach (rows("SELECT s.*, e.store, e.time_from, e.time_to FROM shift_staff s JOIN shift_entries e ON e.staff_id = s.id JOIN staff_line_visibility v ON v.kind = 'exception' AND v.entity_id = s.id AND v.is_public = 1 WHERE s.is_active = 1 AND e.work_date = ? ORDER BY s.sort_order, s.id", [$date]) as $staff) {
        $item = sl_work_exception($staff, $staff, $d);
        if ($item !== null) { $items['exception:' . $staff['id']] = $item; }
    }
    ksort($items);
    return $items;
}

function sl_bundle(array $dates, string $stream = 'store'): array
{
    $out = [];
    foreach ($dates as $date) { $out[$date] = sl_snapshot($date, $stream); }
    return $out;
}

function sl_text(array $snapshot, ?array $before = null, string $stream = 'store'): string
{
    $title = $stream === 'shift' ? '出勤スケジュール' : '店舗スケジュール';
    $lines = ['【' . $title . ($before === null ? '' : 'の変更・削除') . '】'];
    foreach ($snapshot as $date => $items) {
        $part = [];
        if ($before === null) {
            foreach ($items as $item) { $part[] = $item['label'] . '：' . $item['value']; }
            if (!$part) { $part[] = '共有する予定はありません'; }
        } else {
            foreach ($items as $key => $item) {
                $old = $before[$date][$key] ?? null;
                if ($old !== $item) { $part[] = ($old === null ? '追加 ' : '変更 ') . $item['label'] . '：' . ($old === null ? '' : $old['value'] . ' → ') . $item['value']; }
            }
            foreach ($before[$date] ?? [] as $key => $old) {
                // 非公開に切り替えた内容を通知文に再掲しない。
                if (!isset($items[$key])) { $part[] = '削除・共有終了：1件（管理画面で確認）'; }
            }
        }
        if ($part) { $lines[] = $date . '（' . SHIFT_WEEK[(int)(new DateTimeImmutable($date))->format('w')] . '）'; array_push($lines, ...$part); }
    }
    return implode("\n", $lines);
}

/** LINE上限より保守的にUTF-16 4500単位で分割。5メッセージを超えるものは送信せず要確認。 */
function sl_messages(string $text): array
{
    $parts = []; $current = ''; $units = 0;
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $n = strlen(mb_convert_encoding($ch, 'UTF-16LE', 'UTF-8')) / 2;
        if ($units + $n > 4500) { $parts[] = ['type' => 'text', 'text' => $current]; $current = ''; $units = 0; }
        $current .= $ch; $units += $n;
    }
    if ($current !== '') { $parts[] = ['type' => 'text', 'text' => $current]; }
    if (count($parts) > 5) { throw new LengthException('共有内容が長すぎます。共有対象を減らしてください'); }
    return $parts;
}

function sl_enqueue(string $slot, string $kind, array $snapshot, ?array $before, array $s, string $expires, ?string $scheduled = null): void
{
    if ($s['stream'] !== 'store') { throw new HttpError(400); }
    $text = sl_text($snapshot, $before, $s['stream']);
    $state = $s['mode'] === 'auto' ? 'ready' : 'pending';
    try { sl_messages($text); } catch (LengthException) { $state = 'oversized'; }
    run("UPDATE staff_line_jobs SET slot = CONCAT('superseded:', id) WHERE slot = ? AND state = 'superseded' AND attempts = 0", [$slot]);
    run('INSERT IGNORE INTO staff_line_jobs (slot, kind, stream, group_id, snapshot, before_snapshot, body, content_hash, retry_key, state, expires_at, created_at, scheduled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$slot, $kind, $s['stream'], $s['group_id'], sl_json($snapshot), $before === null ? null : sl_json($before), $text, sl_hash([$snapshot, $before]), line_uuid4(), $state, $expires, sl_now(), $scheduled ?? sl_now()]);
}

function sl_generate(array $s, DateTimeImmutable $now): void
{
    $today = $now->format('Y-m-d');
    $expiry = $today . ' 23:59:59';
    if (sl_daily_due($now->setTime(20, 0))) {
        $dates = sl_dates($now, (bool)$s['include_monday'], static fn($date) => sl_snapshot($date, $s['stream']) !== []);
        sl_enqueue('daily:' . $s['stream'] . ':' . $today . ':' . substr(sl_hash($s['group_id']), 0, 20), 'daily', sl_bundle($dates, $s['stream']), null, $s, $expiry, $today . ' 20:00:00');
    }
    if (!$s['notify_changes']) { return; }
    $baselines = rows('SELECT * FROM staff_line_baselines WHERE stream = ? AND group_id = ? AND work_date >= ? ORDER BY work_date', [$s['stream'], $s['group_id'], $today]);
    foreach ($baselines as $base) {
        $before = [$base['work_date'] => json_decode($base['snapshot'], true, 512, JSON_THROW_ON_ERROR)];
        $current = sl_bundle([$base['work_date']], $s['stream']);
        if ($before === $current) { continue; }
        // 基準job IDも含める。A→B→A→Bを別々の変更として扱う。
        $slot = 'change:' . sl_hash([$s['stream'], $s['revision'], $base['job_id'], $current]);
        sl_enqueue($slot, 'change', $current, $before, $s, $expiry);
    }
}

/** 未送信プレビューは常に元データと照合。試行後の本文・宛先・UUIDは絶対に変更しない。 */
function sl_refresh(array $job, array $s): array
{
    if ((int)$job['attempts'] > 0) { return $job; }
    $snapshot = json_decode($job['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    $before = $job['before_snapshot'] === null ? null : json_decode($job['before_snapshot'], true, 512, JSON_THROW_ON_ERROR);
    if ($job['kind'] === 'daily') {
        $day = new DateTimeImmutable(substr($job['created_at'], 0, 10), new DateTimeZone('Asia/Tokyo'));
        $snapshot = sl_bundle(sl_dates($day, (bool)$s['include_monday'], static fn($date) => sl_snapshot($date, $s['stream']) !== []), $s['stream']);
    } else {
        foreach (array_keys($snapshot) as $date) {
            $b = row('SELECT snapshot FROM staff_line_baselines WHERE stream = ? AND group_id = ? AND work_date = ?', [$s['stream'], $job['group_id'], $date]);
            $before[$date] = $b ? json_decode($b['snapshot'], true, 512, JSON_THROW_ON_ERROR) : [];
        }
        $snapshot = sl_bundle(array_keys($snapshot), $s['stream']);
        if ($snapshot === $before) { run("UPDATE staff_line_jobs SET state = 'cancelled' WHERE id = ?", [$job['id']]); return array_replace($job, ['state' => 'cancelled']); }
    }
    $hash = sl_hash([$snapshot, $before]);
    if (!hash_equals($job['content_hash'], $hash)) {
        $state = $s['mode'] === 'auto' ? 'ready' : 'pending';
        $body = sl_text($snapshot, $before, $s['stream']);
        try { sl_messages($body); } catch (LengthException) { $state = 'oversized'; }
        run('UPDATE staff_line_jobs SET snapshot = ?, before_snapshot = ?, body = ?, content_hash = ?, state = ?, approved_by = NULL WHERE id = ?',
            [sl_json($snapshot), $before === null ? null : sl_json($before), $body, $hash, $state, $job['id']]);
        return array_replace($job, ['snapshot' => sl_json($snapshot), 'before_snapshot' => $before === null ? null : sl_json($before), 'body' => $body, 'content_hash' => $hash, 'state' => $state]);
    }
    return $job;
}

function sl_result(int $status, array $headers): string
{
    if ($status === 200 || ($status === 409 && !empty($headers['x-line-accepted-request-id']))) { return 'accepted'; }
    return $status === 0 || $status >= 500 ? 'retry' : 'failed';
}

function sl_http(string $payload, string $uuid): array
{
    $token = (string)(sl_config()['token'] ?? '');
    if ($token === '' || preg_match('/[\r\n]/', $token)) { throw new RuntimeException('スタッフ用LINEトークンが未設定です'); }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nX-Line-Retry-Key: {$uuid}\r\n", 'content' => $payload]]);
    @file_get_contents('https://api.line.me/v2/bot/message/push', false, $ctx);
    $status = 0; $headers = [];
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) { $status = (int)$m[1]; $headers = []; }
        elseif (str_contains($h, ':')) { [$k, $v] = explode(':', $h, 2); $headers[strtolower(trim($k))] = trim($v); }
    }
    return [$status, $headers];
}

function sl_send(array $job, array $s, DateTimeImmutable $now, callable $transport): void
{
    if ($job['stream'] !== 'store' || $s['stream'] !== 'store') { return; }
    $job = sl_refresh($job, $s);
    if ($job['scheduled_at'] > $now->format('Y-m-d H:i:s')) { return; }
    if (!in_array($job['state'], ['ready', 'retry'], true)) { return; }
    if ($job['group_id'] !== $s['group_id'] || !val('SELECT active FROM staff_line_groups WHERE group_id = ?', [$job['group_id']])) { return; }
    // 24時間の有効期間を越えた不明な送信は自動再送しない。
    if ($job['first_attempt_at'] !== null && $now->getTimestamp() - (new DateTimeImmutable($job['first_attempt_at']))->getTimestamp() >= 23 * 3600) {
        run("UPDATE staff_line_jobs SET state = 'uncertain' WHERE id = ?", [$job['id']]); return;
    }
    if ($job['expires_at'] < $now->format('Y-m-d H:i:s')) { run("UPDATE staff_line_jobs SET state = 'expired' WHERE id = ?", [$job['id']]); return; }
    if ((int)$job['attempts'] > 0 && sl_bundle(array_keys(json_decode($job['snapshot'], true, 512, JSON_THROW_ON_ERROR)), $s['stream']) !== json_decode($job['snapshot'], true, 512, JSON_THROW_ON_ERROR)) {
        run("UPDATE staff_line_jobs SET state = 'uncertain' WHERE id = ?", [$job['id']]); return;
    }
    $payload = sl_json(['to' => $job['group_id'], 'messages' => sl_messages($job['body'])]);
    // 送信前に試行を永続化。異常終了後も待機して同一リクエストを再送する。
    $next = $now->modify('+' . min(3600, 60 * (2 ** min(6, (int)$job['attempts']))) . ' seconds')->format('Y-m-d H:i:s');
    run("UPDATE staff_line_jobs SET state = 'retry', attempts = attempts + 1, first_attempt_at = COALESCE(first_attempt_at, ?), next_attempt_at = ? WHERE id = ?", [$now->format('Y-m-d H:i:s'), $next, $job['id']]);
    [$status, $headers] = $transport($payload, $job['retry_key']);
    $state = sl_result($status, $headers);
    transaction(function () use ($job, $state, $status, $now, $s) {
        run('UPDATE staff_line_jobs SET state = ?, http_status = ?, accepted_at = ? WHERE id = ?', [$state, $status, $state === 'accepted' ? $now->format('Y-m-d H:i:s') : null, $job['id']]);
        run('INSERT INTO staff_line_attempts (job_id, attempted_at, http_status, result) VALUES (?,?,?,?)', [$job['id'], $now->format('Y-m-d H:i:s'), $status, $state]);
        if ($state === 'accepted') {
            foreach (json_decode($job['snapshot'], true, 512, JSON_THROW_ON_ERROR) as $date => $items) {
                run('INSERT INTO staff_line_baselines (stream, group_id, work_date, snapshot, job_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE snapshot = VALUES(snapshot), job_id = VALUES(job_id)', [$s['stream'], $job['group_id'], $date, sl_json($items), $job['id']]);
            }
        }
    });
}

function sl_tick(?callable $transport = null, ?DateTimeImmutable $clock = null): void
{
    ensure_staff_line_tables();
    sl_lock(function () use ($transport, $clock) {
        $now = ($clock ?? sl_clock())->setTimezone(new DateTimeZone('Asia/Tokyo'));
        run("UPDATE staff_line_jobs SET state = CASE WHEN attempts = 0 THEN 'expired' ELSE 'uncertain' END WHERE state IN ('pending','ready','retry','oversized') AND expires_at < ?", [$now->format('Y-m-d H:i:s')]);
        // 旧版に出勤共有の設定・配信待ちがあっても、実行できないようにする。
        run("UPDATE staff_line_settings SET enabled = 0 WHERE stream <> 'store'");
        run("UPDATE staff_line_jobs SET state = 'cancelled' WHERE stream <> 'store' AND state IN ('pending','ready','retry','failed','oversized')");
        foreach (rows("SELECT * FROM staff_line_settings WHERE stream = 'store'") as $s) {
            if (!$s['enabled'] || $s['group_id'] === '') { continue; }
            sl_generate($s, $now);
            if (empty(sl_config()['delivery_enabled']) && $transport === null) { continue; }
            foreach (rows("SELECT * FROM staff_line_jobs WHERE stream = ? AND state IN ('ready','retry','oversized') AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY id LIMIT 20", [$s['stream'], $now->format('Y-m-d H:i:s')]) as $job) {
                sl_send($job, $s, $now, $transport ?? 'sl_http');
            }
        }
        run("UPDATE staff_line_jobs SET body = '', snapshot = '{}', before_snapshot = NULL WHERE created_at < ? AND state NOT IN ('pending','ready','retry')", [$now->modify('-90 days')->format('Y-m-d H:i:s')]);
        run('DELETE FROM staff_line_baselines WHERE work_date < ?', [$now->modify('-90 days')->format('Y-m-d')]);
    });
}

function h_staff_line_webhook(): void
{
    $body = (string)file_get_contents('php://input');
    if (!line_signature_ok($body, (string)(sl_config()['secret'] ?? ''), (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? ''))) { throw new HttpError(403); }
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) { throw new HttpError(400); }
    ensure_staff_line_tables();
    sl_record_groups($data['events']);
    echo 'OK';
}

function sl_record_groups(array $events): void
{
    foreach ($events as $ev) {
        $group = (string)($ev['source']['groupId'] ?? '');
        if (($ev['source']['type'] ?? '') !== 'group' || !preg_match('/^C[0-9a-f]{32}$/', $group)) { continue; }
        if (!in_array($ev['type'] ?? '', ['join','leave','message'], true)) { continue; }
        $stamp = (int)($ev['timestamp'] ?? 0);
        if ($stamp <= 0) { continue; }
        $active = $ev['type'] !== 'leave' ? 1 : 0;
        // 本文・発言者・プロフィールは保存しない。古い再配信でleaveを取り消さない。
        run('INSERT INTO staff_line_groups (group_id, active, event_timestamp) VALUES (?,?,?) ON DUPLICATE KEY UPDATE active = IF(VALUES(event_timestamp) > event_timestamp, VALUES(active), active), event_timestamp = GREATEST(event_timestamp, VALUES(event_timestamp))', [$group, $active, $stamp]);
    }
}

function sl_actor(): string
{
    return mb_substr((string)($GLOBALS['CONFIG']['basic_auth']['user'] ?? '管理者'), 0, 80);
}
function sl_audit(string $action, ?int $id = null): void
{
    run('INSERT INTO staff_line_audit (at, actor, action, job_id, stream) VALUES (?,?,?,?,?)', [sl_now(), sl_actor(), $action, $id, sl_stream()]);
}

function h_staff_line(): void
{
    require_admin();
    ensure_staff_line_tables();
    $s = sl_settings(sl_stream());
    // 画面を開くだけでは送信も配信キューの追加もしない。
    sl_lock(function () use ($s) {
        foreach (rows("SELECT * FROM staff_line_jobs WHERE stream = ? AND state IN ('pending','ready','oversized') AND attempts = 0", [$s['stream']]) as $job) { sl_refresh($job, $s); }
    });
    $date = (string)($_GET['date'] ?? now_jst()->format('Y-m-d'));
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
    if (!$dt || $dt->format('Y-m-d') !== $date) { throw new HttpError(400); }
    $dates = sl_dates($dt, (bool)$s['include_monday'], static fn($date) => sl_snapshot($date, $s['stream']) !== []);
    $visibility = [];
    foreach (rows('SELECT * FROM staff_line_visibility WHERE is_public = 1') as $v) { $visibility[$v['kind'] . ':' . $v['entity_id']] = true; }
    render('settings_staff_line', [
        'stream' => $s['stream'], 'stream_title' => $s['stream'] === 'shift' ? '出勤スケジュール' : '店舗スケジュール', 'settings' => $s, 'groups' => rows('SELECT * FROM staff_line_groups WHERE active = 1'),
        'rules' => schedule_rules_all(), 'staff' => shift_staff_list(), 'visibility' => $visibility,
        'csrf' => hash_hmac('sha256', 'staff-line-form|' . (string)($_COOKIE['zadmin'] ?? ''), (string)(sl_config()['admin_password'] ?? '')),
        'delivery_enabled' => !empty(sl_config()['delivery_enabled']),
        'configured' => !empty(sl_config()['admin_password']) && !empty(sl_config()['secret']) && !empty(sl_config()['token']),
        'preview' => sl_text(sl_bundle($dates, $s['stream']), null, $s['stream']), 'preview_date' => $date,
        'jobs' => rows('SELECT * FROM staff_line_jobs WHERE stream = ? ORDER BY id DESC LIMIT 100', [$s['stream']]),
        'audit' => rows('SELECT * FROM staff_line_audit WHERE stream = ? ORDER BY id DESC LIMIT 30', [$s['stream']]),
        'attempts' => rows('SELECT a.* FROM staff_line_attempts a JOIN staff_line_jobs j ON j.id = a.job_id WHERE j.stream = ? ORDER BY a.id DESC LIMIT 50', [$s['stream']]),
    ]);
}

function h_staff_line_save(): void
{
    sl_admin(); ensure_staff_line_tables();
    sl_lock(function () {
        $mode = (string)($_POST['mode'] ?? '');
        $group = (string)($_POST['group_id'] ?? '');
        if (!in_array($mode, ['approval','auto'], true) || ($group !== '' && !val('SELECT active FROM staff_line_groups WHERE group_id = ?', [$group]))) { throw new HttpError(400); }
        transaction(function () use ($mode, $group) {
            run('UPDATE staff_line_settings SET enabled = ?, mode = ?, group_id = ?, include_monday = ?, notify_changes = ?, revision = revision + 1 WHERE stream = ?', [isset($_POST['enabled']), $mode, $group, isset($_POST['include_monday']), isset($_POST['notify_changes']), sl_stream()]);
            run("DELETE FROM staff_line_visibility WHERE kind IN ('rule','exception')");
            foreach (['rule','exception'] as $kind) {
                foreach (array_unique((array)($_POST[$kind] ?? [])) as $id) {
                    if (ctype_digit((string)$id) && (int)$id > 0) { run('INSERT INTO staff_line_visibility (kind, entity_id, is_public) VALUES (?,?,1)', [$kind, (int)$id]); }
                }
            }
            // 設定変更は以前の承認を無効にする。試行済みは本文を変更せず手動確認へ。
            run("UPDATE staff_line_jobs SET state = CASE WHEN attempts = 0 THEN 'superseded' ELSE 'uncertain' END WHERE stream = ? AND state IN ('pending','ready','retry','failed')", [sl_stream()]);
            sl_audit('settings_saved');
        });
    });
    redirect_to('/settings/staff-line?stream=' . sl_stream());
}

function h_staff_line_prepare(): void
{
    sl_admin(); ensure_staff_line_tables();
    sl_lock(function () {
        $s = sl_settings(sl_stream());
        if ($s['enabled'] && $s['group_id'] !== '') { sl_generate($s, now_jst()); sl_audit('queue_prepared'); }
    });
    redirect_to('/settings/staff-line?stream=' . sl_stream());
}

function h_staff_line_action(int $id): void
{
    sl_admin(); ensure_staff_line_tables();
    sl_lock(function () use ($id) {
        $s = sl_settings(sl_stream());
        $j = row('SELECT * FROM staff_line_jobs WHERE id = ?', [$id]);
        if (!$j || $j['stream'] !== $s['stream']) { throw new HttpError(404); }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'cancel' && in_array($j['state'], ['pending','ready','retry','failed'], true)) {
            run("UPDATE staff_line_jobs SET state = 'cancelled' WHERE id = ?", [$id]); sl_audit('cancelled', $id); return;
        }
        if (!in_array($j['state'], ['pending','ready','retry','failed'], true)) { throw new HttpError(409); }
        $j = sl_refresh($j, $s);
        if (!$s['enabled'] || $j['group_id'] !== $s['group_id'] || !hash_equals($j['content_hash'], (string)($_POST['content_hash'] ?? '')) || $j['expires_at'] < sl_now()) { throw new HttpError(409); }
        if ($action === 'approve' && $j['state'] === 'pending') {
            run("UPDATE staff_line_jobs SET state = 'ready', approved_by = ? WHERE id = ?", [sl_actor(), $id]); sl_audit('approved', $id);
        } elseif ($action === 'retry' && in_array($j['state'], ['retry','failed'], true)) {
            if ($j['first_attempt_at'] === null || now_jst()->getTimestamp() - (new DateTimeImmutable($j['first_attempt_at']))->getTimestamp() >= 23 * 3600) { throw new HttpError(409); }
            run("UPDATE staff_line_jobs SET state = 'retry', next_attempt_at = ? WHERE id = ?", [sl_now(), $id]); sl_audit('retry_requested', $id);
        } else { throw new HttpError(409); }
    });
    redirect_to('/settings/staff-line?stream=' . sl_stream());
}
