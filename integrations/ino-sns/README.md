# INO SNS投稿メモ

総合管理の `/sns-memo` はスタッフの既存ログインを使います。WordPressのINOブログ管理室プラグインを経由してMakeへ送り、下書きを保存してLINEで確認します。

- `wordpress/ino-blog-workflow/` はWordPress側のプラグインです。フォルダ単位でZIPにして、既存の同名プラグインを更新します。
- `instagram-system-prompt.txt` はSNS専用のMakeシナリオで使う文章生成の指示です。入力プロンプトには「SNS投稿案を json 形式で作成してください」と受付メモ、Webhookの店舗を入れます。店舗の値は「INO」または「リッチ」です。既存のブログシナリオとは別のWebhookを使います。
- WordPressの「INO ブログ管理室」内の「SNS投稿メモの接続」に受信URL、受信キー、総合管理の接続キーを保存します。
- 総合管理の本番 `config.php` の `ino_blog_staff_key` に、WordPressと同じ総合管理の接続キーを設定します。設定値はGitHubに登録しません。

接続後に架空の施術メモを1件送り、WordPressの下書き保存、LINE着信、「この投稿案でOK」の承認記録を確認します。LINEの承認操作で記事の公開は行われません。
