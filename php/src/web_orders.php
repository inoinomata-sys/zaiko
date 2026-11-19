<?php
declare(strict_types=1);
require_once __DIR__ . '/web_order_secrets.php';

function web_order_defaults(): array
{
    return [
        ['name' => 'アリミノコアミー', 'url' => 'https://market.arimino.co.jp/shop/salontop.aspx', 'host' => 'market.arimino.co.jp'],
        ['name' => 'ナプラ', 'url' => 'https://www.naplatag.jp/Form/Salon/SalonLogin.aspx', 'host' => 'www.naplatag.jp'],
        ['name' => 'オーガニックノート', 'url' => 'https://www.dresspoint.co.jp/NoteOrder/', 'host' => 'www.dresspoint.co.jp'],
        ['name' => 'リファ', 'url' => 'https://b2b-online.mtg.gr.jp', 'host' => 'b2b-online.mtg.gr.jp'],
        ['name' => 'リーディアル', 'url' => 'https://ledeal-ec.com/aec/user/login', 'host' => 'ledeal-ec.com'],
    ];
}


function ensure_web_order_sites(): void
{
    static $done = false;
    if ($done) return;
    run('CREATE TABLE IF NOT EXISTS web_order_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(80) NOT NULL,
        url TEXT NOT NULL,
        credentials TEXT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        seed_key VARCHAR(40) NULL UNIQUE
    ) DEFAULT CHARSET=utf8mb4');
    if (!(int)val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='web_order_sites' AND column_name='credentials'")) run('ALTER TABLE web_order_sites ADD COLUMN credentials TEXT NULL');
    foreach (web_order_defaults() as $i => $site) {
        run('INSERT IGNORE INTO web_order_sites (name,url,sort_order,seed_key) VALUES (?,?,?,?)', [$site['name'], $site['url'], $i+1, 'initial-' . $i]);
    }
    $done = true;
}

function h_web_orders(): void
{
    ensure_web_order_sites();
    $sites = rows('SELECT id,name,url FROM web_order_sites WHERE is_active=1 ORDER BY sort_order,id');
    foreach ($sites as &$site) $site['host'] = (string)parse_url($site['url'], PHP_URL_HOST);
    unset($site);
    render('web_orders', ['sites' => $sites]);
}

function web_order_form_key(): string
{
    return hash_hmac('sha256', 'web-order-sites|' . (string)($_COOKIE['zadmin'] ?? ''), admin_pass());
}

function h_web_order_settings(): void
{
    require_admin();
    header('Cache-Control: no-store, private');
    ensure_web_order_sites();
    render('settings_web_orders', ['sites' => rows('SELECT id,name,url,is_active,sort_order,(credentials IS NOT NULL) AS has_credentials FROM web_order_sites ORDER BY sort_order,id'), 'form_key' => web_order_form_key(), 'saved' => isset($_GET['saved']), 'error' => '', 'secrets_access'=>web_order_secrets_access()]);
}

function h_web_order_site_save(): void
{
    require_admin();
    if (!hash_equals(web_order_form_key(), (string)($_POST['form_key'] ?? ''))) throw new HttpError(403, '画面を開き直してから保存してください');
    ensure_web_order_sites();
    $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($id === false) throw new HttpError(400, '会社の指定が不正です');
    if ($id && !val('SELECT id FROM web_order_sites WHERE id=?', [$id])) throw new HttpError(404);
    $name = trim((string)($_POST['name'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $parts = parse_url($url);
    $error = '';
    if ($name === '' || mb_strlen($name) > 80) $error = '会社名を80文字以内で入力してください。';
    elseif (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['https','http'], true) || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/', $url)) $error = 'http:// または https:// で始まる注文サイトのURLを入力してください。ID・パスワードを含むURLは使えません。';
    $loginId = (string)($_POST['login_id'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $clear = ($_POST['clear_credentials'] ?? '') === '1';
    if ($loginId !== '' || $password !== '' || $clear) {
        if (!web_order_secrets_access()) throw new HttpError(403, 'ログイン情報の保存には管理者の合言葉で設定を開き直してください');
        if (strlen($loginId) > 512 || strlen($password) > 2048) $error = 'ログインIDまたはパスワードが長すぎます。';
    }
    $active = ($_POST['is_active'] ?? '') === '1' ? 1 : 0;
    if ($error !== '') {
        $sites = rows('SELECT id,name,url,is_active,sort_order,(credentials IS NOT NULL) AS has_credentials FROM web_order_sites ORDER BY sort_order,id');
        $draft = ['id'=>$id, 'name'=>$name, 'url'=>$url, 'is_active'=>$active];
        if ($id) { foreach ($sites as &$site) { if ((int)$site['id'] === $id) $site = array_merge($site, $draft); } unset($site); }
        render('settings_web_orders', ['sites'=>$sites, 'form_key'=>web_order_form_key(), 'saved'=>false, 'error'=>$error, 'draft'=>$id ? null : $draft, 'secrets_access'=>web_order_secrets_access()], 400);
        return;
    }
    $id = transaction(function () use ($id, $name, $url, $active, $loginId, $password, $clear) {
        if ($id) run('UPDATE web_order_sites SET name=?,url=?,is_active=? WHERE id=?', [$name,$url,$active,$id]);
        else {
            run('INSERT INTO web_order_sites (name,url,is_active,sort_order) VALUES (?,?,?,?)', [$name,$url,$active,1+(int)val('SELECT COALESCE(MAX(sort_order),0) FROM web_order_sites')]);
            $id = (int)db()->lastInsertId();
        }
        if ($clear) run('UPDATE web_order_sites SET credentials=NULL WHERE id=?', [$id]);
        elseif ($loginId !== '' || $password !== '') {
            $old = web_order_decrypt(val('SELECT credentials FROM web_order_sites WHERE id=?', [$id]), $id);
            $credentials = ['login_id'=>$loginId !== '' ? $loginId : $old['login_id'], 'password'=>$password !== '' ? $password : $old['password']];
            run('UPDATE web_order_sites SET credentials=? WHERE id=?', [web_order_encrypt($credentials, $id),$id]);
        }
        return $id;
    });
    redirect_to('/settings/web-orders?saved=1#site-' . $id);
}
