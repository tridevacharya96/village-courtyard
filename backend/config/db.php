<?php
/**
 * Database connection (PDO, MySQL) — shared singleton.
 *
 * Usage:
 *   $rows = db()->prepare('SELECT * FROM menu_items WHERE category_id = ?');
 *   $row  = db_one('SELECT * FROM users WHERE id = ?', [$id]);
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'),
        env('DB_PORT', '3306'),
        env('DB_NAME', 'village_courtyard')
    );

    try {
        $pdo = new PDO($dsn, (string) env('DB_USER', 'root'), (string) env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,     // real prepared statements
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        // Keep MySQL's NOW() in the same zone as PHP
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    } catch (PDOException $e) {
        error_log('[DB] Connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed.', 500, $e);
    }

    return $pdo;
}

/** Run a prepared query and return the statement. */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch a single row (or null). */
function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Fetch all rows. */
function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

/** Fetch a single column value (or null). */
function db_value(string $sql, array $params = []): mixed
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

/**
 * Insert a row from an associative array. Column names are whitelisted
 * against a strict identifier pattern, values are always bound.
 */
function db_insert(string $table, array $data): int
{
    assert_identifier($table);
    $cols = array_keys($data);
    array_walk($cols, 'assert_identifier');

    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s)',
        $table,
        implode('`, `', $cols),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    db_query($sql, array_values($data));
    return (int) db()->lastInsertId();
}

/** Update rows: db_update('menu_items', ['price' => 299], 'id = ?', [5]) */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    assert_identifier($table);
    $sets = [];
    foreach (array_keys($data) as $col) {
        assert_identifier($col);
        $sets[] = "`$col` = ?";
    }
    $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $where);
    return db_query($sql, [...array_values($data), ...$whereParams])->rowCount();
}

/** Run a callback inside a transaction; rolls back on any exception. */
function db_transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Guard against SQL injection through dynamic table/column names. */
function assert_identifier(string $name): void
{
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/', $name)) {
        throw new InvalidArgumentException("Invalid SQL identifier: $name");
    }
}
