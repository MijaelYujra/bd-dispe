<?php
declare(strict_types=1);

/** Puente HTTP hacia las rutinas de PostgreSQL de la migración 007. */
final class Academico
{
    public static function catalogo(): array
    {
        return [
            'GET /api/academico/inscripciones/{id}/costo' => 'Función fn_costo_inscripcion',
            'GET /api/academico/inscripciones/{id}/resultado' => 'Función fn_resultado_inscripcion',
            'GET /api/academico/convocatorias/{id}/resultados' => 'Función fn_resultados_convocatoria; filtro opcional idCarrera',
            'POST /api/academico/inscripciones' => 'Procedimiento sp_registrar_inscripcion',
            'POST /api/academico/notas' => 'Procedimiento sp_guardar_nota; inserta o actualiza una nota',
            'GET /api/academico/convocatorias/{id}/resultados-cursor' => 'Procedimiento sp_abrir_resultados y FETCH; filtro opcional idCarrera',
            'GET /api/academico/inscripciones/{id}/historial-notas' => 'Auditoría generada por triggers; admite page y limit (máximo 200)',
        ];
    }

    public static function dispatch(string $method, array $segments, array $query): void
    {
        if ($segments === []) {
            self::method($method, 'GET');
            Http::json(['operaciones' => self::catalogo()]);
        }
        if ($segments === ['inscripciones']) {
            self::method($method, 'POST');
            $body = Http::body();
            self::fields($body, ['idPostulante', 'idConvocatoria', 'idCarrera', 'fecha_inscripcion']);
            $clean = Schema::validate(Schema::resource('inscripciones')['cols'], $body);
            $params = [
                'postulante' => self::id($clean['idPostulante'], 422),
                'convocatoria' => self::id($clean['idConvocatoria'], 422),
                'carrera' => self::id($clean['idCarrera'], 422),
                'fecha' => $clean['fecha_inscripcion'],
            ];
            $record = Database::transaction(static function () use ($params): array {
                $output = Database::one('CALL sp_registrar_inscripcion(:postulante, :convocatoria, :carrera, CAST(:fecha AS date), NULL)', $params);
                return CrudController::show('inscripciones', [$output['p_inscripcion']]);
            });
            Http::json(['data' => $record], 201);
        }
        if ($segments === ['notas']) {
            self::method($method, 'POST');
            $body = Http::body();
            self::fields($body, ['idInscripcion', 'idMateria', 'nota']);
            $clean = Schema::validate(Schema::resource('rinde')['cols'], $body);
            $params = [
                'inscripcion' => self::id($body['idInscripcion'] ?? null, 422),
                'materia' => self::id($body['idMateria'] ?? null, 422),
                'nota' => $clean['nota'],
            ];
            $result = Database::transaction(static function () use ($params): array {
                Database::run('CALL sp_guardar_nota(:inscripcion, :materia, CAST(:nota AS numeric))', $params);
                return [
                    'data' => CrudController::show('rinde', [$params['inscripcion'], $params['materia']]),
                    'evaluacion' => self::resultado($params['inscripcion']),
                ];
            });
            Http::json($result);
        }
        if (count($segments) === 3 && $segments[0] === 'inscripciones'
            && in_array($segments[2], ['costo', 'resultado', 'historial-notas'], true)) {
            self::method($method, 'GET');
            $id = self::id($segments[1]);
            switch ($segments[2]) {
                case 'costo':
                    $cost = Database::scalar('SELECT fn_costo_inscripcion(:id, :local)', [
                        'id' => $id, 'local' => Config::get('nacionalidad_local'),
                    ]);
                    Http::json(['idInscripcion' => $id, 'costo' => (float) $cost]);
                case 'resultado':
                    Http::json(self::resultado($id));
                case 'historial-notas':
                    // La función también distingue inscripción inexistente (404) de historial vacío.
                    self::resultado($id);
                    $page = self::id($query['page'] ?? 1);
                    $limit = min(200, self::id($query['limit'] ?? 50));
                    $total = (int) Database::scalar('SELECT count(*) FROM auditoria_notas WHERE idinscripcion = :id', ['id' => $id]);
                    $rows = Database::all('SELECT * FROM auditoria_notas WHERE idinscripcion = :id
                        ORDER BY id_auditoria LIMIT :limite OFFSET :inicio', [
                        'id' => $id, 'limite' => $limit, 'inicio' => ($page - 1) * $limit,
                    ]);
                    Http::json(['data' => self::numbers($rows), 'meta' => [
                        'page' => $page, 'limit' => $limit, 'total' => $total,
                    ]]);
            }
        }
        if (count($segments) === 3 && $segments[0] === 'convocatorias'
            && in_array($segments[2], ['resultados', 'resultados-cursor'], true)) {
            self::method($method, 'GET');
            $params = ['convocatoria' => self::id($segments[1]),
                'carrera' => isset($query['idCarrera']) ? self::id($query['idCarrera']) : null];
            if ($segments[2] === 'resultados-cursor') {
                $rows = Database::transaction(static function () use ($params): array {
                    // Nombre fijo controlado por el servidor: nunca se interpola un nombre enviado por HTTP.
                    $statement = Database::run("CALL sp_abrir_resultados(:convocatoria, CAST(:carrera AS integer), 'dispe_resultados'::refcursor)", $params);
                    $statement->closeCursor();
                    $rows = Database::all('FETCH ALL FROM dispe_resultados');
                    Database::run('CLOSE dispe_resultados');
                    return $rows;
                });
            } else {
                $rows = Database::all('SELECT * FROM fn_resultados_convocatoria(:convocatoria, CAST(:carrera AS integer))
                    ORDER BY idcarrera, promedio DESC NULLS LAST, idinscripcion', $params);
            }
            Http::json(['data' => self::numbers($rows), 'meta' => ['total' => count($rows)]]);
        }
        throw new HttpException(404, 'Operación académica no encontrada.');
    }

    private static function resultado(int $id): array
    {
        return self::numbers([Database::one('SELECT * FROM fn_resultado_inscripcion(:id)', ['id' => $id])])[0];
    }

    private static function id(mixed $value, int $status = 400): int
    {
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            throw new HttpException($status, 'Los identificadores, page y limit deben ser enteros positivos de hasta 2147483647.');
        }
        return (int) $value;
    }

    private static function fields(array $body, array $allowed): void
    {
        if (array_diff(array_keys($body), $allowed)) {
            throw new HttpException(422, 'El cuerpo contiene campos desconocidos.', ['campos_permitidos' => $allowed]);
        }
    }

    private static function method(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new HttpException(405, "Esta operación requiere $expected.");
        }
    }

    private static function numbers(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['promedio', 'nota_anterior', 'nota_nueva'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = (float) $row[$key];
                }
            }
        }
        return $rows;
    }
}
