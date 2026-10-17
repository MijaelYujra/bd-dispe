# Base de Datos - Sistema DISPE

Este proyecto contiene la configuración automatizada de la base de datos PostgreSQL utilizando **Docker**.

## Requisitos Previos
Tener instalado [Docker Desktop](https://docker.com) en tu computadora.

## Cómo Inicializar la Base de Datos
1. Abre una terminal en la raíz de este proyecto.
2. Ejecuta el siguiente comando para levantar el contenedor en segundo plano:
   ```bash
   docker compose up -d
   ```
3. Docker creará la base de datos automáticamente y ejecutará todos los scripts `.sql` de la carpeta `/db` en orden alfabético.

## Credenciales para Conectar el Backend
Usa estos datos exactos en el archivo de configuración (`.env` o equivalente) de tu backend:

- **Host:** `localhost`
- **Puerto:** `5432`
- **Usuario (User):** `postgres`
- **Contraseña (Password):** `123456`
- **Base de Datos (Database):** `bd_dispe`

## Comandos Útiles
- **Detener la base de datos:** `docker compose stop`
- **Volver a encenderla:** `docker compose start`
- **Borrar todo y restablecer los datos de prueba desde cero:** 
  ```bash
  docker compose down -v && docker compose up -d
  ```
