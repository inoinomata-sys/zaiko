<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $c = $GLOBALS['CONFIG'];
    foreach (['db_host', 'db_name', 'db_user', 'db_pass'] as $k) {
        if (!isset($c[$k])) {
            throw new RuntimeException("config.php に {$k} がありません");
        }
    }
    $pdo = new PDO(
        "mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4",
        $c['db_user'],
        $c['db_pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_FOUND_ROWS => true,   // UPDATE の件数は「条件に合った行数」で数える
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+09:00', sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')",
        ]
    );
    return $pdo;
}

/** 値の型をそろえる（真偽は 1/0、小数は桁を落とさない文字に） */
function bind_params(array $params): array
{
    return array_map(static function ($v) {
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        if (is_float($v)) {
            return sprintf('%.15g', $v);
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        return $v;
    }, $params);
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(bind_params($params));
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** 1行目の1列目の値（なければ null） */
function val(string $sql, array $params = []): mixed
{
    $r = q($sql, $params)->fetch(PDO::FETCH_NUM);
    return $r === false ? null : $r[0];
}

/** 更新系。条件に合った行数を返す */
function run(string $sql, array $params = []): int
{
    return q($sql, $params)->rowCount();
}

function last_id(): int
{
    return (int)db()->lastInsertId();
}

/** IN (?,?,?) の ? の並び */
function marks(array $values): string
{
    return implode(',', array_fill(0, count($values), '?'));
}

/** まとめて書く処理。途中で失敗したら、全部なかったことにする */
function transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** 複数行の INSERT（VALUES を何行かずつに分ける） */
function insert_many(string $table, array $cols, array $rowsData, int $chunk = 200): void
{
    foreach (array_chunk($rowsData, $chunk) as $part) {
        $one = '(' . marks($cols) . ')';
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($part), $one));
        $flat = [];
        foreach ($part as $r) {
            foreach ($r as $v) {
                $flat[] = $v;
            }
        }
        q($sql, $flat);
    }
}

/** 不具合の記録（失敗しても本来の処理には影響させない） */
function log_event(string $kind, string $path, string $text = ''): void
{
    try {
        q('INSERT INTO error_log (at, method, path, error) VALUES (?,?,?,?)',
            [now_jst(), $kind, mb_substr($path, 0, 480), mb_substr($text, -4000)]);
    } catch (Throwable) {
    }
}
