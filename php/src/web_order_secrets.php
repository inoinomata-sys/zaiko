<?php
declare(strict_types=1);

function web_order_secrets_access(): bool
{
    return admin_cookie_ok('web-order-secrets|' . admin_pass(), (string)($_COOKIE['zweborders'] ?? ''));
}

/** 暗号鍵はDB・公開ディレクトリと分離。テストでは使い捨ての設定値を利用。 */
function web_order_secret_key(): string
{
    $configured = (string)($GLOBALS['CONFIG']['web_orders_key'] ?? '');
    if ($configured !== '') {
        if (!preg_match('/^[0-9a-f]{64}$/i', $configured)) throw new RuntimeException('Web注文の暗号鍵の設定を確認してください');
        return hex2bin($configured);
    }
    $dir = BASE_DIR . '/storage/secrets';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('暗号鍵の保存先を作成できません');
    $path = $dir . '/web-orders.key';
    $f = @fopen($path, 'c+b');
    if (!$f || !flock($f, LOCK_EX)) throw new RuntimeException('暗号鍵を読み込めません');
    try {
        $key = stream_get_contents($f);
        if ($key === '') {
            $key = random_bytes(32);
            if (fwrite($f, $key) !== 32 || !fflush($f)) throw new RuntimeException('暗号鍵を保存できません');
            @chmod($path, 0600);
        }
        if (strlen($key) !== 32) throw new RuntimeException('暗号鍵を確認してください');
        return $key;
    } finally { flock($f, LOCK_UN); fclose($f); }
}

function web_order_encrypt(array $credentials, int $siteId): string
{
    $nonce = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt(json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'aes-256-gcm', web_order_secret_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'web-order:' . $siteId);
    if ($cipher === false) throw new RuntimeException('ログイン情報を暗号化できません');
    return 'v1:' . base64_encode($nonce . $tag . $cipher);
}

function web_order_decrypt(?string $encrypted, int $siteId): array
{
    if (!$encrypted) return ['login_id'=>'', 'password'=>''];
    $data = str_starts_with($encrypted, 'v1:') ? base64_decode(substr($encrypted, 3), true) : false;
    if ($data === false || strlen($data) < 29) throw new RuntimeException('保存したログイン情報を読み込めません');
    $plain = openssl_decrypt(substr($data,28), 'aes-256-gcm', web_order_secret_key(), OPENSSL_RAW_DATA, substr($data,0,12), substr($data,12,16), 'web-order:' . $siteId);
    if ($plain === false) throw new RuntimeException('保存したログイン情報を読み込めません');
    return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
}

function h_web_order_credentials(int $id): void
{
    require_admin();
    header('Cache-Control: no-store, private'); header('Pragma: no-cache');
    if (!web_order_secrets_access() || !hash_equals(web_order_form_key(), (string)($_POST['form_key'] ?? ''))) throw new HttpError(403, '管理者の暗証番号で設定を開き直してください');
    ensure_web_order_sites();
    $site = row('SELECT credentials FROM web_order_sites WHERE id=?', [$id]);
    if (!$site) throw new HttpError(404);
    respond_json(web_order_decrypt($site['credentials'], $id));
}
