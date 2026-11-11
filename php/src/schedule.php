<?php
declare(strict_types=1);

// 店舗の予定（くり返し）。発注日の前日、清掃、ゴミ出しなど。今日・明日の予定を総合管理の画面に出す。
const SCHEDULE_KINDS = ['weekly' => '毎週', 'nth' => '第○週', 'monthly' => '毎月', 'once' => '1回だけ'];

function ensure_schedule_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $existed = (int)val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'schedule_rules'") > 0;
    run('CREATE TABLE IF NOT EXISTS schedule_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(80) NOT NULL,
        store VARCHAR(10) NOT NULL DEFAULT \'\',
        kind VARCHAR(10) NOT NULL,
        weekday TINYINT NULL,
        nth TINYINT NULL,
        day_of_month TINYINT NULL,
        once_date DATE NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        is_closure TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4');
    if (!(int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'schedule_rules' AND column_name = 'is_closure'")) { run('ALTER TABLE schedule_rules ADD COLUMN is_closure TINYINT NOT NULL DEFAULT 0'); }
    if (!$existed) {   // 最初に表を作った時だけ、最初の予定を入れる
        $seed = [['発注日前日（トピー商事）', 2], ['発注日前日（アイテム）', 3], ['発注日前日（ワールド）', 4],
                 ['夜間清掃日', 3], ['ゴミ出し', 4], ['ゴミ出し', 0]];
        foreach ($seed as $i => [$title, $w]) {
            run('INSERT INTO schedule_rules (title, store, kind, weekday, sort_order) VALUES (?, \'\', \'weekly\', ?, ?)', [$title, $w, $i + 1]);
        }
    }
    $done = true;
}

/** 店舗カレンダーの登録を唯一の店休日データとして利用する。 */
function schedule_closure_map(DateTimeImmutable $first): array
{
    ensure_schedule_tables();
    $map = [];
    $start = $first->format('Y-m-01'); $end = $first->format('Y-m-t');
    foreach (rows("SELECT store, once_date FROM schedule_rules WHERE is_active = 1 AND is_closure = 1 AND kind = 'once' AND once_date BETWEEN ? AND ?", [$start,$end]) as $rule) {
        $date = substr((string)$rule['once_date'],0,10);
        foreach ($rule['store'] === '' ? SHIFT_STORES : [$rule['store']] as $store) { $map[$date][$store] = true; }
    }
    return $map;
}

function schedule_matches(array $r, DateTimeImmutable $d): bool
{
    $w = (int)$d->format('w');
    $day = (int)$d->format('j');
    return match ($r['kind']) {
        'weekly' => $w === (int)$r['weekday'],
        'nth' => $w === (int)$r['weekday'] && (int)ceil($day / 7) === (int)$r['nth'],
        'monthly' => $day === (int)$r['day_of_month'],
        'once' => $r['once_date'] === $d->format('Y-m-d'),
        default => false,
    };
}

/** その日の予定の一覧 */
function schedule_on(array $rules, DateTimeImmutable $d): array
{
    $out = [];
    foreach ($rules as $r) {
        if ((int)$r['is_active'] === 1 && schedule_matches($r, $d)) {
            $out[] = ['title' => $r['title'], 'store' => $r['store']];
        }
    }
    return $out;
}

function schedule_rules_all(): array
{
    return rows('SELECT * FROM schedule_rules ORDER BY sort_order, id');
}

function schedule_rules_for_period(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    return rows("SELECT * FROM schedule_rules WHERE is_active = 1
        AND (kind <> 'once' OR once_date BETWEEN ? AND ?) ORDER BY sort_order, id",
        [$start->format('Y-m-d'), $end->format('Y-m-d')]);
}

function h_menu(): void
{
    ensure_schedule_tables();
    $today = now_jst()->setTime(0, 0);
    $tomorrow = $today->modify('+1 day');
    $rules = schedule_rules_for_period($today, $tomorrow);
    render('menu', ['display_date' => $today->format('n月j日') . ' ' . ['日', '月', '火', '水', '木', '金', '土'][(int)$today->format('w')] . '曜日', 'today' => schedule_on($rules, $today), 'tomorrow' => schedule_on($rules, $tomorrow)]);
}

/** 店舗予定の月間カレンダー。出勤シフトとは別の予定だけを表示する。 */
function h_schedule(): void
{
    ensure_schedule_tables();
    $first = shift_month($_GET['month'] ?? null);
    $rules = schedule_rules_for_period($first, $first->modify('last day of this month'));
    $days = [];
    foreach (shift_days($first) as $day) {
        $d = new DateTimeImmutable($day['date'], new DateTimeZone('Asia/Tokyo'));
        $day['events'] = schedule_on($rules, $d);
        $day['closed'] = (int)$day['dow'] === 1;
        $days[] = $day;
    }
    ensure_staff_line_tables();
    $sharing = sl_settings('store');
    render('schedule', ['nav' => shift_nav($first), 'weeks' => shift_weeks($days),
        'line_enabled' => (bool)$sharing['enabled'] && !empty(sl_config()['delivery_enabled']),
        'line_auto' => $sharing['mode'] === 'auto']);
}

function h_schedule_rules(): void
{
    ensure_schedule_tables();
    $rules = schedule_rules_all();
    foreach ($rules as &$r) {
        $r['text'] = match ($r['kind']) {
            'weekly' => '毎週' . SHIFT_WEEK[(int)$r['weekday']] . '曜日',
            'nth' => '第' . (int)$r['nth'] . SHIFT_WEEK[(int)$r['weekday']] . '曜日',
            'monthly' => '毎月' . (int)$r['day_of_month'] . '日',
            default => (string)$r['once_date'],
        };
    }
    unset($r);
    $date = (string)($_GET['date'] ?? '');
    if ($date !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new HttpError(400); }
    }
    render('schedule_rules', ['rules' => $rules, 'week' => SHIFT_WEEK, 'selected_date' => $date]);
}

function schedule_add_key(): string
{
    return hash_hmac('sha256', 'schedule-add|' . (string)($_COOKIE['zlogin'] ?? ''), admin_pass());
}

function h_schedule_once(): void
{
    $date = (string)($_GET['date'] ?? now_jst()->format('Y-m-d'));
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) { throw new HttpError(400); }
    render('schedule_add', ['date' => $date, 'label' => $d->format('n月j日') . '（' . SHIFT_WEEK[(int)$d->format('w')] . '）', 'month' => $d->format('Y-m'), 'form_key' => schedule_add_key()]);
}

function h_schedule_once_save(): void
{
    if (!hash_equals(schedule_add_key(), (string)($_POST['form_key'] ?? ''))) { throw new HttpError(403); }
    if (isset($_POST['is_closure'])) {
        ensure_schedule_tables(); ensure_staff_line_tables();
        $start = (string)($_POST['once_date'] ?? '');
        $end = (string)($_POST['end_date'] ?? $start);
        $a = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
        $b = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
        $store = (string)($_POST['store'] ?? '');
        if (!$a || !$b || $a->format('Y-m-d') !== $start || $b->format('Y-m-d') !== $end || $b < $a || $a->diff($b)->days > 61 || !in_array($store, ['', ...SHIFT_STORES], true)) { throw new HttpError(400); }
        $title = mb_substr(trim((string)($_POST['title'] ?? '')),0,80);
        if ($title === '') { $title = '店休日'; }
        transaction(function () use ($a,$b,$store,$title) {
            for ($d=$a;$d<=$b;$d=$d->modify('+1 day')) {
                $date=$d->format('Y-m-d');
                if (val("SELECT id FROM schedule_rules WHERE is_active=1 AND is_closure=1 AND store=? AND once_date=?",[$store,$date])) { continue; }
                run("INSERT INTO schedule_rules (title,store,kind,once_date,sort_order,is_closure) VALUES (?,?,'once',?,0,1)",[$title,$store,$date]);
            }
        });
        redirect_to('/schedule?month=' . $a->format('Y-m'));
    }
    $_POST['kind'] = 'once';
    h_schedule_add();
}

function h_schedule_add(): void
{
    ensure_schedule_tables();
    $title = mb_substr(trim(nfkc((string)($_POST['title'] ?? '')) ?? ''), 0, 80);
    $kind = (string)($_POST['kind'] ?? '');
    $store = in_array($_POST['store'] ?? '', SHIFT_STORES, true) ? $_POST['store'] : '';
    $w = isset($_POST['weekday']) ? (int)$_POST['weekday'] : null;
    $nth = (int)($_POST['nth'] ?? 0);
    $dom = (int)($_POST['day_of_month'] ?? 0);
    $once = (string)($_POST['once_date'] ?? '');
    $okDate = DateTimeImmutable::createFromFormat('!Y-m-d', $once);
    if ($title === '' || !isset(SCHEDULE_KINDS[$kind])
        || (in_array($kind, ['weekly', 'nth'], true) && ($w === null || $w < 0 || $w > 6))
        || ($kind === 'nth' && ($nth < 1 || $nth > 5))
        || ($kind === 'monthly' && ($dom < 1 || $dom > 31))
        || ($kind === 'once' && (!$okDate || $okDate->format('Y-m-d') !== $once))) {
        throw new HttpError(400);
    }
    $simple = request_path() === '/schedule/add';
    if ($simple) { ensure_staff_line_tables(); }
    $max = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM schedule_rules');
    run('INSERT INTO schedule_rules (title, store, kind, weekday, nth, day_of_month, once_date, sort_order) VALUES (?,?,?,?,?,?,?,?)', [
        $title, $store, $kind,
        in_array($kind, ['weekly', 'nth'], true) ? $w : null,
        $kind === 'nth' ? $nth : null,
        $kind === 'monthly' ? $dom : null,
        $kind === 'once' ? $once : null,
        $max + 1,
    ]);
    if ($simple) {
        $id = last_id();
        if (isset($_POST['share_line'])) { run("INSERT INTO staff_line_visibility (kind, entity_id, is_public) VALUES ('rule',?,1)", [$id]); }
        redirect_to('/schedule?month=' . substr($once, 0, 7));
    }
    redirect_to('/schedule/rules');
}

function h_schedule_toggle(int $id): void
{
    ensure_schedule_tables();
    run('UPDATE schedule_rules SET is_active = 1 - is_active WHERE id = ?', [$id]);
    redirect_to('/schedule/rules');
}

function h_schedule_delete(int $id): void
{
    ensure_schedule_tables();
    run('DELETE FROM schedule_rules WHERE id = ?', [$id]);
    redirect_to('/schedule/rules');
}
