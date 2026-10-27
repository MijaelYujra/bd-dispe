<?php
declare(strict_types=1);

// Ejecutar: docker compose exec -T backend php tests/smoke.php
// Usa la API real. Crea una persona temporal y la elimina al terminar.
function request(string $method, string $path, ?array $body = null): array
{
    $options = [
        'method' => $method,
        'header' => "Content-Type: application/json\r\nX-API-Key: " . (getenv('API_KEY') ?: '') . "\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ];
    if ($body !== null) {
        $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents('http://127.0.0.1' . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false) {
        throw new RuntimeException("No se pudo consultar $method $path");
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "OK: $message\n";
}

$created = null;
$failed = false;
try {
    [$status, $catalog] = request('GET', '/api');
    check($status === 200 && count($catalog['recursos'] ?? []) === 11, 'Catalogo de las 11 tablas');
    foreach ($catalog['recursos'] as $resource) {
        [$status, $list] = request('GET', '/api/' . $resource . '?limit=2');
        check($status === 200 && isset($list['data'], $list['meta']), "Consulta de $resource");
    }
    foreach (['resultados?idConvocatoria=1', 'boletin/1', 'inscritos-por-carrera',
        'ingresos-por-convocatoria', 'estado-postulantes', 'promedio-por-materia'] as $report) {
        [$status, $result] = request('GET', '/api/reportes/' . $report);
        check($status === 200 && !isset($result['error']), "Reporte $report");
    }
    [$status, $notes] = request('GET', '/api/inscripciones/1/notas');
    check($status === 200 && count($notes['data'] ?? []) === 5, 'Ruta anidada de notas');

    $person = [
        'ci' => random_int(800000000, 899999999),
        'nombre' => 'Prueba Docker', 'paterno' => 'Temporal', 'materno' => 'Integracion',
        'fecha_nacimiento' => '2007-05-10', 'nacionalidad' => 'Boliviana',
        'grado_estudios' => 'BACHILLER', 'estado_postulacion' => 'EN PROCESO',
    ];
    [$status, $record] = request('POST', '/api/postulantes', $person);
    if (isset($record['idPostulante'])) {
        $created = $record['idPostulante'];
    }
    check($status === 201 && $created !== null, 'Crear persona y postulante en una transaccion');
    [$status, $record] = request('PATCH', '/api/postulantes/' . $created, ['estado_postulacion' => 'ADMITIDO']);
    check($status === 200 && $record['estado_postulacion'] === 'ADMITIDO', 'Actualizar postulante');
    [$status] = request('POST', '/api/postulantes', $person);
    check($status === 409, 'Rechazar CI duplicado');
    [$status] = request('POST', '/api/rinde', ['idInscripcion' => 1, 'idMateria' => 1, 'nota' => 101]);
    check($status === 422, 'Rechazar nota fuera de rango');
    [$status] = request('GET', '/api/personas/999999999');
    check($status === 404, 'Responder 404 para un registro inexistente');
} catch (Throwable $e) {
    fwrite(STDERR, 'FALLO: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    if ($created !== null) {
        try {
            [$status] = request('DELETE', '/api/postulantes/' . $created . '?con_persona=true');
            check($status === 204, 'Eliminar el postulante temporal y su persona');
            [$status] = request('GET', '/api/personas/' . $created);
            check($status === 404, 'Confirmar limpieza de la persona temporal');
        } catch (Throwable $e) {
            fwrite(STDERR, 'FALLO DE LIMPIEZA: ' . $e->getMessage() . "\n");
            $failed = true;
        }
    }
}
exit($failed ? 1 : 0);
