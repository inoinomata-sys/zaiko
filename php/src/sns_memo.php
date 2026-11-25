<?php
declare(strict_types=1);

const SNS_MEMO_MENUS = ['縮毛矯正', '前髪の縮毛矯正', 'カット', 'カラー', '相談のみ', 'その他'];

function sns_memo_key(): ?string
{
    $key = (string)($GLOBALS['CONFIG']['ino_blog_staff_key'] ?? '');
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $key) || empty($GLOBALS['CONFIG']['basic_auth']['user'])) {
        return null;
    }
    return $key;
}

function h_sns_memo(): void
{
    header('Cache-Control: no-store');
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    render('sns_memo', ['sent' => isset($_GET['sent']), 'request_id' => $id, 'can_send' => sns_memo_key() !== null, 'menu_options' => SNS_MEMO_MENUS]);
}

function h_sns_memo_submit(): void
{
    $key = sns_memo_key();
    if ($key === null) {
        throw new HttpError(503, 'SNS投稿メモの接続設定が済んでいません。');
    }
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://sougoukanri.ino-vifare.com') {
        throw new HttpError(403);
    }

    $fields = [];
    foreach (['concern', 'memo'] as $name) {
        $value = $_POST[$name] ?? '';
        $fields[$name] = is_string($value) ? trim($value) : '';
    }
    $selected = array_filter((array)($_POST['menu'] ?? []), 'is_string');
    $menus = array_values(array_intersect(SNS_MEMO_MENUS, $selected));
    $id = $_POST['request_id'] ?? '';
    $error = '';
    if (!is_string($id) || !preg_match('/\A[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}\z/D', $id)
        || $fields['memo'] === '' || strlen($fields['memo']) > 3000
        || strlen($fields['concern']) > 300) {
        $error = 'ひとことメモと入力内容を確認してください。';
    }

    $payload = [
        'request_id' => $id,
        'memo' => $fields['memo'] . ($fields['concern'] === '' ? '' : "\n髪のお悩み：" . $fields['concern']),
        'menu' => implode('、', $menus),
    ];
    $photo = $_FILES['photo'] ?? null;
    if ($error === '' && $photo && ($photo['error'] ?? null) !== UPLOAD_ERR_NO_FILE) {
        if (($photo['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($photo['tmp_name'] ?? null)
            || !is_uploaded_file($photo['tmp_name']) || $photo['size'] > 3 * 1024 * 1024) {
            $error = '写真は3MB以内にしてください。';
        } else {
            $payload['photo'] = new CURLFile($photo['tmp_name'], 'application/octet-stream', 'photo');
        }
    }
    if ($error === '') {
        $connection = curl_init('https://ino-vifare.com/contents/wp-admin/admin-post.php?action=ino_blog_staff_submit');
        curl_setopt_array($connection, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['X-INO-Blog-Staff-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($connection);
        $status = curl_getinfo($connection, CURLINFO_RESPONSE_CODE);
        curl_close($connection);
        $result = is_string($body) ? json_decode($body, true) : null;
        if ($status === 200 && ($result['success'] ?? false) === true) {
            header('Location: /sns-memo?sent=1', true, 303);
            return;
        }
        $error = $status === 400 && is_string($result['data'] ?? null)
            ? $result['data']
            : '受付を確認できませんでした。少し待ってから、必要なら同じ画面で送信してください。';
    }

    header('Cache-Control: no-store');
    render('sns_memo', ['fields' => $fields, 'error' => $error, 'can_send' => true, 'menu_options' => SNS_MEMO_MENUS, 'selected_menus' => $menus, 'request_id' => is_string($id) ? $id : ''], 422);
}
