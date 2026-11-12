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
    $hasRole = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shift_staff' AND column_name = 'role'");
    if (!$hasRole) { run("ALTER TABLE shift_staff ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT ''"); }
    $hasHoliday = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shift_staff' AND column_name = 'holiday_off'");
    if (!$hasHoliday) { run("ALTER TABLE shift_staff ADD COLUMN holiday_off TINYINT NOT NULL DEFAULT 0"); }
    // スタッフごとの、ふだんの勤務時間（例 9:00〜18:00）
    foreach (['work_from', 'work_to'] as $col) {
        $has = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shift_staff' AND column_name = '{$col}'");
        if (!$has) {
            run("ALTER TABLE shift_staff ADD COLUMN {$col} VARCHAR(5) NOT NULL DEFAULT ''");
        }
    }
    run('CREATE TABLE IF NOT EXISTS shift_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        work_date DATE NOT NULL,
        author VARCHAR(40) NOT NULL DEFAULT \'\',
        body VARCHAR(600) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_notes_date (work_date)
    ) DEFAULT CHARSET=utf8mb4');
    run('CREATE TABLE IF NOT EXISTS shift_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        staff_id INT NOT NULL,
        work_date DATE NOT NULL,
        old_text VARCHAR(40) NOT NULL DEFAULT \'\',
        new_text VARCHAR(40) NOT NULL DEFAULT \'\',
        KEY idx_shift_log_at (at)
    ) DEFAULT CHARSET=utf8mb4');
    // 休みの一部の時間（パートさんなど）：開始・終了の時刻（どちらか片方だけでもよい。空なら終日）
    foreach (['time_from', 'time_to'] as $col) {
        $has = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'shift_entries' AND column_name = ?", [$col]);
        if (!$has) {
            run("ALTER TABLE shift_entries ADD COLUMN {$col} VARCHAR(5) NULL");
        }
    }
    $done = true;
}

/** 'H:i' に整える（正しくなければ null。空は ''） */
function shift_time(mixed $v): ?string
{
    $v = trim((string)$v);
    if ($v === '') {
        return '';
    }
    return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m) ? sprintf('%d:%02d', (int)$m[1], (int)$m[2]) : null;
}

/** HTML time入力は必ず2桁時刻にする。保存済みの9:00も表示できる。 */
function shift_time_input(mixed $value): string
{
    $time = shift_time($value);
    return $time === null || $time === '' ? '' : str_pad($time, 5, '0', STR_PAD_LEFT);
}

/** 休みの時間の表示。$short なら「9〜13」のように短く */
function shift_time_label(?string $from, ?string $to, bool $short): string
{
    $f = static fn(?string $t): string => $t === null || $t === '' ? '' : ($short ? preg_replace('/:00$/', '', $t) : $t);
    if ($f($from) === '' && $f($to) === '') {
        return '';
    }
    return $f($from) . '〜' . $f($to);
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
    return rows('SELECT id, name, home_store, is_active, off_days, work_from, work_to, role, holiday_off FROM shift_staff' . ($onlyActive ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id');
}

/** 週の定休と祝日休みを共通判定する。個別登録は呼び出し側で優先する。 */
function shift_is_default_off(array $staff, array $day): bool
{
    if ((int)$day['dow'] === 1 || !empty($day['closed_stores'][$staff['home_store'] ?? '']) || !empty($GLOBALS['shift_closure_map'][$day['date'] ?? ''][$staff['home_store'] ?? ''])) { return true; }
    if (in_array((int)$day['dow'], shift_off_days($staff), true)) { return true; }
    if (empty($staff['holiday_off'])) { return false; }
    if (!empty($day['hol'])) { return true; }
    if (!empty($day['date'])) {
        $year = (int)substr($day['date'], 0, 4);
        return isset(jp_holidays($year)[$day['date']]);
    }
    return false;
}

/** その月の、スタッフ×日の出勤先 [staff_id][Y-m-d] => [store, changed] */
function shift_entries_of(DateTimeImmutable $first): array
{
    $last = $first->modify('last day of this month');
    $limit = now_jst()->modify('-' . SHIFT_CHANGED_DAYS . ' days')->format('Y-m-d H:i:s');
    $map = [];
    foreach (rows('SELECT staff_id, work_date, store, prev_store, changed_at, time_from, time_to FROM shift_entries WHERE work_date BETWEEN ? AND ?',
        [$first->format('Y-m-d'), $last->format('Y-m-d')]) as $r) {
        $map[(int)$r['staff_id']][$r['work_date']] = [
            'store' => $r['store'],
            'changed' => $r['changed_at'] !== null && $r['changed_at'] >= $limit,
            'prev' => $r['prev_store'],
            'tf' => (string)($r['time_from'] ?? ''), 'tt' => (string)($r['time_to'] ?? ''),
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

/** カレンダー：月曜はじまりの週ごとに並べる（月の前後の空きは null） */
function shift_weeks(array $days): array
{
    $weeks = [];
    $row = array_fill(0, ((int)$days[0]['dow'] + 6) % 7, null);
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
    return $weeks;
}

/** カレンダーの個別勤務時間を、から・までが分かる言葉で示す。 */
function shift_work_note(?string $from, ?string $to): string
{
    $clock = static function (?string $value): string {
        $value = shift_time($value);
        if ($value === null || $value === '') { return ''; }
        [$h,$m] = explode(':', $value);
        return (int)$h . '時' . ((int)$m === 0 ? '' : (int)$m . '分');
    };
    $a = $clock($from); $b = $clock($to);
    if ($a !== '' && $b !== '') { return $a . '〜' . $b . '出勤'; }
    if ($a !== '') { return $a . 'から出勤'; }
    if ($b !== '') { return $b . 'まで出勤'; }
    return '';
}

/** 基本勤務から一部休みの区間を引き、残る出勤時間を返す。 */
function shift_remaining_hours(array $staff, array $entry): string
{
    $minute = static function ($v, int $fallback): int {
        $t = shift_time($v);
        if ($t === null || $t === '') { return $fallback; }
        [$h,$m] = array_map('intval', explode(':', $t));
        return $h * 60 + $m;
    };
    if (($entry['tf'] ?? '') === '' && ($entry['tt'] ?? '') === '') { return ''; }
    $start = $minute($staff['work_from'] ?? '', 0);
    $end = $minute($staff['work_to'] ?? '', 1440);
    $offStart = $minute($entry['tf'] ?? '', 0);
    $offEnd = $minute($entry['tt'] ?? '', 1440);
    $fmt = static fn(int $v): string => sprintf('%02d:%02d', intdiv($v,60), $v%60);
    $parts = [];
    foreach ([[$start, min($end,$offStart)], [max($start,$offEnd),$end]] as [$a,$b]) {
        if ($a < $b) { $parts[] = ($a === 0 && empty($staff['work_from']) ? '' : $fmt($a)) . '〜' . ($b === 1440 ? '' : $fmt($b)); }
    }
    return implode('／', $parts);
}

/** 個別登録を基本シフトと比較する。登録しただけの同じ勤務は変更扱いにしない。 */
function shift_difference(array $staff, array $day, ?array $entry): array
{
    if ($entry === null) { return []; }
    $off = shift_is_default_off($staff, $day);
    $tags = [];
    if ($entry['store'] === SHIFT_OFF) {
        return $off ? [] : [(($entry['tf'] ?? '') !== '' || ($entry['tt'] ?? '') !== '') ? '一部休み' : '休み'];
    }
    if ($off) { $tags[] = '休日出勤'; }
    if ($entry['store'] !== $staff['home_store']) { $tags[] = 'ヘルプ'; }
    if (($entry['tf'] ?? '') !== '' || ($entry['tt'] ?? '') !== '') {
        if (shift_time_input($entry['tf'] ?? '') !== shift_time_input($staff['work_from'] ?? '') || shift_time_input($entry['tt'] ?? '') !== shift_time_input($staff['work_to'] ?? '')) { $tags[] = '時間変更'; }
    }
    return $tags;
}

/** 通常設定は全員表示でだけ補完し、個別登録を常に優先する。 */
function shift_calendar_entry(array $staff, array $day, ?array $entry, string $view): ?array
{
    if ($view !== 'all') {
        return $entry !== null && $entry['store'] === SHIFT_OFF ? $entry : null;
    }
    if ($entry !== null) {
        if ($entry['store'] !== SHIFT_OFF) { return $entry; }
        if (shift_is_default_off($staff, $day)) { return null; }
        $hours = shift_remaining_hours($staff, $entry);
        if ($hours === '') { return $entry; }
        return array_replace($entry, ['store' => $staff['home_store'], 'work_hours' => $hours, 'partial_off' => true]);
    }
    $off = shift_is_default_off($staff, $day);
    if ($off) { return null; }
    return ['store' => $staff['home_store'], 'changed' => false,
        'tf' => '', 'tt' => '', 'default' => true];
}

/** 見る画面：日ごとに、イノ・リッチ・休みの人が並ぶ。ヘルプ（本店と違う店に入る人）には印が付く */
function h_shift(): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $GLOBALS['shift_closure_map'] = schedule_closure_map($first);
    $view = ($_GET['view'] ?? '') === 'changes' ? 'changes' : 'all';
    $staff = shift_staff_list();
    $entries = shift_entries_of($first);
    $days = shift_days($first);
    $notes = [];
    foreach (rows('SELECT id, work_date, author, body FROM shift_notes WHERE work_date BETWEEN ? AND ? ORDER BY id', [$days[0]['date'], $days[count($days) - 1]['date']]) as $n) {
        $notes[substr((string)$n['work_date'], 0, 10)][] = $n;
    }
    foreach ($days as &$day) {
        $day['closed'] = (int)$day['dow'] === 1 || !empty($GLOBALS['shift_closure_map'][$day['date']]['イノ']);
        $day['notes'] = $notes[$day['date']] ?? [];
        $day['groups'] = ['イノ' => [], 'リッチ' => [], '休' => []];
        $day['exceptions'] = [];
        foreach ($staff as $s) {
            $raw = $entries[(int)$s['id']][$day['date']] ?? null;
            $difference = shift_difference($s, $day, $raw);
            if ($difference) {
                $hours = shift_time_label($raw['tf'], $raw['tt'], false);
                $day['exceptions'][] = ['name' => $s['name'], 'tags' => $difference, 'store' => $raw['store'], 'hours' => $hours];
            }
            $e = shift_calendar_entry($s, $day, $raw, $view);
            // 通常の勤務曜日・定休は設定として保持し、カレンダーには個別登録だけを載せる。
            if ($e === null) {
                continue;
            }
            $day['groups'][$e['store']][] = [
                'id' => (int)$s['id'], 'name' => $s['name'],
                'help' => $e['store'] !== SHIFT_OFF && $e['store'] !== $s['home_store'],
                'from' => $s['home_store'],
                'changed' => $e['changed'],
                'difference' => $difference,
                'partial_off' => !empty($e['partial_off']),
                'default' => !empty($e['default']),
                'work_note' => $e['store'] !== SHIFT_OFF && empty($e['partial_off']) ? shift_work_note($e['tf'], $e['tt']) : '',
                'off_hours' => !empty($e['partial_off']) ? shift_time_label($raw['tf'], $raw['tt'], true) : '',
                'time_s' => $e['store'] === SHIFT_OFF ? shift_time_label($e['tf'], $e['tt'], true) : '',
                'time_l' => $e['store'] === SHIFT_OFF ? shift_time_label($e['tf'], $e['tt'], false) : '',
                'hours' => $e['work_hours'] ?? ($e['store'] !== SHIFT_OFF ? shift_time_label(($e['tf'] !== '' || $e['tt'] !== '') ? $e['tf'] : $s['work_from'], ($e['tf'] !== '' || $e['tt'] !== '') ? $e['tt'] : $s['work_to'], false) : ''),
            ];
        }
        $day['any'] = array_filter($day['groups']) !== [];
    }
    unset($day);
    $last = val('SELECT MAX(at) FROM shift_log');
    render('shift', ['view' => $view, 'nav' => shift_nav($first), 'weeks' => shift_weeks($days), 'staff' => $staff,
        'last_at' => $last ? (new DateTimeImmutable((string)$last))->format('n/j H:i') : '']);
}

/** 入れる画面：スタッフ×日の表。選ぶとすぐ保存される */
function h_shift_edit(): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $GLOBALS['shift_closure_map'] = schedule_closure_map($first);
    $staff = shift_staff_list();
    $entries = shift_entries_of($first);
    if (isset($_GET['staff'])) {   // ひとりぶんを、カレンダーで入れる
        $one = null;
        foreach ($staff as $s) {
            if ((int)$s['id'] === (int)$_GET['staff']) {
                $one = $s;
            }
        }
        if ($one === null) {
            throw new HttpError(404);
        }
        $days = shift_days($first);
        foreach ($days as &$day) {
            $v = $entries[(int)$one['id']][$day['date']]['store'] ?? '';
            $day['v'] = $v;
            $day['tf'] = $entries[(int)$one['id']][$day['date']]['tf'] ?? '';
            $day['tt'] = $entries[(int)$one['id']][$day['date']]['tt'] ?? '';
            $day['time_s'] = shift_time_label($day['tf'], $day['tt'], true);
            $day['dflt'] = $v === '' && shift_is_default_off($one, $day);
        }
        unset($day);
        render('shift_edit_one', ['nav' => shift_nav($first), 'weeks' => shift_weeks($days), 'one' => $one, 'staff' => $staff]);
        return;
    }
    $cells = [];
    $dflt = [];
    foreach ($staff as $s) {
        foreach (shift_days($first) as $day) {
            $cells[(int)$s['id']][$day['date']] = $entries[(int)$s['id']][$day['date']]['store'] ?? '';
            // 入れていない日が、毎週の休みの曜日なら、「休（定休）」として見せる
            $dflt[(int)$s['id']][$day['date']] = $cells[(int)$s['id']][$day['date']] === '' && shift_is_default_off($s, $day);
        }
    }
    render('shift_edit', ['nav' => shift_nav($first), 'days' => shift_days($first), 'staff' => $staff, 'cells' => $cells, 'dflt' => $dflt]);
}

/** 記入履歴：いつ、だれの、どの日を、どう変えたか（新しい順） */
function h_shift_log(): void
{
    ensure_shift_tables();
    $list = [];
    foreach (rows('SELECT l.at, l.work_date, l.old_text, l.new_text, s.name FROM shift_log l LEFT JOIN shift_staff s ON s.id = l.staff_id ORDER BY l.id DESC LIMIT 300') as $r) {
        $d = new DateTimeImmutable((string)$r['work_date']);
        $list[] = ['at' => (new DateTimeImmutable((string)$r['at']))->format('n/j H:i'), 'name' => $r['name'] ?? '（削除済み）',
            'date' => $d->format('n/j') . '（' . SHIFT_WEEK[(int)$d->format('w')] . '）', 'old' => $r['old_text'], 'new' => $r['new_text']];
    }
    render('shift_log', ['list' => $list]);
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
    // 個別の出勤・休みの時間。送信されたときだけ書き換える。
    $hasTime = array_key_exists('time_from', $d) || array_key_exists('time_to', $d);
    $tf = shift_time($d['time_from'] ?? '');
    $tt = shift_time($d['time_to'] ?? '');
    if ($tf === null || $tt === null || ($tf !== '' && $tt !== '' && strcmp(str_pad($tt, 5, '0', STR_PAD_LEFT), str_pad($tf, 5, '0', STR_PAD_LEFT)) <= 0)) {
        bad_input();
    }
    transaction(function () use ($sid, $date, $store, $now, $hasTime, $tf, $tt) {
        $cur = row('SELECT store, time_from, time_to FROM shift_entries WHERE staff_id = ? AND work_date = ? FOR UPDATE', [$sid, $date]);
        $tfv = $tf !== '' ? $tf : null;
        $ttv = $tt !== '' ? $tt : null;
        $text = static fn(?string $st, ?string $a, ?string $b): string => $st === null || $st === '' ? '' : $st . (shift_time_label($a, $b, false) !== '' ? '（' . shift_time_label($a, $b, false) . '）' : '');
        $old = $cur ? $text($cur['store'], $cur['time_from'], $cur['time_to']) : '';
        $changed = false;
        if ($store === '') {
            if ($cur) {
                run('DELETE FROM shift_entries WHERE staff_id = ? AND work_date = ?', [$sid, $date]);
                $changed = true;
            }
        } elseif (!$cur) {
            run('INSERT INTO shift_entries (staff_id, work_date, store, time_from, time_to) VALUES (?,?,?,?,?)', [$sid, $date, $store, $tfv, $ttv]);
            $changed = true;
        } elseif ($cur['store'] !== $store) {
            run('UPDATE shift_entries SET prev_store = ?, store = ?, changed_at = ?, time_from = ?, time_to = ? WHERE staff_id = ? AND work_date = ?',
                [$cur['store'], $store, $now, $tfv, $ttv, $sid, $date]);
            $changed = true;
        } elseif ($hasTime) {
            run('UPDATE shift_entries SET changed_at = ?, time_from = ?, time_to = ? WHERE staff_id = ? AND work_date = ?', [$now, $tfv, $ttv, $sid, $date]);
            $changed = true;
        }
        $new = $store === '' ? '' : $text($store, $tfv, $ttv);
        if ($changed && $old !== $new) {   // いつ・だれの・何を入れたかの履歴
            run('INSERT INTO shift_log (at, staff_id, work_date, old_text, new_text) VALUES (?,?,?,?,?)', [$now, $sid, $date, mb_substr($old, 0, 40), mb_substr($new, 0, 40)]);
        }
    });
    respond_json(['ok' => true]);
}

function h_shift_staff(): void
{
    require_admin();
    ensure_shift_tables();
    $staff = shift_staff_list(false);
    foreach ($staff as &$s) {
        $s['offs'] = shift_off_days($s);
    }
    unset($s);
    render('shift_staff', ['staff' => $staff, 'week' => SHIFT_WEEK]);
}

function shift_staff_role(mixed $value): string
{
    if (!is_string($value) || !in_array($value, ['', 'stylist', 'assistant'], true)) { throw new HttpError(400); }
    return $value;
}

function h_shift_staff_add(): void
{
    require_admin();
    ensure_shift_tables();
    $name = mb_substr(trim(nfkc((string)($_POST['name'] ?? '')) ?? ''), 0, 40);
    $home = in_array($_POST['home_store'] ?? '', SHIFT_STORES, true) ? $_POST['home_store'] : 'イノ';
    if ($name === '') {
        throw new HttpError(400);
    }
    $max = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM shift_staff');
    $wf = shift_time($_POST['work_from'] ?? '') ?? '';
    $wt = shift_time($_POST['work_to'] ?? '') ?? '';
    $off = '';
    if (isset($_POST['work_days_present'])) {
        $work = array_filter((array)($_POST['work'] ?? []), static fn($w) => is_scalar($w) && preg_match('/^[0-6]$/', (string)$w));
        $off = implode(',', array_diff(range(0, 6), array_map('intval', $work)));
    }
    $role = shift_staff_role($_POST['role'] ?? '');
    run('INSERT INTO shift_staff (name, home_store, sort_order, work_from, work_to, off_days, role, holiday_off) VALUES (?,?,?,?,?,?,?,?)', [$name, $home, $max + 1, $wf, $wt, $off, $role, isset($_POST['holiday_off']) ? 1 : 0]);
    redirect_to('/settings/staff');
}

/** スタッフの、毎週きまった休みの曜日を保存する */
function h_shift_staff_off(int $id): void
{
    require_admin();
    ensure_shift_tables();
    $work = [];
    foreach ((array)($_POST['work'] ?? []) as $w) {
        if (is_numeric($w) && (int)$w >= 0 && (int)$w <= 6) {
            $work[(int)$w] = true;
        }
    }
    $off = [];
    for ($w = 0; $w <= 6; $w++) {
        if (!isset($work[$w])) {
            $off[] = $w;
        }
    }
    $wf = shift_time($_POST['work_from'] ?? '') ?? '';
    $wt = shift_time($_POST['work_to'] ?? '') ?? '';
    $holiday = isset($_POST['holiday_setting_present']) ? (isset($_POST['holiday_off']) ? 1 : 0) : (int)val('SELECT holiday_off FROM shift_staff WHERE id = ?', [$id]);
    if (array_key_exists('role', $_POST)) {
        $role = shift_staff_role($_POST['role']);
        run('UPDATE shift_staff SET off_days = ?, work_from = ?, work_to = ?, role = ?, holiday_off = ? WHERE id = ?', [implode(',', $off), $wf, $wt, $role, $holiday, $id]);
    } else {
        run('UPDATE shift_staff SET off_days = ?, work_from = ?, work_to = ?, holiday_off = ? WHERE id = ?', [implode(',', $off), $wf, $wt, $holiday, $id]);
    }
    redirect_to('/settings/staff');
}

function h_shift_staff_toggle(int $id): void
{
    require_admin();
    ensure_shift_tables();
    if (isset($_POST['is_active'])) {
        if (!in_array((string)$_POST['is_active'], ['0', '1'], true)) { throw new HttpError(400); }
        run('UPDATE shift_staff SET is_active = ? WHERE id = ?', [(int)$_POST['is_active'], $id]);
    } else {
        run('UPDATE shift_staff SET is_active = 1 - is_active WHERE id = ?', [$id]);
    }
    redirect_to('/settings/staff');
}

/** スタッフごとの、ふだんの休み（曜日）の一覧（見るだけ） */
function h_shift_default(): void
{
    ensure_shift_tables();
    $staff = shift_staff_list(true);
    foreach ($staff as &$s) {
        $s['offs'] = shift_off_days($s);
    }
    unset($s);
    render('shift_default', ['staff' => $staff, 'week' => SHIFT_WEEK]);
}


/** 日ごとのメモを書く */
function h_api_shift_note(): void
{
    ensure_shift_tables();
    $d = req_json();
    if ($d === null) {
        bad_input();
    }
    $date = (string)($d['date'] ?? '');
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $body = mb_substr(trim((string)($d['body'] ?? '')), 0, 300);
    $author = mb_substr(trim((string)($d['author'] ?? '')), 0, 40);
    if (!$dt || $dt->format('Y-m-d') !== $date || $body === '') {
        bad_input();
    }
    run('INSERT INTO shift_notes (work_date, author, body, created_at) VALUES (?,?,?,?)', [$date, $author, $body, now_jst()->format('Y-m-d H:i:s')]);
    respond_json(['ok' => true]);
}

function h_api_shift_note_delete(): void
{
    ensure_shift_tables();
    $d = req_json();
    if ($d === null || !isset($d['id']) || !is_numeric($d['id'])) {
        bad_input();
    }
    run('DELETE FROM shift_notes WHERE id = ?', [(int)$d['id']]);
    respond_json(['ok' => true]);
}

/** お客様向けには勤務に必要な名前・時間だけを出し、社内メモ・理由は取得しない。 */
function shift_customer_holiday(array $staff, array $day, ?array $entry): ?array
{
    $off = shift_is_default_off($staff, $day);
    if ($entry === null) { return $off && (int)$day['dow'] !== 0 ? ['name'=>$staff['name'], 'note'=>'', 'full'=>true] : null; }
    if ($entry['store'] === SHIFT_OFF) {
        $time = shift_time_label(shift_time($entry['tf']), shift_time($entry['tt']), true);
        return ['name'=>$staff['name'], 'note'=>$time === '' ? '' : $time . '時休み', 'full'=>$time === ''];
    }
    $changes = shift_difference($staff, $day, $entry);
    if (in_array('時間変更', $changes, true)) {
        return ['name'=>$staff['name'], 'note'=>shift_work_note($entry['tf'], $entry['tt']), 'full'=>false];
    }
    return null;
}

function shift_customer_themes(): array
{
    return [
        1=>['en'=>'January','name'=>'梅と新春','color'=>'#9b5960','soft'=>'#f8eff0','motif'=>'plum'],
        2=>['en'=>'February','name'=>'椿','color'=>'#8c5958','soft'=>'#f7f0ef','motif'=>'camellia'],
        3=>['en'=>'March','name'=>'ミモザ','color'=>'#998132','soft'=>'#faf6e8','motif'=>'mimosa'],
        4=>['en'=>'April','name'=>'桜','color'=>'#aa7887','soft'=>'#fcf0f3','motif'=>'cherry'],
        5=>['en'=>'May','name'=>'新緑','color'=>'#66806a','soft'=>'#eff5ef','motif'=>'leaves'],
        6=>['en'=>'June','name'=>'紫陽花','color'=>'#8582a5','soft'=>'#f0eff8','motif'=>'hydrangea'],
        7=>['en'=>'July','name'=>'七夕と星','color'=>'#6a8495','soft'=>'#eef4f8','motif'=>'stars'],
        8=>['en'=>'August','name'=>'向日葵','color'=>'#a38a49','soft'=>'#fcf7e9','motif'=>'sunflower'],
        9=>['en'=>'September','name'=>'月とすすき','color'=>'#988763','soft'=>'#f7f4ec','motif'=>'moon'],
        10=>['en'=>'October','name'=>'金木犀','color'=>'#ab8053','soft'=>'#faf3e9','motif'=>'osmanthus'],
        11=>['en'=>'November','name'=>'紅葉','color'=>'#9c7060','soft'=>'#f8f0eb','motif'=>'maple'],
        12=>['en'=>'December','name'=>'冬の枝と雪','color'=>'#66827e','soft'=>'#edf4f2','motif'=>'winter'],
    ];
}

function h_shift_customer_print(bool $asPdf = false): void
{
    ensure_shift_tables();
    $first = shift_month($_GET['month'] ?? null);
    $GLOBALS['shift_closure_map'] = schedule_closure_map($first);
    $store = 'イノ';
    $staff = array_values(array_filter(shift_staff_list(), static fn($s) => $s['home_store'] === $store && ($s['role'] ?? '') === 'stylist'));
    $entries = shift_entries_of($first);
    $days = shift_days($first);
    foreach ($days as &$day) {
        $day['holidays'] = [];
        $day['closed'] = (int)$day['dow'] === 1 || !empty($GLOBALS['shift_closure_map'][$day['date']]['イノ']);
        if ($day['closed']) { continue; }
        foreach ($staff as $person) {
            $entry = $entries[(int)$person['id']][$day['date']] ?? null;
            // 別店舗へ勤務する日も、この店舗ではお休みとして案内する。
            if ($entry !== null && $entry['store'] !== SHIFT_OFF && $entry['store'] !== $store) {
                $day['holidays'][] = ['name'=>$person['name'], 'note'=>'', 'full'=>true];
                continue;
            }
            $item = shift_customer_holiday($person, $day, $entry);
            if ($item !== null) { $day['holidays'][] = $item; }
        }
    }
    unset($day);
    $month = (int)$first->format('n');
    $data = ['nav'=>shift_nav($first), 'year'=>$first->format('Y'), 'month_number'=>$month,
        'store'=>$store, 'staff_count'=>count($staff), 'stores'=>SHIFT_STORES, 'theme'=>shift_customer_themes()[$month], 'weeks'=>shift_weeks($days)];
    if ($asPdf && count($staff) === 0) {
        redirect_to('/shift/customer-print?month=' . $first->format('Y-m'));
    }
    if ($asPdf) {
        require_once __DIR__ . '/shift_pdf.php';
        respond_pdf(build_shift_customer_pdf($data), 'イノ_お休みカレンダー_' . $first->format('Y-m') . '.pdf', !isset($_GET['download']));
    }
    render('shift_customer_print', $data);
}

function h_shift_customer_pdf(): void { h_shift_customer_print(true); }
