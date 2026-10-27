<?php
declare(strict_types=1);

/** Consultas de resumen sobre las inscripciones, notas y recaudación. */
final class Reportes
{
    private const FLOATS = ['promedio', 'nota', 'nota_minima', 'nota_maxima', 'costo', 'recaudacion', 'costo_nacional', 'costo_extranjero'];

    public static function dispatch(string $name, array $ids, array $query): mixed
    {
        return match ($name) {
            'resultados'              => self::resultados($query),
            'boletin'                 => self::boletin($ids),
            'inscritos-por-carrera'   => self::inscritosPorCarrera($query),
            'ingresos-por-convocatoria' => self::ingresos(),
            'estado-postulantes'      => self::estadoPostulantes(),
            'promedio-por-materia'    => self::promedioPorMateria($query),
            default                   => throw new HttpException(404, "Reporte '$name' no existe."),
        };
    }

    public static function catalogo(): array
    {
        return [
            'resultados'                => 'Promedio y posición de cada inscripción (filtros: idConvocatoria, idCarrera, estado)',
            'boletin/{idInscripcion}'   => 'Notas por materia, promedio y costo de una inscripción',
            'inscritos-por-carrera'     => 'Cantidad de inscritos por carrera (filtro: idConvocatoria)',
            'ingresos-por-convocatoria' => 'Inscritos y recaudación por convocatoria',
            'estado-postulantes'        => 'Cantidad de postulantes por estado de postulación',
            'promedio-por-materia'      => 'Estadísticas de notas por materia (filtro: idConvocatoria)',
        ];
    }

    // ----------------------------------------------------------------------

    private static function resultados(array $q): array
    {
        $where = [];
        $p = [];
        foreach (['idConvocatoria' => 'i.idconvocatoria', 'idCarrera' => 'i.idcarrera'] as $param => $col) {
            if (isset($q[$param]) && $q[$param] !== '') {
                $where[] = "$col = :$param";
                $p[$param] = self::int($q[$param], $param);
            }
        }
        if (!empty($q['estado'])) {
            $where[] = 'UPPER(po.estado_postulacion) = UPPER(:estado)';
            $p['estado'] = $q['estado'];
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $rows = Database::all("
            SELECT i.idinscripcion, i.idconvocatoria, i.idcarrera, ca.nombre AS carrera,
                   i.idpostulante, per.ci,
                   per.nombre || ' ' || per.paterno || ' ' || per.materno AS postulante,
                   po.nacionalidad, po.estado_postulacion,
                   COUNT(r.idmateria)        AS materias_rendidas,
                   ROUND(AVG(r.nota), 2)     AS promedio,
                   MIN(r.nota)               AS nota_minima,
                   MAX(r.nota)               AS nota_maxima,
                   RANK() OVER (PARTITION BY i.idconvocatoria, i.idcarrera ORDER BY AVG(r.nota) DESC NULLS LAST) AS posicion
              FROM inscripcion i
              JOIN postulante po  ON po.idpostulante = i.idpostulante
              JOIN persona per    ON per.idpersona   = i.idpostulante
              JOIN carrera ca     ON ca.idcarrera    = i.idcarrera
              LEFT JOIN rinde r   ON r.idinscripcion = i.idinscripcion
              $w
             GROUP BY i.idinscripcion, per.idpersona, po.idpostulante, ca.idcarrera
             ORDER BY i.idconvocatoria, i.idcarrera, posicion, i.idinscripcion", $p);

        return ['data' => self::floats($rows)];
    }

    private static function boletin(array $ids): array
    {
        if (count($ids) !== 1) {
            throw new HttpException(400, 'Use /api/reportes/boletin/{idInscripcion}.');
        }
        $id = self::int($ids[0], 'idInscripcion');

        $ins = Database::one("
            SELECT i.idinscripcion, i.fecha_inscripcion, i.idconvocatoria, i.idcarrera, ca.nombre AS carrera,
                   f.nombre AS facultad, i.idpostulante, per.ci,
                   per.nombre, per.paterno, per.materno, po.nacionalidad, po.grado_estudios, po.estado_postulacion,
                   c.costo_nacional, c.costo_extranjero,
                   CASE WHEN LOWER(po.nacionalidad) = LOWER(:local) THEN c.costo_nacional ELSE c.costo_extranjero END AS costo
              FROM inscripcion i
              JOIN postulante po  ON po.idpostulante = i.idpostulante
              JOIN persona per    ON per.idpersona   = i.idpostulante
              JOIN carrera ca     ON ca.idcarrera    = i.idcarrera
              LEFT JOIN facultad f ON f.idfacultad   = ca.idfacultad
              JOIN convocatoria c ON c.idconvocatoria = i.idconvocatoria
             WHERE i.idinscripcion = :id", ['id' => $id, 'local' => Config::get('nacionalidad_local')]);
        if (!$ins) {
            throw new HttpException(404, 'Inscripción no encontrada.');
        }

        $notas = Database::all("
            SELECT m.idmateria, m.nombre AS materia, r.nota
              FROM se_dicta sd
              JOIN materia m ON m.idmateria = sd.idmateria
              LEFT JOIN rinde r ON r.idmateria = sd.idmateria AND r.idinscripcion = :id
             WHERE sd.idcarrera = :carrera
             ORDER BY m.nombre", ['id' => $id, 'carrera' => $ins['idCarrera']]);
        $notas = self::floats($notas);

        $rendidas = array_values(array_filter(array_column($notas, 'nota'), fn($n) => $n !== null));
        return [
            'inscripcion' => self::floats([$ins])[0],
            'notas'       => $notas,
            'resumen'     => [
                'materias_de_la_carrera' => count($notas),
                'materias_rendidas'      => count($rendidas),
                'promedio'               => $rendidas ? round(array_sum($rendidas) / count($rendidas), 2) : null,
            ],
        ];
    }

    private static function inscritosPorCarrera(array $q): array
    {
        $on = '';
        $p = [];
        if (isset($q['idConvocatoria']) && $q['idConvocatoria'] !== '') {
            $on = 'AND i.idconvocatoria = :c';
            $p['c'] = self::int($q['idConvocatoria'], 'idConvocatoria');
        }
        return ['data' => Database::all("
            SELECT ca.idcarrera, ca.nombre AS carrera, f.nombre AS facultad, COUNT(i.idinscripcion) AS total_inscritos
              FROM carrera ca
              LEFT JOIN facultad f    ON f.idfacultad = ca.idfacultad
              LEFT JOIN inscripcion i ON i.idcarrera = ca.idcarrera $on
             GROUP BY ca.idcarrera, f.nombre
             ORDER BY total_inscritos DESC, ca.nombre", $p)];
    }

    private static function ingresos(): array
    {
        $rows = Database::all("
            SELECT c.idconvocatoria, f.nombre AS facultad, c.fecha_inicio, c.fecha_fin,
                   c.costo_nacional, c.costo_extranjero,
                   COUNT(i.idinscripcion) AS inscritos,
                   COUNT(i.idinscripcion) FILTER (WHERE LOWER(po.nacionalidad) = LOWER(:local)) AS nacionales,
                   COUNT(i.idinscripcion) FILTER (WHERE LOWER(po.nacionalidad) <> LOWER(:local)) AS extranjeros,
                   COALESCE(SUM(CASE WHEN LOWER(po.nacionalidad) = LOWER(:local) THEN c.costo_nacional
                                     ELSE c.costo_extranjero END) FILTER (WHERE i.idinscripcion IS NOT NULL), 0) AS recaudacion
              FROM convocatoria c
              LEFT JOIN facultad f     ON f.idfacultad = c.idfacultad
              LEFT JOIN inscripcion i  ON i.idconvocatoria = c.idconvocatoria
              LEFT JOIN postulante po  ON po.idpostulante = i.idpostulante
             GROUP BY c.idconvocatoria, f.nombre
             ORDER BY c.idconvocatoria", ['local' => Config::get('nacionalidad_local')]);
        $rows = self::floats($rows);
        return ['data' => $rows, 'total_recaudado' => round(array_sum(array_column($rows, 'recaudacion')), 2)];
    }

    private static function estadoPostulantes(): array
    {
        return ['data' => Database::all('
            SELECT estado_postulacion, COUNT(*) AS total FROM postulante GROUP BY estado_postulacion ORDER BY total DESC')];
    }

    private static function promedioPorMateria(array $q): array
    {
        $filtro = '';
        $p = [];
        if (isset($q['idConvocatoria']) && $q['idConvocatoria'] !== '') {
            $filtro = 'WHERE i.idconvocatoria = :c';
            $p['c'] = self::int($q['idConvocatoria'], 'idConvocatoria');
        }
        $rows = Database::all("
            SELECT m.idmateria, m.nombre AS materia, COUNT(x.nota) AS rendidas,
                   ROUND(AVG(x.nota), 2) AS promedio, MIN(x.nota) AS nota_minima, MAX(x.nota) AS nota_maxima
              FROM materia m
              LEFT JOIN (SELECT r.idmateria, r.nota
                           FROM rinde r JOIN inscripcion i ON i.idinscripcion = r.idinscripcion
                           $filtro) x ON x.idmateria = m.idmateria
             GROUP BY m.idmateria
             ORDER BY m.nombre", $p);
        return ['data' => self::floats($rows)];
    }

    // ----------------------------------------------------------------------

    private static function int(mixed $v, string $name): int
    {
        if (!preg_match('/^-?\d+$/', (string) $v)) {
            throw new HttpException(400, "$name debe ser un entero.");
        }
        return (int) $v;
    }

    /** PDO devuelve NUMERIC como texto; los convierte a número para el JSON. */
    private static function floats(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (self::FLOATS as $k) {
                if (array_key_exists($k, $row) && $row[$k] !== null) {
                    $row[$k] = (float) $row[$k];
                }
            }
        }
        return $rows;
    }
}
