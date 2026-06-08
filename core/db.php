<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

function db_run(string $sql, array $p = []): PDOStatement {
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

function db_row(string $sql, array $p = []): ?array {
    return db_run($sql, $p)->fetch() ?: null;
}

function db_rows(string $sql, array $p = []): array {
    return db_run($sql, $p)->fetchAll();
}

function db_insert(string $sql, array $p = []): int {
    db_run($sql, $p);
    return (int)db()->lastInsertId();
}

function db_val(string $sql, array $p = []): mixed {
    $v = db_run($sql, $p)->fetchColumn();
    return $v === false ? null : $v;
}

function db_count(string $sql, array $p = []): int {
    return (int)db_val($sql, $p);
}

function db_transaction(callable $fn): mixed {
    db()->beginTransaction();
    try {
        $r = $fn();
        db()->commit();
        return $r;
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
}
