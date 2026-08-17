<?php
/**
 * AlastreSystem - consola de operaciones.
 *
 * Panel unico del pipeline: descubrimiento, resumen, navegacion por etapas,
 * ficha de cada lead y las acciones que lo mueven.
 *
 * La consola no ejecuta comandos de shell: llama a las funciones de lib/, asi
 * que no hay nada que inyectar. Y la puerta humana de 40-por-aprobar sigue
 * siendo el punto donde nada sale sin que alguien lo lea.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** Transiciones permitidas desde cada etapa. Lista blanca a proposito. */
const ACCIONES = [
    '00-descubierto' => [
        'calificar' => ['10-calificado', 'calificado a mano'],
        'descartar' => ['99-descartado', 'descartado en revision'],
        'suprimir'  => [SUPRESION, 'no volver a contactar'],
    ],
    '10-calificado' => [
        'auditar'   => ['20-auditado', 'pasado a auditoria'],
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

/** Verticales con su consulta sugerida. */
const VERTICALES = [
    'psicologia'   => 'psicólogo',
    'dentista'     => 'clínica dental',
    'fisioterapia' => 'fisioterapeuta',
    'veterinario'  => 'veterinario',
    'estetica'     => 'centro de estética',
    'peluqueria'   => 'peluquería',
    'restaurante'  => 'restaurante',
    'abogado'      => 'abogado',
    'gestoria'     => 'gestoría',
    'optica'       => 'óptica',
    'nutricion'    => 'nutricionista',
    'gimnasio'     => 'gimnasio',
    'inmobiliaria' => 'inmobiliaria',
    'taller'       => 'taller mecánico',
    'otro'         => '',
];

function h(?string $t): string
{
    return htmlspecialchars($t ?? '', ENT_QUOTES, 'UTF-8');
}

function id_valido(string $id): bool
{
    return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $id) === 1;
}

function etapa_valida(string $e): bool
{
    return isset(ETIQUETAS[$e]);
}

// ===========================================================================
//  ACCIONES
// ===========================================================================

$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $que = (string) ($_POST['que'] ?? '');

    // --- descubrir con Places -----------------------------------------------
    if ($que === 'descubrir') {
        $termino  = trim((string) ($_POST['termino'] ?? ''));
        $lugar    = trim((string) ($_POST['lugar'] ?? ''));
        $vertical = (string) ($_POST['vertical'] ?? 'otro');
        $cuantos  = max(1, min(60, (int) ($_POST['cuantos'] ?? 20)));
        $completo = !empty($_POST['completo']);
        $sinWeb   = !empty($_POST['sin_web']);
        $lat      = $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
        $lng      = $_POST['lng'] !== '' ? (float) $_POST['lng'] : null;
        $radio    = (int) ($_POST['radio'] ?? 3000);

        $clave = env('GOOGLE_PLACES_API_KEY');

        if ($termino === '' || $lugar === '') {
            $aviso = ['mal', 'Hacen falta el término de búsqueda y la localización.'];
        } elseif ($clave === null) {
            $aviso = ['mal', 'Falta GOOGLE_PLACES_API_KEY en el .env.'];
        } else {
            $geo = ($lat !== null && $lng !== null)
                ? ['lat' => $lat, 'lng' => $lng, 'radio' => $radio]
                : [];

            $nuevos = $filtrados = $repetidos = 0;
            $token  = null;
            $vueltas = 0;

            do {
                $r = places_buscar("{$termino} en {$lugar}", $clave, $completo, $token, $geo);

                if (!$r['ok']) {
                    $aviso = ['mal', 'Places: ' . $r['error']];
                    break;
                }

                foreach ($r['lugares'] as $lu) {
                    if (empty($lu['id'])) { continue; }
                    if (etapa_de($lu['id']) !== null) { $repetidos++; continue; }
                    if ($sinWeb && !empty($lu['websiteUri'])) { $filtrados++; continue; }

                    guardar_lead('00-descubierto', $lu['id'], places_a_lead($lu, $vertical, $lugar));
                    $nuevos++;

                    if ($nuevos >= $cuantos) { break 2; }
                }

                $token = $r['token'];
                $vueltas++;
                if ($token !== null) { sleep(2); }
            } while ($token !== null && $vueltas < 3);

            if ($aviso === null) {
                $aviso = ['bien', sprintf(
                    '%d lead(s) nuevo(s). %d ya conocidos, %d filtrados por tener web.',
                    $nuevos, $repetidos, $filtrados
                )];
            }
        }
    }

    // --- traer fotos ---------------------------------------------------------
    elseif ($que === 'fotos') {
        $id = (string) ($_POST['id'] ?? '');
        $clave = env('GOOGLE_PLACES_API_KEY');

        if (!id_valido($id) || ($etapa = etapa_de($id)) === null) {
            $aviso = ['mal', 'Lead no encontrado.'];
        } elseif ($clave === null) {
            $aviso = ['mal', 'Falta GOOGLE_PLACES_API_KEY en el .env.'];
        } else {
            $lead = cargar_lead($etapa, $id);
            $r    = places_fotos($lead, $clave);
            $lead['fotos_descargadas'] = $r;
            guardar_lead($etapa, $id, $lead);
            $aviso = ['bien', "{$r['descargadas']} foto(s) descargada(s) en {$r['carpeta']}"];
        }
    }

    // --- buscar contacto -----------------------------------------------------
    elseif ($que === 'contacto') {
        $id = (string) ($_POST['id'] ?? '');

        if (!id_valido($id) || ($etapa = etapa_de($id)) === null) {
            $aviso = ['mal', 'Lead no encontrado.'];
        } else {
            $lead = cargar_lead($etapa, $id);
            $web  = $lead['web'] ?? ($lead['verificacion_dominio']['web'] ?? null);

            if (empty($web)) {
                $aviso = ['aviso', 'Este lead no tiene web: su contacto es el teléfono.'];
            } else {
                $c = contacto_de_web($web);
                $lead['contacto'] = $c + ['fecha' => date('c'), 'fuente' => $web];
                guardar_lead($etapa, $id, $lead);
                $aviso = ['bien', sprintf('%d correo(s) y %d red(es) encontradas.',
                    count($c['email']), count($c['redes']))];
            }
        }
    }

    // --- mover de etapa ------------------------------------------------------
    else {
        $id     = (string) ($_POST['id'] ?? '');
        $desde  = (string) ($_POST['desde'] ?? '');
        $accion = (string) ($_POST['accion'] ?? '');

        if (!id_valido($id) || !etapa_valida($desde)) {
            $aviso = ['mal', 'Identificador o etapa no válidos.'];
        } elseif (!isset(ACCIONES[$desde][$accion])) {
            $aviso = ['mal', "Esa acción no está permitida desde {$desde}."];
        } else {
            [$hacia, $nota] = ACCIONES[$desde][$accion];
            $aviso = promover($id, $desde, $hacia, $nota)
                ? ['bien', ETIQUETAS[$hacia][0] . ': lead movido.']
                : ['mal', "Ese lead ya no estaba en {$desde}."];
        }
    }
}

// ===========================================================================
//  ESTADO
// ===========================================================================

$recuento = recuento();
$total    = array_sum($recuento);

$etapaActual = (string) ($_GET['etapa'] ?? '');
if (!etapa_valida($etapaActual)) {
    $etapaActual = '00-descubierto';
    foreach (ETIQUETAS as $e => $_) {
        if (($recuento[$e] ?? 0) > 0) { $etapaActual = $e; break; }
    }
    if (($recuento['40-por-aprobar'] ?? 0) > 0) { $etapaActual = '40-por-aprobar'; }
}

$busqueda = trim((string) ($_GET['q'] ?? ''));
$abierto  = (string) ($_GET['id'] ?? '');
$soloSinWeb = isset($_GET['sinweb']);

$leads = [];
foreach (leads_en($etapaActual) as $lid) {
    $l = cargar_lead($etapaActual, $lid);
    if ($busqueda !== '' && stripos($l['nombre'] . ' ' . ($l['zona'] ?? ''), $busqueda) === false) {
        continue;
    }
    if ($soloSinWeb && !in_array($l['web_tipo'] ?? '', ['ninguna', 'directorio'], true)) {
        continue;
    }
    $l['_id'] = $lid;
    $leads[] = $l;
}

// Primero los que mas reputacion tienen sin web: son los mejores leads.
usort($leads, static function (array $a, array $b): int {
    $pa = (in_array($a['web_tipo'] ?? '', ['ninguna', 'directorio'], true) ? 10000 : 0) + (int) ($a['resenas'] ?? 0);
    $pb = (in_array($b['web_tipo'] ?? '', ['ninguna', 'directorio'], true) ? 10000 : 0) + (int) ($b['resenas'] ?? 0);
    return $pb <=> $pa;
});

$sinWebTotal = $conContacto = $porRevisar = 0;
foreach (ETIQUETAS as $e => $_) {
    foreach (leads_en($e) as $lid) {
        $l = cargar_lead($e, $lid);
        if (in_array($l['web_tipo'] ?? '', ['ninguna', 'directorio'], true)) { $sinWebTotal++; }
        if (!empty($l['telefono']) || !empty($l['contacto']['email'])) { $conContacto++; }
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
<meta name="color-scheme" content="light dark">
<title>Consola · AlastreSystem</title>
<script>
// Antes de pintar, para que no haya destello del tema equivocado al cargar.
(function(){
  var t = localStorage.getItem('tema');
  if (!t) { t = matchMedia('(prefers-color-scheme: dark)').matches ? 'oscuro' : 'claro'; }
  document.documentElement.dataset.tema = t;
})();
</script>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="barra">
  <a class="barra__marca" href="?">
    <svg width="26" height="26" viewBox="0 0 32 32" fill="none" aria-hidden="true">
      <circle cx="16" cy="16" r="15" stroke="currentColor" stroke-width="1.6" opacity=".28"/>
      <path d="M16 24v-8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
      <circle cx="11.5" cy="12" r="3" fill="#4F6E54"/><circle cx="16" cy="8.6" r="3" fill="#5B3E86"/>
      <circle cx="20.5" cy="12" r="3" fill="#B08430"/>
    </svg>
    AlastreSystem
  </a>

  <form class="buscador" method="get">
    <input type="hidden" name="etapa" value="<?= h($etapaActual) ?>">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
    <input type="search" name="q" value="<?= h($busqueda) ?>" placeholder="Buscar por nombre o zona…" aria-label="Buscar">
  </form>

  <button type="button" class="tema" id="tema" aria-label="Cambiar tema" title="Cambiar entre claro y oscuro">
    <svg class="tema__sol" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
      <circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>
    </svg>
    <svg class="tema__luna" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10Z"/>
    </svg>
  </button>

  <div class="barra__total"><b><?= $total ?></b> leads</div>
</header>

<?php if ($aviso !== null): ?>
  <div class="env"><div class="aviso aviso--<?= h($aviso[0]) ?>"><?= h($aviso[1]) ?></div></div>
<?php endif; ?>

<main class="env">

  <!-- ===== descubrir ===== -->
  <details class="descubrir" <?= $total === 0 ? 'open' : '' ?>>
    <summary>
      <span class="descubrir__tit">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        Descubrir negocios
      </span>
      <span class="descubrir__sub">Busca en Google Places y vuelca los resultados al pipeline</span>
    </summary>

    <form class="form" method="post">
      <input type="hidden" name="que" value="descubrir">

      <div class="campo campo--ancho">
        <label for="termino">Término de búsqueda</label>
        <input id="termino" name="termino" list="verticales" required
               placeholder="psicólogo, clínica dental, restaurante…" value="<?= h((string) ($_POST['termino'] ?? '')) ?>">
        <datalist id="verticales">
          <?php foreach (VERTICALES as $v => $t): if ($t === '') { continue; } ?>
            <option value="<?= h($t) ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <small>Sé específico. «psicología» devuelve facultades; «psicólogo infantil» devuelve consultas.</small>
      </div>

      <div class="campo campo--ancho">
        <label for="lugar">Localización</label>
        <input id="lugar" name="lugar" required
               placeholder="Benimaclet, Valencia" value="<?= h((string) ($_POST['lugar'] ?? '')) ?>">
        <small>Por barrio mejor que por ciudad: los términos amplios devuelven los negocios más grandes.</small>
      </div>

      <div class="campo">
        <label for="vertical">Vertical (etiqueta)</label>
        <select id="vertical" name="vertical">
          <?php foreach (VERTICALES as $v => $t): ?>
            <option value="<?= h($v) ?>"><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="campo">
        <label for="cuantos">Nº de lugares</label>
        <input id="cuantos" name="cuantos" type="number" min="1" max="60" value="20">
      </div>

      <details class="avanzado campo--todo">
        <summary>Geolocalización y opciones</summary>
        <div class="form form--interno">
          <div class="campo">
            <label for="lat">Latitud</label>
            <input id="lat" name="lat" placeholder="39.4840" inputmode="decimal">
          </div>
          <div class="campo">
            <label for="lng">Longitud</label>
            <input id="lng" name="lng" placeholder="-0.3576" inputmode="decimal">
          </div>
          <div class="campo">
            <label for="radio">Radio (m)</label>
            <input id="radio" name="radio" type="number" min="100" max="50000" value="3000">
          </div>
          <div class="campo campo--todo">
            <small>Con coordenadas la búsqueda se acota a ese círculo, que es lo que de verdad
            saca consultas pequeñas. Sácalas de Google Maps: clic derecho sobre el punto.</small>
          </div>
        </div>
      </details>

      <div class="opciones campo--todo">
        <label class="check">
          <input type="checkbox" name="sin_web" value="1" checked>
          <span>Solo los que no tienen web <em>— son los mejores leads</em></span>
        </label>
        <label class="check">
          <input type="checkbox" name="completo" value="1">
          <span>Traer reseñas y fotos <em>— sube el tramo de precio de la API</em></span>
        </label>
      </div>

      <div class="campo--todo">
        <button class="btn btn--principal" type="submit">
          Buscar en Places
        </button>
        <span class="nota-coste">Cada búsqueda consume crédito de tu cuenta de Google.</span>
      </div>
    </form>
  </details>

  <!-- ===== métricas ===== -->
  <section class="kpis">
    <article class="kpi"><span class="kpi__n"><?= $total ?></span><span class="kpi__t">En el pipeline</span></article>
    <article class="kpi kpi--bien"><span class="kpi__n"><?= $sinWebTotal ?></span><span class="kpi__t">Sin web propia</span></article>
    <article class="kpi kpi--info"><span class="kpi__n"><?= $conContacto ?></span><span class="kpi__t">Con vía de contacto</span></article>
    <article class="kpi <?= ($recuento['40-por-aprobar'] ?? 0) > 0 ? 'kpi--accion' : '' ?>"><span class="kpi__n"><?= $recuento['40-por-aprobar'] ?? 0 ?></span><span class="kpi__t">Esperan tu aprobación</span></article>
    <article class="kpi <?= $porRevisar > 0 ? 'kpi--aviso' : '' ?>"><span class="kpi__n"><?= $porRevisar ?></span><span class="kpi__t">Con dato por verificar</span></article>
  </section>

  <!-- ===== pipeline ===== -->
  <nav class="flujo" aria-label="Etapas del pipeline">
    <?php foreach (ETIQUETAS as $etapa => [$titulo, $tono]): $n = $recuento[$etapa] ?? 0; ?>
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
      <span class="lista__meta"><?= count($leads) ?> lead<?= count($leads) === 1 ? '' : 's' ?></span>
      <a class="filtro <?= $soloSinWeb ? 'es-activo' : '' ?>"
         href="?etapa=<?= h($etapaActual) ?><?= $soloSinWeb ? '' : '&sinweb=1' ?><?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?>">
        <?= $soloSinWeb ? '✓ ' : '' ?>solo sin web
      </a>
    </div>

    <?php if ($leads === []): ?>
      <div class="vacio">
        <p>No hay nada aquí.</p>
        <p class="pista">Abre «Descubrir negocios» arriba y lanza una búsqueda.</p>
      </div>
    <?php else: ?>
      <ul class="filas">
      <?php foreach ($leads as $i => $l):
        $lid = (string) $l['_id'];
        $exp = $lid === $abierto;
        $hallazgos = $l['hallazgos'] ?? [];
        $verif = $l['verificacion_dominio'] ?? null;
        $cont  = $l['contacto'] ?? null;
        $fotos = $l['fotos_descargadas']['archivos'] ?? [];
      ?>
        <li class="fila <?= $exp ? 'es-abierto' : '' ?>" style="--i:<?= min($i, 12) ?>">
          <div class="fila__cab">
            <a class="fila__nombre" id="<?= h($lid) ?>"
               href="?etapa=<?= h($etapaActual) ?><?= $exp ? '' : '&id=' . urlencode($lid) ?><?= $busqueda !== '' ? '&q=' . urlencode($busqueda) : '' ?><?= $soloSinWeb ? '&sinweb=1' : '' ?>#<?= h($lid) ?>">
              <span class="fila__chevron" aria-hidden="true"></span>
              <?= h($l['nombre']) ?>
            </a>

            <div class="fila__marcas">
              <?php if (!empty($l['zona'])): ?><span class="marca"><?= h(mb_substr((string) $l['zona'], 0, 20)) ?></span><?php endif; ?>

              <?php if (($l['web_tipo'] ?? '') === 'ninguna'): ?><span class="marca marca--bien">sin web</span>
              <?php elseif (($l['web_tipo'] ?? '') === 'directorio'): ?><span class="marca marca--bien">solo directorio</span>
              <?php elseif (!empty($l['web'])): ?><span class="marca">tiene web</span><?php endif; ?>

              <?php if (!empty($l['valoracion'])): ?>
                <span class="marca marca--oro"><?= h(number_format((float) $l['valoracion'], 1, ',', '')) ?>★ <?= (int) ($l['resenas'] ?? 0) ?></span>
              <?php endif; ?>

              <?php if (!empty($l['telefono'])): ?><span class="marca marca--info">tel</span><?php endif; ?>
              <?php if (!empty($cont['email'])): ?><span class="marca marca--info">correo</span><?php endif; ?>
              <?php if (!empty($cont['redes'])): ?><span class="marca marca--info"><?= count($cont['redes']) ?> redes</span><?php endif; ?>
              <?php if (!empty($l['fotos'])): ?><span class="marca marca--tenue"><?= count($l['fotos']) ?> fotos</span><?php endif; ?>
              <?php if ($verif !== null && ($verif['resultado'] ?? '') === 'encontrada'): ?>
                <span class="marca marca--mal">web hallada</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($exp): ?>
            <div class="ficha">

              <?php if ($fotos !== []): ?>
                <div class="galeria">
                  <?php foreach ($fotos as $f): ?>
                    <a href="<?= h($f) ?>" target="_blank" rel="noopener"><img src="<?= h($f) ?>" alt="" loading="lazy"></a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <!-- contacto: lo primero, porque sin contacto no hay cliente -->
              <h2 class="ficha__tit">Contacto</h2>
              <div class="contactos">
                <?php if (!empty($l['telefono'])): ?>
                  <a class="cont cont--tel" href="tel:<?= h(preg_replace('/\s+/', '', (string) $l['telefono'])) ?>">
                    <b>Teléfono</b><span><?= h((string) $l['telefono']) ?></span>
                  </a>
                <?php endif; ?>
                <?php foreach ($cont['email'] ?? [] as $mail): ?>
                  <a class="cont cont--mail" href="mailto:<?= h($mail) ?>"><b>Correo</b><span><?= h($mail) ?></span></a>
                <?php endforeach; ?>
                <?php
                /* Cuando la "web" que devuelve Places es un perfil social, eso
                   ya es una via de contacto: no tiene sentido esconderla bajo
                   la etiqueta de directorio. Se fusiona con las que haya
                   encontrado el extractor, sin repetir portal. */
                $redes = $cont['redes'] ?? [];
                if (($l['web_tipo'] ?? '') === 'directorio' && !empty($l['web'])) {
                    foreach (['facebook','instagram','linkedin','tiktok','youtube','wa.me','whatsapp','doctoralia','eholo','topdoctors'] as $portal) {
                        if (str_contains(mb_strtolower((string) $l['web']), $portal)) {
                            $nombre = in_array($portal, ['wa.me','whatsapp'], true) ? 'whatsapp' : $portal;
                            $redes[$nombre] ??= (string) $l['web'];
                            break;
                        }
                    }
                }
                foreach ($redes as $red => $url): ?>
                  <a class="cont cont--red" href="<?= h($url) ?>" target="_blank" rel="noopener"><b><?= h($red) ?></b><span>abrir perfil</span></a>
                <?php endforeach; ?>
                <?php if (!empty($l['maps_url'])): ?>
                  <a class="cont" href="<?= h((string) $l['maps_url']) ?>" target="_blank" rel="noopener"><b>Google Maps</b><span>ficha</span></a>
                <?php endif; ?>
                <?php if (empty($l['telefono']) && empty($cont['email'])): ?>
                  <p class="pista">Sin vía de contacto todavía. Si tiene web, pulsa «Buscar contacto».</p>
                <?php endif; ?>
              </div>

              <div class="ficha__datos">
                <?php foreach ([
                  'Dirección' => $l['direccion'] ?? null,
                  'Web'       => $l['web'] ?? null,
                  'Categoría' => $l['categoria'] ?? null,
                  'Origen'    => $l['origen'] ?? null,
                ] as $et => $val): if (empty($val)) { continue; } ?>
                  <div class="dato"><dt><?= h($et) ?></dt><dd><?= h((string) $val) ?></dd></div>
                <?php endforeach; ?>
              </div>

              <?php if ($hallazgos !== []): ?>
                <h2 class="ficha__tit">Hallazgos citables</h2>
                <ul class="hallazgos">
                  <?php foreach ($hallazgos as $hh): ?>
                    <li>
                      <span class="grav g-<?= h((string) $hh['gravedad']) ?>"><?= h((string) $hh['gravedad']) ?></span>
                      <span><?= h((string) $hh['citable']) ?>
                        <?php if (!empty($hh['verificar'])): ?>
                          <strong class="verificar">Búscalo en Google antes de citarlo.</strong>
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
                    <a href="<?= h((string) $verif['web']) ?>" target="_blank" rel="noopener"><?= h((string) $verif['web']) ?></a>
                    — <?= h((string) $verif['prueba']) ?> (confianza <?= h((string) $verif['confianza']) ?>).
                  <?php else: ?>
                    Sin indicios tras probar <?= (int) ($verif['candidatos'] ?? 0) ?> dominios.
                  <?php endif; ?>
                </p>
              <?php endif; ?>

              <?php if (!empty($l['resenas_texto'])): ?>
                <h2 class="ficha__tit">Reseñas <span class="ficha__aux">— qué valora su clientela, material para el copy</span></h2>
                <ul class="resenas">
                  <?php foreach ($l['resenas_texto'] as $r): if (empty($r['texto'])) { continue; } ?>
                    <li>
                      <div class="resena__cab">
                        <span class="resena__nota"><?= h((string) ($r['puntuacion'] ?? '?')) ?>★</span>
                        <span class="resena__autor"><?= h((string) ($r['autor'] ?? 'anónimo')) ?></span>
                        <time><?= h(substr((string) ($r['fecha'] ?? ''), 0, 10)) ?></time>
                      </div>
                      <p><?= h(mb_substr((string) $r['texto'], 0, 380)) ?><?= mb_strlen((string) $r['texto']) > 380 ? '…' : '' ?></p>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>

              <?php if (!empty($l['horario'])): ?>
                <h2 class="ficha__tit">Horario</h2>
                <div class="medidas">
                  <?php foreach ($l['horario'] as $dia): ?><span class="medida"><?= h((string) $dia) ?></span><?php endforeach; ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($l['mediciones'])): ?>
                <h2 class="ficha__tit">Mediciones</h2>
                <div class="medidas">
                  <?php foreach ($l['mediciones'] as $k => $v): ?>
                    <span class="medida"><b><?= h((string) $k) ?></b> <?= h(is_bool($v) ? ($v ? 'sí' : 'no') : (string) ($v ?? '—')) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <div class="acciones">
                <?php if (!empty($l['landing'])): ?>
                  <a class="btn btn--ver" href="<?= h((string) $l['landing']) ?>" target="_blank" rel="noopener">Ver landing</a>
                <?php endif; ?>

                <?php if (!empty($l['fotos'])): ?>
                  <form method="post"><input type="hidden" name="que" value="fotos"><input type="hidden" name="id" value="<?= h($lid) ?>">
                    <button class="btn"><?= $fotos === [] ? 'Traer fotos' : 'Actualizar fotos' ?><span class="btn__destino"><?= count($l['fotos']) ?> disponibles</span></button>
                  </form>
                <?php endif; ?>

                <form method="post"><input type="hidden" name="que" value="contacto"><input type="hidden" name="id" value="<?= h($lid) ?>">
                  <button class="btn">Buscar contacto<span class="btn__destino">en su web</span></button>
                </form>

                <form method="post">
                  <input type="hidden" name="id" value="<?= h($lid) ?>">
                  <input type="hidden" name="desde" value="<?= h($etapaActual) ?>">
                  <?php foreach (ACCIONES[$etapaActual] ?? [] as $clave => [$destino, $_n]):
                    $clase = $clave === 'suprimir' ? 'btn--stop' : ($clave === 'descartar' ? 'btn--no' : 'btn--ok'); ?>
                    <button class="btn <?= $clase ?>" name="accion" value="<?= h($clave) ?>">
                      <?= h(ucfirst($clave)) ?><span class="btn__destino"><?= h(ETIQUETAS[$destino][0]) ?></span>
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

<script>
// Cambio de tema, recordado entre visitas.
document.getElementById('tema').addEventListener('click', function () {
  var raiz = document.documentElement;
  var nuevo = raiz.dataset.tema === 'oscuro' ? 'claro' : 'oscuro';
  raiz.dataset.tema = nuevo;
  localStorage.setItem('tema', nuevo);
});

// El formulario de descubrimiento gasta credito: se avisa de que esta en curso
// para que nadie lo dispare dos veces pensando que no ha hecho nada.
document.querySelectorAll('form').forEach(function (f) {
  f.addEventListener('submit', function () {
    var b = f.querySelector('button[type="submit"], button.btn--principal');
    if (b) { b.disabled = true; b.classList.add('es-cargando'); }
  });
});
</script>
</body>
</html>
