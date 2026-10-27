<?php
declare(strict_types=1);

final class SqlConsole
{
    private const MAX_SQL_LENGTH = 100000;
    private const CURSOR_NAME = 'dispe_sql_console';

    public static function dispatch(string $method): void
    {
        if ($method !== 'POST') {
            throw new HttpException(405, 'La consola SQL solo admite POST.');
        }

        Http::checkSqlConsoleKey();
        $body = Http::body();
        $mode = $body['mode'] ?? '';
        $sql = $body['sql'] ?? null;

        if (!is_string($sql) || trim($sql) === '' || strlen($sql) > self::MAX_SQL_LENGTH) {
            throw new HttpException(422, 'Escribe una sentencia SQL de hasta 100000 caracteres.');
        }
        $statement = self::singleStatement($sql);
        if ($statement === null) {
            throw new HttpException(422, 'Envía una sola sentencia SQL por ejecución.');
        }

        switch ($mode) {
            case 'ddl':
                self::executeDdl($statement);
            case 'select':
                self::executeSelect($statement);
            case 'call':
                self::executeCall($statement);
            case 'cursor':
                self::executeCursor($statement);
            default:
                throw new HttpException(422, 'Selecciona un modo válido: ddl, select o cursor.');
        }
    }

    private static function executeDdl(string $sql): void
    {
        $sql = self::withoutLeadingComments($sql);
        if (!preg_match('/^CREATE\s+(?:(?:OR\s+REPLACE\s+)?(?:FUNCTION|PROCEDURE)|TRIGGER)\b/i', $sql)) {
            throw new HttpException(422, 'El modo DDL solo permite CREATE FUNCTION, CREATE PROCEDURE o CREATE TRIGGER.');
        }

        try {
            Database::pdo()->exec($sql);
        } catch (PDOException $e) {
            throw new HttpException(422, 'PostgreSQL: ' . $e->getMessage());
        }

        Http::json(['modo' => 'ddl', 'mensaje' => 'Objeto PostgreSQL creado correctamente.']);
    }

    private static function executeSelect(string $sql): void
    {
        if (!preg_match('/^SELECT\b/i', self::withoutLeadingComments($sql))) {
            throw new HttpException(422, 'El modo consulta solo permite sentencias SELECT.');
        }

        try {
            $statement = Database::run($sql);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new HttpException(422, 'PostgreSQL: ' . $e->getMessage());
        }

        Http::json([
            'modo' => 'select',
            'columnas' => $rows ? array_keys($rows[0]) : [],
            'data' => Schema::normalizeRows($rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    private static function executeCall(string $sql): void
    {
        if (!preg_match('/^CALL\b/i', self::withoutLeadingComments($sql))) {
            throw new HttpException(422, 'El modo procedimiento solo permite sentencias CALL.');
        }

        try {
            $statement = Database::run($sql);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new HttpException(422, 'PostgreSQL: ' . $e->getMessage());
        }

        Http::json([
            'modo' => 'call',
            'mensaje' => 'Procedimiento ejecutado correctamente.',
            'data' => Schema::normalizeRows($rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    private static function executeCursor(string $sql): void
    {
        if (!preg_match('/^SELECT\b/i', self::withoutLeadingComments($sql))) {
            throw new HttpException(422, 'El modo cursor requiere una consulta SELECT.');
        }

        try {
            $rows = Database::transaction(static function () use ($sql): array {
                Database::pdo()->exec('DECLARE ' . self::CURSOR_NAME . ' NO SCROLL CURSOR FOR ' . $sql);
                $rows = Database::all('FETCH ALL FROM ' . self::CURSOR_NAME);
                Database::run('CLOSE ' . self::CURSOR_NAME);
                return $rows;
            });
        } catch (PDOException $e) {
            throw new HttpException(422, 'PostgreSQL: ' . $e->getMessage());
        }

        Http::json([
            'modo' => 'cursor',
            'cursor' => self::CURSOR_NAME,
            'data' => $rows,
            'meta' => ['total' => count($rows)],
        ]);
    }

    private static function singleStatement(string $sql): ?string
    {
        $length = strlen($sql);
        $quote = null;
        $escapeString = false;
        $dollarTag = null;
        $blockDepth = 0;
        $lineComment = false;
        $terminator = null;
        $hasContent = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                }
                continue;
            }
            if ($blockDepth > 0) {
                if ($char === '/' && $next === '*') {
                    $blockDepth++;
                    $i++;
                } elseif ($char === '*' && $next === '/') {
                    $blockDepth--;
                    $i++;
                }
                continue;
            }
            if ($dollarTag !== null) {
                if (substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                    $i += strlen($dollarTag) - 1;
                    $dollarTag = null;
                }
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    if ($next === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                        $escapeString = false;
                    }
                } elseif ($escapeString && $char === '\\') {
                    $i++;
                }
                continue;
            }
            if ($char === '-' && $next === '-') {
                $lineComment = true;
                $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockDepth = 1;
                $i++;
                continue;
            }
            if ($terminator !== null && !ctype_space($char)) {
                return null;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $escapeString = $char === "'" && self::isEscapeString($sql, $i);
                $hasContent = true;
                continue;
            }
            if ($char === '$' && preg_match('/\G(\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$)/', $sql, $match, 0, $i)) {
                $dollarTag = $match[1];
                $i += strlen($dollarTag) - 1;
                $hasContent = true;
                continue;
            }
            if ($char === ';') {
                if ($terminator !== null) {
                    return null;
                }
                $terminator = $i;
                continue;
            }
            if ($terminator !== null && !ctype_space($char)) {
                return null;
            }
            if (!ctype_space($char)) {
                $hasContent = true;
            }
        }

        if ($quote !== null || $dollarTag !== null || $blockDepth > 0 || !$hasContent) {
            return null;
        }
        $statement = $terminator === null ? $sql : substr($sql, 0, $terminator);
        $statement = trim($statement);
        return $statement === '' ? null : $statement;
    }

    private static function withoutLeadingComments(string $sql): string
    {
        $length = strlen($sql);
        $position = 0;
        while ($position < $length) {
            while ($position < $length && ctype_space($sql[$position])) {
                $position++;
            }
            if (substr($sql, $position, 2) === '--') {
                $lineEnd = strpos($sql, "\n", $position + 2);
                $position = $lineEnd === false ? $length : $lineEnd + 1;
                continue;
            }
            if (substr($sql, $position, 2) !== '/*') {
                break;
            }
            $depth = 1;
            $position += 2;
            while ($position < $length && $depth > 0) {
                if (substr($sql, $position, 2) === '/*') {
                    $depth++;
                    $position += 2;
                } elseif (substr($sql, $position, 2) === '*/') {
                    $depth--;
                    $position += 2;
                } else {
                    $position++;
                }
            }
            if ($depth > 0) {
                return '';
            }
        }
        return substr($sql, $position);
    }

    private static function isEscapeString(string $sql, int $quotePosition): bool
    {
        if ($quotePosition === 0 || !in_array($sql[$quotePosition - 1], ['e', 'E'], true)) {
            return false;
        }
        return $quotePosition < 2 || !preg_match('/[A-Za-z0-9_$]/', $sql[$quotePosition - 2]);
    }
}
