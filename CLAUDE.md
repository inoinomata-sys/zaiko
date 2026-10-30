# 美容室 在庫・発注管理システム

Flask + PostgreSQL (Supabase)。本番は Render (https://zaiko-sg.onrender.com、シンガポール。DB の Supabase と同じ地域にして速くした)。
旧サービス zaiko（アメリカ東海岸、zaiko-unug.onrender.com）は 2026-10-30 に一時停止（削除はしていない）。

## 変更後は自動で反映する

コードや仕様を変更したら、ユーザーに確認せずに以下まで行う：

1. 動作確認（ローカルで `python3 app.py` → http://localhost:5001）
2. `git add <変更したファイル>` → `git commit`（日本語で変更内容を要約）
3. `git push origin main`
4. Render へデプロイ（新サービス zaiko-sg、service id srv-dbhoilt9el5s73ael8b0）: push だけでは自動デプロイされない。
   Chrome で https://dashboard.render.com/web/srv-dbhoilt9el5s73ael8b0 を開き、Manual Deploy → Deploy latest commit を押す。
   （.env の RENDER_DEPLOY_HOOK は旧サービスのもの。新サービスのフックを .env に入れれば `curl -fsS -X POST "$RENDER_DEPLOY_HOOK"` で済む）
5. デプロイ完了後、本番 URL で表示を確認

`.env` は絶対にコミットしない（DB パスワードとデプロイフックを含む）。

## 注意

- Supabase のセッション用接続は同時15本まで。常に持つ接続は各プロセス2本（app.py の接続プール）。新旧サービスを同時に動かすと枠が足りず起動に失敗した。
- Render からの DB 接続は Supabase の Session pooler (IPv4) を使う。Direct 接続 (db.*.supabase.co) は IPv6 専用で Render から繋がらない。
- DB スキーマを変える場合は `init_db.py` も合わせて更新し、本番 DB にも反映する。
