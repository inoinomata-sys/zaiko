from flask import Flask, render_template, request, jsonify, redirect, url_for, abort, send_from_directory
import os
from datetime import datetime
import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
DATABASE_URL = os.environ.get('DATABASE_URL')


@app.route('/sw.js')
def service_worker():
    resp = send_from_directory('static', 'sw.js', mimetype='application/javascript')
    resp.headers['Service-Worker-Allowed'] = '/'
    return resp


# ── DB ヘルパー ──────────────────────────────────────────────────────────────

def get_db():
    conn = psycopg2.connect(DATABASE_URL, cursor_factory=psycopg2.extras.RealDictCursor)
    return conn


def products_with_stock_supplier(store='イノ', supplier='ワールド美容'):
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        SELECT p.id, p.col_group, p.category, p.name, p.base_qty, p.note, p.sort_order, p.supplier,
               COALESCE(s.current_qty, 0) AS current_qty, s.updated_at
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id AND s.store = %s
        WHERE p.is_active=true AND p.supplier=%s
        ORDER BY p.col_group, p.sort_order
    """, (store, supplier))
    rows = cur.fetchall()
    db.close()
    return rows


def products_with_stock(store='イノ', cat=None):
    db = get_db()
    cur = db.cursor()
    where = "WHERE p.is_active=true"
    params = [store]
    if cat:
        where += " AND p.category=%s"
        params.append(cat)
    cur.execute(f"""
        SELECT p.id, p.col_group, p.category, p.name, p.base_qty, p.note, p.sort_order,
               COALESCE(s.current_qty, 0) AS current_qty,
               s.updated_at
        FROM products p
        LEFT JOIN stock s ON p.id = s.product_id AND s.store = %s
        {where}
        ORDER BY p.col_group, p.sort_order
    """, params)
    rows = cur.fetchall()
    db.close()
    return rows


# ── 在庫管理 ─────────────────────────────────────────────────────────────────

@app.route('/')
def inventory():
    store = request.args.get('store', 'イノ')
    cat   = request.args.get('cat', '')

    db = get_db()
    cur = db.cursor()
    cur.execute(
        "SELECT category FROM products WHERE is_active=true AND category!='' GROUP BY category ORDER BY MIN(sort_order)"
    )
    cats = [r['category'] for r in cur.fetchall()]
    db.close()

    rows = products_with_stock(store, cat or None)

    grouped = {}
    for r in rows:
        grouped.setdefault(r['category'], []).append(r)

    return render_template('inventory.html',
        store=store, cats=cats, current_cat=cat,
        grouped=grouped, now=datetime.now()
    )


@app.route('/api/stock', methods=['POST'])
def api_update_stock():
    data = request.get_json()
    product_id = int(data['product_id'])
    qty        = float(data['qty'])
    store      = data.get('store', 'イノ')
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        INSERT INTO stock (product_id, store, current_qty, updated_at)
        VALUES (%s,%s,%s,%s)
        ON CONFLICT(product_id, store)
        DO UPDATE SET current_qty=EXCLUDED.current_qty, updated_at=EXCLUDED.updated_at
    """, (product_id, store, qty, datetime.now().strftime('%Y-%m-%d %H:%M')))
    db.commit()
    db.close()
    return jsonify(ok=True)


@app.route('/api/stock/bulk', methods=['POST'])
def api_bulk_stock():
    data  = request.get_json()
    store = data.get('store', 'イノ')
    items = data.get('items', [])
    db = get_db()
    cur = db.cursor()
    now = datetime.now().strftime('%Y-%m-%d %H:%M')
    for item in items:
        cur.execute("""
            INSERT INTO stock (product_id, store, current_qty, updated_at)
            VALUES (%s,%s,%s,%s)
            ON CONFLICT(product_id, store)
            DO UPDATE SET current_qty=EXCLUDED.current_qty, updated_at=EXCLUDED.updated_at
        """, (int(item['product_id']), store, float(item['qty']), now))
    db.commit()
    db.close()
    return jsonify(ok=True)


# ── 発注書 ───────────────────────────────────────────────────────────────────

STORES = ['イノ', 'リッチ']

SUPPLIERS = [
    {'name': 'ワールド美容',        'contact': '担当 大橋様　FAX: 06-6696-0071'},
    {'name': 'トピー商事',          'contact': ''},
    {'name': 'アイテム',            'contact': ''},
    {'name': '三井コーポレーション', 'contact': ''},
]

@app.route('/order')
def order_top():
    store = request.args.get('store', '')
    db = get_db()
    cur = db.cursor()
    supplier_list = []
    for s in SUPPLIERS:
        cur.execute("SELECT COUNT(*) FROM products WHERE is_active=true AND supplier=%s", (s['name'],))
        cnt = cur.fetchone()['count']
        supplier_list.append({**s, 'count': cnt})
    db.close()
    return render_template('order_top.html', suppliers=supplier_list, stores=STORES, store=store)


@app.route('/order/form')
def order_form():
    store    = request.args.get('store', 'イノ')
    supplier = request.args.get('supplier', 'ワールド美容')

    db = get_db()
    cur = db.cursor()
    cur.execute(
        "SELECT category FROM products WHERE is_active=true AND supplier=%s AND category!='' GROUP BY category ORDER BY MIN(sort_order)",
        (supplier,)
    )
    cats = [r['category'] for r in cur.fetchall()]
    db.close()

    rows = products_with_stock_supplier(store, supplier)
    grouped = {}
    for r in rows:
        grouped.setdefault(r['category'], []).append(r)

    sup_info = next((s for s in SUPPLIERS if s['name'] == supplier), SUPPLIERS[0])
    return render_template('order.html', store=store, grouped=grouped,
                           cats=cats, rows=rows, now=datetime.now(),
                           supplier=supplier, sup_info=sup_info)


@app.route('/order/save', methods=['POST'])
def order_save():
    data     = request.get_json()
    store    = data.get('store', 'イノ')
    supplier = data.get('supplier', 'ワールド美容')
    items    = data.get('items', [])
    month    = data.get('month')
    day      = data.get('day')

    db = get_db()
    cur = db.cursor()
    now = datetime.now().strftime('%Y-%m-%d %H:%M')
    cur.execute("""
        INSERT INTO orders (store, supplier, order_month, order_day, status, created_at)
        VALUES (%s, %s, %s, %s, 'confirmed', %s)
        RETURNING id
    """, (store, supplier, month, day, now))
    order_id = cur.fetchone()['id']

    for item in items:
        if float(item.get('order_qty', 0)) <= 0:
            continue
        cur.execute("""
            INSERT INTO order_items (order_id, product_id, base_qty, stock_qty, order_qty)
            VALUES (%s,%s,%s,%s,%s)
        """, (order_id, int(item['product_id']),
              item.get('base_qty'), item.get('stock_qty'),
              float(item['order_qty'])))
    db.commit()
    db.close()
    return jsonify(ok=True, order_id=order_id)


@app.route('/order/<int:order_id>/print')
def order_print(order_id):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM orders WHERE id=%s", (order_id,))
    order = cur.fetchone()
    if not order:
        abort(404)

    cur.execute("""
        SELECT oi.*, p.name, p.category, p.col_group, p.note, p.sort_order
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = %s
        ORDER BY p.col_group, p.sort_order
    """, (order_id,))
    items = cur.fetchall()

    sup_name = order.get('supplier', 'ワールド美容')
    all_products = products_with_stock_supplier(order['store'], sup_name)
    order_map = {r['product_id']: r for r in items}

    groups = {}
    for p in all_products:
        groups.setdefault(p['col_group'], []).append(p)

    sup_info = next((s for s in SUPPLIERS if s['name'] == sup_name), SUPPLIERS[0])
    db.close()
    return render_template('print.html',
        order=order, groups=groups, order_map=order_map, sup_info=sup_info)


@app.route('/order/history')
def order_history():
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        SELECT o.*,
               COUNT(oi.id)          AS item_count,
               COALESCE(SUM(oi.order_qty), 0) AS total_qty
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        GROUP BY o.id
        ORDER BY o.order_month DESC, o.order_day DESC, o.created_at DESC
        LIMIT 200
    """)
    orders = cur.fetchall()
    db.close()

    # 日付ごとにグループ化
    from collections import OrderedDict
    grouped = OrderedDict()
    for o in orders:
        key = (o['order_month'], o['order_day'])
        grouped.setdefault(key, []).append(o)

    return render_template('history.html', grouped=grouped)


# ── 商品マスター管理 ──────────────────────────────────────────────────────────

@app.route('/products')
def products():
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM products ORDER BY col_group, sort_order")
    rows = cur.fetchall()
    db.close()
    return render_template('products.html', rows=rows)


@app.route('/products/add', methods=['POST'])
def product_add():
    d = request.form
    db = get_db()
    cur = db.cursor()
    cur.execute(
        "SELECT COALESCE(MAX(sort_order),0) FROM products WHERE col_group=%s",
        (d['col_group'],)
    )
    max_sort = cur.fetchone()['coalesce']
    cur.execute("""
        INSERT INTO products (col_group, category, name, base_qty, note, supplier, sort_order)
        VALUES (%s,%s,%s,%s,%s,%s,%s)
    """, (int(d['col_group']), d['category'], d['name'],
          float(d.get('base_qty', 0)), d.get('note') or None,
          d.get('supplier', 'ワールド美容'), max_sort + 1))
    db.commit()
    db.close()
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/toggle', methods=['POST'])
def product_toggle(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET is_active = NOT is_active WHERE id=%s", (pid,))
    db.commit()
    db.close()
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/base', methods=['POST'])
def product_base(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET base_qty=%s WHERE id=%s",
               (float(request.form['base_qty']), pid))
    db.commit()
    db.close()
    return jsonify(ok=True)


@app.route('/products/<int:pid>/supplier', methods=['POST'])
def product_supplier(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET supplier=%s WHERE id=%s",
               (request.form['supplier'], pid))
    db.commit()
    db.close()
    return jsonify(ok=True)


if __name__ == '__main__':
    app.run(debug=True, port=5001)
