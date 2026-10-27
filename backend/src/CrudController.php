<?php
declare(strict_types=1);

/** CRUD genérico guiado por la definición de Schema::resources(). */
final class CrudController
{
    private const MAX_LIMIT = 200;

    // ------------------------------------------------------------------ listar

    public static function index(string $res, array $query): array
    {
        $r = Schema::resource($res);
        [$where, $params] = self::filters($r, $query);

        $page  = max(1, (int) ($query['page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) ($query['limit'] ?? 50)));
        $order = self::orderBy($r, $query);

        $total = (int) Database::scalar("SELECT COUNT(*) FROM {$r['from']} $where", $params);
        $rows  = Database::all(
            "SELECT {$r['select']} FROM {$r['from']} $where ORDER BY $order LIMIT $limit OFFSET " . (($page - 1) * $limit),
            $params
        );

        return [
            'data' => $rows,
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'paginas' => (int) ceil($total / $limit)],
        ];
    }

    // ------------------------------------------------------------------ obtener uno

    public static function show(string $res, array $ids): array
    {
        $r = Schema::resource($res);
        [$where, $params] = self::pkWhere($r, $ids);
        $row = Database::one("SELECT {$r['select']} FROM {$r['from']} WHERE $where", $params);
        if (!$row) {
            throw new HttpException(404, 'Registro no encontrado.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ crear

    public static function create(string $res, array $body): array
    {
        $r = Schema::resource($res);

        $ids = Database::transaction(function () use ($r, $body) {
            $pkNames = array_keys($r['pk']);
            $data = $body;

            // Roles (postulante/docente/coordinador): crean primero la persona si vienen sus datos.
            if ($r['role']) {
                $personaData = self::extractPersona($data);
                if ($personaData) {
                    $p = Schema::validate(Schema::personaDef(), $personaData);
                    $personaId = self::insertRow(
                        Schema::resource('personas'),
                        $p,
                        isset($data['idPersona']) ? (int) $data['idPersona'] : (isset($data[$pkNames[0]]) ? (int) $data[$pkNames[0]] : null)
                    );
                    $data[$pkNames[0]] = $personaId[$pkNames[0]] ?? $personaId['idPersona'];
                } else {
                    if (!isset($data[$pkNames[0]])) {
                        throw new HttpException(422, "Envíe los datos de la persona o el campo {$pkNames[0]} de una persona existente.");
                    }
                    $exists = Database::scalar('SELECT 1 FROM persona WHERE idpersona = ?', [(int) $data[$pkNames[0]]]);
                    if (!$exists) {
                        throw new HttpException(422, "No existe una persona con id {$data[$pkNames[0]]}.");
                    }
                }
            }

            $clean = Schema::validate($r['cols'], $data);
            if (isset($r['validate'])) {
                ($r['validate'])($clean);
            }

            $explicit = [];
            foreach ($pkNames as $k) {
                if (isset($data[$k])) {
                    $explicit[$k] = (int) $data[$k];
                }
            }
            return self::insertRow($r, $clean, count($pkNames) === 1 ? ($explicit[$pkNames[0]] ?? null) : null, $explicit);
        });

        return self::show($res, array_values($ids));
    }

    // ------------------------------------------------------------------ actualizar

    public static function update(string $res, array $ids, array $body): array
    {
        $r = Schema::resource($res);
        if (!$r['cols'] && !$r['role']) {
            throw new HttpException(405, 'Este recurso no tiene campos editables; elimine y vuelva a crear el registro.');
        }
        $current = self::show($res, $ids); // 404 si no existe

        Database::transaction(function () use ($r, $ids, $body, $current) {
            $data = $body;
            if ($r['role']) {
                $personaData = self::extractPersona($data);
                if ($personaData) {
                    $p = Schema::validate(Schema::personaDef(), $personaData, true);
                    self::updateRow(Schema::resource('personas'), [(int) array_values($ids)[0]], $p);
                }
            }
            $clean = Schema::validate($r['cols'], $data, true);
            if (isset($r['validate'])) {
                ($r['validate'])($clean + array_intersect_key($current, $r['cols']));
            }
            if ($clean) {
                self::updateRow($r, $ids, $clean);
            }
        });

        return self::show($res, $ids);
    }

    // ------------------------------------------------------------------ eliminar

    public static function delete(string $res, array $ids, array $query = []): void
    {
        $r = Schema::resource($res);
        self::show($res, $ids); // 404 si no existe

        Database::transaction(function () use ($r, $ids, $query) {
            [$where, $params] = self::pkWhere($r, $ids, false);
            Database::run("DELETE FROM {$r['table']} WHERE $where", $params);
            if ($r['role'] && filter_var($query['con_persona'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                Database::run('DELETE FROM persona WHERE idpersona = ?', [(int) array_values($ids)[0]]);
            }
        });
    }

    // ------------------------------------------------------------------ utilidades

    /** Inserta una fila; genera el id (MAX+1 bajo bloqueo) si la PK es simple y no viene. */
    private static function insertRow(array $r, array $clean, ?int $id, array $explicitPk = []): array
    {
        $pkNames = array_keys($r['pk']);
        $cols = $clean;

        // La migración 007 usa IDENTITY para no reutilizar ids con auditoría histórica.
        if ($r['table'] === 'inscripcion') {
            if ($id !== null) {
                throw new HttpException(422, 'El identificador de inscripción lo genera PostgreSQL; no envíe idInscripcion.');
            }
            $names = array_keys($cols);
            $sql = 'INSERT INTO inscripcion (' . implode(', ', $names) . ') VALUES ('
                . implode(', ', array_map(fn($name) => ':' . $name, $names)) . ') RETURNING idinscripcion';
            return Database::one($sql, $cols);
        }

        if (count($pkNames) === 1) {
            if ($id === null) {
                Database::run("LOCK TABLE {$r['table']} IN EXCLUSIVE MODE");
                $id = (int) Database::scalar("SELECT COALESCE(MAX({$pkNames[0]}), 0) + 1 FROM {$r['table']}");
            }
            $cols = [$pkNames[0] => $id] + $cols;
        } else {
            foreach ($pkNames as $k) {
                if (!isset($explicitPk[$k])) {
                    throw new HttpException(422, 'Datos inválidos.', [$k => 'Campo obligatorio.']);
                }
            }
            $cols = $explicitPk + $cols;
        }

        $names = array_keys($cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $r['table'],
            implode(', ', $names),
            implode(', ', array_map(fn($n) => ':' . $n, $names))
        );
        Database::run($sql, $cols);

        return array_intersect_key($cols, array_flip($pkNames));
    }

    private static function updateRow(array $r, array $ids, array $clean): void
    {
        if (!$clean) {
            return;
        }
        $sets = implode(', ', array_map(fn($c) => "$c = :v_$c", array_keys($clean)));
        $params = [];
        foreach ($clean as $c => $v) {
            $params["v_$c"] = $v;
        }
        [$where, $wParams] = self::pkWhere($r, $ids, false);
        Database::run("UPDATE {$r['table']} SET $sets WHERE $where", $params + $wParams);
    }

    /** Separa los campos de persona del cuerpo (planos o dentro de "persona"). */
    private static function extractPersona(array &$data): array
    {
        $persona = [];
        if (isset($data['persona']) && is_array($data['persona'])) {
            $persona = $data['persona'];
            unset($data['persona']);
        }
        foreach (Schema::personaCols() as $c) {
            if (array_key_exists($c, $data)) {
                $persona[$c] = $data[$c];
                unset($data[$c]);
            }
        }
        return $persona;
    }

    private static function pkWhere(array $r, array $ids, bool $alias = true): array
    {
        $names = array_keys($r['pk']);
        if (count($ids) !== count($names)) {
            throw new HttpException(400, 'Identificador incompleto: se esperan ' . count($names) . ' valores (' . implode('/', $names) . ').');
        }
        $parts = [];
        $params = [];
        foreach ($names as $i => $n) {
            if (!preg_match('/^-?\d+$/', (string) $ids[$i])) {
                throw new HttpException(400, "El identificador $n debe ser un entero.");
            }
            $parts[] = ($alias ? 't.' : '') . "$n = :pk$i";
            $params["pk$i"] = (int) $ids[$i];
        }
        return [implode(' AND ', $parts), $params];
    }

    /** WHERE a partir de ?q=texto y ?columna=valor (solo columnas conocidas). */
    private static function filters(array $r, array $query): array
    {
        $conds = [];
        $params = [];

        if (!empty($query['q']) && $r['search']) {
            $ors = [];
            foreach ($r['search'] as $i => $col) {
                $ors[] = "CAST($col AS TEXT) ILIKE :q$i";
                $params["q$i"] = '%' . $query['q'] . '%';
            }
            $conds[] = '(' . implode(' OR ', $ors) . ')';
        }

        $known = array_merge(array_keys($r['pk']), array_keys($r['cols']));
        $lower = [];
        foreach ($known as $c) {
            $lower[strtolower($c)] = "t.$c";
        }
        if ($r['role']) {
            foreach (Schema::personaCols() as $c) {
                $lower[strtolower($c)] = "p.$c";
            }
        }
        foreach ($query as $k => $v) {
            $key = strtolower((string) $k);
            if (isset($lower[$key]) && is_scalar($v) && $v !== '') {
                $conds[] = "CAST({$lower[$key]} AS TEXT) = :f_$key";
                $params["f_$key"] = (string) $v;
            }
        }
        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $params];
    }

    private static function orderBy(array $r, array $query): string
    {
        $default = implode(', ', array_map(fn($c) => "t.$c", array_keys($r['pk'])));
        $sort = strtolower((string) ($query['sort'] ?? ''));
        if ($sort === '') {
            return $default;
        }
        $allowed = [];
        foreach (array_merge(array_keys($r['pk']), array_keys($r['cols'])) as $c) {
            $allowed[strtolower($c)] = "t.$c";
        }
        if ($r['role']) {
            foreach (Schema::personaCols() as $c) {
                $allowed[strtolower($c)] = "p.$c";
            }
        }
        if (!isset($allowed[$sort])) {
            throw new HttpException(400, 'Campo de orden no válido: ' . $sort);
        }
        $dir = strtolower((string) ($query['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
        return "{$allowed[$sort]} $dir";
    }
}
