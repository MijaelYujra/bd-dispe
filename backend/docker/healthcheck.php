<?php
declare(strict_types=1);

// Comprueba HTTP, PHP y acceso real a los datos, sin mostrar credenciales.
try {
    $context = stream_context_create(['http' => [
        'timeout' => 3,
        'header' => 'X-API-Key: ' . (getenv('API_KEY') ?: '') . "\r\n",
    ]]);
    $body = file_get_contents('http://127.0.0.1/api/personas?limit=1', false, $context);
    $result = json_decode($body === false ? '' : $body, true, 512, JSON_THROW_ON_ERROR);
    exit(isset($result['data'], $result['meta']) ? 0 : 1);
} catch (Throwable $e) {
    exit(1);
}
