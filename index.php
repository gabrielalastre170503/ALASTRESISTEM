<?php
/**
 * AlastreSystem - punto de entrada.
 *
 * De momento esta pagina solo verifica que el entorno quedo bien montado:
 * que el .env carga y que la base de datos responde. Reemplazar por la
 * pantalla real de la aplicacion cuando exista.
 */

declare(strict_types=1);

$errorArranque = null;
$estadoDb      = null;
$nombreApp     = 'AlastreSystem';

try {
    require __DIR__ . '/bootstrap.php';
    $nombreApp = env('APP_NAME', 'AlastreSystem');

    try {
        db()->query('SELECT 1');
        $estadoDb = [
            'ok'      => true,
            'mensaje' => sprintf('Conexion establecida con la base de datos "%s".', env('DB_NAME', '')),
        ];
    } catch (Throwable $e) {
        $estadoDb = ['ok' => false, 'mensaje' => $e->getMessage()];
    }
} catch (Throwable $e) {
    $errorArranque = $e->getMessage();
}

/** Escapa texto para imprimirlo en HTML. */
function h(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($nombreApp) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <main class="panel">
        <h1><?= h($nombreApp) ?></h1>
        <p class="subtitulo">El proyecto esta montado. Esta pagina es solo una verificacion del entorno.</p>

        <ul class="chequeos">
            <li class="chequeo ok">
                <span class="etiqueta">PHP</span>
                <span>Version <?= h(PHP_VERSION) ?></span>
            </li>

            <?php if ($errorArranque !== null): ?>
                <li class="chequeo falla">
                    <span class="etiqueta">Configuracion</span>
                    <span><?= h($errorArranque) ?></span>
                </li>
            <?php else: ?>
                <li class="chequeo ok">
                    <span class="etiqueta">Configuracion</span>
                    <span>Archivo .env cargado correctamente.</span>
                </li>
                <li class="chequeo <?= $estadoDb['ok'] ? 'ok' : 'falla' ?>">
                    <span class="etiqueta">Base de datos</span>
                    <span><?= h($estadoDb['mensaje']) ?></span>
                </li>
            <?php endif; ?>
        </ul>

        <p class="nota">
            Siguiente paso: definir el alcance del sistema y sustituir este archivo
            por la pantalla inicial real.
        </p>
    </main>
</body>
</html>
