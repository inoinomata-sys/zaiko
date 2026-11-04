<?php
declare(strict_types=1);

// ── 発注書のメール送信 ─────────────────────────────────────────────────────────
// 文面とPDFのリンクはLINEと同じ（line.php の order_notice_text）。送り元は config.php の mail.from（なければ order@example.com）

const MAIL_DEFAULT_FROM = 'order@example.com';

function mail_config(): array
{
    $c = $GLOBALS['CONFIG']['mail'] ?? [];
    return is_array($c) ? $c : [];
}

/** 仕入先の担当者の「メールアドレス」の欄がなければ作る（初回だけ） */
function ensure_contact_email_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $has = (int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'supplier_contacts' AND column_name = 'email'");
    if (!$has) {
        run("ALTER TABLE supplier_contacts ADD COLUMN email VARCHAR(200) NOT NULL DEFAULT ''");
    }
}

function email_valid(string $addr): bool
{
    return $addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $addr);
}

function ensure_mail_send_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    run('CREATE TABLE IF NOT EXISTS mail_sends (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        to_addr VARCHAR(200) NOT NULL,
        ok TINYINT(1) NOT NULL,
        sent_at DATETIME NOT NULL,
        KEY idx_mail_sends_order (order_id)
    ) DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** この発注の仕入先の、メールの送り先（最初にアドレスが入っている担当者）。なければ null */
function mail_contact_for_order(int $orderId): ?array
{
    ensure_contact_email_column();
    $sup = val('SELECT supplier FROM orders WHERE id = ?', [$orderId]);
    if ($sup === null || $sup === '') {
        return null;
    }
    $r = row("SELECT contact_name, email FROM supplier_contacts WHERE supplier_name = ? AND email <> '' ORDER BY sort_order, id LIMIT 1", [$sup]);
    return $r && email_valid(trim((string)$r['email'])) ? ['name' => (string)$r['contact_name'], 'email' => trim((string)$r['email'])] : null;
}

function h_order_email_send(int $id): void
{
    $secret = (string)(line_config()['secret'] ?? '');
    $order = row('SELECT id, store, supplier, order_month, order_day, notes FROM orders WHERE id = ?', [$id]);
    if (!$order) {
        respond_json(['ok' => false, 'error' => '発注が見つかりません'], 404);
    }
    $to = mail_contact_for_order($id);
    if (!$to) {
        respond_json(['ok' => false, 'error' => 'この仕入先のメールアドレスが、入っていません（設定 > 仕入先の担当者）'], 400);
    }
    if ($secret === '') {
        respond_json(['ok' => false, 'error' => 'リンクを作る設定（line.secret）が、ありません'], 400);
    }
    ensure_mail_send_table();
    $from = (string)(mail_config()['from'] ?? MAIL_DEFAULT_FROM);
    $reply = (string)(mail_config()['reply_to'] ?? '');
    $text = order_notice_text($order, line_order_link($id, time() + LINE_LINK_DAYS * 86400, $secret));
    $storeName = $order['store'] === 'イノ' ? 'イノ本店' : $order['store'] . '店';
    $subject = "【発注書】【{$storeName}】{$order['order_month']}月{$order['order_day']}日 有限会社ビューティーサロン花";
    $headers = ['MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
        'From: ' . mb_encode_mimeheader('有限会社ビューティーサロン花', 'UTF-8') . " <{$from}>"];
    if (email_valid($reply)) {
        $headers[] = "Reply-To: {$reply}";
    }
    $ok = @mail($to['email'], mb_encode_mimeheader($subject, 'UTF-8'), chunk_split(base64_encode($text)), implode("\r\n", $headers), '-f' . $from);
    run('INSERT INTO mail_sends (order_id, to_addr, ok, sent_at) VALUES (?,?,?,?)', [$id, $to['email'], $ok ? 1 : 0, now_jst()->format('Y-m-d H:i:s')]);
    if (!$ok) {
        respond_json(['ok' => false, 'error' => 'メールを送れませんでした。もう一度お試しください'], 502);
    }
    respond_json(['ok' => true, 'to' => $to['email']]);
}
