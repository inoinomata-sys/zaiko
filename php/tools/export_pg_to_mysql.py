"""いまの本番データ（PostgreSQL / Supabase）を、ロリポップの MySQL に入れるための SQL に書き出す。

使い方:  python3 php/tools/export_pg_to_mysql.py  [出力ファイル]
  DATABASE_URL は .env から読む（.env はコミットしない）。出力は backups/ に作る（コミットしない）。
  できた .sql を、phpMyAdmin の「インポート」で、schema.mysql.sql のあとに入れる。

・時刻は日本時間に直して DATETIME に入れる
・発注書のPDFの控え（pdf）、エラーの記録（error_log）は移さない（PDFは開いた時に作り直される）
・IDはそのまま引き継ぐ（発注の履歴や注文の紐づきが変わらない）
"""
import datetime as dt
import decimal
import json
import os
import sys
import psycopg2
from dotenv import load_dotenv

JST = dt.timezone(dt.timedelta(hours=9))
BATCH = 200

# (テーブル, 列の並び)。外部キーの親が先
TABLES = [
    ('products', ['id', 'col_group', 'category', 'name', 'base_qty', 'note', 'supplier', 'sort_order', 'is_active', 'request_key']),
    ('product_base', ['product_id', 'store', 'base_qty']),
    ('stock', ['product_id', 'store', 'current_qty', 'updated_at', 'changed_at', 'checked_at', 'version']),
    ('supplier_contacts', ['id', 'supplier_name', 'contact_name', 'fax', 'line_user_id', 'sort_order']),
    ('orders', ['id', 'store', 'supplier', 'order_month', 'order_day', 'status', 'notes', 'created_at', 'usage_upto', 'request_key', 'confirmed_at']),
    ('order_items', ['id', 'order_id', 'product_id', 'base_qty', 'stock_qty', 'order_qty', 'custom_name']),
    ('usage_log', ['id', 'at', 'store', 'product_id', 'qty']),
    ('order_drafts', ['store', 'supplier', 'data', 'saved_at']),
    ('price_items', ['id', 'category', 'name', 'spec', 'price', 'note', 'sort_order', 'is_active', 'pos_id']),
    ('customer_orders', ['id', 'customer', 'phone', 'memo', 'request_key', 'created_at', 'updated_at', 'discount_pct', 'campaign', 'ordered_at', 'store']),
    ('customer_order_items', ['id', 'order_id', 'price_id', 'name', 'spec', 'unit_price', 'qty', 'sort_order', 'discount_pct', 'ordered']),
]
AUTO_ID_TABLES = {'products', 'supplier_contacts', 'orders', 'order_items', 'usage_log', 'price_items', 'customer_orders', 'customer_order_items'}


def lit(v):
    """SQLの値の書き方（MySQL）。文字は ' と \\ を逃がす"""
    if v is None:
        return 'NULL'
    if isinstance(v, bool):
        return '1' if v else '0'
    if isinstance(v, (int, float, decimal.Decimal)):
        return str(v)
    if isinstance(v, dt.datetime):
        if v.tzinfo is not None:
            v = v.astimezone(JST).replace(tzinfo=None)
        return "'" + v.strftime('%Y-%m-%d %H:%M:%S') + "'"
    if isinstance(v, (dict, list)):
        v = json.dumps(v, ensure_ascii=False)
    s = str(v)
    s = (s.replace('\\', '\\\\').replace("'", "\\'").replace('\0', '\\0')
          .replace('\n', '\\n').replace('\r', '\\r').replace('\x1a', '\\Z'))
    return "'" + s + "'"


def main(out_path):
    load_dotenv()
    conn = psycopg2.connect(os.environ['DATABASE_URL'])
    cur = conn.cursor()
    lines = ['-- 本番データの書き出し（PostgreSQL → MySQL）。個人情報を含むので、公開しない・コミットしない',
             f"-- 作成: {dt.datetime.now(JST).strftime('%Y-%m-%d %H:%M')} (日本時間)",
             'SET NAMES utf8mb4;', 'SET FOREIGN_KEY_CHECKS = 0;', 'SET SQL_MODE = \'NO_AUTO_VALUE_ON_ZERO\';', '']
    counts = {}
    for table, cols in TABLES:
        cur.execute(f"SELECT {', '.join(cols)} FROM {table} ORDER BY 1")
        rows = cur.fetchall()
        counts[table] = len(rows)
        lines.append(f'-- {table}: {len(rows)} 行')
        lines.append(f'DELETE FROM {table};')
        for i in range(0, len(rows), BATCH):
            chunk = rows[i:i + BATCH]
            values = ',\n'.join('(' + ', '.join(lit(v) for v in r) + ')' for r in chunk)
            lines.append(f"INSERT INTO {table} ({', '.join(cols)}) VALUES\n{values};")
        lines.append('')
    # 次に作られる番号が、いまの最大より大きくなるように
    for t in sorted(AUTO_ID_TABLES):
        lines.append(f'ALTER TABLE {t} AUTO_INCREMENT = {max(1, _max_id(cur, t) + 1)};')
    lines += ['SET FOREIGN_KEY_CHECKS = 1;', '']
    with open(out_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines))
    conn.close()
    print('書き出し:', out_path)
    for t, n in counts.items():
        print(f'  {t}: {n}')


def _max_id(cur, table):
    cur.execute(f'SELECT COALESCE(MAX(id), 0) FROM {table}')
    return cur.fetchone()[0]


if __name__ == '__main__':
    default = os.path.join(os.path.dirname(__file__), '..', '..', 'backups',
                           'mysql_data_' + dt.datetime.now(JST).strftime('%Y%m%d_%H%M%S') + '.sql')
    main(sys.argv[1] if len(sys.argv) > 1 else os.path.abspath(default))
