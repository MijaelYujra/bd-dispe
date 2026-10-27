#!/bin/sh
set -eu

# Crear primero: si el nombre ya existe, createdb falla y no se borra esa base.
test_db=dispe_prueba_instalacion_007
createdb -U "$POSTGRES_USER" "$test_db"
trap 'dropdb -U "$POSTGRES_USER" "$test_db"' EXIT

for file in /docker-entrypoint-initdb.d/*.sql; do
    psql -U "$POSTGRES_USER" -d "$test_db" -v ON_ERROR_STOP=1 -f "$file" > /dev/null
done

# Repetir la migración demuestra que no duplica eventos ni pierde datos.
psql -U "$POSTGRES_USER" -d "$test_db" -v ON_ERROR_STOP=1 \
    -f /docker-entrypoint-initdb.d/07-programacion.sql > /dev/null

psql -U "$POSTGRES_USER" -d "$test_db" -v ON_ERROR_STOP=1 <<'SQL'
DO $$
BEGIN
    ASSERT (SELECT count(*) FROM persona) = 95, 'Carga de personas';
    ASSERT (SELECT count(*) FROM postulante) = 62, 'Carga de postulantes';
    ASSERT (SELECT count(*) FROM inscripcion) = 70, 'Carga de inscripciones';
    ASSERT (SELECT count(*) FROM rinde) = 303, 'Carga de notas';
    ASSERT (SELECT count(*) FROM auditoria_notas) = 0, 'No se crea auditoría retrospectiva';
    ASSERT (SELECT count(*) FROM schema_migrations WHERE version = '007') = 1, 'Migración única';
    ASSERT (SELECT resultado FROM fn_resultado_inscripcion(1)) = 'NO ADMITIDO', 'Regla por materia';
    ASSERT (SELECT count(*) FROM fn_resultados_convocatoria(1)) = 6, 'Resultados por convocatoria';
    ASSERT (SELECT is_identity FROM information_schema.columns WHERE table_schema = 'public'
        AND table_name = 'inscripcion' AND column_name = 'idinscripcion') = 'YES', 'Identidad instalada';
    ASSERT (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid IN ('inscripcion'::regclass, 'rinde'::regclass)) = 3,
        'Tres triggers instalados';
END;
$$;
SQL
echo 'OK: instalación limpia y segunda aplicación de la migración 007.'
