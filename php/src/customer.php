<?php
declare(strict_types=1);

// ── お客様向けの価格表 ─────────────────────────────────────────────────────────

function load_prices(): array
{
    $groups = [];
    foreach (rows('SELECT id, category, name, spec, price, note FROM price_items WHERE is_active = 1 ORDER BY sort_order, id') as $r) {
        $r['kind'] = price_kind($r['category'], $r['name']);
        $groups[(string)($r['category'] ?: 'その他')][] = $r;
    }
    return $groups;
}

function h_prices(): void
{
    $groups = load_prices();
    $editing = null;
    $customs = [];
    $qty = [];
    $oid = (int)($_GET['order'] ?? 0);
    if ($oid) {
        // お客様の注文を直す時は、選んだ数量とお客様名を入れた状態で開く
        $editing = row('SELECT id, customer, phone, memo, discount_pct, campaign, store FROM customer_orders WHERE id = ?', [$oid]);
        if ($editing) {
            $editing['discount_pct'] = pct_fmt($editing['discount_pct']);
            $customs = rows('SELECT name, unit_price, qty FROM customer_order_items WHERE order_id = ? AND price_id IS NULL ORDER BY sort_order, id', [$oid]);
            foreach (rows('SELECT price_id, qty FROM customer_order_items WHERE order_id = ? AND price_id IS NOT NULL', [$oid]) as $r) {
                $qty[(int)$r['price_id']] = (int)$r['qty'];
            }
        }
    }
    render('prices', ['groups' => $groups, 'total' => array_sum(array_map('count', $groups)), 'note' => PRICE_NOTE,
        'title' => PRICE_TITLE, 'editing' => $editing, 'qty' => $qty, 'customs' => $customs]);
}

// ── お客様ごとの注文（商品袋に貼る注文用紙） ───────────────────────────────────

function load_customer_order(int $oid): array
{
    $order = row('SELECT * FROM customer_orders WHERE id = ?', [$oid]);
    if (!$order) {
        throw new HttpError(404);
    }
    $items = rows('SELECT i.id, i.ordered, i.name, i.spec, i.unit_price, i.qty, i.unit_price * i.qty AS amount,
                          COALESCE(i.discount_pct, ?) AS pct, p.category
                   FROM customer_order_items i LEFT JOIN price_items p ON p.id = i.price_id
                   WHERE i.order_id = ? ORDER BY i.sort_order, i.id', [$order['discount_pct'], $oid]);
    return [$order, $items, (int)array_sum(array_column($items, 'amount'))];
}

/** [割引額, 支払額, 割引の表示]。割引率が行ごとに違う時は「割引」だけにして、率は各行に出す */
function order_discount(array $order, array &$items): array
{
    [$disc, $pay] = calc_discount_lines(array_map(static fn($i) => [$i['amount'], $i['pct']], $items));
    foreach ($items as &$i) {   // 行ごとの割引の説明（例: 「16%引き＋アルマダ特別割引（−¥2,000）」）
        $i['label'] = '';
        if ($order['campaign']) {
            $d = calc_discount_lines([[$i['amount'], $i['pct']]])[0];
            if ($d > 0) {
                $tier = pct_fmt($order['discount_pct']);
                if (price_kind($i['category'], $i['name']) === 'a') {
                    $head = ((float)$order['discount_pct'] > 0 ? "{$tier}%引き＋" : '') . 'アルマダ特別割引';
                } else {
                    $head = pct_fmt($i['pct']) . '%引き';
                }
                $i['label'] = "{$head}（－" . yen_fmt($d) . '）';
            }
        }
    }
    unset($i);
    $rates = [];
    foreach ($items as $i) {
        if ((float)($i['pct'] ?? 0) > 0) {
            $rates[pct_fmt($i['pct'])] = true;
        }
    }
    $label = count($rates) === 1 ? array_key_first($rates) . '% 割引' : '割引（商品ごとの割引率）';
    return [$disc, $pay, $label];
}

function h_customer_orders(): void
{
    $orders = rows('
        SELECT o.id, o.customer, o.phone, o.created_at, o.store, o.discount_pct, COUNT(i.id) AS n,
               COALESCE(SUM(i.ordered), 0) AS n_ordered
        FROM customer_orders o LEFT JOIN customer_order_items i ON i.order_id = o.id
        GROUP BY o.id ORDER BY o.created_at DESC LIMIT 300');
    $lines = [];
    if ($orders) {
        $ids = array_column($orders, 'id');
        $pctOf = array_column($orders, 'discount_pct', 'id');
        foreach (rows('SELECT order_id, unit_price * qty AS a, discount_pct FROM customer_order_items WHERE order_id IN (' . marks($ids) . ')', $ids) as $r) {
            $lines[$r['order_id']][] = [$r['a'], $r['discount_pct'] ?? $pctOf[$r['order_id']]];
        }
    }
    foreach ($orders as &$o) {   // 表示金額は、割引後の合計（行ごとに計算）
        $o['total'] = calc_discount_lines($lines[$o['id']] ?? [])[1];
    }
    unset($o);
    render('customer_orders', ['orders' => $orders]);
}

function h_customer_order_save(): void
{
    $d = req_json();
    if ($d === null) {
        bad_input();
    }
    $customer = mb_substr(nfkc((string)($d['customer'] ?? '')) ?? '', 0, 60);
    $phone = mb_substr(nfkc((string)($d['phone'] ?? '')) ?? '', 0, 30);
    $memo = mb_substr(nfkc((string)($d['memo'] ?? '')) ?? '', 0, 200);
    if ($customer === '') {
        bad_input('お客様名を入力してください');
    }
    $items = [];
    foreach ((array)($d['items'] ?? []) as $i) {
        if (!is_array($i) || !isset($i['price_id'], $i['qty']) || !is_numeric($i['price_id']) || !is_numeric($i['qty'])) {
            bad_input();
        }
        if ((int)$i['qty'] > 0) {
            $items[] = [(int)$i['price_id'], (int)$i['qty']];
        }
    }
    $oid = !empty($d['id']) ? (int)$d['id'] : null;
    $customs = [];
    foreach ((array)($d['customs'] ?? []) as $c) {
        if (!is_array($c) || !isset($c['qty'], $c['price']) || !is_numeric($c['qty']) || !is_numeric($c['price'])) {
            bad_input();
        }
        $cq = (int)$c['qty'];
        $cp = (int)$c['price'];
        $cn = mb_substr(nfkc((string)($c['name'] ?? '')) ?? '', 0, 80);
        if ($cq <= 0) {
            continue;
        }
        if ($cn === '' || $cp < 0 || $cp > 9999999 || $cq > 9999) {
            bad_input('「その他」の品名・値段・数量を確認してください');
        }
        $customs[] = [$cn, $cp, $cq];
    }
    $campaign = !empty($d['campaign']);
    $store = $d['store'] ?? 'イノ';
    if (!in_array($store, STORES, true)) {
        bad_input('店を選んでください');
    }
    if (isset($d['discount_pct']) && $d['discount_pct'] !== '' && !is_numeric($d['discount_pct'])) {
        bad_input();
    }
    $disc = round((float)($d['discount_pct'] ?? 0), 2);
    if ($disc < 0 || $disc > 100) {
        bad_input('割引は0〜100%で入力してください');
    }
    if (!$items && !$customs) {
        bad_input('商品を選んでください');
    }
    if (count($items) + count($customs) > 300 || max(array_merge([0], array_column($items, 1))) > 9999) {
        bad_input('数量または品数が多すぎます');
    }
    $requestKey = ($d['request_key'] ?? null) ?: null;
    $now = now_jst();

    [$data, $status] = transaction(function () use ($items, $customs, $oid, $customer, $phone, $memo, $disc, $campaign, $store, $requestKey, $now) {
        $ids = array_values(array_unique(array_column($items, 0)));
        $snap = [];
        if ($ids) {
            foreach (rows('SELECT id, category, name, spec, price FROM price_items WHERE id IN (' . marks($ids) . ')', $ids) as $r) {
                $snap[(int)$r['id']] = $r;
            }
        }
        if (count($snap) !== count($ids)) {
            return [['ok' => false, 'error' => '価格表にない商品が含まれています。画面を読み込み直してください'], 400];
        }
        $wasOrdered = [];
        if ($oid) {
            if (!val('SELECT id FROM customer_orders WHERE id = ? FOR UPDATE', [$oid])) {
                return [['ok' => false, 'error' => '注文が見つかりません'], 404];
            }
            run('UPDATE customer_orders SET customer = ?, phone = ?, memo = ?, discount_pct = ?, campaign = ?, store = ?, updated_at = ? WHERE id = ?',
                [$customer, $phone, $memo, $disc, $campaign, $store, $now, $oid]);
            // 直して保存しても、発注済みの印は残す（商品と、手書きの品名で照らし合わせる）
            foreach (rows('SELECT price_id, name FROM customer_order_items WHERE order_id = ? AND ordered = 1', [$oid]) as $r) {
                $wasOrdered[$r['price_id'] === null ? 'n' . $r['name'] : 'p' . $r['price_id']] = true;
            }
            run('DELETE FROM customer_order_items WHERE order_id = ?', [$oid]);
        } else {
            if ($requestKey) {
                $done = val('SELECT id FROM customer_orders WHERE request_key = ?', [$requestKey]);
                if ($done) {
                    return [['ok' => true, 'id' => (int)$done, 'repeated' => true], 200];
                }
            }
            run('INSERT INTO customer_orders (customer, phone, memo, discount_pct, campaign, store, request_key, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?)', [$customer, $phone, $memo, $disc, $campaign, $store, $requestKey, $now, $now]);
            $oid = last_id();
        }
        // 「その他」（価格表にない商品）を先頭に、続けて価格表の商品。キャンペーンの時は、商品の種類ごとに行の割引率を決める
        $linePct = static fn(string $kind) => $campaign ? kind_rate($kind, $disc) : null;
        $rowsData = [];
        foreach ($customs as $n => [$cn, $cp, $cq]) {
            $rowsData[] = [$oid, null, $cn, null, $cp, $cq, $n, $linePct('n'), isset($wasOrdered['n' . $cn])];
        }
        foreach ($items as $n => [$pid, $q]) {
            $s = $snap[$pid];
            $rowsData[] = [$oid, $pid, $s['name'], $s['spec'], (int)$s['price'], $q, count($customs) + $n,
                $linePct(price_kind($s['category'], $s['name'])), isset($wasOrdered['p' . $pid])];
        }
        insert_many('customer_order_items', ['order_id', 'price_id', 'name', 'spec', 'unit_price', 'qty', 'sort_order', 'discount_pct', 'ordered'], $rowsData);
        return [['ok' => true, 'id' => $oid], 200];
    });
    respond_json($data, $status);
}

function h_customer_order_view(int $id): void
{
    [$order, $items, $total] = load_customer_order($id);
    [$disc, $payable, $label] = order_discount($order, $items);
    render('customer_order', ['order' => $order, 'items' => $items, 'subtotal' => $total, 'disc' => $disc,
        'total' => $payable, 'pct' => $label, 'note' => PRICE_NOTE]);
}

/** 注文の全商品を、発注済みにする／未発注に戻す */
function h_customer_order_ordered(int $id): void
{
    $on = !empty((req_json() ?? [])['ordered']);
    run('UPDATE customer_order_items SET ordered = ? WHERE order_id = ?', [$on, $id]);
    respond_json(['ok' => true, 'ordered' => $on]);
}

/** 商品1つの発注済みの印を付ける／外す */
function h_customer_order_item_ordered(int $id, int $iid): void
{
    $on = !empty((req_json() ?? [])['ordered']);
    if (!val('SELECT id FROM customer_order_items WHERE id = ? AND order_id = ?', [$iid, $id])) {
        respond_json(['ok' => false, 'error' => '商品が見つかりません'], 404);
    }
    run('UPDATE customer_order_items SET ordered = ? WHERE id = ? AND order_id = ?', [$on, $iid, $id]);
    respond_json(['ok' => true, 'ordered' => $on]);
}

function h_customer_order_delete(int $id): void
{
    run('DELETE FROM customer_orders WHERE id = ?', [$id]);
    respond_json(['ok' => true]);
}

// ── PDF（作り方は pdf.php） ───────────────────────────────────────────────────

/** まだ発注していない商品の一覧（発注の作業用）。?order=ID でその注文だけ。?store=店 でその店だけ */
function h_customer_orders_unordered_pdf(): void
{
    require_once __DIR__ . '/pdf.php';
    $oid = (int)($_GET['order'] ?? 0) ?: null;
    $store = in_array($_GET['store'] ?? null, STORES, true) ? $_GET['store'] : null;
    $where = 'WHERE i.ordered = 0';
    $params = [];
    if ($oid) {
        $where .= ' AND o.id = ?';
        $params[] = $oid;
    }
    if ($store) {
        $where .= ' AND o.store = ?';
        $params[] = $store;
    }
    $groups = [];
    foreach (rows("SELECT i.price_id, i.name, i.spec, i.qty, o.customer, p.sort_order
                   FROM customer_order_items i
                   JOIN customer_orders o ON o.id = i.order_id
                   LEFT JOIN price_items p ON p.id = i.price_id
                   {$where}
                   ORDER BY (p.sort_order IS NULL), p.sort_order, i.name, o.created_at", $params) as $r) {
        $key = $r['price_id'] ? 'p' . $r['price_id'] : 'n' . $r['name'];
        $groups[$key] ??= ['name' => $r['name'], 'spec' => $r['spec'], 'qty' => 0, 'who' => []];
        $groups[$key]['qty'] += (int)$r['qty'];
        $groups[$key]['who'][] = [sama_fmt($r['customer']), (int)$r['qty']];
    }
    $title = '未発注の商品' . ($store ? "（{$store}）" : '');
    if ($oid) {
        $o = row('SELECT customer FROM customer_orders WHERE id = ?', [$oid]);
        if (!$o) {
            throw new HttpError(404);
        }
        $title .= '　' . sama_fmt($o['customer']);
    }
    respond_pdf(build_unordered_pdf(array_values($groups), $title), '未発注の商品.pdf');
}

function h_customer_order_pdf(int $id): void
{
    require_once __DIR__ . '/pdf.php';
    [$order, $items, $subtotal] = load_customer_order($id);
    [$disc, $total, $label] = order_discount($order, $items);
    // A5の1枚に収まる範囲で、いちばん大きい倍率を使う（収まらない時は、標準の大きさで複数ページ）
    $pdf = null;
    for ($step = (int)(ORDER_PDF_MAX_SCALE * 10); $step >= 10; $step--) {
        $cand = build_customer_order_pdf($order, $items, $subtotal, $disc, $total, $label, $step / 10);
        if ($cand->PageNo() === 1 || $step === 10) {
            $pdf = $cand;
            break;
        }
    }
    respond_pdf($pdf->Output('S'), '注文用紙_' . str_replace(' ', '', sama_fmt($order['customer'])) . '.pdf');
}

function h_prices_pdf(): void
{
    require_once __DIR__ . '/pdf.php';
    $groups = load_prices();
    $now = now_jst();
    // 値段・商品・日付が変わらない間は、作ったPDFを使い回す（約350品で作成に数秒かかるため）
    $sig = md5(json_encode([$groups, $now->format('Y-m-d'), PDF_VER], JSON_UNESCAPED_UNICODE));
    $dir = BASE_DIR . '/storage/cache';
    $file = "{$dir}/prices_{$sig}.pdf";
    $fn = PRICE_TITLE . '_' . $now->format('n') . '月' . $now->format('j') . '日.pdf';
    if (is_file($file)) {
        respond_pdf((string)file_get_contents($file), $fn);
    }
    $data = build_prices_pdf($groups, $now);
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        foreach (glob("{$dir}/prices_*.pdf") ?: [] as $old) {
            @unlink($old);
        }
        @file_put_contents($file, $data);
    }
    respond_pdf($data, $fn);
}
