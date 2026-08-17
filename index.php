<?php
/**
 * AlastreSystem - consola de operaciones.
 *
 * Panel unico del pipeline: resumen, navegacion por etapas, ficha de cada lead
 * y las acciones que lo mueven. La puerta humana de 40-por-aprobar sigue siendo
 * el punto donde nada sale sin que alguien lo lea.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Transiciones permitidas desde cada etapa. Es una lista blanca a proposito:
 * lo que llega por HTTP no decide a donde va un lead, solo elige entre lo que
 * aqui esta declarado.
 */
const ACCIONES = [
    '00-descubierto' => [
        'calificar' => ['10-calificado', 'calificado a mano'],
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION, 'no volver a contactar'],
    ],
    '10-calificado' => [
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION, 'no volver a contactar'],
    ],
    '20-auditado' => [
        'construir' => ['30-construido', 'listo para construir landing'],
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION, 'no volver a contactar'],
    ],
    '30-construido' => [
        'proponer'  => ['40-por-aprobar', 'borrador listo para revision'],
        'descartar' => ['99-descartado', 'descartado en revision'],
    ],
    '40-por-aprobar' => [
        'aprobar'   => ['50-enviado', 'aprobado y enviado a mano'],
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION, 'no volver a contactar'],
    ],
    '50-enviado' => [
        'respondio' => ['60-respondido', 'contesto'],
        'descartar' => ['99-descartado', 'sin respuesta'],
    ],
    '60-respondido' => [
        'reunion'   => ['70-reunion', 'reunion agendada'],
        'descartar' => ['99-descartado', 'no interesado'],
    ],
    '70-reunion' => [
        'cerrado'   => ['90-cerrado', 'cliente cerrado'],
        'descartar' => ['99-descartado', 'no salio'],
    ],
    '99-descartado' => [
        'recuperar' => ['00-descubierto', 'recuperado del descarte'],
    ],
];

/** Etiquetas legibles y color de cada etapa. */
const ETIQUETAS = [
    '00-descubierto' => ['Descubiertos',  'neutro'],
    '10-calificado'  => ['Calificados',   'neutro'],
    '20-auditado'    => ['Auditados',     'info'],
    '30-construido'  => ['Con landing',   'info'],
    '40-por-aprobar' => ['Por aprobar',   'accion'],
    '50-enviado'     => ['Enviados',      'info'],
    '60-respondido'  => ['Respondieron',  'bien'],
    '70-reunion'     => ['Con reunión',   'bien'],
    '90-cerrado'     => ['Cerrados',      'bien'],
    '99-descartado'  => ['Descartados',   'mal'],
    SUPRESION        => ['No contactar',  'mal'],
];

function h(?string $t): string
{
    return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');
}

/** Los IDs son alfanumericos con - y _. Nada mas toca una ruta de archivo. */
function id_valido(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) === 1;
}

function etapa_valida(string $e): bool
{
    return isset(ETIQUETAS[$e]);
}

// --- acciones --------------------------------------------------------------

$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (string) ($_POST['id'] ?? '');
    $desde  = (string) ($_POST['desde'] ?? '');
    $accion = (string) ($_POST['accion'] ?? '');

    if (!id_valido($id) || !etapa_valida($desde)) {
        $aviso = ['mal', 'Identificador o etapa no válidos.'];
    } elseif (!isset(ACCIONES[$desde][$accion])) {
        $aviso = ['mal', 'Esa acción no está permitida desde ' . $desde . '.'];
    } else {
        [$hacia, $nota] = ACCIONES[$desde][$accion];

        if (promover($id, $desde, $hacia, $nota)) {
            $aviso = ['bien', ETIQUETAS[$hacia][0] . ': lead movido correctamente.'];
        } else {
            $aviso = ['mal', 'Ese lead ya no estaba en ' . $desde . '.'];
        }
    }
}

// --- estado ----------------------------------------------------------------

$recuento = recuento();
$total    = array_sum($recuento);

$etapaActual = (string) ($_GET['etapa'] ?? '');
if (!etapa_valida($etapaActual)) {
    // Por defecto, la etapa que reclama accion; si no, la que tenga algo.
    $etapaActual = $recuento['40-por-aprobar'] > 0 ? '40-por-aprobar' : '00-descubierto';
    foreach (ETIQUETAS as $e => $_) {
        if (($recuento[$e] ?? 0) > 0) { $etapaActual = $e; break; }
    }
    if ($recuento['40-por-aprobar'] > 0) { $etapaActual = '40-por-aprobar'; }
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$abierto  = (string) ($_GET['id'] ?? '');

/** @var list<array<string,mixed>> $leads */
$leads = [];
foreach (leads_en($etapaActual) as $lid) {
    $l = cargar_lead($etapaActual, $lid);
    if ($busqueda !== '' && stripos($l['nombre'] . ' ' . ($l['zona'] ?? ''), $busqueda) === false) {
        continue;
    }
    $l['_id'] = $lid;
    $leads[] = $l;
}

usort($leads, static fn(array $a, array $b): int =>
    count($b['hallazgos'] ?? []) <=> count($a['hallazgos'] ?? []));

// Metricas de cabecera
$sinWeb = 0;
$porRevisar = 0;
foreach (ETIQUETAS as $e => $_) {
    foreach (leads_en($e) as $lid) {
        $l = cargar_lead($e, $lid);
        if (($l['web_tipo'] ?? '') === 'ninguna' || ($l['web_tipo'] ?? '') === 'directorio') { $sinWeb++; }
        foreach ($l['hallazgos'] ?? [] as $hh) {
            if (!empty($hh['verificar'])) { $porRevisar++; break; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Consola · AlastreSystem</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="barra">
  <div class="barra__marca">
    <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <circle cx="16" cy="16" r="15" stroke="currentColor" stroke-width="1.6" opacity=".28"/>
      <path d="M16 24v-8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <circle cx="11.5" cy="12" r="3" fill="#4F6E54"/><circle cx="16" cy="8.6" r="3" fill="#5B3E86"/>
      <circle cx="20.5" cy="12" r="3" fill="#B08430"/>
    </svg>
    AlastreSystem
  </div>
  <form class="buscador" method="get">
    <input type="hidden" name="etapa" value="<?= h($etapaActual) ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input type="search" name="q" value="<?= h($busqueda) ?>" placeholder="Buscar por nombre o zona…" aria-label="Buscar">
  </form>
  <div class="barra__total"><b><?= $total ?></b> leads</div>
</header>

<?php if ($aviso !== null): ?>
  <div class="env"><div class="aviso aviso--<?= h($aviso[0]) ?>"><?= h($aviso[1]) ?></div></div>
<?php endif; ?>

<main class="env">

  <!-- ===== métricas ===== -->
  <section class="kpis">
    <article class="kpi">
      <span class="kpi__n"><?= $total ?></span>
      <span class="kpi__t">En el pipeline</span>
    </article>
    <article class="kpi kpi--info">
      <span class="kpi__n"><?= $sinWeb ?></span>
      <span class="kpi__t">Sin web propia</span>
    </article>
    <article class="kpi <?= $recuento['40-por-aprobar'] > 0 ? 'kpi--accion' : '' ?>">
      <span class="kpi__n"><?= $recuento['40-por-aprobar'] ?></span>
      <span class="kpi__t">Esperan tu aprobación</span>
    </article>
    <article class="kpi <?= $porRevisar > 0 ? 'kpi--aviso' : '' ?>">
      <span class="kpi__n"><?= $porRevisar ?></span>
      <span class="kpi__t">Con dato por verificar</span>
    </article>
  </section>

  <!-- ===== pipeline ===== -->
  <nav class="flujo" aria-label="Etapas del pipeline">
    <?php foreach (ETIQUETAS as $etapa => [$titulo, $tono]):
      $n = $recuento[$etapa] ?? 0; ?>
      <a class="flujo__paso <?= $etapa === $etapaActual ? 'es-activo' : '' ?> <?= $n === 0 ? 'es-vacio' : '' ?> t-<?= h($tono) ?>"
         href="?etapa=<?= h($etapa) ?><?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>">
        <span class="flujo__n"><?= $n ?></span>
        <span class="flujo__t"><?= h($titulo) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- ===== lista ===== -->
  <section class="lista">
    <div class="lista__cab">
      <h1><?= h(ETIQUETAS[$etapaActual][0]) ?></h1>
      <span class="lista__meta">
        <?= count($leads) ?> lead<?= count($leads) === 1 ? '' : 's' ?>
        <?= $busqueda !== '' ? ' · filtrando por "' . h($busqueda) . '"' : '' ?>
      </span>
    </div>

    <?php if ($leads === []): ?>
      <div class="vacio">
        <p>No hay nada en esta etapa.</p>
        <p class="pista">
          <?php if ($etapaActual === '00-descubierto'): ?>
            Trae leads con <code>php bin/scout-osm.php psicologia "Valencia, España"</code>
          <?php elseif ($etapaActual === '20-auditado'): ?>
            Audita los descubiertos con <code>php bin/auditar.php</code>
          <?php else: ?>
            Los leads llegan aquí desde la etapa anterior.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <ul class="filas">
      <?php foreach ($leads as $l):
        $lid       = (string) $l['_id'];
        $expandido = $lid === $abierto;
        $hallazgos = $l['hallazgos'] ?? [];
        $verif     = $l['verificacion_dominio'] ?? null;
      ?>
        <li class="fila <?= $expandido ? 'es-abierto' : '' ?>">
          <div class="fila__cab">
            <a class="fila__nombre"
               href="?etapa=<?= h($etapaActual) ?><?= $expandido ? '' : '&id=' . urlencode($lid) ?><?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>#<?= h($lid) ?>"
               id="<?= h($lid) ?>">
              <span class="fila__chevron" aria-hidden="true"></span>
              <?= h($l['nombre']) ?>
            </a>

            <div class="fila__marcas">
              <?php if (!empty($l['zona'])): ?>
                <span class="marca"><?= h(mb_substr((string) $l['zona'], 0, 22)) ?></span>
              <?php endif; ?>

              <?php if (($l['web_tipo'] ?? '') === 'ninguna'): ?>
                <span class="marca marca--bien">sin web</span>
              <?php elseif (($l['web_tipo'] ?? '') === 'directorio'): ?>
                <span class="marca marca--bien">solo directorio</span>
              <?php elseif (!empty($l['web'])): ?>
                <span class="marca">tiene web</span>
              <?php endif; ?>

              <?php if (!empty($l['valoracion'])): ?>
                <span class="marca marca--oro"><?= h(number_format((float) $l['valoracion'], 1, ',', '')) ?>★ <?= (int) ($l['resenas'] ?? 0) ?></span>
              <?php endif; ?>

              <?php if ($verif !== null && ($verif['resultado'] ?? '') === 'encontrada'): ?>
                <span class="marca marca--mal">web hallada: <?= h((string) ($verif['confianza'] ?? '')) ?></span>
              <?php endif; ?>

              <span class="marca marca--tenue"><?= count($hallazgos) ?> hallazgo<?= count($hallazgos) === 1 ? '' : 's' ?></span>
            </div>
          </div>

          <?php if ($expandido): ?>
            <div class="ficha">
              <div class="ficha__datos">
                <?php foreach ([
                  'Dirección' => $l['direccion'] ?? null,
                  'Teléfono'  => $l['telefono'] ?? null,
                  'Web'       => $l['web'] ?? null,
                  'Origen'    => $l['origen'] ?? 'places',
                  'Vertical'  => $l['vertical'] ?? null,
                ] as $etiqueta => $valor):
                  if ($valor === null || $valor === '') { continue; } ?>
                  <div class="dato">
                    <dt><?= h($etiqueta) ?></dt>
                    <dd><?= h((string) $valor) ?></dd>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if ($hallazgos !== []): ?>
                <h2 class="ficha__tit">Hallazgos citables</h2>
                <ul class="hallazgos">
                  <?php foreach ($hallazgos as $hh): ?>
                    <li>
                      <span class="grav g-<?= h((string) $hh['gravedad']) ?>"><?= h((string) $hh['gravedad']) ?></span>
                      <span>
                        <?= h((string) $hh['citable']) ?>
                        <?php if (!empty($hh['verificar'])): ?>
                          <strong class="verificar">Búscalo en Google antes de citarlo: que Maps no enlace una web no prueba que no exista.</strong>
                        <?php endif; ?>
                      </span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if ($verif !== null): ?>
                <h2 class="ficha__tit">Verificación de dominio</h2>
                <p class="ficha__p">
                  <?php if (($verif['resultado'] ?? '') === 'encontrada'): ?>
                    Se encontró <a href="<?= h((string) $verif['web']) ?>" target="_blank" rel="noopener"><?= h((string) $verif['web']) ?></a>
                    — <?= h((string) $verif['prueba']) ?> (confianza <?= h((string) $verif['confianza']) ?>).
                  <?php else: ?>
                    Sin indicios de web propia tras probar <?= (int) ($verif['candidatos'] ?? 0) ?> dominios.
                  <?php endif; ?>
                </p>
              <?php endif; ?>

              <?php if (!empty($l['resenas_texto'])): ?>
                <h2 class="ficha__tit">Reseñas en Google
                  <span class="ficha__aux">— material directo para el copy: aquí se ve qué valora su clientela</span>
                </h2>
                <ul class="resenas">
                  <?php foreach ($l['resenas_texto'] as $r):
                    if (empty($r['texto'])) { continue; } ?>
                    <li>
                      <div class="resena__cab">
                        <span class="resena__nota"><?= h((string) ($r['puntuacion'] ?? '?')) ?>★</span>
                        <span class="resena__autor"><?= h((string) ($r['autor'] ?? 'anónimo')) ?></span>
                        <time><?= h(substr((string) ($r['fecha'] ?? ''), 0, 10)) ?></time>
                      </div>
                      <p><?= h(mb_substr((string) $r['texto'], 0, 420)) ?><?= mb_strlen((string) $r['texto']) > 420 ? '…' : '' ?></p>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (!empty($l['fotos'])): ?>
                <h2 class="ficha__tit">Fotos disponibles en Places</h2>
                <p class="ficha__p">
                  <?= count($l['fotos']) ?> referencia(s).
                  <?php if (!empty($l['fotos_descargadas'])): ?>
                    <?= (int) $l['fotos_descargadas']['total'] ?> descargadas en
                    <code><?= h((string) $l['fotos_descargadas']['carpeta']) ?></code>.
                  <?php else: ?>
                    Descárgalas con <code>php bin/fotos.php --id=<?= h($lid) ?></code>
                  <?php endif; ?>
                </p>
              <?php endif; ?>

              <?php if (!empty($l['mediciones'])): ?>
                <h2 class="ficha__tit">Mediciones</h2>
                <div class="medidas">
                  <?php foreach ($l['mediciones'] as $k => $v): ?>
                    <span class="medida">
                      <b><?= h((string) $k) ?></b>
                      <?= h(is_bool($v) ? ($v ? 'sí' : 'no') : (string) ($v ?? '—')) ?>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($l['borrador'])): ?>
                <h2 class="ficha__tit">Borrador del mensaje</h2>
                <pre class="borrador"><?= h((string) $l['borrador']) ?></pre>
              <?php endif; ?>

              <?php if (!empty($l['historial'])): ?>
                <h2 class="ficha__tit">Historial</h2>
                <ol class="historial">
                  <?php foreach (array_reverse($l['historial']) as $paso): ?>
                    <li>
                      <span><?= h((string) ($paso['etapa'] ?? '')) ?></span>
                      <span class="historial__nota"><?= h((string) ($paso['nota'] ?? '')) ?></span>
                      <time><?= h(substr((string) ($paso['fecha'] ?? ''), 0, 16)) ?></time>
                    </li>
                  <?php endforeach; ?>
                </ol>
              <?php endif; ?>

              <div class="acciones">
                <?php if (!empty($l['landing'])): ?>
                  <a class="btn btn--ver" href="<?= h((string) $l['landing']) ?>" target="_blank" rel="noopener">Ver la landing</a>
                <?php endif; ?>
                <form method="post">
                  <input type="hidden" name="id" value="<?= h($lid) ?>">
                  <input type="hidden" name="desde" value="<?= h($etapaActual) ?>">
                  <?php foreach (ACCIONES[$etapaActual] ?? [] as $clave => [$destino, $_nota]):
                    $clase = match ($clave) {
                      'suprimir', 'descartar' => $clave === 'suprimir' ? 'btn--stop' : 'btn--no',
                      default => 'btn--ok',
                    }; ?>
                    <button class="btn <?= $clase ?>" name="accion" value="<?= h($clave) ?>">
                      <?= h(ucfirst($clave)) ?>
                      <span class="btn__destino"><?= h(ETIQUETAS[$destino][0]) ?></span>
                    </button>
                  <?php endforeach; ?>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</main>

</body>
</html>
