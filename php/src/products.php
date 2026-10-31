<?php
declare(strict_types=1);

// ── 商品マスター管理 ──────────────────────────────────────────────────────────

function h_products(): void
{
    require_admin();
    $supplier = $_GET['supplier'] ?? SUPPLIER_NAMES[0];
    $all = rows('SELECT * FROM products ORDER BY col_group, sort_order, id');
    $perStore = [];
    foreach (rows('SELECT product_id, store, base_qty FROM product_base') as $b) {
        $perStore[(int)$b['product_id']][$b['store']] = $b['base_qty'];
    }
    $counts = [];
    foreach ($all as &$r) {
        // 店舗ごとの基準在庫（未入力の店舗は、これまでの共通の基準数）
        $r['base_by_store'] = [];
        foreach (STORES as $st) {
            $r['base_by_store'][$st] = $perStore[(int)$r['id']][$st] ?? $r['base_qty'];
        }
        $counts[$r['supplier']] = ($counts[$r['supplier']] ?? 0) + 1;
    }
    unset($r);
    $list = $supplier === 'all' ? $all : array_values(array_filter($all, static fn($r) => $r['supplier'] === $supplier));
    $categories = [];
    foreach ($list as $r) {
        if ($r['category'] !== '' && $r['category'] !== null && !in_array($r['category'], $categories, true)) {
            $categories[] = $r['category'];
        }
    }
    render('products', ['rows' => $list, 'categories' => $categories, 'current_supplier' => $supplier,
        'counts' => $counts, 'total' => count($all)]);
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function h_product_add(): void
{
    require_admin();
    $category = nfkc($_POST['category'] ?? '') ?? '';
    $name = nfkc($_POST['name'] ?? '');
    $note = nfkc($_POST['note'] ?? null) ?: null;
    $supplier = ($_POST['supplier'] ?? '') ?: SUPPLIER_NAMES[0];
    $requestKey = ($_POST['request_key'] ?? '') ?: null;
    $num = static function (string $key, float $default): float {
        $v = $_POST[$key] ?? '';
        if ($v === '' || $v === null) {
            return max($default, 0.0);
        }
        return is_numeric($v) ? max((float)$v, 0.0) : $default;
    };
    // 店舗ごとの基準数（どちらか片方だけ入力された時は、もう片方も同じ数にする）
    $baseIno = $num('base_qty_ino', $num('base_qty', 1.0));
    $baseRich = $num('base_qty_rich', $num('base_qty', 1.0));
    $fail = static function (string $msg) use ($supplier): never {
        if (wants_json()) {
            respond_json(['ok' => false, 'error' => $msg], 400);
        }
        redirect_to('/products?' . http_build_query(['supplier' => $supplier]));
    };
    if (!in_array($supplier, SUPPLIER_NAMES, true)) {
        $fail('仕入先が不正です');
    }
    if (!$name) {
        $fail('商品名を入力してください');
    }
    if (mb_strlen($name) > 200 || mb_strlen($category) > 60 || ($note && mb_strlen($note) > 100)) {
        $fail('文字数が多すぎます（商品名200・カテゴリ60・備考100文字まで）');
    }
    $result = transaction(function () use ($category, $name, $note, $supplier, $requestKey, $baseIno, $baseRich) {
        if ($requestKey) {
            // 通信が切れて自動でやり直した時に、同じ商品が2件できないようにする
            $done = val('SELECT id FROM products WHERE request_key = ?', [$requestKey]);
            if ($done) {
                return ['id' => (int)$done, 'repeated' => true];
            }
        }
        // 同じ仕入先・同じカテゴリの最後の商品のすぐ後ろに入れる（一覧でそのカテゴリの中に並ぶように）
        $last = row('SELECT col_group, sort_order FROM products WHERE supplier = ? AND category = ?
                     ORDER BY col_group DESC, sort_order DESC, id DESC LIMIT 1', [$supplier, $category]);
        if ($last) {
            $colGroup = (int)$last['col_group'];
            $pos = (int)$last['sort_order'] + 1;
            run('UPDATE products SET sort_order = sort_order + 1 WHERE col_group = ? AND sort_order >= ?', [$colGroup, $pos]);
        } else {
            // 新しいカテゴリは最後の列の一番後ろ
            $colGroup = (int)val('SELECT COALESCE(MAX(col_group), 1) FROM products');
            $pos = (int)val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM products WHERE col_group = ?', [$colGroup]);
        }
        run('INSERT INTO products (col_group, category, name, base_qty, note, supplier, sort_order, request_key)
             VALUES (?,?,?,?,?,?,?,?)', [$colGroup, $category, $name, $baseIno, $note, $supplier, $pos, $requestKey]);
        $newId = last_id();
        run("INSERT INTO product_base (product_id, store, base_qty) VALUES (?, 'イノ', ?), (?, 'リッチ', ?)",
            [$newId, $baseIno, $newId, $baseRich]);
        return ['id' => $newId];
    });
    $url = '/products?' . http_build_query(['supplier' => $supplier, 'added' => $name]) . '#p' . $result['id'];
    if (wants_json()) {
        respond_json(['ok' => true, 'url' => $url] + (isset($result['repeated']) ? ['repeated' => true] : []));
    }
    redirect_to($url);
}

/** 同じ仕入先・同じカテゴリの中で、1つ上(up)または下(down)の商品と並び順を入れ替える */
function h_product_move(int $id): void
{
    require_admin();
    $direction = (req_json() ?? [])['dir'] ?? null;
    if (!in_array($direction, ['up', 'down'], true)) {
        respond_json(['ok' => false], 400);
    }
    [$cmp, $order] = $direction === 'up' ? ['<', 'DESC'] : ['>', 'ASC'];
    [$data, $status] = transaction(function () use ($id, $direction, $cmp, $order) {
        $me = row('SELECT id, supplier, category, col_group, sort_order FROM products WHERE id = ? FOR UPDATE', [$id]);
        if (!$me) {
            return [['ok' => false], 404];
        }
        $other = row("SELECT id, col_group, sort_order FROM products
                      WHERE supplier = ? AND category = ? AND id <> ?
                        AND (col_group, sort_order, id) {$cmp} (?, ?, ?)
                      ORDER BY col_group {$order}, sort_order {$order}, id {$order} LIMIT 1 FOR UPDATE",
            [$me['supplier'], $me['category'], $id, $me['col_group'], $me['sort_order'], $id]);
        if (!$other) {
            return [['ok' => true, 'moved' => false], 200];
        }
        if ((int)$other['col_group'] === (int)$me['col_group'] && (int)$other['sort_order'] === (int)$me['sort_order']) {
            // 並び順が同じ値だと入れ替えても変わらないので、ずらしてから入れ替える
            $bump = $direction === 'up' ? -1 : 1;
            run('UPDATE products SET sort_order = sort_order + ? WHERE id = ?', [$bump, $other['id']]);
            $other['sort_order'] = (int)$other['sort_order'] + $bump;
        }
        run('UPDATE products SET col_group = ?, sort_order = ? WHERE id = ?', [$other['col_group'], $other['sort_order'], $id]);
        run('UPDATE products SET col_group = ?, sort_order = ? WHERE id = ?', [$me['col_group'], $me['sort_order'], $other['id']]);
        return [['ok' => true, 'moved' => true, 'other_id' => (int)$other['id']], 200];
    });
    respond_json($data, $status);
}

function h_product_toggle(int $id): void
{
    require_admin();
    run('UPDATE products SET is_active = NOT is_active WHERE id = ?', [$id]);
    redirect_to(($_SERVER['HTTP_REFERER'] ?? '') ?: '/products');
}

function h_product_base(int $id): void
{
    require_admin();
    $store = $_POST['store'] ?? '';
    if (!in_array($store, STORES, true)) {
        respond_json(['ok' => false, 'error' => '店舗が不正です'], 400);
    }
    $raw = $_POST['base_qty'] ?? '';
    if (!is_numeric($raw)) {
        respond_json(['ok' => false, 'error' => '数を入力してください'], 400);
    }
    $qty = (float)$raw;
    if ($qty < 0) {
        respond_json(['ok' => false, 'error' => '0以上の数を入力してください'], 400);
    }
    if (!val('SELECT 1 FROM products WHERE id = ?', [$id])) {
        respond_json(['ok' => false, 'error' => '商品が見つかりません'], 404);
    }
    run('INSERT INTO product_base (product_id, store, base_qty) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE base_qty = VALUES(base_qty)', [$id, $store, $qty]);
    respond_json(['ok' => true]);
}

function h_product_supplier(int $id): void
{
    require_admin();
    if (!isset($_POST['supplier'])) {
        throw new HttpError(400);
    }
    run('UPDATE products SET supplier = ? WHERE id = ?', [$_POST['supplier'], $id]);
    respond_json(['ok' => true]);
}

// ── 設定・担当者管理 ───────────────────────────────────────────────────────────

function h_settings_top(): void
{
    require_admin();
    render('settings');
}

function h_contacts_settings(): void
{
    require_admin();
    render('contacts', ['suppliers' => get_suppliers_with_contacts()]);
}

function h_contact_add(): void
{
    require_admin();
    if (!isset($_POST['supplier_name'])) {
        throw new HttpError(400);
    }
    $sn = $_POST['supplier_name'];
    $max = (int)val('SELECT COALESCE(MAX(sort_order), 0) FROM supplier_contacts WHERE supplier_name = ?', [$sn]);
    run('INSERT INTO supplier_contacts (supplier_name, contact_name, fax, sort_order) VALUES (?,?,?,?)',
        [$sn, $_POST['contact_name'] ?? '', $_POST['fax'] ?? '', $max + 1]);
    redirect_to('/settings/contacts');
}

function h_contact_update(int $id): void
{
    require_admin();
    run('UPDATE supplier_contacts SET contact_name = ?, fax = ? WHERE id = ?', [$_POST['contact_name'] ?? '', $_POST['fax'] ?? '', $id]);
    redirect_to('/settings/contacts');
}

function h_contact_delete(int $id): void
{
    require_admin();
    run('DELETE FROM supplier_contacts WHERE id = ?', [$id]);
    redirect_to('/settings/contacts');
}
