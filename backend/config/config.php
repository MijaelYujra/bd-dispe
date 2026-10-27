<?php
declare(strict_types=1);

// Lee variables de entorno; si existe un archivo .env en la raíz, lo carga primero.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        if (getenv($k) === false) {
            putenv($k . '=' . trim($v, " \t\"'"));
        }
    }
}

$env = static fn(string $k, string $default = ''): string => getenv($k) !== false ? (string) getenv($k) : $default;

return [
    'db' => [
        'host'     => $env('DB_HOST', 'localhost'),
        'port'     => $env('DB_PORT', '5432'),
        'name'     => $env('DB_NAME', 'bd_dispe'),
        'user'     => $env('DB_USER', 'postgres'),
        'password' => $env('DB_PASSWORD', ''),
    ],
    // Si se define API_KEY, todas las peticiones deben enviar el encabezado X-API-Key.
    'api_key'      => $env('API_KEY', ''),
    'sql_console_key' => $env('SQL_CONSOLE_KEY', ''),
    'cors_origin'  => $env('CORS_ORIGIN', '*'),
    'debug'        => $env('APP_DEBUG', 'false') === 'true',
    // Nacionalidad que paga el costo nacional (el resto paga costo_extranjero).
    'nacionalidad_local' => $env('NACIONALIDAD_LOCAL', 'Boliviana'),
];
