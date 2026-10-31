<?php
declare(strict_types=1);

// ── 仕入先と担当者 ─────────────────────────────────────────────────────────────

function get_suppliers_with_contacts(): array
{
    $result = [];
    foreach (SUPPLIER_NAMES as $name) {
        $result[$name] = ['name' => $name, 'contacts' => []];
    }
    foreach (rows('SELECT supplier_name, contact_name, fax, id FROM supplier_contacts ORDER BY supplier_name, sort_order, id') as $r) {
        $sn = $r['supplier_name'];
        $result[$sn] ??= ['name' => $sn, 'contacts' => []];
        $result[$sn]['contacts'][] = ['id' => $r['id'], 'name' => $r['contact_name'], 'fax' => $r['fax']];
    }
    return array_values($result);
}

function supplier_info(string $name): array
{
    foreach (get_suppliers_with_contacts() as $s) {
        if ($s['name'] === $name) {
            return $s;
        }
    }
    return ['name' => $name, 'contacts' => []];
}

function h_order_top(): void
{
    $store = $_GET['store'] ?? '';
    $counts = [];
    foreach (rows('SELECT supplier, COUNT(*) AS c FROM products WHERE is_active = 1 GROUP BY supplier') as $r) {
        $counts[$r['supplier']] = (int)$r['c'];
    }
    $list = array_map(static fn($s) => $s + ['count' => $counts[$s['name']] ?? 0], get_suppliers_with_contacts());
    render('order_top', ['suppliers' => $list, 'store' => $store]);
}

// ── 発注画面 ──────────────────────────────────────────────────────────────────

function h_order_form(): void
{
    $store = $_GET['store'] ?? 'イノ';
    $supplier = $_GET['supplier'] ?? 'ワールド美容';
    $now = now_jst();

    // この画面に出した使用数の範囲（これより後に入力された使用は次回の発注に回す）
    $usageUpto = (int)val('SELECT COALESCE(MAX(id), 0) FROM usage_log');
    $usageMap = pending_usage($store, $supplier);
    $orderedToday = in_array($supplier, NO_HISTORY_SUPPLIERS, true) ? 0 : (int)val(
        'SELECT COUNT(*) FROM orders WHERE store = ? AND supplier = ? AND order_month = ? AND order_day = ?',
        [$store, $supplier, (int)$now->format('n'), (int)$now->format('j')]
    );
    $draftRow = row('SELECT data, saved_at FROM order_drafts WHERE store = ? AND supplier = ?', [$store, $supplier]);
    $draft = $draftRow ? (json_decode((string)$draftRow['data'], true) ?: []) : [];
    $draftItems = $draft['items'] ?? [];

    $grouped = [];
    $counts = ['unchecked' => 0, 'stale' => 0, 'usage' => 0];
    foreach (products_with_stock($store, null, $supplier) as $r) {
        $r['usage'] = $usageMap[(int)$r['id']]['pending'] ?? 0.0;
        $counts['usage'] += (int)($r['usage'] > 0);
        // 保存した下書きがあればその数。下書きの後に増えた使用数は上乗せする
        $default = (int)ceil($r['usage']);
        $saved = $draftItems[(string)$r['id']] ?? null;
        $r['qty0'] = !$saved ? $default : max(0, (int)$saved[0] + (int)ceil(max(0.0, $r['usage'] - (float)$saved[1])));
        $checked = jst_dt($r['checked_at']);
        $r['unchecked'] = $checked === null;
        $r['days'] = $checked === null ? null : (int)floor(($now->getTimestamp() - $checked->getTimestamp()) / 86400);
        $r['stale'] = !$r['unchecked'] && $r['days'] >= STALE_DAYS;
        // 数えた記録がない商品は在庫数が分からないので、不足数は出さない（在庫0扱いで発注しないため）
        $r['shortage'] = $r['unchecked'] ? 0 : max((float)($r['base_qty'] ?? 0) - (float)($r['current_qty'] ?? 0), 0);
        $counts['unchecked'] += (int)$r['unchecked'];
        $counts['stale'] += (int)$r['stale'];
        $grouped[(string)$r['category']][] = $r;
    }
    render('order', [
        'store' => $store, 'grouped' => $grouped, 'cats' => array_map('strval', array_keys($grouped)), 'supplier' => $supplier,
        'sup_info' => supplier_info($supplier), 'counts' => $counts, 'stale_days' => STALE_DAYS,
        'usage_upto' => $usageUpto, 'ordered_today' => $orderedToday, 'request_key' => bin2hex(random_bytes(16)),
        'draft_custom' => $draft['custom'] ?? [], 'draft_saved' => $draftRow ? jst_fmt($draftRow['saved_at']) : '',
    ]);
}

/** 発注画面の入力途中の数を保存する（自動保存・保存ボタン共通） */
function h_api_order_draft(): void
{
    $d = req_json();
    if ($d === null) {
        bad_input();
    }
    $store = $d['store'] ?? null;
    $supplier = $d['supplier'] ?? null;
    if (!in_array($store, STORES, true) || !in_array($supplier, SUPPLIER_NAMES, true)) {
        bad_input();
    }
    $items = [];
    foreach (($d['items'] ?? []) as $k => $v) {
        if (!is_numeric($k) || !is_array($v) || !isset($v[0], $v[1]) || !is_numeric($v[0]) || !is_numeric($v[1])) {
            bad_input();
        }
        $items[(string)(int)$k] = [(int)$v[0], (float)$v[1]];
    }
    $custom = [];
    foreach (array_slice((array)($d['custom'] ?? []), 0, 100) as $c) {
        if (!is_array($c)) {
            bad_input();
        }
        $custom[] = ['name' => mb_substr((string)($c['name'] ?? ''), 0, 200), 'spec' => mb_substr((string)($c['spec'] ?? ''), 0, 100),
            'qty' => max(0, (int)($c['qty'] ?? 0))];
    }
    $key = $d['request_key'] ?? null;
    if ($key && val('SELECT 1 FROM orders WHERE request_key = ?', [$key])) {
        // この画面ですでに発注を確定していたら、下書きは作り直さない
        respond_json(['ok' => true, 'skipped' => true]);
    }
    $now = now_jst();
    run('INSERT INTO order_drafts (store, supplier, data, saved_at) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE data = VALUES(data), saved_at = VALUES(saved_at)',
        [$store, $supplier, json_encode(['items' => (object)$items, 'custom' => $custom], JSON_UNESCAPED_UNICODE), $now]);
    respond_json(['ok' => true, 'saved_at' => jst_fmt($now)]);
}

function h_api_order_draft_clear(): void
{
    $d = req_json() ?? [];
    run('DELETE FROM order_drafts WHERE store = ? AND supplier = ?', [$d['store'] ?? '', $d['supplier'] ?? '']);
    respond_json(['ok' => true]);
}

// ── 発注の確定 ────────────────────────────────────────────────────────────────

function h_order_save(): void
{
    $d = req_json();
    if ($d === null) {
        bad_input();
    }
    $store = $d['store'] ?? 'イノ';
    $supplier = $d['supplier'] ?? 'ワールド美容';
    $items = $d['items'] ?? [];
    $requestKey = ($d['request_key'] ?? null) ?: null;
    if (!is_array($items)) {
        bad_input();
    }
    $monthRaw = $d['month'] ?? null;
    $dayRaw = $d['day'] ?? null;
    $month = ($monthRaw === null || $monthRaw === '') ? null : (int)$monthRaw;
    $day = ($dayRaw === null || $dayRaw === '') ? null : (int)$dayRaw;
    $usageUpto = isset($d['usage_upto']) ? (int)$d['usage_upto'] : null;
    $clean = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            bad_input();
        }
        $qty = (float)($it['order_qty'] ?? 0);
        if ($qty <= 0) {
            continue;
        }
        $it['order_qty'] = $qty;
        $it['product_id'] = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        $clean[] = $it;
    }
    $items = $clean;
    if (!in_array($store, STORES, true) || !in_array($supplier, SUPPLIER_NAMES, true)) {
        bad_input('店舗または仕入先が不正です');
    }
    if ($items) {
        $ids = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
        if ($ids && (int)val('SELECT COUNT(*) FROM products WHERE id IN (' . marks($ids) . ')', $ids) !== count($ids)) {
            bad_input('存在しない商品が含まれています。画面を読み込み直してください');
        }
    }
    [$data, $status] = transaction(function () use ($store, $supplier, $items, $requestKey, $month, $day, $usageUpto, $d) {
        // 同じ画面からの二重送信（連打・通信のやり直し）は、最初の1件をそのまま返す
        if ($requestKey) {
            $done = val('SELECT id FROM orders WHERE request_key = ? FOR UPDATE', [$requestKey]);
            if ($done) {
                return [['ok' => true, 'order_id' => (int)$done, 'repeated' => true], 200];
            }
        }
        // 同じ店舗・仕入先・発注日の発注が既にあれば、確認なしでは確定しない
        if (empty($d['allow_duplicate']) && !in_array($supplier, NO_HISTORY_SUPPLIERS, true)) {
            if (val('SELECT COUNT(*) FROM orders WHERE store = ? AND supplier = ? AND order_month = ? AND order_day = ?',
                [$store, $supplier, $month, $day])) {
                return [['ok' => false, 'duplicate' => true,
                    'error' => "{$month}月{$day}日の {$store}・{$supplier} の発注はすでに確定済みです。"], 409];
            }
        }
        $id = insert_order($store, $supplier, $month, $day, $items, $requestKey, $usageUpto);
        run('DELETE FROM order_drafts WHERE store = ? AND supplier = ?', [$store, $supplier]);
        return [['ok' => true, 'order_id' => $id], 200];
    });
    if (!empty($data['ok']) && empty($data['repeated'])) {
        $oid = (int)$data['order_id'];
        respond_json_after($data, $status, static fn() => warm_pdf($oid));
    }
    respond_json($data, $status);
}

function insert_order(string $store, string $supplier, ?int $month, ?int $day, array $items, ?string $requestKey, ?int $usageUpto): int
{
    $now = now_jst();
    run("INSERT INTO orders (store, supplier, order_month, order_day, status, created_at, request_key, usage_upto, confirmed_at)
         VALUES (?, ?, ?, ?, 'confirmed', ?, ?, ?, ?)",
        [$store, $supplier, $month, $day, $now->format('Y-m-d H:i'), $requestKey, $usageUpto, $now]);
    $orderId = last_id();
    $rowsData = [];
    $amounts = [];
    foreach ($items as $it) {
        $qty = (float)$it['order_qty'];
        if ($qty <= 0) {
            continue;
        }
        $pid = !empty($it['product_id']) ? (int)$it['product_id'] : null;
        $rowsData[] = [$orderId, $pid, is_numeric($it['base_qty'] ?? null) ? (float)$it['base_qty'] : 0.0,
            is_numeric($it['stock_qty'] ?? null) ? (float)$it['stock_qty'] : 0.0, $qty, nfkc($it['custom_name'] ?? null)];
        if ($pid) {
            $amounts[$pid] = ($amounts[$pid] ?? 0) + $qty;
        }
    }
    if ($rowsData) {
        insert_many('order_items', ['order_id', 'product_id', 'base_qty', 'stock_qty', 'order_qty', 'custom_name'], $rowsData, 500);
    }
    // 入荷処理はしない運用なので、発注した時点で在庫に足す（届く予定の分も在庫として扱う）
    add_to_stock($store, $amounts);
    return $orderId;
}

// ── 発注書の表示・印刷 ─────────────────────────────────────────────────────────

/** 発注書1件分を、カテゴリ順のブロック [カテゴリ名 => [{name, note, qty}]] として返す */
function load_order(int $orderId): array
{
    $order = row('
        SELECT o.id, o.store, o.supplier, o.order_month, o.order_day, o.created_at,
               (SELECT c.contact_name FROM supplier_contacts c WHERE c.supplier_name = o.supplier
                ORDER BY c.sort_order, c.id LIMIT 1) AS contact_name
        FROM orders o WHERE o.id = ?', [$orderId]);
    if (!$order) {
        throw new HttpError(404);
    }
    $blocks = [];
    foreach (rows('
        SELECT oi.order_qty, oi.custom_name, p.name, p.category, p.note
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
        ORDER BY (p.col_group IS NULL), p.col_group, (p.sort_order IS NULL), p.sort_order, oi.id', [$orderId]) as $r) {
        if ($r['name'] !== null) {
            $blocks[($r['category'] ?: 'その他')][] = ['name' => $r['name'], 'note' => $r['note'], 'qty' => $r['order_qty']];
        } elseif ($r['custom_name']) {
            $blocks[CUSTOM_CAT][] = ['name' => $r['custom_name'], 'note' => null, 'qty' => $r['order_qty']];
        }
    }
    return [$order, $blocks];
}

function order_sup_info(array $order): array
{
    $name = $order['supplier'] ?: 'ワールド美容';
    $contact = $order['contact_name'] ?? null;
    return ['name' => $name, 'contacts' => $contact ? [['name' => $contact]] : []];
}

function h_order_print(int $id): void
{
    [$order, $blocks] = load_order($id);
    $itemCount = 0;
    $totalQty = 0.0;
    foreach ($blocks as $items) {
        $itemCount += count($items);
        foreach ($items as $it) {
            $totalQty += (float)$it['qty'];
        }
    }
    render('print', ['order' => $order, 'blocks' => $blocks, 'sup_info' => order_sup_info($order),
        'item_count' => $itemCount, 'total_qty' => $totalQty]);
}

function pdf_filename(array $order): string
{
    return "発注書_{$order['order_month']}月{$order['order_day']}日_" . ($order['supplier'] ?: 'ワールド美容') . '.pdf';
}

function h_order_pdf(int $id): void
{
    $row = row('SELECT order_month, order_day, supplier, pdf, pdf_ver FROM orders WHERE id = ?', [$id]);
    if (!$row) {
        throw new HttpError(404);
    }
    if ($row['pdf'] !== null && $row['pdf_ver'] === PDF_VER) {
        $data = (string)$row['pdf'];
    } else {
        require_once __DIR__ . '/pdf.php';
        $data = build_order_pdf_file($id);
        run('UPDATE orders SET pdf = ?, pdf_ver = ? WHERE id = ?', [$data, PDF_VER, $id]);
    }
    respond_pdf($data, pdf_filename($row), !empty($_GET['inline']));
}

/** 発注確定の直後に裏でPDFを作って保存しておく（印刷画面を開く頃には用意できているように） */
function warm_pdf(int $orderId): void
{
    require_once __DIR__ . '/pdf.php';
    $data = build_order_pdf_file($orderId);
    run('UPDATE orders SET pdf = ?, pdf_ver = ? WHERE id = ? AND pdf IS NULL', [$data, PDF_VER, $orderId]);
}

// ── 発注履歴・取り消し ─────────────────────────────────────────────────────────

function group_by_date(array $orders): array
{
    $groups = [];
    foreach ($orders as $o) {
        $k = $o['order_month'] . '/' . $o['order_day'];
        $groups[$k] ??= ['month' => $o['order_month'], 'day' => $o['order_day'], 'orders' => []];
        $groups[$k]['orders'][] = $o;
    }
    return array_values($groups);
}

function h_order_history(): void
{
    $orders = rows('
        SELECT o.id, o.store, o.supplier, o.order_month, o.order_day, o.status, o.created_at,
               COUNT(oi.id) AS item_count, COALESCE(SUM(oi.order_qty), 0) AS total_qty
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.supplier NOT IN (' . marks(NO_HISTORY_SUPPLIERS) . ')
        GROUP BY o.id
        ORDER BY o.order_month DESC, o.order_day DESC, o.created_at DESC
        LIMIT 200', NO_HISTORY_SUPPLIERS);
    render('history', ['grouped' => group_by_date($orders),
        'history_suppliers' => array_values(array_diff(SUPPLIER_NAMES, NO_HISTORY_SUPPLIERS))]);
}

/** 発注の取り消し（設定の中）。発注履歴の画面からは分けてある */
function h_settings_orders(): void
{
    $orders = rows('
        SELECT o.id, o.store, o.supplier, o.order_month, o.order_day,
               COUNT(oi.id) AS item_count, COALESCE(SUM(oi.order_qty), 0) AS total_qty
        FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.order_month DESC, o.order_day DESC, o.created_at DESC
        LIMIT 100');
    render('settings_orders', ['grouped' => group_by_date($orders)]);
}

/** 発注の取り消し。確定した時に在庫へ足した分を引き、履歴から消す。使用入力の基準は、この発注より前の発注に戻る */
function h_order_delete(int $id): void
{
    $now = now_jst();
    [$data, $status] = transaction(function () use ($id, $now) {
        $o = row('SELECT store FROM orders WHERE id = ? FOR UPDATE', [$id]);
        if (!$o) {
            return [['ok' => false, 'error' => '発注が見つかりません'], 404];
        }
        foreach (rows('SELECT product_id, SUM(order_qty) AS q FROM order_items WHERE order_id = ? AND product_id IS NOT NULL GROUP BY product_id', [$id]) as $r) {
            if ((float)$r['q'] == 0.0) {
                continue;
            }
            run('UPDATE stock SET current_qty = GREATEST(current_qty - ?, 0), version = version + 1, changed_at = ?, updated_at = ?
                 WHERE product_id = ? AND store = ?', [(float)$r['q'], $now, $now->format('Y-m-d H:i'), (int)$r['product_id'], $o['store']]);
        }
        run('DELETE FROM order_items WHERE order_id = ?', [$id]);
        run('DELETE FROM orders WHERE id = ?', [$id]);
        return [['ok' => true], 200];
    });
    respond_json($data, $status);
}
