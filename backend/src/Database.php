<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s;options='--client_encoding=UTF8'", $c['host'], $c['port'], $c['name']);
            self::$pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /** Ejecuta una consulta preparada y devuelve el statement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $name = is_int($k) ? $k + 1 : $k;
            if (is_int($v)) {
                $st->bindValue($name, $v, PDO::PARAM_INT);
            } elseif (is_bool($v)) {
                $st->bindValue($name, $v, PDO::PARAM_BOOL);
            } elseif ($v === null) {
                $st->bindValue($name, null, PDO::PARAM_NULL);
            } else {
                $st->bindValue($name, (string) $v);
            }
        }
        $st->execute();
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return Schema::normalizeRows(self::run($sql, $params)->fetchAll());
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row ? Schema::normalizeRow($row) : null;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Ejecuta $fn dentro de una transacción (rollback automático si lanza excepción). */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
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
}