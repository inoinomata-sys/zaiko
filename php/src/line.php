<?php
declare(strict_types=1);

// 公式LINE（発注専用）との連携。業者さんの LINE の個人IDを受け取り、仕入先とひも付ける。
// チャネルシークレット・トークンは config.php の 'line' に書く（GitHub には載せない）。

function line_config(): array
{
    $c = $GLOBALS['CONFIG']['line'] ?? [];
    return is_array($c) ? $c : [];
}

function ensure_line_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    run('CREATE TABLE IF NOT EXISTS line_contacts (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY,
        display_name VARCHAR(80) NOT NULL DEFAULT \'\',
        supplier VARCHAR(40) NULL,
        last_text VARCHAR(200) NOT NULL DEFAULT \'\',
        last_at DATETIME NOT NULL
    ) DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** LINE から来たことの確認（本文を、シークレットで署名した値と、ヘッダーの署名を照らし合わせる） */
function line_signature_ok(string $body, string $secret, string $header): bool
{
    if ($secret === '' || $header === '') {
        return false;
    }
    return hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $header);
}

/** LINE が、友だちの発言などを知らせてくる入口（ログインなし。署名で確認する） */
function h_line_webhook(): void
{
    $cfg = line_config();
    $body = (string)file_get_contents('php://input');
    if (!line_signature_ok($body, (string)($cfg['secret'] ?? ''), (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? ''))) {
        http_response_code(403);
        exit('NG');
    }
    ensure_line_tables();
    $data = json_decode($body, true);
    foreach ((array)($data['events'] ?? []) as $ev) {
        $uid = (string)($ev['source']['userId'] ?? '');
        if ($uid === '' || !preg_match('/^U[0-9a-f]{32}$/', $uid)) {
            continue;
        }
        $text = (string)($ev['message']['text'] ?? '');
        $name = line_profile_name($uid, (string)($cfg['token'] ?? ''));
        run('INSERT INTO line_contacts (user_id, display_name, last_text, last_at) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE last_text = VALUES(last_text), last_at = VALUES(last_at),
             display_name = IF(VALUES(display_name) = \'\', display_name, VALUES(display_name))',
            [$uid, $name, mb_substr($text, 0, 200), now_jst()->format('Y-m-d H:i:s')]);
    }
    http_response_code(200);
    echo 'OK';
}

function line_profile_name(string $uid, string $token): string
{
    if ($token === '') {
        return '';
    }
    $ctx = stream_context_create(['http' => ['header' => "Authorization: Bearer {$token}\r\n", 'timeout' => 5, 'ignore_errors' => true]]);
    $r = @file_get_contents('https://api.line.me/v2/bot/profile/' . $uid, false, $ctx);
    $j = is_string($r) ? json_decode($r, true) : null;
    return mb_substr((string)($j['displayName'] ?? ''), 0, 80);
}

function h_settings_line(): void
{
    ensure_line_tables();
    $cfg = line_config();
    render('settings_line', [
        'contacts' => rows('SELECT user_id, display_name, supplier, last_text, last_at FROM line_contacts ORDER BY last_at DESC'),
        'ready' => ($cfg['secret'] ?? '') !== '' && ($cfg['token'] ?? '') !== '',
    ]);
}

function h_settings_line_assign(): void
{
    ensure_line_tables();
    $uid = (string)($_POST['user_id'] ?? '');
    $sup = (string)($_POST['supplier'] ?? '');
    if ($sup !== '' && !in_array($sup, SUPPLIER_NAMES, true)) {
        throw new HttpError(400);
    }
    // 1つの仕入先に、ひも付けられるのは1人（送り先を間違えないため）
    transaction(function () use ($uid, $sup) {
        if ($sup !== '') {
            run('UPDATE line_contacts SET supplier = NULL WHERE supplier = ?', [$sup]);
        }
        run('UPDATE line_contacts SET supplier = ? WHERE user_id = ?', [$sup === '' ? null : $sup, $uid]);
    });
    redirect_to('/settings/line');
}
