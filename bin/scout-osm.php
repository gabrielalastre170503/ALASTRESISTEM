<?php
/**
 * AlastreSystem - Scout sobre OpenStreetMap.
 *
 *   php bin/scout-osm.php psicologia "Valencia, Espana" [--max=40]
 *   php bin/scout-osm.php --listar
 *
 * Alternativa gratuita a bin/scout.php: no necesita clave ni facturacion.
 * Usa Nominatim para localizar la zona y Overpass para pedir los negocios.
 *
 * Limitacion honesta: OSM cubre los negocios pequenos de Espana bastante peor
 * que Google. Encontrara menos, y muchos sin telefono. A cambio es gratis,
 * legal y sin tarjeta. Para la Fase 0 basta; si el volumen se queda corto, ahi
 * es donde compensa pagar la Places API.
 *
 * Ambos servicios son de uso comunitario y gratuito: este script va despacio a
 * proposito y se identifica. No conviene quitarle las pausas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

/**
 * Etiquetas de OSM por vertical. Un negocio puede estar etiquetado de varias
 * formas segun quien lo mapeara, asi que cada vertical prueba varias.
 */
const VERTICALES = [
    'psicologia'   => ['healthcare=psychotherapist', 'healthcare=counselling', 'office=therapist'],
    'dentista'     => ['amenity=dentist', 'healthcare=dentist'],
    'fisioterapia' => ['healthcare=physiotherapist'],
    'veterinario'  => ['amenity=veterinary'],
    'abogado'      => ['office=lawyer'],
    'gestoria'     => ['office=accountant', 'office=tax_advisor'],
    'peluqueria'   => ['shop=hairdresser'],
    'estetica'     => ['shop=beauty', 'leisure=spa'],
    'clinica'      => ['amenity=clinic', 'healthcare=centre'],
    'optica'       => ['shop=optician'],
    'nutricion'    => ['healthcare=nutrition_counselling'],
    'restaurante'  => ['amenity=restaurant'],
    'hotel'        => ['tourism=hotel'],
    'gimnasio'     => ['leisure=fitness_centre'],
];

const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

/**
 * Espejos de Overpass. La instancia principal se satura a menudo y devuelve
 * 504; el servicio es comunitario y gratuito, asi que en vez de insistir sobre
 * la misma se prueba la siguiente.
 */
const OVERPASS = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
    'https://overpass.private.coffee/api/interpreter',
];

$opciones = getopt('', ['max::', 'listar']);

if (isset($opciones['listar'])) {
    echo "Verticales disponibles:\n";
    foreach (VERTICALES as $nombre => $tags) {
        printf("  %-14s %s\n", $nombre, implode(', ', $tags));
    }
    exit(0);
}

$argumentos = array_values(array_filter(
    array_slice($argv, 1),
    static fn(string $a): bool => !str_starts_with($a, '--')
));

$vertical = $argumentos[0] ?? '';
$zona     = $argumentos[1] ?? '';
$maximo   = (int) ($opciones['max'] ?? 40);

if ($vertical === '' || $zona === '') {
    exit(
        "Uso: php bin/scout-osm.php <vertical> \"<zona>\" [--max=40]\n" .
        "     php bin/scout-osm.php --listar\n\n" .
        "Ejemplo:\n" .
        "  php bin/scout-osm.php psicologia \"Valencia, Espana\"\n"
    );
}

if (!isset(VERTICALES[$vertical])) {
    exit("Vertical desconocido. Mira las disponibles con --listar\n");
}

// --- 1. localizar la zona --------------------------------------------------

echo "Buscando la zona \"{$zona}\"...\n";
$area = area_de($zona);

if ($area === null) {
    exit("No se pudo localizar esa zona. Prueba con \"Ciudad, Pais\".\n");
}

printf(
    "  %s\n  caja: %.3f,%.3f a %.3f,%.3f\n\n",
    mb_substr($area['nombre'], 0, 72),
    $area['caja'][0], $area['caja'][1], $area['caja'][2], $area['caja'][3]
);

// --- 2. pedir los negocios -------------------------------------------------

$consulta = consulta_overpass($area, VERTICALES[$vertical]);

$datos = null;

foreach (OVERPASS as $i => $servidor) {
    printf("Consultando Overpass (%d/%d, puede tardar)...\n", $i + 1, count(OVERPASS));
    $res = http_post_form($servidor, ['data' => $consulta], 120);

    if ($res['estado'] === 200 && ($d = json_o_null($res['cuerpo'])) !== null) {
        $datos = $d;
        break;
    }

    printf("  %s: %s\n", parse_url($servidor, PHP_URL_HOST) ?: '?',
        $res['error'] ?? 'HTTP ' . $res['estado']);

    if ($i < count(OVERPASS) - 1) {
        sleep(3);
    }
}

if ($datos === null) {
    exit("\nNingun espejo de Overpass respondio. Suelen saturarse: reintenta en unos minutos.\n");
}

$elementos = $datos['elements'] ?? [];

printf("  %d elementos devueltos\n\n", count($elementos));

// --- 3. volcarlos al pipeline ----------------------------------------------

$nuevos = $repetidos = $sinNombre = 0;

foreach ($elementos as $el) {
    if ($nuevos >= $maximo) {
        break;
    }

    $tags   = $el['tags'] ?? [];
    $nombre = trim((string) ($tags['name'] ?? ''));

    if ($nombre === '') {
        $sinNombre++;
        continue;
    }

    $id = 'osm_' . ($el['type'] ?? 'node') . '_' . ($el['id'] ?? '');

    if (($etapa = etapa_de($id)) !== null) {
        echo "  = ya estaba ({$etapa}): {$nombre}\n";
        $repetidos++;
        continue;
    }

    [$web, $tipoWeb] = clasificar_web_osm($tags);

    guardar_lead('00-descubierto', $id, [
        'place_id'    => $id,
        'nombre'      => $nombre,
        'direccion'   => direccion_de($tags),
        'web'         => $web,
        'web_tipo'    => $tipoWeb,
        'telefono'    => $tags['phone'] ?? $tags['contact:phone'] ?? null,
        // OSM no tiene valoraciones ni resenas: ese hallazgo no estara.
        'valoracion'  => null,
        'resenas'     => null,
        'categoria'   => $tags['healthcare'] ?? $tags['office'] ?? $tags['amenity'] ?? $tags['shop'] ?? null,
        'vertical'    => $vertical,
        'zona'        => $area['nombre'],
        'origen'      => 'openstreetmap',
        'osm'         => ['tipo' => $el['type'] ?? null, 'id' => $el['id'] ?? null],
        'descubierto' => date('c'),
        'hallazgos'   => [],
        'landing'     => null,
        'borrador'    => null,
        'historial'   => [
            ['etapa' => '00-descubierto', 'fecha' => date('c'), 'nota' => 'scout openstreetmap'],
        ],
    ]);

    $nuevos++;
    printf("  + %-42s %s\n", mb_substr($nombre, 0, 42),
        ['ninguna' => '[SIN WEB]', 'directorio' => '[SOLO DIRECTORIO]', 'propia' => ''][$tipoWeb]);
}

echo "\n";
printf("Nuevos: %d   Repetidos: %d   Sin nombre (descartados): %d\n", $nuevos, $repetidos, $sinNombre);

if ($nuevos > 0) {
    echo "\nSiguiente paso: php bin/auditar.php --limite={$nuevos}\n";
    echo "Y despues:      php bin/verificar.php\n";
}

// ---------------------------------------------------------------------------

/**
 * Localiza la zona con Nominatim.
 *
 * Hay que filtrar por limite administrativo: pidiendo "Valencia, Espana" el
 * primer resultado que devuelve es la Plaza de Espana, una plaza dentro de
 * Valencia. Quedarse con el primero sin mirar da consultas absurdas.
 *
 * Si no aparece ninguna relacion administrativa se cae a la caja envolvente
 * del mejor resultado, que es menos preciso pero funciona.
 *
 * @return array{nombre:string, osm_id:?int, area_id:?int, caja:?array{float,float,float,float}}|null
 */
function area_de(string $zona): ?array
{
    $url = NOMINATIM . '?' . http_build_query([
        'q'      => $zona,
        'format' => 'json',
        'limit'  => 10,
    ]);

    // Nominatim pide identificarse y como maximo una peticion por segundo.
    $res = http_get($url, ['Accept-Language' => 'es'], 25);
    sleep(1);

    if ($res['estado'] !== 200) {
        return null;
    }

    $datos = json_decode($res['cuerpo'], true);

    if (!is_array($datos) || $datos === []) {
        return null;
    }

    $tiposValidos = ['city', 'town', 'municipality', 'village', 'administrative', 'state', 'province', 'suburb'];

    foreach ($datos as $r) {
        if (($r['osm_type'] ?? '') !== 'relation') {
            continue;
        }
        $tipo = $r['addresstype'] ?? $r['type'] ?? '';
        if (($r['class'] ?? '') !== 'boundary' && !in_array($tipo, $tiposValidos, true)) {
            continue;
        }

        $caja = $r['boundingbox'] ?? null;

        if (!is_array($caja) || count($caja) !== 4) {
            continue;
        }

        return [
            'nombre'  => (string) ($r['display_name'] ?? $zona),
            'osm_id'  => (int) $r['osm_id'],
            'caja'    => [(float) $caja[0], (float) $caja[2], (float) $caja[1], (float) $caja[3]],
        ];
    }

    // Respaldo: caja envolvente del primer resultado.
    $caja = $datos[0]['boundingbox'] ?? null;

    if (!is_array($caja) || count($caja) !== 4) {
        return null;
    }

    return [
        'nombre'  => (string) ($datos[0]['display_name'] ?? $zona),
        'osm_id'  => null,
        'caja'    => [(float) $caja[0], (float) $caja[2], (float) $caja[1], (float) $caja[3]],
    ];
}

/**
 * Consulta por caja envolvente, no por area.
 *
 * Con area(...) Overpass tiene que resolver el poligono administrativo entero
 * y las tres instancias devolvieron 504 para Valencia. La misma consulta por
 * caja responde en menos de dos segundos. La caja es un rectangulo, asi que
 * arrastra algo de alrededores: para captar leads eso no estorba.
 *
 * @param array{caja:array{float,float,float,float}} $area
 * @param list<string> $tags Pares clave=valor
 */
function consulta_overpass(array $area, array $tags): string
{
    $filtro = sprintf('(%F,%F,%F,%F)', ...$area['caja']);

    $partes = [];
    foreach ($tags as $par) {
        [$clave, $valor] = explode('=', $par, 2);
        // Nodos y vias: un negocio puede estar mapeado como punto o como edificio.
        foreach (['node', 'way'] as $tipo) {
            $partes[] = sprintf('%s["%s"="%s"]%s;', $tipo, $clave, $valor, $filtro);
        }
    }

    return "[out:json][timeout:50];\n"
        . "(\n  " . implode("\n  ", $partes) . "\n);\n"
        . 'out center tags;';
}

/**
 * @param array<string,string> $tags
 */
function direccion_de(array $tags): ?string
{
    $partes = array_filter([
        trim(($tags['addr:street'] ?? '') . ' ' . ($tags['addr:housenumber'] ?? '')),
        $tags['addr:postcode'] ?? '',
        $tags['addr:city'] ?? '',
    ]);

    return $partes === [] ? null : implode(', ', $partes);
}

/**
 * @param array<string,string> $tags
 * @return array{0:?string, 1:string}
 */
function clasificar_web_osm(array $tags): array
{
    $bruta = trim((string) ($tags['website'] ?? $tags['contact:website'] ?? ''));

    if ($bruta === '') {
        // Estar solo en Facebook es el caso "solo directorio" de siempre.
        if (!empty($tags['contact:facebook']) || !empty($tags['facebook'])) {
            return [null, 'directorio'];
        }
        return [null, 'ninguna'];
    }

    $url = preg_match('#^https?://#i', $bruta) ? $bruta : 'https://' . $bruta;

    return filter_var($url, FILTER_VALIDATE_URL) !== false
        ? [$url, 'propia']
        : [null, 'ninguna'];
}
