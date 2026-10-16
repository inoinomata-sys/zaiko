# 美容室 在庫・発注管理システム

Flask + PostgreSQL (Supabase)。本番は Render (https://zaiko-unug.onrender.com)。

## 変更後は自動で反映する

コードや仕様を変更したら、ユーザーに確認せずに以下まで行う：

1. 動作確認（ローカルで `python3 app.py` → http://localhost:5001）
2. `git add <変更したファイル>` → `git commit`（日本語で変更内容を要約）
3. `git push origin main`
4. Render へデプロイ: `source .env && curl -fsS -X POST "$RENDER_DEPLOY_HOOK"`
   （Render は公開リポジトリ接続のため、push だけでは自動デプロイされない）
5. デプロイ完了後、本番 URL で表示を確認

`.env` は絶対にコミットしない（DB パスワードとデプロイフックを含む）。

## 注意

- Render からの DB 接続は Supabase の Session pooler (IPv4) を使う。Direct 接続 (db.*.supabase.co) は IPv6 専用で Render から繋がらない。
- DB スキーマを変える場合は `init_db.py` も合わせて更新し、本番 DB にも反映する。
