# Render の起動コマンド `gunicorn app:app` が自動で読み込む設定
workers = 2
threads = 4
worker_class = 'gthread'
timeout = 120
# Renderの入口（ロードバランサ）は接続を長めに使い回す。アプリ側が先に切ると、
# 「接続が予期せず解除された」というエラーになるので、入口より長くする
keepalive = 120
