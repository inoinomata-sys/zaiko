<?php
/**
 * Plugin Name: INO ブログ管理室
 * Description: 施術メモをMakeに送り、WordPressの下書きをLINEで承認します。
 * Version: 0.1.10
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

final class Ino_Blog_Workflow {
    private const OPTION = 'ino_blog_workflow';
    private const PREFIX = 'ino_blog_submission_';
    private const META = '_ino_blog_submission';

    public static function init() {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_ino_blog_form', [self::class, 'form']);
        add_action('admin_post_ino_blog_submit', [self::class, 'submit']);
        add_action('admin_post_nopriv_ino_blog_staff_submit', [self::class, 'staff_submit']);
        add_action('admin_post_ino_blog_staff_submit', [self::class, 'staff_submit']);
        add_action('admin_post_ino_blog_settings', [self::class, 'save_settings']);
        add_action('admin_post_ino_sns_settings', [self::class, 'save_sns_settings']);
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('template_redirect', [self::class, 'show_sns_preview'], 0);
    }

    private static function config() {
        return (array) get_option(self::OPTION, []);
    }

    private static function valid_webhook($url) {
        return is_string($url) && preg_match('~\Ahttps://hook\.eu1\.make\.com/[a-z0-9]+\z~D', $url);
    }

    private static function valid_uuid($id) {
        return is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id);
    }

    private static function input($key, $multiline = false) {
        if (!isset($_POST[$key]) || !is_string($_POST[$key])) {
            return '';
        }
        $value = wp_unslash($_POST[$key]);
        return $multiline ? sanitize_textarea_field($value) : sanitize_text_field($value);
    }

    public static function menu() {
        add_options_page('INO ブログ管理室', 'INO ブログ管理室', 'manage_options', 'ino-blog-workflow', [self::class, 'settings']);
    }

    public static function settings() {
        if (!current_user_can('manage_options')) {
            wp_die('この画面を開く権限がありません。', '', ['response' => 403]);
        }
        $config = self::config();
        ?>
        <div class="wrap">
            <h1>INO ブログ管理室</h1>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success"><p>設定を保存しました。</p></div><?php endif; ?>
            <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin-post.php?action=ino_blog_form')); ?>">施術メモのフォームを開く</a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ino_blog_settings'); ?>
                <input type="hidden" name="action" value="ino_blog_settings">
                <table class="form-table">
                    <tr><th><label for="hook">Makeの受信URL</label></th><td><input class="regular-text" type="url" id="hook" name="hook" required value="<?php echo esc_attr($config['hook'] ?? ''); ?>"></td></tr>
                    <tr><th><label for="key">MakeのWebhookキー</label></th><td><input class="regular-text" type="password" id="key" name="key" autocomplete="new-password" placeholder="<?php echo empty($config['key']) ? '入力してください' : '保存済み'; ?>"></td></tr>
                    <tr><th><label for="line_user">承認する人のLINEユーザーID</label></th><td><input class="regular-text" type="text" id="line_user" name="line_user" pattern="U[0-9a-f]{32}" value="<?php echo esc_attr($config['line_user'] ?? ''); ?>"></td></tr>
                    <tr><th><label for="line_secret">LINEのチャネルシークレット</label></th><td><input class="regular-text" type="password" id="line_secret" name="line_secret" autocomplete="new-password" placeholder="<?php echo empty($config['line_secret']) ? '入力してください' : '保存済み'; ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>SNS投稿メモの接続</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ino_sns_settings'); ?>
                <input type="hidden" name="action" value="ino_sns_settings">
                <table class="form-table">
                    <?php foreach (['sns_hook' => 'SNS用の受信URL', 'sns_image_hook' => 'AI画像付き投稿用の受信URL', 'sns_key' => 'SNS用の受信キー', 'staff_key' => '総合管理の接続キー', 'rich_journal_key' => 'リッチのコラム接続キー'] as $name => $label) : ?>
                    <tr><th><label for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" type="password" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" autocomplete="new-password" placeholder="<?php echo empty($config[$name]) ? '未設定' : '保存済み'; ?>"></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('SNS設定を保存'); ?>
            </form>
            <p>下書き保存のURL <code><?php echo esc_html(rest_url('ino-blog/v1/draft')); ?></code></p>
            <p>LINEのWebhook URL <code><?php echo esc_html(rest_url('ino-blog/v1/line')); ?></code></p>
        </div>
        <?php
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('設定を変更する権限がありません。', '', ['response' => 403]);
        }
        check_admin_referer('ino_blog_settings');
        $config = self::config();
        $url = self::input('hook');
        $line_user = self::input('line_user');
        if (!self::valid_webhook($url) || ($line_user !== '' && !preg_match('/\AU[0-9a-f]{32}\z/D', $line_user))) {
            wp_die('Makeの受信URLまたはLINEユーザーIDの形式を確認してください。', '', ['response' => 400]);
        }
        $config['hook'] = $url;
        $config['line_user'] = $line_user;
        foreach (['key', 'line_secret'] as $key) {
            $value = self::input($key);
            if ($value !== '') {
                $config[$key] = $value;
            }
        }
        update_option(self::OPTION, $config, false);
        wp_safe_redirect(admin_url('options-general.php?page=ino-blog-workflow&saved=1'));
        exit;
    }

    public static function save_sns_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('設定を変更する権限がありません。', '', ['response' => 403]);
        }
        check_admin_referer('ino_sns_settings');
        $values = array_filter([
            'sns_hook' => self::input('sns_hook'),
            'sns_image_hook' => self::input('sns_image_hook'),
            'sns_key' => self::input('sns_key'),
            'staff_key' => self::input('staff_key'),
            'rich_journal_key' => self::input('rich_journal_key'),
        ], static function ($value) { return $value !== ''; });
        if ((isset($values['sns_hook']) && !self::valid_webhook($values['sns_hook']))
            || (isset($values['sns_image_hook']) && !self::valid_webhook($values['sns_image_hook']))
            || (isset($values['sns_key']) && !preg_match('/\A[0-9a-f]{64}\z/D', $values['sns_key']))
            || (isset($values['rich_journal_key']) && !preg_match('/\A[0-9a-f]{64}\z/D', $values['rich_journal_key']))
            || (isset($values['staff_key']) && !preg_match('/\A[0-9a-f]{64}\z/D', $values['staff_key']))) {
            wp_die('SNS用の受信URLと接続キーをご確認ください。', '', ['response' => 400]);
        }
        update_option(self::OPTION, array_replace(self::config(), $values), false);
        wp_safe_redirect(admin_url('options-general.php?page=ino-blog-workflow&saved=1'));
        exit;
    }

    public static function form() {
        if (!current_user_can('manage_options')) {
            wp_die('フォームを使う権限がありません。', '', ['response' => 403]);
        }
        nocache_headers();
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow');
        $request_id = wp_generate_uuid4();
        require __DIR__ . '/form.php';
        exit;
    }

    public static function submit() {
        if (!current_user_can('manage_options') || !wp_verify_nonce(self::input('_wpnonce'), 'ino_blog_submit')) {
            wp_send_json_error('ログインし直して、フォームを開いてください。', 403);
        }
        self::submit_to_make();
    }

    public static function staff_submit() {
        $key = defined('INO_BLOG_STAFF_KEY') ? INO_BLOG_STAFF_KEY : (self::config()['staff_key'] ?? '');
        $provided = $_SERVER['HTTP_X_INO_BLOG_STAFF_KEY'] ?? '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key) || !hash_equals($key, $provided)) {
            wp_send_json_error('スタッフ用フォームの接続を確認してください。', 403);
        }
        $shop = isset($_POST['shop']) ? self::input('shop') : 'INO';
        if (!in_array($shop, ['INO', 'リッチ'], true)) {
            wp_send_json_error('店舗を選び直してください。', 400);
        }
        $kind = isset($_POST['kind']) ? self::input('kind') : 'sns';
        if (!in_array($kind, ['sns', 'journal'], true) || ($kind === 'journal' && $shop !== 'リッチ')) {
            wp_send_json_error('作成する内容を選び直してください。', 400);
        }
        if ($kind === 'journal' && empty(self::config()['rich_journal_key'])) {
            wp_send_json_error('コラムの接続設定が済んでいません。', 503);
        }
        $generate_image = ($kind === 'journal' || $shop === 'INO') && self::input('generate_image') === '1';
        self::submit_to_make($kind === 'journal' ? 'rich-journal' : 'instagram', $shop, $generate_image);
    }

    private static function submit_to_make($format = 'blog', $shop = 'INO', $generate_image = false) {
        $config = self::config();
        $hook = $format !== 'blog'
            ? (defined('INO_INSTAGRAM_MAKE_HOOK') ? INO_INSTAGRAM_MAKE_HOOK : ($config['sns_hook'] ?? ''))
            : ($config['hook'] ?? '');
        $key = $format !== 'blog'
            ? (defined('INO_INSTAGRAM_MAKE_KEY') ? INO_INSTAGRAM_MAKE_KEY : ($config['sns_key'] ?? ''))
            : ($config['key'] ?? '');
        if (!self::valid_webhook($hook) || !is_string($key) || $key === '') {
            wp_send_json_error('Makeの接続を設定してください。', 503);
        }

        $memo = self::input('memo', true);
        $image_prompt = $format === 'instagram' && $shop === 'INO' && $generate_image
            ? self::ino_image_prompt($memo) : '';
        if ($format === 'instagram' && $shop === 'INO' && $image_prompt === '') {
            $generate_image = false;
        }
        $id = self::input('request_id');
        if ($memo === '' || strlen($memo) > 6000 || !self::valid_uuid($id)) {
            wp_send_json_error('施術メモと入力内容を確認してください。', 400);
        }
        $fields = ['年代' => 'age', '性別' => 'gender', 'メニュー' => 'menu', 'おすすめアイテム' => 'item'];
        foreach ($fields as $label => $field) {
            $value = self::input($field);
            if ($value !== '') {
                $memo .= "\n" . $label . '：' . wp_html_excerpt($value, 100, '');
            }
        }
        $concerns = array_filter(array_map('sanitize_text_field', array_filter((array) wp_unslash($_POST['concerns'] ?? []), 'is_string')));
        if ($concerns) {
            $memo .= "\n髪のお悩み：" . wp_html_excerpt(implode('、', $concerns), 200, '');
        }

        if ($format === 'instagram') {
            $memo = '店舗：' . $shop . "\n種類：SNS投稿案\n" . $memo;
        } elseif ($format === 'rich-journal') {
            $memo = "店舗：リッチ\n種類：髪とカラーのお話\n" . $memo;
        }

        $photo = self::photo();
        if (is_wp_error($photo)) {
            wp_send_json_error($photo->get_error_message(), 400);
        }
        if ($format === 'rich-journal' && $photo !== null) {
            wp_send_json_error('コラムの写真は、下書きができてからWordPressで追加してください。', 400);
        }

        if ($generate_image && $photo === null) {
            $hook = $config['sns_image_hook'] ?? '';
            if (!self::valid_webhook($hook)) {
                wp_send_json_error('画像生成の受付先を設定してください。', 503);
            }
        }
        $stage = self::PREFIX . $id;
        if (get_transient($stage . '_sent')) {
            wp_send_json_success('受付済みです。LINEへの通知をお待ちください。');
        }
        if (!add_option($stage . '_submit', time(), '', false)) {
            wp_send_json_error('このメモを送信中です。少し待ってから画面を確認してください。', 409);
        }

        try {
            $record = ['photo' => $photo, 'owner' => get_current_user_id(), 'format' => $format, 'shop' => $shop, 'generate_image' => $generate_image && $photo === null];
            $record['image_prompt'] = $image_prompt;
            if (get_transient($stage) !== $record && !set_transient($stage, $record, DAY_IN_SECONDS)) {
                $outcome = ['error' => '写真と入力内容を準備できませんでした。', 'status' => 500];
            } else {
                $response = wp_safe_remote_post($hook, [
                    'headers' => ['Content-Type' => 'application/json', 'x-make-apikey' => $key],
                    'body' => wp_json_encode(['shop' => $shop, 'memo' => $memo, 'request_id' => $id]),
                    'timeout' => 12,
                    'redirection' => 0,
                ]);
                if (is_wp_error($response)) {
                    $outcome = ['error' => 'Makeの応答を確認できませんでした。LINEに通知が届かなければ、同じ画面から再試行できます。', 'status' => 502];
                } elseif (!in_array(wp_remote_retrieve_response_code($response), [200, 202], true)) {
                    $outcome = ['error' => 'Makeが受け付けませんでした。接続設定を確認してください。', 'status' => 502];
                } else {
                    set_transient($stage . '_sent', 1, DAY_IN_SECONDS);
                    $outcome = ['success' => true];
                }
            }
        } finally {
            delete_option($stage . '_submit');
        }
        if (empty($outcome['success'])) {
            wp_send_json_error($outcome['error'], $outcome['status']);
        }
        wp_send_json_success('受け付けました。下書きができるとLINEに届きます。');
    }

    private static function photo() {
        $file = $_FILES['photo'] ?? null;
        if (!$file || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size'] > 3 * MB_IN_BYTES) {
            return new WP_Error('photo_upload', '3MB以内の写真を選んでください。');
        }
        $mime = wp_get_image_mime($file['tmp_name']);
        if (!isset($types[$mime])) {
            return new WP_Error('photo_type', 'JPEG・PNG・WebPの写真を選んでください。');
        }
        $data = file_get_contents($file['tmp_name']);
        if ($data === false) {
            return new WP_Error('photo_read', '写真を読み込めませんでした。');
        }
        return ['data' => base64_encode($data), 'extension' => $types[$mime], 'mime' => $mime];
    }

    public static function routes() {
        register_rest_route('ino-blog/v1', '/draft', [
            'methods' => 'POST',
            'permission_callback' => static function () { return current_user_can('edit_posts'); },
            'callback' => [self::class, 'create_draft'],
        ]);
        register_rest_route('ino-blog/v1', '/draft-image', [
            'methods' => 'POST',
            'permission_callback' => static function () { return current_user_can('upload_files'); },
            'callback' => [self::class, 'save_draft_image'],
        ]);
        register_rest_route('ino-blog/v1', '/line', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'verify_line'],
            'callback' => [self::class, 'approve_from_line'],
        ]);
    }

    public static function create_draft(WP_REST_Request $request) {
        $input = $request->get_json_params();
        $id = $input['request_id'] ?? null;
        $title = $input['title'] ?? null;
        $content = $input['content'] ?? null;
        if (!self::valid_uuid($id) || !is_string($title) || !is_string($content) || trim($title) === '' || trim($content) === '') {
            return new WP_Error('draft_input', '下書きの入力が不足しています。', ['status' => 400]);
        }

        $lock = self::PREFIX . $id . '_draft';
        if (!add_option($lock, time(), '', false)) {
            return new WP_Error('draft_busy', 'この下書きを処理中です。', ['status' => 409]);
        }
        try {
            return self::save_draft($id, $title, $content);
        } finally {
            delete_option($lock);
        }
    }

    private static function save_draft($id, $title, $content) {
        $stage = self::PREFIX . $id;
        $photo_record = get_transient($stage);
        if (($photo_record['format'] ?? '') === 'rich-journal') {
            return self::save_rich_draft($id, $title, $content, ($photo_record['generate_image'] ?? false) === true);
        }
        $existing = get_posts([
            'post_type' => 'post',
            'post_status' => ['draft', 'pending', 'publish', 'future', 'private', 'trash'],
            'meta_key' => self::META,
            'meta_value' => $id,
            'numberposts' => 1,
        ]);
        $post = $existing[0] ?? null;
        if ($post && ($post->post_status !== 'draft' || get_post_meta($post->ID, self::META, true) !== $id)) {
            return new WP_Error('draft_unavailable', '対象記事を確認できません。', ['status' => 409]);
        }
        if (!$post && !is_array($photo_record)) {
            return new WP_Error('draft_expired', 'フォームの受付記録が見つかりません。', ['status' => 404]);
        }
        $instagram = $post
            ? get_post_meta($post->ID, '_ino_blog_format', true) === 'instagram'
            : ($photo_record['format'] ?? '') === 'instagram';
        $recorded_shop = $post ? get_post_meta($post->ID, '_ino_blog_shop', true) : ($photo_record['shop'] ?? 'INO');
        $shop = $recorded_shop === 'リッチ' ? 'リッチ' : 'INO';
        if (!$post) {
            $post_id = wp_insert_post(wp_slash([
                'post_title' => sanitize_text_field($title),
                'post_content' => wp_kses_post($content),
                'post_status' => 'draft',
                'post_type' => 'post',
                'meta_input' => [self::META => $id, '_ino_blog_format' => $instagram ? 'instagram' : 'blog', '_ino_blog_shop' => $shop],
            ]), true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }
            $post = get_post($post_id);
        }
        if (!empty($photo_record['photo']) && !has_post_thumbnail($post->ID)) {
            $attachment = self::attach_photo($post->ID, $id, $photo_record['photo']);
            if (is_wp_error($attachment)) {
                return $attachment;
            }
        }
        $image_prompt = $photo_record['image_prompt'] ?? get_post_meta($post->ID, '_ino_image_prompt', true);
        $image_requested = $image_prompt !== '' && $instagram && $shop === 'INO' && !has_post_thumbnail($post->ID)
            && (($photo_record['generate_image'] ?? false) === true || get_post_meta($post->ID, '_ino_image_requested', true) === '1');
        if ($image_requested) {
            update_post_meta($post->ID, '_ino_image_prompt', $image_prompt);
            update_post_meta($post->ID, '_ino_image_requested', '1');
        }
        delete_transient($stage);
        $preview = get_preview_post_link($post);
        $approval_data = ($shop === 'INO' ? 'ino_publish:' : 'ino_ok:') . $post->ID . ':' . $id;
        $label = $shop === 'INO' ? 'OK・ホームページに公開' : 'この投稿案でOK';
        $plain_title = html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES, 'UTF-8');
        $plain_body = html_entity_decode(wp_strip_all_tags($post->post_content), ENT_QUOTES, 'UTF-8');
        return rest_ensure_response([
            'id' => $post->ID,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'preview_url' => $preview,
            'approval_data' => $approval_data,
            'image_requested' => $image_requested,
            'image_request_id' => $image_requested ? $id : '',
            'image_prompt' => $image_requested ? $image_prompt : '',
            'messages' => [
                ['type' => 'text', 'text' => ($instagram ? ('【' . $shop . "｜SNS投稿案】\n") : "【INO ブログ管理室｜ブログ下書き】\n")
                    . ($instagram ? "\n" : wp_html_excerpt($plain_title, 150, '…') . "\n\n")
                    . wp_html_excerpt($plain_body, 2000, '…')],
                [
                    'type' => 'template',
                    'altText' => $instagram ? $shop . ' SNS投稿案の確認' : 'INO ブログ下書きの確認',
                    'template' => [
                        'type' => 'buttons',
                        'text' => $shop === 'INO' ? '内容を確認し、公開する場合は下の公開ボタンを押してください。' : '投稿案を確認してください。',
                        'actions' => [
                            ['type' => 'uri', 'label' => $instagram ? '投稿案をプレビュー' : '記事をプレビュー', 'uri' => $preview],
                            ['type' => 'postback', 'label' => $label, 'data' => $approval_data, 'displayText' => $label],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private static function rich_request($route, array $payload) {
        $key = self::config()['rich_journal_key'] ?? '';
        if (!is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key)) {
            return new WP_Error('rich_not_configured', 'リッチのコラム接続が未設定です。', ['status' => 503]);
        }
        $response = wp_safe_remote_post('https://rich-color.life/wp-json/rich-journal/v1/' . $route, [
            'headers' => ['Content-Type' => 'application/json', 'X-Rich-Journal-Key' => $key],
            'body' => wp_json_encode($payload), 'timeout' => 12, 'redirection' => 0,
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('rich_request_failed', 'リッチのコラムを処理できませんでした。', ['status' => 502]);
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : new WP_Error('rich_response', 'リッチの応答を確認できません。', ['status' => 502]);
    }

    private static function save_rich_draft($id, $title, $content, $generate_image) {
        $draft = self::rich_request('draft', ['request_id' => $id, 'title' => $title, 'content' => $content, 'generate_image' => $generate_image]);
        if (is_wp_error($draft)) {
            return $draft;
        }
        if (!is_int($draft['id'] ?? null) || ($draft['status'] ?? '') !== 'draft'
            || !is_string($draft['title'] ?? null) || !is_string($draft['content'] ?? null)
            || wp_parse_url($draft['preview_url'] ?? '', PHP_URL_HOST) !== 'rich-color.life'
            || wp_parse_url($draft['preview_url'], PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('rich_draft_response', 'リッチの下書き情報を確認できません。', ['status' => 502]);
        }
        $approval = 'rich_journal_ok:' . $draft['id'] . ':' . $id;
        $label = 'この下書きでOK';
        $plain_title = html_entity_decode(wp_strip_all_tags($draft['title']), ENT_QUOTES, 'UTF-8');
        $plain_body = html_entity_decode(wp_strip_all_tags($draft['content']), ENT_QUOTES, 'UTF-8');
        $draft['approval_data'] = $approval;
        $draft['messages'] = [
            ['type' => 'text', 'text' => "【リッチ｜髪とカラーのお話・下書き】\n"
                . wp_html_excerpt($plain_title, 150, '…') . "\n\n" . wp_html_excerpt($plain_body, 3500, '…')],
            [
                'type' => 'template',
                'altText' => 'リッチのコラム下書きの確認',
                'template' => [
                    'type' => 'buttons',
                    'text' => 'コラムの下書きを確認してください。',
                    'actions' => [
                        ['type' => 'uri', 'label' => 'コラムをプレビュー', 'uri' => $draft['preview_url']],
                        ['type' => 'postback', 'label' => $label, 'data' => $approval, 'displayText' => $label],
                    ],
                ],
            ],
        ];
        return rest_ensure_response($draft);
    }

    public static function show_sns_preview() {
        if (!is_preview()) {
            return;
        }
        $post = get_queried_object();
        if (!$post instanceof WP_Post || $post->post_status !== 'draft'
            || get_post_meta($post->ID, '_ino_blog_format', true) !== 'instagram') {
            return;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            auth_redirect();
            wp_die('この下書きを見る権限がありません。', '', ['response' => 403]);
        }
        $shop = get_post_meta($post->ID, '_ino_blog_shop', true) === 'リッチ' ? 'リッチ' : 'INO';
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=UTF-8');
        ?>
        <!doctype html>
        <html lang="ja">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title>SNS投稿案・下書き | <?php echo esc_html($shop); ?></title>
            <style>
                * { box-sizing: border-box; }
                body { margin: 0; background: #faf8f3; color: #262219; font: 1rem/1.8 system-ui, sans-serif; }
                main { width: min(100%, 42rem); margin: auto; padding: 24px 16px; overflow-wrap: anywhere; }
                h1 { margin: 0 0 16px; font-size: 1.5rem; }
                article { padding: 16px; background: #fff; border: 1px solid #ded6c2; border-radius: 12px; }
                h2 { margin: 0; font-size: 1.15rem; }
                img { display: block; max-width: 100%; height: auto; margin: 16px 0; border-radius: 8px; }
                p:last-child { margin-bottom: 0; }
            </style>
        </head>
        <body>
        <main>
            <h1>SNS投稿案・下書き</h1>
            <p><?php echo esc_html($shop); ?></p>
            <article>
                <h2><?php echo esc_html($post->post_title); ?></h2>
                <?php echo get_the_post_thumbnail($post, 'large'); ?>
                <?php echo wp_kses_post(wpautop($post->post_content)); ?>
            </article>
        </main>
        </body>
        </html>
        <?php
        exit;
    }

    private static function ino_image_prompt($memo) {
        // Only illustrate an explicitly described technique, never infer a finished style.
        $actions = [
            '前髪を少しずつ切' => 'Show a stylist trimming a small section of fringe on a training mannequin.',
            '前髪を少しずつカット' => 'Show a stylist trimming a small section of fringe on a training mannequin.',
            '根元から乾か' => 'Show a side view of a blow dryer aimed at the roots of hair on a training mannequin.',
            '根元から風を当て' => 'Show a side view of a blow dryer aimed at the roots of hair on a training mannequin.',
        ];
        foreach ($actions as $action => $subject) {
            if (strpos($memo, $action) !== false && !preg_match('/ない|ません|せず|しない|避け/u', $memo)) {
                return 'Simple editorial technique illustration. ' . $subject
                    . ' Focus on this action. Neutral background. No client portrait, result photo, clippings, lettering or logos.';
            }
        }
        return '';
    }

    public static function save_draft_image(WP_REST_Request $request) {
        if (strlen($request->get_body()) > 4500000) {
            return new WP_Error('ino_image_size', '画像が大きすぎます。', ['status' => 400]);
        }
        $input = $request->get_json_params();
        $post_id = $input['post_id'] ?? null;
        $id = $input['image_request_id'] ?? null;
        $data = $input['image_base64'] ?? null;
        if (!is_int($post_id) || $post_id < 1 || !self::valid_uuid($id) || !is_string($data) || strlen($data) > 4 * MB_IN_BYTES) {
            return new WP_Error('ino_image_input', '画像の入力を確認してください。', ['status' => 400]);
        }
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'draft'
            || get_post_meta($post_id, self::META, true) !== $id
            || get_post_meta($post_id, '_ino_image_requested', true) !== '1') {
            return new WP_Error('ino_image_draft', '画像を希望した下書きが見つかりません。', ['status' => 404]);
        }
        $bytes = base64_decode($data, true);
        $size = is_string($bytes) && strlen($bytes) <= 3 * MB_IN_BYTES ? @getimagesizefromstring($bytes) : false;
        $types = ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];
        $mime = $size['mime'] ?? '';
        if (!isset($types[$mime]) || min($size[0], $size[1]) < 256 || max($size[0], $size[1]) > 2048) {
            return new WP_Error('ino_image_type', '画像の形式を確認してください。', ['status' => 400]);
        }
        $lock = self::PREFIX . $id . '_image';
        if (!add_option($lock, time(), '', false)) {
            return new WP_Error('ino_image_busy', '画像を保存中です。', ['status' => 409]);
        }
        try {
            if (get_post_status($post_id) !== 'draft') {
                return new WP_Error('ino_image_not_draft', '対象の記事は下書きではありません。', ['status' => 409]);
            }
            if (has_post_thumbnail($post_id)) {
                return rest_ensure_response(['ok' => true]);
            }
            $attachment = self::attach_photo($post_id, $id, ['data' => $data, 'extension' => $types[$mime], 'mime' => $mime], true);
            return is_wp_error($attachment) ? $attachment : rest_ensure_response(['ok' => true, 'attachment_id' => $attachment]);
        } finally {
            delete_option($lock);
        }
    }

    private static function attach_photo($post_id, $id, $photo, $generated = false) {
        if (!current_user_can('upload_files')) {
            return new WP_Error('photo_permission', '連携用ユーザーに写真を登録する権限がありません。', ['status' => 403]);
        }
        $bytes = base64_decode($photo['data'], true);
        $upload = wp_upload_bits('ino-' . $id . '.' . $photo['extension'], null, $bytes);
        if (!empty($upload['error'])) {
            return new WP_Error('photo_store', '写真を登録できませんでした。', ['status' => 500]);
        }
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $photo['mime'],
            'post_title' => $generated ? 'INOの見出し画像（AI生成）' : 'INO ブログ掲載写真',
            'post_status' => 'inherit',
        ], $upload['file'], $post_id, true);
        if (is_wp_error($attachment_id)) {
            wp_delete_file($upload['file']);
            return $attachment_id;
        }
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $upload['file']));
        if (!set_post_thumbnail($post_id, $attachment_id)) {
            wp_delete_attachment($attachment_id, true);
            return new WP_Error('photo_thumbnail', '下書きに写真を設定できませんでした。', ['status' => 500]);
        }
        if ($generated) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', '投稿のテーマを表したイメージ画像');
        }
        return $attachment_id;
    }

    public static function verify_line(WP_REST_Request $request) {
        $config = self::config();
        $body = $request->get_body();
        $signature = $request->get_header('x-line-signature');
        if (empty($config['line_secret']) || empty($config['line_user']) || !is_string($signature) || strlen($body) > 1024 * 1024) {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $body, $config['line_secret'], true));
        return hash_equals($expected, $signature);
    }

    public static function approve_from_line(WP_REST_Request $request) {
        $config = self::config();
        $input = $request->get_json_params();
        if (!isset($input['events']) || !is_array($input['events'])) {
            return new WP_Error('line_events', 'イベント形式を確認してください。', ['status' => 400]);
        }
        foreach ($input['events'] as $event) {
            $data = $event['postback']['data'] ?? '';
            if (($event['type'] ?? '') !== 'postback' || ($event['source']['type'] ?? '') !== 'user'
                || ($event['source']['userId'] ?? '') !== $config['line_user'] || !is_string($data)) {
                continue;
            }
            if (preg_match('/\Arich_journal_ok:([1-9][0-9]{0,17}):([0-9a-f-]{36})\z/D', $data, $parts)
                && self::valid_uuid($parts[2])) {
                $result = self::rich_request('approve', ['post_id' => (int) $parts[1], 'request_id' => $parts[2]]);
                if (is_wp_error($result)) {
                    return $result;
                }
                continue;
            }
            if (!preg_match('/\A(?:ino_ok|ino_publish):([1-9][0-9]{0,17}):([0-9a-f-]{36})\z/D', $data, $parts)) {
                continue;
            }
            $post = get_post((int) $parts[1]);
            if (!$post || $post->post_type !== 'post' || $post->post_status !== 'draft'
                || !self::valid_uuid($parts[2]) || get_post_meta($post->ID, self::META, true) !== $parts[2]) {
                continue;
            }
            $record = ['approved_at' => gmdate('c'), 'source' => 'line'];
            $key = 'ino_blog_approved_' . $post->ID;
            if (!get_option($key, false) && !add_option($key, $record, '', false) && !get_option($key, false)) {
                return new WP_Error('approval_write', '承認を記録できませんでした。', ['status' => 503]);
            }
            if (strpos($data, 'ino_publish:') === 0 && get_post_meta($post->ID, '_ino_blog_shop', true) === 'INO') {
                $result = wp_update_post(['ID' => $post->ID, 'post_status' => 'publish'], true);
                if (is_wp_error($result) || get_post_status($post->ID) !== 'publish') {
                    return new WP_Error('publication_failed', '記事を公開できませんでした。', ['status' => 503]);
                }
            }
        }
        return rest_ensure_response(['ok' => true]);
    }

    public static function approval_box($post) {
        if (!get_post_meta($post->ID, self::META, true)) {
            return;
        }
        add_meta_box('ino-blog-approval', 'LINE承認', static function ($post) {
            $record = get_option('ino_blog_approved_' . $post->ID, false);
            echo $record ? '承認済み ' . esc_html(wp_date('Y/m/d H:i', strtotime($record['approved_at']))) : '承認待ち';
        }, 'post', 'side');
    }
}

Ino_Blog_Workflow::init();
add_action('add_meta_boxes_post', [Ino_Blog_Workflow::class, 'approval_box']);
