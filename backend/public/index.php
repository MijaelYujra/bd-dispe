<?php
declare(strict_types=1);

// Servidor integrado de PHP: devuelve archivos estáticos si existen.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$root = dirname(__DIR__);
foreach (['Config', 'Http', 'Schema', 'Database', 'CrudController', 'Reportes', 'Academico', 'SqlConsole'] as $c) {
    require_once "$root/src/$c.php";
}

Http::cors();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    Http::checkApiKey();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $pos = strpos($path, '/api');
    $rest = $pos === false ? '' : substr($path, $pos + 4);
    $seg = array_values(array_filter(explode('/', $rest), fn($s) => $s !== ''));
    $method = $_SERVER['REQUEST_METHOD'];
    $query = $_GET;

    // Rutas anidadas de consulta: /api/{padre}/{id}/{hijo}
    $nested = [
        'inscripciones' => ['notas' => ['rinde', 'idInscripcion']],
        'carreras'      => ['materias' => ['se-dicta', 'idCarrera'], 'inscripciones' => ['inscripciones', 'idCarrera']],
        'facultades'    => ['carreras' => ['carreras', 'idFacultad'], 'convocatorias' => ['convocatorias', 'idFacultad']],
        'convocatorias' => ['inscripciones' => ['inscripciones', 'idConvocatoria']],
        'postulantes'   => ['inscripciones' => ['inscripciones', 'idPostulante']],
        'materias'      => ['carreras' => ['se-dicta', 'idMateria']],
    ];

    if (!$seg) {
        Http::json([
            'api'       => 'Backend del examen de dispensación',
            'recursos'  => array_keys(Schema::resources()),
            'reportes'  => Reportes::catalogo(),
            'operaciones_academicas' => Academico::catalogo(),
            'ejemplos'  => ['GET /api/postulantes?limit=10&q=garcia', 'GET /api/reportes/resultados?idConvocatoria=1'],
        ]);
    }

    $res = array_shift($seg);

    if ($res === 'academico') {
        Academico::dispatch($method, $seg, $query);
    }

    if ($res === 'sql-console' && $seg === []) {
        SqlConsole::dispatch($method);
    }

    if ($res === 'reportes') {
        if ($method !== 'GET') {
            throw new HttpException(405, 'Los reportes solo admiten GET.');
        }
        $name = array_shift($seg) ?? '';
        Http::json(Reportes::dispatch($name, $seg, $query));
    }

    $pkCount = count(Schema::resource($res)['pk']);
    $ids = array_slice($seg, 0, $pkCount);
    $extra = array_slice($seg, $pkCount);

    if ($extra) {
        $sub = $extra[0];
        $target = $nested[$res][$sub] ?? null;
        if ($target === null || $method !== 'GET' || count($ids) !== $pkCount) {
            throw new HttpException(404, 'Ruta no encontrada.');
        }
        Http::json(CrudController::index($target[0], [$target[1] => $ids[0]] + $query));
    }

    switch ($method) {
        case 'GET':
            Http::json($ids ? CrudController::show($res, $ids) : CrudController::index($res, $query));
        case 'POST':
            if ($ids) {
                throw new HttpException(405, 'Use POST sobre la colección, sin identificador.');
            }
            Http::json(CrudController::create($res, Http::body()), 201);
        case 'PUT':
        case 'PATCH':
            if (count($ids) !== $pkCount) {
                throw new HttpException(400, 'Falta el identificador del registro.');
            }
            Http::json(CrudController::update($res, $ids, Http::body()));
        case 'DELETE':
            if (count($ids) !== $pkCount) {
                throw new HttpException(400, 'Falta el identificador del registro.');
            }
            CrudController::delete($res, $ids, $query);
            http_response_code(204);
            exit;
        default:
            throw new HttpException(405, 'Método no permitido.');
    }
} catch (HttpException $e) {
    Http::error($e->status, $e->getMessage(), $e->details);
} catch (PDOException $e) {
    $state = (string) ($e->errorInfo[0] ?? '');
    $detail = Config::get('debug') ? ['sql' => $e->getMessage()] : [];
    switch ($state) {
        case 'P0002':
        case '23514':
            // Solo el mensaje principal; el contexto SQL se muestra únicamente en modo debug.
            preg_match('/ERROR:\s*([^\r\n]+)/', (string) ($e->errorInfo[2] ?? ''), $message);
            Http::error($state === 'P0002' ? 404 : 422, $message[1] ?? 'La operación no cumple las reglas académicas.', $detail);
        case '23505':
            Http::error(409, 'Ya existe un registro con ese valor único (por ejemplo, el CI o la clave).', $detail);
        case '23503':
            if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'PATCH'], true)) {
                Http::error(422, 'Una de las referencias (idPostulante, idCarrera, idConvocatoria, idMateria, etc.) no existe.', $detail);
            }
            Http::error(409, 'No se puede eliminar: el registro tiene datos asociados en otras tablas.', $detail);
        case '23502':
        case '22P02':
        case '22007':
        case '22008':
        case '22003':
        case '428C9':
            Http::error(422, 'Valor inválido para la base de datos.', $detail);
        default:
            error_log($e->getMessage());
            Http::error(500, 'Error de base de datos.', $detail);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    Http::error(500, 'Error interno del servidor.', Config::get('debug') ? ['mensaje' => $e->getMessage()] : []);
}
