<?php
/**
 * AlastreSystem - descarga de fotos de Google Places.
 *
 *   php bin/fotos.php --id=ChIJ... [--max=6] [--ancho=1600]
 *   php bin/fotos.php --etapa=30-construido --max=6
 *
 * Las fotos no vienen dentro de la respuesta de busqueda: Places devuelve
 * referencias, y la imagen se pide a la Place Photos API, que se factura por
 * peticion. Por eso esto va aparte del scout y solo para los leads que ya vas
 * a trabajar, no para el barrido entero.
 *
 * Las guarda en landings/<slug>/img/ con los nombres que espera la plantilla.
 *
 * DOS COSAS QUE NO SON TECNICAS Y CONVIENE TENER CLARAS:
 *
 * 1. Estas fotos las suben usuarios a Google y llevan atribucion a su autor.
 *    Se guarda en fotos.json junto a las imagenes. Si publicas la landing,
 *    la atribucion tiene que aparecer.
 * 2. Los terminos de Places limitan cuanto tiempo puedes conservar el
 *    contenido. Para una propuesta de venta es razonable; para montar un
 *    archivo permanente de fotos ajenas, no. Si el cliente contrata, pidele
 *    sus originales.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$opciones = getopt('', ['id::', 'etapa::', 'max::', 'ancho::']);
$soloId   = $opciones['id'] ?? null;
$etapa    = $opciones['etapa'] ?? '30-construido';
$maxFotos = max(1, min(10, (int) ($opciones['max'] ?? 6)));
$ancho    = max(400, min(4800, (int) ($opciones['ancho'] ?? 1600)));

$clave = env_obligatoria('GOOGLE_PLACES_API_KEY');

$ids = $soloId !== null ? [$soloId] : leads_en($etapa);

if ($ids === []) {
    exit("No hay leads en {$etapa}.\n");
}

$totalDescargadas = 0;

foreach ($ids as $id) {
    $donde = $soloId !== null ? (etapa_de($id) ?? $etapa) : $etapa;
    $lead  = cargar_lead($donde, $id);

    echo "\n· " . mb_substr($lead['nombre'], 0, 52) . "\n";

    $fotos = $lead['fotos'] ?? [];

    if ($fotos === []) {
        echo "  sin referencias de foto. Vuelve a pasar el scout con --completo\n";
        continue;
    }

    $carpeta = BASE_PATH . '/landings/' . slug($lead['nombre']) . '/img';

    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }

    $atribuciones = [];
    $n = 0;

    foreach (array_slice($fotos, 0, $maxFotos) as $i => $foto) {
        if (empty($foto['nombre'])) {
            continue;
        }

        // La referencia ya viene con la forma places/XXX/photos/YYY
        $url = sprintf(
            'https://places.googleapis.com/v1/%s/media?maxWidthPx=%d&key=%s',
            $foto['nombre'],
            $ancho,
            urlencode($clave)
        );

        $res = http_get($url, [], 45);

        if ($res['estado'] !== 200 || strlen($res['cuerpo']) < 1024) {
            printf("  ! foto %d: HTTP %d\n", $i + 1, $res['estado']);
            continue;
        }

        $archivo = sprintf('places-%02d.jpg', $i + 1);
        file_put_contents($carpeta . '/' . $archivo, $res['cuerpo']);

        $atribuciones[] = [
            'archivo'    => $archivo,
            'atribucion' => $foto['atribucion'] ?? null,
            'origen'     => 'Google Places',
            'descargada' => date('c'),
        ];

        printf("  + %s  (%d KB, autor: %s)\n",
            $archivo, (int) (strlen($res['cuerpo']) / 1024), $foto['atribucion'] ?? '?');

        $n++;
        $totalDescargadas++;

        usleep(300000); // no conviene disparar en rafaga contra la API
    }

    if ($atribuciones !== []) {
        // La atribucion viaja con las fotos: sin este archivo, dentro de una
        // semana nadie sabe de quien son.
        file_put_contents(
            $carpeta . '/fotos.json',
            json_encode($atribuciones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $lead['fotos_descargadas'] = ['carpeta' => 'landings/' . slug($lead['nombre']) . '/img', 'total' => $n];
        guardar_lead($donde, $id, $lead);
    }

    printf("  %d foto(s) en landings/%s/img/\n", $n, slug($lead['nombre']));
}

printf("\nTotal descargado: %d foto(s).\n", $totalDescargadas);

if ($totalDescargadas > 0) {
    echo "Cada carpeta lleva un fotos.json con la atribucion de autor.\n";
}

// ---------------------------------------------------------------------------

function slug(string $texto): string
{
    $t = mb_strtolower(trim($texto));
    $t = strtr($t, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'à'=>'a','è'=>'e','ò'=>'o','ï'=>'i','ç'=>'c',
    ]);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? $t;

    return trim($t, '-') ?: 'lead';
}
