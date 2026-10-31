<?php
declare(strict_types=1);

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

require __DIR__ . '/stock.php';
require __DIR__ . '/orders.php';
require __DIR__ . '/customer.php';
require __DIR__ . '/products.php';

// ── 画面（Twig）の準備 ─────────────────────────────────────────────────────────

function twig(): Environment
{
    static $t = null;
    if ($t !== null) {
        return $t;
    }
    $cache = BASE_DIR . '/storage/twig_cache';
    if (!is_dir($cache)) {
        @mkdir($cache, 0775, true);
    }
    $t = new Environment(new FilesystemLoader(BASE_DIR . '/templates'), [
        'cache' => is_writable($cache) ? $cache : false,
        'autoescape' => 'html',
        'auto_reload' => true,
    ]);
    $safe = ['is_safe' => ['html']];
    $t->addFilter(new TwigFilter('n', 'num_fmt'));
    $t->addFilter(new TwigFilter('sama', 'sama_fmt'));
    $t->addFilter(new TwigFilter('yen', 'yen_fmt'));
    $t->addFilter(new TwigFilter('md', 'md_fmt'));
    $t->addFilter(new TwigFilter('jst', 'jst_fmt'));
    $t->addFilter(new TwigFilter('pctf', 'pct_fmt'));
    $t->addFilter(new TwigFilter('int', static fn($v) => (int)$v));
    $t->addFilter(new TwigFilter('urlencode', static fn($v) => str_replace('%2F', '/', rawurlencode((string)$v))));
    // JavaScript に値を渡す（</script> などが紛れても壊れない形にする）
    $t->addFilter(new TwigFilter('tojson', static fn($v) => json_encode(
        $v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_PRESERVE_ZERO_FRACTION
    ), $safe));
    $t->addFilter(new TwigFilter('forceescape', static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $safe));
    $t->addGlobal('supplier_names', SUPPLIER_NAMES);
    $t->addGlobal('stores', STORES);
    $t->addGlobal('request', ['path' => request_path(), 'args' => $_GET]);
    return $t;
}

function render(string $name, array $vars = [], int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo twig()->render($name . '.twig', $vars);
}

function request_path(): string
{
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return strlen($p) > 1 ? rtrim($p, '/') : $p;
}

// ── 画面の入り口の一覧（Flask 版の @app.route と同じ） ────────────────────────────

function routes(): array
{
    return [
        ['GET', '/', 'h_inventory'],
        ['POST', '/api/stock', 'h_api_stock'],
        ['POST', '/api/stock/check_category', 'h_api_check_category'],
        ['GET', '/usage', 'h_usage'],
        ['POST', '/api/usage', 'h_api_usage'],
        ['GET', '/order', 'h_order_top'],
        ['GET', '/order/form', 'h_order_form'],
        ['POST', '/api/order_draft', 'h_api_order_draft'],
        ['POST', '/api/order_draft/clear', 'h_api_order_draft_clear'],
        ['POST', '/order/save', 'h_order_save'],
        ['GET', '/order/history', 'h_order_history'],
        ['GET', '/order/<int:id>/print', 'h_order_print'],
        ['GET', '/order/<int:id>/pdf', 'h_order_pdf'],
        ['POST', '/order/<int:id>/delete', 'h_order_delete'],
        ['GET', '/prices', 'h_prices'],
        ['GET', '/prices/pdf', 'h_prices_pdf'],
        ['GET', '/customer-orders', 'h_customer_orders'],
        ['POST', '/customer-orders/save', 'h_customer_order_save'],
        ['GET', '/customer-orders/unordered/pdf', 'h_customer_orders_unordered_pdf'],
        ['GET', '/customer-orders/<int:id>', 'h_customer_order_view'],
        ['POST', '/customer-orders/<int:id>/ordered', 'h_customer_order_ordered'],
        ['POST', '/customer-orders/<int:id>/items/<int:iid>/ordered', 'h_customer_order_item_ordered'],
        ['GET', '/customer-orders/<int:id>/pdf', 'h_customer_order_pdf'],
        ['POST', '/customer-orders/<int:id>/delete', 'h_customer_order_delete'],
        ['GET', '/products', 'h_products'],
        ['POST', '/products/add', 'h_product_add'],
        ['POST', '/products/<int:id>/move', 'h_product_move'],
        ['POST', '/products/<int:id>/toggle', 'h_product_toggle'],
        ['POST', '/products/<int:id>/base', 'h_product_base'],
        ['POST', '/products/<int:id>/supplier', 'h_product_supplier'],
        ['GET', '/settings', 'h_settings_top'],
        ['GET', '/settings/orders', 'h_settings_orders'],
        ['GET', '/settings/contacts', 'h_contacts_settings'],
        ['POST', '/settings/contacts/add', 'h_contact_add'],
        ['POST', '/settings/contacts/<int:id>/update', 'h_contact_update'],
        ['POST', '/settings/contacts/<int:id>/delete', 'h_contact_delete'],
        ['GET', '/ping', 'h_ping'],
        ['GET', '/health', 'h_health'],
    ];
}

function run_app(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'HEAD') {
        $method = 'GET';
    }
    $path = request_path();
    try {
        $pathMatched = false;
        foreach (routes() as [$m, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#<int:(\w+)>#', '(?P<$1>\d+)', $pattern) . '$#';
            if (!preg_match($regex, $path, $mm)) {
                continue;
            }
            $pathMatched = true;
            if ($m !== $method) {
                continue;
            }
            $args = [];
            foreach ($mm as $k => $v) {
                if (is_string($k)) {
                    $args[] = (int)$v;
                }
            }
            $handler(...$args);
            return;
        }
        throw new HttpError($pathMatched ? 405 : 404);
    } catch (HttpError $e) {
        http_response_code($e->status);
        if (str_starts_with($path, '/api/') || $method !== 'GET') {
            respond_json(['ok' => false, 'error' => $e->status === 404 ? '見つかりません' : '受け付けできません'], $e->status);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->status === 404 ? 'Not Found' : 'Method Not Allowed';
    } catch (Throwable $e) {
        $trace = $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        error_log($trace);
        log_event($method, $_SERVER['REQUEST_URI'] ?? '', $trace);
        $debug = !empty($GLOBALS['CONFIG']['debug']);
        if (str_starts_with($path, '/api/') || $method !== 'GET') {
            respond_json(['ok' => false, 'error' => 'サーバーで一時的なエラーが起きました。もう一度お試しください。'
                . ($debug ? ' [' . $e->getMessage() . ']' : '')], 500);
        }
        try {
            render('error', [], 500);
        } catch (Throwable) {
            http_response_code(500);
            echo 'サーバーでエラーが起きました。';
        }
        if ($debug) {
            echo '<pre style="white-space:pre-wrap;padding:12px">' . htmlspecialchars($trace) . '</pre>';
        }
    }
}

function h_ping(): void
{
    header('Content-Type: text/plain');
    echo 'ok';
}

/** 状態の確認（個人情報や設定値は返さない）。ロリポップに置いた直後の動作確認にも使う */
function h_health(): void
{
    $info = [
        'php' => PHP_VERSION,
        'extensions' => [
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
            'intl' => extension_loaded('intl'),
            'zlib' => extension_loaded('zlib'),
        ],
        'font' => is_file(BASE_DIR . '/storage/pdffont/unifont/NotoSansJP-Regular.ttf'),
        'storage_writable' => is_writable(BASE_DIR . '/storage'),
        'config' => is_file(BASE_DIR . '/config.php'),
    ];
    try {
        $info['db'] = (int)val('SELECT 1') === 1;
        $info['counts'] = [];
        foreach (['products', 'stock', 'orders', 'customer_orders', 'price_items'] as $t) {
            $info['counts'][$t] = (int)val("SELECT COUNT(*) FROM {$t}");
        }
        $info['db_time'] = val('SELECT NOW()');
    } catch (Throwable $e) {
        $info['db'] = false;
        $info['db_error'] = $e->getMessage();
    }
    respond_json($info);
}
