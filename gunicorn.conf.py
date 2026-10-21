# Render の起動コマンド `gunicorn app:app` が自動で読み込む設定
workers = 2
threads = 4
worker_class = 'gthread'
timeout = 120
