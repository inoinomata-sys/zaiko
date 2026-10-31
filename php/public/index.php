<?php
// すべてのリクエストの入口
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/app.php';

require_basic_auth();
run_app();
