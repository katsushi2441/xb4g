<?php
/** SQLite への接続。 */
declare(strict_types=1);

function giin_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) { return $pdo; }
    $f = __DIR__ . '/../data/giin.sqlite';
    $pdo = new PDO('sqlite:' . $f, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=5000');
    // **レンタルサーバーでは WAL を使わない。** WAL は -wal / -shm をディレクトリに
    // 作るので、書き込み権限が無いと読むだけで readonly エラーになる（heteml で実測）。
    try { $pdo->exec('PRAGMA journal_mode=DELETE'); } catch (PDOException $e) { }
    return $pdo;
}

function g_all(string $sql, array $a = []): array
{
    $st = giin_db()->prepare($sql); $st->execute($a); return $st->fetchAll();
}

function g_one(string $sql, array $a = []): ?array
{
    $st = giin_db()->prepare($sql); $st->execute($a); $r = $st->fetch(); return $r ?: null;
}

function g_val(string $sql, array $a = [])
{
    $st = giin_db()->prepare($sql); $st->execute($a); return $st->fetchColumn();
}

function g_meta(string $k): string
{
    return (string)(g_val('SELECT v FROM meta WHERE k=?', [$k]) ?: '');
}
