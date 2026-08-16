<?php
/**
 * AlastreSystem - panel de revision.
 *
 * Esta es la puerta humana del pipeline: nada sale de 40-por-aprobar sin que
 * alguien lo lea aqui y pulse un boton. Es deliberadamente lo unico del
 * sistema que no esta automatizado.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Los IDs de Google Places son alfanumericos con - y _. Nada mas entra. */
function id_valido(string $id): bool
{
    return $id !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) === 1;
}

function h(?string $t): string
{
    return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');
}

$aviso = null;

// --- acciones -------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (string) ($_POST['id'] ?? '');
    $accion = (string) ($_POST['accion'] ?? '');

    $destinos = [
        'aprobar'   => ['50-enviado',   'aprobado y enviado a mano'],
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION,       'no volver a contactar'],
    ];

    if (!id_valido($id)) {
        $aviso = ['error', 'Identificador no valido.'];
    } elseif (!isset($destinos[$accion])) {
        $aviso = ['error', 'Accion desconocida.'];
    } else {
        [$hacia, $nota] = $destinos[$accion];

        if (promover($id, '40-por-aprobar', $hacia, $nota)) {
            $aviso = ['ok', "Lead movido a {$hacia}."];
        } else {
            $aviso = ['error', 'Ese lead ya no estaba en 40-por-aprobar.'];
        }
    }
}

$recuento  = recuento();
$porAprobar = leads_en('40-por-aprobar');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AlastreSystem — Revisión</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<header class="cabecera">
    <h1>Puerta de revisión</h1>
    <p class="sub">Nada se envía sin pasar por aquí.</p>
</header>

<?php if ($aviso !== null): ?>
    <div class="aviso <?= h($aviso[0]) ?>"><?= h($aviso[1]) ?></div>
<?php endif; ?>

<section class="recuento">
    <?php foreach ($recuento as $etapa => $n): ?>
        <div class="etapa <?= $n > 0 ? 'viva' : '' ?>">
            <span class="etapa-n"><?= $n ?></span>
            <span class="etapa-nombre"><?= h($etapa) ?></span>
        </div>
    <?php endforeach; ?>
</section>

<main>
<?php if ($porAprobar === []): ?>
    <div class="vacio">
        <p>No hay nada esperando aprobación.</p>
        <p class="pista">
            Genera leads con <code>php bin/scout.php "dentista" "tu ciudad"</code>
            y audítalos con <code>php bin/auditar.php</code>.
        </p>
    </div>
<?php else: ?>
    <?php foreach ($porAprobar as $id):
        $lead = cargar_lead('40-por-aprobar', $id); ?>
        <article class="lead">
            <div class="lead-cab">
                <h2><?= h($lead['nombre']) ?></h2>
                <div class="lead-meta">
                    <?= h($lead['direccion'] ?? '') ?>
                    <?php if (!empty($lead['telefono'])): ?>
                        · <?= h($lead['telefono']) ?>
                    <?php endif; ?>
                    <?php if (!empty($lead['valoracion'])): ?>
                        · <?= h((string) $lead['valoracion']) ?>★
                        (<?= (int) ($lead['resenas'] ?? 0) ?>)
                    <?php endif; ?>
                </div>
            </div>

            <div class="bloque">
                <h3>Hallazgos verificados</h3>
                <?php if (empty($lead['hallazgos'])): ?>
                    <p class="pista">Sin hallazgos. Este lead no debería estar aquí.</p>
                <?php else: ?>
                    <ul class="hallazgos">
                        <?php foreach ($lead['hallazgos'] as $hallazgo): ?>
                            <li>
                                <span class="grav g-<?= h($hallazgo['gravedad']) ?>">
                                    <?= h($hallazgo['gravedad']) ?>
                                </span>
                                <span>
                                    <?= h($hallazgo['citable']) ?>
                                    <?php if (!empty($hallazgo['verificar'])): ?>
                                        <strong class="verificar">
                                            Búscalo en Google antes de citarlo: que Maps no
                                            enlace una web no prueba que no exista.
                                        </strong>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if (!empty($lead['borrador'])): ?>
                <div class="bloque">
                    <h3>Borrador del mensaje</h3>
                    <pre class="borrador"><?= h($lead['borrador']) ?></pre>
                </div>
            <?php endif; ?>

            <div class="acciones">
                <?php if (!empty($lead['landing'])): ?>
                    <a class="btn btn-ver" href="<?= h($lead['landing']) ?>" target="_blank" rel="noopener">
                        Ver la landing
                    </a>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="id" value="<?= h($id) ?>">
                    <button class="btn btn-ok" name="accion" value="aprobar">Aprobado, lo envío</button>
                    <button class="btn btn-no" name="accion" value="descartar">Descartar</button>
                    <button class="btn btn-stop" name="accion" value="suprimir">No contactar nunca</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
<?php endif; ?>
</main>
</body>
</html>
