<?php
/**
 * Plugin Name: INO ブログ管理室
 * Description: 施術メモをMakeに送り、WordPressの下書きをLINEで承認します。
 * Version: 0.1.2
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
        add_action('rest_api_init', [self::class, 'routes']);
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
        $key = defined('INO_BLOG_STAFF_KEY') ? INO_BLOG_STAFF_KEY : '';
        $provided = $_SERVER['HTTP_X_INO_BLOG_STAFF_KEY'] ?? '';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($key) || !preg_match('/\A[0-9a-f]{64}\z/D', $key) || !hash_equals($key, $provided)) {
            wp_send_json_error('スタッフ用フォームの接続を確認してください。', 403);
        }
        self::submit_to_make('instagram');
    }

    private static function submit_to_make($format = 'blog') {
        $config = self::config();
        $hook = $format === 'instagram'
            ? (defined('INO_INSTAGRAM_MAKE_HOOK') ? INO_INSTAGRAM_MAKE_HOOK : '')
            : ($config['hook'] ?? '');
        $key = $format === 'instagram'
            ? (defined('INO_INSTAGRAM_MAKE_KEY') ? INO_INSTAGRAM_MAKE_KEY : '')
            : ($config['key'] ?? '');
        if (!self::valid_webhook($hook) || !is_string($key) || $key === '') {
            wp_send_json_error('Makeの接続を設定してください。', 503);
        }

        $memo = self::input('memo', true);
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

        $photo = self::photo();
        if (is_wp_error($photo)) {
            wp_send_json_error($photo->get_error_message(), 400);
        }

        $stage = self::PREFIX . $id;
        if (get_transient($stage . '_sent')) {
            wp_send_json_success('受付済みです。LINEへの通知をお待ちください。');
        }
        if (!add_option($stage . '_submit', time(), '', false)) {
            wp_send_json_error('このメモを送信中です。少し待ってから画面を確認してください。', 409);
        }

        try {
            $record = ['photo' => $photo, 'owner' => get_current_user_id(), 'format' => $format];
            if (get_transient($stage) !== $record && !set_transient($stage, $record, DAY_IN_SECONDS)) {
                $outcome = ['error' => '写真と入力内容を準備できませんでした。', 'status' => 500];
            } else {
                $response = wp_safe_remote_post($hook, [
                    'headers' => ['Content-Type' => 'application/json', 'x-make-apikey' => $key],
                    'body' => wp_json_encode(['shop' => 'INO', 'memo' => $memo, 'request_id' => $id]),
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
        if (!$post) {
            $post_id = wp_insert_post(wp_slash([
                'post_title' => sanitize_text_field($title),
                'post_content' => wp_kses_post($content),
                'post_status' => 'draft',
                'post_type' => 'post',
                'meta_input' => [self::META => $id, '_ino_blog_format' => $instagram ? 'instagram' : 'blog'],
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
        delete_transient($stage);
        $preview = get_preview_post_link($post);
        $approval_data = 'ino_ok:' . $post->ID . ':' . $id;
        $label = $instagram ? 'この投稿案でOK' : 'この下書きでOK';
        $plain_title = html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES, 'UTF-8');
        $plain_body = html_entity_decode(wp_strip_all_tags($post->post_content), ENT_QUOTES, 'UTF-8');
        return rest_ensure_response([
            'id' => $post->ID,
            'status' => $post->post_status,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'preview_url' => $preview,
            'approval_data' => $approval_data,
            'messages' => [
                ['type' => 'text', 'text' => ($instagram ? "【INO｜Instagram投稿案】\n" : "【INO ブログ管理室｜ブログ下書き】\n")
                    . ($instagram ? "\n" : wp_html_excerpt($plain_title, 150, '…') . "\n\n")
                    . wp_html_excerpt($plain_body, 2000, '…')],
                [
                    'type' => 'template',
                    'altText' => $instagram ? 'INO Instagram投稿案の確認' : 'INO ブログ下書きの確認',
                    'template' => [
                        'type' => 'buttons',
                        'text' => $instagram ? '投稿案を確認してください。' : '下書きを確認してください。',
                        'actions' => [
                            ['type' => 'uri', 'label' => $instagram ? '投稿案をプレビュー' : '記事をプレビュー', 'uri' => $preview],
                            ['type' => 'postback', 'label' => $label, 'data' => $approval_data, 'displayText' => $label],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private static function attach_photo($post_id, $id, $photo) {
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
            'post_title' => 'INO ブログ掲載写真',
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
                || ($event['source']['userId'] ?? '') !== $config['line_user'] || !is_string($data)
                || !preg_match('/\Aino_ok:([1-9][0-9]{0,17}):([0-9a-f-]{36})\z/D', $data, $parts)) {
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
