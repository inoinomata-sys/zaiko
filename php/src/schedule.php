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
        sort_order INT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4');
    if (!$existed) {   // 最初に表を作った時だけ、最初の予定を入れる
        $seed = [['発注日前日（トピー商事）', 2], ['発注日前日（アイテム）', 3], ['発注日前日（ワールド）', 4],
                 ['夜間清掃日', 3], ['ゴミ出し', 4], ['ゴミ出し', 0]];
        foreach ($seed as $i => [$title, $w]) {
            run('INSERT INTO schedule_rules (title, store, kind, weekday, sort_order) VALUES (?, \'\', \'weekly\', ?, ?)', [$title, $w, $i + 1]);
        }
    }
    $done = true;
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

function h_menu(): void
{
    ensure_schedule_tables();
    $rules = schedule_rules_all();
    $today = now_jst()->setTime(0, 0);
    render('menu', ['today' => schedule_on($rules, $today), 'tomorrow' => schedule_on($rules, $today->modify('+1 day'))]);
}

/** 月の表：予定のある日だけ並べる */
function h_schedule(): void
{
    ensure_schedule_tables();
    $first = shift_month($_GET['month'] ?? null);
    $rules = schedule_rules_all();
    $days = [];
    foreach (shift_days($first) as $day) {
        $d = new DateTimeImmutable($day['date'], new DateTimeZone('Asia/Tokyo'));
        $day['events'] = schedule_on($rules, $d);
        $days[] = $day;
    }
    render('schedule', ['nav' => shift_nav($first), 'days' => $days]);
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
    render('schedule_rules', ['rules' => $rules, 'week' => SHIFT_WEEK]);
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
    $max = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM schedule_rules');
    run('INSERT INTO schedule_rules (title, store, kind, weekday, nth, day_of_month, once_date, sort_order) VALUES (?,?,?,?,?,?,?,?)', [
        $title, $store, $kind,
        in_array($kind, ['weekly', 'nth'], true) ? $w : null,
        $kind === 'nth' ? $nth : null,
        $kind === 'monthly' ? $dom : null,
        $kind === 'once' ? $once : null,
        $max + 1,
    ]);
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
