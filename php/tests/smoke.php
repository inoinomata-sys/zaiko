<?php
// 全部の画面・機能を、本物の MySQL につないだ状態で通しで動かして確かめる（GitHub Actions で自動実行）。
// 使い方: php php/tests/smoke.php http://127.0.0.1:8080   （先に php -S でサーバーを起動し、seed.sql を入れておく）
declare(strict_types=1);

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
$fail = 0;
$count = 0;
$failures = [];

/** 失敗の説明を短くする（500 のエラー画面なら、PHP のエラー文の部分を取り出す） */
function brief(string $detail): string
{
    if (($p = strpos($detail, '<pre')) !== false) {
        $detail = html_entity_decode(strip_tags(substr($detail, $p)));
    }
    return substr(preg_replace('/\s+/', ' ', $detail), 0, 700);
}

function ok(bool $cond, string $label, string $detail = ''): void
{
    global $fail, $count, $failures;
    $count++;
    if (!$cond) {
        $fail++;
        $failures[] = ['label' => $label, 'detail' => $detail];
        echo "NG  {$label}" . ($detail !== '' ? "\n      " . brief($detail) : '') . "\n";
    }
}

/** [状態コード, 本文, 見出し] */
function http(string $method, string $path, ?array $json = null, ?array $form = null, string $accept = 'text/html'): array
{
    global $BASE;
    $header = "Accept: {$accept}\r\n";
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 180]];
    if ($json !== null) {
        $header .= "Content-Type: application/json\r\n";
        $opts['http']['content'] = json_encode($json, JSON_UNESCAPED_UNICODE);
    } elseif ($form !== null) {
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $opts['http']['content'] = http_build_query($form);
    } elseif ($method === 'POST') {
        $opts['http']['content'] = '';
    }
    $opts['http']['header'] = $header;
    $body = @file_get_contents($BASE . $path, false, stream_context_create($opts));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    return [$status, $body === false ? '' : $body, $http_response_header ?? []];
}

function api(string $path, array $data): array
{
    [$st, $body] = http('POST', $path, $data, null, 'application/json');
    $j = json_decode($body, true);
    return [$st, is_array($j) ? $j : ['_raw' => substr($body, 0, 300)]];
}

function page_ok(string $path, string $mustContain = ''): string
{
    [$st, $body] = http('GET', $path);
    ok($st === 200, "GET {$path} が 200", "status={$st} " . substr(strip_tags($body), 0, 300));
    ok(!preg_match('/(Warning|Notice|Deprecated|Fatal error|Parse error):/', $body), "GET {$path} に PHP の警告がない",
        (string)(preg_match('/(Warning|Notice|Deprecated|Fatal error)[^\n]{0,200}/', $body, $m) ? $m[0] : ''));
    if ($mustContain !== '') {
        ok(str_contains($body, $mustContain), "GET {$path} に「{$mustContain}」がある");
    }
    return $body;
}

function pdf_ok(string $path, array $mustContainText = []): string
{
    [$st, $body] = http('GET', $path);
    ok($st === 200 && str_starts_with($body, '%PDF') && strlen($body) > 1500, "PDF {$path}", "status={$st} bytes=" . strlen($body) . ' ' . (str_contains($body, '<pre') ? $body : substr($body, 0, 200)));
    if ($mustContainText && str_starts_with($body, '%PDF') && trim((string)shell_exec('command -v pdftotext')) !== '') {
        $f = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($f, $body);
        $text = (string)shell_exec('pdftotext -layout ' . escapeshellarg($f) . ' - 2>/dev/null');
        unlink($f);
        foreach ($mustContainText as $t) {
            ok(str_contains($text, $t), "PDF {$path} の文字に「{$t}」がある", substr($text, 0, 400));
        }
    }
    return $body;
}

function db_val(string $sql): mixed
{
    $cfg = require dirname(__DIR__) . '/config.php';
    static $pdo = null;
    $pdo ??= new PDO("mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4", $cfg['db_user'], $cfg['db_pass']);
    $r = $pdo->query($sql)->fetch(PDO::FETCH_NUM);
    return $r ? $r[0] : null;
}

echo "== 画面 ==\n";
ok(trim(http('GET', '/ping')[1]) === 'ok', '/ping');
$h = json_decode(http('GET', '/health')[1], true);
ok(($h['db'] ?? false) === true && ($h['extensions']['pdo_mysql'] ?? false), '/health で DB につながる', json_encode($h));
ok(($h['font'] ?? false) === true, 'PDF のフォントが置かれている');
page_ok('/', 'ホーユートリートメント');
page_ok('/?store=' . rawurlencode('リッチ'));
page_ok('/usage', 'NCSシャンプー');
page_ok('/usage?supplier=' . rawurlencode('ワールド美容'));
page_ok('/order', 'ワールド美容');
page_ok('/order/form?store=' . rawurlencode('イノ') . '&supplier=' . rawurlencode('ワールド美容'), 'ミントトリートメント');
page_ok('/order/history');
page_ok('/settings', '発注の取り消し');
page_ok('/settings/orders');
page_ok('/settings/contacts', '山田');
page_ok('/products', 'ホーユートリートメント');
page_ok('/products?supplier=all');
page_ok('/prices', 'アルマダ1000ml');
page_ok('/customer-orders');
ok(http('GET', '/no-such-page')[0] === 404, '存在しない画面は 404');

echo "== 在庫 ==\n";
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'delta', 'value' => 2]);
ok($st === 200 && ($j['qty'] ?? null) == 7, '＋2で在庫が7になる', json_encode($j, JSON_UNESCAPED_UNICODE));
$ver = $j['version'] ?? 0;
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'set', 'value' => 4, 'version' => $ver - 1]);
ok($st === 409 && !empty($j['conflict']), '古い version での上書きは 409', json_encode($j, JSON_UNESCAPED_UNICODE));
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'set', 'value' => 4, 'version' => $ver]);
ok($st === 200 && ($j['qty'] ?? null) == 4 && $j['checked_at'] !== '', '数を直接入力すると保存され、確認日時が付く', json_encode($j, JSON_UNESCAPED_UNICODE));
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'delta', 'value' => -10]);
ok($st === 200 && ($j['qty'] ?? null) == 0, '－で0未満にはならない', json_encode($j, JSON_UNESCAPED_UNICODE));
[$st, $j] = api('/api/stock', ['product_id' => 99999, 'store' => 'イノ', 'op' => 'delta', 'value' => 1]);
ok($st === 404, '存在しない商品は 404');
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'set', 'value' => -1]);
ok($st === 400, '0未満は 400');
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'リッチ', 'op' => 'check']);
ok($st === 200 && ($j['qty'] ?? null) == 0 && $j['checked_at'] !== '', '店舗リッチで初めて「確認」すると、在庫0の行ができる', json_encode($j, JSON_UNESCAPED_UNICODE));
[$st, $j] = api('/api/stock/check_category', ['store' => 'イノ', 'category' => 'トリートメント']);
ok($st === 200 && ($j['count'] ?? 0) >= 2, '棚の一括確認', json_encode($j, JSON_UNESCAPED_UNICODE));
[$st, $j] = api('/api/stock', ['product_id' => 1, 'store' => 'イノ', 'op' => 'set', 'value' => 5]);

echo "== 使用入力と発注 ==\n";
[$st, $j] = api('/api/usage', ['product_id' => 2, 'store' => 'イノ', 'delta' => 3]);
ok($st === 200 && $j['pending'] == 3 && $j['today'] == 3, '使用 +3', json_encode($j));
[$st, $j] = api('/api/usage', ['product_id' => 2, 'store' => 'イノ', 'delta' => -1]);
ok($st === 200 && $j['pending'] == 2, '使用 −1', json_encode($j));
[$st, $j] = api('/api/usage', ['product_id' => 2, 'store' => 'イノ', 'delta' => -5]);
ok($st === 400 && $j['pending'] == 2, '使用を0より少なくはできない', json_encode($j, JSON_UNESCAPED_UNICODE));
ok((float)db_val("SELECT current_qty FROM stock WHERE product_id=2 AND store='イノ'") == -1.0, '使用入力で在庫が減る（1 − 2 = −1）');

$body = page_ok('/order/form?store=' . rawurlencode('イノ') . '&supplier=' . rawurlencode('ワールド美容'));
preg_match('/USAGE_UPTO\s*=\s*(\d+)/', $body, $m);
$upto = (int)($m[1] ?? 0);
ok($upto > 0, '発注画面に使用入力の範囲(USAGE_UPTO)が出る');
preg_match('/REQUEST_KEY\s*=\s*"([0-9a-f]{32})"/', $body, $m);
$rk = $m[1] ?? '';
ok($rk !== '', '発注画面に二重送信防止の鍵が出る');

[$st, $j] = api('/api/order_draft', ['store' => 'イノ', 'supplier' => 'ワールド美容', 'items' => ['1' => [2, 0.0]], 'custom' => [['name' => '手入力', 'spec' => '', 'qty' => 1]]]);
ok($st === 200 && !empty($j['saved_at']), '発注の下書きを保存', json_encode($j, JSON_UNESCAPED_UNICODE));
page_ok('/order/form?store=' . rawurlencode('イノ') . '&supplier=' . rawurlencode('ワールド美容'), '手入力');

$before1 = (float)db_val("SELECT current_qty FROM stock WHERE product_id=1 AND store='イノ'");
$payload = ['store' => 'イノ', 'supplier' => 'ワールド美容', 'month' => (int)date('n'), 'day' => (int)date('j'), 'usage_upto' => $upto, 'request_key' => $rk,
    'items' => [['product_id' => 1, 'order_qty' => 3, 'base_qty' => 3, 'stock_qty' => 5], ['product_id' => 2, 'order_qty' => 2],
        ['custom_name' => '手入力の品', 'order_qty' => 1], ['product_id' => 3, 'order_qty' => 0]]];
[$st, $j] = api('/order/save', $payload);
ok($st === 200 && !empty($j['order_id']), '発注を確定', json_encode($j, JSON_UNESCAPED_UNICODE));
$oid = (int)($j['order_id'] ?? 0);
ok((float)db_val("SELECT current_qty FROM stock WHERE product_id=1 AND store='イノ'") == $before1 + 3, '発注した分が在庫に足される');
ok((int)db_val("SELECT COUNT(*) FROM order_items WHERE order_id={$oid}") === 3, '数量0の行は入らない');
ok((int)db_val('SELECT COUNT(*) FROM order_drafts') === 0, '確定すると下書きが消える');
[$st, $j2] = api('/order/save', $payload);
ok($st === 200 && ($j2['order_id'] ?? 0) === $oid && !empty($j2['repeated']), '同じ鍵の二重送信は、最初の1件を返す', json_encode($j2));
$payload['request_key'] = bin2hex(random_bytes(16));
[$st, $j3] = api('/order/save', $payload);
ok($st === 409 && !empty($j3['duplicate']), '同じ日・同じ仕入先は、確認なしでは確定しない', json_encode($j3, JSON_UNESCAPED_UNICODE));
$payload['allow_duplicate'] = true;
[$st, $j4] = api('/order/save', $payload);
ok($st === 200 && ($j4['order_id'] ?? 0) !== $oid, '確認ありなら確定できる');
$oid2 = (int)($j4['order_id'] ?? 0);

[$st, $j] = api('/api/usage', ['product_id' => 2, 'store' => 'イノ', 'delta' => 1]);
ok($st === 200 && $j['pending'] == 1, '発注確定のあとは、使用数が数え直しになる（前の分は引き継がない）', json_encode($j));

page_ok("/order/{$oid}/print", 'ワールド美容');
pdf_ok("/order/{$oid}/pdf", ['ワールド美容 御中', '手入力の品']);
pdf_ok("/order/{$oid}/pdf?inline=1");
ok(db_val("SELECT pdf_ver FROM orders WHERE id={$oid}") === '3', '作ったPDFは保存される');
page_ok('/order/history', 'ワールド美容');
page_ok('/settings/orders', '取り消す');

$after1 = (float)db_val("SELECT current_qty FROM stock WHERE product_id=1 AND store='イノ'");
[$st, $j] = api("/order/{$oid2}/delete", []);
ok($st === 200, '発注の取り消し');
ok((float)db_val("SELECT current_qty FROM stock WHERE product_id=1 AND store='イノ'") == $after1 - 3, '取り消すと、足した分が在庫から引かれる');
ok((int)db_val("SELECT COUNT(*) FROM orders WHERE id={$oid2}") === 0, '取り消した発注は消える');
[$st, $j] = api("/order/{$oid2}/delete", []);
ok($st === 404, '2回目の取り消しは 404');

echo "== お客様注文 ==\n";
$cust = ['customer' => '田中 花子', 'phone' => '090-1111-2222', 'memo' => 'テスト', 'store' => 'リッチ', 'campaign' => true, 'discount_pct' => 16,
    'request_key' => bin2hex(random_bytes(16)),
    'items' => [['price_id' => 1, 'qty' => 3], ['price_id' => 2, 'qty' => 7], ['price_id' => 3, 'qty' => 2]],
    'customs' => [['name' => '手書きの品', 'price' => 1000, 'qty' => 1]]];
[$st, $j] = api('/customer-orders/save', $cust);
ok($st === 200 && !empty($j['id']), 'お客様注文を保存', json_encode($j, JSON_UNESCAPED_UNICODE));
$cid = (int)($j['id'] ?? 0);
[$st, $jr] = api('/customer-orders/save', $cust);
ok($st === 200 && ($jr['id'] ?? 0) === $cid && !empty($jr['repeated']), '同じ鍵の二重送信は、最初の1件を返す');
$body = page_ok("/customer-orders/{$cid}", 'アルマダ特別割引');
// 手書きの品（1,000円）を含めた金額。アルマダ1000mlだけ、16%引きのあとにさらに5%（= 20.2%）
$expected = [[13200, 16.0], [77000, round(100 - (100 - 16) * 0.95, 2)], [5300, 16.0], [1000, 16.0]];
$sub = 0; $pay = 0;
foreach ($expected as [$a, $pct]) {
    $sub += $a;
    $p100 = (int)round($pct * 100);
    $pay += min($a, intdiv($a * (10000 - $p100) + 100000 - 1, 100000) * 10);
}
ok(str_contains($body, '¥' . number_format($pay)), "お客様注文の合計に ¥" . number_format($pay) . ' が出る', substr(strip_tags($body), 0, 0));
ok(str_contains($body, '16%引き'), '行ごとに「16%引き」が出る');
page_ok('/customer-orders', '田中');
page_ok("/prices?order={$cid}", '田中');
pdf_ok("/customer-orders/{$cid}/pdf", ['ご注文用紙', '合計', number_format($pay)]);
pdf_ok('/customer-orders/unordered/pdf', ['未発注の商品']);
pdf_ok("/customer-orders/unordered/pdf?order={$cid}&store=" . rawurlencode('リッチ'), ['田中']);
[$st, $j] = api("/customer-orders/{$cid}/ordered", ['ordered' => true]);
ok($st === 200 && (int)db_val("SELECT COUNT(*) FROM customer_order_items WHERE order_id={$cid} AND ordered=1") === 4, '全部を発注済みにする');
[$st, $j] = api("/customer-orders/{$cid}/ordered", ['ordered' => false]);
$iid = (int)db_val("SELECT id FROM customer_order_items WHERE order_id={$cid} AND price_id=2");
[$st, $j] = api("/customer-orders/{$cid}/items/{$iid}/ordered", ['ordered' => true]);
ok($st === 200 && (int)db_val("SELECT ordered FROM customer_order_items WHERE id={$iid}") === 1, '商品1つを発注済みにする');
[$st, $j] = api("/customer-orders/{$cid}/items/99999/ordered", ['ordered' => true]);
ok($st === 404, '存在しない商品は 404');
$cust['id'] = $cid;
$cust['items'][] = ['price_id' => 4, 'qty' => 1];
$cust['request_key'] = null;
[$st, $j] = api('/customer-orders/save', $cust);
ok($st === 200 && ($j['id'] ?? 0) === $cid, '直して保存');
ok((int)db_val("SELECT ordered FROM customer_order_items WHERE order_id={$cid} AND price_id=2") === 1, '直して保存しても、発注済みの印は残る');
ok((int)db_val("SELECT COUNT(*) FROM customer_order_items WHERE order_id={$cid}") === 5, '商品が増えた分だけ行が増える');
[$st, $j] = api('/customer-orders/save', ['customer' => '', 'items' => []]);
ok($st === 400, 'お客様名なしは 400');
[$st, $j] = api('/customer-orders/save', ['customer' => 'A', 'store' => 'イノ', 'items' => [['price_id' => 999, 'qty' => 1]]]);
ok($st === 400, '価格表にない商品は 400');
pdf_ok('/prices/pdf', ['商品価格表', 'アルマダ1000ml', '11,000']);
pdf_ok('/prices/pdf');
[$st, $j] = api("/customer-orders/{$cid}/delete", []);
ok($st === 200 && (int)db_val("SELECT COUNT(*) FROM customer_order_items WHERE order_id={$cid}") === 0, 'お客様注文を削除すると、明細も消える');

echo "== 商品マスターと担当者 ==\n";
[$st, $body] = http('POST', '/products/add', null, ['name' => 'テスト新商品', 'category' => 'トリートメント', 'supplier' => 'ワールド美容',
    'base_qty_ino' => '3', 'base_qty_rich' => '1', 'request_key' => 'k' . bin2hex(random_bytes(8))], 'application/json');
$j = json_decode($body, true);
ok($st === 200 && !empty($j['ok']) && str_contains((string)($j['url'] ?? ''), '#p'), '商品を追加（JSON）', $body);
$newId = (int)db_val("SELECT id FROM products WHERE name='テスト新商品'");
ok($newId > 0, '追加した商品がある');
ok((int)db_val("SELECT COUNT(*) FROM product_base WHERE product_id={$newId}") === 2, '店舗ごとの基準数が2つ入る');
ok((float)db_val("SELECT base_qty FROM product_base WHERE product_id={$newId} AND store='リッチ'") == 1.0, 'リッチの基準数が入る');
[$st] = http('POST', '/products/add', null, ['name' => '', 'supplier' => 'ワールド美容']);
ok($st === 302, '商品名が空ならもどる（302）');
[$st, $j] = api("/products/{$newId}/move", ['dir' => 'up']);
ok($st === 200 && !empty($j['ok']), '商品を上へ', json_encode($j));
[$st, $j] = api("/products/{$newId}/move", ['dir' => 'sideways']);
ok($st === 400, '向きが不正なら 400');
[$st, $body] = http('POST', "/products/{$newId}/base", null, ['store' => 'イノ', 'base_qty' => '9']);
ok($st === 200 && (float)db_val("SELECT base_qty FROM product_base WHERE product_id={$newId} AND store='イノ'") == 9.0, '基準数を変える');
[$st, $body] = http('POST', "/products/{$newId}/supplier", null, ['supplier' => 'コタ']);
ok($st === 200 && db_val("SELECT supplier FROM products WHERE id={$newId}") === 'コタ', '仕入先を変える');
[$st] = http('POST', "/products/{$newId}/toggle", null, []);
ok($st === 302 && (int)db_val("SELECT is_active FROM products WHERE id={$newId}") === 0, '無効化する');
[$st] = http('POST', '/settings/contacts/add', null, ['supplier_name' => 'コタ', 'contact_name' => '鈴木', 'fax' => '06-1234-5678']);
ok($st === 302 && (int)db_val("SELECT COUNT(*) FROM supplier_contacts WHERE contact_name='鈴木'") === 1, '担当者を足す');
$cidc = (int)db_val("SELECT id FROM supplier_contacts WHERE contact_name='鈴木'");
[$st] = http('POST', "/settings/contacts/{$cidc}/update", null, ['contact_name' => '鈴木二郎', 'fax' => '']);
ok($st === 302 && (int)db_val("SELECT COUNT(*) FROM supplier_contacts WHERE contact_name='鈴木二郎'") === 1, '担当者を直す');
page_ok('/settings/contacts', '鈴木二郎');
[$st] = http('POST', "/settings/contacts/{$cidc}/delete", null, []);
ok($st === 302 && (int)db_val("SELECT COUNT(*) FROM supplier_contacts WHERE id={$cidc}") === 0, '担当者を消す');

echo "\n{$count} 件を確認、失敗 {$fail} 件\n";
if ($failures) {
    echo "=== 失敗の一覧 ===\n";
    $seen = [];
    foreach ($failures as $f) {
        $b = brief($f['detail']);
        $key = md5($f['label'] . $b);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        echo "NG {$f['label']} :: " . substr($b, 0, 600) . "\n";
    }
}
exit($fail ? 1 : 0);
