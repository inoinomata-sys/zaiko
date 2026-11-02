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
];

function ensure_data_migrations(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    run('CREATE TABLE IF NOT EXISTS data_migrations (name VARCHAR(100) NOT NULL PRIMARY KEY, done_at DATETIME NOT NULL) DEFAULT CHARSET=utf8mb4');
    foreach (DATA_MIGRATIONS as $name => $statements) {
        if (row('SELECT name FROM data_migrations WHERE name = ?', [$name])) {
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
