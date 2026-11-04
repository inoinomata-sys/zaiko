<?php
declare(strict_types=1);

// データの一回きりの書き換え。実行したら、data_migrations に名前を残し、2回目からは何もしない。
// 価格表など、画面から直せないデータを、決まった形で直すために使う。
const DATA_MIGRATIONS = [
    // V3ファンデーションの商品一覧（Excel）に合わせて、名前を直し、足りない1品を足す。お客様注文で使われている行は、消さずに書き換える
    '2026-11-02-v3-foundation' => [
        ["UPDATE price_items SET name = 'V3 エキサイティング ファンデーション 本体', price = 8800 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3エキサイティングファンデ'"],
        ["UPDATE price_items SET name = 'V3 シャイニング ファンデーション 本体', price = 9350 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3シャイニングファンデ'"],
        ["UPDATE price_items SET name = 'V3 シャイニング ファンデーション レフィル', price = 8250 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3シャイニングレフィル'"],
        ["UPDATE price_items SET name = 'V3 ブリリアント ファンデーション 本体', price = 9350 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3ブリリアントファンデ'"],
        ["UPDATE price_items SET name = 'V3 ブリリアント ファンデーション レフィル', price = 8250 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3ブリリアントレフィル'"],
        ["UPDATE price_items SET name = 'V3 インテリジェント ファンデーション 本体', price = 9900 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3インテリジェントファンデ'"],
        ["UPDATE price_items SET name = 'V3 インテリジェント ファンデーション レフィル', price = 8800 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3インテリジェントレフィル'"],
        ["INSERT INTO price_items (category, name, price, sort_order, is_active)
          SELECT 'メイクアイテム・化粧品', 'V3 エキサイティング ファンデーション レフィル', 7700, 901310, 1 FROM DUAL
          WHERE NOT EXISTS (SELECT 1 FROM price_items WHERE name = 'V3 エキサイティング ファンデーション レフィル')"],
    ],
    // V3ファンデの並びを、サロンアンサーの表示順（1301〜1308）と同じにする。ナイトクリームは、その後ろ
    '2026-11-02-v3-order' => [
        ["UPDATE price_items SET sort_order = 901301 WHERE name = 'V3 エキサイティング ファンデーション 本体'"],
        ["UPDATE price_items SET sort_order = 901302 WHERE name = 'V3 エキサイティング ファンデーション レフィル'"],
        ["UPDATE price_items SET sort_order = 901303 WHERE name = 'V3 シャイニング ファンデーション 本体'"],
        ["UPDATE price_items SET sort_order = 901304 WHERE name = 'V3 シャイニング ファンデーション レフィル'"],
        ["UPDATE price_items SET sort_order = 901305 WHERE name = 'V3 ブリリアント ファンデーション 本体'"],
        ["UPDATE price_items SET sort_order = 901306 WHERE name = 'V3 ブリリアント ファンデーション レフィル'"],
        ["UPDATE price_items SET sort_order = 901307 WHERE name = 'V3 インテリジェント ファンデーション 本体'"],
        ["UPDATE price_items SET sort_order = 901308 WHERE name = 'V3 インテリジェント ファンデーション レフィル'"],
        ["UPDATE price_items SET sort_order = 901320 WHERE name = 'V3ナイトクリーム'"],
    ],
    // 仕入先の名前を「三井コーポレーション」から「ミツイコーポレーション」に変える（商品・発注の履歴・担当者・LINEの送り先・発注の下書き）
    '2026-11-02-supplier-mitsui' => [
        ["UPDATE products SET supplier = 'ミツイコーポレーション' WHERE supplier = '三井コーポレーション'"],
        ["UPDATE orders SET supplier = 'ミツイコーポレーション' WHERE supplier = '三井コーポレーション'"],
        ["UPDATE supplier_contacts SET supplier_name = 'ミツイコーポレーション' WHERE supplier_name = '三井コーポレーション'"],
        ["UPDATE order_drafts SET supplier = 'ミツイコーポレーション' WHERE supplier = '三井コーポレーション'"],
    ],
    // 「V3ナイトクリーム」は、プレジストの「Vフィックス アメージングクリーム 50g」（22,000円）と同じ商品なので、名前を書き換える（サロンアンサーと同じ名前）
    '2026-11-02-amazing-cream' => [
        ["UPDATE price_items SET name = 'プレジスト Vフィックス アメージングクリーム 50g', price = 22000 WHERE category = 'メイクアイテム・化粧品' AND name = 'V3ナイトクリーム'"],
    ],
    // お客様注文（価格表）のコタ商品を、商品マスターの仕入先「コタ」にも入れる（同じ名前が、すでにあれば入れない）。基準数は仮の1
    '2026-11-04-cota-products' => [
        ["INSERT INTO products (col_group, category, name, base_qty, supplier, sort_order)
          SELECT (SELECT g FROM (SELECT COALESCE(MAX(col_group), 1) AS g FROM products) t),
                 CASE WHEN pi.name LIKE '%トリートメント%' THEN 'トリートメント' WHEN pi.name LIKE '%シャンプー%' THEN 'シャンプー' ELSE 'スタイリング・その他' END,
                 pi.name, 1, 'コタ', 1000 + pi.sort_order
          FROM price_items pi
          WHERE pi.category = 'コタ' AND pi.is_active = 1
            AND NOT EXISTS (SELECT 1 FROM products p WHERE p.supplier = 'コタ' AND p.name = pi.name)"],
        ["INSERT INTO product_base (product_id, store, base_qty)
          SELECT p.id, 'イノ', 1 FROM products p WHERE p.supplier = 'コタ'
            AND NOT EXISTS (SELECT 1 FROM product_base b WHERE b.product_id = p.id AND b.store = 'イノ')"],
        ["INSERT INTO product_base (product_id, store, base_qty)
          SELECT p.id, 'リッチ', 1 FROM products p WHERE p.supplier = 'コタ'
            AND NOT EXISTS (SELECT 1 FROM product_base b WHERE b.product_id = p.id AND b.store = 'リッチ')"],
    ],
    // 仕入先の名前を「コタ」から「株式会社クリエイティブブレーン」に変える（商品・発注の履歴・担当者・発注の下書き）。お客様注文の価格表の「コタ」は、商品の分類なので、そのまま
    '2026-11-04-supplier-creative-brain' => [
        ["UPDATE products SET supplier = '株式会社クリエイティブブレーン' WHERE supplier = 'コタ'"],
        ["UPDATE orders SET supplier = '株式会社クリエイティブブレーン' WHERE supplier = 'コタ'"],
        ["UPDATE supplier_contacts SET supplier_name = '株式会社クリエイティブブレーン' WHERE supplier_name = 'コタ'"],
        ["UPDATE order_drafts SET supplier = '株式会社クリエイティブブレーン' WHERE supplier = 'コタ'"],
    ],
    // 仕入先の名前に「株式会社」をつける（ワールド美容・トピー商事・アモール）。商品・発注の履歴・担当者・発注の下書きを書き換える
    '2026-11-04-supplier-kabushiki' => [
        ["UPDATE products SET supplier = '株式会社ワールド美容' WHERE supplier = 'ワールド美容'"],
        ["UPDATE orders SET supplier = '株式会社ワールド美容' WHERE supplier = 'ワールド美容'"],
        ["UPDATE supplier_contacts SET supplier_name = '株式会社ワールド美容' WHERE supplier_name = 'ワールド美容'"],
        ["UPDATE order_drafts SET supplier = '株式会社ワールド美容' WHERE supplier = 'ワールド美容'"],
        ["UPDATE products SET supplier = '株式会社トピー商事' WHERE supplier = 'トピー商事'"],
        ["UPDATE orders SET supplier = '株式会社トピー商事' WHERE supplier = 'トピー商事'"],
        ["UPDATE supplier_contacts SET supplier_name = '株式会社トピー商事' WHERE supplier_name = 'トピー商事'"],
        ["UPDATE order_drafts SET supplier = '株式会社トピー商事' WHERE supplier = 'トピー商事'"],
        ["UPDATE products SET supplier = '株式会社アモール' WHERE supplier = 'アモール'"],
        ["UPDATE orders SET supplier = '株式会社アモール' WHERE supplier = 'アモール'"],
        ["UPDATE supplier_contacts SET supplier_name = '株式会社アモール' WHERE supplier_name = 'アモール'"],
        ["UPDATE order_drafts SET supplier = '株式会社アモール' WHERE supplier = 'アモール'"],
    ],
    // 仕入先の名前を「ミツイコーポレーション」から「ミツイコーポレーション株式会社」に変える
    '2026-11-04-supplier-mitsui-kabushiki' => [
        ["UPDATE products SET supplier = 'ミツイコーポレーション株式会社' WHERE supplier = 'ミツイコーポレーション'"],
        ["UPDATE orders SET supplier = 'ミツイコーポレーション株式会社' WHERE supplier = 'ミツイコーポレーション'"],
        ["UPDATE supplier_contacts SET supplier_name = 'ミツイコーポレーション株式会社' WHERE supplier_name = 'ミツイコーポレーション'"],
        ["UPDATE order_drafts SET supplier = 'ミツイコーポレーション株式会社' WHERE supplier = 'ミツイコーポレーション'"],
    ],
];

function ensure_data_migrations(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $finished = array_column(rows('SELECT name FROM data_migrations'), 'name');
    } catch (Throwable $e) {   // 表が、まだない（最初の1回）
        run('CREATE TABLE IF NOT EXISTS data_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, done_at DATETIME NOT NULL) DEFAULT CHARSET=utf8mb4');
        $finished = [];
    }
    foreach (DATA_MIGRATIONS as $name => $statements) {
        if (in_array($name, $finished, true)) {
            continue;
        }
        transaction(function () use ($name, $statements) {
            foreach ($statements as [$sql]) {
                run($sql);
            }
            run('INSERT INTO data_migrations (name, done_at) VALUES (?, ?)', [$name, now_jst()->format('Y-m-d H:i:s')]);
        });
    }
}
