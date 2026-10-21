from flask import Flask, render_template, request, jsonify, redirect, url_for, abort, send_from_directory, make_response
import os
import time
from datetime import datetime, timedelta, timezone
from collections import OrderedDict
import urllib.parse
import unicodedata
import psycopg2
import psycopg2.extras
import psycopg2.pool
from dotenv import load_dotenv
from fpdf import FPDF

load_dotenv()

app = Flask(__name__)
DATABASE_URL = os.environ.get('DATABASE_URL')
JST = timezone(timedelta(hours=9))

_pool = psycopg2.pool.ThreadedConnectionPool(
    1, 5, DATABASE_URL, keepalives=1, keepalives_idle=30, keepalives_interval=10, keepalives_count=3)

STORES = ['イノ', 'リッチ']
SUPPLIER_NAMES = ['ワールド美容', 'トピー商事', 'アイテム', '三井コーポレーション',
                  'ナプラ', 'コタ', 'アルマダ', 'リファ']
STALE_DAYS = 7


def nfkc(v):
    """全角英数字・半角カナなどの表記ゆれをそろえる"""
    return unicodedata.normalize('NFKC', v).strip() if v else v


def now_jst():
    return datetime.now(JST)


@app.template_filter('n')
def num_fmt(v):
    if v is None:
        return '0'
    f = float(v)
    return str(int(f)) if f == int(f) else f'{f:g}'


@app.template_filter('jst')
def jst_fmt(v):
    if not v:
        return ''
    return v.astimezone(JST).strftime('%m/%d %H:%M')


@app.context_processor
def inject_globals():
    return {'supplier_names': SUPPLIER_NAMES, 'stores': STORES}


@app.route('/sw.js')
def service_worker():
    resp = send_from_directory('static', 'sw.js', mimetype='application/javascript')
    resp.headers['Service-Worker-Allowed'] = '/'
    return resp


@app.route('/ping')
def ping():
    return 'ok', 200


# ── DB ヘルパー ──────────────────────────────────────────────────────────────

_last_used = {}
IDLE_CHECK_SEC = 60


def get_db():
    conn = _pool.getconn()
    # しばらく使っていない接続はDB側で切られていることがあるので、確かめてから使う
    if conn.closed or time.time() - _last_used.get(id(conn), 0) > IDLE_CHECK_SEC:
        try:
            conn.cursor().execute('SELECT 1')
            conn.rollback()
        except Exception:
            _pool.putconn(conn, close=True)
            conn = _pool.getconn()
    conn.cursor_factory = psycopg2.extras.RealDictCursor
    return conn


def release_db(conn):
    _last_used[id(conn)] = time.time()
    try:
        if not conn.closed:
            conn.rollback()  # 失敗したトランザクションを残したままプールに戻さない
    except Exception:
        pass
    _pool.putconn(conn, close=conn.closed)


def migrate():
    db = get_db()
    cur = db.cursor()
    cur.execute("ALTER TABLE stock ADD COLUMN IF NOT EXISTS checked_at TIMESTAMPTZ")
    cur.execute("ALTER TABLE stock ADD COLUMN IF NOT EXISTS version INTEGER NOT NULL DEFAULT 0")
    cur.execute("ALTER TABLE stock ADD COLUMN IF NOT EXISTS changed_at TIMESTAMPTZ")
    cur.execute("ALTER TABLE order_items ALTER COLUMN product_id DROP NOT NULL")
    db.commit()
    release_db(db)


migrate()


PRODUCT_STOCK_SQL = """
    SELECT p.id, p.col_group, p.category, p.name, p.base_qty, p.note, p.sort_order, p.supplier,
           COALESCE(s.current_qty, 0) AS current_qty, s.changed_at, s.checked_at,
           COALESCE(s.version, 0) AS version
    FROM products p
    LEFT JOIN stock s ON p.id = s.product_id AND s.store = %s
    WHERE p.is_active=true {where}
    ORDER BY p.col_group, p.sort_order
"""


def products_with_stock(store='イノ', cat=None, supplier=None):
    where, params = '', [store]
    if cat:
        where += " AND p.category=%s"
        params.append(cat)
    if supplier:
        where += " AND p.supplier=%s"
        params.append(supplier)
    db = get_db()
    cur = db.cursor()
    cur.execute(PRODUCT_STOCK_SQL.format(where=where), params)
    rows = cur.fetchall()
    release_db(db)
    return rows


# ── 在庫管理 ─────────────────────────────────────────────────────────────────

@app.route('/')
def inventory():
    store = request.args.get('store', 'イノ')
    if store not in STORES:
        store = 'イノ'
    cat = request.args.get('cat', '')

    db = get_db()
    cur = db.cursor()
    cur.execute(
        "SELECT category FROM products WHERE is_active=true AND category!='' GROUP BY category ORDER BY MIN(sort_order)"
    )
    cats = [r['category'] for r in cur.fetchall()]
    release_db(db)

    rows = products_with_stock(store, cat or None)
    stale_before = now_jst() - timedelta(days=STALE_DAYS)
    grouped = OrderedDict()
    counts = {'unchecked': 0, 'stale': 0, 'shortage': 0}
    for r in rows:
        r = dict(r)
        r['unchecked'] = r['checked_at'] is None
        r['stale'] = (not r['unchecked']) and r['checked_at'] < stale_before
        r['shortage'] = max(float(r['base_qty'] or 0) - float(r['current_qty'] or 0), 0)
        counts['unchecked'] += r['unchecked']
        counts['stale'] += r['stale']
        counts['shortage'] += r['shortage'] > 0
        grouped.setdefault(r['category'], []).append(r)

    return render_template('inventory.html',
        store=store, cats=cats, current_cat=cat, grouped=grouped,
        counts=counts, stale_days=STALE_DAYS)


def stock_payload(row):
    return {
        'qty': float(row['current_qty']),
        'version': row['version'],
        'changed_at': jst_fmt(row['changed_at']),
        'checked_at': jst_fmt(row['checked_at']),
    }


@app.route('/api/stock', methods=['POST'])
def api_update_stock():
    """op=delta: 加減（同時操作でも合算）/ op=set: 直接入力（version不一致は409）/ op=check: 数量を変えず確認済みにする"""
    data = request.get_json() or {}
    try:
        product_id = int(data['product_id'])
        op = data.get('op', 'set')
        value = float(data.get('value', 0))
    except (KeyError, TypeError, ValueError):
        return jsonify(ok=False, error='入力が不正です'), 400
    store = data.get('store', 'イノ')
    if store not in STORES or op not in ('delta', 'set', 'check'):
        return jsonify(ok=False, error='入力が不正です'), 400
    if op == 'set' and value < 0:
        return jsonify(ok=False, error='0未満は保存できません'), 400

    now = now_jst()
    db = get_db()
    try:
        cur = db.cursor()
        cur.execute("""
            INSERT INTO stock (product_id, store, current_qty, version)
            VALUES (%s, %s, 0, 0) ON CONFLICT (product_id, store) DO NOTHING
        """, (product_id, store))
        cur.execute("SELECT current_qty, version FROM stock WHERE product_id=%s AND store=%s FOR UPDATE",
                    (product_id, store))
        row = cur.fetchone()

        if op == 'set':
            expected = data.get('version')
            if expected is not None and int(expected) != row['version']:
                cur.execute("SELECT current_qty, version, changed_at, checked_at FROM stock WHERE product_id=%s AND store=%s",
                            (product_id, store))
                latest = cur.fetchone()
                db.rollback()
                return jsonify(ok=False, conflict=True,
                               error='他の端末で先に更新されました。最新の数量を表示します。',
                               **stock_payload(latest)), 409
            new_qty = value
        elif op == 'delta':
            new_qty = max(float(row['current_qty'] or 0) + value, 0)
        else:
            new_qty = float(row['current_qty'] or 0)

        changed = new_qty != float(row['current_qty'] or 0)
        cur.execute("""
            UPDATE stock SET current_qty=%s, version=version+1, checked_at=%s,
                   changed_at=CASE WHEN %s THEN %s ELSE changed_at END,
                   updated_at=%s
            WHERE product_id=%s AND store=%s
            RETURNING current_qty, version, changed_at, checked_at
        """, (new_qty, now, changed, now, now.strftime('%Y-%m-%d %H:%M'), product_id, store))
        result = cur.fetchone()
        db.commit()
    except Exception:
        db.rollback()
        release_db(db)
        return jsonify(ok=False, error='保存に失敗しました'), 500
    release_db(db)
    return jsonify(ok=True, **stock_payload(result))


@app.route('/api/stock/check_category', methods=['POST'])
def api_check_category():
    data = request.get_json() or {}
    store = data.get('store', 'イノ')
    cat = data.get('category')
    if store not in STORES or cat is None:
        return jsonify(ok=False, error='入力が不正です'), 400
    now = now_jst()
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        INSERT INTO stock (product_id, store, current_qty, version, checked_at, updated_at)
        SELECT id, %s, 0, 1, %s, %s FROM products WHERE is_active=true AND category=%s
        ON CONFLICT (product_id, store)
        DO UPDATE SET checked_at=EXCLUDED.checked_at, version=stock.version+1
    """, (store, now, now.strftime('%Y-%m-%d %H:%M'), cat))
    n = cur.rowcount
    db.commit()
    release_db(db)
    return jsonify(ok=True, count=n, checked_at=jst_fmt(now))


# ── 発注書 ───────────────────────────────────────────────────────────────────

def get_suppliers_with_contacts():
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT supplier_name, contact_name, fax, id FROM supplier_contacts ORDER BY supplier_name, sort_order, id")
    rows = cur.fetchall()
    release_db(db)
    result = OrderedDict((name, {'name': name, 'contacts': []}) for name in SUPPLIER_NAMES)
    for r in rows:
        sn = r['supplier_name']
        result.setdefault(sn, {'name': sn, 'contacts': []})
        result[sn]['contacts'].append({'id': r['id'], 'name': r['contact_name'], 'fax': r['fax']})
    return list(result.values())


def supplier_info(name):
    suppliers = get_suppliers_with_contacts()
    return next((s for s in suppliers if s['name'] == name), {'name': name, 'contacts': []})


@app.route('/order')
def order_top():
    store = request.args.get('store', '')
    suppliers = get_suppliers_with_contacts()
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT supplier, COUNT(*) AS c FROM products WHERE is_active=true GROUP BY supplier")
    counts = {r['supplier']: r['c'] for r in cur.fetchall()}
    release_db(db)
    supplier_list = [{**s, 'count': counts.get(s['name'], 0)} for s in suppliers]
    return render_template('order_top.html', suppliers=supplier_list, store=store)


@app.route('/order/form')
def order_form():
    store    = request.args.get('store', 'イノ')
    supplier = request.args.get('supplier', 'ワールド美容')

    rows = products_with_stock(store, supplier=supplier)
    grouped = OrderedDict()
    for r in rows:
        grouped.setdefault(r['category'], []).append(r)

    return render_template('order.html', store=store, grouped=grouped,
                           cats=list(grouped.keys()), supplier=supplier,
                           sup_info=supplier_info(supplier))


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
    cur.execute("""
        INSERT INTO orders (store, supplier, order_month, order_day, status, created_at)
        VALUES (%s, %s, %s, %s, 'confirmed', %s)
        RETURNING id
    """, (store, supplier, month, day, now_jst().strftime('%Y-%m-%d %H:%M')))
    order_id = cur.fetchone()['id']

    rows = [(order_id, int(item['product_id']) if item.get('product_id') else None,
             item.get('base_qty', 0), item.get('stock_qty', 0),
             float(item['order_qty']), nfkc(item.get('custom_name')))
            for item in items if float(item.get('order_qty', 0)) > 0]
    if rows:
        psycopg2.extras.execute_values(cur, """
            INSERT INTO order_items (order_id, product_id, base_qty, stock_qty, order_qty, custom_name)
            VALUES %s
        """, rows, page_size=1000)
    db.commit()
    release_db(db)
    return jsonify(ok=True, order_id=order_id)


CUSTOM_CAT = 'リスト外の品（手入力）'


def load_order(order_id):
    """発注書1件分を、カテゴリ順のブロック [(カテゴリ名, [{name, note, qty}])] として返す"""
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM orders WHERE id=%s", (order_id,))
    order = cur.fetchone()
    if not order:
        release_db(db)
        abort(404)
    cur.execute("""
        SELECT oi.order_qty, oi.custom_name, p.name, p.category, p.note
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = %s
        ORDER BY p.col_group NULLS LAST, p.sort_order NULLS LAST, oi.id
    """, (order_id,))
    rows = cur.fetchall()
    release_db(db)

    blocks = OrderedDict()
    for r in rows:
        if r['name'] is not None:
            blocks.setdefault(r['category'] or 'その他', []).append(
                {'name': r['name'], 'note': r['note'], 'qty': r['order_qty']})
        elif r['custom_name']:
            blocks.setdefault(CUSTOM_CAT, []).append(
                {'name': r['custom_name'], 'note': None, 'qty': r['order_qty']})
    return order, list(blocks.items())


@app.route('/order/<int:order_id>/print')
def order_print(order_id):
    order, blocks = load_order(order_id)
    item_count = sum(len(items) for _, items in blocks)
    total_qty = sum(float(it['qty']) for _, items in blocks for it in items)
    return render_template('print.html', order=order, blocks=blocks,
                           sup_info=supplier_info(order.get('supplier') or 'ワールド美容'),
                           item_count=item_count, total_qty=total_qty)


class OrderPDF(FPDF):
    font_name = 'Helvetica'
    title_text = ''

    def header(self):
        if self.page_no() > 1:
            self.set_font(self.font_name, '', 10)
            self.cell(0, 6, self.title_text, new_x='LMARGIN', new_y='NEXT')
            self.ln(1)

    def footer(self):
        self.set_y(-12)
        self.set_font(self.font_name, '', 10)
        self.cell(0, 6, f'{self.page_no()} / {{nb}} ページ', align='C')


PDF_COLS = 3


@app.route('/order/<int:order_id>/pdf')
def order_pdf(order_id):
    order, blocks = load_order(order_id)
    sup_name = order.get('supplier') or 'ワールド美容'
    sup = supplier_info(sup_name)
    contact_name = sup['contacts'][0]['name'] if sup['contacts'] else ''
    date_str = f"{order['order_month']}月{order['order_day']}日"

    pdf = OrderPDF(orientation='P', unit='mm', format='A4')
    font_path = os.path.join(os.path.dirname(__file__), 'static', 'NotoSansJP-Regular.ttf')
    if os.path.exists(font_path):
        pdf.add_font('NotoSans', '', font_path)
        pdf.font_name = 'NotoSans'
    f = pdf.font_name
    pdf.title_text = f"発注書　{sup_name}御中　{date_str}　{order['store']}"
    pdf.set_margins(10, 10, 10)
    pdf.set_auto_page_break(auto=False)
    pdf.alias_nb_pages()
    # 同梱フォントが極細のため、文字に輪郭線を重ねて太く見せる
    pdf.text_mode = 'FILL_STROKE'
    pdf.set_line_width(0.25)
    pdf.add_page()

    pdf.set_font(f, '', 18)
    pdf.cell(0, 10, f"{sup_name} 御中" + (f"　担当 {contact_name}" if contact_name else ''), new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 13)
    pdf.cell(110, 8, f"発注日 {date_str}　店舗 {order['store']}")
    pdf.cell(0, 8, "(有)イノ　TEL 072-999-6571", align='R', new_x='LMARGIN', new_y='NEXT')
    pdf.ln(2)

    gap = 3
    col_w = (pdf.w - pdf.l_margin - pdf.r_margin - gap * (PDF_COLS - 1)) / PDF_COLS
    qty_w = 13
    name_w = col_w - qty_w
    FS = 13
    row_h, line_h, head_h = 7.5, 6, 7.5
    bottom = pdf.h - 16
    pos = {'col': 0, 'top': pdf.get_y(), 'y': pdf.get_y()}

    def x0():
        return pdf.l_margin + pos['col'] * (col_w + gap)

    def col_head():
        pdf.set_font(f, '', FS)
        pdf.set_fill_color(235, 235, 235)
        pdf.set_xy(x0(), pos['y'])
        pdf.cell(name_w, head_h, ' 商品名', border=1, fill=True)
        pdf.cell(qty_w, head_h, ' 数量', border=1, fill=True)
        pos['y'] += head_h

    def next_col():
        pos['col'] += 1
        if pos['col'] >= PDF_COLS:
            pdf.add_page()
            pos['col'], pos['top'] = 0, pdf.get_y()
        pos['y'] = pos['top']
        col_head()

    def ensure(h, cont_cat=None):
        if pos['y'] + h > bottom:
            next_col()
            if cont_cat:
                cat_row(cont_cat + '（続き）')

    def cat_row(text):
        pdf.set_font(f, '', FS)
        pdf.set_fill_color(205, 205, 205)
        pdf.set_xy(x0(), pos['y'])
        pdf.cell(col_w, head_h, ' ' + text, border=1, fill=True)
        pos['y'] += head_h

    col_head()
    for cat, items in blocks:
        ensure(head_h + row_h)
        cat_row(cat)
        for it in items:
            name = it['name'] + (f"（{it['note']}）" if it['note'] else '')
            pdf.set_font(f, '', FS)
            n = len(pdf.multi_cell(name_w - 2, line_h, name, dry_run=True, output='LINES'))
            h = max(row_h, n * line_h + 1.5)
            ensure(h, cat)
            pdf.set_font(f, '', FS)
            x, y = x0(), pos['y']
            pdf.rect(x, y, name_w, h)
            pdf.rect(x + name_w, y, qty_w, h)
            pdf.set_xy(x + 1, y + (h - n * line_h) / 2)
            pdf.multi_cell(name_w - 2, line_h, name)
            pdf.set_xy(x + name_w + 1, y + (h - n * line_h) / 2)
            pdf.cell(qty_w - 1, line_h, num_fmt(it['qty']))
            pos['y'] += h

    if not blocks:
        pdf.set_font(f, '', 13)
        pdf.set_xy(pdf.l_margin, pos['y'] + 4)
        pdf.cell(0, 10, '発注品はありません')

    filename = f"発注書_{date_str}_{sup_name}.pdf"
    response = make_response(bytes(pdf.output()))
    response.headers['Content-Type'] = 'application/pdf'
    disposition = 'inline' if request.args.get('inline') else 'attachment'
    response.headers['Content-Disposition'] = f"{disposition}; filename*=UTF-8''{urllib.parse.quote(filename)}"
    return response


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

    grouped = OrderedDict()
    for o in orders:
        grouped.setdefault((o['order_month'], o['order_day']), []).append(o)
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
    """, (int(d['col_group']), nfkc(d['category']), nfkc(d['name']),
          float(d.get('base_qty', 0)), nfkc(d.get('note')) or None,
          d.get('supplier', 'ワールド美容'), max_sort + 1))
    db.commit()
    release_db(db)
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/toggle', methods=['POST'])
def product_toggle(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET is_active = NOT is_active WHERE id=%s", (pid,))
    db.commit()
    release_db(db)
    return redirect(url_for('products'))


@app.route('/products/<int:pid>/base', methods=['POST'])
def product_base(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET base_qty=%s WHERE id=%s",
               (float(request.form['base_qty']), pid))
    db.commit()
    release_db(db)
    return jsonify(ok=True)


@app.route('/products/<int:pid>/supplier', methods=['POST'])
def product_supplier(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET supplier=%s WHERE id=%s",
               (request.form['supplier'], pid))
    db.commit()
    release_db(db)
    return jsonify(ok=True)


# ── 担当者管理 ────────────────────────────────────────────────────────────────

@app.route('/settings/contacts')
def contacts_settings():
    return render_template('contacts.html', suppliers=get_suppliers_with_contacts())


@app.route('/settings/contacts/add', methods=['POST'])
def contact_add():
    supplier_name = request.form['supplier_name']
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT COALESCE(MAX(sort_order),0) FROM supplier_contacts WHERE supplier_name=%s", (supplier_name,))
    max_sort = cur.fetchone()['coalesce']
    cur.execute("INSERT INTO supplier_contacts (supplier_name, contact_name, fax, sort_order) VALUES (%s,%s,%s,%s)",
                (supplier_name, request.form.get('contact_name', ''), request.form.get('fax', ''), max_sort + 1))
    db.commit()
    release_db(db)
    return redirect(url_for('contacts_settings'))


@app.route('/settings/contacts/<int:cid>/update', methods=['POST'])
def contact_update(cid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE supplier_contacts SET contact_name=%s, fax=%s WHERE id=%s",
                (request.form.get('contact_name', ''), request.form.get('fax', ''), cid))
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
