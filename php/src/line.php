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

// ── 発注書を、業者さんの LINE に送る ───────────────────────────────────────────────

const LINE_LINK_DAYS = 7;   // 発注書のリンクが開ける日数

function ensure_line_send_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    run('CREATE TABLE IF NOT EXISTS line_sends (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        user_id VARCHAR(64) NOT NULL,
        status INT NOT NULL,
        sent_at DATETIME NOT NULL,
        KEY idx_line_sends_order (order_id)
    ) DEFAULT CHARSET=utf8mb4');
    $done = true;
}

function line_order_sig(int $orderId, int $exp, string $secret): string
{
    return substr(hash_hmac('sha256', "order|{$orderId}|{$exp}", hash('sha256', 'link|' . $secret)), 0, 32);
}

function line_order_link(int $orderId, int $exp, string $secret): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'sougoukanri.ino-vifare.com';
    return "https://{$host}/pub/order?id={$orderId}&exp={$exp}&sig=" . line_order_sig($orderId, $exp, $secret);
}

/** 業者さんが、LINE のリンクから開く発注書（ログインなし。署名と期限で確認する） */
function h_pub_order(): void
{
    $id = (int)($_GET['id'] ?? 0);
    $exp = (int)($_GET['exp'] ?? 0);
    $sig = (string)($_GET['sig'] ?? '');
    $secret = (string)(line_config()['secret'] ?? '');
    if ($id <= 0 || $secret === '' || $exp < time() || !hash_equals(line_order_sig($id, $exp, $secret), $sig)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('このリンクは、期限が切れているか、正しくありません。お手数ですが、ご連絡ください。');
    }
    $_GET['inline'] = '1';
    h_order_pdf($id);
}

/** この発注の仕入先にひも付いた、LINE の送り先（なければ null） */
function line_contact_for_order(int $orderId): ?array
{
    ensure_line_tables();
    return row('SELECT c.user_id, c.display_name FROM orders o JOIN line_contacts c ON c.supplier = o.supplier WHERE o.id = ?', [$orderId]);
}

function h_order_line_send(int $id): void
{
    $cfg = line_config();
    $secret = (string)($cfg['secret'] ?? '');
    $token = (string)($cfg['token'] ?? '');
    $order = row('SELECT id, store, supplier, order_month, order_day FROM orders WHERE id = ?', [$id]);
    if (!$order) {
        respond_json(['ok' => false, 'error' => '発注が見つかりません'], 404);
    }
    $to = line_contact_for_order($id);
    if ($secret === '' || $token === '' || !$to) {
        respond_json(['ok' => false, 'error' => 'この仕入先の LINE の送り先が、決まっていません（設定 > LINE）'], 400);
    }
    ensure_line_send_table();
    $exp = time() + LINE_LINK_DAYS * 86400;
    $text = "【発注書】有限会社イノ（{$order['store']}）\n{$order['order_month']}月{$order['order_day']}日の発注です。\n"
        . "下のリンクから、PDFをご確認ください（" . LINE_LINK_DAYS . "日間有効）。\n"
        . line_order_link($id, $exp, $secret) . "\nよろしくお願いいたします。";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\nX-Line-Retry-Key: " . bin2hex(random_bytes(8)) . '-' . bin2hex(random_bytes(2)) . "\r\n",
        'content' => json_encode(['to' => $to['user_id'], 'messages' => [['type' => 'text', 'text' => $text]]], JSON_UNESCAPED_UNICODE),
    ]]);
    @file_get_contents('https://api.line.me/v2/bot/message/push', false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    run('INSERT INTO line_sends (order_id, user_id, status, sent_at) VALUES (?,?,?,?)', [$id, $to['user_id'], $status, now_jst()->format('Y-m-d H:i:s')]);
    if ($status !== 200) {
        respond_json(['ok' => false, 'error' => "LINE に送れませんでした（{$status}）。もう一度お試しください"], 502);
    }
    respond_json(['ok' => true, 'to' => $to['display_name']]);
}
