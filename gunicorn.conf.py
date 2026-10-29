# Render の起動コマンド `gunicorn app:app` が自動で読み込む設定
workers = 2
threads = 4
worker_class = 'gthread'
timeout = 120
# Renderの入口（ロードバランサ）は接続を長めに使い回す。アプリ側が先に切ると、
# 「接続が予期せず解除された」というエラーになるので、入口より長くする
keepalive = 120


def post_fork(server, worker):
    # 各プロセスが起動した直後に、DBの接続を先に作っておく（最初に触った人が待たされないように）
    import threading
    import app as _app
    threading.Thread(target=lambda: _app._get_pool(), daemon=True).start()
