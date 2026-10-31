-- 自動テスト用のわずかなデータ（本物のデータではない）
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM products; DELETE FROM stock; DELETE FROM product_base; DELETE FROM supplier_contacts;
DELETE FROM price_items; DELETE FROM orders; DELETE FROM order_items; DELETE FROM usage_log;
DELETE FROM customer_orders; DELETE FROM customer_order_items; DELETE FROM order_drafts;

INSERT INTO products (id, col_group, category, name, base_qty, note, supplier, sort_order, is_active) VALUES
 (1, 1, 'トリートメント', 'ホーユートリートメント', 3, 'テスト備考', 'ワールド美容', 1, 1),
 (2, 1, 'トリートメント', 'ミントトリートメント 1000g', 2, NULL, 'ワールド美容', 2, 1),
 (3, 2, 'シャンプー', 'NCSシャンプー 1L', 4, NULL, 'ワールド美容', 1, 1),
 (4, 1, 'コタ', 'コタシャンプー', 2, NULL, 'コタ', 1, 1);
INSERT INTO product_base (product_id, store, base_qty) VALUES (1, 'イノ', 3), (1, 'リッチ', 2);
INSERT INTO stock (product_id, store, current_qty, updated_at, changed_at, checked_at, version) VALUES
 (1, 'イノ', 5, '2026-10-01 10:00', '2026-10-01 10:00:00', '2026-10-01 10:00:00', 1),
 (2, 'イノ', 1, '2026-10-30 10:00', '2026-10-30 10:00:00', '2026-10-30 10:00:00', 1),
 (3, 'イノ', 0, NULL, NULL, NULL, 0);
INSERT INTO supplier_contacts (id, supplier_name, contact_name, fax, line_user_id, sort_order) VALUES (1, 'ワールド美容', '山田', '06-0000-0000', '', 1);
INSERT INTO price_items (id, category, name, spec, price, note, sort_order, is_active, pos_id) VALUES
 (1, 'アリミノ シャンプー', '新コアミーシャンプーS 250ml', NULL, 4400, NULL, 100001, 1, 1648),
 (2, 'アルマダシリーズ', 'アルマダ1000ml', NULL, 11000, NULL, 1200001, 1, 179),
 (3, 'アルマダシリーズ', 'アルマダ200ml', NULL, 2650, NULL, 1200002, 1, 177),
 (4, 'リファシリーズ', 'リファドライヤー', NULL, 38000, NULL, 1100001, 1, 1473),
 (5, 'ステラボーテシリーズ', 'ステラボーテブラシ', NULL, 58000, NULL, 1300001, 1, 2097),
 (6, 'シャンプーその他', 'モイストシトラス250ml', '', 2970, '備考あり', 500001, 1, 1321);
ALTER TABLE products AUTO_INCREMENT = 10;
ALTER TABLE price_items AUTO_INCREMENT = 10;
SET FOREIGN_KEY_CHECKS = 1;
