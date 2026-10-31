<?php
declare(strict_types=1);

// ── 決まった値（Flask 版 app.py と同じ） ───────────────────────────────────────
const STORES = ['イノ', 'リッチ'];
const SUPPLIER_NAMES = ['ワールド美容', 'トピー商事', 'アイテム美容', '三井コーポレーション',
    'ナプラ（N.シリーズ）Web注文', 'コタ', 'リファ', '買い物忘れ・買い物しといて',
    'コーナン・業務用スーパー・100均一'];
const STALE_DAYS = 7;
// 発注履歴に出さず、同じ日の二重発注の警告もしない仕入先（買い物リスト）
const NO_HISTORY_SUPPLIERS = ['買い物忘れ・買い物しといて'];
const CUSTOM_CAT = 'リスト外の品（手入力）';
const PRICE_TITLE = '商品価格表';
const PRICE_NOTE = '表示の価格は税込です。';
const DISCOUNT_ROUND = 10;
const CAMPAIGN_TIERS = [[15000, 8], [20000, 10], [30000, 12], [40000, 13], [80000, 14]];
const CAMPAIGN_TOP = 16;
const PDF_VER = '3';
const ORDER_PDF_MAX_SCALE = 1.6;

/** 画面の入り口で出す「見つかりません」などの例外 */
class HttpError extends Exception
{
    public function __construct(public int $status, string $message = '')
    {
        parent::__construct($message);
    }
}

/** intl 拡張がないサーバー用の代わり（半角カナ→全角、全角英数・空白→半角） */
function nfkc_basic(string $v): string
{
    return trim(mb_convert_kana($v, 'KVas'));
}

/** 全角英数字・半角カナなどの表記ゆれをそろえる（Python の unicodedata.normalize('NFKC') 相当） */
function nfkc(?string $v): ?string
{
    if ($v === null || $v === '') {
        return $v;
    }
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($v, Normalizer::FORM_KC);
        if ($n !== false) {
            return trim($n);
        }
    }
    return nfkc_basic($v);
}

function jst_tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Asia/Tokyo');
}

function now_jst(): DateTimeImmutable
{
    return new DateTimeImmutable('now', jst_tz());
}

/** データベースの日時（日本時間のまま入っている）を DateTime にする */
function jst_dt(mixed $v): ?DateTimeImmutable
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    if ($v instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($v)->setTimezone(jst_tz());
    }
    return new DateTimeImmutable((string)$v, jst_tz());
}

function jst_fmt(mixed $v): string
{
    $d = jst_dt($v);
    return $d ? $d->format('m/d H:i') : '';
}

function md_fmt(mixed $v): string
{
    $d = jst_dt($v);
    return $d ? $d->format('n/j') : '';
}

function yen_fmt(mixed $v): string
{
    return '¥' . number_format((int)$v);
}

/** 数の表示（3.0 → 3、2.5 → 2.5） */
function num_fmt(mixed $v): string
{
    if ($v === null || $v === '') {
        return '0';
    }
    $f = (float)$v;
    if ($f == floor($f) && abs($f) < 1e15) {
        return (string)(int)$f;
    }
    return rtrim(rtrim(sprintf('%.6g', $f), '0'), '.');
}

function pct_fmt(mixed $pct): string
{
    $f = (float)($pct ?: 0);
    if ($f == floor($f)) {
        return (string)(int)$f;
    }
    return rtrim(rtrim(sprintf('%.6g', $f), '0'), '.');
}

function sama_fmt(?string $name): string
{
    $name = trim((string)$name);
    return str_ends_with($name, '様') ? $name : "{$name} 様";
}

// ── 割引の計算（Flask 版・画面の JS と同じ結果にする） ────────────────────────────

/** [割引額, 支払額]。$pairs は [金額, 割引率] の並び。行ごとに10円単位へ繰り上げる */
function calc_discount_lines(array $pairs): array
{
    $subtotal = 0;
    $payable = 0;
    $unit = 10000 * DISCOUNT_ROUND;
    foreach ($pairs as [$a, $pct]) {
        $a = (int)$a;
        $subtotal += $a;
        $p100 = (int)round((float)($pct ?? 0) * 100);
        $payable += $p100 <= 0 ? $a : min($a, intdiv($a * (10000 - $p100) + $unit - 1, $unit) * DISCOUNT_ROUND);
    }
    return [$subtotal - $payable, $payable];
}

function campaign_tier(int $normalTotal): int
{
    if ($normalTotal <= 0) {
        return 0;
    }
    foreach (CAMPAIGN_TIERS as [$limit, $pct]) {
        if ($normalTotal <= $limit) {
            return $pct;
        }
    }
    return CAMPAIGN_TOP;
}

/** オーガニックノート（キャンペーンでも最大5%まで）の商品名。レジの商品名には「オーガニックノート」の文字が入らない */
const NO_DISCOUNT_NAMES = ['コアミー1ヶ月S', 'コアミー1ヶ月M', 'コアミー1ヶ月EX', 'コアミー1ヶ月RP', 'コアミー1ヶ月ER', 'トラックオイルno.3'];
const ORGANIC_NOTE_PREFIXES = ['モイスト', 'スムース', 'CMC'];
const ORGANIC_NOTE_NAMES = ['ホワイトオーキット詰替', 'トライアルセット'];

/** n=通常 a=アルマダ(さらに5%) f5=最大5% f10=最大10% z=割引なし */
function price_kind(?string $category, ?string $name): string
{
    $name = $name ?? '';
    if (str_contains($name, '詰替えホルダー') || in_array(str_replace(' ', '', $name), NO_DISCOUNT_NAMES, true)) {
        return 'z';
    }
    if (str_contains($name, 'ステラボーテ')) {
        return 'f10';
    }
    $compact = str_replace(' ', '', $name);
    $organic = in_array($compact, ORGANIC_NOTE_NAMES, true);
    foreach (ORGANIC_NOTE_PREFIXES as $pre) {
        if (str_starts_with($name, $pre)) {
            $organic = true;
        }
    }
    if (str_starts_with((string)$category, 'リファ') || str_contains($name, 'リファ') || str_contains($name, 'オーガニックノート') || $organic) {
        return 'f5';
    }
    if (in_array($compact, ['アルマダ1000ml', 'エグータムプレミアム'], true)) {
        return 'a';
    }
    return 'n';
}

function kind_rate(string $kind, float|int $tier): float
{
    if ($kind === 'a') {
        return round(100 - (100 - $tier) * 0.95, 2);
    }
    if ($kind === 'z') {
        return 0.0;
    }
    if ($kind === 'f5') {
        return (float)min($tier, 5);
    }
    if ($kind === 'f10') {
        return (float)min($tier, 10);
    }
    return (float)$tier;
}

// ── リクエスト・レスポンスの道具 ────────────────────────────────────────────────

/** 送られてきた JSON（なければ null） */
function req_json(): ?array
{
    static $cache = false;
    if ($cache === false) {
        $raw = file_get_contents('php://input');
        $d = $raw ? json_decode($raw, true) : null;
        $cache = is_array($d) ? $d : null;
    }
    return $cache;
}

function respond_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function redirect_to(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function respond_pdf(string $data, string $filename, bool $inline = false): never
{
    header('Content-Type: application/pdf');
    $disp = $inline ? 'inline' : 'attachment';
    header("Content-Disposition: {$disp}; filename*=UTF-8''" . rawurlencode($filename));
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/** 返事を先に返してから、続きの処理（PDFの事前作成など）をする。できない環境ではふつうに最後まで処理する */
function respond_json_after(array $data, int $status, callable $after): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    header('Content-Length: ' . strlen($body));
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_flush();
        flush();
    }
    try {
        $after();
    } catch (Throwable $e) {
        error_log('後処理に失敗: ' . $e->getMessage());
    }
    exit;
}

// ── 画面を見るためのID・パスワード（Basic認証）。config.php の 'basic_auth' に書いた時だけ有効 ─────────────────

/** 送られてきた Authorization から [ID, パスワード] を取り出す（サーバーによって入る場所が違う） */
function basic_auth_credentials(): array
{
    if (isset($_SERVER['PHP_AUTH_USER'])) {
        return [(string)$_SERVER['PHP_AUTH_USER'], (string)($_SERVER['PHP_AUTH_PW'] ?? '')];
    }
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') {
                $h = $v;
            }
        }
    }
    if (stripos($h, 'basic ') === 0) {
        $dec = base64_decode(substr($h, 6), true);
        if ($dec !== false && str_contains($dec, ':')) {
            [$u, $pw] = explode(':', $dec, 2);
            return [$u, $pw];
        }
    }
    return ['', ''];
}

function basic_auth_ok(array $cfg, string $user, string $pass): bool
{
    $okUser = hash_equals((string)($cfg['user'] ?? ''), $user);
    $okPass = hash_equals((string)($cfg['pass'] ?? ''), $pass);
    return $okUser && $okPass && ($cfg['user'] ?? '') !== '' && ($cfg['pass'] ?? '') !== '';
}

/** ログインしたことを覚えておく印（Cookie）。期限と署名だけで、パスワードそのものは入れない */
function login_cookie_value(array $cfg, int $expires): string
{
    $secret = hash('sha256', 'zaiko|' . ($cfg['user'] ?? '') . '|' . ($cfg['pass'] ?? ''));
    return $expires . '.' . hash_hmac('sha256', (string)$expires, $secret);
}

function login_cookie_ok(array $cfg, string $value): bool
{
    if (!preg_match('/^(\d{9,12})\.([0-9a-f]{64})$/', $value, $m)) {
        return false;
    }
    return (int)$m[1] > time() && hash_equals(login_cookie_value($cfg, (int)$m[1]), $value);
}

function login_page(string $user, string $error = ''): void
{
    http_response_code($error === '' ? 200 : 401);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $u = htmlspecialchars($user, ENT_QUOTES, 'UTF-8');
    $e = $error === '' ? '' : '<p class="e">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex"><title>ログイン</title>'
        . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#faf0f4;font-family:-apple-system,"Hiragino Sans",sans-serif;color:#2a1f24}'
        . 'form{width:min(88vw,360px);background:#fff;border-radius:14px;padding:24px 20px;box-shadow:0 2px 14px rgba(0,0,0,.08)}'
        . 'h1{font-size:18px;margin:0 0 16px}input{width:100%;box-sizing:border-box;font-size:17px;padding:12px;margin:0 0 12px;border:1px solid #ddd;border-radius:10px}'
        . 'button{width:100%;font-size:17px;font-weight:700;padding:13px;border:0;border-radius:10px;background:#c2547a;color:#fff}'
        . '.e{color:#b3261e;font-size:14px;margin:0 0 12px}</style></head><body>'
        . '<form method="post" action="/login"><h1>INO.inc 在庫管理</h1>' . $e
        . '<input name="user" value="' . $u . '" autocomplete="username" autocapitalize="none" autocorrect="off" placeholder="ID" required>'
        . '<input name="pass" type="password" autocomplete="current-password" placeholder="パスワード" required>'
        . '<button type="submit">ログイン</button></form></body></html>';
    exit;
}

/** 設定があれば、ログインを求める（ログイン画面とCookie。ID・パスワードのヘッダでも通す）。/ping だけは聞かない */
function require_basic_auth(): void
{
    $cfg = $GLOBALS['CONFIG']['basic_auth'] ?? null;
    if (!is_array($cfg) || ($cfg['user'] ?? '') === '') {
        return;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/ping') {
        return;
    }
    if (login_cookie_ok($cfg, (string)($_COOKIE['zlogin'] ?? ''))) {
        if ($path === '/login') {
            header('Location: /menu');
            exit;
        }
        return;
    }
    [$u, $pw] = basic_auth_credentials();
    if (basic_auth_ok($cfg, $u, $pw)) {
        return;
    }
    if ($path === '/login' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $pu = (string)($_POST['user'] ?? '');
        if (basic_auth_ok($cfg, $pu, (string)($_POST['pass'] ?? ''))) {
            $exp = time() + 180 * 86400;
            setcookie('zlogin', login_cookie_value($cfg, $exp), [
                'expires' => $exp, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
            ]);
            header('Location: /menu');
            exit;
        }
        sleep(1);
        login_page($pu, 'IDかパスワードが違います');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $path !== '/login' && !str_starts_with($path, '/api')) {
        header('Location: /login');
        exit;
    }
    if ($path === '/login') {
        login_page((string)($cfg['user'] ?? ''));
    }
    http_response_code(401);
    echo 'ログインが必要です。';
    exit;
}
