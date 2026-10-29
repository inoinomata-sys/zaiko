from werkzeug.exceptions import HTTPException
from flask import Flask, g, has_request_context, render_template, request, jsonify, redirect, url_for, abort, send_from_directory, make_response
import os
import sys
import hashlib
import math
import traceback
import time
import threading
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
from collections import OrderedDict
import urllib.parse
import unicodedata
import uuid
import psycopg2
import psycopg2.extras
import psycopg2.pool
from dotenv import load_dotenv
from fpdf import FPDF

load_dotenv()

app = Flask(__name__)
DATABASE_URL = os.environ.get('DATABASE_URL')
JST = timezone(timedelta(hours=9))

# 接続プールは最初に使う時に作る（起動時に作ると、サーバーが複数プロセスに分かれる時に接続を共有して壊れるため）
_pool = None
_pool_lock = threading.Lock()


def _get_pool():
    global _pool
    if _pool is None:
        with _pool_lock:
            if _pool is None:
                # 最大数は gunicorn のスレッド数(4)に合わせる。2本は使い終わっても閉じずに残す
                _pool = psycopg2.pool.ThreadedConnectionPool(
                    2, 4, DATABASE_URL, keepalives=1, keepalives_idle=30,
                    keepalives_interval=10, keepalives_count=3)
    return _pool

STORES = ['イノ', 'リッチ']
SUPPLIER_NAMES = ['ワールド美容', 'トピー商事', 'アイテム美容', '三井コーポレーション',
                  'ナプラ（N.シリーズ）Web注文', 'コタ', 'リファ', '買い物忘れ・買い物しといて',
                  'コーナン・業務用スーパー・100均一']
STALE_DAYS = 7
# 発注履歴に出さず、同じ日の二重発注の警告もしない仕入先（買い物リスト）。記録は使用数・在庫の計算のため裏に残る
NO_HISTORY_SUPPLIERS = ['買い物忘れ・買い物しといて']


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


@app.template_filter('sama')
def sama_fmt(name):
    """お客様名の後ろに「様」を付ける（すでに付いている時は重ねない）"""
    name = (name or '').strip()
    return name if name.endswith('様') else f"{name} 様"


@app.template_filter('yen')
def yen_fmt(v):
    return f"¥{int(v):,}"


@app.template_filter('md')
def md_fmt(v):
    return v.astimezone(JST).strftime('%-m/%-d') if v else ''


@app.template_filter('jst')
def jst_fmt(v):
    if not v:
        return ''
    return v.astimezone(JST).strftime('%m/%d %H:%M')


@app.context_processor
def inject_globals():
    return {'supplier_names': SUPPLIER_NAMES, 'stores': STORES}


@app.errorhandler(Exception)
def handle_error(e):
    if isinstance(e, HTTPException):
        return e
    tb = traceback.format_exc()
    app.logger.error(tb)
    log_event(request.method, request.full_path, tb)
    # DBとの接続が途中で切れていた場合（SSL error など）、GET は1回だけ自動でやり直す
    if (isinstance(e, (psycopg2.OperationalError, psycopg2.InterfaceError))
            and request.method == 'GET' and not request.args.get('_r')):
        args = request.args.to_dict(flat=False)
        args['_r'] = ['1']
        return redirect(request.path + '?' + urllib.parse.urlencode(args, doseq=True))
    if request.path.startswith('/api/') or request.method != 'GET':
        return jsonify(ok=False, error='サーバーで一時的なエラーが起きました。もう一度お試しください。'), 500
    return render_template('error.html'), 500


@app.route('/sw.js')
def service_worker():
    resp = send_from_directory('static', 'sw.js', mimetype='application/javascript')
    resp.headers['Service-Worker-Allowed'] = '/'
    return resp


@app.route('/ping')
def ping():
    return 'ok', 200


_BOOT_TIME = time.time()


@app.route('/health')
def health():
    """サーバーの状態（プロセス数・メモリ・稼働時間）。不具合の調査用で、個人情報や設定値は返さない"""
    info = {'pid': os.getpid(), 'uptime_s': int(time.time() - _BOOT_TIME), 'threads': threading.active_count()}
    try:
        with open('/proc/self/status') as f:
            for line in f:
                if line.startswith('VmRSS'):
                    info['rss_mb'] = round(int(line.split()[1]) / 1024)
        procs = []
        for pid in os.listdir('/proc'):
            if pid.isdigit():
                try:
                    cmd = open(f'/proc/{pid}/cmdline', 'rb').read().replace(b'\0', b' ').decode()
                except OSError:
                    continue
                if 'gunicorn' in cmd:
                    procs.append({'pid': int(pid), 'cmd': cmd.strip()[:120]})
        info['gunicorn_processes'] = procs
        info['cpu_count'] = os.cpu_count()
        info['cwd_has_conf'] = os.path.exists('gunicorn.conf.py')
        info['cwd'] = os.getcwd()
        info['gunicorn_env'] = {k: v for k, v in os.environ.items() if k.startswith(('GUNICORN', 'WEB_CONCURRENCY'))}
        info['python'] = sys.version.split()[0]
        import gunicorn
        info['gunicorn'] = gunicorn.__version__
    except Exception as e:
        info['diag_error'] = str(e)
    return jsonify(info)


# ── DB ヘルパー ──────────────────────────────────────────────────────────────

_last_used = {}
IDLE_CHECK_SEC = 60


def _getconn():
    # 接続数の上限などで一時的に失敗することがあるので、少し待って1回だけやり直す
    try:
        return _get_pool().getconn()
    except Exception:
        time.sleep(0.5)
        return _get_pool().getconn()


def _set_autocommit(conn):
    # 読み取りのたびにROLLBACKの往復が発生しないよう自動コミットにする（まとめて書く処理は transaction() を使う）
    if not conn.autocommit:
        if conn.info.transaction_status != psycopg2.extensions.TRANSACTION_STATUS_IDLE:
            conn.rollback()
        conn.autocommit = True


def _checkout():
    conn = _getconn()
    # しばらく使っていない接続はDB側で切られていることがあるので、確かめてから使う
    if conn.closed or time.time() - _last_used.get(id(conn), 0) > IDLE_CHECK_SEC:
        try:
            _set_autocommit(conn)
            conn.cursor().execute('SELECT 1')
        except Exception:
            _get_pool().putconn(conn, close=True)
            conn = _getconn()
    _set_autocommit(conn)
    conn.cursor_factory = psycopg2.extras.RealDictCursor
    return conn


def _checkin(conn):
    _last_used[id(conn)] = time.time()
    try:
        if not conn.closed and conn.info.transaction_status != psycopg2.extensions.TRANSACTION_STATUS_IDLE:
            conn.rollback()
    except Exception:
        pass
    _get_pool().putconn(conn, close=bool(conn.closed))


def get_db():
    """1回のリクエストでは同じ接続を使い回す（DBとの往復を減らすため）"""
    if has_request_context():
        if 'db' not in g:
            g.db = _checkout()
        return g.db
    return _checkout()


def release_db(conn):
    if has_request_context() and g.get('db') is conn:
        return
    _checkin(conn)


@app.teardown_request
def _release_request_db(exc):
    conn = g.pop('db', None)
    if conn is not None:
        _checkin(conn)


@contextmanager
def transaction(conn):
    cur = conn.cursor()
    cur.execute('BEGIN')
    try:
        yield cur
    except BaseException:
        try:
            cur.execute('ROLLBACK')
        except Exception:
            pass
        raise
    else:
        cur.execute('COMMIT')


SCHEMA_VERSION = 12
SCHEMA_SQL = [
    "ALTER TABLE stock ADD COLUMN IF NOT EXISTS checked_at TIMESTAMPTZ",
    "ALTER TABLE stock ADD COLUMN IF NOT EXISTS version INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE stock ADD COLUMN IF NOT EXISTS changed_at TIMESTAMPTZ",
    "ALTER TABLE order_items ALTER COLUMN product_id DROP NOT NULL",
    # 使用数（ラベル）の記録。1回の入力ごとに1行。取り消しはマイナスの行として残す
    """CREATE TABLE IF NOT EXISTS usage_log (
        id SERIAL PRIMARY KEY, at TIMESTAMPTZ NOT NULL DEFAULT now(), store TEXT NOT NULL,
        product_id INTEGER NOT NULL REFERENCES products(id), qty DOUBLE PRECISION NOT NULL)""",
    "CREATE INDEX IF NOT EXISTS usage_log_store_product ON usage_log (store, product_id)",
    # この発注に含めた使用記録の最後のid（これより後の使用が次回の仮発注になる）
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS usage_upto INTEGER",
    # 同じ確定操作の二重送信で発注が2件できないようにする
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS request_key TEXT",
    "CREATE UNIQUE INDEX IF NOT EXISTS orders_request_key ON orders (request_key) WHERE request_key IS NOT NULL",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMPTZ",
    "CREATE INDEX IF NOT EXISTS order_items_product ON order_items (product_id)",
    # 作ったPDFを保存しておき、2回目からはすぐ返す（発注確定後に内容は変わらないため）
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pdf BYTEA",
    "ALTER TABLE orders ADD COLUMN IF NOT EXISTS pdf_ver TEXT",
    """CREATE TABLE IF NOT EXISTS error_log (
        id SERIAL PRIMARY KEY, at TIMESTAMPTZ DEFAULT now(), method TEXT, path TEXT, error TEXT)""",
    # 商品追加の自動やり直しで、同じ商品が2件できないようにする
    "ALTER TABLE products ADD COLUMN IF NOT EXISTS request_key TEXT",
    "CREATE UNIQUE INDEX IF NOT EXISTS products_request_key ON products (request_key) WHERE request_key IS NOT NULL",
    # 店舗ごとの基準在庫。行がない店舗は products.base_qty を使う（これまでの値がそのまま初期値）
    """CREATE TABLE IF NOT EXISTS product_base (
        product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
        store TEXT NOT NULL, base_qty DOUBLE PRECISION NOT NULL, PRIMARY KEY (product_id, store))""",
    # お客様に渡す価格表（在庫・発注の商品マスターとは別に持つ）
    """CREATE TABLE IF NOT EXISTS price_items (
        id SERIAL PRIMARY KEY, category TEXT NOT NULL DEFAULT '', name TEXT NOT NULL, spec TEXT,
        price INTEGER NOT NULL, note TEXT, sort_order INTEGER NOT NULL DEFAULT 0, is_active BOOLEAN NOT NULL DEFAULT true)""",
    # レジ（POS）の商品番号。同じ番号の商品は、再取り込みで更新される（tools/import_pos_goods.py）
    "ALTER TABLE price_items ADD COLUMN IF NOT EXISTS pos_id INTEGER",
    "CREATE UNIQUE INDEX IF NOT EXISTS price_items_pos_id ON price_items (pos_id) WHERE pos_id IS NOT NULL",
    # お客様ごとの注文（商品袋に貼る注文用紙）。商品名・規格・単価は、その時の内容を控えとして残す
    """CREATE TABLE IF NOT EXISTS customer_orders (
        id SERIAL PRIMARY KEY, customer TEXT NOT NULL, phone TEXT, memo TEXT, request_key TEXT UNIQUE,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now(), updated_at TIMESTAMPTZ NOT NULL DEFAULT now())""",
    """CREATE TABLE IF NOT EXISTS customer_order_items (
        id SERIAL PRIMARY KEY, order_id INTEGER NOT NULL REFERENCES customer_orders(id) ON DELETE CASCADE,
        price_id INTEGER REFERENCES price_items(id) ON DELETE SET NULL,
        name TEXT NOT NULL, spec TEXT, unit_price INTEGER NOT NULL, qty INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0)""",
    "CREATE INDEX IF NOT EXISTS customer_order_items_order ON customer_order_items (order_id)",
    # キャンペーン等の割引（注文ごとに％を入力）
    "ALTER TABLE customer_orders ADD COLUMN IF NOT EXISTS discount_pct NUMERIC(5,2) NOT NULL DEFAULT 0",
    # 割引率が商品ごとに違う注文（年末キャンペーン）。行ごとの割引率が空なら、注文全体の割引率を使う
    "ALTER TABLE customer_orders ADD COLUMN IF NOT EXISTS campaign BOOLEAN NOT NULL DEFAULT false",
    "ALTER TABLE customer_order_items ADD COLUMN IF NOT EXISTS discount_pct NUMERIC(6,2)",
    # お客様注文を仕入先へ発注したか（一覧で一目で分かるように）
    "ALTER TABLE customer_orders ADD COLUMN IF NOT EXISTS ordered_at TIMESTAMPTZ",
    # 商品ごとの発注済み（仕入先がまちまちなので、注文の中で商品ごとに印を付ける）
    "ALTER TABLE customer_order_items ADD COLUMN IF NOT EXISTS ordered BOOLEAN NOT NULL DEFAULT false",
    # 発注画面の下書き（入力途中の発注数）。店舗×仕入先ごとに1つ。確定すると消える
    """CREATE TABLE IF NOT EXISTS order_drafts (
        store TEXT NOT NULL, supplier TEXT NOT NULL, data JSONB NOT NULL,
        saved_at TIMESTAMPTZ NOT NULL DEFAULT now(), PRIMARY KEY (store, supplier))""",
]


def migrate():
    """起動時に、データベースの構造が最新かを1回の問い合わせで確かめ、古い時だけ変更する。
    （毎回の起動でALTERを実行すると、起動が遅くなり、デプロイ中に他の画面を一時的に止めることがある）"""
    db = psycopg2.connect(DATABASE_URL, connect_timeout=10)
    db.autocommit = True
    cur = db.cursor()
    try:
        cur.execute("CREATE TABLE IF NOT EXISTS schema_meta (id INTEGER PRIMARY KEY, version INTEGER NOT NULL)")
        cur.execute("SELECT version FROM schema_meta WHERE id=1")
        row = cur.fetchone()
        if row and row[0] >= SCHEMA_VERSION:
            return
        # 複数のプロセスが同時に起動しても、構造の変更は1つだけが行う
        cur.execute("SELECT pg_try_advisory_lock(7240001)")
        if not cur.fetchone()[0]:
            return
        try:
            cur.execute("SET lock_timeout = '5s'")
            for sql in SCHEMA_SQL:
                cur.execute(sql)
            cur.execute("""INSERT INTO schema_meta (id, version) VALUES (1, %s)
                           ON CONFLICT (id) DO UPDATE SET version = EXCLUDED.version""", (SCHEMA_VERSION,))
        finally:
            cur.execute("SELECT pg_advisory_unlock(7240001)")
    finally:
        db.close()


migrate()


def log_event(kind, path, text=''):
    """起動・遅い処理などを error_log に残す（失敗しても本来の処理には影響させない）"""
    try:
        conn = psycopg2.connect(DATABASE_URL, connect_timeout=5)
        conn.autocommit = True
        conn.cursor().execute("INSERT INTO error_log (method, path, error) VALUES (%s,%s,%s)",
                              (kind, path, text[-4000:]))
        conn.close()
    except Exception:
        pass


# サーバーが（デプロイ以外で）勝手に再起動していないかを後から調べられるよう、起動を記録する
threading.Thread(target=log_event, args=('BOOT', f'pid {os.getpid()}'), daemon=True).start()


@app.before_request
def _start_timer():
    g.t0 = time.time()


@app.after_request
def _log_slow(resp):
    t0 = g.get('t0')
    if t0 and time.time() - t0 > 6 and request.path not in ('/ping',):
        threading.Thread(target=log_event, args=('SLOW', request.full_path,
                         f'{time.time() - t0:.1f}s status={resp.status_code}'), daemon=True).start()
    return resp


PRODUCT_STOCK_SQL = """
    SELECT p.id, p.col_group, p.category, p.name, COALESCE(pb.base_qty, p.base_qty) AS base_qty,
           p.note, p.sort_order, p.supplier,
           COALESCE(s.current_qty, 0) AS current_qty, (s.product_id IS NOT NULL) AS has_stock,
           s.changed_at, s.checked_at,
           COALESCE(s.version, 0) AS version,
           ord.qty AS ordered_qty, ord.last AS ordered_at
    FROM products p
    LEFT JOIN stock s ON p.id = s.product_id AND s.store = %s
    LEFT JOIN product_base pb ON pb.product_id = p.id AND pb.store = %s
    -- 最後に数えた後に発注した数（在庫に足してあるが、まだ届いていないかもしれない分）
    LEFT JOIN LATERAL (
        SELECT SUM(oi.order_qty) AS qty, MAX(o.confirmed_at) AS last
        FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_id = p.id AND o.store = %s
          AND o.confirmed_at > COALESCE(s.checked_at, '-infinity'::timestamptz)
    ) ord ON true
    WHERE p.is_active=true {where}
    ORDER BY p.col_group, p.sort_order
"""


def products_with_stock(store='イノ', cat=None, supplier=None):
    where, params = '', [store, store, store]
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

    all_rows = products_with_stock(store)
    first_sort = {}
    for r in all_rows:
        if r['category'] and r['sort_order'] < first_sort.get(r['category'], float('inf')):
            first_sort[r['category']] = r['sort_order']
    cats = sorted(first_sort, key=first_sort.get)
    rows = all_rows
    stale_before = now_jst() - timedelta(days=STALE_DAYS)
    grouped = OrderedDict()
    counts = {'unchecked': 0, 'stale': 0, 'shortage': 0}
    for r in rows:
        r = dict(r)
        r['unchecked'] = r['checked_at'] is None
        r['stale'] = (not r['unchecked']) and r['checked_at'] < stale_before
        r['shortage'] = 0 if r['unchecked'] else max(float(r['base_qty'] or 0) - float(r['current_qty'] or 0), 0)
        counts['unchecked'] += r['unchecked']
        counts['stale'] += r['stale']
        counts['shortage'] += r['shortage'] > 0
        grouped.setdefault(r['category'], []).append(r)

    sups = [n for n in SUPPLIER_NAMES if any(r['supplier'] == n for r in all_rows)]
    cat_sups = {}
    for r in all_rows:
        cat_sups.setdefault(r['category'] or '', set()).add(r['supplier'] or '')
    cat_sups = {c: sorted(v) for c, v in cat_sups.items()}
    return render_template('inventory.html',
        sups=sups, cat_sups=cat_sups,
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
    """在庫数の保存。
    op=delta: ＋／－（使用・補充など）。同時操作でも合算。数が変わったら確認日時も更新する
    op=set:   数を直接入力。version不一致なら上書きせず409。数が変わったら確認日時も更新する
    op=check: 実際に数えて確認した。value があればその数で保存し、確認日時を記録する
    """
    data = request.get_json() or {}
    try:
        product_id = int(data['product_id'])
        op = data.get('op', 'set')
        value = data.get('value')
        value = None if value in (None, '') else float(value)
    except (KeyError, TypeError, ValueError):
        return jsonify(ok=False, error='入力が不正です'), 400
    store = data.get('store', 'イノ')
    if store not in STORES or op not in ('delta', 'set', 'check'):
        return jsonify(ok=False, error='入力が不正です'), 400
    if op in ('delta', 'set') and value is None:
        return jsonify(ok=False, error='数量がありません'), 400
    if op in ('set', 'check') and value is not None and value < 0:
        return jsonify(ok=False, error='0未満は保存できません'), 400

    now = now_jst()
    db = get_db()
    with transaction(db) as cur:
        cur.execute("SELECT 1 FROM products WHERE id=%s", (product_id,))
        if not cur.fetchone():
            return jsonify(ok=False, error='商品が見つかりません'), 404
        cur.execute("""
            INSERT INTO stock (product_id, store, current_qty, version)
            VALUES (%s, %s, 0, 0) ON CONFLICT (product_id, store) DO NOTHING
        """, (product_id, store))
        cur.execute("SELECT current_qty, version, changed_at, checked_at FROM stock "
                    "WHERE product_id=%s AND store=%s FOR UPDATE", (product_id, store))
        row = cur.fetchone()
        current = float(row['current_qty'] or 0)
        expected = data.get('version')
        if op != 'delta' and value is not None and expected is not None and int(expected) != row['version']:
            return jsonify(ok=False, conflict=True,
                           error='他の端末で先に更新されました。最新の数量を表示します。',
                           **stock_payload(row)), 409

        if op == 'delta':
            new_qty = max(current + value, 0)
        elif value is not None:
            new_qty = value
        else:
            new_qty = current
        changed = new_qty != current
        cur.execute("""
            UPDATE stock SET current_qty=%s, version=version+1,
                   changed_at=CASE WHEN %s THEN %s ELSE changed_at END,
                   checked_at=CASE WHEN %s THEN %s ELSE checked_at END,
                   updated_at=%s
            WHERE product_id=%s AND store=%s
            RETURNING current_qty, version, changed_at, checked_at
        """, (new_qty, changed, now, op == 'check' or changed, now, now.strftime('%Y-%m-%d %H:%M'),
              product_id, store))
        result = cur.fetchone()
    return jsonify(ok=True, **stock_payload(result))


@app.route('/api/stock/check_category', methods=['POST'])
def api_check_category():
    """棚（カテゴリ）の一括確認。数量が一度も入力されていない商品は対象外（勝手に在庫0にしない）"""
    data = request.get_json() or {}
    store = data.get('store', 'イノ')
    cat = data.get('category')
    if store not in STORES or cat is None:
        return jsonify(ok=False, error='入力が不正です'), 400
    now = now_jst()
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        UPDATE stock s SET checked_at=%s, version=s.version+1
        FROM products p
        WHERE s.product_id=p.id AND s.store=%s AND p.is_active=true AND p.category=%s
    """, (now, store, cat))
    n = cur.rowcount
    cur.execute("""
        SELECT COUNT(*) AS c FROM products p
        WHERE p.is_active=true AND p.category=%s
          AND NOT EXISTS (SELECT 1 FROM stock s WHERE s.product_id=p.id AND s.store=%s)
    """, (cat, store))
    skipped = cur.fetchone()['c']
    return jsonify(ok=True, count=n, skipped=skipped, checked_at=jst_fmt(now))


def add_to_stock(cur, store, amounts):
    """在庫数に加減する（発注確定で＋、使用入力で－）。確認日時は変えない。
    amounts: {product_id: 加える数}"""
    rows = [(pid, store, q) for pid, q in amounts.items() if q]
    if not rows:
        return
    now = now_jst()
    psycopg2.extras.execute_values(cur, """
        INSERT INTO stock (product_id, store, current_qty, version, changed_at, updated_at)
        VALUES %s
        ON CONFLICT (product_id, store) DO UPDATE
        SET current_qty = stock.current_qty + EXCLUDED.current_qty,
            version = stock.version + 1, changed_at = EXCLUDED.changed_at, updated_at = EXCLUDED.updated_at
    """, rows, template=f"(%s, %s, %s, 1, '{now.isoformat()}'::timestamptz, '{now.strftime('%Y-%m-%d %H:%M')}')")


# ── 使用入力（ラベル） ────────────────────────────────────────────────────────

PENDING_USAGE_SQL = """
    SELECT u.product_id,
           SUM(u.qty) AS pending,
           SUM(CASE WHEN u.at >= %s THEN u.qty ELSE 0 END) AS today
    FROM usage_log u
    JOIN products p ON p.id = u.product_id
    WHERE u.store = %s {where}
      AND u.id > COALESCE((SELECT MAX(o.usage_upto) FROM orders o
                           WHERE o.store = u.store AND o.supplier = p.supplier), 0)
    GROUP BY u.product_id
"""


def today_start():
    return now_jst().replace(hour=0, minute=0, second=0, microsecond=0)


def pending_usage(cur, store, supplier=None, product_id=None):
    """前回その仕入先に発注確定した後の使用数（＝仮発注数）と、今日入力した数を商品ごとに返す"""
    where, params = '', [today_start(), store]
    if supplier:
        where += ' AND p.supplier = %s'
        params.append(supplier)
    if product_id:
        where += ' AND u.product_id = %s'
        params.append(product_id)
    cur.execute(PENDING_USAGE_SQL.format(where=where), params)
    return {r['product_id']: {'pending': float(r['pending']), 'today': float(r['today'])}
            for r in cur.fetchall()}


@app.route('/usage')
def usage():
    store = request.args.get('store', 'イノ')
    if store not in STORES:
        store = 'イノ'
    supplier = request.args.get('supplier', '')
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        SELECT id, supplier, category, name, note FROM products
        WHERE is_active=true {where} ORDER BY col_group, sort_order, id
    """.format(where="AND supplier=%s" if supplier else ''), (supplier,) if supplier else ())
    rows = cur.fetchall()
    usage_map = pending_usage(cur, store, supplier or None)
    grouped = OrderedDict()
    for r in rows:
        u = usage_map.get(r['id'], {'pending': 0, 'today': 0})
        grouped.setdefault((r['supplier'], r['category']), []).append({**r, **u})
    today_total = sum(u['today'] for u in usage_map.values())
    return render_template('usage.html', store=store, supplier=supplier, grouped=grouped,
                           today_total=today_total)


@app.route('/api/usage', methods=['POST'])
def api_usage():
    data = request.get_json() or {}
    try:
        product_id = int(data['product_id'])
        delta = float(data['delta'])
    except (KeyError, TypeError, ValueError):
        return jsonify(ok=False, error='入力が不正です'), 400
    store = data.get('store', 'イノ')
    if store not in STORES or delta == 0:
        return jsonify(ok=False, error='入力が不正です'), 400
    db = get_db()
    with transaction(db) as cur:
        # 同じ商品への同時入力で数がずれないよう、商品の行をロックしてから計算する
        cur.execute("SELECT id FROM products WHERE id=%s FOR UPDATE", (product_id,))
        if not cur.fetchone():
            return jsonify(ok=False, error='商品が見つかりません'), 404
        cur_u = pending_usage(cur, store, product_id=product_id).get(product_id, {'pending': 0, 'today': 0})
        if cur_u['pending'] + delta < 0:
            return jsonify(ok=False, error='0より少なくはできません', **cur_u), 400
        cur.execute("INSERT INTO usage_log (store, product_id, qty) VALUES (%s,%s,%s)",
                    (store, product_id, delta))
        add_to_stock(cur, store, {product_id: -delta})
        cur_u = pending_usage(cur, store, product_id=product_id).get(product_id, {'pending': 0, 'today': 0})
    return jsonify(ok=True, **cur_u)


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

    now = now_jst()
    db = get_db()
    cur = db.cursor()
    # この画面に出した使用数の範囲（これより後に入力された使用は次回の発注に回す）
    cur.execute("SELECT COALESCE(MAX(id), 0) AS m FROM usage_log")
    usage_upto = cur.fetchone()['m']
    usage_map = pending_usage(cur, store, supplier)
    cur.execute("""SELECT COUNT(*) AS c FROM orders WHERE store=%s AND supplier=%s
                   AND order_month=%s AND order_day=%s""", (store, supplier, now.month, now.day))
    ordered_today = 0 if supplier in NO_HISTORY_SUPPLIERS else cur.fetchone()['c']
    cur.execute("SELECT data, saved_at FROM order_drafts WHERE store=%s AND supplier=%s", (store, supplier))
    draft_row = cur.fetchone()
    draft = (draft_row['data'] if draft_row else None) or {}
    draft_items = draft.get('items', {})
    grouped = OrderedDict()
    counts = {'unchecked': 0, 'stale': 0, 'usage': 0}
    for r in products_with_stock(store, supplier=supplier):
        r = dict(r)
        r['usage'] = usage_map.get(r['id'], {}).get('pending', 0)
        counts['usage'] += r['usage'] > 0
        # 保存した下書きがあればその数。下書きの後に増えた使用数は上乗せする
        default = math.ceil(r['usage'])
        saved = draft_items.get(str(r['id']))
        r['qty0'] = default if not saved else max(
            0, int(saved[0]) + math.ceil(max(0.0, r['usage'] - float(saved[1]))))
        r['unchecked'] = r['checked_at'] is None
        r['days'] = None if r['unchecked'] else (now - r['checked_at']).days
        r['stale'] = not r['unchecked'] and r['days'] >= STALE_DAYS
        # 数えた記録がない商品は在庫数が分からないので、不足数は出さない（在庫0扱いで発注しないため）
        r['shortage'] = 0 if r['unchecked'] else max(float(r['base_qty'] or 0) - float(r['current_qty'] or 0), 0)
        counts['unchecked'] += r['unchecked']
        counts['stale'] += r['stale']
        grouped.setdefault(r['category'], []).append(r)

    return render_template('order.html', store=store, grouped=grouped,
                           cats=list(grouped.keys()), supplier=supplier,
                           sup_info=supplier_info(supplier), counts=counts, stale_days=STALE_DAYS,
                           usage_upto=usage_upto, ordered_today=ordered_today,
                           request_key=uuid.uuid4().hex,
                           draft_custom=draft.get('custom', []),
                           draft_saved=jst_fmt(draft_row['saved_at']) if draft_row else '')


@app.route('/api/order_draft', methods=['POST'])
def api_order_draft():
    """発注画面の入力途中の数を保存する（自動保存・保存ボタン共通）"""
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify(ok=False, error='入力が不正です'), 400
    store, supplier = data.get('store'), data.get('supplier')
    if store not in STORES or supplier not in SUPPLIER_NAMES:
        return jsonify(ok=False, error='入力が不正です'), 400
    try:
        items = {str(int(k)): [int(v[0]), float(v[1])] for k, v in (data.get('items') or {}).items()}
        custom = [{'name': str(c.get('name', ''))[:200], 'spec': str(c.get('spec', ''))[:100],
                   'qty': max(0, int(c.get('qty') or 0))} for c in (data.get('custom') or [])][:100]
    except (ValueError, TypeError, AttributeError, IndexError):
        return jsonify(ok=False, error='入力が不正です'), 400
    db = get_db()
    cur = db.cursor()
    key = data.get('request_key')
    if key:
        # この画面ですでに発注を確定していたら、下書きは作り直さない
        cur.execute("SELECT 1 FROM orders WHERE request_key=%s", (key,))
        if cur.fetchone():
            return jsonify(ok=True, skipped=True)
    cur.execute("""
        INSERT INTO order_drafts (store, supplier, data, saved_at) VALUES (%s, %s, %s, now())
        ON CONFLICT (store, supplier) DO UPDATE SET data = EXCLUDED.data, saved_at = now()
        RETURNING saved_at""", (store, supplier, psycopg2.extras.Json({'items': items, 'custom': custom})))
    return jsonify(ok=True, saved_at=jst_fmt(cur.fetchone()['saved_at']))


@app.route('/api/order_draft/clear', methods=['POST'])
def api_order_draft_clear():
    data = request.get_json(silent=True) or {}
    db = get_db()
    db.cursor().execute("DELETE FROM order_drafts WHERE store=%s AND supplier=%s",
                        (data.get('store'), data.get('supplier')))
    return jsonify(ok=True)


@app.route('/order/save', methods=['POST'])
def order_save():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify(ok=False, error='入力が不正です'), 400
    store    = data.get('store', 'イノ')
    supplier = data.get('supplier', 'ワールド美容')
    items    = data.get('items', [])
    request_key = data.get('request_key') or None
    try:
        month = int(data['month']) if data.get('month') not in (None, '') else None
        day   = int(data['day']) if data.get('day') not in (None, '') else None
        usage_upto = int(data['usage_upto']) if data.get('usage_upto') is not None else None
        if not isinstance(items, list):
            raise ValueError
        clean = []
        for it in items:
            qty = float(it.get('order_qty') or 0)
            if qty <= 0:
                continue
            clean.append({**it, 'order_qty': qty,
                          'product_id': int(it['product_id']) if it.get('product_id') else None})
        items = clean
    except (ValueError, TypeError, AttributeError):
        return jsonify(ok=False, error='入力が不正です'), 400
    if store not in STORES or supplier not in SUPPLIER_NAMES:
        return jsonify(ok=False, error='店舗または仕入先が不正です'), 400
    if items:
        db0 = get_db()
        cur0 = db0.cursor()
        ids = [i['product_id'] for i in items if i['product_id']]
        cur0.execute("SELECT id FROM products WHERE id = ANY(%s)", (ids,))
        if len(cur0.fetchall()) != len(set(ids)):
            return jsonify(ok=False, error='存在しない商品が含まれています。画面を読み込み直してください'), 400

    db = get_db()
    with transaction(db) as cur:
        # 同じ画面からの二重送信（連打・通信のやり直し）は、最初の1件をそのまま返す
        if request_key:
            cur.execute("SELECT id FROM orders WHERE request_key=%s", (request_key,))
            done = cur.fetchone()
            if done:
                return jsonify(ok=True, order_id=done['id'], repeated=True)
        # 同じ店舗・仕入先・発注日の発注が既にあれば、確認なしでは確定しない
        if not data.get('allow_duplicate') and supplier not in NO_HISTORY_SUPPLIERS:
            cur.execute("""SELECT COUNT(*) AS c FROM orders WHERE store=%s AND supplier=%s
                           AND order_month=%s AND order_day=%s""", (store, supplier, month, day))
            if cur.fetchone()['c']:
                return jsonify(ok=False, duplicate=True,
                               error=f'{month}月{day}日の {store}・{supplier} の発注はすでに確定済みです。'), 409
        order_id = _insert_order(cur, store, supplier, month, day, items, request_key, usage_upto)
        cur.execute("DELETE FROM order_drafts WHERE store=%s AND supplier=%s", (store, supplier))
    threading.Thread(target=warm_pdf, args=(order_id,), daemon=True).start()
    return jsonify(ok=True, order_id=order_id)


def _insert_order(cur, store, supplier, month, day, items, request_key=None, usage_upto=None):
    cur.execute("""
        INSERT INTO orders (store, supplier, order_month, order_day, status, created_at, request_key, usage_upto,
                            confirmed_at)
        VALUES (%s, %s, %s, %s, 'confirmed', %s, %s, %s, now())
        RETURNING id
    """, (store, supplier, month, day, now_jst().strftime('%Y-%m-%d %H:%M'), request_key,
          int(usage_upto) if usage_upto is not None else None))
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
    # 入荷処理はしない運用なので、発注した時点で在庫に足す（届く予定の分も在庫として扱う）
    amounts = {}
    for r in rows:
        if r[1]:
            amounts[r[1]] = amounts.get(r[1], 0) + r[4]
    add_to_stock(cur, store, amounts)
    return order_id


CUSTOM_CAT = 'リスト外の品（手入力）'


def load_order(order_id, conn=None):
    """発注書1件分を、カテゴリ順のブロック [(カテゴリ名, [{name, note, qty}])] として返す"""
    db = conn or get_db()
    cur = db.cursor()
    cur.execute("""
        SELECT o.id, o.store, o.supplier, o.order_month, o.order_day, o.created_at,
               (SELECT c.contact_name FROM supplier_contacts c WHERE c.supplier_name = o.supplier
                ORDER BY c.sort_order, c.id LIMIT 1) AS contact_name
        FROM orders o WHERE o.id=%s
    """, (order_id,))
    order = cur.fetchone()
    if not order:
        if conn is None:
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
    if conn is None:
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
                           sup_info=order_sup_info(order),
                           item_count=item_count, total_qty=total_qty)


def order_sup_info(order):
    name = order.get('supplier') or 'ワールド美容'
    contact = order.get('contact_name')
    return {'name': name, 'contacts': [{'name': contact}] if contact else []}


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


# ── お客様用の価格表 ─────────────────────────────────────────────────────────

PRICE_TITLE = '商品価格表'
PRICE_NOTE = '表示の価格は税込です。'
PRICE_SHOP = '(有)イノ　TEL 072-999-6571'


PRICE_FIRST_CATEGORIES = ['コアミー シャンプー', 'コアミー ケア用品']


def load_prices():
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT id, category, name, spec, price, note FROM price_items WHERE is_active ORDER BY sort_order, id")
    groups = OrderedDict()
    for r in cur.fetchall():
        r['kind'] = price_kind(r['category'], r['name'])
        groups.setdefault(r['category'] or 'その他', []).append(r)
    # よく使うカテゴリを先頭に（残りは今までの並び）
    first = [c for c in PRICE_FIRST_CATEGORIES if c in groups]
    return OrderedDict([(c, groups[c]) for c in first] + [(c, v) for c, v in groups.items() if c not in first])


@app.route('/prices')
def prices():
    groups = load_prices()
    editing = None
    customs = []
    qty = {}
    oid = request.args.get('order', type=int)
    if oid:
        # お客様の注文を直す時は、選んだ数量とお客様名を入れた状態で開く
        cur = get_db().cursor()
        cur.execute("SELECT id, customer, phone, memo, discount_pct, campaign FROM customer_orders WHERE id=%s", (oid,))
        editing = cur.fetchone()
        if editing:
            editing['discount_pct'] = pct_fmt(editing['discount_pct'])
            cur.execute("SELECT name, unit_price, qty FROM customer_order_items WHERE order_id=%s AND price_id IS NULL ORDER BY sort_order, id", (oid,))
            customs = [dict(r) for r in cur.fetchall()]
            cur.execute("SELECT price_id, qty FROM customer_order_items WHERE order_id=%s AND price_id IS NOT NULL", (oid,))
            qty = {r['price_id']: r['qty'] for r in cur.fetchall()}
    return render_template('prices.html', groups=groups, total=sum(len(v) for v in groups.values()),
                           note=PRICE_NOTE, title=PRICE_TITLE, editing=editing, qty=qty, customs=customs)


# ── お客様ごとの注文（商品袋に貼る注文用紙） ───────────────────────────────────

DISCOUNT_ROUND = 10   # 割引後の支払額は、この円単位で繰り上げる（画面の計算 prices.html と同じにする）


def calc_discount_lines(pairs):
    """(割引額, 支払額)。pairs は (金額, 割引率) の並び。
    レジ（POS）と同じく、商品の行ごとに「金額×(100-割引率)%」を10円単位に繰り上げて足す"""
    subtotal = sum(a for a, _ in pairs)
    unit = 10000 * DISCOUNT_ROUND
    payable = 0
    for a, pct in pairs:
        p100 = int(round(float(pct or 0) * 100))
        payable += a if p100 <= 0 else min(a, (a * (10000 - p100) + unit - 1) // unit * DISCOUNT_ROUND)
    return subtotal - payable, payable


def calc_discount(amounts, pct):
    return calc_discount_lines([(a, pct) for a in amounts])


# ── 年末キャンペーン（割引率が商品ごとに違う） ──
# 割引率は、全ての商品の税込合計で決まる。画面の計算 prices.html と同じにする
CAMPAIGN_TIERS = [(15000, 8), (20000, 10), (30000, 12), (40000, 13), (80000, 14)]
CAMPAIGN_TOP = 16


def campaign_tier(normal_total):
    if normal_total <= 0:
        return 0
    for limit, pct in CAMPAIGN_TIERS:
        if normal_total <= limit:
            return pct
    return CAMPAIGN_TOP


def price_kind(category, name):
    """n=通常 a=アルマダ(さらに5%) f5=最大5% f10=最大10%"""
    name = name or ''
    if 'ステラボーテ' in name:
        return 'f10'
    if category == 'リファ' or 'リファ' in name or 'オーガニックノート' in name:
        return 'f5'
    if category == 'アルマダ' or 'アルマダ' in name or 'エグタム' in name or 'エグータム' in name:
        return 'a'
    return 'n'


def kind_rate(kind, tier):
    if kind == 'a':
        return round(100 - (100 - tier) * 0.95, 2)
    return min(tier, {'f5': 5, 'f10': 10}[kind]) if kind in ('f5', 'f10') else tier   # リファ等は「最大5%／10%」


def pct_fmt(pct):
    return ('%g' % float(pct or 0))


app.add_template_filter(pct_fmt, 'pctf')


def load_customer_order(cur, oid):
    cur.execute("SELECT * FROM customer_orders WHERE id=%s", (oid,))
    order = cur.fetchone()
    if not order:
        abort(404)
    cur.execute("""SELECT i.id, i.ordered, i.name, i.spec, i.unit_price, i.qty, i.unit_price * i.qty AS amount,
                          COALESCE(i.discount_pct, %s) AS pct, p.category
                   FROM customer_order_items i LEFT JOIN price_items p ON p.id = i.price_id
                   WHERE i.order_id=%s ORDER BY i.sort_order, i.id""", (order['discount_pct'], oid))
    items = cur.fetchall()
    return order, items, sum(i['amount'] for i in items)


def order_discount(order, items):
    """(割引額, 支払額, 割引の表示)。割引率が行ごとに違う時は「割引」だけにして、率は各行に出す"""
    disc, pay = calc_discount_lines([(i['amount'], i['pct']) for i in items])
    for i in items:   # 行ごとの割引の説明（例: 「16%引き＋アルマダ特別割引（−¥2,000）」）
        i['label'] = ''
        if order['campaign']:
            d = calc_discount_lines([(i['amount'], i['pct'])])[0]
            if d > 0:
                tier = pct_fmt(order['discount_pct'])
                if price_kind(i['category'], i['name']) == 'a':
                    head = (f"{tier}%引き＋" if float(order['discount_pct'] or 0) > 0 else '') + 'アルマダ特別割引'
                else:
                    head = f"{pct_fmt(i['pct'])}%引き"
                i['label'] = f"{head}（－{yen_fmt(d)}）"
    rates = {pct_fmt(i['pct']) for i in items if float(i['pct'] or 0) > 0}
    label = f"{rates.pop()}% 割引" if len(rates) == 1 else "割引（商品ごとの割引率）"
    return disc, pay, label


@app.route('/customer-orders')
def customer_orders():
    cur = get_db().cursor()
    cur.execute("""
        SELECT o.id, o.customer, o.phone, o.created_at, o.discount_pct, COUNT(i.id) AS n, COALESCE(SUM(i.ordered::int), 0) AS n_ordered, COALESCE(SUM(i.unit_price * i.qty), 0) AS total
        FROM customer_orders o LEFT JOIN customer_order_items i ON i.order_id = o.id
        GROUP BY o.id ORDER BY o.created_at DESC LIMIT 300""")
    orders = cur.fetchall()
    lines = {}
    cur.execute("SELECT order_id, unit_price * qty AS a, discount_pct FROM customer_order_items WHERE order_id = ANY(%s)", ([o['id'] for o in orders],))
    pct_of = {o['id']: o['discount_pct'] for o in orders}
    for r in cur.fetchall():
        lines.setdefault(r['order_id'], []).append((r['a'], r['discount_pct'] if r['discount_pct'] is not None else pct_of[r['order_id']]))
    for o in orders:
        o['total'] = calc_discount_lines(lines.get(o['id'], []))[1]
    return render_template('customer_orders.html', orders=orders)


@app.route('/customer-orders/save', methods=['POST'])
def customer_order_save():
    data = request.get_json(silent=True)
    if not isinstance(data, dict):
        return jsonify(ok=False, error='入力が不正です'), 400
    customer = (nfkc(str(data.get('customer') or '')) or '')[:60]
    phone = (nfkc(str(data.get('phone') or '')) or '')[:30]
    memo = (nfkc(str(data.get('memo') or '')) or '')[:200]
    if not customer:
        return jsonify(ok=False, error='お客様名を入力してください'), 400
    try:
        items = [(int(i['price_id']), int(i['qty'])) for i in (data.get('items') or []) if int(i['qty']) > 0]
        oid = int(data['id']) if data.get('id') else None
        customs = []
        for c in (data.get('customs') or []):
            cq, cp, cn = int(c['qty']), int(c['price']), (nfkc(str(c.get('name') or '')) or '')[:80]
            if cq <= 0:
                continue
            if not cn or cp < 0 or cp > 9999999 or cq > 9999:
                return jsonify(ok=False, error='「その他」の品名・値段・数量を確認してください'), 400
            customs.append((cn, cp, cq))
        campaign = bool(data.get('campaign'))
        disc = round(float(data.get('discount_pct') or 0), 2)
        if not 0 <= disc <= 100:
            return jsonify(ok=False, error='割引は0〜100%で入力してください'), 400
    except (KeyError, TypeError, ValueError, OverflowError):
        return jsonify(ok=False, error='入力が不正です'), 400
    if not items and not customs:
        return jsonify(ok=False, error='商品を選んでください'), 400
    if len(items) + len(customs) > 300 or any(q > 9999 for _, q in items):
        return jsonify(ok=False, error='数量または品数が多すぎます'), 400
    request_key = data.get('request_key') or None
    db = get_db()
    with transaction(db) as cur:
        ids = [i for i, _ in items]
        cur.execute("SELECT id, category, name, spec, price FROM price_items WHERE id = ANY(%s)", (ids,))
        snap = {r['id']: r for r in cur.fetchall()}
        if len(snap) != len(set(ids)):
            return jsonify(ok=False, error='価格表にない商品が含まれています。画面を読み込み直してください'), 400
        was_ordered = set()
        if oid:
            cur.execute("""UPDATE customer_orders SET customer=%s, phone=%s, memo=%s, discount_pct=%s, campaign=%s, updated_at=now()
                           WHERE id=%s RETURNING id""", (customer, phone, memo, disc, campaign, oid))
            if not cur.fetchone():
                return jsonify(ok=False, error='注文が見つかりません'), 404
            # 直して保存しても、発注済みの印は残す（商品と、手書きの品名で照らし合わせる）
            cur.execute("SELECT price_id, name FROM customer_order_items WHERE order_id=%s AND ordered", (oid,))
            was_ordered = {(r['price_id'], r['name'] if r['price_id'] is None else None) for r in cur.fetchall()}
            cur.execute("DELETE FROM customer_order_items WHERE order_id=%s", (oid,))
        else:
            if request_key:
                cur.execute("SELECT id FROM customer_orders WHERE request_key=%s", (request_key,))
                done = cur.fetchone()
                if done:
                    return jsonify(ok=True, id=done['id'], repeated=True)
            cur.execute("INSERT INTO customer_orders (customer, phone, memo, discount_pct, campaign, request_key) VALUES (%s,%s,%s,%s,%s,%s) RETURNING id",
                        (customer, phone, memo, disc, campaign, request_key))
            oid = cur.fetchone()['id']
        # 価格表の並び順で、その時の商品名・規格・単価を控えとして残す
        order_of = {r['id']: n for n, r in enumerate(sorted(snap.values(), key=lambda r: r['id']))}
        # 「その他」（価格表にない商品）を先頭に、続けて価格表の商品
        # キャンペーンの時は、商品の種類ごとに行の割引率を決める（「通常」の行は、入力された割引率）
        def line_pct(kind):
            return kind_rate(kind, disc) if campaign else None
        rows_ = [(oid, None, cn, None, cp, cq, n, line_pct('n'), (None, cn) in was_ordered) for n, (cn, cp, cq) in enumerate(customs)]
        rows_ += [(oid, pid, snap[pid]['name'], snap[pid]['spec'], snap[pid]['price'], q, len(customs) + n,
                   line_pct(price_kind(snap[pid]['category'], snap[pid]['name'])), (pid, None) in was_ordered)
                  for n, (pid, q) in enumerate(items)]
        psycopg2.extras.execute_values(cur, """
            INSERT INTO customer_order_items (order_id, price_id, name, spec, unit_price, qty, sort_order, discount_pct, ordered) VALUES %s
        """, rows_)
    return jsonify(ok=True, id=oid)


@app.route('/customer-orders/<int:oid>')
def customer_order_view(oid):
    order, items, total = load_customer_order(get_db().cursor(), oid)
    disc, payable, label = order_discount(order, items)
    return render_template('customer_order.html', order=order, items=items, subtotal=total, disc=disc, total=payable,
                           pct=label, note=PRICE_NOTE)


@app.route('/customer-orders/<int:oid>/ordered', methods=['POST'])
def customer_order_ordered(oid):
    """注文の全商品を、発注済みにする／未発注に戻す"""
    on = bool((request.get_json(silent=True) or {}).get('ordered'))
    cur = get_db().cursor()
    cur.execute("UPDATE customer_order_items SET ordered=%s WHERE order_id=%s", (on, oid))
    return jsonify(ok=True, ordered=on)


@app.route('/customer-orders/<int:oid>/items/<int:iid>/ordered', methods=['POST'])
def customer_order_item_ordered(oid, iid):
    """商品1つの発注済みの印を付ける／外す"""
    on = bool((request.get_json(silent=True) or {}).get('ordered'))
    cur = get_db().cursor()
    cur.execute("UPDATE customer_order_items SET ordered=%s WHERE id=%s AND order_id=%s RETURNING id", (on, iid, oid))
    if not cur.fetchone():
        return jsonify(ok=False, error='商品が見つかりません'), 404
    return jsonify(ok=True, ordered=on)


@app.route('/customer-orders/unordered/pdf')
def customer_orders_unordered_pdf():
    """まだ発注していない商品の一覧（発注の作業用）。?order=ID でその注文だけ。商品ごとに数量を合計し、お客様名を添える"""
    oid = request.args.get('order', type=int)
    cur = get_db().cursor()
    cur.execute("""SELECT i.price_id, i.name, i.spec, i.qty, o.customer, p.sort_order
                   FROM customer_order_items i
                   JOIN customer_orders o ON o.id = i.order_id
                   LEFT JOIN price_items p ON p.id = i.price_id
                   WHERE NOT i.ordered AND (%s::int IS NULL OR o.id = %s)
                   ORDER BY p.sort_order NULLS LAST, i.name, o.created_at""", (oid, oid))
    groups = OrderedDict()
    for r in cur.fetchall():
        g = groups.setdefault((r['price_id'], None if r['price_id'] else r['name']),
                              {'name': r['name'], 'spec': r['spec'], 'qty': 0, 'who': []})
        g['qty'] += r['qty']
        g['who'].append((sama_fmt(r['customer']), r['qty']))
    title = '未発注の商品'
    if oid:
        cur.execute("SELECT customer FROM customer_orders WHERE id=%s", (oid,))
        o = cur.fetchone()
        if not o:
            abort(404)
        title += f"　{sama_fmt(o['customer'])}"
    pdf = OrderPDF(orientation='P', unit='mm', format='A4')
    font_path = os.path.join(os.path.dirname(__file__), 'static', 'NotoSansJP-Regular.ttf')
    if os.path.exists(font_path):
        pdf.add_font('NotoSans', '', font_path)
        pdf.font_name = 'NotoSans'
    f = pdf.font_name
    pdf.title_text = title
    pdf.set_margins(12, 12, 12)
    pdf.set_auto_page_break(auto=True, margin=16)
    pdf.alias_nb_pages()
    pdf.text_mode = 'FILL_STROKE'
    pdf.set_line_width(0.25)
    pdf.add_page()
    pdf.set_font(f, '', 18)
    pdf.cell(0, 10, title, new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 10)
    pdf.cell(0, 6, f"{jst_fmt(now_jst())} 作成　{len(groups)} 品目", new_x='LMARGIN', new_y='NEXT')
    pdf.ln(2)
    if not groups:
        pdf.set_font(f, '', 13)
        pdf.cell(0, 10, '未発注の商品はありません。', new_x='LMARGIN', new_y='NEXT')
    W = pdf.w - 24
    for g in groups.values():
        need = 8 + 6 * len(g['who']) + 2
        if pdf.get_y() + need > pdf.h - 16:
            pdf.add_page()
        y = pdf.get_y()
        pdf.rect(pdf.l_margin, y + 1.5, 4.5, 4.5)   # 発注したらチェックを入れる四角
        pdf.set_xy(pdf.l_margin + 7, y)
        pdf.set_font(f, '', 12)
        pdf.cell(W - 7 - 24, 8, g['name'] + (f"　{g['spec']}" if g['spec'] else ''))
        pdf.set_font(f, '', 14)
        pdf.cell(24, 8, f"× {g['qty']}", align='R', new_x='LMARGIN', new_y='NEXT')
        pdf.set_font(f, '', 10)
        for who, q in g['who']:
            pdf.set_x(pdf.l_margin + 7)
            pdf.cell(W - 7 - 24, 6, who)
            pdf.cell(24, 6, f"{q}", align='R', new_x='LMARGIN', new_y='NEXT')
        pdf.set_draw_color(190, 190, 190)
        pdf.line(pdf.l_margin, pdf.get_y() + 1, pdf.w - pdf.r_margin, pdf.get_y() + 1)
        pdf.set_draw_color(0, 0, 0)
        pdf.ln(3)
    resp = make_response(bytes(pdf.output()))
    resp.headers['Content-Type'] = 'application/pdf'
    fn = urllib.parse.quote('未発注の商品.pdf')
    resp.headers['Content-Disposition'] = f"attachment; filename*=UTF-8''{fn}"
    return resp


@app.route('/customer-orders/<int:oid>/delete', methods=['POST'])
def customer_order_delete(oid):
    get_db().cursor().execute("DELETE FROM customer_orders WHERE id=%s", (oid,))
    return jsonify(ok=True)


@app.route('/customer-orders/<int:oid>/pdf')
def customer_order_pdf(oid):
    order, items, subtotal = load_customer_order(get_db().cursor(), oid)
    disc, total, disc_label = order_discount(order, items)
    pdf = OrderPDF(orientation='P', unit='mm', format='A5')
    font_path = os.path.join(os.path.dirname(__file__), 'static', 'NotoSansJP-Regular.ttf')
    if os.path.exists(font_path):
        pdf.add_font('NotoSans', '', font_path)
        pdf.font_name = 'NotoSans'
    f = pdf.font_name
    pdf.title_text = f"ご注文用紙　{sama_fmt(order['customer'])}"
    pdf.set_margins(8, 8, 8)
    pdf.set_auto_page_break(auto=False)
    pdf.alias_nb_pages()
    pdf.text_mode = 'FILL_STROKE'   # 同梱フォントが極細のため、輪郭線で太く見せる
    pdf.set_line_width(0.25)
    pdf.add_page()
    W = pdf.w - 16
    name_w, qty_w = W - 14 - 32, 14
    amt_w = 32
    bottom = pdf.h - 14

    pdf.set_font(f, '', 11)
    pdf.cell(0, 6, f"ご注文用紙　受付 {jst_fmt(order['created_at'])}", new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 22)
    pdf.multi_cell(0, 11, sama_fmt(order['customer']), new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 11)
    if order['phone']:
        pdf.cell(0, 6, f"TEL {order['phone']}", new_x='LMARGIN', new_y='NEXT')
    pdf.cell(0, 6, PRICE_SHOP, new_x='LMARGIN', new_y='NEXT')
    pdf.ln(2)

    def head():
        pdf.set_font(f, '', 10)
        pdf.set_fill_color(225, 225, 225)
        pdf.cell(name_w, 7, ' 商品名', fill=True)
        pdf.cell(qty_w, 7, '数量', fill=True, align='C')
        pdf.cell(amt_w, 7, '金額 ', fill=True, align='R', new_x='LMARGIN', new_y='NEXT')
    head()
    for it in items:
        pdf.set_font(f, '', 11)
        lines = pdf.multi_cell(name_w - 2, 5.6, it['name'], dry_run=True, output='LINES')
        sub = (f"{it['spec']}　" if it['spec'] else '') + f"{yen_fmt(it['unit_price'])}" + (f"　{it['label']}" if it['label'] else '')
        h = len(lines) * 5.6 + 5 + 2
        if pdf.get_y() + h > bottom - 16:
            pdf.add_page()
            head()
        y = pdf.get_y()
        pdf.set_xy(pdf.l_margin + 1, y + 1)
        pdf.multi_cell(name_w - 2, 5.6, it['name'])
        pdf.set_font(f, '', 8.5)
        pdf.set_x(pdf.l_margin + 1)
        pdf.cell(name_w - 2, 4.5, sub)
        pdf.set_font(f, '', 13)
        pdf.set_xy(pdf.l_margin + name_w, y)
        pdf.cell(qty_w, h, str(it['qty']), align='C')
        pdf.cell(amt_w - 1, h, yen_fmt(it['amount']), align='R')
        # 区切り線だけ灰色にして、すぐ黒に戻す（文字の輪郭も同じ「線の色」を使うため、戻さないと文字が薄くなる）
        pdf.set_draw_color(190, 190, 190)
        pdf.line(pdf.l_margin, y + h, pdf.w - pdf.r_margin, y + h)
        pdf.set_draw_color(0, 0, 0)
        pdf.set_xy(pdf.l_margin, y + h)
    pdf.ln(2)
    if disc:
        pdf.set_font(f, '', 12)
        pdf.cell(W - 62, 8, f"小計 {len(items)} 品")
        pdf.cell(62, 8, yen_fmt(subtotal), align='R', new_x='LMARGIN', new_y='NEXT')
        pdf.cell(W - 62, 8, disc_label)
        pdf.cell(62, 8, f"－{yen_fmt(disc)}", align='R', new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 12)
    pdf.cell(W - 62, 11, ("" if disc else f"合計 {len(items)} 品") + ("　" + PRICE_NOTE.rstrip('。') if PRICE_NOTE else ''))
    pdf.set_font(f, '', 18)
    pdf.cell(62, 11, f"合計 {yen_fmt(total)}", align='R', new_x='LMARGIN', new_y='NEXT')
    if order['memo']:
        pdf.ln(2)
        pdf.set_font(f, '', 10)
        pdf.multi_cell(0, 5.5, f"メモ: {order['memo']}")

    response = make_response(bytes(pdf.output()))
    response.headers['Content-Type'] = 'application/pdf'
    fn = urllib.parse.quote(f"注文用紙_{sama_fmt(order['customer']).replace(' ', '')}.pdf")
    response.headers['Content-Disposition'] = f"attachment; filename*=UTF-8''{fn}"
    return response


_price_pdf_cache = {}


@app.route('/prices/pdf')
def prices_pdf():
    groups = load_prices()
    now = now_jst()
    # 値段・商品・日付が変わらない間は、作ったPDFを使い回す（433品で作成に約4秒かかるため）
    sig = hashlib.md5(repr(([(c, [(i['id'], i['name'], i['spec'], i['price'], i['note']) for i in v]) for c, v in groups.items()],
                            now.date().isoformat())).encode()).hexdigest()
    fn = urllib.parse.quote(f"{PRICE_TITLE}_{now.month}月{now.day}日.pdf")
    if _price_pdf_cache.get('sig') == sig:
        resp = make_response(_price_pdf_cache['data'])
        resp.headers['Content-Type'] = 'application/pdf'
        resp.headers['Content-Disposition'] = f"attachment; filename*=UTF-8''{fn}"
        return resp
    pdf = OrderPDF(orientation='P', unit='mm', format='A4')
    font_path = os.path.join(os.path.dirname(__file__), 'static', 'NotoSansJP-Regular.ttf')
    if os.path.exists(font_path):
        pdf.add_font('NotoSans', '', font_path)
        pdf.font_name = 'NotoSans'
    f = pdf.font_name
    pdf.title_text = f"{PRICE_TITLE}　{PRICE_SHOP}"
    pdf.set_margins(15, 12, 15)
    pdf.set_auto_page_break(auto=False)
    pdf.alias_nb_pages()
    pdf.text_mode = 'FILL_STROKE'   # 同梱フォントが極細のため、輪郭線で太く見せる
    pdf.set_line_width(0.25)
    pdf.add_page()

    pdf.set_font(f, '', 24)
    pdf.cell(0, 13, PRICE_TITLE, new_x='LMARGIN', new_y='NEXT')
    pdf.set_font(f, '', 11)
    pdf.cell(0, 6, f"{now.year}年{now.month}月{now.day}日現在　{PRICE_SHOP}", new_x='LMARGIN', new_y='NEXT')
    pdf.cell(0, 6, PRICE_NOTE, new_x='LMARGIN', new_y='NEXT')
    pdf.ln(3)

    name_w, spec_w, price_w = 100, 42, 38
    line_h, FS = 6.5, 12
    bottom = pdf.h - 16

    def cat_bar(text):
        pdf.set_font(f, '', 12)
        pdf.set_fill_color(215, 215, 215)
        pdf.set_x(pdf.l_margin)
        pdf.cell(name_w + spec_w + price_w, 8, ' ' + text, fill=True, new_x='LMARGIN', new_y='NEXT')

    if not groups:
        pdf.set_font(f, '', 13)
        pdf.cell(0, 10, 'まだ商品が登録されていません', new_x='LMARGIN', new_y='NEXT')
    for cat, items in groups.items():
        if pdf.get_y() + 8 + 10 > bottom:
            pdf.add_page()
        cat_bar(cat)
        for it in items:
            name = it['name'] + (f"（{it['note']}）" if it['note'] else '')
            spec = it['spec'] or ''
            pdf.set_font(f, '', FS)
            n_name = len(pdf.multi_cell(name_w - 2, line_h, name, dry_run=True, output='LINES'))
            n_spec = len(pdf.multi_cell(spec_w - 2, line_h, spec, dry_run=True, output='LINES')) if spec else 1
            h = max(n_name, n_spec) * line_h + 3
            if pdf.get_y() + h > bottom:
                pdf.add_page()
                cat_bar(cat + '（続き）')
            y = pdf.get_y()
            pdf.set_font(f, '', FS)
            pdf.set_xy(pdf.l_margin + 1, y + 1.5)
            pdf.multi_cell(name_w - 2, line_h, name)
            if spec:
                pdf.set_xy(pdf.l_margin + name_w + 1, y + 1.5)
                pdf.multi_cell(spec_w - 2, line_h, spec)
            pdf.set_font(f, '', 14)
            pdf.set_xy(pdf.l_margin + name_w + spec_w, y)
            pdf.cell(price_w - 2, h, yen_fmt(it['price']), align='R')
            pdf.set_draw_color(190, 190, 190)   # 区切り線だけ灰色。文字の輪郭に使う線の色は、すぐ黒に戻す
            pdf.line(pdf.l_margin, y + h, pdf.w - pdf.r_margin, y + h)
            pdf.set_draw_color(0, 0, 0)
            pdf.set_xy(pdf.l_margin, y + h)
        pdf.ln(3)

    data = bytes(pdf.output())
    _price_pdf_cache.update(sig=sig, data=data)
    response = make_response(data)
    response.headers['Content-Type'] = 'application/pdf'
    response.headers['Content-Disposition'] = f"attachment; filename*=UTF-8''{fn}"
    return response


PDF_VER = '3'  # PDFのレイアウトを変えたら上げる（保存済みのPDFを作り直させるため）


def pdf_filename(order):
    return f"発注書_{order['order_month']}月{order['order_day']}日_{order.get('supplier') or 'ワールド美容'}.pdf"


@app.route('/order/<int:order_id>/pdf')
def order_pdf(order_id):
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT order_month, order_day, supplier, pdf, pdf_ver FROM orders WHERE id=%s", (order_id,))
    row = cur.fetchone()
    if not row:
        abort(404)
    if row['pdf'] is not None and row['pdf_ver'] == PDF_VER:
        data = bytes(row['pdf'])
    else:
        data = build_pdf(order_id)
        cur.execute("UPDATE orders SET pdf=%s, pdf_ver=%s WHERE id=%s", (psycopg2.Binary(data), PDF_VER, order_id))
    response = make_response(data)
    response.headers['Content-Type'] = 'application/pdf'
    disposition = 'inline' if request.args.get('inline') else 'attachment'
    response.headers['Content-Disposition'] = f"{disposition}; filename*=UTF-8''{urllib.parse.quote(pdf_filename(row))}"
    return response


def warm_pdf(order_id):
    """発注確定の直後に裏でPDFを作って保存しておく（印刷画面を開く頃には用意できているように）"""
    with app.app_context():
        conn = _checkout()
        try:
            data = build_pdf(order_id, conn)
            conn.cursor().execute("UPDATE orders SET pdf=%s, pdf_ver=%s WHERE id=%s AND pdf IS NULL",
                                  (psycopg2.Binary(data), PDF_VER, order_id))
        except Exception:
            app.logger.exception('PDFの事前作成に失敗')
        finally:
            _checkin(conn)


def build_pdf(order_id, conn=None):
    order, blocks = load_order(order_id, conn)
    sup_name = order.get('supplier') or 'ワールド美容'
    contact_name = order.get('contact_name') or ''
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

    return bytes(pdf.output())


@app.route('/order/history')
def order_history():
    db = get_db()
    cur = db.cursor()
    cur.execute("""
        SELECT o.id, o.store, o.supplier, o.order_month, o.order_day, o.status, o.created_at,
               COUNT(oi.id)          AS item_count,
               COALESCE(SUM(oi.order_qty), 0) AS total_qty
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        WHERE o.supplier <> ALL(%s)
        GROUP BY o.id
        ORDER BY o.order_month DESC, o.order_day DESC, o.created_at DESC
        LIMIT 200
    """, (NO_HISTORY_SUPPLIERS,))
    orders = cur.fetchall()
    release_db(db)

    grouped = OrderedDict()
    for o in orders:
        grouped.setdefault((o['order_month'], o['order_day']), []).append(o)
    return render_template('history.html', grouped=grouped,
                           history_suppliers=[s for s in SUPPLIER_NAMES if s not in NO_HISTORY_SUPPLIERS])


@app.route('/order/<int:order_id>/delete', methods=['POST'])
def order_delete(order_id):
    """発注の取り消し（試しの発注など）。確定した時に在庫へ足した分を引き、履歴から消す。
    使用入力の「前回の発注から」の基準は、この発注より前の発注に戻る"""
    db = get_db()
    with transaction(db) as cur:
        cur.execute("SELECT store FROM orders WHERE id=%s FOR UPDATE", (order_id,))
        o = cur.fetchone()
        if not o:
            return jsonify(ok=False, error='発注が見つかりません'), 404
        cur.execute("SELECT product_id, SUM(order_qty) AS q FROM order_items WHERE order_id=%s AND product_id IS NOT NULL GROUP BY product_id", (order_id,))
        amounts = {r['product_id']: -float(r['q']) for r in cur.fetchall()}
        add_to_stock(cur, o['store'], amounts)
        if amounts:
            cur.execute("UPDATE stock SET current_qty = GREATEST(current_qty, 0) WHERE store=%s AND product_id = ANY(%s)",
                        (o['store'], list(amounts)))
        cur.execute("DELETE FROM order_items WHERE order_id=%s", (order_id,))
        cur.execute("DELETE FROM orders WHERE id=%s", (order_id,))
    return jsonify(ok=True)


# ── 商品マスター管理 ──────────────────────────────────────────────────────────

@app.route('/products')
def products():
    supplier = request.args.get('supplier', SUPPLIER_NAMES[0])
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT * FROM products ORDER BY col_group, sort_order, id")
    all_rows = [dict(r) for r in cur.fetchall()]
    cur.execute("SELECT product_id, store, base_qty FROM product_base")
    per_store = {}
    for b in cur.fetchall():
        per_store.setdefault(b['product_id'], {})[b['store']] = b['base_qty']
    for r in all_rows:
        # 店舗ごとの基準在庫（未入力の店舗は、これまでの共通の基準数）
        r['base_by_store'] = {st: per_store.get(r['id'], {}).get(st, r['base_qty']) for st in STORES}
    counts = {}
    for r in all_rows:
        counts[r['supplier']] = counts.get(r['supplier'], 0) + 1
    rows = all_rows if supplier == 'all' else [r for r in all_rows if r['supplier'] == supplier]
    categories = list(OrderedDict.fromkeys(r['category'] for r in rows if r['category']))
    return render_template('products.html', rows=rows, categories=categories,
                           current_supplier=supplier, counts=counts, total=len(all_rows))


@app.route('/products/add', methods=['POST'])
def product_add():
    d = request.form
    wants_json = 'application/json' in request.headers.get('Accept', '')
    category = nfkc(d.get('category', '')) or ''
    name = nfkc(d.get('name', ''))
    note = nfkc(d.get('note')) or None
    supplier = d.get('supplier') or SUPPLIER_NAMES[0]
    request_key = d.get('request_key') or None
    def num(key, default):
        try:
            return max(float(d.get(key) or default), 0)
        except ValueError:
            return default
    # 店舗ごとの基準数（どちらか片方だけ入力された時は、もう片方も同じ数にする）
    base_ino, base_rich = num('base_qty_ino', num('base_qty', 1)), num('base_qty_rich', num('base_qty', 1))
    base_qty = base_ino

    def fail(msg):
        return (jsonify(ok=False, error=msg), 400) if wants_json else redirect(url_for('products', supplier=supplier))
    if supplier not in SUPPLIER_NAMES:
        return fail('仕入先が不正です')
    if not name:
        return fail('商品名を入力してください')
    if len(name) > 200 or len(category) > 60 or (note and len(note) > 100):
        return fail('文字数が多すぎます（商品名200・カテゴリ60・備考100文字まで）')
    db = get_db()
    with transaction(db) as cur:
        if request_key:
            # 通信が切れて自動でやり直した時に、同じ商品が2件できないようにする
            cur.execute("SELECT id FROM products WHERE request_key=%s", (request_key,))
            done = cur.fetchone()
            if done:
                url = url_for('products', supplier=supplier, added=name) + f"#p{done['id']}"
                return jsonify(ok=True, url=url, repeated=True) if wants_json else redirect(url)
        # 同じ仕入先・同じカテゴリの最後の商品のすぐ後ろに入れる（一覧でそのカテゴリの中に並ぶように）
        cur.execute("""SELECT col_group, sort_order FROM products WHERE supplier=%s AND category=%s
                       ORDER BY col_group DESC, sort_order DESC, id DESC LIMIT 1""", (supplier, category))
        last = cur.fetchone()
        if last:
            col_group, pos = last['col_group'], last['sort_order'] + 1
            cur.execute("UPDATE products SET sort_order = sort_order + 1 WHERE col_group=%s AND sort_order >= %s",
                        (col_group, pos))
        else:
            # 新しいカテゴリは最後の列の一番後ろ
            cur.execute("SELECT COALESCE(MAX(col_group), 1) AS g FROM products")
            col_group = cur.fetchone()['g']
            cur.execute("SELECT COALESCE(MAX(sort_order), 0) + 1 AS s FROM products WHERE col_group=%s", (col_group,))
            pos = cur.fetchone()['s']
        cur.execute("""
            INSERT INTO products (col_group, category, name, base_qty, note, supplier, sort_order, request_key)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id
        """, (col_group, category, name, base_qty, note, supplier, pos, request_key))
        new_id = cur.fetchone()['id']
        cur.execute("INSERT INTO product_base (product_id, store, base_qty) VALUES (%s,'イノ',%s),(%s,'リッチ',%s)",
                    (new_id, base_ino, new_id, base_rich))
    url = url_for('products', supplier=supplier, added=name) + f'#p{new_id}'
    return jsonify(ok=True, url=url) if wants_json else redirect(url)


@app.route('/products/<int:pid>/move', methods=['POST'])
def product_move(pid):
    """同じ仕入先・同じカテゴリの中で、1つ上(up)または下(down)の商品と並び順を入れ替える"""
    direction = (request.get_json() or {}).get('dir')
    if direction not in ('up', 'down'):
        return jsonify(ok=False), 400
    cmp, order = ('<', 'DESC') if direction == 'up' else ('>', 'ASC')
    db = get_db()
    with transaction(db) as cur:
        cur.execute("SELECT id, supplier, category, col_group, sort_order FROM products WHERE id=%s FOR UPDATE", (pid,))
        me = cur.fetchone()
        if not me:
            return jsonify(ok=False), 404
        cur.execute(f"""
            SELECT id, col_group, sort_order FROM products
            WHERE supplier=%s AND category=%s AND id<>%s
              AND (col_group, sort_order, id) {cmp} (%s, %s, %s)
            ORDER BY col_group {order}, sort_order {order}, id {order} LIMIT 1 FOR UPDATE
        """, (me['supplier'], me['category'], pid, me['col_group'], me['sort_order'], pid))
        other = cur.fetchone()
        if not other:
            return jsonify(ok=True, moved=False)
        if (other['col_group'], other['sort_order']) == (me['col_group'], me['sort_order']):
            # 並び順が同じ値だと入れ替えても変わらないので、ずらしてから入れ替える
            bump = -1 if direction == 'up' else 1
            cur.execute("UPDATE products SET sort_order=sort_order+%s WHERE id=%s", (bump, other['id']))
            other['sort_order'] += bump
        cur.execute("UPDATE products SET col_group=%s, sort_order=%s WHERE id=%s",
                    (other['col_group'], other['sort_order'], pid))
        cur.execute("UPDATE products SET col_group=%s, sort_order=%s WHERE id=%s",
                    (me['col_group'], me['sort_order'], other['id']))
    return jsonify(ok=True, moved=True, other_id=other['id'])


@app.route('/products/<int:pid>/toggle', methods=['POST'])
def product_toggle(pid):
    db = get_db()
    cur = db.cursor()
    cur.execute("UPDATE products SET is_active = NOT is_active WHERE id=%s", (pid,))
    db.commit()
    release_db(db)
    return redirect(request.referrer or url_for('products'))


@app.route('/products/<int:pid>/base', methods=['POST'])
def product_base(pid):
    store = request.form.get('store', '')
    if store not in STORES:
        return jsonify(ok=False, error='店舗が不正です'), 400
    try:
        qty = float(request.form.get('base_qty', ''))
    except ValueError:
        return jsonify(ok=False, error='数を入力してください'), 400
    if qty < 0:
        return jsonify(ok=False, error='0以上の数を入力してください'), 400
    db = get_db()
    cur = db.cursor()
    cur.execute("SELECT 1 FROM products WHERE id=%s", (pid,))
    if not cur.fetchone():
        return jsonify(ok=False, error='商品が見つかりません'), 404
    cur.execute("""INSERT INTO product_base (product_id, store, base_qty) VALUES (%s,%s,%s)
                   ON CONFLICT (product_id, store) DO UPDATE SET base_qty = EXCLUDED.base_qty""", (pid, store, qty))
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
