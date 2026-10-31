<?php
declare(strict_types=1);

// ── 在庫一覧に出す商品（在庫・基準数・最後に数えた後の発注数つき） ──────────────────

function products_with_stock(string $store = 'イノ', ?string $cat = null, ?string $supplier = null): array
{
    $where = '';
    // SQLの？の並び: 発注済み数の副問い合わせ2つ、在庫の店舗、基準数の店舗、そのあとに絞り込み
    $params = [$store, $store, $store, $store];
    if ($cat) {
        $where .= ' AND p.category = ?';
        $params[] = $cat;
    }
    if ($supplier) {
        $where .= ' AND p.supplier = ?';
        $params[] = $supplier;
    }
    // 最後に数えた後に発注した数（在庫に足してあるが、まだ届いていないかもしれない分）
    $ordered = "FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE oi.product_id = p.id AND o.store = ?
                  AND o.confirmed_at > COALESCE(s.checked_at, '1000-01-01 00:00:00')";
    return rows("
        SELECT p.id, p.col_group, p.category, p.name, COALESCE(pb.base_qty, p.base_qty) AS base_qty,
               p.note, p.sort_order, p.supplier,
               COALESCE(s.current_qty, 0) AS current_qty, (s.product_id IS NOT NULL) AS has_stock,
               s.changed_at, s.checked_at, COALESCE(s.version, 0) AS version,
               (SELECT SUM(oi.order_qty) {$ordered}) AS ordered_qty,
               (SELECT MAX(o.confirmed_at) {$ordered}) AS ordered_at
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id AND s.store = ?
        LEFT JOIN product_base pb ON pb.product_id = p.id AND pb.store = ?
        WHERE p.is_active = 1 {$where}
        ORDER BY p.col_group, p.sort_order
    ", $params);
}

// ── 在庫管理 ──────────────────────────────────────────────────────────────────

function valid_store(?string $s): string
{
    return in_array($s, STORES, true) ? $s : 'イノ';
}

function h_inventory(): void
{
    $store = valid_store($_GET['store'] ?? 'イノ');
    $cat = $_GET['cat'] ?? '';
    $all = products_with_stock($store);

    $firstSort = [];
    foreach ($all as $r) {
        $c = (string)$r['category'];
        if ($c !== '' && (!isset($firstSort[$c]) || $r['sort_order'] < $firstSort[$c])) {
            $firstSort[$c] = $r['sort_order'];
        }
    }
    asort($firstSort);
    $cats = array_map('strval', array_keys($firstSort));

    $staleBefore = now_jst()->modify('-' . STALE_DAYS . ' days');
    $grouped = [];
    $counts = ['unchecked' => 0, 'stale' => 0, 'shortage' => 0];
    foreach ($all as $r) {
        $checked = jst_dt($r['checked_at']);
        $r['unchecked'] = $checked === null;
        $r['stale'] = $checked !== null && $checked < $staleBefore;
        $r['shortage'] = $r['unchecked'] ? 0 : max((float)($r['base_qty'] ?? 0) - (float)($r['current_qty'] ?? 0), 0);
        $counts['unchecked'] += (int)$r['unchecked'];
        $counts['stale'] += (int)$r['stale'];
        $counts['shortage'] += (int)($r['shortage'] > 0);
        $grouped[(string)$r['category']][] = $r;
    }

    $sups = array_values(array_filter(SUPPLIER_NAMES, static function ($n) use ($all) {
        foreach ($all as $r) {
            if ($r['supplier'] === $n) {
                return true;
            }
        }
        return false;
    }));
    $catSups = [];
    foreach ($all as $r) {
        $catSups[(string)($r['category'] ?? '')][(string)($r['supplier'] ?? '')] = true;
    }
    foreach ($catSups as $c => $set) {
        $list = array_keys($set);
        sort($list, SORT_STRING);
        $catSups[$c] = array_map('strval', $list);
    }
    render('inventory', [
        'sups' => $sups, 'cat_sups' => $catSups ?: new stdClass(), 'store' => $store, 'cats' => $cats,
        'current_cat' => $cat, 'grouped' => $grouped, 'counts' => $counts, 'stale_days' => STALE_DAYS,
    ]);
}

function stock_payload(array $r): array
{
    return [
        'qty' => (float)$r['current_qty'],
        'version' => (int)$r['version'],
        'changed_at' => jst_fmt($r['changed_at']),
        'checked_at' => jst_fmt($r['checked_at']),
    ];
}

function bad_input(string $msg = '入力が不正です', int $status = 400): never
{
    respond_json(['ok' => false, 'error' => $msg], $status);
}

/**
 * 在庫数の保存。
 * op=delta: ＋／－。同時操作でも合算。数が変わったら確認日時も更新する
 * op=set:   数を直接入力。version不一致なら上書きせず409。数が変わったら確認日時も更新する
 * op=check: 実際に数えて確認した。value があればその数で保存し、確認日時を記録する
 */
function h_api_stock(): void
{
    $d = req_json() ?? [];
    if (!isset($d['product_id']) || !is_numeric($d['product_id'])) {
        bad_input();
    }
    $pid = (int)$d['product_id'];
    $op = $d['op'] ?? 'set';
    $value = $d['value'] ?? null;
    if ($value === '') {
        $value = null;
    }
    if ($value !== null && !is_numeric($value)) {
        bad_input();
    }
    $value = $value === null ? null : (float)$value;
    $store = $d['store'] ?? 'イノ';
    if (!in_array($store, STORES, true) || !in_array($op, ['delta', 'set', 'check'], true)) {
        bad_input();
    }
    if (in_array($op, ['delta', 'set'], true) && $value === null) {
        bad_input('数量がありません');
    }
    if (in_array($op, ['set', 'check'], true) && $value !== null && $value < 0) {
        bad_input('0未満は保存できません');
    }
    $expected = $d['version'] ?? null;
    if ($expected !== null && !is_numeric($expected)) {
        bad_input();
    }
    $now = now_jst();

    [$data, $status] = transaction(function () use ($pid, $store, $op, $value, $expected, $now) {
        if (!val('SELECT 1 FROM products WHERE id = ?', [$pid])) {
            return [['ok' => false, 'error' => '商品が見つかりません'], 404];
        }
        run('INSERT IGNORE INTO stock (product_id, store, current_qty, version) VALUES (?, ?, 0, 0)', [$pid, $store]);
        $cur = row('SELECT current_qty, version, changed_at, checked_at FROM stock WHERE product_id = ? AND store = ? FOR UPDATE', [$pid, $store]);
        $current = (float)($cur['current_qty'] ?? 0);
        if ($op !== 'delta' && $value !== null && $expected !== null && (int)$expected !== (int)$cur['version']) {
            return [['ok' => false, 'conflict' => true,
                'error' => '他の端末で先に更新されました。最新の数量を表示します。'] + stock_payload($cur), 409];
        }
        if ($op === 'delta') {
            $new = max($current + $value, 0.0);
        } elseif ($value !== null) {
            $new = $value;
        } else {
            $new = $current;
        }
        $changed = $new != $current;
        run('UPDATE stock SET current_qty = ?, version = version + 1,
                    changed_at = IF(?, ?, changed_at), checked_at = IF(?, ?, checked_at), updated_at = ?
             WHERE product_id = ? AND store = ?',
            [$new, $changed, $now, ($op === 'check' || $changed), $now, $now->format('Y-m-d H:i'), $pid, $store]);
        $res = row('SELECT current_qty, version, changed_at, checked_at FROM stock WHERE product_id = ? AND store = ?', [$pid, $store]);
        return [['ok' => true] + stock_payload($res), 200];
    });
    respond_json($data, $status);
}

/** 棚（カテゴリ）の一括確認。数量が一度も入力されていない商品は対象外（勝手に在庫0にしない） */
function h_api_check_category(): void
{
    $d = req_json() ?? [];
    $store = $d['store'] ?? 'イノ';
    $cat = $d['category'] ?? null;
    if (!in_array($store, STORES, true) || $cat === null) {
        bad_input();
    }
    $now = now_jst();
    $n = run('UPDATE stock s JOIN products p ON s.product_id = p.id
              SET s.checked_at = ?, s.version = s.version + 1
              WHERE s.store = ? AND p.is_active = 1 AND p.category = ?', [$now, $store, $cat]);
    $skipped = (int)val('SELECT COUNT(*) FROM products p
                         WHERE p.is_active = 1 AND p.category = ?
                           AND NOT EXISTS (SELECT 1 FROM stock s WHERE s.product_id = p.id AND s.store = ?)', [$cat, $store]);
    respond_json(['ok' => true, 'count' => $n, 'skipped' => $skipped, 'checked_at' => jst_fmt($now)]);
}

/** 在庫数に加減する（発注確定で＋、使用入力で－）。確認日時は変えない。$amounts: [商品ID => 加える数] */
function add_to_stock(string $store, array $amounts): void
{
    $now = now_jst();
    foreach ($amounts as $pid => $q) {
        if (!$q) {
            continue;
        }
        run('INSERT INTO stock (product_id, store, current_qty, version, changed_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE current_qty = current_qty + VALUES(current_qty), version = version + 1,
                                     changed_at = VALUES(changed_at), updated_at = VALUES(updated_at)',
            [(int)$pid, $store, (float)$q, $now, $now->format('Y-m-d H:i')]);
    }
}

// ── 使用入力（ラベル） ────────────────────────────────────────────────────────

function today_start(): DateTimeImmutable
{
    return now_jst()->setTime(0, 0, 0);
}

/** 前回その仕入先に発注確定した後の使用数（＝仮発注数）と、今日入力した数を商品ごとに返す */
function pending_usage(string $store, ?string $supplier = null, ?int $productId = null): array
{
    $where = '';
    $params = [today_start(), $store];
    if ($supplier) {
        $where .= ' AND p.supplier = ?';
        $params[] = $supplier;
    }
    if ($productId) {
        $where .= ' AND u.product_id = ?';
        $params[] = $productId;
    }
    $out = [];
    foreach (rows("
        SELECT u.product_id,
               SUM(u.qty) AS pending,
               SUM(CASE WHEN u.at >= ? THEN u.qty ELSE 0 END) AS today
        FROM usage_log u
        JOIN products p ON p.id = u.product_id
        WHERE u.store = ? {$where}
          AND u.id > COALESCE((SELECT MAX(o.usage_upto) FROM orders o
                               WHERE o.store = u.store AND o.supplier = p.supplier), 0)
        GROUP BY u.product_id
    ", $params) as $r) {
        $out[(int)$r['product_id']] = ['pending' => (float)$r['pending'], 'today' => (float)$r['today']];
    }
    return $out;
}

function h_usage(): void
{
    $store = valid_store($_GET['store'] ?? 'イノ');
    $supplier = $_GET['supplier'] ?? '';
    $list = rows('SELECT id, supplier, category, name, note FROM products
                  WHERE is_active = 1' . ($supplier ? ' AND supplier = ?' : '') . '
                  ORDER BY col_group, sort_order, id', $supplier ? [$supplier] : []);
    $usageMap = pending_usage($store, $supplier ?: null);
    $groups = [];
    $order = [];
    foreach ($list as $r) {
        $u = $usageMap[(int)$r['id']] ?? ['pending' => 0.0, 'today' => 0.0];
        $key = $r['supplier'] . "\0" . $r['category'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['sup' => $r['supplier'], 'cat' => $r['category'], 'prods' => []];
            $order[] = $key;
        }
        $groups[$key]['prods'][] = $r + $u;
    }
    $grouped = array_map(static fn($k) => $groups[$k], $order);
    $usageCats = [];
    foreach ($grouped as $g) {
        if (!in_array($g['cat'], $usageCats, true)) {
            $usageCats[] = $g['cat'];
        }
    }
    $todayTotal = array_sum(array_column($usageMap, 'today'));
    render('usage', ['store' => $store, 'supplier' => $supplier, 'grouped' => $grouped,
        'usage_cats' => $usageCats, 'today_total' => $todayTotal]);
}

function h_api_usage(): void
{
    $d = req_json() ?? [];
    if (!isset($d['product_id'], $d['delta']) || !is_numeric($d['product_id']) || !is_numeric($d['delta'])) {
        bad_input();
    }
    $pid = (int)$d['product_id'];
    $delta = (float)$d['delta'];
    $store = $d['store'] ?? 'イノ';
    if (!in_array($store, STORES, true) || $delta == 0.0) {
        bad_input();
    }
    $now = now_jst();
    // 商品の行をロックしてから計算するので、同じ商品への同時入力でも在庫の数はずれない
    [$data, $status] = transaction(function () use ($pid, $store, $delta, $now) {
        if (!val('SELECT id FROM products WHERE id = ? FOR UPDATE', [$pid])) {
            return [['ok' => false, 'error' => '商品が見つかりません'], 404];
        }
        $u = pending_usage($store, null, $pid)[$pid] ?? ['pending' => 0.0, 'today' => 0.0];
        if ($u['pending'] + $delta < 0) {
            return [['ok' => false, 'error' => '0より少なくはできません', 'pending' => $u['pending'], 'today' => $u['today']], 400];
        }
        run('INSERT INTO usage_log (at, store, product_id, qty) VALUES (?, ?, ?, ?)', [$now, $store, $pid, $delta]);
        add_to_stock($store, [$pid => -$delta]);
        return [['ok' => true, 'pending' => $u['pending'] + $delta, 'today' => $u['today'] + $delta], 200];
    });
    respond_json($data, $status);
}
