<?php
/**
 * AlastreSystem - importador manual de leads.
 *
 *   php bin/importar.php leads.csv "psicologia" "Valencia, Espana"
 *
 * Para la Fase 0: buscas en Google Maps, exportas o copias a un CSV, y esto
 * lo mete en el pipeline. Sin Places API y sin facturacion.
 *
 * Las columnas se detectan por el nombre de la cabecera, no por posicion, asi
 * que da igual el orden y sobran las columnas que no usemos. Reconoce:
 *
 *   nombre / negocio          (obligatoria)
 *   sitio web / url / web
 *   telefono
 *   calificacion / valoracion
 *   resenas / total resenas
 *   direccion
 *   categoria
 *
 * Sin cabecera reconocible cae a modo posicional: nombre;web;telefono.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

/** Portales donde el negocio esta listado pero la web no es suya. */
const DIRECTORIOS = [
    'doctoralia', 'wa.me', 'whatsapp', 'facebook', 'instagram', 'linkedin',
    'eholo', 'topdoctors', 'paginasamarillas', 'milanuncios', 'sites.google',
    'business.site', 'linktr.ee', 'booksy', 'mapsofmind',
];

$archivo  = $argv[1] ?? '';
$vertical = $argv[2] ?? 'sin-clasificar';
$zonaPorDefecto = $argv[3] ?? 'sin-zona';

if ($archivo === '' || !is_readable($archivo)) {
    exit(
        "Uso: php bin/importar.php <archivo.csv> \"<vertical>\" \"<zona>\"\n\n" .
        "Ejemplo:\n" .
        "  php bin/importar.php leads.csv \"psicologia\" \"Valencia, Espana\"\n"
    );
}

$texto = (string) file_get_contents($archivo);
$texto = reparar_codificacion($texto);

$lineas = preg_split('/\R/', $texto) ?: [];
$filas  = [];

foreach ($lineas as $linea) {
    if (trim($linea) === '') {
        continue;
    }
    $filas[] = str_getcsv($linea, detectar_separador($lineas[0] ?? ''));
}

if ($filas === []) {
    exit("El archivo esta vacio.\n");
}

$columnas = mapear_columnas($filas[0]);

if ($columnas === null) {
    echo "Sin cabecera reconocible: uso modo posicional (nombre;web;telefono).\n";
    $columnas = ['nombre' => 0, 'web' => 1, 'telefono' => 2];
} else {
    array_shift($filas); // la cabecera no es un lead
}

$nuevos = 0;
$repetidos = 0;

foreach ($filas as $fila) {
    $nombre = valor($fila, $columnas, 'nombre');

    if ($nombre === '') {
        continue;
    }

    $webCruda  = valor($fila, $columnas, 'web');
    $direccion = valor($fila, $columnas, 'direccion');
    $zona      = ciudad_de($direccion) ?? $zonaPorDefecto;

    [$web, $tipoWeb] = clasificar_web($webCruda);

    $id = 'manual_' . substr(hash('sha256', mb_strtolower($nombre) . '|' . $zona), 0, 24);

    if (($etapa = etapa_de($id)) !== null) {
        echo "  = ya estaba ({$etapa}): {$nombre}\n";
        $repetidos++;
        continue;
    }

    $valoracion = valor($fila, $columnas, 'valoracion');
    $resenas    = valor($fila, $columnas, 'resenas');

    guardar_lead('00-descubierto', $id, [
        'place_id'    => $id,
        'nombre'      => $nombre,
        'direccion'   => $direccion !== '' ? $direccion : null,
        'web'         => $web,
        'web_tipo'    => $tipoWeb, // propia | directorio | ninguna
        'web_cruda'   => $webCruda !== '' ? $webCruda : null,
        'telefono'    => ($t = valor($fila, $columnas, 'telefono')) !== '' ? $t : null,
        'valoracion'  => is_numeric(str_replace(',', '.', $valoracion))
            ? (float) str_replace(',', '.', $valoracion) : null,
        'resenas'     => is_numeric($resenas) ? (int) $resenas : null,
        'categoria'   => ($c = valor($fila, $columnas, 'categoria')) !== '' ? $c : null,
        'vertical'    => $vertical,
        'zona'        => $zona,
        'origen'      => 'manual',
        'descubierto' => date('c'),
        'hallazgos'   => [],
        'landing'     => null,
        'borrador'    => null,
        'historial'   => [
            ['etapa' => '00-descubierto', 'fecha' => date('c'), 'nota' => 'importado a mano'],
        ],
    ]);

    $nuevos++;
    printf(
        "  + %-38s %-12s %s\n",
        mb_substr($nombre, 0, 38),
        mb_substr($zona, 0, 12),
        ['ninguna' => '[SIN WEB]', 'directorio' => '[SOLO DIRECTORIO]', 'propia' => ''][$tipoWeb]
    );
}

echo "\nNuevos: {$nuevos}   Repetidos: {$repetidos}\n";

if ($nuevos > 0) {
    echo "\nSiguiente paso: php bin/auditar.php --limite={$nuevos}\n";
}

// ---------------------------------------------------------------------------

/**
 * Quita el BOM y repara el mojibake tipico de un CSV UTF-8 leido como Latin-1
 * (PsicÃ³logo en vez de Psicologo). Exportar desde Excel lo provoca a menudo.
 */
function reparar_codificacion(string $texto): string
{
    $texto = preg_replace('/^\x{FEFF}/u', '', $texto) ?? $texto;
    $texto = str_replace("\xEF\xBB\xBF", '', $texto);

    // Si aparecen estas secuencias, los bytes UTF-8 se leyeron como Latin-1.
    if (preg_match('/Ã[\x80-\xBF]|Â[\x80-\xBF]/u', $texto)) {
        $reparado = mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');

        if (mb_check_encoding($reparado, 'UTF-8')) {
            echo "  (codificacion reparada: el CSV venia mal exportado)\n";
            return $reparado;
        }
    }

    return $texto;
}

function detectar_separador(string $cabecera): string
{
    return substr_count($cabecera, ';') > substr_count($cabecera, ',') ? ';' : ',';
}

/**
 * Empareja los nombres de la cabecera con los campos que usamos.
 *
 * @param list<string> $cabecera
 * @return array<string,int>|null
 */
function mapear_columnas(array $cabecera): ?array
{
    $alias = [
        'nombre'     => ['nombre', 'negocio', 'empresa', 'titulo', 'name'],
        'web'        => ['sitio web / url', 'sitio web', 'web', 'url', 'website'],
        'telefono'   => ['telefono', 'tel', 'phone', 'movil'],
        'valoracion' => ['calificacion', 'valoracion', 'puntuacion', 'rating'],
        'resenas'    => ['total resenas', 'resenas', 'opiniones', 'reviews'],
        'direccion'  => ['direccion', 'address', 'ubicacion'],
        'categoria'  => ['categoria', 'tipo', 'category'],
    ];

    $mapa = [];

    foreach ($cabecera as $i => $celda) {
        $limpia = normalizar($celda);

        foreach ($alias as $campo => $posibles) {
            if (isset($mapa[$campo])) {
                continue;
            }
            if (in_array($limpia, $posibles, true)) {
                $mapa[$campo] = $i;
            }
        }
    }

    // Sin el nombre no hay nada que hacer: no es una cabecera valida.
    return isset($mapa['nombre']) ? $mapa : null;
}

/** Minusculas, sin acentos y sin espacios sobrantes. */
function normalizar(string $texto): string
{
    $texto = mb_strtolower(trim($texto));
    return strtr($texto, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ñ' => 'n', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ò' => 'o',
    ]);
}

/**
 * @param list<string> $fila
 * @param array<string,int> $columnas
 */
function valor(array $fila, array $columnas, string $campo): string
{
    if (!isset($columnas[$campo])) {
        return '';
    }

    $bruto = trim((string) ($fila[$columnas[$campo]] ?? ''));

    // Google Maps pone "-" donde no hay dato.
    return ($bruto === '-' || $bruto === 'N/A') ? '' : $bruto;
}

/**
 * Distingue web propia, ficha en un portal, y nada.
 * La diferencia importa: "solo esta en Doctoralia" es un argumento distinto
 * (y mas facil de defender) que "no tiene nada".
 *
 * @return array{0: ?string, 1: string}
 */
function clasificar_web(string $bruta): array
{
    // El export a veces trae "dominio.com (Reserva: otro.com)".
    $bruta = trim(preg_replace('/\s*\(.*$/', '', $bruta) ?? $bruta);

    if ($bruta === '') {
        return [null, 'ninguna'];
    }

    $minuscula = mb_strtolower($bruta);

    foreach (DIRECTORIOS as $portal) {
        if (str_contains($minuscula, $portal)) {
            return [null, 'directorio'];
        }
    }

    $url = preg_match('#^https?://#i', $bruta) ? $bruta : 'https://' . $bruta;

    return filter_var($url, FILTER_VALIDATE_URL) !== false
        ? [$url, 'propia']
        : [null, 'ninguna'];
}

/**
 * Saca la ciudad de una direccion espanola: el texto tras el codigo postal.
 */
function ciudad_de(string $direccion): ?string
{
    if (preg_match('/\b\d{5}\s+([^,]+)/u', $direccion, $m)) {
        return trim($m[1]);
    }
    return null;
}
