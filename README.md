# Sistema DISPE con PHP y PostgreSQL en Docker

Esta carpeta integra la API PHP del compañero con la base PostgreSQL del grupo. Docker Compose levanta los dos servicios y configura su conexión. Incluye funciones almacenadas, procedimientos, un reporte mediante cursor y triggers de validación y auditoría, conectados a las rutas `/api/academico`.

Para entender y demostrar esas operaciones, seguir [la guía de programación de PostgreSQL](GUIA_PROGRAMACION_POSTGRESQL.md). Si la base ya estaba instalada con la versión anterior, aplicar primero la migración 07 como indica esa guía antes de actualizar el backend.

La interfaz web de demostración está disponible en [http://localhost:8081/app.html](http://localhost:8081/app.html) después de levantar los servicios. Permite ejecutar las funciones, los procedimientos y el cursor, consultar la auditoría que generan los triggers y usar una consola SQL protegida para crear rutinas.

## 1. Entender el recorrido de una petición

```text
Navegador, Postman o frontend
        |
        | http://localhost:8081/api/...
        v
Contenedor backend: Apache + PHP 8.2 + PDO PostgreSQL
        |
        | postgres_db:5432 dentro de la red de Docker
        v
Contenedor postgres_db: PostgreSQL 16
        |
        v
Volumen postgres_data: conserva los datos
```

## 2. Archivos y cambios realizados

```text
sistema-dispe/
  compose.yaml             Define servicios, red, puertos, volumen y comprobaciones
  .env.example             Plantilla de configuración local
  .env                     Configuración de esta computadora (ignorada por Git)
  db/                      Seis scripts del grupo y la migración 07 de programación
  GUIA_PROGRAMACION_POSTGRESQL.md  Explicaciones y demostración de las rutinas
  tests/instalacion-limpia.sh     Prueba en una base temporal independiente
  backend/
    Dockerfile             Construye PHP con sus extensiones y Apache
    config/                Configuración del backend original
    public/                Entrada de la API y reglas de Apache
    src/                   Código PHP y puente hacia rutinas PostgreSQL
    docker/                Configuración de Apache, PHP y comprobación de salud
    tests/smoke.php         Prueba real de consultas, reportes y operaciones CRUD
    tests/academico.php     Pruebas HTTP y SQL de programación y triggers
    README-original.md     Documentación recibida del compañero
```

## 3. Preparar Docker Desktop

Instalar Docker Desktop si la computadora todavía no lo tiene. Abrirlo y esperar a que el motor esté en funcionamiento. La configuración usa contenedores Linux.

En PowerShell, verificar:

```powershell
docker version
docker compose version
```

`docker version` debe mostrar tanto Client como Server. Si solo aparece Client y hay un error de conexión, el motor todavía no está disponible. En esta computadora Docker Desktop ya está instalado.

## 4. Abrir la carpeta correcta y preparar la configuración

En esta computadora:

```powershell
cd 'C:\Users\B.R.R.P\Desktop\proyBase\sistema-dispe'
```

En otra computadora, entrar a la carpeta `sistema-dispe` donde se haya copiado el proyecto. Los comandos siguientes se ejecutan desde la carpeta que contiene `compose.yaml`.

Si todavía no existe `.env`, crear una copia:

```powershell
Copy-Item .env.example .env
```

La copia de esta computadora ya está preparada. La configuración inicial es:

```dotenv
DB_NAME=bd_dispe
DB_USER=postgres
DB_PASSWORD=123456
DB_PORT_HOST=5434
API_PORT=8081
APP_DEBUG=false
CORS_ORIGIN=*
API_KEY=
SQL_CONSOLE_KEY=
```

`DB_PORT_HOST` indica el puerto de Windows; Compose fija el puerto interno en 5432. `API_PORT` es el puerto donde se abre la API. La contraseña inicial coincide con la demostración del repositorio del grupo. `.env` no se debe subir a Git; se comparte `.env.example` como plantilla.

Para habilitar la consola SQL del frontend, define `SQL_CONSOLE_KEY` en `.env` con una clave privada larga. La consola solicita esa clave y permanece deshabilitada si la variable está vacía. Tras cambiarla, recrea el contenedor para que Docker aplique la variable: `docker compose up -d --force-recreate backend`. No compartas ni subas la clave al repositorio.

Los puertos se publican en `127.0.0.1`: esta configuración permite acceder desde la propia computadora. Para que integrantes del grupo trabajen en sus computadoras, cada uno puede copiar esta carpeta y levantar su propia instancia.

## 5. Construir y arrancar los dos servicios

```powershell
docker compose up -d --build --wait --wait-timeout 180
```

La primera ejecución descarga imágenes y paquetes, así que requiere Internet y puede demorar varios minutos. PostgreSQL crea `bd_dispe` y ejecuta los siete SQL por orden de nombre. PHP espera a que PostgreSQL acepte conexiones por TCP; después se comprueba una consulta HTTP real de personas para confirmar que la API accede a los datos.

Los scripts de `db/` se ejecutan automáticamente solo cuando el volumen de datos está vacío. Reiniciar los contenedores conserva los datos y no vuelve a insertar los registros.

## 6. Confirmar que ambos servicios están listos

```powershell
docker compose ps
```

Ambos deben aparecer en ejecución y con estado `healthy`. Para consultar problemas:

```powershell
docker compose logs --tail=80 postgres_db backend
```

## 7. Probar la API desde Windows

Abrir en el navegador:

- http://localhost:8081/api
- http://localhost:8081/api/postulantes?limit=3
- http://localhost:8081/api/reportes/estado-postulantes
- http://localhost:8081/api/reportes/boletin/1

La primera dirección muestra el catálogo y no consulta la base. Las siguientes sí comprueban la conexión real. Con los datos iniciales, hay 62 postulantes; los estados son ADMITIDO 24, EN PROCESO 20 y NO ADMITIDO 18. El boletín 1 tiene cinco notas y promedio 69.4. Ese promedio es descriptivo: todavía no implementa una decisión automática de aprobación.

También se puede consultar desde PowerShell:

```powershell
Invoke-RestMethod 'http://localhost:8081/api/postulantes?limit=3' | ConvertTo-Json -Depth 8
```

Si se cambia `API_PORT`, cambiar también el puerto en las direcciones. Si se define `API_KEY`, las peticiones de navegador necesitan una herramienta que permita enviar `X-API-Key`, como Postman. La configuración inicial deja esa clave vacía para facilitar las pruebas locales.

## 8. Comprobar PostgreSQL directamente

```powershell
docker compose exec postgres_db psql -U postgres -d bd_dispe -c 'SELECT COUNT(*) FROM persona;'
```

El resultado inicial es 95. Para entrar a la consola SQL:

```powershell
docker compose exec postgres_db psql -U postgres -d bd_dispe
```

Dentro de `psql`, escribir `\dt` para listar tablas y `\q` para salir. Los comandos anteriores suponen el usuario y la base predeterminados; si se cambian en `.env`, ajustar `-U` y `-d`.

Desde pgAdmin instalado en Windows, registrar un servidor con host `localhost`, puerto `5434`, base `bd_dispe`, usuario `postgres` y contraseña `123456`.

No ejecutar además `admision.sql`: estos contenedores ya inicializan la base con los archivos de `db/`.

## 9. Ejecutar la prueba de integración

```powershell
docker compose exec -T backend php tests/smoke.php
docker compose exec -T backend php tests/academico.php
```

La primera prueba consulta las 11 colecciones, los seis reportes y las notas de una inscripción. Después crea, modifica y elimina un postulante temporal. La segunda prueba las funciones, procedimientos, cursor y triggers mediante HTTP y SQL directo. Ambas terminan con código 0 cuando todas las verificaciones pasan. Las pruebas académicas conservan los eventos de auditoría de sus registros temporales por diseño. No necesitan PHP ni Postman instalados en Windows.

## 10. Detener, continuar y aplicar cambios

Para detener conservando contenedores y datos:

```powershell
docker compose stop
```

Para continuar:

```powershell
docker compose up -d --wait
```

Para retirar los contenedores conservando el volumen de datos:

```powershell
docker compose down
```

Para aplicar cambios en archivos PHP o en el Dockerfile:

```powershell
docker compose up -d --build --wait
```

El código PHP se copia dentro de la imagen; hay que reconstruirla después de editarlo. No se monta una copia de trabajo encima de la aplicación.

Modificar un SQL en `db/` no cambia una base ya inicializada. Las futuras modificaciones de la base se deben aplicar como migraciones. Cambiar `DB_PASSWORD` en `.env` tampoco cambia la contraseña del usuario de una base que ya existe.

## 11. Resolver problemas frecuentes

| Síntoma | Qué revisar |
|---|---|
| Docker no conecta con el motor | Abrir Docker Desktop y esperar a que el motor Linux esté listo. |
| Puerto 5434 ocupado | Detener la instancia anterior si corresponde, o cambiar `DB_PORT_HOST` en `.env` y repetir `up`. La conexión interna sigue usando 5432. |
| Puerto 8081 ocupado | Cambiar `API_PORT`, por ejemplo a 8082, y repetir `up`. |
| Fallo descargando imágenes o paquetes | Revisar Internet y los mensajes de Docker; repetir la construcción cuando la conexión esté disponible. |
| Backend unhealthy o error 500 | Revisar `docker compose logs --tail=80 backend postgres_db`. Para diagnóstico local, activar `APP_DEBUG=true` y repetir `docker compose up -d --wait`. |
| Tabla inexistente al arrancar | Revisar los logs de inicialización de PostgreSQL: un SQL pudo fallar antes de completar la carga. |
| Cambié un SQL y nada cambió | El volumen ya tiene datos; los SQL de inicialización solo se ejecutan en un volumen vacío. |
| Cambié la contraseña y ya no conecta | La contraseña de la base existente no se cambia editando `.env`; restaurar el valor anterior o coordinar el cambio del usuario en PostgreSQL. |

No usar `docker compose down -v` para resolver errores rutinarios: la opción `-v` elimina el volumen y todos sus datos. Esta integración usa el nombre de proyecto `dispe-integrado`, de modo que su volumen es independiente del creado al ejecutar el repositorio original en otra carpeta.

## 12. Interfaz de gestión de datos

Con los servicios activos, abre `http://localhost:8081/app.html`. La pestaña **Datos** permite seleccionar cualquiera de las entidades disponibles, consultar sus registros, buscar por texto y crear o editar información mediante formularios. Los campos relacionados —por ejemplo, facultad, materia, convocatoria e inscripción— se seleccionan desde sus catálogos. Los identificadores se generan automáticamente cuando corresponde; las relaciones con clave compuesta se capturan en el formulario. La API y las reglas de validación de PostgreSQL siguen siendo la autoridad al guardar.

Como el frontend se copia en la imagen del backend durante la construcción, después de modificarlo aplica los cambios con `docker compose up -d --build backend`.

La pestaña **Consola SQL** se mantiene separada de los formularios y ofrece un editor para crear funciones, procedimientos y triggers, consultar con `SELECT`, ejecutar procedimientos con `CALL` o recorrer un `SELECT` con un cursor PostgreSQL. También incluye un ejemplo ejecutable de **cursores anidados**: un cursor externo recorre convocatorias y abre un cursor interno para las inscripciones de cada una. Pulsa **Cargar creación del ejemplo**, ejecútala en modo DDL y luego pulsa **Cargar consulta del ejemplo** y ejecútala en modo SELECT para ver las filas devueltas. Configura `SQL_CONSOLE_KEY` en `.env`, recrea el backend con `docker compose up -d --force-recreate backend` y escribe esa misma clave en la consola. Envía una sentencia por ejecución; los puntos y coma dentro de los bloques `$$...$$` de una rutina son válidos. En modo cursor se escribe el `SELECT`; el backend abre, recorre y cierra el cursor dentro de una transacción.

Por seguridad, el editor no permite DML arbitrario ni `ALTER`/`DROP`. `CALL` puede modificar datos, por lo que debe usarse con una rutina conocida. Comparte la clave solo con personas de confianza: las rutinas creadas pueden realizar acciones cuando se invoquen. Los triggers de validación y auditoría se ejecutan automáticamente al registrar inscripciones o guardar notas; no se llaman directamente desde HTTP. Si se configura `API_KEY`, introdúcela en el campo opcional de la interfaz antes de conectar. Para usar otra dirección del backend, cambia la dirección base de la API.

La regla de aprobación por materia ya está implementada; los estados generales históricos de los postulantes se mantienen separados del resultado calculado por inscripción. La [guía de programación de PostgreSQL](GUIA_PROGRAMACION_POSTGRESQL.md) explica esa diferencia y los detalles de cada rutina.

## 13. Resultado de la verificación

La integración se arrancó en esta computadora con ambos servicios en estado `healthy`. Todos los PHP pasaron la comprobación de sintaxis. La prueba `tests/smoke.php` completó sus 26 comprobaciones, incluidas las 11 colecciones, los seis reportes y la creación, modificación y eliminación de un postulante temporal.

También se consultó la API desde Windows en `http://localhost:8081`: el listado devolvió tres postulantes de un total de 62 y el reporte de estados devolvió los totales 24, 20 y 18. Después de limpiar el registro de prueba, PostgreSQL conservó 95 personas, 62 postulantes, 70 inscripciones y 303 notas.

La programación añadida se comprueba con `tests/academico.php`, que cubre lectura por funciones y cursor, escritura por procedimientos, validaciones de triggers, auditoría y rollback. Las tareas de integración con el frontend se describen en el apartado anterior.

La verificación de esta versión completó 26 comprobaciones anteriores y 73 académicas. También pasó la instalación desde cero en una base temporal y la repetición de la migración. Se conservaron los conteos de datos del grupo indicados arriba. Los eventos de auditoría creados por las pruebas permanecen como historial de las inscripciones temporales eliminadas.
