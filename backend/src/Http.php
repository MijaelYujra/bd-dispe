<?php
declare(strict_types=1);

final class HttpException extends RuntimeException
{
    public int $status;
    public array $details;

    public function __construct(int $status, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->details = $details;
    }
}

final class Http
{
    public static function json(mixed $data, int $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Los mensajes de PostgreSQL en Windows pueden traer bytes que no son UTF-8;
        // JSON_INVALID_UTF8_SUBSTITUTE evita que json_encode falle y deje la página en blanco.
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json === false ? '{"error":"No se pudo generar la respuesta JSON."}' : $json;
        exit;
    }

    public static function error(int $status, string $message, array $details = [])
    {
        $body = ['error' => $message];
        if ($details) {
            $body['detalles'] = $details;
        }
        self::json($body, $status);
    }

    /** Cuerpo JSON de la petición como arreglo asociativo. */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new HttpException(400, 'El cuerpo debe ser un JSON válido (objeto).');
        }
        return $data;
    }

    public static function cors(): void
    {
        header('Access-Control-Allow-Origin: ' . Config::get('cors_origin'));
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-SQL-Console-Key');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    public static function checkApiKey(): void
    {
        $key = (string) Config::get('api_key');
        if ($key === '') {
            return;
        }
        $sent = $_SERVER['HTTP_X_API_KEY'] ?? '';
        if (!hash_equals($key, $sent)) {
            throw new HttpException(401, 'API key inválida o ausente.');
        }
    }

    public static function checkSqlConsoleKey(): void
    {
        $key = (string) Config::get('sql_console_key');
        if ($key === '') {
            throw new HttpException(503, 'La consola SQL está deshabilitada. Define SQL_CONSOLE_KEY y reinicia el backend.');
        }
        $sent = $_SERVER['HTTP_X_SQL_CONSOLE_KEY'] ?? '';
        if (!hash_equals($key, $sent)) {
            throw new HttpException(401, 'Clave de la consola SQL inválida o ausente.');
        }
    }
}