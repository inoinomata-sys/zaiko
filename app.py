from flask import Flask, render_template, request, jsonify, redirect, url_for, abort, send_from_directory
import os
from datetime import datetime
import psycopg2
import psycopg2.extras
import psycopg2.pool
from dotenv import load_dotenv
import urllib.request
import json as json_lib

load_dotenv()

app = Flask(__name__)
DATABASE_URL = os.environ.get('DATABASE_URL')

# 接続プール（最小1・最大5）
_pool = psycopg2.pool.SimpleConnectionPool(1, 5, DATABASE_URL)

# ── メモリキャッシュ ─────────────────────────────────────────────────────────
_cache = {}

def cache_get(key):
    return _cache.get(key)

def cache_set(key, val):
    _cache[key] = val

def cache_del(*keys):
    for k in keys:
        _cache.pop(k, None)
    # プレフィックス一致で削除
    for k in list(_cache.keys()):
        for prefix in keys:
            if k.startswith(str(prefix)):
                _cache.pop(k, None)


@app.template_filter('n')
def num_fmt(v):
    if v is None:
        return '0'
    f = float(v)
    return str(int(f)) if f == int(f) else f'{f:g}'


@app.route('/sw.js')
def service_worker():
    resp = send_from_directory('static', 'sw.js', mimetype='application/javascript')
    resp.headers['Service-Worker-Allowed'] = '/'
    return resp


@app.route('/ping')
def ping():
    return 'ok', 200


# ── DB ヘルパー ──────────────────────────────────────────────────────────────

def get_db():
    global _pool
    try:
        conn = _pool.getconn()
        # 切断されていたら再接続
        if conn.closed:
            _pool.putconn(conn)
            raise psycopg2.InterfaceError
        conn.cursor_factory = psycopg2.extras.RealDictCursor
        return conn
    except Exception:
        # プールを作り直して再試行
        _pool = psycopg2.pool.SimpleConnectionPool(1, 5, DATABASE_URL)
        conn = _pool.getconn()
        conn.cursor_factory = psycopg2.extras.RealDictCursor
        return conn


def release_db(conn):
    try:
        if not conn.closed:
            _pool.putconn(conn)
    except Exception:
        pass


def products_with_stock_supplier(store='イノ', supplier='ワールド美容'):
    key = f'pws:{store}:{supplier}'
    hit = cache_get(key)
    if hit is not None:
        return hit
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
    release_db(db)
    cache_set(key, rows)
    return rows


def products_with_stock(store='イノ', cat=None):
    key = f'pw:{store}:{cat}'
    hit = cache_get(key)
    if hit is not None:
        return hit
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
    release_db(db)
    cache_set(key, rows)
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
    release_db(db)

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
    release_db(db)
    cache_del(f'pw:{store}:', f'pws:{store}:')
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
    release_db(db)
    cache_del(f'pw:{store}:', f'pws:{store}:')
    return jsonify(ok=True)


# ── 発注書 ───────────────────────────────────────────────────────────────────

LINE_TOKEN = os.environ.get('LINE_CHANNEL_ACCESS_TOKEN', '')

def send_line_message(to_user_id, text):
    if not LINE_TOKEN or not to_user_id:
        return False
    data = json_lib.dumps({
        'to': to_user_id,
        'messages': [{'type': 'text', 'text': text}]
    }).encode('utf-8')
    req = urllib.request.Request(
        'https://api.line.me/v2/bot/message/push',
        data=data,
        headers={
            'Content-Type': 'application/json',
            'Authorization': f'Bearer {LINE_TOKEN}'
        },
        method='POST'
    )
    try:
        urllib.request.urlopen(req, timeout=10)
        return True
    except Exception:
        return False


STORES = ['イノ', 'リッチ']

SUPPLIER_NAMES = ['ワールド美容', 'トピー商事', 'アイテム', '三井コーポレーション']


def get_suppliers_with_contacts():
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT supplier_name, contact_name, fax, line_user_id, id FROM supplier_contacts ORDER BY supplier_name, sort_order, id")
    rows = cur.fetchall()
    release_db(db)
    result = {}
    for name in SUPPLIER_NAMES:
        result[name] = {'name': name, 'contacts': []}
    for r in rows:
        sn = r['supplier_name']
        if sn not in result:
            result[sn] = {'name': sn, 'contacts': []}
        result[sn]['contacts'].append({
            'id': r['id'],
            'name': r['contact_name'],
            'fax': r['fax'],
            'line_user_id': r['line_user_id'],
        })
    return list(result.values())

@app.route('/order')
def order_top():
    store = request.args.get('store', '')
    suppliers = get_suppliers_with_contacts()
    db = get_db()
    cur = db.cursor()
    supplier_list = []
    for s in suppliers:
        cur.execute("SELECT COUNT(*) FROM products WHERE is_active=true AND supplier=%s", (s['name'],))
        cnt = cur.fetchone()['count']
        supplier_list.append({**s, 'count': cnt})
    release_db(db)
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
    release_db(db)

    rows = products_with_stock_supplier(store, supplier)
    grouped = {}
    for r in rows:
        grouped.setdefault(r['category'], []).append(r)

    suppliers = get_suppliers_with_contacts()
    sup_info = next((s for s in suppliers if s['name'] == supplier), suppliers[0])
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
        pid = int(item['product_id']) if item.get('product_id') else None
        cur.execute("""
            INSERT INTO order_items (order_id, product_id, base_qty, stock_qty, order_qty, custom_name)
            VALUES (%s,%s,%s,%s,%s,%s)
        """, (order_id, pid,
              item.get('base_qty', 0), item.get('stock_qty', 0),
              float(item['order_qty']), item.get('custom_name')))
    db.commit()
    release_db(db)
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
        WHERE oi.order_id = %s AND oi.product_id IS NOT NULL
        ORDER BY p.col_group, p.sort_order
    """, (order_id,))
    items = cur.fetchall()

    cur.execute("""
        SELECT order_qty, custom_name FROM order_items
        WHERE order_id=%s AND product_id IS NULL AND custom_name IS NOT NULL
    """, (order_id,))
    custom_items = cur.fetchall()

    sup_name = order.get('supplier', 'ワールド美容')
    all_products = products_with_stock_supplier(order['store'], sup_name)
    order_map = {r['product_id']: r for r in items}

    groups = {}
    for p in all_products:
        groups.setdefault(p['col_group'], []).append(p)

    suppliers = get_suppliers_with_contacts()
    sup_info = next((s for s in suppliers if s['name'] == sup_name), suppliers[0])
    release_db(db)
    return render_template('print.html',
        order=order, groups=groups, order_map=order_map, sup_info=sup_info,
        custom_items=custom_items)



@app.route('/order/<int:order_id>/send_line', methods=['POST'])
def order_send_line(order_id):
    req_data = request.get_json() or {}
    contact_index = int(req_data.get('contact_index', 0))

    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM orders WHERE id=%s", (order_id,))
    order = cur.fetchone()
    if not order:
        release_db(db)
        return jsonify(ok=False, error='注文が見つかりません')

    cur.execute("""
        SELECT oi.order_qty, p.name, p.category
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = %s
        ORDER BY p.col_group, p.sort_order
    """, (order_id,))
    items = cur.fetchall()
    release_db(db)

    suppliers = get_suppliers_with_contacts()
    sup_info = next((s for s in suppliers if s['name'] == order.get('supplier', '')), None)
    if not sup_info:
        return jsonify(ok=False, error='この仕入先が見つかりません')

    contacts = sup_info.get('contacts', [])
    if contact_index >= len(contacts):
        return jsonify(ok=False, error='担当者が見つかりません')
    contact = contacts[contact_index]
    if not contact.get('line_user_id'):
        return jsonify(ok=False, error='この担当者のLINE IDが未設定です')

    contact_label = contact['name'] if contact['name'] else ''
    lines = [f"【発注】{order['order_month']}月{order['order_day']}日 {order['store']}",
             f"仕入先：{order['supplier']}" + (f"　{contact_label}" if contact_label else ""), ""]
    current_cat = ''
    for it in items:
        if it['category'] != current_cat:
            current_cat = it['category']
            lines.append(f"▼ {current_cat}")
        lines.append(f"  {it['name']}  ×{int(it['order_qty'])}")
    lines += ["", "(有)イノ　TEL: 072-999-6571"]
    text = '\n'.join(lines)

    ok = send_line_message(contact['line_user_id'], text)
    return jsonify(ok=ok, error=None if ok else '送信に失敗しました')


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
    release_db(db)

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
    release_db(db)
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
    release_db(db)
    cache_del('pw:', 'pws:')
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/toggle', methods=['POST'])
def product_toggle(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET is_active = NOT is_active WHERE id=%s", (pid,))
    db.commit()
    release_db(db)
    cache_del('pw:', 'pws:')
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/base', methods=['POST'])
def product_base(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET base_qty=%s WHERE id=%s",
               (float(request.form['base_qty']), pid))
    db.commit()
    release_db(db)
    cache_del('pw:', 'pws:')
    return jsonify(ok=True)


@app.route('/products/<int:pid>/supplier', methods=['POST'])
def product_supplier(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET supplier=%s WHERE id=%s",
               (request.form['supplier'], pid))
    db.commit()
    release_db(db)
    cache_del('pw:', 'pws:')
    return jsonify(ok=True)


# ── LINE フォロワー取得 ────────────────────────────────────────────────────────

def get_line_followers():
    """公式LINEを友達追加したユーザーIDとプロフィール名を取得"""
    if not LINE_TOKEN:
        return []
    results = []
    next_token = None
    while True:
        url = 'https://api.line.me/v2/bot/followers/ids?limit=1000'
        if next_token:
            url += f'&start={next_token}'
        req = urllib.request.Request(url, headers={'Authorization': f'Bearer {LINE_TOKEN}'})
        try:
            with urllib.request.urlopen(req, timeout=10) as r:
                data = json_lib.loads(r.read())
            for uid in data.get('userIds', []):
                # プロフィール取得
                preq = urllib.request.Request(
                    f'https://api.line.me/v2/bot/profile/{uid}',
                    headers={'Authorization': f'Bearer {LINE_TOKEN}'}
                )
                try:
                    with urllib.request.urlopen(preq, timeout=5) as pr:
                        profile = json_lib.loads(pr.read())
                    results.append({'user_id': uid, 'display_name': profile.get('displayName', uid)})
                except Exception:
                    results.append({'user_id': uid, 'display_name': uid})
            next_token = data.get('next')
            if not next_token:
                break
        except Exception:
            break
    return results


@app.route('/api/line/followers')
def api_line_followers():
    followers = get_line_followers()
    return jsonify(followers=followers)


# ── 担当者管理 ────────────────────────────────────────────────────────────────

@app.route('/settings/contacts')
def contacts_settings():
    suppliers = get_suppliers_with_contacts()
    return render_template('contacts.html', suppliers=suppliers, supplier_names=SUPPLIER_NAMES)


@app.route('/settings/contacts/add', methods=['POST'])
def contact_add():
    supplier_name = request.form['supplier_name']
    contact_name  = request.form.get('contact_name', '')
    fax           = request.form.get('fax', '')
    line_user_id  = request.form.get('line_user_id', '')
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT COALESCE(MAX(sort_order),0) FROM supplier_contacts WHERE supplier_name=%s", (supplier_name,))
    max_sort = cur.fetchone()['coalesce']
    cur.execute("INSERT INTO supplier_contacts (supplier_name, contact_name, fax, line_user_id, sort_order) VALUES (%s,%s,%s,%s,%s)",
                (supplier_name, contact_name, fax, line_user_id, max_sort + 1))
    db.commit()
    release_db(db)
    return redirect(url_for('contacts_settings'))


@app.route('/settings/contacts/<int:cid>/update', methods=['POST'])
def contact_update(cid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE supplier_contacts SET contact_name=%s, fax=%s, line_user_id=%s WHERE id=%s",
                (request.form.get('contact_name', ''),
                 request.form.get('fax', ''),
                 request.form.get('line_user_id', ''),
                 cid))
    db.commit()
    release_db(db)
    return redirect(url_for('contacts_settings'))


@app.route('/settings/contacts/<int:cid>/delete', methods=['POST'])
def contact_delete(cid):
    db = get_db()
    cur = db.cursor()
    cur.execute("DELETE FROM supplier_contacts WHERE id=%s", (cid,))
    db.commit()
    release_db(db)
    return redirect(url_for('contacts_settings'))


if __name__ == '__main__':
    app.run(debug=True, port=5001)
