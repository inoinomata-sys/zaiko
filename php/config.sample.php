<?php
// これを「config.php」という名前でコピーして、ロリポップのサーバーに置く（GitHub には載せない）。
// 場所: ロリポップの FTP で、アプリのフォルダの直下（public の1つ上）。自動アップロードでは上書きされない。
return [
    // ロリポップ管理画面「サーバーの管理・設定 > データベース」に出る値
    'db_host' => 'mysqlXXXX.lolipop.jp',
    'db_name' => 'LAA0000000-zaiko',
    'db_user' => 'LAA0000000',
    'db_pass' => 'ここにデータベースのパスワード',
    // 不具合の調査用。うまく動かない時だけ true にする（画面に詳しいエラーが出る）。ふだんは false
    'debug' => false,
];
