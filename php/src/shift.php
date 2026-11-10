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
    // スタッフごとの、毎週きまった休み（曜日の番号をカンマでつなぐ。0=日 … 6=土）
    $has = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shift_staff' AND column_name = 'off_days'");
    if (!$has) {
        run("ALTER TABLE shift_staff ADD COLUMN off_days VARCHAR(20) NOT NULL DEFAULT ''");
    }
    $done = true;
}

/** そのスタッフの、毎週の休みの曜日（番号の配列） */
function shift_off_days(array $s): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string)($s['off_days'] ?? ''))), static fn($w) => $w >= 0 && $w <= 6));
}

/** 第n月曜日 */
function nth_monday(int $y, int $m, int $n): DateTimeImmutable
{
    $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), new DateTimeZone('Asia/Tokyo'));
    $d = $d->modify('+' . ((8 - (int)$d->format('N')) % 7) . ' days');   // 最初の月曜日
    return $d->modify('+' . (($n - 1) * 7) . ' days');
}

/** 日本の祝日（その年の分）。[Y-m-d => 名前]。春分・秋分は計算式（1980〜2099年）。国民の休日・振替休日も入る */
function jp_holidays(int $y): array
{
    static $cache = [];
    if (isset($cache[$y])) {
        return $cache[$y];
    }
    $h = [];
    $add = static function (int $m, int $d, string $name) use (&$h, $y): void {
        $h[sprintf('%04d-%02d-%02d', $y, $m, $d)] = $name;
    };
    $mon = static fn(int $m, int $n): string => nth_monday($y, $m, $n)->format('Y-m-d');
    $add(1, 1, '元日');
    if ($y >= 2000) {
        $h[$mon(1, 2)] = '成人の日';
    } else {
        $add(1, 15, '成人の日');
    }
    $add(2, 11, '建国記念の日');
    if ($y >= 2020) {
        $add(2, 23, '天皇誕生日');
    }
    $n = $y - 1980;
    $add(3, (int)floor(20.8431 + 0.242194 * $n - intdiv($n, 4)), '春分の日');
    $add(4, 29, $y >= 2007 ? '昭和の日' : 'みどりの日');
    $add(5, 3, '憲法記念日');
    if ($y >= 2007) {
        $add(5, 4, 'みどりの日');
    }
    $add(5, 5, 'こどもの日');
    if ($y === 2019) {
        $add(5, 1, '休日（天皇即位日）');
    }
    if ($y === 2020) {
        $add(7, 23, '海の日');
    } elseif ($y === 2021) {
        $add(7, 22, '海の日');
    } elseif ($y >= 2003) {
        $h[$mon(7, 3)] = '海の日';
    }
    if ($y === 2020) {
        $add(8, 10, '山の日');
    } elseif ($y === 2021) {
        $add(8, 9, '山の日');
    } elseif ($y >= 2016) {
        $add(8, 11, '山の日');
    }
    $h[$mon(9, 3)] = '敬老の日';
    $add(9, (int)floor(23.2488 + 0.242194 * $n - intdiv($n, 4)), '秋分の日');
    if ($y === 2020) {
        $add(7, 24, 'スポーツの日');
    } elseif ($y === 2021) {
        $add(7, 23, 'スポーツの日');
    } elseif ($y >= 2022) {
        $h[$mon(10, 2)] = 'スポーツの日';
    }
    if ($y === 2019) {
        $add(10, 22, '休日（即位礼正殿の儀）');
    }
    $add(11, 3, '文化の日');
    $add(11, 23, '勤労感謝の日');
    $tz = new DateTimeZone('Asia/Tokyo');
    $dates = array_keys($h);
    sort($dates);
    foreach ($dates as $ds) {   // 国民の休日：祝日にはさまれた平日
        $d = new DateTimeImmutable($ds, $tz);
        $mid = $d->modify('+1 day')->format('Y-m-d');
        if (isset($h[$d->modify('+2 days')->format('Y-m-d')]) && !isset($h[$mid]) && $d->modify('+1 day')->format('w') !== '0') {
            $h[$mid] = '国民の休日';
        }
    }
    $dates = array_keys($h);
    sort($dates);
    foreach ($dates as $ds) {   // 振替休日：日曜が祝日のとき、次の祝日でない日
        $d = new DateTimeImmutable($ds, $tz);
        if ($d->format('w') === '0') {
            $x = $d->modify('+1 day');
            while (isset($h[$x->format('Y-m-d')])) {
                $x = $x->modify('+1 day');
            }
            if ((int)$x->format('Y') === $y) {
                $h[$x->format('Y-m-d')] = '振替休日';
            }
        }
    }
    ksort($h);
    return $cache[$y] = $h;
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
    return rows('SELECT id, name, home_store, is_active, off_days FROM shift_staff' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
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
    $hol = jp_holidays((int)$first->format('Y'));
    for ($d = 1; $d <= $n; $d++) {
        $dt = $first->setDate((int)$first->format('Y'), (int)$first->format('m'), $d);
        $w = (int)$dt->format('w');
        $days[] = ['date' => $dt->format('Y-m-d'), 'd' => $d, 'w' => SHIFT_WEEK[$w], 'sun' => $w === 0 || isset($hol[$dt->format('Y-m-d')]), 'sat' => $w === 6, 'today' => $dt->format('Y-m-d') === $today,
            'hol' => $hol[$dt->format('Y-m-d')] ?? '', 'dow' => $w];
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
            if ($e === null) {   // 入れていない日でも、毎週の休みの曜日なら「休」
                if (in_array((int)$day['dow'], shift_off_days($s), true)) {
                    $day['groups'][SHIFT_OFF][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'help' => false, 'from' => $s['home_store'], 'changed' => false];
                }
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
    // カレンダー：日曜はじまりの週ごとに並べる（月の前後の空きは null）
    $weeks = [];
    $row = array_fill(0, (int)$days[0]['dow'], null);
    foreach ($days as $day) {
        $row[] = $day;
        if (count($row) === 7) {
            $weeks[] = $row;
            $row = [];
        }
    }
    if ($row) {
        $weeks[] = array_pad($row, 7, null);
    }
    render('shift', ['nav' => shift_nav($first), 'weeks' => $weeks, 'staff' => $staff]);
}

/** 入れる画面：スタッフ×日の表。選ぶとすぐ保存される */
function h_shift_edit(): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $staff = shift_staff_list();
    $entries = shift_entries_of($first);
    $cells = [];
    $dflt = [];
    foreach ($staff as $s) {
        foreach (shift_days($first) as $day) {
            $cells[(int)$s['id']][$day['date']] = $entries[(int)$s['id']][$day['date']]['store'] ?? '';
            // 入れていない日が、毎週の休みの曜日なら、「休（定休）」として見せる
            $dflt[(int)$s['id']][$day['date']] = $cells[(int)$s['id']][$day['date']] === '' && in_array((int)$day['dow'], shift_off_days($s), true);
        }
    }
    render('shift_edit', ['nav' => shift_nav($first), 'days' => shift_days($first), 'staff' => $staff, 'cells' => $cells, 'dflt' => $dflt]);
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
    $staff = shift_staff_list(false);
    foreach ($staff as &$s) {
        $s['offs'] = shift_off_days($s);
    }
    unset($s);
    render('shift_staff', ['staff' => $staff, 'week' => SHIFT_WEEK]);
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

/** スタッフの、毎週きまった休みの曜日を保存する */
function h_shift_staff_off(int $id): void
{
    ensure_shift_tables();
    $days = [];
    foreach ((array)($_POST['off'] ?? []) as $w) {
        if (is_numeric($w) && (int)$w >= 0 && (int)$w <= 6) {
            $days[(int)$w] = (int)$w;
        }
    }
    sort($days);
    run('UPDATE shift_staff SET off_days = ? WHERE id = ?', [implode(',', $days), $id]);
    redirect_to('/shift/staff');
}

function h_shift_staff_toggle(int $id): void
{
    ensure_shift_tables();
    run('UPDATE shift_staff SET is_active = 1 - is_active WHERE id = ?', [$id]);
    redirect_to('/shift/staff');
}
