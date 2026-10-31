# 美容室 在庫・発注管理システム

本番は **ロリポップ**（https://sougoukanri.ino-vifare.com、PHP 8 + MySQL、`php/` 配下）。ID・パスワード（Basic認証）は `php/config.php`（サーバー上のみ・git管理外）。
旧 Flask 版（`app.py`）は Render（zaiko-sg）で動いていたが、2026-10-31 に切り替えて **Render は停止済み**（削除はしていない）。Render へのデプロイはもう行わない。

## 変更後は自動で反映する

コードや仕様を変更したら、ユーザーに確認せずに以下まで行う：

1. `php/` の変更が本番。テンプレートは Flask 版 `templates/*.html` から `python3 php/tools/convert_templates.py` で生成する（両方コミット）
2. `git add <変更したファイル>` → `git commit`（日本語で変更内容を要約）
3. `git push origin main` → GitHub Actions がテスト後にロリポップへ自動デプロイ
4. 反映後、本番 URL で確認（Basic認証あり。`/ping` は認証なしで 200）

`.env` は絶対にコミットしない（DB パスワードとデプロイフックを含む）。

## 注意

- シフト管理（`/shift`）は PHP 版だけの機能。画面は `php/templates/shift*.twig` に直接書いてある（Flask 版の元ファイルはない。convert_templates.py は触らない）。表は初回アクセスで自動作成（`ensure_shift_tables`）。

- 自動デプロイの設定は `.github/workflows/lolipop.yml`（実際に動く）と `php/ci/lolipop.yml`（控え）の2つ。push では workflows を書き換えられないので、直す時は GitHub のサイトで控えを上げて置き換え、2つを同じ中身にそろえる。
- 旧 `app.py` と `templates/*.html` は本番では動かないが、PHP の画面（`php/templates/*.twig`）の生成元と、計算テストの正解データ（`php/tests/vectors.json`）の生成元なので、消さない。

- 旧 Supabase（Render 版が使っていた DB）は残っている。Render を再開する時は Supabase の接続上限（セッション15本）に注意。
- DB スキーマを変える場合は `init_db.py` も合わせて更新し、本番 DB にも反映する。
