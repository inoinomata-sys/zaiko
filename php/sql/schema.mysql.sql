-- 在庫・発注管理システム（ロリポップ MySQL 用）。phpMyAdmin の「インポート」で1回だけ実行する
-- 文字コードは utf8mb4。時刻は日本時間のまま DATETIME に入れる（アプリ側で日本時間にそろえる）
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  col_group   INT NOT NULL,
  category    VARCHAR(100) NOT NULL DEFAULT '',
  name        VARCHAR(255) NOT NULL,
  base_qty    DOUBLE DEFAULT 0,
  note        VARCHAR(255) NULL,
  supplier    VARCHAR(100) DEFAULT 'ワールド美容',
  sort_order  INT DEFAULT 0,
  is_active   TINYINT(1) DEFAULT 1,
  request_key VARCHAR(64) NULL,
  UNIQUE KEY products_request_key (request_key),
  KEY products_supplier (supplier, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock (
  product_id  INT UNSIGNED NOT NULL,
  store       VARCHAR(20) NOT NULL DEFAULT 'イノ',
  current_qty DOUBLE DEFAULT 0,
  updated_at  VARCHAR(32) NULL,
  changed_at  DATETIME NULL,
  checked_at  DATETIME NULL,
  version     INT NOT NULL DEFAULT 0,
  PRIMARY KEY (product_id, store),
  CONSTRAINT stock_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_base (
  product_id INT UNSIGNED NOT NULL,
  store      VARCHAR(20) NOT NULL,
  base_qty   DOUBLE NOT NULL,
  PRIMARY KEY (product_id, store),
  CONSTRAINT product_base_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_name VARCHAR(100) NOT NULL,
  contact_name  VARCHAR(100) NOT NULL DEFAULT '',
  fax           VARCHAR(50) NOT NULL DEFAULT '',
  line_user_id  VARCHAR(100) NOT NULL DEFAULT '',
  sort_order    INT NOT NULL DEFAULT 0,
  KEY supplier_contacts_name (supplier_name, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  store        VARCHAR(20) NOT NULL DEFAULT 'イノ',
  supplier     VARCHAR(100) DEFAULT 'ワールド美容',
  order_month  INT NULL,
  order_day    INT NULL,
  status       VARCHAR(20) DEFAULT 'draft',
  notes        TEXT NULL,
  created_at   VARCHAR(32) NULL,
  usage_upto   INT NULL,
  request_key  VARCHAR(64) NULL,
  pdf          LONGBLOB NULL,
  pdf_ver      VARCHAR(10) NULL,
  confirmed_at DATETIME NULL,
  UNIQUE KEY orders_request_key (request_key),
  KEY orders_store_supplier (store, supplier, order_month, order_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NULL,
  base_qty    DOUBLE NULL,
  stock_qty   DOUBLE NULL,
  order_qty   DOUBLE NOT NULL,
  custom_name VARCHAR(255) NULL,
  KEY order_items_order (order_id),
  KEY order_items_product (product_id),
  CONSTRAINT order_items_order_fk FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT order_items_product_fk FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usage_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at         DATETIME NOT NULL,
  store      VARCHAR(20) NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  qty        DOUBLE NOT NULL,
  KEY usage_log_store_product (store, product_id),
  CONSTRAINT usage_log_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_drafts (
  store    VARCHAR(20) NOT NULL,
  supplier VARCHAR(100) NOT NULL,
  data     LONGTEXT NOT NULL,
  saved_at DATETIME NOT NULL,
  PRIMARY KEY (store, supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_items (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category   VARCHAR(100) NOT NULL DEFAULT '',
  name       VARCHAR(255) NOT NULL,
  spec       VARCHAR(255) NULL,
  price      INT NOT NULL,
  note       VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  pos_id     INT NULL,
  UNIQUE KEY price_items_pos_id (pos_id),
  KEY price_items_sort (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_orders (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer     VARCHAR(100) NOT NULL,
  phone        VARCHAR(50) NULL,
  memo         VARCHAR(500) NULL,
  request_key  VARCHAR(64) NULL,
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL,
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  campaign     TINYINT(1) NOT NULL DEFAULT 0,
  ordered_at   DATETIME NULL,
  store        VARCHAR(20) NOT NULL DEFAULT 'イノ',
  UNIQUE KEY customer_orders_request_key (request_key),
  KEY customer_orders_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_order_items (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id     INT UNSIGNED NOT NULL,
  price_id     INT UNSIGNED NULL,
  name         VARCHAR(255) NOT NULL,
  spec         VARCHAR(255) NULL,
  unit_price   INT NOT NULL,
  qty          INT NOT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  discount_pct DECIMAL(6,2) NULL,
  ordered      TINYINT(1) NOT NULL DEFAULT 0,
  KEY customer_order_items_order (order_id),
  CONSTRAINT coi_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE,
  CONSTRAINT coi_price FOREIGN KEY (price_id) REFERENCES price_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_log (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  at     DATETIME NULL,
  method VARCHAR(20) NULL,
  path   VARCHAR(500) NULL,
  error  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
