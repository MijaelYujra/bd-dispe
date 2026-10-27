# Funciones procedimientos cursores y triggers del sistema DISPE

Esta guía explica la programación añadida a PostgreSQL, cómo la llama la API PHP y cómo demostrar cada operación. El archivo `db/07-programacion.sql` instala las rutinas y los triggers. Los seis SQL anteriores conservan los datos del grupo.

## 1. Qué significa cada elemento

| Elemento | Qué hace en este proyecto | Cómo se ejecuta |
|---|---|---|
| Función | Calcula un costo o devuelve los resultados de una inscripción o convocatoria. | `SELECT` |
| Procedimiento | Registra una inscripción o guarda una nota; otro procedimiento abre un cursor. | `CALL` |
| Cursor | Permite leer el conjunto de resultados abierto en PostgreSQL. | `OPEN`, `FETCH` y `CLOSE`, dentro de una transacción. |
| Trigger | Valida un cambio o registra su auditoría cuando se modifica una tabla. | Se activa automáticamente con `INSERT`, `UPDATE` o `DELETE`. |

PHP recibe la petición HTTP y llama a la rutina con parámetros. PostgreSQL realiza la operación y aplica sus triggers. PHP transforma el resultado en JSON para que el frontend lo pueda mostrar.

## 2. Instalar en una computadora nueva

Seguir primero el README de instalación. Al ejecutar `docker compose up -d --build --wait` por primera vez con un volumen vacío, PostgreSQL ejecuta `01` a `07` en orden. La programación queda instalada junto con los datos. Los puertos predeterminados son 8081 para la API y 5434 para PostgreSQL desde Windows.

## 3. Actualizar una base que ya estaba funcionando

Abrir PowerShell dentro de `sistema-dispe` y arrancar PostgreSQL si estaba detenido:

```powershell
docker compose up -d postgres_db --wait
docker compose exec -T postgres_db psql -U postgres -d bd_dispe -v ON_ERROR_STOP=1 -f /docker-entrypoint-initdb.d/07-programacion.sql
docker compose up -d --build --wait
```

El primer comando inicia la base, el segundo instala las rutinas en esa base existente y el tercero reconstruye PHP para incorporar sus rutas. Si se configuraron otros nombres de usuario o base, ajustar `-U` y `-d`.

La migración usa `BEGIN` y `COMMIT`: un error revierte sus cambios. Se puede repetir sin borrar los datos, duplicar la auditoría ni reiniciar la secuencia de identificadores. `schema_migrations` registra la versión `007`. La migración presupone que no existen inscripciones repetidas para la misma combinación de postulante, convocatoria y carrera; si existen, la creación del índice falla y hay que revisar esos datos.

No es necesario eliminar el volumen. Los scripts de inicialización no vuelven a ejecutarse solos en una base que ya tiene datos; por eso se aplica expresamente el archivo 07.

Si la imagen del backend ya se construyó antes y Docker Hub no está disponible, se puede incorporar el código actualizado usando esa imagen local:

```powershell
docker build --pull=false -f backend/Dockerfile.actualizar -t dispe-integrado-backend:latest backend
docker compose up -d --no-build --wait
```

Este método de actualización requiere la imagen local `dispe-integrado-backend:latest`. La instalación inicial sigue usando el Dockerfile normal y necesita Internet.

## 4. Qué reglas se implementaron

- Una nota debe estar entre 0 y 100, incluidos los límites. Se rechazan valores no finitos.
- La materia de una nota debe pertenecer a la carrera de la inscripción.
- La fecha de inscripción debe estar entre el inicio y el fin de la convocatoria, incluidos ambos días.
- La carrera debe pertenecer a la facultad que emite la convocatoria.
- Deben existir las referencias utilizadas y los cuatro datos obligatorios de la inscripción.
- No se repite la combinación postulante, convocatoria y carrera. Esto lo garantiza un índice único, también ante peticiones simultáneas.
- No se permite cambiar la carrera de una inscripción si sus notas quedarían asociadas a materias ajenas.
- Para mover una nota a otra inscripción o materia, se elimina y se vuelve a registrar, de modo que se conserven ambos eventos en el historial.

Los triggers de validación actúan al insertar o editar inscripciones y notas, tanto con la API como con SQL directo. No hacen una reparación retrospectiva de datos históricos ni una validación general de futuras reorganizaciones de catálogos de carreras, materias y convocatorias. Esos cambios de catálogo deben coordinarse con el grupo.

## 5. Cómo se calcula el resultado académico

La función consulta las materias de la carrera y sus notas para esa inscripción:

1. Si la carrera no tiene materias definidas o falta alguna nota, devuelve `EN PROCESO`.
2. Con todas las materias evaluadas, si alguna nota es menor que 51, devuelve `NO ADMITIDO`.
3. Si todas las notas son al menos 51, devuelve `ADMITIDO`.

El promedio se devuelve como información adicional. Una nota baja no se compensa con un promedio alto. Un cero es una nota rendida y reprobada; una nota inexistente es una materia pendiente.

El resultado se calcula **por inscripción**. No se sobrescribe automáticamente `postulante.estado_postulacion`, porque una persona puede tener varias inscripciones. El reporte nuevo muestra `resultado` y `estado_registrado` para que la diferencia sea visible. Por ejemplo, la inscripción 1 tiene promedio 69.4 y dos materias reprobadas: su resultado calculado es `NO ADMITIDO`, aunque el estado general histórico del postulante diga `ADMITIDO`.

El frontend debe usar `resultado` de las rutas académicas para mostrar esta evaluación. Los reportes anteriores conservan su comportamiento de consultas descriptivas y estado histórico.

## 6. Funciones y sus rutas

| Función PostgreSQL | Ruta GET |
|---|---|
| `fn_costo_inscripcion(id, nacionalidad_local)` | `/api/academico/inscripciones/{id}/costo` |
| `fn_resultado_inscripcion(id)` | `/api/academico/inscripciones/{id}/resultado` |
| `fn_resultados_convocatoria(id, carrera_opcional)` | `/api/academico/convocatorias/{id}/resultados` |

Probar en el navegador:

- http://localhost:8081/api/academico
- http://localhost:8081/api/academico/inscripciones/1/costo
- http://localhost:8081/api/academico/inscripciones/1/resultado
- http://localhost:8081/api/academico/convocatorias/1/resultados?idCarrera=14

La función de costo compara la nacionalidad del postulante con `NACIONALIDAD_LOCAL`, enviada por PHP desde su configuración. Si coincide, aplica el costo nacional; en otro caso, el extranjero. El valor es el costo definido por la convocatoria, no un comprobante de pago.

Para probar directamente en `psql` o en la herramienta SQL de pgAdmin:

```sql
SELECT fn_costo_inscripcion(1, 'Boliviana');
SELECT * FROM fn_resultado_inscripcion(1);
SELECT * FROM fn_resultados_convocatoria(1, NULL);
```

## 7. Procedimientos y contratos para el frontend

### Registrar una inscripción

`POST /api/academico/inscripciones` llama a `sp_registrar_inscripcion`.

```json
{
  "idPostulante": 96,
  "idConvocatoria": 1,
  "idCarrera": 14,
  "fecha_inscripcion": "2025-12-01"
}
```

El `96` es ilustrativo: usar el identificador real devuelto al crear un postulante. La respuesta tiene estado 201 y el registro dentro de `data`, incluido `idInscripcion`. No enviar un identificador de inscripción: PostgreSQL lo genera mediante `IDENTITY`. Esto también se aplica a la ruta CRUD anterior `/api/inscripciones`.

El procedimiento usa un parámetro `INOUT` para devolver el identificador. Su forma SQL es `CALL sp_registrar_inscripcion(postulante, convocatoria, carrera, fecha, NULL)`. Se envía `NULL` en el último argumento para recibir el id generado.

Las secuencias pueden dejar huecos cuando una operación falla o se revierte. Es normal: los identificadores sirven para identificar registros, no para contar inscripciones.

### Guardar una nota

`POST /api/academico/notas` llama a `sp_guardar_nota`.

```json
{
  "idInscripcion": 71,
  "idMateria": 5,
  "nota": 80
}
```

Usar los identificadores reales de la inscripción y sus materias. El procedimiento inserta la nota si no existe y la actualiza si ya existe. Responde con estado 200, la nota en `data` y el resultado calculado en `evaluacion`. Su forma SQL es `CALL sp_guardar_nota(inscripcion, materia, nota)`.

Guardar la misma nota otra vez no genera una entrada de cambio en la auditoría.

## 8. Cursor y su ruta

`GET /api/academico/convocatorias/1/resultados-cursor` llama a `sp_abrir_resultados`. Admite `?idCarrera=14`. El backend hace lo siguiente en una misma conexión:

```sql
BEGIN;
CALL sp_abrir_resultados(1, NULL, 'dispe_resultados'::refcursor);
FETCH ALL FROM dispe_resultados;
CLOSE dispe_resultados;
COMMIT;
```

Un cursor normal deja de existir al terminar la transacción. Por eso PHP no hace `COMMIT` antes de leerlo. En esta API se leen todas sus filas para responder un JSON; el ejemplo demuestra el ciclo de un cursor y no implementa transmisión de grandes conjuntos por bloques al frontend.

Para demostrar lectura por partes directamente en SQL:

```sql
BEGIN;
CALL sp_abrir_resultados(1, NULL, 'demo_resultados'::refcursor);
FETCH NEXT FROM demo_resultados;
FETCH FORWARD 2 FROM demo_resultados;
CLOSE demo_resultados;
COMMIT;
```

El nombre del cursor HTTP está fijado en PHP. No se permite que una petición envíe nombres de cursores ni SQL para ejecutar.

## 9. Triggers y auditoría

| Trigger | Tabla y momento | Trabajo |
|---|---|---|
| `validar_inscripcion` | `inscripcion`, BEFORE INSERT/UPDATE | Verifica referencias obligatorias, facultad, fechas y compatibilidad con notas existentes. |
| `validar_nota` | `rinde`, BEFORE INSERT/UPDATE | Verifica rango, pertenencia de la materia y claves de la nota. |
| `auditar_nota` | `rinde`, AFTER INSERT/UPDATE/DELETE | Registra cambios en `auditoria_notas`. |

La auditoría guarda operación, inscripción, materia, fecha, nota anterior, nota nueva y usuario de PostgreSQL. `usuario_bd` identifica al usuario de conexión de la base —actualmente `postgres`—; no identifica al docente que usa un frontend, porque todavía no hay autenticación de personas en la aplicación.

Consulta HTTP, mientras la inscripción exista:

```text
GET /api/academico/inscripciones/{id}/historial-notas?page=1&limit=50
```

La auditoría no tiene una ruta para crear o editar eventos manualmente. Si se elimina una inscripción, su historial permanece en la tabla para consulta SQL, aunque su ruta HTTP ya responda 404:

```sql
SELECT * FROM auditoria_notas ORDER BY id_auditoria DESC LIMIT 20;
```

Los triggers no reconstruyen el historial anterior a su instalación. Los cambios rechazados y los cambios revertidos por una transacción no dejan eventos confirmados.

## 10. Demostración completa desde PowerShell

Este ejemplo crea sus propios registros de prueba. Ejecutarlo por bloques y observar cada respuesta. Usa las fechas históricas de la convocatoria 1; la fecha actual está fuera de esa convocatoria.

### Crear un postulante y registrar su inscripción

```powershell
$dispeApi = 'http://localhost:8081/api'
$dispePersona = Invoke-RestMethod -Method Post -Uri "$dispeApi/postulantes" -ContentType 'application/json' -Body (@{
    ci = Get-Random -Minimum 800000000 -Maximum 899999999
    nombre = 'Demo'; paterno = 'PostgreSQL'; materno = 'Temporal'
    fecha_nacimiento = '2007-05-10'; nacionalidad = 'Boliviana'
    grado_estudios = 'BACHILLER'; estado_postulacion = 'EN PROCESO'
} | ConvertTo-Json)

$dispeRegistro = Invoke-RestMethod -Method Post -Uri "$dispeApi/academico/inscripciones" -ContentType 'application/json' -Body (@{
    idPostulante = $dispePersona.idPostulante
    idConvocatoria = 1; idCarrera = 14; fecha_inscripcion = '2025-12-01'
} | ConvertTo-Json)
$dispeId = $dispeRegistro.data.idInscripcion
Invoke-RestMethod "$dispeApi/academico/inscripciones/$dispeId/resultado"
```

El resultado debe ser `EN PROCESO` porque aún no hay notas. Consultar el costo:

```powershell
Invoke-RestMethod "$dispeApi/academico/inscripciones/$dispeId/costo"
```

### Registrar notas y observar el resultado

```powershell
$dispeMaterias = (Invoke-RestMethod "$dispeApi/carreras/14/materias").data
$dispePrimera = $dispeMaterias[0].idMateria
foreach ($dispeMateria in $dispeMaterias) {
    $dispeNota = if ($dispeMateria.idMateria -eq $dispePrimera) { 47 } else { 90 }
    Invoke-RestMethod -Method Post -Uri "$dispeApi/academico/notas" -ContentType 'application/json' -Body (@{
        idInscripcion = $dispeId; idMateria = $dispeMateria.idMateria; nota = $dispeNota
    } | ConvertTo-Json)
}
Invoke-RestMethod "$dispeApi/academico/inscripciones/$dispeId/resultado"
```

Con todas las notas cargadas, una materia tiene 47: debe aparecer `NO ADMITIDO`. Corregir esa nota a 51:

```powershell
Invoke-RestMethod -Method Post -Uri "$dispeApi/academico/notas" -ContentType 'application/json' -Body (@{
    idInscripcion = $dispeId; idMateria = $dispePrimera; nota = 51
} | ConvertTo-Json)
Invoke-RestMethod "$dispeApi/academico/inscripciones/$dispeId/resultado"
Invoke-RestMethod "$dispeApi/academico/inscripciones/$dispeId/historial-notas" | ConvertTo-Json -Depth 8
Invoke-RestMethod "$dispeApi/academico/convocatorias/1/resultados-cursor?idCarrera=14" | ConvertTo-Json -Depth 8
```

El resultado debe ser `ADMITIDO`. En el historial debe aparecer el cambio de 47 a 51.

### Limpiar únicamente los registros creados en esta demostración

```powershell
foreach ($dispeMateria in $dispeMaterias) {
    Invoke-RestMethod -Method Delete -Uri "$dispeApi/rinde/$dispeId/$($dispeMateria.idMateria)"
}
Invoke-RestMethod -Method Delete -Uri "$dispeApi/inscripciones/$dispeId"
Invoke-RestMethod -Method Delete -Uri "$dispeApi/postulantes/$($dispePersona.idPostulante)?con_persona=true"
```

Los registros académicos temporales se eliminan; la auditoría permanece como evidencia. Este ejemplo presupone `API_KEY` vacía, como la configuración inicial. Si se activa, añadir el encabezado `X-API-Key` a las peticiones.

## 11. Errores esperados

| HTTP | Significado |
|---|---|
| 400 | Identificador o parámetro de consulta inválido. |
| 404 | Inscripción, convocatoria o carrera consultada inexistente. |
| 405 | Método HTTP incorrecto para la operación. |
| 409 | Inscripción duplicada u otra clave única en conflicto. |
| 422 | Campos incompletos, fecha fuera de plazo, carrera de otra facultad, materia ajena o nota inválida. |

Los mensajes de validación de los triggers se muestran sin el contexto interno del SQL. Para un error de conexión o migración faltante, revisar los logs del backend y PostgreSQL.

## 12. Verificación automática

```powershell
docker compose exec -T backend php tests/smoke.php
docker compose exec -T backend php tests/academico.php
```

La primera prueba cubre las operaciones anteriores. La segunda verifica las rutinas nuevas tanto por HTTP como mediante SQL directo: resultados incompletos, aprobación, reprobación, duplicados, errores de fechas y facultades, pertenencia de materias, rango y valores no finitos de notas, auditoría, rollback y cierre del cursor.

Las pruebas usan los datos iniciales del grupo para las convocatorias y crean sus propios postulantes e inscripciones. Eliminan sus registros temporales; la auditoría confirmada permanece por diseño. Las pruebas SQL directas se revierten por completo. Los identificadores de secuencia consumidos no se reutilizan.

En esta versión pasaron las 26 comprobaciones anteriores y las 73 académicas. Los conteos de las tablas del grupo permanecieron en 95 personas, 62 postulantes, 70 inscripciones y 303 notas.

La prueba de instalación desde cero está en `tests/instalacion-limpia.sh`. Se ejecuta en una base temporal distinta dentro del contenedor y la elimina al finalizar. No reinicia el volumen ni elimina la base de trabajo.

```powershell
docker compose cp tests/instalacion-limpia.sh postgres_db:/tmp/dispe-instalacion-limpia.sh
docker compose exec -T postgres_db sh /tmp/dispe-instalacion-limpia.sh
```

## 13. Qué archivos leer para explicarlo en la exposición

1. `db/07-programacion.sql`: recorrer funciones, procedimientos, funciones de trigger y declaraciones `CREATE TRIGGER`.
2. `backend/src/Academico.php`: ver cómo PHP recibe parámetros, llama a `SELECT` o `CALL` y maneja el cursor en una transacción.
3. `backend/public/index.php`: identificar las rutas académicas y la conversión de errores SQL a códigos HTTP.
4. `backend/src/CrudController.php`: observar que las inscripciones ahora reciben el id de PostgreSQL.
5. `backend/tests/academico.php`: revisar las pruebas de reglas y auditoría.

Referencias oficiales de PostgreSQL 16: [cursores](https://www.postgresql.org/docs/16/plpgsql-cursors.html), [procedimientos](https://www.postgresql.org/docs/16/sql-createprocedure.html) y [triggers](https://www.postgresql.org/docs/16/trigger-definition.html).
