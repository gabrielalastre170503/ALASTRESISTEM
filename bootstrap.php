<?php
/**
 * AlastreSystem - arranque comun (CLI y panel web).
 *
 * Carga las variables de .env y las librerias del pipeline. No hay base de
 * datos: el estado vive en el sistema de archivos, bajo pipeline/.
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
    $valor = $_ENV[$clave] ?? $defecto;
    return $valor === '' ? $defecto : $valor;
}

/**
 * Igual que env() pero revienta si falta. Para claves sin las que no se puede
 * seguir: mejor un mensaje claro ahora que un 401 raro tres pasos despues.
 */
function env_obligatoria(string $clave): string
{
    $valor = env($clave);

    if ($valor === null) {
        throw new RuntimeException(
            "Falta {$clave} en el archivo .env. Mira .env.example para saber de donde sacarla."
        );
    }

    return $valor;
}

// En CLI un stack trace no ayuda a nadie: el mensaje ya dice que hacer.
if (PHP_SAPI === 'cli') {
    set_exception_handler(static function (Throwable $e): void {
        fwrite(STDERR, "\n  Error: {$e->getMessage()}\n\n");
        exit(1);
    });
}

cargar_env(BASE_PATH . '/.env');

require_once BASE_PATH . '/lib/http.php';
require_once BASE_PATH . '/lib/pipeline.php';
require_once BASE_PATH . '/lib/places.php';

preparar_pipeline();

// En local queremos ver los errores; en produccion se registran, no se muestran.
$depurar = env('APP_DEBUG', 'false') === 'true';
ini_set('display_errors', $depurar ? '1' : '0');
error_reporting($depurar ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);
