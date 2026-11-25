<?php
declare(strict_types=1);

const SNS_MEMO_SHOPS = [
    'INO' => ['縮毛矯正', '顔周りの縮毛矯正', 'カット', 'カラー', 'パーマ'],
    'リッチ' => ['リタッチ（根元染め）', '全体染め'],
];

function sns_memo_key(): ?string
{
    $key = (string)($GLOBALS['CONFIG']['ino_blog_staff_key'] ?? '');
    if (!preg_match('/\A[0-9a-f]{64}\z/D', $key) || empty($GLOBALS['CONFIG']['basic_auth']['user'])) {
        return null;
    }
    return $key;
}

function sns_memo_image_availability(): array
{
    return [
        'journal' => (bool)($GLOBALS['CONFIG']['ino_journal_images_available'] ?? true),
        'ino' => (bool)($GLOBALS['CONFIG']['ino_sns_images_available'] ?? true),
    ];
}

function h_sns_memo(): void
{
    header('Cache-Control: no-store');
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    render('sns_memo', ['sent' => isset($_GET['sent']), 'kind' => ($_GET['sent'] ?? '') === 'journal' ? 'journal' : 'sns', 'request_id' => $id, 'can_send' => sns_memo_key() !== null, 'images_available' => sns_memo_image_availability(), 'shops' => SNS_MEMO_SHOPS, 'shop' => 'INO']);
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
    foreach (['concern', 'menu_other', 'memo'] as $name) {
        $value = $_POST[$name] ?? '';
        $fields[$name] = is_string($value) ? trim($value) : '';
    }
    $shop = $_POST['shop'] ?? 'INO';
    if (!is_string($shop) || !isset(SNS_MEMO_SHOPS[$shop])) {
        throw new HttpError(400, '店舗を選び直してください。');
    }
    $kind = $_POST['kind'] ?? 'sns';
    if (!in_array($kind, ['sns', 'journal'], true) || ($kind === 'journal' && $shop !== 'リッチ')) {
        throw new HttpError(400, '作成する内容を選び直してください。');
    }
    $generate_image = ($kind === 'journal' || $shop === 'INO') && ($_POST['generate_image'] ?? '') === '1';
    $images_available = sns_memo_image_availability();
    $photo = $_FILES['photo'] ?? null;
    $has_photo = $photo && ($photo['error'] ?? null) !== UPLOAD_ERR_NO_FILE;
    if ($has_photo) {
        $generate_image = false;
    }
    $selected = array_filter((array)($_POST['menu'] ?? []), 'is_string');
    $menus = array_values(array_intersect(SNS_MEMO_SHOPS[$shop], $selected));
    $id = $_POST['request_id'] ?? '';
    $error = '';
    if (!is_string($id) || !preg_match('/\A[\da-f]{8}-[\da-f]{4}-4[\da-f]{3}-[89ab][\da-f]{3}-[\da-f]{12}\z/D', $id)
        || $fields['memo'] === '' || strlen($fields['memo']) > 3000
        || strlen($fields['concern']) > 300 || strlen($fields['menu_other']) > 300) {
        $error = 'ひとことメモと入力内容を確認してください。';
    }

    if ($generate_image && !$images_available[$kind === 'journal' ? 'journal' : 'ino']) {
        $error = '現在、画像の自動生成は利用できません。本文だけで下書きを作れます。';
    }

    $memo_parts = [$fields['memo']];
    if ($fields['concern'] !== '') {
        $memo_parts[] = '髪のお悩み：' . $fields['concern'];
    }
    if ($fields['menu_other'] !== '') {
        $memo_parts[] = '施術メニュー（自由記入）：' . $fields['menu_other'];
    }

    $payload = [
        'request_id' => $id,
        'shop' => $shop,
        'kind' => $kind,
        'generate_image' => $generate_image ? '1' : '0',
        'memo' => implode("\n", $memo_parts),
        'menu' => implode('、', $menus),
    ];
    if ($error === '' && $has_photo && $kind === 'journal') {
        $error = 'コラムの写真は、下書きができてからWordPressで追加してください。';
    }
    if ($error === '' && $has_photo) {
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
            header('Location: /sns-memo?sent=' . ($kind === 'journal' ? 'journal' : '1'), true, 303);
            return;
        }
        $error = $status === 400 && is_string($result['data'] ?? null)
            ? $result['data']
            : '受付を確認できませんでした。少し待ってから、必要なら同じ画面で送信してください。';
    }

    header('Cache-Control: no-store');
    render('sns_memo', ['fields' => $fields, 'error' => $error, 'can_send' => true, 'images_available' => $images_available, 'generate_image' => $generate_image, 'shops' => SNS_MEMO_SHOPS, 'shop' => $shop, 'kind' => $kind, 'selected_menus' => $menus, 'request_id' => is_string($id) ? $id : ''], 422);
}
