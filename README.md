# AlastreSystem

Sistema web en PHP sobre XAMPP. El repositorio esta recien inicializado: por
ahora contiene el esqueleto del proyecto y la verificacion del entorno, no la
aplicacion en si.

## Requisitos

- XAMPP con PHP 8.0 o superior (se usa `str_starts_with` y tipos estrictos)
- MySQL / MariaDB
- Apache con `mod_rewrite` y `AllowOverride All` para que los `.htaccess` apliquen

## Puesta en marcha

1. Clonar el repositorio dentro de `htdocs`:

   ```bash
   git clone https://github.com/gabrielalastre170503/ALASTRESISTEM.git AlastreSystem
   ```

2. Crear el archivo de configuracion a partir de la plantilla:

   ```bash
   cp .env.example .env
   ```

3. Editar `.env` con los datos de la maquina local. Los valores por defecto ya
   corresponden a una instalacion limpia de XAMPP (`root` sin contrasena).

4. Crear la base de datos indicada en `DB_NAME`:

   ```sql
   CREATE DATABASE alastresystem CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

5. Levantar Apache y MySQL desde el panel de XAMPP y abrir
   <http://localhost/AlastreSystem>.

Si todo esta bien, la pagina muestra los tres chequeos (PHP, configuracion y
base de datos) en verde.

## Estructura

```
AlastreSystem/
├── assets/
│   ├── css/style.css     Estilos base
│   └── js/               Scripts del cliente
├── database/             Esquema y migraciones (los backups no se versionan)
├── uploads/              Archivos subidos por usuarios (no se versionan)
├── .env.example          Plantilla de configuracion
├── .htaccess             Reglas de Apache y bloqueo de archivos sensibles
├── bootstrap.php         Carga del .env y conexion PDO
└── index.php             Punto de entrada
```

## Configuracion y secretos

El archivo `.env` **no se versiona**. Solo viaja `.env.example`, que lista los
nombres de las variables sin ningun valor real. Al clonar en otra maquina hay
que copiarlo y rellenarlo.

Tampoco se versionan los archivos subidos (`uploads/`) ni los respaldos de base
de datos (`database/backups/`), porque contienen datos reales.

## Convenciones

- Codigo y comentarios en espanol, igual que el resto de proyectos.
- Consultas siempre con sentencias preparadas de PDO (`db()` en `bootstrap.php`).
- Toda salida a HTML pasa por `h()` para evitar XSS.
