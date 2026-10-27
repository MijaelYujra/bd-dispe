<?php
declare(strict_types=1);

// Ejecutar dentro del contenedor: php tests/academico.php
foreach (['Config', 'Http', 'Schema', 'Database'] as $class) {
    require_once dirname(__DIR__) . '/src/' . $class . '.php';
}
$checks = 0;
function academicCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
    echo "OK: $message\n";
}
function academicRequest(string $method, string $path, ?array $body = null): array
{
    $options = ['method' => $method, 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Content-Type: application/json\r\nX-API-Key: " . (getenv('API_KEY') ?: '') . "\r\n"];
    if ($body !== null) {
        $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents('http://127.0.0.1' . $path, false, stream_context_create(['http' => $options]));
    if ($raw === false) {
        throw new RuntimeException("No respondió $method $path");
    }
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return [(int) ($match[1] ?? 0), $raw === '' ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function academicStatus(int $expected, string $method, string $path, ?array $body = null): mixed
{
    [$status, $result] = academicRequest($method, $path, $body);
    academicCheck($status === $expected, "$method $path => $expected (recibido $status) " . ($result['error'] ?? ''));
    return $result;
}
function academicSqlError(string $sql, array $params, string $state, string $message): void
{
    Database::run('SAVEPOINT caso_invalido');
    $actual = null;
    try {
        Database::run($sql, $params);
    } catch (PDOException $e) {
        $actual = $e->errorInfo[0] ?? $e->getCode();
    } finally {
        Database::run('ROLLBACK TO SAVEPOINT caso_invalido');
        Database::run('RELEASE SAVEPOINT caso_invalido');
    }
    academicCheck($actual === $state, $message . " (SQLSTATE $actual)");
}

$postulante = null;
$inscripcion = null;
$legacyInscripcion = null;
$materias = [];
$failed = false;
try {
    $catalog = academicStatus(200, 'GET', '/api/academico');
    academicCheck(count($catalog['operaciones']) === 7, 'Catalogo académico completo');
    $cost = academicStatus(200, 'GET', '/api/academico/inscripciones/1/costo');
    academicCheck($cost['costo'] == 1385, 'Costo extranjero calculado por función PostgreSQL');
    $seed = academicStatus(200, 'GET', '/api/academico/inscripciones/1/resultado');
    academicCheck($seed['resultado'] === 'NO ADMITIDO' && $seed['promedio'] == 69.4,
        'Un promedio alto no aprueba una inscripción con una materia inferior a 51');
    $function = academicStatus(200, 'GET', '/api/academico/convocatorias/1/resultados');
    $cursor = academicStatus(200, 'GET', '/api/academico/convocatorias/1/resultados-cursor');
    academicCheck($function === $cursor && count($cursor['data']) === 6, 'Cursor y función devuelven los mismos seis resultados');
    $filtered = academicStatus(200, 'GET', '/api/academico/convocatorias/1/resultados-cursor?idCarrera=14');
    academicCheck(count($filtered['data']) === 1 && $filtered['data'][0]['idCarrera'] === 14, 'Filtro de carrera aplicado dentro del cursor');
    academicStatus(404, 'GET', '/api/academico/convocatorias/999999999/resultados-cursor');
    academicStatus(404, 'GET', '/api/academico/inscripciones/999999999/resultado');
    academicStatus(400, 'GET', '/api/academico/convocatorias/1/resultados-cursor?idCarrera=1%3BDROP');
    academicStatus(405, 'GET', '/api/academico/notas');

    $created = academicStatus(201, 'POST', '/api/postulantes', [
        'ci' => random_int(800000000, 899999999), 'nombre' => 'Prueba Academica',
        'paterno' => 'Temporal', 'materno' => 'PostgreSQL', 'fecha_nacimiento' => '2007-05-10',
        'nacionalidad' => 'Boliviana', 'grado_estudios' => 'BACHILLER', 'estado_postulacion' => 'EN PROCESO',
    ]);
    $postulante = $created['idPostulante'];
    $input = ['idPostulante' => $postulante, 'idConvocatoria' => 1, 'idCarrera' => 14, 'fecha_inscripcion' => '2025-12-01'];
    $created = academicStatus(201, 'POST', '/api/academico/inscripciones', $input);
    $inscripcion = $created['data']['idInscripcion'];
    academicCheck(is_int($inscripcion) && $inscripcion > 70, 'Procedimiento devuelve un id generado por IDENTITY');
    $legacy = academicStatus(201, 'POST', '/api/inscripciones', array_replace($input, [
        'idConvocatoria' => 3, 'fecha_inscripcion' => '2026-06-01',
    ]));
    $legacyInscripcion = $legacy['idInscripcion'];
    academicCheck($legacyInscripcion > $inscripcion, 'La ruta CRUD anterior también usa la secuencia de PostgreSQL');
    academicStatus(204, 'DELETE', "/api/inscripciones/$legacyInscripcion");
    $legacyInscripcion = null;
    $localCost = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/costo");
    academicCheck($localCost['costo'] == 650, 'Costo nacional calculado por función');
    academicStatus(409, 'POST', '/api/academico/inscripciones', $input);
    academicStatus(422, 'POST', '/api/academico/inscripciones', array_replace($input, ['fecha_inscripcion' => '2026-01-01']));
    academicStatus(422, 'POST', '/api/academico/inscripciones', array_replace($input, ['idCarrera' => 28]));
    academicStatus(422, 'POST', '/api/academico/inscripciones', array_replace($input, ['idPostulante' => 999999999]));
    academicStatus(422, 'PATCH', "/api/inscripciones/$inscripcion", ['fecha_inscripcion' => '2026-01-01']);
    $initial = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/resultado");
    academicCheck($initial['resultado'] === 'EN PROCESO' && $initial['materias_rendidas'] === 0, 'Inscripción sin notas queda en proceso');

    $list = academicStatus(200, 'GET', '/api/carreras/14/materias');
    $materias = array_column($list['data'], 'idMateria');
    foreach ($materias as $index => $materia) {
        $note = academicStatus(200, 'POST', '/api/academico/notas', [
            'idInscripcion' => $inscripcion, 'idMateria' => $materia, 'nota' => $index === 0 ? 47 : 90,
        ]);
    }
    academicCheck($note['evaluacion']['resultado'] === 'NO ADMITIDO', 'Una sola materia reprobada impide aprobar');
    $fixed = academicStatus(200, 'POST', '/api/academico/notas', [
        'idInscripcion' => $inscripcion, 'idMateria' => $materias[0], 'nota' => 51,
    ]);
    academicCheck($fixed['evaluacion']['resultado'] === 'ADMITIDO', 'Todas las materias con nota mínima 51 permiten aprobar');
    $history = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/historial-notas");
    academicCheck($history['meta']['total'] === count($materias) + 1, 'Auditoría registra INSERT y UPDATE');
    $last = $history['data'][array_key_last($history['data'])];
    academicCheck($last['nota_anterior'] == 47 && $last['nota_nueva'] == 51, 'Auditoría conserva nota anterior y nueva');
    academicStatus(200, 'POST', '/api/academico/notas', ['idInscripcion' => $inscripcion, 'idMateria' => $materias[0], 'nota' => 51]);
    $same = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/historial-notas");
    academicCheck($same['meta']['total'] === $history['meta']['total'], 'Guardar la misma nota no genera un cambio ficticio');
    academicStatus(422, 'POST', '/api/academico/notas', ['idInscripcion' => $inscripcion, 'idMateria' => $materias[0], 'nota' => 101]);
    $all = academicStatus(200, 'GET', '/api/materias');
    $outside = array_values(array_diff(array_column($all['data'], 'idMateria'), $materias))[0];
    academicStatus(422, 'POST', '/api/academico/notas', ['idInscripcion' => $inscripcion, 'idMateria' => $outside, 'nota' => 80]);
    academicStatus(422, 'POST', '/api/rinde', ['idInscripcion' => $inscripcion, 'idMateria' => $outside, 'nota' => 80]);
    $person = academicStatus(200, 'GET', "/api/postulantes/$postulante");
    academicCheck($person['estado_postulacion'] === 'EN PROCESO', 'El resultado por inscripción no sobrescribe el estado histórico general');
    academicStatus(204, 'DELETE', "/api/rinde/$inscripcion/{$materias[0]}");
    $pending = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/resultado");
    academicCheck($pending['resultado'] === 'EN PROCESO' && $pending['materias_pendientes'] === 1, 'Eliminar una nota vuelve a dejar una materia pendiente');
    $history = academicStatus(200, 'GET', "/api/academico/inscripciones/$inscripcion/historial-notas?limit=1&page=2");
    academicCheck(count($history['data']) === 1 && $history['meta']['page'] === 2, 'Paginación del historial');
} catch (Throwable $e) {
    fwrite(STDERR, 'FALLO HTTP: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    try {
        if ($legacyInscripcion !== null) {
            academicStatus(204, 'DELETE', "/api/inscripciones/$legacyInscripcion");
        }
        if ($inscripcion !== null) {
            foreach ($materias as $materia) {
                [$status] = academicRequest('DELETE', "/api/rinde/$inscripcion/$materia");
                if (!in_array($status, [204, 404], true)) {
                    throw new RuntimeException('No se pudo limpiar una nota temporal.');
                }
            }
            academicStatus(204, 'DELETE', "/api/inscripciones/$inscripcion");
            academicCheck((int) Database::scalar('SELECT count(*) FROM auditoria_notas WHERE idinscripcion = ?', [$inscripcion]) > 0,
                'El historial permanece después de borrar la inscripción temporal');
        }
        if ($postulante !== null) {
            academicStatus(204, 'DELETE', "/api/postulantes/$postulante?con_persona=true");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'FALLO LIMPIEZA: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

// Las pruebas SQL directas se revierten completas. Demuestran que los triggers
// funcionan sin depender de las validaciones del controlador PHP.
$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    Database::run('LOCK TABLE persona IN EXCLUSIVE MODE');
    $person = (int) Database::scalar('SELECT coalesce(max(idpersona), 0) + 1 FROM persona');
    Database::run("INSERT INTO persona VALUES (:id, :ci, 'Prueba SQL', 'Temporal', 'Rollback', DATE '2007-05-10')",
        ['id' => $person, 'ci' => random_int(800000000, 899999999)]);
    Database::run("INSERT INTO postulante VALUES (?, 'Boliviana', 'BACHILLER', 'EN PROCESO')", [$person]);
    $output = Database::one("CALL sp_registrar_inscripcion(?, 1, 14, DATE '2025-12-01', NULL)", [$person]);
    $id = $output['p_inscripcion'];
    $subject = (int) Database::scalar('SELECT min(idmateria) FROM se_dicta WHERE idcarrera = 14');
    $outside = (int) Database::scalar('SELECT min(idmateria) FROM materia WHERE idmateria NOT IN (SELECT idmateria FROM se_dicta WHERE idcarrera = 14)');
    academicSqlError("INSERT INTO inscripcion (idpostulante, idconvocatoria, idcarrera, fecha_inscripcion) VALUES (?, 1, 14, DATE '2026-01-01')", [$person], '23514', 'Trigger rechaza fecha inválida mediante SQL directo');
    academicSqlError("INSERT INTO inscripcion (idpostulante, idconvocatoria, idcarrera, fecha_inscripcion) VALUES (?, 1, 28, DATE '2025-12-01')", [$person], '23514', 'Trigger rechaza facultad distinta mediante SQL directo');
    academicSqlError("CALL sp_registrar_inscripcion(?, 1, 14, DATE '2025-12-01', NULL)", [$person], '23505', 'Índice único impide inscripciones duplicadas');
    foreach (['-1', '101', 'NaN', 'Infinity', '-Infinity'] as $invalid) {
        academicSqlError('CALL sp_guardar_nota(?, ?, CAST(? AS numeric))', [$id, $subject, $invalid], '23514', "Trigger rechaza nota $invalid sin PHP");
    }
    academicSqlError('INSERT INTO rinde VALUES (?, ?, 80)', [$id, $outside], '23514', 'Trigger rechaza materia ajena mediante SQL directo');
    academicCheck((int) Database::scalar('SELECT count(*) FROM auditoria_notas WHERE idinscripcion = ?', [$id]) === 0,
        'Operaciones rechazadas no generan auditoría');
    Database::run('CALL sp_guardar_nota(?, ?, 0)', [$id, $subject]);
    Database::run('CALL sp_guardar_nota(?, ?, 100)', [$id, $subject]);
    academicCheck(Database::scalar('SELECT nota FROM rinde WHERE idinscripcion = ? AND idmateria = ?', [$id, $subject]) == 100,
        'Los límites 0 y 100 son válidos');
    Database::run('SAVEPOINT cambio_revertido');
    Database::run('CALL sp_guardar_nota(?, ?, 75)', [$id, $subject]);
    Database::run('ROLLBACK TO SAVEPOINT cambio_revertido');
    academicCheck((int) Database::scalar('SELECT count(*) FROM auditoria_notas WHERE idinscripcion = ?', [$id]) === 2,
        'Revertir una nota también revierte su auditoría');
    Database::run("CALL sp_abrir_resultados(1, NULL, 'prueba_cursor'::refcursor)")->closeCursor();
    academicCheck(count(Database::all('FETCH ALL FROM prueba_cursor')) === 7, 'Cursor SQL lee seis inscripciones originales y la temporal');
    Database::run('CLOSE prueba_cursor');
    academicCheck((int) Database::scalar("SELECT count(*) FROM pg_cursors WHERE name = 'prueba_cursor'") === 0, 'CLOSE libera el cursor en la misma conexión');
} catch (Throwable $e) {
    fwrite(STDERR, 'FALLO SQL: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    $pdo->rollBack();
}
echo "Comprobaciones académicas correctas: $checks\n";
exit($failed ? 1 : 0);
