<?php
/**
 * AlastreSystem - arranque de la aplicacion.
 *
 * Carga las variables de .env y expone la conexion a la base de datos.
 * Incluir este archivo al inicio de cualquier punto de entrada.
 */

declare(strict_types=1);

define('BASE_PATH', __DIR__);

/**
 * Lee el .env y lo vuelca en $_ENV. Sin dependencias: XAMPP trae PHP pelado.
 */
function cargar_env(string $ruta): void
{
    if (!is_readable($ruta)) {
        throw new RuntimeException(
            'No se encontro el archivo .env. Copia .env.example como .env y rellena los valores.'
        );
    }

    foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#')) {
            continue;
        }

        [$clave, $valor] = array_pad(explode('=', $linea, 2), 2, '');
        $clave = trim($clave);
        $valor = trim($valor, " \t\"'");

        if ($clave !== '') {
            $_ENV[$clave] = $valor;
        }
    }
}

/**
 * Devuelve una variable de entorno, con valor por defecto opcional.
 */
function env(string $clave, ?string $defecto = null): ?string
{
    return $_ENV[$clave] ?? $defecto;
}

/**
 * Conexion PDO compartida. Se abre una sola vez por peticion.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            env('DB_HOST', 'localhost'),
            env('DB_PORT', '3306'),
            env('DB_NAME', ''),
            env('DB_CHARSET', 'utf8mb4')
        );

        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}

cargar_env(BASE_PATH . '/.env');

// En local queremos ver los errores; en produccion se registran, no se muestran.
$depurar = env('APP_DEBUG', 'false') === 'true';
ini_set('display_errors', $depurar ? '1' : '0');
error_reporting($depurar ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
