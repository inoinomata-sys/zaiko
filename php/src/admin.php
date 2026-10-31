<?php
declare(strict_types=1);

// 管理者の合言葉。LINE の送り先など、間違えると困る画面に鍵をかける。合言葉は config.php の 'admin_pass'（ログインのパスワードとは別）。
const ADMIN_UNLOCK_MINUTES = 60;

function admin_cookie_value(string $pass, int $expires): string
{
    return $expires . '.' . hash_hmac('sha256', "admin|{$expires}", hash('sha256', 'admin-key|' . $pass));
}

function admin_cookie_ok(string $pass, string $value): bool
{
    if ($pass === '' || !preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $value, $m)) {
        return false;
    }
    return (int)$m[1] > time() && hash_equals(admin_cookie_value($pass, (int)$m[1]), $value);
}

function admin_pass(): string
{
    return (string)($GLOBALS['CONFIG']['admin_pass'] ?? '');
}

/** 鍵がかかった画面の最初に呼ぶ。開いていなければ、合言葉の画面を出して、そこで止まる */
function require_admin(): void
{
    $pass = admin_pass();
    if (admin_cookie_ok($pass, (string)($_COOKIE['zadmin'] ?? ''))) {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {   // 画面の操作（ボタン）は、開いている間だけ。切れていたら、断る
        respond_json(['ok' => false, 'error' => '管理者の合言葉の時間が切れました。設定を開き直してください'], 403);
    }
    redirect_to('/admin/unlock?next=' . urlencode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
}

function admin_next(string $n): string
{
    return preg_match('#^/[A-Za-z0-9/_-]*$#', $n) ? $n : '/settings';
}

function h_admin_unlock(): void
{
    $pass = admin_pass();
    $next = admin_next((string)($_GET['next'] ?? $_POST['next'] ?? '/settings'));
    $error = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $pass !== '') {
        if (hash_equals($pass, (string)($_POST['pass'] ?? ''))) {
            $exp = time() + ADMIN_UNLOCK_MINUTES * 60;
            setcookie('zadmin', admin_cookie_value($pass, $exp), ['expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
            redirect_to($next);
        }
        sleep(1);
        $error = '合言葉が違います';
    }
    render('admin_unlock', ['next' => $next, 'error' => $error, 'unset' => $pass === ''], $error !== '' ? 401 : 200);
}
