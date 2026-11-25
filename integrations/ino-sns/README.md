# INO SNS投稿メモ

総合管理の `/sns-memo` はスタッフの既存ログインを使います。WordPressのINOブログ管理室プラグインを経由してMakeへ送り、下書きを保存してLINEで確認します。

- `wordpress/ino-blog-workflow/` はWordPress側のプラグインです。フォルダ単位でZIPにして、既存の同名プラグインを更新します。
- `instagram-system-prompt.txt` はSNS専用のMakeシナリオで使う文章生成の指示です。既存のブログシナリオとは別のWebhookを使います。
- 総合管理の本番 `config.php` の `ino_blog_staff_key` と、WordPress本番の `INO_BLOG_STAFF_KEY` を同じランダムな64桁の16進数に設定します。
- WordPressの `INO_INSTAGRAM_MAKE_HOOK` と `INO_INSTAGRAM_MAKE_KEY` には、SNS専用のWebhook URLと認証キーを設定します。設定値はGitHubに登録しません。

接続後に架空の施術メモを1件送り、WordPressの下書き保存、LINE着信、「この投稿案でOK」の承認記録を確認します。LINEの承認操作で記事の公開は行われません。
