<?php
declare(strict_types=1);

/**
 * Describe las tablas de la base de datos y valida los datos de entrada.
 * PostgreSQL guarda los nombres sin comillas en minúsculas (idpersona);
 * aquí se declaran con su nombre "bonito" (idPersona) y se usan para devolver el JSON.
 */
final class Schema
{
    private static ?array $resources = null;
    private static ?array $keyMap = null;
    private static ?array $decimals = null;

    private const PERSONA_COLS = ['ci', 'nombre', 'paterno', 'materno', 'fecha_nacimiento'];

    public static function resources(): array
    {
        if (self::$resources !== null) {
            return self::$resources;
        }

        $personaJoin = 'JOIN persona p ON p.idpersona = t.%s';
        $personaSel  = 't.*, p.ci, p.nombre, p.paterno, p.materno, p.fecha_nacimiento';

        return self::$resources = [
            'personas' => [
                'table' => 'persona',
                'pk'    => ['idPersona' => 'int'],
                'cols'  => [
                    'ci'               => ['type' => 'bigint', 'required' => true],
                    'nombre'           => ['type' => 'string', 'max' => 100, 'required' => true],
                    'paterno'          => ['type' => 'string', 'max' => 100, 'required' => true],
                    'materno'          => ['type' => 'string', 'max' => 100, 'required' => true],
                    'fecha_nacimiento' => ['type' => 'date', 'required' => true],
                ],
                'search' => ['ci', 'nombre', 'paterno', 'materno'],
            ],
            'facultades' => [
                'table' => 'facultad',
                'pk'    => ['idFacultad' => 'int'],
                'cols'  => [
                    'nombre'    => ['type' => 'string', 'max' => 100, 'required' => true],
                    'ubicacion' => ['type' => 'string', 'max' => 100, 'required' => true],
                ],
                'search' => ['nombre', 'ubicacion'],
            ],
            'materias' => [
                'table' => 'materia',
                'pk'    => ['idMateria' => 'int'],
                'cols'  => [
                    'nombre' => ['type' => 'string', 'max' => 100, 'required' => true],
                ],
                'search' => ['nombre'],
            ],
            'carreras' => [
                'table'  => 'carrera',
                'pk'     => ['idCarrera' => 'int'],
                'cols'   => [
                    'nombre'     => ['type' => 'string', 'max' => 100, 'required' => true],
                    'idFacultad' => ['type' => 'int', 'required' => false],
                ],
                'select' => 't.*, f.nombre AS facultad',
                'from'   => 'carrera t LEFT JOIN facultad f ON f.idfacultad = t.idfacultad',
                'search' => ['t.nombre'],
            ],
            'postulantes' => [
                'table'  => 'postulante',
                'pk'     => ['idPostulante' => 'int'],
                'role'   => true, // su PK es también FK a persona
                'cols'   => [
                    'nacionalidad'       => ['type' => 'string', 'max' => 50, 'required' => true],
                    'grado_estudios'     => ['type' => 'string', 'max' => 50, 'required' => true],
                    'estado_postulacion' => ['type' => 'string', 'max' => 50, 'required' => true],
                ],
                'select' => $personaSel,
                'from'   => 'postulante t ' . sprintf($personaJoin, 'idpostulante'),
                'search' => ['p.ci', 'p.nombre', 'p.paterno', 'p.materno'],
            ],
            'coordinadores' => [
                'table'  => 'coordinador',
                'pk'     => ['idCoordinador' => 'int'],
                'role'   => true,
                'cols'   => [
                    'unidad' => ['type' => 'string', 'max' => 100, 'required' => true],
                ],
                'select' => $personaSel,
                'from'   => 'coordinador t ' . sprintf($personaJoin, 'idcoordinador'),
                'search' => ['p.ci', 'p.nombre', 'p.paterno', 'p.materno', 't.unidad'],
            ],
            'docentes' => [
                'table'  => 'docente',
                'pk'     => ['idDocente' => 'int'],
                'role'   => true,
                'cols'   => [
                    'cargo'     => ['type' => 'string', 'max' => 100, 'required' => true],
                    'idMateria' => ['type' => 'int', 'required' => false],
                ],
                'select' => $personaSel . ', m.nombre AS materia',
                'from'   => 'docente t ' . sprintf($personaJoin, 'iddocente') . ' LEFT JOIN materia m ON m.idmateria = t.idmateria',
                'search' => ['p.ci', 'p.nombre', 'p.paterno', 'p.materno', 't.cargo'],
            ],
            'se-dicta' => [
                'table'  => 'se_dicta',
                'pk'     => ['idCarrera' => 'int', 'idMateria' => 'int'],
                'cols'   => [],
                'select' => 't.*, c.nombre AS carrera, m.nombre AS materia',
                'from'   => 'se_dicta t JOIN carrera c ON c.idcarrera = t.idcarrera JOIN materia m ON m.idmateria = t.idmateria',
                'search' => ['c.nombre', 'm.nombre'],
            ],
            'convocatorias' => [
                'table'  => 'convocatoria',
                'pk'     => ['idConvocatoria' => 'int'],
                'cols'   => [
                    'fecha_inicio'     => ['type' => 'date', 'required' => true],
                    'fecha_fin'        => ['type' => 'date', 'required' => true],
                    'costo_nacional'   => ['type' => 'decimal', 'required' => true],
                    'costo_extranjero' => ['type' => 'decimal', 'required' => true],
                    'idFacultad'       => ['type' => 'int', 'required' => false],
                    'idCoordinador'    => ['type' => 'int', 'required' => false],
                ],
                'select' => "t.*, f.nombre AS facultad, p.nombre || ' ' || p.paterno || ' ' || p.materno AS coordinador",
                'from'   => 'convocatoria t LEFT JOIN facultad f ON f.idfacultad = t.idfacultad '
                          . 'LEFT JOIN persona p ON p.idpersona = t.idcoordinador',
                'search' => ['f.nombre'],
                'validate' => static function (array $d): void {
                    if (isset($d['fecha_inicio'], $d['fecha_fin']) && $d['fecha_fin'] < $d['fecha_inicio']) {
                        throw new HttpException(422, 'fecha_fin no puede ser anterior a fecha_inicio.');
                    }
                },
            ],
            'inscripciones' => [
                'table'  => 'inscripcion',
                'pk'     => ['idInscripcion' => 'int'],
                'cols'   => [
                    'fecha_inscripcion' => ['type' => 'date', 'required' => true],
                    'idPostulante'      => ['type' => 'int', 'required' => true],
                    'idConvocatoria'    => ['type' => 'int', 'required' => true],
                    'idCarrera'         => ['type' => 'int', 'required' => true],
                ],
                'select' => "t.*, p.nombre || ' ' || p.paterno || ' ' || p.materno AS postulante, p.ci, ca.nombre AS carrera",
                'from'   => 'inscripcion t JOIN persona p ON p.idpersona = t.idpostulante '
                          . 'LEFT JOIN carrera ca ON ca.idcarrera = t.idcarrera',
                'search' => ['p.ci', 'p.nombre', 'p.paterno', 'p.materno', 'ca.nombre'],
            ],
            'rinde' => [
                'table'  => 'rinde',
                'pk'     => ['idInscripcion' => 'int', 'idMateria' => 'int'],
                'cols'   => [
                    'nota' => ['type' => 'decimal', 'required' => true, 'min' => 0, 'max_value' => 100],
                ],
                'select' => 't.*, m.nombre AS materia',
                'from'   => 'rinde t JOIN materia m ON m.idmateria = t.idmateria',
                'search' => ['m.nombre'],
            ],
        ];
    }

    public static function resource(string $name): array
    {
        $r = self::resources()[$name] ?? null;
        if ($r === null) {
            throw new HttpException(404, "Recurso '$name' no existe.");
        }
        return $r + ['select' => 't.*', 'from' => $r['table'] . ' t', 'search' => [], 'role' => false];
    }

    public static function personaCols(): array
    {
        return self::PERSONA_COLS;
    }

    public static function personaDef(): array
    {
        return self::resources()['personas']['cols'];
    }

    // ---------------------------------------------------------------- validación

    /**
     * Valida y limpia $input según $cols. Si $partial es true no exige campos obligatorios.
     * Devuelve solo las columnas conocidas.
     */
    public static function validate(array $cols, array $input, bool $partial = false): array
    {
        $out = [];
        $errors = [];
        foreach ($cols as $name => $def) {
            if (!array_key_exists($name, $input)) {
                if (!$partial && ($def['required'] ?? false)) {
                    $errors[$name] = 'Campo obligatorio.';
                }
                continue;
            }
            $v = $input[$name];
            if ($v === null || $v === '') {
                if ($def['required'] ?? false) {
                    $errors[$name] = 'Campo obligatorio.';
                } else {
                    $out[$name] = null;
                }
                continue;
            }
            switch ($def['type']) {
                case 'int':
                case 'bigint':
                    if (!is_int($v) && !(is_string($v) && preg_match('/^-?\d+$/', $v))) {
                        $errors[$name] = 'Debe ser un número entero.';
                        break;
                    }
                    $out[$name] = (int) $v;
                    break;
                case 'decimal':
                    if (!is_numeric($v)) {
                        $errors[$name] = 'Debe ser numérico.';
                        break;
                    }
                    $n = (float) $v;
                    if (isset($def['min']) && $n < $def['min']) {
                        $errors[$name] = "Debe ser >= {$def['min']}.";
                    } elseif (isset($def['max_value']) && $n > $def['max_value']) {
                        $errors[$name] = "Debe ser <= {$def['max_value']}.";
                    } else {
                        $out[$name] = $n;
                    }
                    break;
                case 'date':
                    $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
                    if (!$d || $d->format('Y-m-d') !== $v) {
                        $errors[$name] = 'Fecha inválida; use el formato AAAA-MM-DD.';
                        break;
                    }
                    $out[$name] = $v;
                    break;
                default: // string
                    if (!is_string($v)) {
                        $errors[$name] = 'Debe ser texto.';
                        break;
                    }
                    $v = trim($v);
                    if (isset($def['max']) && mb_strlen($v) > $def['max']) {
                        $errors[$name] = "Máximo {$def['max']} caracteres.";
                        break;
                    }
                    $out[$name] = $v;
            }
        }
        if ($errors) {
            throw new HttpException(422, 'Datos inválidos.', $errors);
        }
        return $out;
    }

    // ---------------------------------------------------------------- nombres de salida

    /** Convierte claves en minúsculas (idpersona) a su forma declarada (idPersona). */
    public static function normalizeRow(array $row): array
    {
        $map = self::keyMap();
        $dec = self::decimalKeys();
        $out = [];
        foreach ($row as $k => $v) {
            if (isset($dec[$k]) && is_string($v) && is_numeric($v)) {
                $v = (float) $v; // PDO entrega NUMERIC como texto
            }
            $out[$map[$k] ?? $k] = $v;
        }
        return $out;
    }

    public static function normalizeRows(array $rows): array
    {
        return array_map([self::class, 'normalizeRow'], $rows);
    }

    private static function decimalKeys(): array
    {
        if (self::$decimals === null) {
            self::$decimals = [];
            foreach (self::resources() as $r) {
                foreach ($r['cols'] as $k => $def) {
                    if ($def['type'] === 'decimal') {
                        self::$decimals[strtolower($k)] = true;
                    }
                }
            }
        }
        return self::$decimals;
    }

    private static function keyMap(): array
    {
        if (self::$keyMap === null) {
            self::$keyMap = [];
            foreach (self::resources() as $r) {
                foreach (array_keys($r['pk']) as $k) {
                    self::$keyMap[strtolower($k)] = $k;
                }
                foreach (array_keys($r['cols']) as $k) {
                    self::$keyMap[strtolower($k)] = $k;
                }
            }
        }
        return self::$keyMap;
    }
}
