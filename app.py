from flask import Flask, render_template, request, jsonify, redirect, url_for, abort
import os
from datetime import datetime
import psycopg2
import psycopg2.extras
from dotenv import load_dotenv

load_dotenv()

app = Flask(__name__)
DATABASE_URL = os.environ.get('DATABASE_URL')


# ── DB ヘルパー ──────────────────────────────────────────────────────────────

def get_db():
    conn = psycopg2.connect(DATABASE_URL, cursor_factory=psycopg2.extras.RealDictCursor)
    return conn


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

@app.route('/order')
def order_form():
    store = request.args.get('store', 'イノ')
    rows  = products_with_stock(store)

    grouped = {}
    cats = []
    for r in rows:
        c = r['category']
        grouped.setdefault(c, []).append(r)
        if c and c not in cats:
            cats.append(c)

    return render_template('order.html', store=store, grouped=grouped,
                           cats=cats, rows=rows, now=datetime.now())


@app.route('/order/save', methods=['POST'])
def order_save():
    data  = request.get_json()
    store = data.get('store', 'イノ')
    items = data.get('items', [])
    month = data.get('month')
    day   = data.get('day')

    db = get_db()
    cur = db.cursor()
    now = datetime.now().strftime('%Y-%m-%d %H:%M')
    cur.execute("""
        INSERT INTO orders (store, supplier, order_month, order_day, status, created_at)
        VALUES (%s, 'ワールド美容', %s, %s, 'confirmed', %s)
        RETURNING id
    """, (store, month, day, now))
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

    all_products = products_with_stock(order['store'])
    order_map = {r['product_id']: r for r in items}

    groups = {}
    for p in all_products:
        groups.setdefault(p['col_group'], []).append(p)

    db.close()
    return render_template('print.html',
        order=order, groups=groups, order_map=order_map)


@app.route('/order/history')
def order_history():
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM orders ORDER BY created_at DESC LIMIT 50")
    orders = cur.fetchall()
    db.close()
    return render_template('history.html', orders=orders)


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
        INSERT INTO products (col_group, category, name, base_qty, note, sort_order)
        VALUES (%s,%s,%s,%s,%s,%s)
    """, (int(d['col_group']), d['category'], d['name'],
          float(d.get('base_qty', 0)), d.get('note') or None, max_sort + 1))
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


if __name__ == '__main__':
    app.run(debug=True, port=5001)
