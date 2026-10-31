<?php
declare(strict_types=1);

// シフト管理。イノ・リッチの2店舗で、本店のスタッフがヘルプに入る日が見えるようにする。
const SHIFT_STORES = ['イノ', 'リッチ'];
const SHIFT_OFF = '休';
const SHIFT_CHANGED_DAYS = 3;   // 変更してから、この日数のあいだ「変更」の印を出す
const SHIFT_WEEK = ['日', '月', '火', '水', '木', '金', '土'];

/** 最初に開いた時に、表を作る（すでにあれば何もしない。在庫・発注の表には触らない） */
function ensure_shift_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    run('CREATE TABLE IF NOT EXISTS shift_staff (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(40) NOT NULL,
        home_store VARCHAR(10) NOT NULL DEFAULT \'イノ\',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT NOT NULL DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4');
    run('CREATE TABLE IF NOT EXISTS shift_entries (
        staff_id INT NOT NULL,
        work_date DATE NOT NULL,
        store VARCHAR(10) NOT NULL,
        prev_store VARCHAR(10) NULL,
        changed_at DATETIME NULL,
        PRIMARY KEY (staff_id, work_date),
        KEY idx_shift_date (work_date)
    ) DEFAULT CHARSET=utf8mb4');
    $done = true;
}

function shift_month(?string $m): DateTimeImmutable
{
    $tz = new DateTimeZone('Asia/Tokyo');
    if ($m !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
        return new DateTimeImmutable($m . '-01', $tz);
    }
    return new DateTimeImmutable('first day of this month 00:00', $tz);
}

function shift_staff_list(bool $onlyActive = true): array
{
    return rows('SELECT id, name, home_store, is_active FROM shift_staff' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
}

/** その月の、スタッフ×日の出勤先 [staff_id][Y-m-d] => [store, changed] */
function shift_entries_of(DateTimeImmutable $first): array
{
    $last = $first->modify('last day of this month');
    $limit = now_jst()->modify('-' . SHIFT_CHANGED_DAYS . ' days')->format('Y-m-d H:i:s');
    $map = [];
    foreach (rows('SELECT staff_id, work_date, store, prev_store, changed_at FROM shift_entries WHERE work_date BETWEEN ? AND ?',
        [$first->format('Y-m-d'), $last->format('Y-m-d')]) as $r) {
        $map[(int)$r['staff_id']][$r['work_date']] = [
            'store' => $r['store'],
            'changed' => $r['changed_at'] !== null && $r['changed_at'] >= $limit,
            'prev' => $r['prev_store'],
        ];
    }
    return $map;
}

function shift_days(DateTimeImmutable $first): array
{
    $days = [];
    $n = (int)$first->format('t');
    $today = now_jst()->format('Y-m-d');
    for ($d = 1; $d <= $n; $d++) {
        $dt = $first->setDate((int)$first->format('Y'), (int)$first->format('m'), $d);
        $w = (int)$dt->format('w');
        $days[] = ['date' => $dt->format('Y-m-d'), 'd' => $d, 'w' => SHIFT_WEEK[$w], 'sun' => $w === 0, 'sat' => $w === 6, 'today' => $dt->format('Y-m-d') === $today];
    }
    return $days;
}

function shift_nav(DateTimeImmutable $first): array
{
    return [
        'month' => $first->format('Y-m'),
        'label' => $first->format('Y年n月'),
        'prev' => $first->modify('-1 month')->format('Y-m'),
        'next' => $first->modify('+1 month')->format('Y-m'),
    ];
}

/** 見る画面：日ごとに、イノ・リッチ・休みの人が並ぶ。ヘルプ（本店と違う店に入る人）には印が付く */
function h_shift(): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $staff = shift_staff_list();
    $entries = shift_entries_of($first);
    $days = shift_days($first);
    foreach ($days as &$day) {
        $day['groups'] = ['イノ' => [], 'リッチ' => [], '休' => []];
        foreach ($staff as $s) {
            $e = $entries[(int)$s['id']][$day['date']] ?? null;
            if ($e === null) {
                continue;
            }
            $day['groups'][$e['store']][] = [
                'id' => (int)$s['id'], 'name' => $s['name'],
                'help' => $e['store'] !== SHIFT_OFF && $e['store'] !== $s['home_store'],
                'from' => $s['home_store'],
                'changed' => $e['changed'],
            ];
        }
        $day['any'] = array_filter($day['groups']) !== [];
    }
    unset($day);
    render('shift', ['nav' => shift_nav($first), 'days' => $days, 'staff' => $staff]);
}

/** 入れる画面：スタッフ×日の表。選ぶとすぐ保存される */
function h_shift_edit(): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $staff = shift_staff_list();
    $entries = shift_entries_of($first);
    $cells = [];
    foreach ($staff as $s) {
        foreach (shift_days($first) as $day) {
            $cells[(int)$s['id']][$day['date']] = $entries[(int)$s['id']][$day['date']]['store'] ?? '';
        }
    }
    render('shift_edit', ['nav' => shift_nav($first), 'days' => shift_days($first), 'staff' => $staff, 'cells' => $cells]);
}

function h_api_shift(): void
{
    ensure_shift_tables();
    $d = req_json();
    if ($d === null) {
        bad_input();
    }
    $sid = (int)($d['staff_id'] ?? 0);
    $date = (string)($d['date'] ?? '');
    $store = (string)($d['store'] ?? '');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($sid <= 0 || !$dt || $dt->format('Y-m-d') !== $date || !in_array($store, ['', ...SHIFT_STORES, SHIFT_OFF], true)) {
        bad_input();
    }
    if (!row('SELECT id FROM shift_staff WHERE id = ?', [$sid])) {
        respond_json(['ok' => false, 'error' => 'スタッフが見つかりません'], 404);
    }
    $now = now_jst()->format('Y-m-d H:i:s');
    transaction(function () use ($sid, $date, $store, $now) {
        $cur = row('SELECT store FROM shift_entries WHERE staff_id = ? AND work_date = ? FOR UPDATE', [$sid, $date]);
        if ($store === '') {
            if ($cur) {
                run('DELETE FROM shift_entries WHERE staff_id = ? AND work_date = ?', [$sid, $date]);
            }
        } elseif (!$cur) {
            run('INSERT INTO shift_entries (staff_id, work_date, store) VALUES (?,?,?)', [$sid, $date, $store]);
        } elseif ($cur['store'] !== $store) {
            run('UPDATE shift_entries SET prev_store = ?, store = ?, changed_at = ? WHERE staff_id = ? AND work_date = ?', [$cur['store'], $store, $now, $sid, $date]);
        }
    });
    respond_json(['ok' => true]);
}

function h_shift_staff(): void
{
    ensure_shift_tables();
    render('shift_staff', ['staff' => shift_staff_list(false)]);
}

function h_shift_staff_add(): void
{
    ensure_shift_tables();
    $name = mb_substr(trim(nfkc((string)($_POST['name'] ?? '')) ?? ''), 0, 40);
    $home = in_array($_POST['home_store'] ?? '', SHIFT_STORES, true) ? $_POST['home_store'] : 'イノ';
    if ($name === '') {
        throw new HttpError(400);
    }
    $max = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM shift_staff');
    run('INSERT INTO shift_staff (name, home_store, sort_order) VALUES (?,?,?)', [$name, $home, $max + 1]);
    redirect_to('/shift/staff');
}

function h_shift_staff_toggle(int $id): void
{
    ensure_shift_tables();
    run('UPDATE shift_staff SET is_active = 1 - is_active WHERE id = ?', [$id]);
    redirect_to('/shift/staff');
}
